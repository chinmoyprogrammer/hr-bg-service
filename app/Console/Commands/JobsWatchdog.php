<?php

namespace App\Console\Commands;

use App\Support\Alert;
use App\Support\RunTracker;
use Cron\CronExpression;
use DateTime;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Finds what a job can't report about itself: due runs that never happened (missed), runs whose
 * worker died (lost) or overran (timed_out), queues nobody drains and silent heartbeats (stuck),
 * and late / slow / zero-output runs (flags), then sends the alerts (App\Support\Alert).
 * start-worker.sh runs it every 60 s. See docs/job-failure-tracking-plan.html, Phases 2-3.
 */
class JobsWatchdog extends Command
{
    protected $signature = 'jobs:watchdog';

    protected $description = 'Check job runs, schedules, queues and heartbeats for problems the jobs cannot report themselves.';

    const STATE = 'jobs_watchdog:';  // cache keys for state kept between runs
    const FINISHED = ['success', 'completed_with_errors', 'partial', 'rolled_back', 'failed', 'failed_retry_unsafe'];

    private array $schedules = [];
    private array $stats = [];
    private ?array $queues = null;

    public function handle(): int
    {
        $this->schedules = RunTracker::db()->table('job_schedules')->get()->keyBy('job')->all();
        $this->stats = [];
        $this->queues = null;  // per run; the command object can be reused

        // Each check on its own, so one failing (e.g. RabbitMQ API down) doesn't stop the others
        // alerts after the checks that write rows and flags, so it sees this run's findings
        foreach (['missed', 'stalled', 'late', 'orphans', 'stuckQueues', 'heartbeats', 'flags', 'alerts', 'digest', 'retention'] as $check) {
            try {
                $this->{$check}();
            } catch (Throwable $e) {
                $this->error("[watchdog] {$check}: {$e->getMessage()}");
                Log::error("jobs:watchdog {$check} failed", ['error' => $e->getMessage()]);
            }
        }
        RunTracker::beat('watchdog', ['host' => gethostname(), 'pid' => getmypid()]);

        return 0;
    }

    /** A schedule's latest due time (at least grace_minutes ago) with no run since: one `missed` row. */
    private function missed(): void
    {
        $local = new DateTimeZone(date_default_timezone_get());
        foreach ($this->schedules as $s) {
            if (!$s->enabled || !$s->cron) {
                continue;
            }
            $tz = new DateTimeZone($s->timezone ?: $local->getName());
            $cron = new CronExpression($s->cron);
            $cutoff = new DateTime("-{$s->grace_minutes} minutes", $tz);
            $last = $s->last_expected_at ? new DateTime($s->last_expected_at, $local) : null;

            // Every slot since the last check, so none is skipped if the watchdog itself was down.
            // ponytail: at most 60 per run (an hour of a minute job); older ones are not reported.
            $dues = [];
            for ($n = 0; $n < 60; $n++) {
                $due = $cron->getPreviousRunDate($cutoff, $n, true, $tz->getName());
                if ($last && $due <= $last) {
                    break;
                }
                $dues[] = $due;
                if (!$last) {
                    break;  // never checked: only the latest
                }
            }
            foreach (array_reverse($dues) as $due) {
                $this->checkSlot($s, $cron, $due, $tz, $local);
            }
            if ($dues) {
                RunTracker::db()->table('job_schedules')->where('job', $s->job)->update([
                    'last_expected_at' => (clone $dues[0])->setTimezone($local)->format('Y-m-d H:i:s'),
                    'last_checked_at' => RunTracker::now(),
                ]);
            }
        }
    }

    private function checkSlot(object $s, CronExpression $cron, DateTime $due, DateTimeZone $tz, DateTimeZone $local): void
    {
        $next = $cron->getNextRunDate($due, 0, false, $tz->getName());
        $dueAt = (clone $due)->setTimezone($local)->format('Y-m-d H:i:s');

        // This slot only: the next slot's run doesn't make up for a skipped one. The skew covers
        // clock drift between containers and a slow trigger consumer.
        $skew = min(60, intdiv($next->getTimestamp() - $due->getTimestamp(), 2));
        $from = RunTracker::now(false, $due->getTimestamp() - $skew);
        $to = RunTracker::now(false, $next->getTimestamp() - $skew);
        $ran = RunTracker::db()->table('job_runs')->where('job', $s->job)->where('status', '!=', 'missed')
            ->where(fn ($q) => $q->whereBetween('queued_at', [$from, $to])->orWhereBetween('started_at', [$from, $to]))
            ->exists();
        if (!$ran) {
            $this->record([
                'job' => $s->job,
                'queue' => $s->queue,
                'trigger_source' => 'scheduler',
                'status' => 'missed',
                'queued_at' => $dueAt,
                // What it would have been sent, for a "run now"; date fields are from the last run
                'payload' => RunTracker::db()->table('job_runs')->where('job', $s->job)->where('trigger_source', 'scheduler')
                    ->whereNotNull('payload')->orderByDesc('id')->value('payload'),
                'error_message' => "Due at {$dueAt}; no run started within {$s->grace_minutes} min",
            ]);
        }
    }

