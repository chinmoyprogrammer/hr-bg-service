<?php

namespace App\Jobs;

use App\Support\RunTracker;
use Cron\CronExpression;
use Illuminate\Support\Facades\Log;

/**
 * task-scheduler publishes its cron jobs on startup (schedulerRegistry_trigger_queue). Keeps
 * job_schedules in step with them, so the watchdog knows when each job is due. The CRON_*
 * env vars in task-scheduler stay the one source of truth.
 */
class SchedulerRegistryJob extends Job
{
    protected array $payload;

    public function __construct(array $payload)
    {
        $this->payload = $payload;
    }

    public function handle(): void
    {
        $now = RunTracker::now();
        $seen = [];
        foreach ($this->payload['jobs'] ?? [] as $entry) {
            $job = (string) ($entry['job'] ?? '');
            $cron = (string) ($entry['cron'] ?? '');
            if ($job === '' || !CronExpression::isValidExpression($cron)) {
                Log::warning('SchedulerRegistryJob: skipped entry', ['entry' => $entry]);
                continue;
            }
            $seen[] = $job;
            $values = [
                'queue' => $job . '_trigger_queue',
                'cron' => $cron,
                'timezone' => (string) ($entry['timezone'] ?? 'Asia/Dhaka'),
                'enabled' => true,
                'source' => 'scheduler',
                'synced_at' => $now,
            ];
            if (RunTracker::db()->table('job_schedules')->where('job', $job)->exists()) {
                RunTracker::db()->table('job_schedules')->where('job', $job)->update($values);
            } else {
                // Only due times from now on count; runs before tracking existed aren't "missed"
                RunTracker::db()->table('job_schedules')->insert($values + ['job' => $job, 'last_expected_at' => $now]);
            }
        }

        // Removed from the scheduler: stop expecting it
        RunTracker::db()->table('job_schedules')->where('source', 'scheduler')->whereNotIn('job', $seen ?: [''])->update(['enabled' => false]);
        RunTracker::beat('scheduler', ['via' => 'registry', 'jobs' => count($seen)]);
        Log::info('SchedulerRegistryJob: ' . count($seen) . ' schedules synced');
    }
}
