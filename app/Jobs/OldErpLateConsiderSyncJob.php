<?php

namespace App\Jobs;

use Illuminate\Support\Facades\DB;

/**
 * Brings one new-ERP late attendance consideration request up to date in the old
 * ERP (/apiLateConsider). Queued by hr-admin-backend old_erp_late_consider_sync()
 * on oldErpLateConsiderSync_queue at apply, HR final approve / reject and delete;
 * runs in the old ERP sync worker loop (start-worker.sh).
 *
 * State based, like OldErpLoanSyncJob: reads the request as it is NOW and sends
 * only what the old ERP lacks.
 *
 *   apply        -> create (pending), consider_days = applied days
 *   HR approve   -> update consider_days / note (approval remark), then approve
 *   HR reject    -> update note (approval remark), then reject
 *   delete       -> update note "Deleted in New ERP", then reject (old has no delete)
 *
 * The old ERP takes whole days: fractions are rounded down and logged. It counts
 * late days itself and keeps one record per employee per month, so "month already
 * has a record" (409), fewer than 5 old late days and consider > old deduct days
 * are refused: logged and skipped, never forced. Months before
 * OLD_ERP_LATE_CONSIDER_SYNC_FROM (YYYY-MM) are skipped; empty = disabled.
 * Log: storage/logs/old-erp-late-consider-sync-YYYY-MM-DD.log
 */
class OldErpLateConsiderSyncJob extends Job
{
    use OldErpApi;

    private const RESOURCE    = 'apiLateConsider';
    private const LOG_CHANNEL = 'old_erp_late_consider_sync';

    protected array $payload;
    private int $requestId = 0;

    public function __construct(array $payload = [])
    {
        $this->payload = $payload;
    }

    public function handle(): void
    {
        $this->requestId = (int) ($this->payload['late_consider_id'] ?? 0);
        $req = DB::table('late_attendance_consideration_requests')->find($this->requestId);   // incl. soft deleted
        if (!$req) {
            $this->log('warning', 'request not found');
            return;
        }

        $from = substr((string) env('OLD_ERP_LATE_CONSIDER_SYNC_FROM'), 0, 7);
        if (!$from) {
            $this->log('warning', 'sync disabled: OLD_ERP_LATE_CONSIDER_SYNC_FROM is not set');
            return;
        }
        $month = sprintf('%04d-%02d', $req->year, $req->month);
        if ($month < $from) {
            $this->log('info', "skipped: month {$month} is before {$from} -- kept in the old ERP by hand");
            return;
        }

        $ext = 'NEWERP-LC-' . $req->id;
        $deleted = $req->deleted_at !== null;
        $status = $deleted ? 'rejected' : ([1 => 'approved', 2 => 'rejected'][(int) $req->approval_status] ?? 'pending');

        $old = $this->get(['external_id' => $ext]);
        if ($old === null) {
            if ($status === 'rejected') {
                $this->log('info', 'rejected / deleted before it reached the old ERP -- nothing to reject');
                return;
            }
            $days = $this->wholeDays($req->applied_days, 'applied');
            $created = $this->post('create', [], [
                'action_by_emp_id' => (int) $req->created_user_id,
                'external_id'      => $ext,
                'employee_id'      => (int) $req->employee_user_id,   // users.id = old employees.id
                'month'            => $month,
                'consider_days'    => $days,
            ]);
            if ($created === null) {
                return;   // month already in the old ERP, too few old late days, ...: logged
            }
            $old = $created + ['status' => '0', 'consider_days' => $days, 'note' => ''];
        } elseif ((string) ($old['employee_id'] ?? '') !== (string) $req->employee_user_id || ($old['month'] ?? '') !== $month) {
            // the new ERP id now names another request (DB restored / copied) -- never touch someone else's record
            $this->log('error', "{$ext} in the old ERP is a different record (employee " . ($old['employee_id'] ?? '?')
                . ', month ' . ($old['month'] ?? '?') . ") -- nothing sent");
            return;
        }

        $oldStatus = self::oldStatus($old);
        if ($oldStatus !== 'pending') {
            if ($oldStatus !== $status) {
                $this->log('warning', "old record is {$oldStatus}, new is {$status} -- nothing sent");
            }
            return;
        }
        if ($status === 'pending') {
            return;   // pending on both sides
        }

        $actor = (int) ($deleted ? $req->deleted_by : $req->updated_user_id);
        $note = $deleted ? 'Deleted in New ERP' : trim((string) $req->approval_remark);

        $update = [];
        if ($status === 'approved') {
            $days = $this->wholeDays($req->consider_days, 'consider');
            if ($days !== (int) ($old['consider_days'] ?? -1)) {
                $update['consider_days'] = $days;
            }
        }
        if ($note !== '' && $note !== (string) ($old['note'] ?? '')) {
            $update['note'] = $note;
        }
        // a refused update (e.g. consider days above the old deduct days) must not approve the old days
        if ($update && $this->post('update', ['external_id' => $ext], ['action_by_emp_id' => $actor] + $update) === null) {
            return;
        }
        $this->post($status === 'approved' ? 'approve' : 'reject', ['external_id' => $ext], ['action_by_emp_id' => $actor]);
    }

    /** The old ERP takes whole days only: round down, log what was cut. */
    private function wholeDays($value, string $label): int
    {
        $days = (int) floor((float) $value);
        if ($days != (float) $value) {
            $this->log('info', "{$label} days {$value} sent as {$days}: the old ERP takes whole days");
        }
        return $days;
    }

    /** pending | approved | rejected, from status_name or the old status code (0 / 1 / 2). */
    public static function oldStatus(array $record): string
    {
        $name = strtolower(trim((string) ($record['status_name'] ?? '')));
        return $name !== '' ? $name : (['0' => 'pending', '1' => 'approved', '2' => 'rejected'][(string) ($record['status'] ?? '')] ?? 'unknown');
    }

    private function logSubject(): string
    {
        return "late consider {$this->requestId}";
    }
}