    /** `running` rows: worker process gone -> lost; still going past max_runtime -> timed_out. */
    private function stalled(): void
    {
        foreach (RunTracker::db()->table('job_runs')->where('status', 'running')->limit(500)->get() as $run) {
            $age = time() - strtotime($run->started_at);
            $alive = $run->worker_host === gethostname() && self::alive((int) $run->worker_pid);
            $max = $this->maxRuntime($run->job);

            if (!$alive && $age > 60) {
                $this->close($run, 'lost', "Worker process {$run->worker_host}/{$run->worker_pid} is gone (killed or crashed mid-run); found by watchdog");
            } elseif ($alive && $age > $max) {
                $this->close($run, 'timed_out', "Still running after {$age}s (max_runtime {$max}s); found by watchdog");
            }
        }
    }

    /** `queued` rows waiting longer than max_wait get the late flag. */
    private function late(): void
    {
        foreach (RunTracker::db()->table('job_runs')->where('status', 'queued')->whereNotNull('queued_at')->limit(500)->get() as $run) {
            if (time() - strtotime($run->queued_at) > $this->maxWait($run->job)) {
                $this->flag($run, ['late']);
            }
        }
    }

    /**
     * `queued` / `retrying` rows whose message is gone (purged, lost with a crashed broker): their
     * queue and its retry delay queues hold nothing, so no worker will ever pick them up.
     */
    private function orphans(): void
    {
        $queues = $this->queues();
        $rows = RunTracker::db()->table('job_runs')->whereIn('status', ['queued', 'retrying'])->whereNotNull('queue')->limit(500)->get();
        foreach ($rows as $run) {
            $since = $run->status === 'queued' ? $run->queued_at : $run->finished_at;
            if (!$since || time() - strtotime($since) < 120) {
                continue;  // just pushed or just released; the message may not be visible yet
            }
            $pending = 0;
            foreach ($queues as $name => $q) {
                if ($name === $run->queue || str_starts_with($name, $run->queue . '.delay.')) {
                    $pending += (int) ($q['messages_ready'] ?? 0) + (int) ($q['messages_unacknowledged'] ?? 0);
                }
            }
            // ponytail: a busy queue hides an orphan (can't tell which message is whose); late/stuck still show it
            if ($pending === 0) {
                $this->close($run, 'lost', "Still {$run->status} but {$run->queue} and its retry queues are empty: the message is gone; found by watchdog");
            }
        }
    }

    /** RabbitMQ management API, once per watchdog run. Throws if it's down, so the calling check is skipped. */
    private function queues(): array
    {
        if ($this->queues === null) {
            $url = rtrim(env('RABBITMQ_MGMT_URL', 'http://' . env('RABBITMQ_HOST', '127.0.0.1') . ':15672'), '/');
            $list = Http::timeout(10)
                ->withBasicAuth(env('RABBITMQ_MGMT_USER', env('RABBITMQ_USER', 'guest')), env('RABBITMQ_MGMT_PASSWORD', env('RABBITMQ_PASSWORD', 'guest')))
                ->get("{$url}/api/queues", ['columns' => 'name,messages_ready,messages_unacknowledged,message_stats.deliver_get'])
                ->throw()->json();
            $this->queues = array_column($list, null, 'name');
        }
        return $this->queues;
    }

    /**
     * Messages waiting and none delivered for JOBS_STUCK_MINUTES. Consumer counts don't help:
     * queue:work polls (basic_get), so RabbitMQ shows 0 consumers on every worker queue.
     */
    private function stuckQueues(): void
    {
        $minutes = (int) env('JOBS_STUCK_MINUTES', 10);

        foreach ($this->queues() as $q) {
            $name = $q['name'];
            if (preg_match('/\.delay\.\d+$/', $name)) {
                continue;  // retry backoff queues hold messages on purpose
            }
            $key = self::STATE . 'queue:' . $name;
            $ready = (int) ($q['messages_ready'] ?? 0);
            $delivered = (int) ($q['message_stats']['deliver_get'] ?? 0);
            $state = Cache::get($key);
            $moving = $ready === 0 || ($state && $state['delivered'] !== $delivered);
            if ($state && $moving && $state['run_id']) {
                Alert::resolve(RunTracker::jobName($name), 'stuck');  // the episode is over
            }
            if ($ready === 0) {
                Cache::forget($key);
                continue;
            }
            if (!$state || $moving) {
                $state = ['since' => time(), 'delivered' => $delivered, 'run_id' => null];  // new or moving again
            }
            $for = time() - $state['since'];
            if ($for >= $minutes * 60) {
                $state['run_id'] = $this->ongoing($state['run_id'], [
                    'job' => RunTracker::jobName($name),
                    'queue' => $name,
                    'status' => 'stuck',
                    'error_message' => "{$ready} message(s) waiting in {$name}, none delivered for " . intdiv($for, 60) . ' min',
                ]);
            }
            Cache::put($key, $state, 86400);
        }
    }

