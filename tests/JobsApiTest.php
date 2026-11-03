<?php

namespace Tests;

use App\Http\Controllers\JobRunsController;
use App\Support\RunTracker;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// /api/jobs/* (JobsAuth + JobRunsController) on sqlite (TrackingDatabase). The auth service is faked:
// token "admin" = user 7 (JOBS_ADMIN_USER_IDS), "viewer" = user 8 (JOBS_VIEWER_USER_IDS), "other" = user 9.
class JobsApiTest extends TestCase
{
    use TrackingDatabase;

    private array $env = [];
    private int $authStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTrackingDatabase();
        RunTracker::$current = null;
        ApiTestJob::$received = null;
        $this->setEnv(['JOBS_ADMIN_USER_IDS' => '7', 'JOBS_VIEWER_USER_IDS' => '8, 12', 'JOBS_INTERNAL_TOKEN' => 'internal-secret']);
        Http::fake(['*/api/auth/validate-token' => function ($request) {
            $ids = ['Bearer admin' => 7, 'Bearer viewer' => 8, 'Bearer other' => 9];
            $id = $ids[$request->header('Authorization')[0] ?? ''] ?? null;
            if ($this->authStatus !== 200) {
                return Http::response('down', $this->authStatus);
            }
            return $id ? Http::response(['isValid' => true, 'user' => ['id' => $id]]) : Http::response(['isValid' => false], 401);
        }]);
    }

    protected function tearDown(): void
    {
        foreach ($this->env as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
        \Mockery::close();
        parent::tearDown();
    }

    private function setEnv(array $values): void
    {
        foreach ($values as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
            $this->env[] = $key;
        }
    }

    private function call_(string $method, string $uri, ?string $token = null, array $data = [], array $headers = []): array
    {
        if ($token) {
            $headers['Authorization'] = "Bearer {$token}";
        }
        $this->json($method, $uri, $data, $headers);
        return [$this->response->status(), json_decode($this->response->getContent(), true)];
    }

    private function insertRun(array $row): int
    {
        return RunTracker::db()->table('job_runs')->insertGetId($row + [
            'run_uuid' => (string) Str::uuid(), 'job' => 'probe', 'status' => 'success', 'queue' => 'probe_queue',
            'queued_at' => RunTracker::now(),
        ]);
    }

    public function test_every_endpoint_needs_auth_except_health(): void
    {
        $id = $this->insertRun(['status' => 'failed']);
        foreach ([['GET', '/api/jobs/runs'], ['GET', "/api/jobs/runs/{$id}"], ['GET', '/api/jobs/summary'],
                  ['POST', "/api/jobs/runs/{$id}/retry"], ['POST', "/api/jobs/runs/{$id}/replay"], ['POST', "/api/jobs/missed/{$id}/run-now"]] as [$method, $uri]) {
            $this->assertSame(401, $this->call_($method, $uri)[0], "{$method} {$uri} without a token");
            $this->assertSame(401, $this->call_($method, $uri, 'forged')[0], "{$method} {$uri} with a bad token");
        }
        $this->assertSame(200, $this->call_('GET', '/api/jobs/health')[0]);

        $this->get('/jobs');
        $this->assertSame(200, $this->response->status());
        $this->assertStringContainsString('<title>Job Runs</title>', $this->response->getContent());
    }

    public function test_access_levels(): void
    {
        $id = $this->insertRun(['status' => 'success']);
        $this->assertSame(200, $this->call_('GET', '/api/jobs/runs', 'viewer')[0]);
        $this->assertSame(200, $this->call_('GET', '/api/jobs/runs', 'admin')[0]);
        [$status, $body] = $this->call_('GET', '/api/jobs/runs', 'other');
        $this->assertSame(403, $status);
        $this->assertSame('Job dashboard access required', $body['message']);
        [$status, $body] = $this->call_('POST', "/api/jobs/runs/{$id}/retry", 'viewer');
        $this->assertSame(403, $status);
        $this->assertSame('Job admin access required', $body['message']);

        // Internal token: everything
        $this->assertSame(409, $this->call_('POST', "/api/jobs/runs/{$id}/retry", null, [], ['X-Internal-Token' => 'internal-secret'])[0]);
        $this->assertSame(401, $this->call_('GET', '/api/jobs/runs', null, [], ['X-Internal-Token' => 'guess'])[0]);

        $this->authStatus = 500;
        $this->assertSame(503, $this->call_('GET', '/api/jobs/runs', 'unseen-token')[0]);
    }

    public function test_empty_or_star_lets_any_signed_in_user_view_but_not_act(): void
    {
        $id = $this->insertRun(['status' => 'failed']);
        foreach (['', '*'] as $viewers) {
            $this->setEnv(['JOBS_VIEWER_USER_IDS' => $viewers]);
            $this->assertSame(200, $this->call_('GET', '/api/jobs/runs', 'other')[0]);
            $this->assertSame(200, $this->call_('GET', '/api/jobs/summary', 'other')[0]);
            $this->assertSame(403, $this->call_('POST', "/api/jobs/runs/{$id}/retry", 'other')[0]);
            $this->assertSame(401, $this->call_('GET', '/api/jobs/runs', 'nobody')[0]);
        }
        putenv('JOBS_VIEWER_USER_IDS');  // unset
        unset($_ENV['JOBS_VIEWER_USER_IDS'], $_SERVER['JOBS_VIEWER_USER_IDS']);
        $this->assertSame(200, $this->call_('GET', '/api/jobs/runs', 'other')[0]);

        $this->setEnv(['JOBS_ADMIN_USER_IDS' => '*']);  // never a wildcard for actions
        $this->assertSame(403, $this->call_('POST', "/api/jobs/runs/{$id}/retry", 'other')[0]);
    }

    public function test_runs_list_filters_and_pages(): void
    {
        $this->insertRun(['job' => 'a', 'status' => 'failed', 'error_message' => 'Connection refused', 'started_at' => '2026-10-20 10:00:00']);
        $this->insertRun(['job' => 'a', 'status' => 'success', 'flags' => '["late"]', 'started_at' => '2026-10-25 10:00:00']);
        $this->insertRun(['job' => 'b', 'status' => 'missed', 'queued_at' => '2026-10-25 11:00:00']);
        for ($i = 0; $i < 5; $i++) {
            $this->insertRun(['job' => 'c', 'started_at' => '2026-10-26 10:00:00']);
        }

        $ids = fn ($query) => array_column($this->call_('GET', '/api/jobs/runs?' . $query, 'viewer')[1]['data'], 'job');
        $this->assertSame(['b', 'a'], $ids('status=failed,missed'));
        $this->assertSame(['a', 'a'], $ids('job=a'));
        $this->assertSame(['a'], $ids('flag=late'));
        $this->assertSame(['b', 'a'], $ids('from=2026-10-21&to=2026-10-25'));
        $this->assertSame(['a'], $ids('q=refused'));

        [, $body] = $this->call_('GET', '/api/jobs/runs?job=c&per_page=2&page=3', 'viewer');
        $this->assertSame(5, $body['total']);
        $this->assertCount(1, $body['data']);
        $this->assertArrayNotHasKey('log', $body['data'][0]);
        $this->assertSame(['late'], $this->call_('GET', '/api/jobs/runs?flag=late', 'viewer')[1]['data'][0]['flags']);
    }

    public function test_run_detail_with_log_levels_and_related_runs(): void
    {
        $lines = [['t' => '10:00:00.000', 'l' => 'info', 'm' => 'start'], ['t' => '10:00:00.010', 'l' => 'tx', 'm' => 'BEGIN'],
                  ['t' => '10:00:00.020', 'l' => 'error', 'm' => 'insert failed'], ['t' => '10:00:00.030', 'l' => 'tx', 'm' => 'ROLLBACK (1 writes undone)']];
        $parent = $this->insertRun(['status' => 'failed', 'log' => gzencode(json_encode($lines)), 'payload' => '{"date":"2026-11-01"}']);
        $child = $this->insertRun(['status' => 'success', 'parent_run_id' => $parent, 'trigger_source' => 'retry']);

        [$status, $body] = $this->call_('GET', "/api/jobs/runs/{$parent}", 'viewer');
        $this->assertSame(200, $status);
        $this->assertSame(['date' => '2026-11-01'], $body['run']['payload']);
        $this->assertCount(4, $body['log']);
        $this->assertSame([$child], array_column($body['related'], 'id'));

        $body = $this->call_('GET', "/api/jobs/runs/{$parent}?levels=error,tx", 'viewer')[1];
        $this->assertSame(['BEGIN', 'insert failed', 'ROLLBACK (1 writes undone)'], array_column($body['log'], 'm'));
        $this->assertSame([$parent], array_column($this->call_('GET', "/api/jobs/runs/{$child}", 'viewer')[1]['related'], 'id'));
        $this->assertSame(404, $this->call_('GET', '/api/jobs/runs/999999', 'viewer')[0]);
    }

    public function test_summary(): void
    {
        $this->insertRun(['job' => 'a', 'status' => 'failed', 'finished_at' => RunTracker::now()]);
        $this->insertRun(['job' => 'a', 'status' => 'success', 'finished_at' => RunTracker::now()]);
        RunTracker::db()->table('job_schedules')->insert(['job' => 'nightly', 'cron' => '0 0 * * *']);
        RunTracker::db()->table('job_alerts')->insert(['job' => 'a', 'kind' => 'failed', 'opened_at' => RunTracker::now()]);
        RunTracker::db()->table('heartbeats')->insert(['name' => 'watchdog', 'beat_at' => RunTracker::now()]);

        $body = $this->call_('GET', '/api/jobs/summary', 'viewer')[1];
        $this->assertSame(['failed' => 1, 'success' => 1], $body['jobs']['a']['counts_24h']);
        $this->assertSame('success', $body['jobs']['a']['last_run']['status']);
        $this->assertNotNull($body['jobs']['a']['last_success_at']);
        $this->assertSame('failed', $body['jobs']['a']['open_alerts'][0]['kind']);
        $this->assertSame('0 0 * * *', $body['jobs']['nightly']['schedule']['cron']);
        $this->assertSame([], $body['jobs']['nightly']['counts_24h']);
        $this->assertArrayHasKey('watchdog', $body['heartbeats']);
    }

    public function test_retry_runs_the_job_again_as_a_linked_tracked_run(): void
    {
        config(['queue.connections.rabbitmq' => ['driver' => 'sync']]);  // run it right here, through the tracking provider
        $failed = $this->insertRun(['status' => 'failed', 'job_class' => ApiTestJob::class, 'payload' => '{"date":"2026-11-01"}']);

        [$status, $body] = $this->call_('POST', "/api/jobs/runs/{$failed}/retry", 'admin');
        $this->assertSame(202, $status);
        $this->assertSame(['date' => '2026-11-01'], ApiTestJob::$received);

        $retry = RunTracker::db()->table('job_runs')->find($body['run_id']);
        $this->assertSame('success', $retry->status);  // the worker side updated the API's queued row, not a new one
        $this->assertSame($failed, (int) $retry->parent_run_id);
        $this->assertSame('retry', $retry->trigger_source);
        $this->assertSame(7, (int) $retry->triggered_by);
        $this->assertSame(2, RunTracker::db()->table('job_runs')->count());
    }

    public function test_retry_refusals_and_forced_unsafe_retry(): void
    {
        config(['queue.connections.rabbitmq' => ['driver' => 'sync']]);
        $ok = $this->insertRun(['status' => 'success', 'job_class' => ApiTestJob::class, 'payload' => '{}']);
        $missed = $this->insertRun(['status' => 'missed', 'job_class' => ApiTestJob::class]);
        $redacted = $this->insertRun(['status' => 'failed', 'job_class' => ApiTestJob::class, 'payload' => '{"password":"[redacted]"}']);
        $gone = $this->insertRun(['status' => 'failed', 'job_class' => 'App\\Jobs\\NoSuchJob', 'payload' => '{}']);
        $unsafe = $this->insertRun(['status' => 'failed_retry_unsafe', 'writes_kept' => 3, 'job_class' => ApiTestJob::class, 'payload' => '{"n":1}']);

        $this->assertSame(409, $this->call_('POST', "/api/jobs/runs/{$ok}/retry", 'admin')[0]);
        $this->assertStringContainsString('use run-now', $this->call_('POST', "/api/jobs/runs/{$missed}/retry", 'admin')[1]['message']);
        $this->assertSame(422, $this->call_('POST', "/api/jobs/runs/{$redacted}/retry", 'admin')[0]);
        $this->assertSame(422, $this->call_('POST', "/api/jobs/runs/{$gone}/retry", 'admin')[0]);

        [$status, $body] = $this->call_('POST', "/api/jobs/runs/{$unsafe}/retry", 'admin');
        $this->assertSame(409, $status);
        $this->assertStringContainsString('committed 3 write(s)', $body['message']);
        $this->assertNull(ApiTestJob::$received);

        [$status, $body] = $this->call_('POST', "/api/jobs/runs/{$unsafe}/retry?force=1", 'admin');
        $this->assertSame(202, $status);
        $this->assertSame(['n' => 1], ApiTestJob::$received);
        $this->assertSame('["forced"]', RunTracker::db()->table('job_runs')->where('id', $body['run_id'])->value('flags'));
    }

    public function test_replay_sends_the_trigger_message_again(): void
    {
        $parsed = $this->insertRun(['status' => 'trigger_failed', 'queue' => 'x_trigger_queue', 'payload' => '{"date":"2026-11-01"}']);
        $raw = $this->insertRun(['status' => 'trigger_failed', 'queue' => 'x_trigger_queue', 'payload' => '{"raw":"not json"}']);
        $notTrigger = $this->insertRun(['status' => 'failed']);
        $sent = [];
        $conn = \Mockery::mock();
        $conn->shouldReceive('pushRaw')->andReturnUsing(function ($body, $queue) use (&$sent) { $sent[] = [$queue, $body]; });
        Queue::shouldReceive('connection')->with('rabbitmq')->andReturn($conn);

        $this->assertSame(202, $this->call_('POST', "/api/jobs/runs/{$parsed}/replay", 'admin')[0]);
        $this->assertSame(202, $this->call_('POST', "/api/jobs/runs/{$raw}/replay", 'admin')[0]);
        $this->assertSame(409, $this->call_('POST', "/api/jobs/runs/{$notTrigger}/replay", 'admin')[0]);

        $this->assertSame('x_trigger_queue', $sent[0][0]);
        $this->assertSame(['date' => '2026-11-01', 'trigger_source' => 'replay', 'triggered_by' => 7, 'parent_run_id' => $parsed], json_decode($sent[0][1], true));
        $this->assertSame('not json', $sent[1][1]);  // unparseable: exactly as it came
    }

    public function test_run_now_sends_the_missed_slot_with_its_own_date_once(): void
    {
        $missed = $this->insertRun(['status' => 'missed', 'queue' => 'probe_trigger_queue', 'queued_at' => '2026-10-31 00:00:00',
            'payload' => '{"date":"2026-11-02","year":"2026","month":"11","type":"all"}']);
        $sent = [];
        $conn = \Mockery::mock();
        $conn->shouldReceive('pushRaw')->andReturnUsing(function ($body, $queue) use (&$sent) { $sent[] = [$queue, json_decode($body, true)]; });
        Queue::shouldReceive('connection')->with('rabbitmq')->andReturn($conn);

        [$status, $body] = $this->call_('POST', "/api/jobs/missed/{$missed}/run-now", 'admin');
        $this->assertSame(202, $status);
        $this->assertSame(['date' => '2026-10-31', 'year' => '2026', 'month' => '10', 'type' => 'all'], $body['payload']);
        $this->assertSame(['probe_trigger_queue', $body['payload'] + ['trigger_source' => 'catchup', 'triggered_by' => 7, 'parent_run_id' => $missed]], $sent[0]);

        // The consumer's row for it exists now: a second click is refused unless forced
        $this->insertRun(['status' => 'queued', 'parent_run_id' => $missed, 'trigger_source' => 'catchup']);
        $this->assertSame(409, $this->call_('POST', "/api/jobs/missed/{$missed}/run-now", 'admin')[0]);
        [$status, $body] = $this->call_('POST', "/api/jobs/missed/{$missed}/run-now?force=1", 'admin', ['payload' => ['date' => '2026-10-30']]);
        $this->assertSame(202, $status);
        $this->assertSame(['date' => '2026-10-30'], $body['payload']);
        $this->assertSame(409, $this->call_('POST', '/api/jobs/missed/' . $this->insertRun(['status' => 'failed']) . '/run-now', 'admin')[0]);
    }

    public function test_for_slot(): void
    {
        $slot = strtotime('2026-01-31 00:00:00');
        $this->assertSame(
            ['start_date' => '2026-01-31', 'end_date' => '2026-01-31', 'year' => '2026', 'month' => '01', 'date' => 'not-a-date', 'type' => []],
            JobRunsController::forSlot(['start_date' => '2026-02-01', 'end_date' => '2026-02-01', 'year' => '2026', 'month' => '02', 'date' => 'not-a-date', 'type' => []], $slot)
        );
    }
}

class ApiTestJob extends \App\Jobs\Job
{
    public static ?array $received = null;

    public function __construct(protected array $payload)
    {
    }

    public function handle(): void
    {
        self::$received = $this->payload;
    }
}
