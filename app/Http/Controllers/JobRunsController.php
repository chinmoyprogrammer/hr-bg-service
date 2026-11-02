<?php

namespace App\Http\Controllers;

use App\Jobs\RabbitMQJob;
use App\Support\RunTracker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/** Job runs API (docs/job-failure-tracking-plan.html, Phase 4). Behind JobsAuth. */
class JobRunsController extends Controller
{
    const LIST_COLUMNS = ['id', 'run_uuid', 'job', 'job_class', 'queue', 'trigger_source', 'triggered_by', 'parent_run_id', 'status',
        'flags', 'attempt', 'max_attempts', 'queued_at', 'started_at', 'finished_at', 'wait_ms', 'duration_ms', 'error_class',
        'error_message', 'tx_committed', 'tx_rolled_back', 'writes_kept', 'writes_undone', 'http_calls', 'http_failed',
        'errors', 'warnings', 'log_lines', 'log_dropped', 'metrics'];
    const RETRYABLE = ['failed', 'failed_retry_unsafe', 'rolled_back', 'partial', 'completed_with_errors', 'timed_out', 'lost'];
    const WHEN = 'COALESCE(started_at, queued_at, finished_at)';

    /** GET /api/jobs/runs?job=&status=a,b&flag=&from=&to=&q=&page=&per_page= */
    public function index(Request $request)
    {
        $query = RunTracker::db()->table('job_runs');
        if ($job = $request->query('job')) {
            $query->where('job', $job);
        }
        if ($status = $request->query('status')) {
            $query->whereIn('status', explode(',', $status));
        }
        if ($flag = $request->query('flag')) {
            $query->where('flags', 'like', '%"' . str_replace(['%', '_', '"'], '', $flag) . '"%');
        }
        if ($from = $request->query('from')) {
            $query->whereRaw(self::WHEN . ' >= ?', [$from]);
        }
        if ($to = $request->query('to')) {
            $query->whereRaw(self::WHEN . ' <= ?', [strlen($to) === 10 ? "{$to} 23:59:59.999" : $to]);
        }
        if ($q = $request->query('q')) {
            $query->where(fn ($w) => $w->where('error_message', 'like', '%' . addcslashes($q, '%_') . '%')->orWhere('run_uuid', $q));
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 50)));
        $page = max(1, (int) $request->query('page', 1));
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('id')->forPage($page, $perPage)->get(self::LIST_COLUMNS)->map(fn ($r) => self::decode($r));

        return response()->json(['data' => $rows, 'page' => $page, 'per_page' => $perPage, 'total' => $total]);
    }

    /** GET /api/jobs/runs/{id}?levels=error,warning,tx */
    public function show(Request $request, int $id)
    {
        $db = RunTracker::db();
        $run = $db->table('job_runs')->find($id);
        if (!$run) {
            return response()->json(['message' => 'Run not found'], 404);
        }
        $log = $run->log ? (json_decode((string) gzdecode($run->log), true) ?: []) : null;
        if ($log !== null && ($levels = $request->query('levels'))) {
            $keep = explode(',', $levels);
            $log = array_values(array_filter($log, fn ($l) => in_array($l['l'], $keep, true)));
        }
        unset($run->log);

        return response()->json([
            'run' => self::decode($run),
            'log' => $log,  // null: none kept (pruned after 7 days on success, or the run never logged)
            'related' => $db->table('job_runs')->where('parent_run_id', $id)
                ->when($run->parent_run_id, fn ($q) => $q->orWhere('id', $run->parent_run_id))
                ->orderBy('id')->get(['id', 'status', 'trigger_source', 'parent_run_id', 'queued_at', 'started_at', 'finished_at']),
            'alerts' => $db->table('job_alerts')->where('job', $run->job)->orderByDesc('id')->limit(5)->get(),
        ]);
    }

    /** GET /api/jobs/summary: per job last run, last success, 24 h counts, open alerts; heartbeats. */
    public function summary()
    {
        $db = RunTracker::db();
        $since = RunTracker::now(false, microtime(true) - 86400);
        $jobs = [];

        $counts = $db->table('job_runs')->whereRaw(self::WHEN . ' >= ?', [$since])->groupBy('job', 'status')
            ->selectRaw('job, status, COUNT(*) AS n')->get();
        foreach ($counts as $c) {
            $jobs[$c->job]['counts_24h'][$c->status] = (int) $c->n;
        }
        $lastIds = $db->table('job_runs')->whereRaw(self::WHEN . ' >= ?', [RunTracker::now(false, microtime(true) - 7 * 86400)])
            ->groupBy('job')->selectRaw('MAX(id) AS id')->pluck('id');
        foreach ($db->table('job_runs')->whereIn('id', $lastIds)->get(['id', 'job', 'status', 'flags', 'queued_at', 'started_at', 'finished_at', 'duration_ms', 'error_message']) as $r) {
            $jobs[$r->job]['last_run'] = self::decode($r);
        }
        foreach ($db->table('job_runs')->where('status', 'success')->groupBy('job')->selectRaw('job, MAX(finished_at) AS at')->get() as $r) {
            if (isset($jobs[$r->job])) {
                $jobs[$r->job]['last_success_at'] = $r->at;
            }
        }
        foreach ($db->table('job_schedules')->get() as $s) {
            $jobs[$s->job]['schedule'] = ['cron' => $s->cron, 'enabled' => (bool) $s->enabled, 'max_runtime_s' => $s->max_runtime_s, 'max_wait_s' => $s->max_wait_s];
        }
        foreach ($db->table('job_alerts')->whereNull('resolved_at')->get() as $a) {
            $jobs[$a->job]['open_alerts'][] = $a;
        }
        ksort($jobs);

        $heartbeats = [];
        foreach ($db->table('heartbeats')->get() as $b) {
            $heartbeats[$b->name] = (int) round(microtime(true) - strtotime($b->beat_at));
        }

        return response()->json(['jobs' => array_map(fn ($j) => $j + ['counts_24h' => [], 'open_alerts' => []], $jobs), 'heartbeats' => $heartbeats]);
    }

    /** POST /api/jobs/runs/{id}/retry[?force=1]: the same job and payload again, linked by parent_run_id. */
    public function retry(Request $request, int $id)
    {
        $run = RunTracker::db()->table('job_runs')->find($id);
        if (!$run) {
            return response()->json(['message' => 'Run not found'], 404);
        }
        if (!in_array($run->status, self::RETRYABLE, true)) {
            return response()->json(['message' => "A {$run->status} run can't be retried" . ($run->status === 'trigger_failed' ? '; use replay' : ($run->status === 'missed' ? '; use run-now' : ''))], 409);
        }
        $force = $request->boolean('force');
        if ($run->status === 'failed_retry_unsafe' && !$force) {
            return response()->json(['message' => "Run #{$id} committed {$run->writes_kept} write(s) before failing; a retry may apply them twice. Check the data, then retry with force=1."], 409);
        }
        $payload = json_decode((string) $run->payload, true);
        $class = $run->job_class;
        if (!is_array($payload) && $run->payload !== null && $class !== RabbitMQJob::class) {
            return response()->json(['message' => 'The stored payload is not a job input'], 422);
        }
        if (!empty($payload['truncated']) || preg_match('/\[(redacted|jwt)\]/', (string) $run->payload)) {
            return response()->json(['message' => 'The stored payload is incomplete (truncated or redacted), so it cannot be re-sent as is'], 422);
        }
        if (!$class || !class_exists($class)) {
            return response()->json(['message' => "Job class {$class} not found"], 422);
        }

        $user = $request->attributes->get('jobs_user');
        if ($force && $run->status === 'failed_retry_unsafe') {
            Log::warning('jobs: forced retry of a retry-unsafe run', ['run_id' => $id, 'job' => $run->job, 'user' => $user]);
        }
        $job = $class === RabbitMQJob::class ? new RabbitMQJob($payload ?? json_decode((string) $run->payload), $run->queue) : new $class($payload ?? []);
        $newId = self::dispatchTracked($job, $run->queue, [
            'job' => $run->job, 'job_class' => $class, 'trigger_source' => 'retry', 'triggered_by' => self::userId($user),
            'parent_run_id' => $id, 'payload' => $run->payload, 'flags' => $force ? json_encode(['forced']) : null,
        ]);

        return response()->json(['message' => 'Retry queued', 'run_id' => $newId], 202);
    }

    /** POST /api/jobs/runs/{id}/replay: a trigger_failed message, sent to its trigger queue again. */
    public function replay(Request $request, int $id)
    {
        $run = RunTracker::db()->table('job_runs')->find($id);
        if (!$run) {
            return response()->json(['message' => 'Run not found'], 404);
        }
        if ($run->status !== 'trigger_failed') {
            return response()->json(['message' => "Only trigger_failed runs can be replayed (this one is {$run->status})"], 409);
        }
        $payload = json_decode((string) $run->payload, true) ?: [];
        // Unparseable messages go back exactly as they came; parsed ones get who/why for the new row
        $body = array_key_exists('raw', $payload) && count($payload) === 1
            ? (string) $payload['raw']
            : json_encode(array_merge($payload, ['trigger_source' => 'replay', 'triggered_by' => self::userId($request->attributes->get('jobs_user')), 'parent_run_id' => $id]));
        self::publish($run->queue, $body);

        return response()->json(['message' => "Replayed to {$run->queue}"], 202);
    }

    /** POST /api/jobs/missed/{id}/run-now[?force=1] body: {payload?}: the missed slot's trigger, now, for its own date. */
    public function runNow(Request $request, int $id)
    {
        $db = RunTracker::db();
        $run = $db->table('job_runs')->find($id);
        if (!$run) {
            return response()->json(['message' => 'Run not found'], 404);
        }
        if ($run->status !== 'missed') {
            return response()->json(['message' => "Only missed runs can be run now (this one is {$run->status})"], 409);
        }
        if (!$request->boolean('force') && $db->table('job_runs')->where('parent_run_id', $id)->where('trigger_source', 'catchup')->exists()) {
            return response()->json(['message' => 'A catch-up for this missed run was already started; use force=1 to run it again'], 409);
        }
        $payload = $request->input('payload');
        if (!is_array($payload)) {
            $payload = self::forSlot(json_decode((string) $run->payload, true) ?: [], strtotime($run->queued_at));
        }
        self::publish($run->queue, json_encode(array_merge($payload, [
            'trigger_source' => 'catchup', 'triggered_by' => self::userId($request->attributes->get('jobs_user')), 'parent_run_id' => $id,
        ])));

        return response()->json(['message' => "Catch-up sent to {$run->queue}", 'payload' => $payload], 202);
    }

    /**
     * The scheduler builds its payloads from "now", so a catch-up needs the missed slot's date
     * instead: date / start_date / end_date, year, month.
     */
    public static function forSlot(array $payload, int $slot): array
    {
        foreach ($payload as $key => $value) {
            if (in_array($key, ['date', 'start_date', 'end_date'], true) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value)) {
                $payload[$key] = date('Y-m-d', $slot);
            } elseif ($key === 'year') {
                $payload[$key] = date('Y', $slot);
            } elseif ($key === 'month') {
                $payload[$key] = date('m', $slot);
            }
        }
        return $payload;
    }

    /** queued row first, then the job carrying its run_uuid (same as the trigger consumer), so the worker updates this row. */
    private static function dispatchTracked(object $job, ?string $queue, array $row): ?int
    {
        $uuid = (string) Str::uuid();
        $id = RunTracker::write(fn ($db) => $db->table('job_runs')->insertGetId($row + [
            'run_uuid' => $uuid, 'queue' => $queue, 'status' => 'queued', 'queued_at' => RunTracker::now(),
        ]));
        RunTracker::$nextRunUuid = $uuid;
        try {
            Queue::connection('rabbitmq')->push($job, '', $queue);
        } finally {
            RunTracker::$nextRunUuid = null;
        }
        return $id;
    }

    private static function publish(string $queue, string $body): void
    {
        Queue::connection('rabbitmq')->pushRaw($body, $queue);
    }

    private static function userId(?string $user): ?int
    {
        return is_numeric($user) ? (int) $user : null;
    }

    private static function decode(object $run): object
    {
        foreach (['flags', 'metrics', 'payload'] as $column) {
            if (property_exists($run, $column) && is_string($run->{$column})) {
                $run->{$column} = json_decode($run->{$column}, true);
            }
        }
        return $run;
    }
}