    /** Silent trigger consumer, worker or scheduler. A worker running a long job doesn't beat, so its process counts. */
    private function heartbeats(): void
    {
        $stale = (int) env('JOBS_HEARTBEAT_STALE_S', 180);
        foreach (RunTracker::db()->table('heartbeats')->get() as $beat) {
            if ($beat->name === 'watchdog') {
                continue;
            }
            $age = time() - strtotime($beat->beat_at);
            $info = json_decode((string) $beat->info, true) ?: [];
            $busy = str_starts_with($beat->name, 'worker:') && ($info['host'] ?? null) === gethostname() && self::alive((int) ($info['pid'] ?? 0));
            $key = self::STATE . 'beat:' . $beat->name;
            if ($age <= $stale || $busy) {
                if (Cache::pull($key)) {
                    Alert::resolve('heartbeat:' . $beat->name, 'stuck');
                }
                continue;
            }
            Cache::put($key, $this->ongoing(Cache::get($key), [
                'job' => 'heartbeat:' . $beat->name,
                'status' => 'stuck',
                'error_message' => "No {$beat->name} heartbeat for " . intdiv($age, 60) . " min (last {$beat->beat_at})",
            ]), 86400);
        }
    }

    /** late / slow / zero_output on runs finished since the last check. */
    private function flags(): void
    {
        $cursorKey = self::STATE . 'flags_cursor';
        $cursor = Cache::get($cursorKey) ?? RunTracker::now();  // first run: no backfill
        $runs = RunTracker::db()->table('job_runs')->where('finished_at', '>', $cursor)->whereIn('status', self::FINISHED)
            ->orderBy('finished_at')->limit(1000)->get();

        foreach ($runs as $run) {
            $cursor = $run->finished_at;
            $flags = [];
            $maxRuntimeMs = $this->maxRuntime($run->job) * 1000;
            if ($run->wait_ms !== null && $run->wait_ms > $this->maxWait($run->job) * 1000) {
                $flags[] = 'late';
            }
            $p95 = $this->stats($run->job)['p95'];
            if ($run->duration_ms > 0.8 * $maxRuntimeMs || ($run->duration_ms >= 30000 && $p95 && $run->duration_ms > 2 * $p95)) {
                $flags[] = 'slow';
            }
            $metric = $this->schedules[$run->job]->expect_metric ?? null;
            if ($metric && in_array($run->status, ['success', 'completed_with_errors'], true)) {
                $value = json_decode((string) $run->metrics, true)[$metric] ?? null;
                $median = $this->stats($run->job)['metric_median'];
                if ($value !== null && ($value == 0 || ($median && $value < 0.2 * $median))) {
                    $flags[] = 'zero_output';
                }
            }
            if ($flags) {
                $this->flag($run, $flags);
            }
        }
        Cache::forever($cursorKey, $cursor);
    }

    /** Runs that ended (or were found) since the last check go through Alert. */
    private function alerts(): void
    {
        Alert::retryUnsent();
        $cursorKey = self::STATE . 'alerts_cursor';
        $cursor = Cache::get($cursorKey) ?? RunTracker::now();  // first run: nothing from before
        $runs = RunTracker::db()->table('job_runs')->where('finished_at', '>', $cursor)
            ->whereNotIn('status', ['queued', 'running', 'retrying'])->orderBy('finished_at')->limit(1000)->get();
        foreach ($runs as $run) {
            Alert::run($run);
            $cursor = $run->finished_at;
        }
        Cache::forever($cursorKey, $cursor);
    }

    /** Once a day at JOB_DIGEST_AT (default 09:00), or the first run after it. */
    private function digest(): void
    {
        $key = self::STATE . 'digest:' . date('Y-m-d');
        if (date('H:i') < env('JOB_DIGEST_AT', '09:00') || !Cache::add($key, 1, 2 * 86400)) {
            return;
        }
        if (!Alert::send(Alert::digestText())) {
            Cache::forget($key);  // webhook down: try again next run
        }
    }

