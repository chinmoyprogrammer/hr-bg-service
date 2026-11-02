<?php

namespace App\Support;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\DB;
use JsonSerializable;
use Throwable;

/**
 * State of the job run in progress in this worker process (one at a time). Saved to job_runs
 * through the `tracking` connection, so a job's own rollback can't erase it.
 * Fed by JobTrackingServiceProvider; see docs/job-failure-tracking-plan.html.
 */
class RunTracker
{
    const KEEP_LINES = 2500;     // first N and last N log lines are kept, the middle is dropped
    const SUCCESS_KEEP = 25;     // on a clean success: warnings, errors and the first/last N other lines
    const FIELD_MAX = 4096;      // bytes per log message and per log context
    const FLUSH_EVERY = 30;      // seconds between saves while the job runs
    const ERROR_LEVELS = ['error', 'critical', 'alert', 'emergency'];
    const SECRET_KEY = '/pass(word)?|token|secret|authori[sz]ation|api[_-]?key/i';
    const JWT = '/eyJ[\w-]+\.[\w-]+\.[\w-]*/';
    const WRITE_SQL = '/^\s*(insert|update|delete|replace|truncate|create|alter|drop)\b/i';

    public static ?self $current = null;
    /** Set by the trigger consumer around dispatch; the payload hook copies it into the job payload. */
    public static ?string $nextRunUuid = null;

    public float $startedAt;
    public ?string $firstError = null;
    public array $metrics = [];
    public array $counts = [
        'tx_committed' => 0, 'tx_rolled_back' => 0, 'writes_kept' => 0, 'writes_undone' => 0,
        'http_calls' => 0, 'http_failed' => 0, 'errors' => 0, 'warnings' => 0, 'log_lines' => 0,
    ];

    private array $stacks = [];  // connection => writes per open transaction level, innermost last
    private array $head = [];
    private array $ring = [];    // last KEEP_LINES lines after the head filled up
    private int $ringPos = 0;
    private ?array $pending = null;  // newest line, held back so consecutive repeats collapse into it
    private string $pendingKey = '';
    private float $lastFlush;

    public function __construct(public ?int $id, public string $uuid, public int $jobRef = 0)
    {
        $this->startedAt = $this->lastFlush = microtime(true);
    }

    /** Output count for the zero_output check, e.g. RunTracker::metric('attendance', 512). */
    public static function metric(string $key, int|float $value): void
    {
        if (self::$current) {
            self::$current->metrics[$key] = $value;
        }
    }

    // ---- DB transactions (one stack per connection, so savepoints and rollBack($toLevel) count right)

    public function begin(string $conn): void
    {
        $this->stacks[$conn][] = 0;
        $depth = count($this->stacks[$conn]);
        $this->tx($depth === 1 ? 'BEGIN' : "SAVEPOINT (level {$depth})", $conn);
    }

    public function query(string $conn, string $sql): void
    {
        if (preg_match(self::WRITE_SQL, $sql)) {
            if (empty($this->stacks[$conn])) {
                $this->counts['writes_kept']++;  // autocommit
            } else {
                $this->stacks[$conn][array_key_last($this->stacks[$conn])]++;
            }
        }
        $this->maybeFlush(false);
    }

    /** $level is the transaction level after the commit. */
    public function committed(string $conn, int $level): void
    {
        if (empty($this->stacks[$conn])) {
            return;
        }
        $writes = array_pop($this->stacks[$conn]);
        if ($level === 0 || empty($this->stacks[$conn])) {
            $this->counts['writes_kept'] += $writes;
            $this->counts['tx_committed']++;
            $this->tx("COMMIT ({$writes} writes)", $conn);
        } else {
            $this->stacks[$conn][array_key_last($this->stacks[$conn])] += $writes;  // savepoint folds into its parent
            $this->tx('RELEASE SAVEPOINT (level ' . ($level + 1) . ", {$writes} writes)", $conn);
        }
    }

