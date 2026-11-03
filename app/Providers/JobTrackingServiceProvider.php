<?php

namespace App\Providers;

use App\Support\RunTracker;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Queue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Throwable;

/** Records every queued job run in job_runs. State lives in RunTracker. */
class JobTrackingServiceProvider extends ServiceProvider
{
    private static ?string $reservedMemory = null;  // freed on a fatal so the shutdown handler can still write
    private static float $lastBeat = 0;

    public function boot(): void
    {
        $events = $this->app['events'];

        Queue::createPayloadUsing(fn () => array_filter([
            'run_uuid' => RunTracker::$nextRunUuid,
            'pushed_at' => microtime(true),
        ]));

        $events->listen(JobProcessing::class, fn ($e) => $this->start($e->job));
        $events->listen(JobProcessed::class, function ($e) {
            if ($this->owns($e->job)) {
                $this->finish($e->job->isReleased() ? 'retrying' : null);
            }
        });
        $events->listen(JobExceptionOccurred::class, fn ($e) => $this->attemptFailed($e->job, $e->exception));
        $events->listen(JobFailed::class, function ($e) {
            if ($this->owns($e->job)) {
                $this->finish(null, $e->exception);
            }
        });
        // Fires right before the worker SIGKILLs itself, so shutdown functions won't run
        $events->listen(JobTimedOut::class, function ($e) {
            if ($this->owns($e->job)) {
                $this->finish('timed_out', null, 'Job exceeded its timeout');
            }
        });
        $events->listen(Looping::class, function () {
            if (microtime(true) - self::$lastBeat >= 30) {
                self::$lastBeat = microtime(true);
                RunTracker::beat('worker:' . RunTracker::jobName(explode(',', self::argv('queue') ?? 'default')[0]), [
                    'host' => gethostname(), 'pid' => getmypid(),
                ]);
            }
        });

        $events->listen(QueryExecuted::class, function ($e) {
            if ($e->connectionName !== 'tracking') {
                RunTracker::$current?->query($e->connectionName, $e->sql);
            }
        });
        $events->listen(TransactionBeginning::class, function ($e) {
            if ($e->connectionName !== 'tracking') {
                RunTracker::$current?->begin($e->connectionName);
            }
        });
        $events->listen(TransactionCommitted::class, function ($e) {
            if ($e->connectionName !== 'tracking') {
                RunTracker::$current?->committed($e->connectionName, $e->connection->transactionLevel());
            }
        });
        $events->listen(TransactionRolledBack::class, function ($e) {
            if ($e->connectionName !== 'tracking') {
                RunTracker::$current?->rolledBack($e->connectionName, $e->connection->transactionLevel());
            }
        });

        $events->listen(MessageLogged::class, fn ($e) => RunTracker::$current?->log($e->level, (string) $e->message, $e->context));
        $events->listen(RequestSending::class, fn () => RunTracker::$current?->httpSending());
        $events->listen(ResponseReceived::class, function ($e) {
            $seconds = $e->response->transferStats?->getTransferTime();
            RunTracker::$current?->httpCall(self::httpRequest($e->request) + [
                'status' => $e->response->status(),
                'response_headers' => $e->response->headers(),
                'response_body' => $e->response->body(),
                'duration_ms' => $seconds === null ? null : (int) round($seconds * 1000),
            ]);
        });
        $events->listen(ConnectionFailed::class, fn ($e) => RunTracker::$current?->httpCall(self::httpRequest($e->request) + ['status' => null]));

        self::$reservedMemory = str_repeat(' ', 256 * 1024);
        register_shutdown_function(fn () => $this->shutdown());
    }

    private function start($job): void
    {
        // ponytail: a job run with the sync driver from inside another job is folded into the outer run
        if (RunTracker::$current) {
            return;
        }

        $payload = $job->payload();
        $attempt = $job->attempts();
        $uuid = $payload['run_uuid'] ?? $payload['uuid'] ?? (string) Str::uuid();
        $input = self::input($payload);
        $now = RunTracker::now();
        $queue = $job->getQueue() ?: $job->getConnectionName();  // sync jobs pushed without a queue
        $row = [
            'status' => 'running', 'attempt' => $attempt, 'started_at' => $now, 'finished_at' => null,
            'max_attempts' => $job->maxTries() ?? (int) (self::argv('tries') ?? 1),
            'worker_host' => gethostname(), 'worker_pid' => getmypid(),
        ];
        if ($attempt === 1 && isset($payload['pushed_at'])) {
            $row['wait_ms'] = max(0, (int) round((microtime(true) - $payload['pushed_at']) * 1000));
        }

        $existing = RunTracker::write(fn ($db) => $db->table('job_runs')->where('run_uuid', $uuid)->first(['id', 'status', 'error_message', 'queued_at']));
        if ($attempt === 1 && $existing?->status === 'queued' && $existing->queued_at) {
            // Trigger rows: from the scheduler's publish, so time spent waiting for the consumer counts too
            $row['wait_ms'] = max(0, (int) round((microtime(true) - strtotime($existing->queued_at)) * 1000));
        }
        $parentId = null;
        $crashes = 0;
        if ($existing && $attempt === 1 && $existing->status !== 'queued') {
            // Same payload again (queue:retry, or redelivered after a kill): a new run linked to the old one
            $parentId = $existing->id;
            $existing = null;
            $uuid = (string) Str::uuid();
            $crashes = self::crashesInARow($parentId);
            unset($row['wait_ms']);  // pushed_at is the original push, not this one
        }

        if ($existing) {
            RunTracker::write(fn ($db) => $db->table('job_runs')->where('id', $existing->id)->update($row));
            $id = $existing->id;
        } else {
            $id = RunTracker::write(fn ($db) => $db->table('job_runs')->insertGetId($row + [
                'run_uuid' => $uuid,
                'job' => RunTracker::jobName($queue),
                'job_class' => $payload['displayName'] ?? null,
                'queue' => $queue,
                'trigger_source' => $parentId ? 'retry' : (string) ($input['trigger_source'] ?? 'api'),
                'triggered_by' => is_numeric($input['triggered_by'] ?? null) ? (int) $input['triggered_by'] : null,
                'parent_run_id' => $parentId,
                'payload' => self::json($input),
                'queued_at' => !$parentId && isset($payload['pushed_at']) ? RunTracker::now(false, (float) $payload['pushed_at']) : null,
            ]));
        }

        $tracker = new RunTracker($id ? (int) $id : null, $uuid, spl_object_id($job));
        if ($attempt > 1 && $existing?->error_message) {
            $tracker->log('warning', 'Attempt ' . ($attempt - 1) . ' failed: ' . $existing->error_message, []);
        }
        RunTracker::$current = $tracker;
        Log::shareContext(['run_id' => $uuid]);

        // A kill (OOM, timeout SIGKILL) never reaches release(), so RabbitMQ redelivers with the same
        // attempt count, forever. Give up after max_attempts crashes; the worker then skips fire().
        if ($crashes >= $row['max_attempts']) {
            $job->fail(new \RuntimeException("Worker died {$crashes} times in a row running this job; not running it again"));
        }
    }