    /** Every 6 h: drop success logs after 7 days; rows, API calls and resolved alerts after 90. */
    private function retention(): void
    {
        if (!Cache::add(self::STATE . 'retention', 1, 6 * 3600)) {
            return;
        }
        $db = RunTracker::db();
        $week = self::ago(7 * 86400);
        $cut = self::ago(90 * 86400);
        do {
            $n = $db->table('job_runs')->where('status', 'success')->whereNotNull('log')->where('finished_at', '<', $week)
                ->limit(5000)->update(['log' => null]);
        } while ($n === 5000);
        do {
            $n = $db->table('job_runs')
                ->where(fn ($q) => $q->where('queued_at', '<', $cut)->orWhere(fn ($q) => $q->whereNull('queued_at')->where('started_at', '<', $cut)))
                ->limit(5000)->delete();
        } while ($n === 5000);
        do {
            $n = $db->table('job_http_calls')->where('started_at', '<', $cut)->limit(5000)->delete();
        } while ($n === 5000);
        $db->table('job_alerts')->where('resolved_at', '<', $cut)->delete();
    }

    // ---- Helpers

    /** Insert a finding, or update the one already open for the same problem. Returns its id. */
    private function ongoing(?int $runId, array $row): ?int
    {
        if ($runId) {
            RunTracker::write(fn ($db) => $db->table('job_runs')->where('id', $runId)
                ->update(['error_message' => $row['error_message'], 'finished_at' => RunTracker::now()]));
            return $runId;
        }
        return $this->record($row);
    }

    private function record(array $row): ?int
    {
        $this->warn("[watchdog] {$row['status']} {$row['job']}: {$row['error_message']}");
        return RunTracker::write(fn ($db) => $db->table('job_runs')->insertGetId($row + [
            'run_uuid' => (string) Str::uuid(),
            'trigger_source' => 'watchdog',
            'finished_at' => RunTracker::now(),
            'worker_host' => gethostname(),
            'worker_pid' => getmypid(),
        ]));
    }

    private function close(object $run, string $status, string $error): void
    {
        $this->warn("[watchdog] {$status} {$run->job} #{$run->id}: {$error}");
        // Only if unchanged since we read it: the worker may have moved it on meanwhile
        RunTracker::write(fn ($db) => $db->table('job_runs')->where('id', $run->id)->where('status', $run->status)
            ->update(['status' => $status, 'error_message' => $error, 'finished_at' => RunTracker::now()]));
    }

    private function flag(object $run, array $add): void
    {
        $flags = json_decode((string) $run->flags, true) ?: [];
        $merged = array_values(array_unique(array_merge($flags, $add)));
        if ($merged !== $flags) {
            RunTracker::write(fn ($db) => $db->table('job_runs')->where('id', $run->id)->update(['flags' => json_encode($merged)]));
        }
    }

    /** 7-day p95 duration and median output metric of successful runs (null below 10 / 5 samples). */
    private function stats(string $job): array
    {
        if (!isset($this->stats[$job])) {
            $runs = RunTracker::db()->table('job_runs')->where('job', $job)->whereIn('status', ['success', 'completed_with_errors'])
                ->where('finished_at', '>=', self::ago(7 * 86400))->get(['duration_ms', 'metrics']);
            $durations = $runs->pluck('duration_ms')->filter(fn ($d) => $d !== null)->sort()->values()->all();
            $metric = $this->schedules[$job]->expect_metric ?? null;
            $values = $metric ? $runs->map(fn ($r) => json_decode((string) $r->metrics, true)[$metric] ?? null)->filter(fn ($v) => $v !== null)->sort()->values()->all() : [];
            $this->stats[$job] = [
                'p95' => count($durations) >= 10 ? $durations[(int) floor(0.95 * (count($durations) - 1))] : null,
                'metric_median' => count($values) >= 5 ? $values[intdiv(count($values), 2)] : null,
            ];
        }
        return $this->stats[$job];
    }

    private function maxRuntime(string $job): int
    {
        return (int) ($this->schedules[$job]->max_runtime_s ?? env('JOBS_MAX_RUNTIME_S', 900));
    }

    private function maxWait(string $job): int
    {
        return (int) ($this->schedules[$job]->max_wait_s ?? env('JOBS_MAX_WAIT_S', 300));
    }

    /** Same container (pid namespace) only; checks the cmdline so a reused pid isn't mistaken for the worker. */
    private static function alive(int $pid): bool
    {
        $cmdline = $pid > 0 ? @file_get_contents("/proc/{$pid}/cmdline") : false;
        return $cmdline !== false && str_contains($cmdline, 'artisan');
    }

    private static function ago(int $seconds): string
    {
        return RunTracker::now(false, microtime(true) - $seconds);
    }
}
