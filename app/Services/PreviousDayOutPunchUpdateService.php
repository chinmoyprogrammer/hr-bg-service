<?php

namespace App\Services;

use App\Models\EmployeeAttendance;
use App\Models\EmployeeAttendanceStatusLog;
use App\Models\PayrollAccruedAllowanceIncome;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Dedicated pre-pass that completes the PREVIOUS day's missing out-punch.
 *
 * This runs BEFORE AttendanceProcessingService. The main per-day service only
 * knows about a single day, so when a checkout physically happens after midnight
 * (i.e. on the next calendar day) the previous day would otherwise stay
 * "Incomplete Out". This service scans the next day's early punches and back-fills
 * the previous day's out_time / out_date, keeping status logs and night-duty
 * allowance in sync — exactly what the old inline handlers inside
 * AttendanceProcessingService used to do, now centralised here.
 *
 * It covers both shift types, distinguished by the shift->is_overnight flag:
 *   - single calendar-day shift (is_overnight = 0): shift start & end fall on the
 *     same calendar day. A checkout that spills past midnight is overnight/overtime
 *     duty → previous day gets status 13 (Over Night Duty) + night allowance.
 *   - two calendar-day shift    (is_overnight = 1): shift start & end span two
 *     calendar days, so the checkout naturally lands on the next calendar day →
 *     previous day gets status 12 (Night Duty checkout), no extra allowance.
 *
 * In both cases the next day's early punch (before the check-in cutoff) is what
 * back-fills the previous day's out_time / out_date. For a two-day shift whose next
 * day carries ONLY that checkout punch (no fresh check-in), it returns a "skip key"
 * so the caller does not build a spurious current-day (absent) row.
 */
class PreviousDayOutPunchUpdateService
{
    private int $systemUserId;

    public function __construct()
    {
        $this->systemUserId = (int) env('SYSTEM_USER_ID', 1);
    }

    /**
     * @param  Collection  $officialInfos  EmployeeOfficialInformation rows with `employeeAttendanceTemps` eager-loaded
     * @param  array<int,string>  $dates    dates being processed (Y-m-d)
     * @param  callable  $shiftResolver     fn(object $row, string $date): ?object  → resolves the shift for that emp/date
     * @return array<string,int>            skip keys "employee_user_id|date" whose punch was consumed as the previous day's night checkout
     */
    public function process(Collection $officialInfos, array $dates, callable $shiftResolver): array
    {
        $skipKeys = [];
        $now      = date('Y-m-d H:i:s');

        Log::info('PreviousDayOutPunchUpdateService started', [
            'employee_count' => $officialInfos->count(),
            'dates' => $dates,
        ]);

        foreach ($officialInfos as $row) {
            // Only act on employees whose biometric punches are actually loaded.
            // (Manual / correction flows swap in fake punches later and must not be auto-updated here.)
            if (!$row->relationLoaded('employeeAttendanceTemps') || $row->employeeAttendanceTemps->isEmpty()) {
                Log::info('PreviousDayOutPunchUpdateService skipped employee: no biometric temps', [
                    'employee_user_id' => $row->employee_user_id ?? null,
                    'emp_code' => $row->emp_code ?? null,
                    'temps_loaded' => $row->relationLoaded('employeeAttendanceTemps'),
                ]);
                continue;
            }

            foreach ($dates as $date) {
                $shift = $shiftResolver($row, $date);
                if (!$shift) {
                    Log::info('PreviousDayOutPunchUpdateService skipped date: shift not found', [
                        'employee_user_id' => $row->employee_user_id,
                        'emp_code' => $row->emp_code ?? null,
                        'date' => $date,
                    ]);
                    continue;
                }

                [$first, $last, $lastBeforeCutoff] = $this->resolveFirstLastPunch($row, $date, $shift);

                Log::info('PreviousDayOutPunchUpdateService punches resolved', [
                    'employee_user_id' => $row->employee_user_id,
                    'emp_code' => $row->emp_code ?? null,
                    'date' => $date,
                    'shift_id' => $shift->id ?? null,
                    'is_overnight' => (int) ($shift->is_overnight ?? 0),
                    'start_check_in_time' => $shift->start_check_in_time ?? null,
                    'clock_in' => $shift->clock_in ?? null,
                    'clock_out' => $shift->clock_out ?? null,
                    'first' => $first->punch_datetime ?? null,
                    'last' => $last->punch_datetime ?? null,
                    'last_before_cutoff' => $lastBeforeCutoff->punch_datetime ?? null,
                    'prev_date' => date('Y-m-d', strtotime($date . ' -1 day')),
                ]);

                // Only a punch that fell BEFORE the check-in cutoff on $date can be
                // the previous day's cross-midnight checkout. Nothing to do otherwise.
                if ($lastBeforeCutoff === null) {
                    Log::info('PreviousDayOutPunchUpdateService no early punch to back-fill previous day', [
                        'employee_user_id' => $row->employee_user_id,
                        'date' => $date,
                    ]);
                    continue;
                }

                if ((int) ($shift->is_overnight ?? 0) === 1) {
                    // Two calendar-day shift: check-in happened on $date-1, checkout is
                    // this morning. Back-fill $date-1; when $date has no fresh check-in
                    // ($first === null) skip its otherwise-spurious current-day row.
                    $updated = $this->backfillTwoDayShiftCheckout($row, $date, $lastBeforeCutoff, $now);
                    Log::info('PreviousDayOutPunchUpdateService two-day shift back-fill result', [
                        'employee_user_id' => $row->employee_user_id,
                        'date' => $date,
                        'updated' => $updated,
                        'has_fresh_check_in' => (bool) $first,
                        'will_skip_current_day' => $updated && $first === null,
                    ]);
                    if ($updated && $first === null) {
                        $skipKey = $row->employee_user_id . '|' . $date;
                        $skipKeys[$skipKey] = 2;
                        Log::info('PreviousDayOutPunchUpdateService skip key added', [
                            'skip_key' => $skipKey,
                        ]);
                    }
                } else {
                    // Single calendar-day shift: an early $date punch is $date-1's
                    // overnight / overtime checkout.
                    $updated = $this->backfillSingleDayOvertimeCheckout($row, $date, $lastBeforeCutoff, $now);
                    Log::info('PreviousDayOutPunchUpdateService single-day overtime back-fill result', [
                        'employee_user_id' => $row->employee_user_id,
                        'date' => $date,
                        'updated' => $updated,
                    ]);
                }
            }
        }

        Log::info('PreviousDayOutPunchUpdateService completed', [
            'skip_key_count' => count($skipKeys),
            'skip_keys' => array_keys($skipKeys),
        ]);

        return $skipKeys;
    }

