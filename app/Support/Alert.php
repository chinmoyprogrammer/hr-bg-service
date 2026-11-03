<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Job alerts (docs/job-failure-tracking-plan.html, Phase 3). One channel: a Slack-compatible
 * webhook ({"text": ...}) in JOB_ALERT_WEBHOOK_URL. Deduped by (job, kind) in job_alerts: sent
 * when it opens, at most one reminder an hour while it keeps happening, one "resolved" when the
 * job is fine again. jobs:watchdog feeds it every minute, so a slow webhook never holds up a job.
 */
class Alert
{
    const RED = ['failed', 'failed_retry_unsafe', 'rolled_back', 'timed_out', 'lost', 'trigger_failed', 'missed'];
    const AMBER = ['completed_with_errors', 'partial', 'stuck'];
    const FLAGS = ['late', 'zero_output'];
    /** Runs that actually ran to an end: they can show a flag is gone. */
    const FINISHED = ['success', 'completed_with_errors', 'partial', 'rolled_back', 'failed', 'failed_retry_unsafe'];
    const REMIND_EVERY = 3600;

    /** A run that just ended or was found by the watchdog: raise what's wrong, resolve what's fine again. */
    public static function run(object $run): void
    {
        if (self::muted($run->job)) {
            return;
        }
        if (in_array($run->status, [...self::RED, ...self::AMBER], true)) {
            self::raise($run->job, $run->status, $run);
        }
        $flags = json_decode((string) $run->flags, true) ?: [];
        foreach (self::FLAGS as $flag) {
            if (in_array($flag, $flags, true)) {
                self::raise($run->job, $flag, $run);
            } elseif (in_array($run->status, self::FINISHED, true)) {
                self::resolve($run->job, $flag, $run);
            }
        }
        if ($run->status === 'success') {
            $open = RunTracker::db()->table('job_alerts')->where('job', $run->job)->whereNull('resolved_at')
                ->whereNotIn('kind', [...self::FLAGS, 'stuck'])->pluck('kind');
            foreach ($open as $kind) {
                self::resolve($run->job, $kind, $run);
            }
        }
    }

    public static function raise(string $job, string $kind, ?object $run): void
    {
        $db = RunTracker::db();
        $now = RunTracker::now();
        $alert = $db->table('job_alerts')->where('job', $job)->where('kind', $kind)->whereNull('resolved_at')->first();
        if (!$alert) {
            $id = $db->table('job_alerts')->insertGetId([
                'job' => $job, 'kind' => $kind, 'first_run_id' => $run?->id, 'last_run_id' => $run?->id,
                'opened_at' => $now, 'occurrences' => 1,
            ]);
            if (self::send(self::openedText($job, $kind, $run))) {
                $db->table('job_alerts')->where('id', $id)->update(['last_sent_at' => $now]);
            }
            return;
        }

        $update = [];
        if ($run && $run->id != $alert->last_run_id) {
            $update = ['last_run_id' => $run->id, 'occurrences' => $alert->occurrences + 1];  // a new run, not the same one updated
        }
        $occurrences = $update['occurrences'] ?? $alert->occurrences;
        $due = !$alert->last_sent_at || time() - strtotime($alert->last_sent_at) >= self::REMIND_EVERY;
        if ($due) {
            $text = $alert->last_sent_at
                ? self::reminderText($job, $kind, $run, $occurrences, $alert->opened_at)
                : self::openedText($job, $kind, $run);  // the first send failed: try again
            if (self::send($text)) {
                $update['last_sent_at'] = $now;
            }
        }
        if ($update) {
            $db->table('job_alerts')->where('id', $alert->id)->update($update);
        }
    }

    public static function resolve(string $job, string $kind, ?object $run = null): void
    {
        $db = RunTracker::db();
        $alert = $db->table('job_alerts')->where('job', $job)->where('kind', $kind)->whereNull('resolved_at')->first();
        if (!$alert) {
            return;
        }
        $db->table('job_alerts')->where('id', $alert->id)->update(['resolved_at' => RunTracker::now()]);
        if ($alert->last_sent_at && !self::muted($job)) {  // never announced: nothing to take back
            $since = date('d M H:i', strtotime($alert->opened_at));
            self::send(":large_green_circle: *resolved* `{$job}` {$kind}: OK again after {$alert->occurrences}× since {$since}"
                . ($run ? ' (' . self::link($run->id) . ')' : ''));
        }
    }

    /** Open alerts whose first message didn't go out (webhook down): retried every watchdog run. */
    public static function retryUnsent(): void
    {
        $db = RunTracker::db();
        foreach ($db->table('job_alerts')->whereNull('resolved_at')->whereNull('last_sent_at')->get() as $alert) {
            $run = $alert->last_run_id ? $db->table('job_runs')->find($alert->last_run_id) : null;
            if (self::send(self::openedText($alert->job, $alert->kind, $run))) {
                $db->table('job_alerts')->where('id', $alert->id)->update(['last_sent_at' => RunTracker::now()]);
            }
        }
    }