    /** Runs of this payload that ended with the worker dying, counted back from the newest. */
    private static function crashesInARow(int $rootId): int
    {
        $statuses = RunTracker::write(fn ($db) => $db->table('job_runs')
            ->where(fn ($q) => $q->where('id', $rootId)->orWhere('parent_run_id', $rootId))
            ->orderByDesc('id')->limit(50)->pluck('status')) ?? [];
        $crashes = 0;
        foreach ($statuses as $status) {
            if (!in_array($status, ['lost', 'timed_out', 'running'], true)) {
                break;
            }
            $crashes++;
        }
        return $crashes;
    }

    private function attemptFailed($job, Throwable $e): void
    {
        // Last attempt: JobFailed already closed the run. Sync driver: it calls fail() right after this event.
        if (!$this->owns($job) || $job->getConnectionName() === 'sync') {
            return;
        }
        if (RunTracker::$current->counts['writes_kept'] > 0) {
            $job->fail($e);  // committed writes would be applied twice; JobFailed closes it as failed_retry_unsafe
            return;
        }
        $this->finish('retrying', $e);
    }

    /** $status null = decide from what the run did. */
    private function finish(?string $status, ?Throwable $e = null, ?string $error = null): void
    {
        $tracker = RunTracker::$current;

        // A transaction left open would leak into the next job on this connection
        foreach (DB::getConnections() as $name => $conn) {
            if ($name !== 'tracking' && ($level = $conn->transactionLevel()) > 0) {
                $tracker->log('warning', "Job left {$level} transaction level(s) open on [{$name}], rolled back", []);
                try {
                    $conn->rollBack(0);
                } catch (Throwable) {
                }
            }
        }
        $tracker->abandonOpen();

        RunTracker::$current = null;
        Log::flushSharedContext();
        foreach (Log::getChannels() as $channel) {
            $channel->withoutContext();  // shareContext() put run_id on every open channel, not just the default
        }

        $columns = [
            'status' => $status ?? $tracker->status($e !== null, $e),
            'finished_at' => RunTracker::now(),
            'duration_ms' => (int) round((microtime(true) - $tracker->startedAt) * 1000),
        ];
        if ($e) {
            $columns['error_class'] = get_class($e);
            $columns['error_message'] = RunTracker::cut(RunTracker::redactString($e->getMessage()));
            $columns['error_trace'] = RunTracker::cut($e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString(), 65535);
        } elseif ($error) {
            $columns['error_message'] = $error;
        }
        $tracker->save($columns);
    }

    private function shutdown(): void
    {
        self::$reservedMemory = null;
        if (!RunTracker::$current) {
            return;
        }
        ini_set('memory_limit', '-1');  // the process is exiting; let the last write through after an OOM
        $err = error_get_last();
        $fatal = $err && in_array($err['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], true);
        $this->finish('lost', null, $fatal
            ? "{$err['message']} in {$err['file']}:{$err['line']}"
            : 'Worker exited while the job was running');
    }

    private function owns($job): bool
    {
        return RunTracker::$current?->jobRef === spl_object_id($job);
    }

    /** The job's constructor input: $payload on every job, $data on RabbitMQJob. */
    private static function input(array $payload): mixed
    {
        try {
            $props = (array) unserialize($payload['data']['command'] ?? '');
            return $props["\0*\0payload"] ?? $props["\0*\0data"] ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function json(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $json = json_encode(RunTracker::redact($value), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return strlen($json) > 65535 ? json_encode(['truncated' => true, 'bytes' => strlen($json)]) : $json;
    }

    private static function httpRequest(\Illuminate\Http\Client\Request $request): array
    {
        return ['method' => $request->method(), 'url' => $request->url(), 'request_headers' => $request->headers(), 'request_body' => $request->body()];
    }

    /** --name=value from the worker command line. */
    private static function argv(string $name): ?string
    {
        foreach ($_SERVER['argv'] ?? [] as $arg) {
            if (str_starts_with($arg, "--{$name}=")) {
                return substr($arg, strlen($name) + 3);
            }
        }
        return null;
    }
}