    /**
     * Single calendar-day shift (is_overnight = 0): the employee stayed past
     * midnight, so the previous day's checkout spilled onto $date. Back-fill the
     * previous day and mark it as Over Night Duty (status 13) + night allowance.
     * Ported from the old AttendanceProcessingService::handleOvernightCheckoutForNormalShift.
     */
    private function backfillSingleDayOvertimeCheckout(
        object $row,
        string $date,
        ?object $lastBeforeCutoff,
        string $now
    ): bool {
        if ($lastBeforeCutoff == null) {
            return false;
        }

        $prevDate = date('Y-m-d', strtotime($date . ' -1 day'));
        $prevAttendance = EmployeeAttendance::where('employee_user_id', $row->employee_user_id)
            ->where('date', $prevDate)
            ->whereNotNull('in_time')
            ->first();

        if (!$prevAttendance) {
            Log::info('PreviousDayOutPunchUpdateService single-day: previous attendance not found', [
                'employee_user_id' => $row->employee_user_id,
                'date' => $date,
                'prev_date' => $prevDate,
                'checkout_punch' => $lastBeforeCutoff->punch_datetime ?? null,
            ]);
            return false;
        }

        $outTime = date('H:i:s', strtotime($lastBeforeCutoff->punch_datetime));
        $outDate = date('Y-m-d', strtotime($lastBeforeCutoff->punch_datetime));

        Log::info('PreviousDayOutPunchUpdateService single-day: updating previous attendance', [
            'employee_user_id' => $row->employee_user_id,
            'prev_attendance_id' => $prevAttendance->id,
            'prev_date' => $prevDate,
            'existing_in_time' => $prevAttendance->in_time,
            'existing_out_time' => $prevAttendance->out_time,
            'existing_out_date' => $prevAttendance->out_date,
            'new_out_time' => $outTime,
            'new_out_date' => $outDate,
        ]);

        $prevAttendance->update([
            'out_time' => $outTime,
            'out_date' => $outDate,
        ]);

        $hasNightCheckoutStatus = EmployeeAttendanceStatusLog::where('employee_attendance_id', $prevAttendance->id)
            ->where('attendance_status', 13)
            ->where('attendance_date', $date)
            ->exists();

        if (!$hasNightCheckoutStatus) {
            $deletedIncompleteOut = EmployeeAttendanceStatusLog::where('employee_attendance_id', $prevAttendance->id)
                ->where('attendance_status', 11)
                ->delete();

            Log::info('PreviousDayOutPunchUpdateService single-day: status 11 removed, status 13 inserted', [
                'employee_user_id' => $row->employee_user_id,
                'prev_attendance_id' => $prevAttendance->id,
                'deleted_incomplete_out_count' => $deletedIncompleteOut,
                'attendance_date_for_status_13' => $date,
            ]);

            EmployeeAttendanceStatusLog::insert([
                'employee_user_id'       => $row->employee_user_id,
                'employee_attendance_id' => $prevAttendance->id,
                'leave_application_id'   => null,
                'attendance_status'      => 13, // Over Night Duty
                'attendance_date'        => $date,
                'created_user_id'        => $this->systemUserId,
                'created_at'             => $now,
            ]);
        } else {
            Log::info('PreviousDayOutPunchUpdateService single-day: status 13 already exists', [
                'employee_user_id' => $row->employee_user_id,
                'prev_attendance_id' => $prevAttendance->id,
                'attendance_date' => $date,
            ]);
        }

        $hasNightAllowance = PayrollAccruedAllowanceIncome::where('employee_attendance_id', $prevAttendance->id)
            ->where('type', 6)
            ->where('date', $date)
            ->exists();

        if (!$hasNightAllowance) {
            $amount = ($row->gross_salary / date('t')) * 1;
            Log::info('PreviousDayOutPunchUpdateService single-day: night allowance inserted', [
                'employee_user_id' => $row->employee_user_id,
                'prev_attendance_id' => $prevAttendance->id,
                'amount' => $amount,
                'type' => 6,
                'date' => $date,
            ]);
            PayrollAccruedAllowanceIncome::insert([
                'employee_user_id'       => $row->employee_user_id,
                'employee_attendance_id' => $prevAttendance->id,
                'amount'                 => $amount,
                'type'                   => 6, // Night Duty Allowance
                'month'                  => date('m'),
                'year'                   => date('Y'),
                'date'                   => $date,
                'created_user_id'        => $this->systemUserId,
                'created_at'             => $now,
            ]);
        }

        return true;
    }