    /** Daily summary: runs per status per job, open alerts, slowest jobs (last 24 h). */
    public static function digestText(): string
    {
        $db = RunTracker::db();
        $since = RunTracker::now(false, microtime(true) - 86400);
        $rows = $db->table('job_runs')->whereRaw('COALESCE(finished_at, queued_at, started_at) >= ?', [$since])
            ->groupBy('job', 'status')->selectRaw('job, status, COUNT(*) AS n')->get();

        $total = $rows->sum('n');
        $byStatus = $rows->groupBy('status')->map(fn ($g) => $g->sum('n'))->sortDesc();
        $lines = ['*Job digest*, last 24 h to ' . date('d M H:i'), "Runs: {$total} (" . $byStatus->map(fn ($n, $s) => "{$s} {$n}")->implode(', ') . ')'];

        $problems = [];
        foreach ($rows->groupBy('job') as $job => $statuses) {
            $bad = $statuses->whereNotIn('status', ['success', 'queued', 'running', 'retrying']);
            if ($bad->isNotEmpty()) {
                $problems[] = "• `{$job}`: {$statuses->sum('n')} runs, " . $bad->map(fn ($r) => "{$r->n} {$r->status}")->implode(', ');
            }
        }
        $lines[] = $problems ? "Problems:\n" . implode("\n", $problems) : 'Problems: none';

        $open = $db->table('job_alerts')->whereNull('resolved_at')->orderBy('opened_at')->limit(10)->get();
        $lines[] = $open->isEmpty() ? 'Open alerts: none' : "Open alerts:\n" . $open->map(
            fn ($a) => "• `{$a->job}` {$a->kind} since " . date('d M H:i', strtotime($a->opened_at)) . " ({$a->occurrences}×)"
        )->implode("\n");

        $slow = $db->table('job_runs')->where('finished_at', '>=', $since)->whereIn('status', ['success', 'completed_with_errors'])
            ->groupBy('job')->selectRaw('job, MAX(duration_ms) AS max_ms, AVG(duration_ms) AS avg_ms')->orderByDesc('max_ms')->limit(5)->get();
        if ($slow->isNotEmpty()) {
            $lines[] = "Slowest:\n" . $slow->map(fn ($s) => "• `{$s->job}` max " . self::seconds($s->max_ms) . ', avg ' . self::seconds($s->avg_ms))->implode("\n");
        }

        return implode("\n", $lines);
    }

    /** Posts to the webhook; without one configured the log is the channel. False = try again later. */
    public static function send(string $text): bool
    {
        $url = env('JOB_ALERT_WEBHOOK_URL');
        if (!$url) {
            Log::warning("[job-alert] {$text}");
            return true;
        }
        try {
            $ok = Http::timeout(10)->post($url, ['text' => $text])->successful();
        } catch (Throwable $e) {
            $ok = false;
        }
        if (!$ok) {
            error_log('[job-alert] webhook failed; will retry: ' . strtok($text, "\n"));
        }
        return $ok;
    }

    public static function muted(string $job): bool
    {
        return in_array($job, array_map('trim', explode(',', (string) env('JOB_ALERT_MUTE', ''))), true);
    }

    private static function openedText(string $job, string $kind, ?object $run): string
    {
        $lines = [self::icon($kind) . " *{$kind}* `{$job}`"];
        if ($reason = self::reason($run)) {
            $lines[] = "Reason: {$reason}";
        }
        if ($run) {
            $lines[] = self::details($run);
        }
        if ($kind === 'failed_retry_unsafe') {
            $lines[] = 'Retry unsafe: it had already committed writes, so it was not retried. Check the data before retrying.';
        }
        return implode("\n", $lines);
    }

    private static function reminderText(string $job, string $kind, ?object $run, int $occurrences, string $openedAt): string
    {
        $lines = [self::icon($kind) . " *still {$kind}* `{$job}`: {$occurrences}× since " . date('d M H:i', strtotime($openedAt))];
        if ($reason = self::reason($run)) {
            $lines[] = "Latest: {$reason}";
        }
        if ($run) {
            $lines[] = self::details($run);
        }
        return implode("\n", $lines);
    }

    private static function details(object $run): string
    {
        $parts = [self::link($run->id)];
        if ($run->attempt) {
            $parts[] = "attempt {$run->attempt}" . ($run->max_attempts ? "/{$run->max_attempts}" : '');
        }
        if ($run->duration_ms !== null) {
            $parts[] = self::seconds($run->duration_ms);
        }
        if ($run->wait_ms !== null && $run->wait_ms >= 1000) {
            $parts[] = 'waited ' . self::seconds($run->wait_ms);
        }
        if ($run->writes_kept || $run->writes_undone) {
            $parts[] = "writes kept {$run->writes_kept} / undone {$run->writes_undone}";
        }
        return implode(' · ', $parts);
    }

    private static function reason(?object $run): ?string
    {
        $first = $run?->error_message ? trim(strtok($run->error_message, "\n")) : '';
        return $first === '' ? null : RunTracker::cut($first, 300);
    }

    private static function link(int|string $id): string
    {
        $base = rtrim((string) env('JOB_DASHBOARD_URL', ''), '/');
        return $base ? "<{$base}/runs/{$id}|run #{$id}>" : "run #{$id}";
    }

    private static function icon(string $kind): string
    {
        return in_array($kind, self::RED, true) ? ':red_circle:' : ':large_orange_circle:';
    }

    private static function seconds(int|float|string|null $ms): string
    {
        return round(((float) $ms) / 1000, 1) . 's';
    }
}