    /** $level is the transaction level after the rollback. */
    public function rolledBack(string $conn, int $level): void
    {
        $stack = $this->stacks[$conn] ?? [];
        $undone = 0;
        while (count($stack) > $level) {
            $undone += array_pop($stack);
        }
        $this->counts['writes_undone'] += $undone;
        $this->stacks[$conn] = $stack;
        $this->counts['tx_rolled_back']++;
        $this->tx(($level === 0 ? 'ROLLBACK' : "ROLLBACK TO level {$level}") . " ({$undone} writes undone)", $conn);
    }

    /** Transactions still open at the end (connection lost, process dying): MySQL undoes them. */
    public function abandonOpen(): void
    {
        foreach ($this->stacks as $stack) {
            if ($stack) {
                $this->counts['writes_undone'] += array_sum($stack);
                $this->counts['tx_rolled_back']++;
            }
        }
        $this->stacks = [];
    }

    public function http(bool $failed): void
    {
        $this->counts['http_calls']++;
        $this->counts['http_failed'] += (int) $failed;
    }

    // ---- Log buffer

    public function log(string $level, string $message, array $context): void
    {
        $level = strtolower($level);
        $this->counts['log_lines']++;
        $isError = in_array($level, self::ERROR_LEVELS, true);
        if ($isError) {
            $this->counts['errors']++;
            $this->firstError ??= self::cut(self::redactString($message));
        } elseif ($level === 'warning') {
            $this->counts['warnings']++;
        }

        unset($context['run_id']);
        $line = ['l' => $level, 'm' => self::cut(self::redactString($message))];
        if ($context) {
            $json = json_encode(self::redact($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            $line['c'] = strlen($json) > self::FIELD_MAX ? self::cut($json) : json_decode($json, true);
        }
        $this->buffer($line, $level . "\0" . $line['m'] . "\0" . json_encode($line['c'] ?? null));
        $this->maybeFlush($isError);
    }

    /** Transaction boundaries, level `tx`, so the log shows which lines a rollback undid. Not counted as log lines. */
    private function tx(string $message, string $conn): void
    {
        $line = ['l' => 'tx', 'm' => $conn === 'mysql' ? $message : "{$message} [{$conn}]"];
        $this->buffer($line, "tx\0" . $line['m']);
    }

    private function buffer(array $line, string $key): void
    {
        if ($this->pending && $key === $this->pendingKey) {
            $this->pending['n'] = ($this->pending['n'] ?? 1) + 1;
        } else {
            $this->push();
            $this->pending = ['t' => self::now(true)] + $line;
            $this->pendingKey = $key;
        }
    }

    private function push(): void
    {
        if (!$this->pending) {
            return;
        }
        if (count($this->head) < self::KEEP_LINES) {
            $this->head[] = $this->pending;
        } else {
            $this->ring[$this->ringPos++ % self::KEEP_LINES] = $this->pending;
        }
        $this->pending = null;
    }

    /** @return array{0: array, 1: int} kept lines and how many were dropped */
    public function lines(bool $trim): array
    {
        $dropped = max(0, $this->ringPos - self::KEEP_LINES);
        $tail = [];
        for ($i = $this->ringPos - min($this->ringPos, self::KEEP_LINES); $i < $this->ringPos; $i++) {
            $tail[] = $this->ring[$i % self::KEEP_LINES];
        }
        $gap = $dropped ? [['t' => '', 'l' => 'notice', 'm' => "... {$dropped} lines dropped ..."]] : [];
        $lines = array_merge($this->head, $gap, $tail, $this->pending ? [$this->pending] : []);

        if ($trim) {
            $important = array_merge(self::ERROR_LEVELS, ['warning']);
            $other = array_keys(array_filter($lines, fn ($l) => !in_array($l['l'], $important, true)));
            $keep = array_flip(array_merge(array_slice($other, 0, self::SUCCESS_KEEP), array_slice($other, -self::SUCCESS_KEEP)));
            $before = count($lines);
            $lines = array_values(array_filter(
                $lines,
                fn ($l, $i) => isset($keep[$i]) || in_array($l['l'], $important, true),
                ARRAY_FILTER_USE_BOTH
            ));
            $dropped += $before - count($lines);
        }

        return [$lines, $dropped];
    }

    // ---- Outcome

    /** Final status of the run (status model in the plan). */
    public function status(bool $failed, ?Throwable $e = null): string
    {
        if ($failed) {
            if ($e instanceof TimeoutExceededException) {
                return 'timed_out';
            }
            // Writes already committed: a retry would apply them twice
            return $this->counts['writes_kept'] > 0 ? 'failed_retry_unsafe' : 'failed';
        }
        // Only a rollback that undid writes counts; a read-only transaction rolled back undid nothing
        if ($this->counts['writes_undone'] > 0) {
            return $this->counts['writes_kept'] > 0 ? 'partial' : 'rolled_back';
        }

        return $this->counts['errors'] > 0 ? 'completed_with_errors' : 'success';
    }

    /** job_runs columns for the current state; $status 'success' trims the log. */
    public function row(?string $status = null): array
    {
        [$lines, $dropped] = $this->lines($status === 'success');

        return $this->counts + [
            'log_dropped' => $dropped,
            'log' => gzencode(json_encode($lines, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR)),
            'metrics' => $this->metrics ? json_encode($this->metrics) : null,
            'error_message' => $this->firstError,
        ];
    }

    /** Saves the current state; $columns (status, error_*, ...) override it. */
    public function save(array $columns = []): void
    {
        $this->lastFlush = microtime(true);
        if ($this->id) {
            self::write(fn ($db) => $db->table('job_runs')->where('id', $this->id)->update($columns + $this->row($columns['status'] ?? null)));
        }
    }

    // ponytail: error lines flush at most once a second, so a job logging 10k errors doesn't do 10k writes
    private function maybeFlush(bool $urgent): void
    {
        $age = microtime(true) - $this->lastFlush;
        if ($age >= self::FLUSH_EVERY || ($urgent && $age >= 1)) {
            $this->save();
        }
    }

    // ---- Helpers

    public static function redact(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 6) {
            return '[too deep]';
        }
        if ($value instanceof Throwable) {
            return get_class($value) . ': ' . self::redactString($value->getMessage()) . ' at ' . $value->getFile() . ':' . $value->getLine();
        }
        if ($value instanceof Arrayable) {
            $value = $value->toArray();
        } elseif ($value instanceof JsonSerializable) {
            $value = $value->jsonSerialize();
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = is_string($k) && preg_match(self::SECRET_KEY, $k) ? '[redacted]' : self::redact($v, $depth + 1);
            }
            return $out;
        }
        if (is_object($value)) {
            return '[' . get_class($value) . ']';
        }

        return is_string($value) ? self::redactString($value) : $value;
    }

