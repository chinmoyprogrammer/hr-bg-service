<?php

namespace Tests;

use App\Support\RunTracker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// Alerts sent by jobs:watchdog (App\Support\Alert), on sqlite (TrackingDatabase). The webhook is faked.
class AlertTest extends TestCase
{
    use TrackingDatabase;

    const HOOK = 'http://hooks.test/alerts';

    private array $queues = [];
    private int $hookStatus = 200;
    private array $env = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTrackingDatabase();
        $this->env(['JOB_ALERT_WEBHOOK_URL' => self::HOOK, 'JOB_DIGEST_AT' => '24:00', 'JOB_ALERT_MUTE' => '']);
        Http::fake([
            '*/api/queues*' => fn () => Http::response($this->queues),
            'hooks.test/*' => fn () => Http::response('ok', $this->hookStatus),
        ]);
        Cache::forever('jobs_watchdog:alerts_cursor', RunTracker::now(false, microtime(true) - 7200));
    }

    protected function tearDown(): void
    {
        foreach ($this->env as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
        parent::tearDown();
    }

    private function env(array $values): void
    {
        foreach ($values as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
            $this->env[] = $key;
        }
    }

    private function watchdog(): void
    {
        $this->artisan('jobs:watchdog');
    }

    /** Texts posted to the webhook so far. */
    private function sent(): array
    {
        return Http::recorded(fn ($request) => str_starts_with($request->url(), self::HOOK))
            ->map(fn ($pair) => $pair[0]['text'])->values()->all();
    }

    private function finished(string $status, array $extra = []): int
    {
        usleep(2000);  // distinct finished_at
        return RunTracker::db()->table('job_runs')->insertGetId($extra + [
            'run_uuid' => (string) Str::uuid(), 'job' => 'probe', 'status' => $status,
            'attempt' => 3, 'max_attempts' => 3, 'duration_ms' => 1234, 'finished_at' => RunTracker::now(),
            'error_message' => $status === 'success' ? null : "SQLSTATE[HY000] [2002] Connection refused\n#0 trace",
        ]);
    }

    private function alert(string $kind = 'failed'): ?object
    {
        return RunTracker::db()->table('job_alerts')->where('job', 'probe')->where('kind', $kind)->first();
    }

    public function test_failing_every_minute_sends_one_alert_hourly_reminders_and_one_resolved(): void
    {
        $first = $this->finished('failed');
        $this->watchdog();
        $this->assertCount(1, $this->sent());
        $this->assertSame(
            ":red_circle: *failed* `probe`\nReason: SQLSTATE[HY000] [2002] Connection refused\nrun #{$first} · attempt 3/3 · 1.2s",
            $this->sent()[0]
        );

        // 29 more minutes of failures: counted, not sent
        for ($i = 0; $i < 29; $i++) {
            $this->finished('failed');
            $this->watchdog();
        }
        $this->assertCount(1, $this->sent());
        $this->assertSame(30, (int) $this->alert()->occurrences);

        // An hour after the last message: one reminder with the count
        RunTracker::db()->table('job_alerts')->update(['last_sent_at' => RunTracker::now(false, microtime(true) - 3601)]);
        $this->finished('failed');
        $this->finished('failed');
        $this->watchdog();
        $this->assertCount(2, $this->sent());
        // Sent on the first new failure (31st); the second in the same minute is only counted
        $this->assertStringStartsWith(':red_circle: *still failed* `probe`: 31× since ', $this->sent()[1]);
        $this->assertStringContainsString('Latest: SQLSTATE', $this->sent()[1]);
        $this->assertSame(32, (int) $this->alert()->occurrences);

        // Passes again: one resolved, then quiet
        $ok = $this->finished('success');
        $this->watchdog();
        $this->finished('success');
        $this->watchdog();
        $this->assertCount(3, $this->sent());
        $this->assertStringStartsWith(':large_green_circle: *resolved* `probe` failed: OK again after 32× since ', $this->sent()[2]);
        $this->assertStringEndsWith("(run #{$ok})", $this->sent()[2]);
        $this->assertNotNull($this->alert()->resolved_at);

        // Fails again later: a new alert
        $this->finished('failed');
        $this->watchdog();
        $this->assertCount(4, $this->sent());
        $this->assertSame(2, RunTracker::db()->table('job_alerts')->count());
    }

    public function test_kinds_are_separate_and_messages_carry_details(): void
    {
        $this->finished('failed_retry_unsafe', ['writes_kept' => 3, 'writes_undone' => 0]);
        $this->finished('partial', ['writes_kept' => 2, 'writes_undone' => 1, 'error_message' => 'employee 3 has no official info']);
        $this->watchdog();
        [$unsafe, $partial] = $this->sent();

        $this->assertStringStartsWith(':red_circle: *failed_retry_unsafe* `probe`', $unsafe);
        $this->assertStringContainsString('writes kept 3 / undone 0', $unsafe);
        $this->assertStringContainsString('Retry unsafe: it had already committed writes', $unsafe);
        $this->assertStringStartsWith(":large_orange_circle: *partial* `probe`\nReason: employee 3 has no official info", $partial);
        $this->assertStringContainsString('writes kept 2 / undone 1', $partial);

        // One success resolves both
        $this->finished('success');
        $this->watchdog();
        $this->assertCount(4, $this->sent());
        $this->assertSame(0, RunTracker::db()->table('job_alerts')->whereNull('resolved_at')->count());
    }

    public function test_flag_alert_resolves_on_the_next_run_without_the_flag(): void
    {
        $this->finished('success', ['flags' => '["late"]', 'wait_ms' => 96500]);
        $this->watchdog();
        $this->assertStringStartsWith(':large_orange_circle: *late* `probe`', $this->sent()[0]);
        $this->assertStringContainsString('waited 96.5s', $this->sent()[0]);

        $this->finished('success', ['flags' => '["late"]', 'wait_ms' => 50000]);
        $this->watchdog();
        $this->assertCount(1, $this->sent());

        $this->finished('success');
        $this->watchdog();
        $this->assertCount(2, $this->sent());
        $this->assertStringStartsWith(':large_green_circle: *resolved* `probe` late', $this->sent()[1]);
    }

    public function test_stuck_queue_alerts_once_and_resolves_when_it_drains(): void
    {
        $this->queues = [['name' => 'legacySync_trigger_queue', 'messages_ready' => 2]];
        Cache::put('jobs_watchdog:queue:legacySync_trigger_queue', ['since' => time() - 660, 'delivered' => 0, 'run_id' => null], 600);
        $this->watchdog();
        $this->watchdog();  // same stuck row updated: no second message
        $this->assertCount(1, $this->sent());
        $this->assertStringStartsWith(":large_orange_circle: *stuck* `legacySync`\nReason: 2 message(s) waiting", $this->sent()[0]);

        $this->queues = [['name' => 'legacySync_trigger_queue', 'messages_ready' => 0]];
        $this->watchdog();
        $this->assertCount(2, $this->sent());
        $this->assertStringStartsWith(':large_green_circle: *resolved* `legacySync` stuck', $this->sent()[1]);
    }

    public function test_silent_heartbeat_alerts_and_resolves(): void
    {
        $db = RunTracker::db();
        $db->table('heartbeats')->insert(['name' => 'scheduler', 'beat_at' => RunTracker::now(false, microtime(true) - 600)]);
        $this->watchdog();
        $this->assertStringStartsWith(':large_orange_circle: *stuck* `heartbeat:scheduler`', $this->sent()[0]);

        $db->table('heartbeats')->update(['beat_at' => RunTracker::now()]);
        $this->watchdog();
        $this->assertStringStartsWith(':large_green_circle: *resolved* `heartbeat:scheduler` stuck', $this->sent()[1]);
    }

    public function test_webhook_down_retries_until_sent(): void
    {
        $this->hookStatus = 500;
        $this->finished('failed');
        $this->watchdog();
        $this->assertNull($this->alert()->last_sent_at);

        $this->hookStatus = 200;
        $this->watchdog();  // no new run: retried anyway
        $this->assertNotNull($this->alert()->last_sent_at);
        $this->assertCount(2, $this->sent());
        $this->assertSame($this->sent()[0], $this->sent()[1]);
    }

    public function test_unsent_alert_is_announced_before_its_resolution(): void
    {
        $this->hookStatus = 500;
        $this->finished('failed');
        $this->watchdog();
        $this->finished('success');
        $this->hookStatus = 200;
        $this->watchdog();  // retryUnsent first, then the success resolves it

        $texts = array_slice($this->sent(), -2);
        $this->assertStringStartsWith(':red_circle: *failed*', $texts[0]);
        $this->assertStringStartsWith(':large_green_circle: *resolved*', $texts[1]);
    }

    public function test_never_announced_alert_resolves_silently(): void
    {
        $this->hookStatus = 500;  // down the whole time
        $this->finished('failed');
        $this->watchdog();
        $this->finished('success');
        $this->watchdog();

        $this->assertNotNull($this->alert()->resolved_at);
        $this->assertCount(0, array_filter(Http::recorded()->all(), fn ($p) => $p[1]->successful() && str_starts_with($p[0]->url(), self::HOOK)));
        $this->assertCount(0, array_filter($this->sent(), fn ($t) => str_contains($t, 'resolved')));
    }

    public function test_without_a_webhook_alerts_are_recorded_and_logged_only(): void
    {
        $this->env(['JOB_ALERT_WEBHOOK_URL' => '']);
        $this->finished('failed');
        $this->watchdog();

        $this->assertSame([], $this->sent());
        $this->assertNotNull($this->alert()->last_sent_at);
    }

    public function test_muted_jobs_are_not_alerted(): void
    {
        $this->env(['JOB_ALERT_MUTE' => 'other, probe']);
        $this->finished('failed');
        $this->watchdog();

        $this->assertSame([], $this->sent());
        $this->assertNull($this->alert());
    }

    public function test_daily_digest_once_a_day(): void
    {
        $this->finished('success', ['duration_ms' => 21300]);
        $this->finished('success', ['duration_ms' => 700]);
        $this->finished('failed');  // last, so the alert is still open
        $this->env(['JOB_DIGEST_AT' => '00:00']);
        $this->watchdog();
        $this->watchdog();
        $digests = array_values(array_filter($this->sent(), fn ($t) => str_starts_with($t, '*Job digest*')));

        $this->assertCount(1, $digests);
        $this->assertStringContainsString('Runs: 3 (success 2, failed 1)', $digests[0]);
        $this->assertStringContainsString('• `probe`: 3 runs, 1 failed', $digests[0]);
        $this->assertStringContainsString("Open alerts:\n• `probe` failed since ", $digests[0]);
        $this->assertStringContainsString('• `probe` max 21.3s, avg 11s', $digests[0]);
    }
}
