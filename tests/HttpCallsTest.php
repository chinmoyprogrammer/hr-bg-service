<?php

namespace Tests;

use App\Support\RunTracker;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// Outgoing API calls saved to job_http_calls (RunTracker::httpCall), on sqlite (TrackingDatabase).
class HttpCallsTest extends TestCase
{
    use TrackingDatabase;

    private int $runId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTrackingDatabase();
        $uuid = (string) Str::uuid();
        $this->runId = RunTracker::db()->table('job_runs')->insertGetId(['run_uuid' => $uuid, 'job' => 'processTempData', 'status' => 'running', 'started_at' => RunTracker::now()]);
        RunTracker::$current = new RunTracker($this->runId, $uuid);
    }

    protected function tearDown(): void
    {
        RunTracker::$current = null;
        parent::tearDown();
    }

    private function calls(): array
    {
        return RunTracker::db()->table('job_http_calls')->where('job_run_id', $this->runId)->orderBy('seq')->get()->all();
    }

    public function test_http_client_calls_are_saved_with_secrets_masked(): void
    {
        Http::fake([
            'device.test/api/token*' => Http::response(['access_token' => 'abc.def', 'token_type' => 'bearer', 'expires' => 3600], 200, ['Set-Cookie' => 'sid=1', 'X-Rate' => '9']),
            'device.test/api/attendance*' => Http::response(['data' => [['emp' => 'E1', 'punch' => '09:01']]]),
        ]);

        Http::asForm()->post('http://device.test/api/token?api_key=k123&day=1', ['username' => 'admin', 'password' => 'p@ss']);
        Http::withToken('abc.def')->withHeaders(['X-API-KEY' => 'k123'])->get('http://device.test/api/attendance', ['date' => '2026-11-03']);

        [$token, $attendance] = $this->calls();
        $this->assertSame(['POST', 200, 0, 1], [$token->method, (int) $token->status, (int) $token->failed, (int) $token->seq]);
        $this->assertSame('http://device.test/api/token?api_key=[hidden]&day=1', $token->url);
        $this->assertSame('username=admin&password=[hidden]', $token->request_body);
        $this->assertSame(['access_token' => '[hidden]', 'token_type' => '[hidden]', 'expires' => 3600], json_decode($token->response_body, true));
        $this->assertStringContainsString('Set-Cookie: [hidden]', $token->response_headers);
        $this->assertStringContainsString('X-Rate: 9', $token->response_headers);

        $this->assertStringContainsString('Authorization: [hidden]', $attendance->request_headers);
        $this->assertStringContainsString('X-API-KEY: [hidden]', $attendance->request_headers);
        $this->assertStringNotContainsString('k123', $attendance->request_headers . $attendance->url);
        $this->assertSame([['emp' => 'E1', 'punch' => '09:01']], json_decode($attendance->response_body, true)['data']);
        $this->assertSame([2, 0], [RunTracker::$current->counts['http_calls'], RunTracker::$current->counts['http_failed']]);
    }

    public function test_failure_rules(): void
    {
        Http::fake([
            'api.test/500' => Http::response(['message' => 'boom'], 500),
            'api.test/success-false' => Http::response(['success' => false, 'message' => 'Invalid employee']),
            'api.test/status-error' => Http::response(['status' => 'Failed']),
            'api.test/errors' => Http::response(['errors' => ['emp_code' => ['required']]]),
            'api.test/empty-errors' => Http::response(['errors' => [], 'data' => 1]),
            'api.test/text-error' => Http::response('ERROR: invalid number'),
            'api.test/text-ok' => Http::response('1701|sent'),
            'api.test/html' => Http::response('<html><script>onerror=1</script>Error page?</html>'),
        ]);
        foreach (['500', 'success-false', 'status-error', 'errors', 'empty-errors', 'text-error', 'text-ok', 'html'] as $path) {
            Http::get("http://api.test/{$path}");
        }

        $calls = array_column($this->calls(), null, 'url');
        $error = fn ($path) => $calls["http://api.test/{$path}"]->error;
        $this->assertSame('HTTP 500', $error('500'));
        $this->assertSame('Response says success: false', $error('success-false'));
        $this->assertSame('Response says status: Failed', $error('status-error'));
        $this->assertSame('Response has errors: {"emp_code":["required"]}', $error('errors'));
        $this->assertSame('Response text: ERROR: invalid number', $error('text-error'));
        $this->assertNull($error('empty-errors'));
        $this->assertNull($error('text-ok'));
        $this->assertNull($error('html'));
        $this->assertSame([8, 5], [RunTracker::$current->counts['http_calls'], RunTracker::$current->counts['http_failed']]);
        $this->assertSame('success', RunTracker::$current->status(false));  // body failures don't change the run's status
    }

    public function test_connection_failure_and_sms_style_form_array(): void
    {
        RunTracker::$current->httpCall(['method' => 'POST', 'url' => '182.163.102.203:8086/sms_api/send_sms.php', 'status' => null,
            'error' => 'Failed to connect: Connection refused', 'duration_ms' => 30000,
            'request_body' => ['token' => 'multibrand', 'receipentNo' => '8801712345678', 'smsContent' => 'Your OTP is 4411']]);
        RunTracker::$current->httpCall(['method' => 'POST', 'url' => 'x', 'status' => null]);

        [$sms, $bare] = $this->calls();
        $this->assertSame([null, 1, 'Failed to connect: Connection refused', 30000], [$sms->status, (int) $sms->failed, $sms->error, (int) $sms->duration_ms]);
        // Phone and text are kept as they are (chosen), the gateway token is not
        $this->assertSame(['token' => '[hidden]', 'receipentNo' => '8801712345678', 'smsContent' => 'Your OTP is 4411'], json_decode($sms->request_body, true));
        $this->assertSame('No response (connection failed)', $bare->error);
    }

    public function test_large_bodies_keep_first_and_last_8_kb(): void
    {
        $body = 'A' . str_repeat('x', 50000) . 'Z';
        Http::fake(['big.test/*' => Http::response($body)]);
        Http::get('http://big.test/pull');

        $call = $this->calls()[0];
        $this->assertSame(50002, (int) $call->response_bytes);
        $this->assertStringStartsWith('A' . str_repeat('x', 100), $call->response_body);
        $this->assertStringEndsWith(str_repeat('x', 100) . 'Z', $call->response_body);
        $this->assertStringContainsString('... [' . (50002 - 2 * 8192) . ' bytes cut] ...', $call->response_body);
        $this->assertLessThan(16500, strlen($call->response_body));
        $this->assertSame('short', RunTracker::clip('short'));
    }

    public function test_first_50_then_failures_only_up_to_200(): void
    {
        for ($i = 0; $i < 60; $i++) {
            RunTracker::$current->httpCall(['url' => "ok/{$i}", 'status' => 200]);
        }
        RunTracker::$current->httpCall(['url' => 'bad/1', 'status' => 502]);
        $calls = $this->calls();
        $this->assertCount(51, $calls);
        $this->assertSame([61, 1], [RunTracker::$current->counts['http_calls'], RunTracker::$current->counts['http_failed']]);
        $this->assertSame(61, (int) end($calls)->seq);  // seq is the call number, so gaps show skipped calls

        for ($i = 0; $i < 300; $i++) {
            RunTracker::$current->httpCall(['url' => "bad/x{$i}", 'status' => 500]);
        }
        $this->assertCount(200, $this->calls());
        $this->assertSame(361, RunTracker::$current->counts['http_calls']);
    }

    public function test_nothing_is_saved_outside_a_run(): void
    {
        RunTracker::$current = null;
        Http::fake(['*' => Http::response('ok')]);
        Http::get('http://api.test/outside');
        $this->assertCount(0, $this->calls());
    }

    public function test_run_detail_api_returns_calls_failed_first(): void
    {
        RunTracker::$current->httpCall(['url' => 'a', 'status' => 200]);
        RunTracker::$current->httpCall(['url' => 'b', 'status' => 404]);
        RunTracker::$current->httpCall(['url' => 'c', 'status' => 201]);
        RunTracker::$current = null;

        putenv('JOBS_INTERNAL_TOKEN=t1');
        $_ENV['JOBS_INTERNAL_TOKEN'] = $_SERVER['JOBS_INTERNAL_TOKEN'] = 't1';
        $response = $this->call('GET', "/api/jobs/runs/{$this->runId}", [], [], [], ['HTTP_X_INTERNAL_TOKEN' => 't1']);
        putenv('JOBS_INTERNAL_TOKEN');
        unset($_ENV['JOBS_INTERNAL_TOKEN'], $_SERVER['JOBS_INTERNAL_TOKEN']);
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $calls = json_decode($response->getContent(), true)['http_calls'];
        $this->assertSame(['b', 'a', 'c'], array_column($calls, 'url'));
        $this->assertSame([true, false, false], array_column($calls, 'failed'));
    }
}
