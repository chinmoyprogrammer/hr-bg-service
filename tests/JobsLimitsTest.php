<?php

namespace Tests;

use App\Console\Commands\JobsLimits;
use App\Support\RunTracker;
use Illuminate\Support\Str;

// jobs:limits on sqlite (TrackingDatabase).
class JobsLimitsTest extends TestCase
{
    use TrackingDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTrackingDatabase();
    }

    public function test_suggestions(): void
    {
        // Enough runs: 2x the longest (rounded up to 10 s), 3x the p95 wait, with floors
        $s = JobsLimits::suggest('cacheRegenerate', array_fill(0, 9, 3000) + [9 => 41000], array_fill(0, 10, 50000));
        $this->assertSame(['max_runtime_s' => 90, 'max_wait_s' => 150, 'basis' => 'data'], array_intersect_key($s, array_flip(['max_runtime_s', 'max_wait_s', 'basis'])));

        $s = JobsLimits::suggest('confirmProvisionalEmployees', array_fill(0, 20, 13), array_fill(0, 20, 300));
        $this->assertSame([60, 120], [$s['max_runtime_s'], $s['max_wait_s']]);

        // Never above the job's own timeout: ProcessTempSalaryJob::$timeout = 600
        $this->assertSame(600, JobsLimits::suggest('processTempSalary', array_fill(0, 10, 900000), [])['max_runtime_s']);

        // Too few runs: the timeout (job's own, worker default, or the old-ERP worker's 120 s)
        $this->assertSame([600, 600], array_values(array_intersect_key(JobsLimits::suggest('processTempSalary', [1000], []), ['max_runtime_s' => 0, 'max_wait_s' => 0])));
        $this->assertSame(300, JobsLimits::suggest('sendBulkSMS', [], [])['max_runtime_s']);
        $this->assertSame(120, JobsLimits::suggest('oldErpLoanSync', [], [])['max_runtime_s']);
        $this->assertSame('timeout (1 runs)', JobsLimits::suggest('processTempSalary', [1000], [])['basis']);
    }

    public function test_dry_run_writes_nothing_and_apply_writes_on_demand_rows_and_metrics(): void
    {
        $db = RunTracker::db();
        $db->table('job_schedules')->insert(['job' => 'processTempData', 'cron' => '0 */1 * * *', 'source' => 'scheduler', 'max_runtime_s' => 600, 'max_wait_s' => 300]);
        for ($i = 0; $i < 12; $i++) {
            $db->table('job_runs')->insert(['run_uuid' => (string) Str::uuid(), 'job' => 'cacheRegenerate', 'status' => 'success',
                'started_at' => RunTracker::now(), 'duration_ms' => 5000, 'wait_ms' => 1000]);
        }

        $this->artisan('jobs:limits');
        $this->assertSame(1, $db->table('job_schedules')->count());

        $this->artisan('jobs:limits', ['--apply' => true]);
        $s = $db->table('job_schedules')->get()->keyBy('job');

        $this->assertCount(count(JobsLimits::ON_DEMAND) + 1, $s);
        $this->assertSame([60, 120, 'manual', 'cacheRegenerate_queue'], [(int) $s['cacheRegenerate']->max_runtime_s, (int) $s['cacheRegenerate']->max_wait_s, $s['cacheRegenerate']->source, $s['cacheRegenerate']->queue]);
        $this->assertSame([600, 600, 'employees'], [(int) $s['processTempSalary']->max_runtime_s, (int) $s['processTempSalary']->max_wait_s, $s['processTempSalary']->expect_metric]);
        $this->assertNull($s['processTempSalary']->cron);  // no cron: the missed check skips it

        // Cron job: limits and source untouched, only expect_metric set
        $this->assertSame(['scheduler', 600, 300, 'attendance'], [$s['processTempData']->source, (int) $s['processTempData']->max_runtime_s, (int) $s['processTempData']->max_wait_s, $s['processTempData']->expect_metric]);
        $this->assertNull($s['employeeTransferApply'] ?? null);  // 0 applied is normal: not watched
    }

    public function test_rabbitmq_job_failures_now_reach_the_worker(): void
    {
        $conn = \Mockery::mock();
        $conn->shouldReceive('to->pushRaw')->andThrow(new \RuntimeException('channel closed'));
        \Illuminate\Support\Facades\Queue::shouldReceive('connection')->with('rabbitmq')->andReturn($conn);
        $job = new \App\Jobs\RabbitMQJob(['date' => '2026-11-02'], 'other_queue');
        $job->setJob(new \Illuminate\Queue\Jobs\SyncJob($this->app, '{}', 'rabbitmq', 'this_queue'));

        $this->expectExceptionMessage('channel closed');
        $job->handle();
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }
}
