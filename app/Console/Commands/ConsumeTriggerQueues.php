<?php

namespace App\Console\Commands;

use App\Jobs\ProcessTempDataJob;
use App\Jobs\EmployeeDeactivation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Support\RunTracker;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use Str;

class ConsumeTriggerQueues extends Command
{
    protected $signature = 'rabbitmq:consume-triggers {--queues=}';

    protected $description = 'Consume trigger queues with JSON payloads and dispatch internal jobs.';

    public function handle(): int
    {
        $queues = $this->option('queues') ?: env('HR_BG_TRIGGER_QUEUES', '');
        
        $queueList = array_values(array_filter(array_map('trim', explode(',', (string) $queues))));

        if (!$queueList) {
            $this->error('No queues provided. Use --queues=queue1,queue2 or set HR_BG_TRIGGER_QUEUES.');
            return 1;
        }

        $host = env('RABBITMQ_HOST', '127.0.0.1');
        $port = (int) env('RABBITMQ_PORT', 5672);
        $user = env('RABBITMQ_USER', 'guest');
        $pass = env('RABBITMQ_PASSWORD', 'guest');
        $vhost = env('RABBITMQ_VHOST', '/');

        $connection = new AMQPStreamConnection($host, $port, $user, $pass, $vhost);
        $channel = $connection->channel();

        foreach ($queueList as $queueName) {
            $channel->queue_declare($queueName, false, true, false, false);
        }

        $channel->basic_qos(null, 1, null);

        foreach ($queueList as $queueName) {
            $channel->basic_consume(
                $queueName,
                '',
                false,
                false,
                false,
                false,
                function ($msg) use ($queueName) {
                    $runUuid = (string) \Illuminate\Support\Str::uuid();
                    // Publish time (set by task-scheduler), so a trigger consumed late still counts for its slot
                    $queuedAt = $msg->has('timestamp') ? RunTracker::now(false, (float) $msg->get('timestamp')) : RunTracker::now();
                    $body = $msg->getBody();
                    try {
                        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

                    } catch (\Throwable $e) {
                        Log::error('Trigger message is not valid JSON', [
                            'queue' => $queueName,
                            'body' => $body,
                            'error' => $e->getMessage(),
                        ]);
                        $this->recordTriggerFailed($runUuid, $queueName, ['raw' => RunTracker::cut($body, 65535)], $e, $queuedAt);
                        $msg->ack();
                        return;
                    }
                    Log::info('Consuming trigger message from queue final: ' . $queueName);
                    $payload = is_array($payload) ? $payload : [];
                    try {
                        $this->dispatchFromTriggerQueue($queueName, $payload, $runUuid, $queuedAt);
                    } catch (\Throwable $e) {
                        Log::error('Trigger consumer failed', [
                            'queue' => $queueName,
                            'payload' => $payload,
                            'error' => $e->getMessage(),
                        ]);
                        // Acked, not dropped: body and error are kept in job_runs for replay
                        $this->recordTriggerFailed($runUuid, $queueName, $payload, $e, $queuedAt);
                    }
                    $msg->ack();
                }
            );
        }

        $this->info('Consuming trigger queues: ' . implode(', ', $queueList));

        while ($channel->is_consuming()) {
            try {
                $channel->wait(null, false, 30);
            } catch (AMQPTimeoutException $e) {
                // idle; wake up to beat
            }
            RunTracker::beat('trigger_consumer', ['host' => gethostname(), 'pid' => getmypid()]);
        }

        $channel->close();
        $connection->close();

        return 0;
    }

    private function dispatchFromTriggerQueue(string $queueName, array $payload, string $runUuid, string $queuedAt): void
    {
        Log::info('Consuming trigger message', ['queue' => $queueName, 'payload' => $payload]);
        $suffix = '_trigger_queue';
        if (!\Illuminate\Support\Str::endsWith($queueName, $suffix)) {
            throw new \RuntimeException("No trigger handler registered for queue [{$queueName}]");
        }

        $jobMapJson = (string) env('HR_BG_TRIGGER_JOB_MAP', '');
        $jobMap = [];
        if ($jobMapJson !== '') {
            try {
                $decoded = json_decode($jobMapJson, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $jobMap = $decoded;
                }
            } catch (\Throwable $e) {
                Log::warning('Invalid HR_BG_TRIGGER_JOB_MAP JSON', ['error' => $e->getMessage()]);
            }
        }

        $baseName = substr($queueName, 0, -strlen($suffix));
        $targetQueue = $baseName . '_queue';

        $studlyBaseName = \Illuminate\Support\Str::studly($baseName);
        $jobClass = $jobMap[$queueName] ?? null;

        if (!is_string($jobClass) || !class_exists($jobClass)) {
            $jobClass = 'App\\Jobs\\' . $studlyBaseName . 'Job';
            if (!class_exists($jobClass)) {
                $jobClass = 'App\\Jobs\\' . $studlyBaseName;
            }
        }

        if (!class_exists($jobClass)) {
            throw new \RuntimeException("No trigger handler registered for queue [{$queueName}]: {$jobClass} not found");
        }

        try {
            $job = new $jobClass($payload);
        } catch (\Throwable $e) {
            Log::error('Trigger handler resolved but could not be instantiated', [
                'queue' => $queueName,
                'resolved_job' => $jobClass,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        RunTracker::write(fn ($db) => $db->table('job_runs')->insert([
            'run_uuid' => $runUuid,
            'job' => $baseName,
            'job_class' => $jobClass,
            'queue' => $targetQueue,
            'trigger_source' => (string) ($payload['trigger_source'] ?? 'scheduler'),
            'triggered_by' => is_numeric($payload['triggered_by'] ?? null) ? (int) $payload['triggered_by'] : null,
            // set by the jobs API on replay / run-now
            'parent_run_id' => is_numeric($payload['parent_run_id'] ?? null) ? (int) $payload['parent_run_id'] : null,
            'payload' => json_encode(RunTracker::redact($payload), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'status' => 'queued',
            'queued_at' => $queuedAt,
        ]));

        // The payload hook copies this into the job, so the worker updates the row above
        RunTracker::$nextRunUuid = $runUuid;
        try {
            dispatch($job->onQueue($targetQueue)->onConnection('rabbitmq'));
        } finally {
            RunTracker::$nextRunUuid = null;
        }
    }

    private function recordTriggerFailed(string $runUuid, string $queueName, array $payload, \Throwable $e, string $queuedAt): void
    {
        $now = RunTracker::now();
        RunTracker::write(fn ($db) => $db->table('job_runs')->updateOrInsert(['run_uuid' => $runUuid], [
            'job' => RunTracker::jobName($queueName),
            'queue' => $queueName,
            'trigger_source' => (string) ($payload['trigger_source'] ?? 'scheduler'),
            'payload' => json_encode(RunTracker::redact($payload), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'status' => 'trigger_failed',
            'queued_at' => $queuedAt,
            'finished_at' => $now,
            'worker_host' => gethostname(),
            'worker_pid' => getmypid(),
            'error_class' => get_class($e),
            'error_message' => RunTracker::cut($e->getMessage()),
        ]));
    }
}
