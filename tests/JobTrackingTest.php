<?php

namespace Tests;

use App\Support\RunTracker;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// JobTrackingServiceProvider driven through the real queue Worker. `mysql` and `tracking` are two
// separate in-memory sqlite DBs, so nothing touches the shared database.
class JobTrackingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RunTracker::$current = null;
        TrackingTestHandler::$ran = false;

        config(['logging.channels.discard' => ['driver' => 'monolog', 'handler' => \Monolog\Handler\NullHandler::class], 'logging.default' => 'discard']);
        $this->app->configure('database');
        $sqlite = ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''];
        config(['database.connections.mysql' => $sqlite, 'database.connections.tracking' => $sqlite, 'database.default' => 'tracking']);
        (require __DIR__ . '/../database/migrations/2026_11_01_100000_create_job_tracking_tables.php')->up();
        config(['database.default' => 'mysql']);
        DB::statement('create table probe (id integer primary key autoincrement, v integer)');
    }

    private function job(string $mode, array $extra = []): SyncJob
    {
        $payload = $extra + [
            'uuid' => (string) Str::uuid(),
            'displayName' => 'Tests\\TrackingTestHandler',
            'job' => 'Tests\\TrackingTestHandler@fire',
            'maxTries' => 3,
            'data' => ['mode' => $mode],
            'pushed_at' => microtime(true) - 0.25,
        ];
        // connection 'rabbitmq' so the provider treats it like a worker job, not the sync driver
        return new SyncJob($this->app, json_encode($payload), 'rabbitmq', 'probe_queue');
    }

    private function work(SyncJob $job, int $maxTries = 3): ?\Throwable
    {
        try {
            $this->app['queue.worker']->process('rabbitmq', $job, new WorkerOptions(maxTries: $maxTries));
        } catch (\Throwable $e) {
            return $e;
        }
        return null;
    }

    private function runs()
    {
        return DB::connection('tracking')->table('job_runs')->orderBy('id')->get();
    }

    public function test_success(): void
    {
        $this->assertNull($this->work($this->job('write')));
        $run = $this->runs()->sole();

        $this->assertSame('success', $run->status);
        $this->assertSame('Tests\\TrackingTestHandler', $run->job_class);  // job is 'sync': SyncJob::getQueue() is hard-coded
        $this->assertSame(1, (int) $run->writes_kept);
        $this->assertSame(1, (int) $run->attempt);
        $this->assertSame(3, (int) $run->max_attempts);
        $this->assertSame('api', $run->trigger_source);
        $this->assertGreaterThanOrEqual(250, (int) $run->wait_ms);
        $this->assertNotNull($run->finished_at);
        $this->assertSame([['l' => 'info', 'm' => 'probe wrote']], array_map(
            fn ($l) => array_diff_key($l, ['t' => 0]),
            json_decode(gzdecode($run->log), true)
        ));
        $this->assertNull(RunTracker::$current);
    }

    public function test_exception_before_last_attempt_is_retrying(): void
    {
        $job = $this->job('throw');
        $this->assertNotNull($this->work($job));
        $run = $this->runs()->sole();

        $this->assertSame('retrying', $run->status);
        $this->assertSame('probe throw', $run->error_message);
        $this->assertTrue($job->isReleased());
    }

    public function test_exception_on_last_attempt_is_failed(): void
    {
        $job = $this->job('throw', ['maxTries' => 1]);
        $this->work($job, 1);

        $this->assertSame('failed', $this->runs()->sole()->status);
        $this->assertTrue($job->hasFailed());
    }

    public function test_exception_after_commit_is_not_retried(): void
    {
        $job = $this->job('write_throw');
        $this->work($job);
        $run = $this->runs()->sole();

        $this->assertSame('failed_retry_unsafe', $run->status);
        $this->assertSame(1, (int) $run->writes_kept);
        $this->assertTrue($job->hasFailed());
        $this->assertFalse($job->isReleased());
    }

    public function test_swallowed_rollback(): void
    {
        $this->work($this->job('swallow'));
        $run = $this->runs()->sole();

        $this->assertSame('rolled_back', $run->status);
        $this->assertSame(1, (int) $run->writes_undone);
        $this->assertSame('probe insert failed', $run->error_message);
        $this->assertSame(0, DB::table('probe')->count());
    }

    public function test_open_transaction_is_rolled_back(): void
    {
        $this->work($this->job('open_tx'));

        $this->assertSame('rolled_back', $this->runs()->sole()->status);
        $this->assertSame(0, DB::connection('mysql')->transactionLevel());
        $this->assertSame(0, DB::table('probe')->count());
    }

    public function test_trigger_row_is_updated_not_duplicated(): void
    {
        $uuid = (string) Str::uuid();
        DB::connection('tracking')->table('job_runs')->insert([
            'run_uuid' => $uuid, 'job' => 'probe', 'status' => 'queued', 'trigger_source' => 'scheduler', 'queued_at' => RunTracker::now(),
        ]);
        $this->work($this->job('write', ['run_uuid' => $uuid]));
        $run = $this->runs()->sole();

        $this->assertSame($uuid, $run->run_uuid);
        $this->assertSame('success', $run->status);
        $this->assertSame('scheduler', $run->trigger_source);
    }

    public function test_queue_retry_makes_a_linked_run(): void
    {
        $uuid = (string) Str::uuid();
        $parent = DB::connection('tracking')->table('job_runs')->insertGetId([
            'run_uuid' => $uuid, 'job' => 'probe', 'status' => 'failed', 'attempt' => 1,
        ]);
        $this->work($this->job('write', ['uuid' => $uuid]));
        $run = $this->runs()->last();

        $this->assertNotSame($uuid, $run->run_uuid);
        $this->assertSame($parent, (int) $run->parent_run_id);
        $this->assertSame('retry', $run->trigger_source);
        $this->assertNull($run->wait_ms);
        $this->assertNull($run->queued_at);
        $this->assertSame('success', $run->status);
    }

    public function test_crash_loop_guard(): void
    {
        $uuid = (string) Str::uuid();
        $db = DB::connection('tracking')->table('job_runs');
        $root = $db->insertGetId(['run_uuid' => $uuid, 'job' => 'probe', 'status' => 'lost']);
        $db->insert(['run_uuid' => (string) Str::uuid(), 'job' => 'probe', 'status' => 'timed_out', 'parent_run_id' => $root]);

        // 2 crashes: still runs
        $this->work($this->job('write', ['uuid' => $uuid]));
        $this->assertTrue(TrackingTestHandler::$ran);
        $this->assertSame('success', $this->runs()->last()->status);

        // a success breaks the streak; 3 more crashes and it stops
        foreach (['lost', 'lost', 'running'] as $status) {
            $db->insert(['run_uuid' => (string) Str::uuid(), 'job' => 'probe', 'status' => $status, 'parent_run_id' => $root]);
        }
        TrackingTestHandler::$ran = false;
        $job = $this->job('write', ['uuid' => $uuid]);
        $this->work($job);
        $run = $this->runs()->last();

        $this->assertFalse(TrackingTestHandler::$ran);
        $this->assertTrue($job->hasFailed());
        $this->assertSame('failed', $run->status);
        $this->assertStringStartsWith('Worker died 3 times in a row', $run->error_message);
    }

    public function test_sync_driver_failure_is_failed_not_retrying(): void
    {
        try {
            Queue::connection('sync')->push('Tests\\TrackingTestHandler@fire', ['mode' => 'throw']);
            $this->fail('expected the job exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('probe throw', $e->getMessage());
        }
        $this->assertSame('failed', $this->runs()->sole()->status);
    }

    public function test_run_id_is_shared_during_the_run_only(): void
    {
        $this->work($this->job('log_context'));
        $this->assertSame($this->runs()->sole()->run_uuid, TrackingTestHandler::$context['run_id'] ?? null);

        $seen = null;
        $this->app['events']->listen(\Illuminate\Log\Events\MessageLogged::class, function ($e) use (&$seen) {
            $seen = $e->context;
        });
        Log::info('after the run');
        $this->assertArrayNotHasKey('run_id', $seen);
    }
}

class TrackingTestHandler
{
    public static bool $ran = false;
    public static array $context = [];

    public function fire($job, array $data): void
    {
        self::$ran = true;
        $insert = fn () => DB::table('probe')->insert(['v' => 1]);

        switch ($data['mode']) {
            case 'write':
                $insert();
                Log::info('probe wrote');
                break;
            case 'throw':
                throw new \RuntimeException('probe throw');
            case 'write_throw':
                $insert();
                throw new \RuntimeException('probe throw after commit');
            case 'swallow':
                DB::beginTransaction();
                $insert();
                DB::rollBack();
                Log::error('probe insert failed');
                break;
            case 'open_tx':
                DB::beginTransaction();
                $insert();
                break;
            case 'log_context':
                app('events')->listen(\Illuminate\Log\Events\MessageLogged::class, function ($e) {
                    self::$context = $e->context;
                });
                Log::info('inside the run');
                break;
        }
    }
}
