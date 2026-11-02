<?php

namespace App\Console\Commands;

use App\Support\RunTracker;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Per-job limits for the watchdog, from real durations (docs/job-failure-tracking-plan.html, Phase 5).
 * Prints a suggestion for every job; --apply writes them for the on-demand jobs (cron jobs get their
 * row from task-scheduler) and sets expect_metric. Re-run after a week of tracking for better numbers.
 */
class JobsLimits extends Command
{
    protected $signature = 'jobs:limits {--apply : Write the on-demand limits and expect_metric to job_schedules} {--days=7}';

    protected $description = 'Suggest max_runtime_s / max_wait_s per job from tracked durations, and set expect_metric.';

    /** Queued on demand (no cron): without a row here the watchdog only has its env defaults for them. */
    const ON_DEMAND = [
        'processManualData', 'processTempSalary', 'processRealSalary', 'sendBulkSMS',
        'sendBulkSMSToMultiUserOneSMS', 'recalculateAttendance', 'createDeviceUserByEmpCode', 'recalculateSelectedAttendanceData',
        'cacheRegenerate', 'oldErpLoanSync', 'oldErpLateConsiderSync', 'oldErpAttendanceSync',
    ];
    /** The RunTracker::metric() each job reports that the zero_output check watches. Not set where 0 is normal (employeeTransferApply). */
    const EXPECT_METRIC = [
        'processTempData' => 'attendance',
        'processTempSalary' => 'employees',
        'sendBulkSMS' => 'sent',
        'sendBulkSMSToMultiUserOneSMS' => 'sent',
    ];
    const MIN_RUNS = 10;           // fewer successful runs than this: fall back to the job's timeout
    const ON_DEMAND_WAIT_S = 600;  // fallback max_wait: one worker serves every queue, so on-demand jobs queue behind others

    public function handle(): int
    {
        $db = RunTracker::db();
        $since = RunTracker::now(false, microtime(true) - 86400 * (int) $this->option('days'));
        $schedules = $db->table('job_schedules')->get()->keyBy('job');
        $jobs = collect(self::ON_DEMAND)->merge($schedules->keys())->merge(
            $db->table('job_runs')->where('started_at', '>=', $since)->distinct()->pluck('job')
        )->reject(fn ($j) => str_starts_with($j, 'heartbeat:'))->unique()->sort()->values();

        $rows = [];
        foreach ($jobs as $job) {
            $runs = $db->table('job_runs')->where('job', $job)->where('started_at', '>=', $since)
                ->whereIn('status', ['success', 'completed_with_errors'])->get(['duration_ms', 'wait_ms']);
            $s = self::suggest($job, $runs->pluck('duration_ms')->filter()->all(), $runs->pluck('wait_ms')->filter(fn ($w) => $w !== null)->all());
            $current = $schedules[$job] ?? null;
            $rows[] = [
                $job, count($runs), self::secs($s['max_ms']), self::secs($s['p95_wait_ms']),
                $current ? "{$current->max_runtime_s}/{$current->max_wait_s}" : '-',
                "{$s['max_runtime_s']}/{$s['max_wait_s']}", $s['basis'],
                in_array($job, self::ON_DEMAND, true) ? 'on demand' : ($current?->cron ? 'cron' : '-'),
            ];

            if ($this->option('apply') && in_array($job, self::ON_DEMAND, true)) {
                $values = ['max_runtime_s' => $s['max_runtime_s'], 'max_wait_s' => $s['max_wait_s']];
                if (!$current) {
                    $values += ['queue' => "{$job}_queue", 'source' => 'manual', 'enabled' => true];
                }
                $db->table('job_schedules')->updateOrInsert(['job' => $job], $values);
            }
        }
        $this->table(['job', 'runs', 'max', 'p95 wait', 'now (run/wait s)', 'suggested', 'from', 'kind'], $rows);

        if ($this->option('apply')) {
            foreach (self::EXPECT_METRIC as $job => $metric) {
                $db->table('job_schedules')->updateOrInsert(['job' => $job], ['expect_metric' => $metric]);
            }
            $this->info('Applied: limits for ' . count(self::ON_DEMAND) . ' on-demand jobs, expect_metric for ' . count(self::EXPECT_METRIC) . ' jobs.');
        } else {
            $this->line('Dry run. --apply writes the on-demand rows and expect_metric.');
        }
        return 0;
    }

    /**
     * max_runtime: 2× the longest run, at least 60 s, never above the job's own timeout (pcntl kills it there).
     * max_wait: 3× the 95th percentile wait, at least 120 s. Too few runs: the timeout / ON_DEMAND_WAIT_S.
     */
    public static function suggest(string $job, array $durationsMs, array $waitsMs): array
    {
        $timeout = self::timeout($job);
        sort($durationsMs);
        sort($waitsMs);
        $max = $durationsMs ? end($durationsMs) : null;
        $p95Wait = $waitsMs ? $waitsMs[(int) floor(0.95 * (count($waitsMs) - 1))] : null;

        if (count($durationsMs) < self::MIN_RUNS) {
            return ['max_runtime_s' => $timeout, 'max_wait_s' => self::ON_DEMAND_WAIT_S, 'max_ms' => $max, 'p95_wait_ms' => $p95Wait,
                'basis' => 'timeout (' . count($durationsMs) . ' runs)'];
        }
        $up10 = fn ($s) => (int) (ceil($s / 10) * 10);
        return [
            'max_runtime_s' => min($timeout, max(60, $up10($max * 2 / 1000))),
            'max_wait_s' => max(120, $up10(($p95Wait ?? 0) * 3 / 1000)),
            'max_ms' => $max,
            'p95_wait_ms' => $p95Wait,
            'basis' => 'data',
        ];
    }

    /** The job's own $timeout, else its worker's --timeout (start-worker.sh). */
    private static function timeout(string $job): int
    {
        foreach (['App\\Jobs\\' . Str::studly($job) . 'Job', 'App\\Jobs\\' . Str::studly($job)] as $class) {
            if (class_exists($class) && ($t = (new \ReflectionClass($class))->getDefaultProperties()['timeout'] ?? null)) {
                return (int) $t;
            }
        }
        return str_starts_with($job, 'oldErp') ? 120 : (int) env('HR_BG_WORKER_TIMEOUT', 300);
    }

    private static function secs(?int $ms): string
    {
        return $ms === null ? '-' : round($ms / 1000, 1) . 's';
    }
}
