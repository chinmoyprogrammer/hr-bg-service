<?php

namespace App\Jobs;

use Illuminate\Support\Facades\DB;

/**
 * Brings one employee's attendance on one date up to date in the old ERP
 * (/apiAttendance). Queued by ProcessManualDataJob on oldErpAttendanceSync_queue
 * after a manual entry or an HR approved correction (hr-admin sets old_erp_sync;
 * CSV import doesn't); runs in the old ERP sync worker loop (start-worker.sh).
 *
 * State based, like OldErpLoanSyncJob: reads the new row as it is NOW, compares
 * with old view and sends save only when the times differ. save overwrites the
 * old row (device punches included) and marks it manual.
 *
 * The old ERP keeps times only (HH:MM, no out date): seconds are cut, a night
 * shift (out next day) goes with the in time only, statuses are not sent. It requires an in time:
 * a new row without one keeps the old in time, or is skipped when old has none
 * either; a missing out time keeps the old out time the same way.
 * Months before OLD_ERP_ATTENDANCE_SYNC_FROM (YYYY-MM, calendar month of the
 * date) are skipped; empty = disabled.
 * Log: storage/logs/old-erp-attendance-sync-YYYY-MM-DD.log
 */
class OldErpAttendanceSyncJob extends Job
{
    use OldErpApi;

    private const RESOURCE    = 'apiAttendance';
    private const LOG_CHANNEL = 'old_erp_attendance_sync';

    protected array $payload;
    private int $employeeUserId = 0;
    private string $date = '';

    public function __construct(array $payload = [])
    {
        $this->payload = $payload;
    }

    public function handle(): void
    {
        $this->employeeUserId = (int) ($this->payload['employee_user_id'] ?? 0);
        $this->date = (string) ($this->payload['date'] ?? '');

        $from = substr((string) env('OLD_ERP_ATTENDANCE_SYNC_FROM'), 0, 7);
        if (!$from) {
            $this->log('warning', 'sync disabled: OLD_ERP_ATTENDANCE_SYNC_FROM is not set');
            return;
        }
        if (substr($this->date, 0, 7) < $from) {
            $this->log('info', "skipped: month is before {$from}");
            return;
        }

        $row = self::manualRows()
            ->where('ea.employee_user_id', $this->employeeUserId)
            ->where('ea.date', $this->date)
            ->orderByDesc('ea.id')
            ->first();
        if (!$row) {
            $this->log('info', 'no manual / corrected attendance in the new ERP (device data, CSV import or reprocessed) -- nothing sent');
            return;
        }

        $in = self::hhmm($row->in_time);
        $out = self::hhmm($row->out_time);
        $old = $this->get(['employee_id' => $this->employeeUserId, 'date' => $this->date]) ?? [];   // users.id = old employees.id
        $oldIn = self::hhmm($old['in_time'] ?? null);
        $oldOut = self::hhmm($old['out_time'] ?? null);

        if (!$in) {
            if (!$oldIn) {
                $this->log('warning', 'no in time in either ERP -- nothing sent (the old ERP requires in_time)');
                return;
            }
            $in = $oldIn;
            $this->log('info', "new row has no in time: keeping the old in time {$oldIn}");
        }
        // night shift: the old ERP has no out date and refuses out <= in, so only the in time goes
        $night = $row->out_date && $row->out_date > $this->date;
        if ($night) {
            if ($old && $in === $oldIn) {
                return;   // in time already the same
            }
            $this->log('info', "night shift (out {$row->out_date} {$out}): sending the in time only");
            $out = null;
        } else {
            if (!$out && $oldOut) {
                $out = $oldOut;
                $this->log('info', "new row has no out time: keeping the old out time {$oldOut}");
            }
            if ($old && $in === $oldIn && $out === $oldOut) {
                return;   // already the same
            }
        }

        $this->post('save', [], [
            // manual entry: created_by is 0, the actor is the row creator; correction: created_by is the HR approver
            'action_by_emp_id' => (int) ($row->created_by ?: $row->created_user_id),
            'employee_id'      => $this->employeeUserId,
            'date'             => $this->date,
            'in_time'          => $in,
        ] + ($out ? ['out_time' => $out] : []));
    }

    /** New attendance rows written by a manual entry or a correction (not CSV import). Also used by old-erp:resync. */
    public static function manualRows()
    {
        return DB::table('employee_attendance as ea')
            ->join('manual_attendance_records as m', 'm.employee_attendance_id', '=', 'ea.id')
            ->whereNull('ea.deleted_at')
            // CSV import writes manual rows too, recognisable only by its remark
            ->where(fn ($q) => $q->where('ea.is_corrected', 1)
                ->orWhereNull('ea.remarks')
                ->orWhere('ea.remarks', 'not like', 'CSV import%'))
            ->select('ea.employee_user_id', 'ea.date', 'ea.in_time', 'ea.out_date', 'ea.out_time', 'ea.created_user_id', 'm.created_by');
    }

    /** HH:MM, null for empty; ponytail: 00:00 counts as empty (old moved Fridays are 0 / 0), a real midnight punch is lost. */
    public static function hhmm($time): ?string
    {
        $t = substr(trim((string) $time), 0, 5);
        return $t === '' || $t === '00:00' ? null : $t;
    }

    private function logSubject(): string
    {
        return "attendance {$this->employeeUserId} {$this->date}";
    }
}