    public static function redactString(string $s): string
    {
        return preg_replace(self::JWT, '[jwt]', $s);
    }

    public static function cut(?string $s, int $max = self::FIELD_MAX): ?string
    {
        return $s === null || strlen($s) <= $max ? $s : mb_strcut($s, 0, $max) . '... [+' . (strlen($s) - $max) . ' bytes]';
    }

    /** Asia/Dhaka (set in bootstrap/app.php) with milliseconds; $timeOnly for log lines, $t to format another time. */
    public static function now(bool $timeOnly = false, ?float $t = null): string
    {
        $t ??= microtime(true);

        return date($timeOnly ? 'H:i:s' : 'Y-m-d H:i:s', (int) $t) . sprintf('.%03d', ($t - floor($t)) * 1000);
    }

    public static function db(): \Illuminate\Database\Connection
    {
        return DB::connection('tracking');
    }

    /** Runs a tracking write; tracking must never break a job, so failures only go to stderr. */
    public static function write(callable $fn): mixed
    {
        try {
            return $fn(self::db());
        } catch (Throwable $e) {
            error_log('[job-tracking] ' . $e->getMessage());
            return null;
        }
    }

    public static function beat(string $name, array $info = []): void
    {
        self::write(fn ($db) => $db->table('heartbeats')->updateOrInsert(
            ['name' => $name],
            ['beat_at' => self::now(), 'info' => json_encode($info)]
        ));
    }

    /** processTempData_queue / processTempData_trigger_queue -> processTempData */
    public static function jobName(string $queue): string
    {
        return preg_replace('/(_trigger)?_queue$/', '', $queue);
    }
}
