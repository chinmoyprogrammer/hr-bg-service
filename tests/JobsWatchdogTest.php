<?php

namespace Tests;

use App\Jobs\SchedulerRegistryJob;
use App\Support\RunTracker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// jobs:watchdog, SchedulerRegistryJob and /api/jobs/health on sqlite (TrackingDatabase).
class JobsWatchdogTest extends TestCase
{
    use TrackingDatabase;

    private array $procs = [];
    private array $queues = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTrackingDatabase();
        Http::fake(['*/api/queues*' => fn () => Http::response($this->queues)]);
    }

    protected function tearDown(): void
    {
        foreach ($this->procs as $proc) {
            proc_terminate($proc);
        }
        parent::tearDown();
    }

    private function watchdog(): void
    {
        $this->artisan('jobs:watchdog');
    }

    private static function ago(int $seconds): string
    {
        return RunTracker::now(false, microtime(true) - $seconds);
    }

    private function db()
    {
        return RunTracker::db();
    }

    private function insertRun(array $row): int
    {
        return $this->db()->table('job_runs')->insertGetId($row + ['run_uuid' => (string) Str::uuid(), 'job' => 'probe', 'status' => 'success']);
    }

    private function runs(string $status)
    {
        return $this->db()->table('job_runs')->where('status', $status)->get();
    }

    /** A live process whose cmdline has "artisan", like a worker. */
    private function fakeWorkerPid(): int
    {
        $proc = proc_open(['php', '-r', 'sleep(30);', 'artisan'], [], $pipes);
        $this->procs[] = $proc;
        usleep(100000);
        return proc_get_status($proc)['pid'];
    }

    private function schedule(array $values): void
    {
        $this->db()->table('job_schedules')->insert($values + [
            'job' => 'probe', 'queue' => 'probe_trigger_queue', 'cron' => '*/5 * * * *', 'source' => 'scheduler',
            'last_expected_at' => null,  // never checked: only the latest slot
        ]);
    }

    public function test_missed_once_per_due_time(): void
    {
        $this->schedule([]);
        $this->insertRun(['trigger_source' => 'scheduler', 'payload' => '{"date":"2026-10-31"}', 'queued_at' => self::ago(3600), 'started_at' => self::ago(3600)]);

        $this->watchdog();
        $this->watchdog();
        $missed = $this->runs('missed');

        $this->assertCount(1, $missed);
        $due = strtotime($missed[0]->queued_at);
        $this->assertLessThanOrEqual(time() - 300, $due);   // at least grace (5 min) ago
        $this->assertGreaterThan(time() - 300 - 301, $due);  // the latest */5 slot before that
        $this->assertSame('{"date":"2026-10-31"}', $missed[0]->payload);
        $this->assertSame('probe_trigger_queue', $missed[0]->queue);
        $this->assertStringStartsWith('Due at ', $missed[0]->error_message);
    }

    public function test_not_missed_when_it_ran_or_was_already_checked(): void
    {
        // A minute job that ran in every slot of the last 10 minutes
        $this->schedule(['cron' => '* * * * *']);
        $minute = intdiv(time(), 60) * 60;
        for ($i = 0; $i <= 10; $i++) {
            $this->insertRun(['queued_at' => RunTracker::now(false, $minute - 60 * $i + 0.2)]);
        }
        $this->watchdog();
        $this->assertCount(0, $this->runs('missed'));

        $this->db()->table('job_schedules')->insert(['job' => 'fresh', 'cron' => '* * * * *', 'last_expected_at' => RunTracker::now()]);
        $this->db()->table('job_schedules')->insert(['job' => 'off', 'cron' => '* * * * *', 'enabled' => false, 'last_expected_at' => self::ago(86400)]);
        $this->watchdog();
        $this->assertCount(0, $this->runs('missed'));
    }

    public function test_every_slot_since_the_last_check(): void
    {
        // Watchdog down for a while: the 3 minute slots since the last check are all checked
        $cutoffMinute = intdiv(time() - 300, 60) * 60;
        $this->schedule(['cron' => '* * * * *', 'last_expected_at' => date('Y-m-d H:i:s', $cutoffMinute - 180)]);
        $this->insertRun(['queued_at' => date('Y-m-d H:i:s', $cutoffMinute - 60) . '.200']);  // the middle one ran
        $this->watchdog();

        $this->assertSame(
            [date('Y-m-d H:i:s', $cutoffMinute - 120), date('Y-m-d H:i:s', $cutoffMinute)],
            $this->runs('missed')->pluck('queued_at')->all()
        );
        $this->assertSame(date('Y-m-d H:i:s', $cutoffMinute), $this->db()->table('job_schedules')->value('last_expected_at'));
    }

    public function test_next_slots_run_does_not_cover_a_skipped_slot(): void
    {
        $this->schedule([]);  // */5, grace 5: checks the slot 5-10 min ago
        $this->insertRun(['queued_at' => self::ago(30), 'started_at' => self::ago(29)]);
        $this->watchdog();

        $this->assertCount(1, $this->runs('missed'));
    }

    public function test_dead_worker_is_lost_and_overrun_is_timed_out(): void
    {
        $host = gethostname();
        $dead = $this->insertRun(['status' => 'running', 'started_at' => self::ago(120), 'worker_host' => $host, 'worker_pid' => 999999]);
        $justStarted = $this->insertRun(['status' => 'running', 'started_at' => self::ago(10), 'worker_host' => $host, 'worker_pid' => 999999]);
        $otherHost = $this->insertRun(['status' => 'running', 'started_at' => self::ago(120), 'worker_host' => 'old-container', 'worker_pid' => getmypid()]);
        $pid = $this->fakeWorkerPid();
        $overrun = $this->insertRun(['status' => 'running', 'started_at' => self::ago(1000), 'worker_host' => $host, 'worker_pid' => $pid]);
        $busy = $this->insertRun(['status' => 'running', 'started_at' => self::ago(120), 'worker_host' => $host, 'worker_pid' => $pid]);

        $this->watchdog();
        $status = fn ($id) => $this->db()->table('job_runs')->where('id', $id)->value('status');

        $this->assertSame('lost', $status($dead));
        $this->assertSame('running', $status($justStarted));
        $this->assertSame('lost', $status($otherHost));
        $this->assertSame('timed_out', $status($overrun));
        $this->assertSame('running', $status($busy));
    }

    public function test_late_flag_on_long_queued_runs(): void
    {
        $old = $this->insertRun(['status' => 'queued', 'queued_at' => self::ago(600)]);
        $recent = $this->insertRun(['status' => 'queued', 'queued_at' => self::ago(60)]);
        $this->watchdog();
        $this->watchdog();

        $this->assertSame('["late"]', $this->db()->table('job_runs')->where('id', $old)->value('flags'));
        $this->assertNull($this->db()->table('job_runs')->where('id', $recent)->value('flags'));
    }

    public function test_stuck_queue_after_ten_minutes_without_deliveries(): void
    {
        $this->queues = [
            ['name' => 'legacySync_trigger_queue', 'messages_ready' => 2],
            ['name' => 'busy_queue', 'messages_ready' => 5, 'message_stats' => ['deliver_get' => 10]],
            ['name' => 'busy_queue.delay.30000', 'messages_ready' => 1],
        ];
        $this->watchdog();
        $this->assertCount(0, $this->runs('stuck'));

        // 11 minutes later; busy_queue delivered more in between
        Cache::put('jobs_watchdog:queue:legacySync_trigger_queue', ['since' => time() - 660, 'delivered' => 0, 'run_id' => null], 60);
        Cache::put('jobs_watchdog:queue:busy_queue', ['since' => time() - 660, 'delivered' => 7, 'run_id' => null], 60);
        $this->watchdog();
        $this->watchdog();
        $stuck = $this->runs('stuck');

        $this->assertCount(1, $stuck);
        $this->assertSame('legacySync', $stuck[0]->job);
        $this->assertSame('2 message(s) waiting in legacySync_trigger_queue, none delivered for 11 min', $stuck[0]->error_message);

        // Drained: the episode ends, a later one is a new row
        $this->queues = [['name' => 'legacySync_trigger_queue', 'messages_ready' => 0]];
        $this->watchdog();
        $this->assertNull(Cache::get('jobs_watchdog:queue:legacySync_trigger_queue'));
    }

    public function test_orphaned_queued_and_retrying_rows_are_lost(): void
    {
        $orphan = $this->insertRun(['status' => 'queued', 'queue' => 'a_queue', 'queued_at' => self::ago(600)]);
        $waiting = $this->insertRun(['status' => 'queued', 'queue' => 'b_queue', 'queued_at' => self::ago(600)]);
        $justPushed = $this->insertRun(['status' => 'queued', 'queue' => 'a_queue', 'queued_at' => self::ago(30)]);
        $retryInDelay = $this->insertRun(['status' => 'retrying', 'queue' => 'c_queue', 'finished_at' => self::ago(300)]);
        $retryGone = $this->insertRun(['status' => 'retrying', 'queue' => 'd_queue', 'finished_at' => self::ago(300)]);
        $inFlight = $this->insertRun(['status' => 'queued', 'queue' => 'e_queue', 'queued_at' => self::ago(600)]);
        $this->queues = [
            ['name' => 'a_queue', 'messages_ready' => 0],
            ['name' => 'b_queue', 'messages_ready' => 3],
            ['name' => 'c_queue', 'messages_ready' => 0],
            ['name' => 'c_queue.delay.120000', 'messages_ready' => 1],
            ['name' => 'e_queue', 'messages_ready' => 0, 'messages_unacknowledged' => 1],
        ];  // d_queue: no longer exists at all
        $this->watchdog();
        $status = fn ($id) => $this->db()->table('job_runs')->where('id', $id)->value('status');

        $this->assertSame('lost', $status($orphan));
        $this->assertSame('queued', $status($waiting));
        $this->assertSame('queued', $status($justPushed));
        $this->assertSame('retrying', $status($retryInDelay));
        $this->assertSame('lost', $status($retryGone));
        $this->assertSame('queued', $status($inFlight));
        $this->assertStringContainsString('the message is gone', $this->db()->table('job_runs')->where('id', $orphan)->value('error_message'));
    }

    public function test_rabbitmq_api_down_does_not_stop_other_checks(): void
    {
        Http::fake(['*' => Http::response('down', 503)]);
        $this->schedule([]);
        $this->watchdog();

        $this->assertCount(1, $this->runs('missed'));
        $this->assertNotNull($this->db()->table('heartbeats')->where('name', 'watchdog')->value('beat_at'));
    }

    public function test_silent_heartbeats(): void
    {
        $host = gethostname();
        $beat = fn ($name, $age, $info = []) => $this->db()->table('heartbeats')->insert(['name' => $name, 'beat_at' => self::ago($age), 'info' => json_encode($info)]);
        $beat('scheduler', 600);
        $beat('trigger_consumer', 20);
        $beat('worker:busy', 900, ['host' => $host, 'pid' => $this->fakeWorkerPid()]);
        $beat('worker:dead', 900, ['host' => $host, 'pid' => 999999]);

        $this->watchdog();
        $this->watchdog();

        $this->assertSame(['heartbeat:scheduler', 'heartbeat:worker:dead'], $this->runs('stuck')->pluck('job')->sort()->values()->all());
    }

    public function test_flags_on_finished_runs(): void
    {
        $this->schedule(['expect_metric' => 'rows']);
        for ($i = 0; $i < 12; $i++) {
            $this->insertRun(['duration_ms' => 1000, 'metrics' => '{"rows":100}', 'finished_at' => self::ago(3600)]);
        }
        Cache::forever('jobs_watchdog:flags_cursor', self::ago(60));

        $slow = $this->insertRun(['duration_ms' => 40000, 'metrics' => '{"rows":100}', 'finished_at' => self::ago(30)]);
        $late = $this->insertRun(['wait_ms' => 400000, 'duration_ms' => 1000, 'metrics' => '{"rows":100}', 'finished_at' => self::ago(20)]);
        $zero = $this->insertRun(['duration_ms' => 1000, 'metrics' => '{"rows":0}', 'finished_at' => self::ago(10)]);
        $fine = $this->insertRun(['duration_ms' => 1500, 'metrics' => '{"rows":50}', 'finished_at' => self::ago(5)]);
        $this->watchdog();
        $flags = fn ($id) => $this->db()->table('job_runs')->where('id', $id)->value('flags');

        $this->assertSame('["slow"]', $flags($slow));
        $this->assertSame('["late"]', $flags($late));
        $this->assertSame('["zero_output"]', $flags($zero));
        $this->assertNull($flags($fine));
        $this->assertNull($this->db()->table('job_runs')->orderBy('id')->value('flags'));  // before the cursor
    }

    public function test_retention(): void
    {
        $oldLog = $this->insertRun(['log' => 'x', 'finished_at' => self::ago(8 * 86400), 'queued_at' => self::ago(8 * 86400)]);
        $oldFailedLog = $this->insertRun(['status' => 'failed', 'log' => 'x', 'finished_at' => self::ago(8 * 86400), 'queued_at' => self::ago(8 * 86400)]);
        $ancient = $this->insertRun(['queued_at' => self::ago(91 * 86400)]);
        $ancientNoQueue = $this->insertRun(['started_at' => self::ago(91 * 86400)]);
        $this->db()->table('job_alerts')->insert(['job' => 'probe', 'kind' => 'failed', 'opened_at' => self::ago(95 * 86400), 'resolved_at' => self::ago(91 * 86400)]);

        $this->watchdog();
        $runs = $this->db()->table('job_runs')->get()->keyBy('id');

        $this->assertNull($runs[$oldLog]->log);
        $this->assertSame('x', $runs[$oldFailedLog]->log);
        $this->assertFalse($runs->has($ancient));
        $this->assertFalse($runs->has($ancientNoQueue));
        $this->assertSame(0, $this->db()->table('job_alerts')->count());
    }

    public function test_scheduler_registry(): void
    {
        $this->db()->table('job_schedules')->insert(['job' => 'removed', 'cron' => '* * * * *', 'source' => 'scheduler']);
        $this->db()->table('job_schedules')->insert(['job' => 'processTempData', 'cron' => '0 * * * *', 'source' => 'scheduler', 'grace_minutes' => 9, 'last_expected_at' => '2026-01-01 00:00:00']);

        (new SchedulerRegistryJob(['jobs' => [
            ['id' => 'processTempData_job', 'job' => 'processTempData', 'cron' => '0 */1 * * *', 'timezone' => 'Asia/Dhaka'],
            ['id' => 'confirmProvisionalEmployees_job', 'job' => 'confirmProvisionalEmployees', 'cron' => '*/1 * * * *', 'timezone' => 'Asia/Dhaka'],
            ['id' => 'broken_job', 'job' => 'broken', 'cron' => 'not a cron'],
        ]]))->handle();
        $s = $this->db()->table('job_schedules')->get()->keyBy('job');

        $this->assertSame('0 */1 * * *', $s['processTempData']->cron);
        $this->assertSame(9, (int) $s['processTempData']->grace_minutes);         // local tuning kept
        $this->assertSame('2026-01-01 00:00:00', $s['processTempData']->last_expected_at);
        $this->assertSame('confirmProvisionalEmployees_trigger_queue', $s['confirmProvisionalEmployees']->queue);
        $this->assertNotNull($s['confirmProvisionalEmployees']->last_expected_at);  // new: only due times from now on
        $this->assertFalse($s->has('broken'));
        $this->assertSame(0, (int) $s['removed']->enabled);
        $this->assertNotNull($this->db()->table('heartbeats')->where('name', 'scheduler')->value('beat_at'));
    }

    public function test_health_endpoint(): void
    {
        $this->db()->table('heartbeats')->insert(['name' => 'watchdog', 'beat_at' => self::ago(42)]);

        $this->get('/api/jobs/health');
        $this->assertNull($this->db()->table('heartbeats')->where('name', 'scheduler')->value('beat_at'));

        $this->get('/api/jobs/health', ['X-Caller' => 'task-scheduler']);
        $body = json_decode($this->response->getContent(), true);
        $this->assertSame('ok', $body['status']);
        $this->assertEqualsWithDelta(42, $body['heartbeats']['watchdog'], 2);
        $this->assertNotNull($this->db()->table('heartbeats')->where('name', 'scheduler')->value('beat_at'));
    }
}