    /**
     * Two calendar-day shift (is_overnight = 1): the check-in was recorded on
     * $date-1 and the checkout is this morning's early punch ($lastBeforeCutoff,
     * i.e. before the evening check-in cutoff). Back-fill the previous day's out
     * and mark it Night Duty checkout (status 12). No extra allowance — the whole
     * shift is a scheduled night shift, not overtime.
     *
     * Returns true when the previous day was updated (so the caller can decide to
     * skip a spurious current-day row).
     */
    private function backfillTwoDayShiftCheckout(
        object $row,
        string $date,
        ?object $lastBeforeCutoff,
        string $now
    ): bool {
        if ($lastBeforeCutoff == null) {
            return false;
        }

        $prevDate = date('Y-m-d', strtotime($date . ' -1 day'));
        $prevAttendance = EmployeeAttendance::where('employee_user_id', $row->employee_user_id)
            ->where('date', $prevDate)
            ->whereNotNull('in_time')
            ->first();

        if (!$prevAttendance) {
            Log::info('PreviousDayOutPunchUpdateService two-day: previous attendance not found', [
                'employee_user_id' => $row->employee_user_id,
                'date' => $date,
                'prev_date' => $prevDate,
                'checkout_punch' => $lastBeforeCutoff->punch_datetime ?? null,
            ]);
            return false;
        }

        $outTime = date('H:i:s', strtotime($lastBeforeCutoff->punch_datetime));
        $outDate = date('Y-m-d', strtotime($lastBeforeCutoff->punch_datetime));

        Log::info('PreviousDayOutPunchUpdateService two-day: updating previous attendance', [
            'employee_user_id' => $row->employee_user_id,
            'prev_attendance_id' => $prevAttendance->id,
            'prev_date' => $prevDate,
            'existing_in_time' => $prevAttendance->in_time,
            'existing_out_time' => $prevAttendance->out_time,
            'existing_out_date' => $prevAttendance->out_date,
            'new_out_time' => $outTime,
            'new_out_date' => $outDate,
        ]);

        $prevAttendance->update([
            'out_time' => $outTime,
            'out_date' => $outDate,
        ]);

        $hasNightCheckoutStatus = EmployeeAttendanceStatusLog::where('employee_attendance_id', $prevAttendance->id)
            ->where('attendance_status', 12)
            ->where('attendance_date', $date)
            ->exists();

        if (!$hasNightCheckoutStatus) {
            // Record is now complete → drop the stale "Incomplete Out" (11) flag.
            $deletedIncompleteOut = EmployeeAttendanceStatusLog::where('employee_attendance_id', $prevAttendance->id)
                ->where('attendance_status', 11)
                ->delete();

            Log::info('PreviousDayOutPunchUpdateService two-day: status 11 removed, status 12 inserted', [
                'employee_user_id' => $row->employee_user_id,
                'prev_attendance_id' => $prevAttendance->id,
                'deleted_incomplete_out_count' => $deletedIncompleteOut,
                'attendance_date_for_status_12' => $date,
            ]);

            EmployeeAttendanceStatusLog::insert([
                'employee_user_id'       => $row->employee_user_id,
                'employee_attendance_id' => $prevAttendance->id,
                'leave_application_id'   => null,
                'attendance_status'      => 12, // Night Duty (checkout)
                'attendance_date'        => $date,
                'created_user_id'        => $this->systemUserId,
                'created_at'             => $now,
            ]);
        } else {
            Log::info('PreviousDayOutPunchUpdateService two-day: status 12 already exists', [
                'employee_user_id' => $row->employee_user_id,
                'prev_attendance_id' => $prevAttendance->id,
                'attendance_date' => $date,
            ]);
        }

        return true;
    }

    /**
     * Resolve first / last / last-before-cutoff punches for a single day.
     * Ported from AttendanceProcessingService::resolveFirstLastPunch (debug logging
     * removed) to keep punch selection identical to the main service.
     */
    private function resolveFirstLastPunch(object $row, string $date, ?object $shift): array
    {
        if (!$shift || $row->employeeAttendanceTemps->isEmpty()) {
            return [null, null, null];
        }

        $start = Carbon::parse($date . ' ' . $shift->start_check_in_time);
        $end   = Carbon::parse($date . ' ' . $shift->end_check_in_time);

        $temps = $row->employeeAttendanceTemps
            ->unique('punch_datetime')
            ->sortBy('punch_datetime')
            ->values();

        if ($temps->isEmpty()) {
            return [null, null, null];
        }

        $cutoffTime = $shift->start_check_in_time;

        $beforeCutoff = $temps->filter(function ($t) use ($cutoffTime) {
            return Carbon::parse($t->punch_datetime)->format('H:i:s') < $cutoffTime;
        })->values();

        $afterCutoff = $temps->filter(function ($t) use ($cutoffTime) {
            return Carbon::parse($t->punch_datetime)->format('H:i:s') >= $cutoffTime;
        })->values();

        $checkInWindowPunches = $afterCutoff->filter(function ($t) use ($start, $end) {
            $punchTime = Carbon::parse($t->punch_datetime);
            return $punchTime->betweenIncluded($start, $end);
        })->values();

        $afterCheckInWindowPunches = $afterCutoff->reject(function ($t) use ($start, $end) {
            $punchTime = Carbon::parse($t->punch_datetime);
            return $punchTime->betweenIncluded($start, $end);
        })->values();

        $cleanedAfterCutoff = collect();
        if ($checkInWindowPunches->isNotEmpty()) {
            $cleanedAfterCutoff->push($checkInWindowPunches->first());
        }
        $cleanedAfterCutoff = $cleanedAfterCutoff
            ->concat($afterCheckInWindowPunches)
            ->values();

        $lastBeforeCutoff = $beforeCutoff->last();
        $first = $cleanedAfterCutoff->first();
        $last = $cleanedAfterCutoff->last();

        if (!$first && $lastBeforeCutoff) {
            $first = null;
            $last = null;
        }

        if ($first && $last) {
            $firstTrimmed = Carbon::parse((string) $first->punch_datetime)->startOfMinute();
            $lastTrimmed = Carbon::parse((string) $last->punch_datetime)->startOfMinute();
            if ($firstTrimmed->eq($lastTrimmed)) {
                $last = null;
            }
        }

        if ($first && $last && strtotime($date . ' ' . $shift->clock_in . '+ 10 minutes') >= strtotime($last->punch_datetime)) {
            $last = null;
        }

        return [$first, $last, $lastBeforeCutoff];
    }
}
