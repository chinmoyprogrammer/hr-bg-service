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
 * It covers both shift types, distinguished by the PREVIOUS day's shift->is_overnight
 * flag (roster assignments can change day to day, so the day being closed out must
 * be classified by its OWN shift, not by whatever shift is scheduled on the day the
 * early punch physically landed on):
 *   - single calendar-day shift (is_overnight = 0): shift start & end fall on the
 *     same calendar day. A checkout that spills past midnight is overnight/overtime
 *     duty → previous day gets status 13 (Over Night Duty) + night allowance.
 *   - two calendar-day shift    (is_overnight = 1): shift start & end span two
 *     calendar days, so the checkout naturally lands on the next calendar day →
 *     previous day gets status 12 (Night Duty checkout), no extra allowance.
 *
 * In both cases the next day's early punch (before the check-in cutoff) is what
 * back-fills the previous day's out_time / out_date. When that next day carries
 * ONLY that checkout punch (no fresh check-in), this service returns a
 * "carry-over key" for it: employee_user_id|date => 12 or 13 (the night-duty
 * status code matching the shift type just closed out). The caller must then
 * build that date as Incomplete In (status 10) + the carry-over status, instead
 * of marking it Absent — the punch was real, it was just spent completing the
 * previous day's checkout, so the day itself is still "open" pending its own
 * check-in.
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
     * @return array<string,int>            carry-over keys "employee_user_id|date" => 12|13, for dates whose only
     *                                       punch was consumed as the previous day's night checkout
     */
    public function process(Collection $officialInfos, array $dates, callable $shiftResolver): array
    {
        $carryOverKeys = [];
        $now           = date('Y-m-d H:i:s');

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

                // Resolve $prevShift BEFORE selecting the completion-punch candidate.
                // The candidate itself must be chosen using $prevShift's own after-hours
                // window (its clock_out through its next start_check_in_time) — not just
                // "anything before $date's own cutoff". Selecting first and validating
                // after the fact would only reject a bad candidate; it would never go
                // back and pick a DIFFERENT, genuinely-valid earlier punch that also
                // happened to fall before $date's (possibly much later) cutoff.
                $prevDate  = date('Y-m-d', strtotime($date . ' -1 day'));
                $prevShift = $shiftResolver($row, $prevDate);
                Log::info('Previous Shift:', [
                    'prev_date' => $prevDate,
                    'prev_shift_id' => $prevShift->id ?? null,
                ]);

                [$first, $last, $lastBeforeCutoff] = $this->resolveFirstLastPunch($row, $date, $shift, $prevShift, $prevDate);

                //var_dump($first, $last, $lastBeforeCutoff);

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
                    'prev_date' => $prevDate,
                    'prev_shift_id' => $prevShift->id ?? null,
                    'prev_is_overnight' => $prevShift ? (int) ($prevShift->is_overnight ?? 0) : null,
                ]);

                // No punch fell within $prevShift's plausible after-hours window —
                // nothing to back-fill for $prevDate from this date's punches.
                if ($lastBeforeCutoff === null) {
                    Log::info('PreviousDayOutPunchUpdateService no plausible early punch to back-fill previous day', [
                        'employee_user_id' => $row->employee_user_id,
                        'date' => $date,
                    ]);
                    continue;
                }

                // The shift that decides HOW to close out the previous day (single-day
                // overtime vs. scheduled two-day night duty) must be the shift that
                // actually governed $prevDate — NOT $date's own shift. Roster
                // assignments can change day to day, so falling back to $shift here
                // only when $prevShift can't be resolved at all.
                $shiftForPrevDayClassification = $prevShift ?? $shift;

                if ((int) ($shiftForPrevDayClassification->is_overnight ?? 0) === 1) {
                    // Two calendar-day shift: check-in happened on $date-1, checkout is
                    // this morning. Back-fill $date-1; when $date has no fresh check-in
                    // ($first === null), $date itself carries over as Incomplete In (10)
                    // + Night Duty checkout (12) rather than being left Absent.
                    $updated = $this->backfillTwoDayShiftCheckout($row, $date, $lastBeforeCutoff, $now, $prevShift);
                    Log::info('PreviousDayOutPunchUpdateService two-day shift back-fill result', [
                        'employee_user_id' => $row->employee_user_id,
                        'date' => $date,
                        'updated' => $updated,
                        'has_fresh_check_in' => (bool) $first,
                        'will_carry_over_current_day' => $updated && $first === null,
                    ]);
                    if ($updated && $first === null) {
                        $carryOverKey = $row->employee_user_id . '|' . $date;
                        $carryOverKeys[$carryOverKey] = 12; // Night Duty (checkout)
                        Log::info('PreviousDayOutPunchUpdateService carry-over key added', [
                            'carry_over_key' => $carryOverKey,
                            'carry_over_status' => 12,
                        ]);
                    }
                } else {
                    // Single calendar-day shift: an early $date punch is $date-1's
                    // overnight / overtime checkout. When $date has no fresh check-in
                    // ($first === null), $date itself carries over as Incomplete In (10)
                    // + Over Night Duty (13) rather than being left Absent.
                    $updated = $this->backfillSingleDayOvertimeCheckout($row, $date, $lastBeforeCutoff, $now, $prevShift);
                    Log::info('PreviousDayOutPunchUpdateService single-day overtime back-fill result', [
                        'employee_user_id' => $row->employee_user_id,
                        'date' => $date,
                        'updated' => $updated,
                        'has_fresh_check_in' => (bool) $first,
                        'will_carry_over_current_day' => $updated && $first === null,
                    ]);
                    if ($updated && $first === null) {
                        $carryOverKey = $row->employee_user_id . '|' . $date;
                        $carryOverKeys[$carryOverKey] = 13; // Over Night Duty
                        Log::info('PreviousDayOutPunchUpdateService carry-over key added', [
                            'carry_over_key' => $carryOverKey,
                            'carry_over_status' => 13,
                        ]);
                    }
                }
            }
        }

        Log::info('PreviousDayOutPunchUpdateService completed', [
            'carry_over_key_count' => count($carryOverKeys),
            'carry_over_keys' => $carryOverKeys,
        ]);

        return $carryOverKeys;
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
        string $now,
        ?object $prevShift = null
    ): bool {
        if ($lastBeforeCutoff == null) {
            return false;
        }

        $prevDate = date('Y-m-d', strtotime($date . ' -1 day'));
        $prevAttendance = EmployeeAttendance::where('employee_user_id', $row->employee_user_id)
            ->where('date', $prevDate)
            ->where('is_manual', 0)
            ->where('is_corrected', 0)
            ->whereNotNull('in_time')
            // Defense in depth: only a genuinely INCOMPLETE previous day (no out_time
            // yet) should ever be touched here. Without this, any already-complete
            // record matching the date/in_time criteria would get silently
            // overwritten the moment ANY later punch happens to pass the
            // plausibility window check above.
            ->whereNull('out_time')
            ->first();

        if (!$prevAttendance) {
            Log::info('PreviousDayOutPunchUpdateService single-day: previous attendance not found or is manual/corrected (protected)', [
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

        $this->applyEarlyOutIfNeeded($row, $date, $prevShift, $prevAttendance, $lastBeforeCutoff, $now);

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
        string $now,
        ?object $prevShift = null
    ): bool {
        if ($lastBeforeCutoff == null) {
            return false;
        }

        $prevDate = date('Y-m-d', strtotime($date . ' -1 day'));
        $prevAttendance = EmployeeAttendance::where('employee_user_id', $row->employee_user_id)
            ->where('date', $prevDate)
            ->where('is_manual', 0)
            ->where('is_corrected', 0)
            ->whereNotNull('in_time')
            // Defense in depth: only a genuinely INCOMPLETE previous day (no out_time
            // yet) should ever be touched here. Without this, any already-complete
            // record matching the date/in_time criteria would get silently
            // overwritten the moment ANY later punch happens to pass the
            // plausibility window check above.
            ->whereNull('out_time')
            ->first();

        if (!$prevAttendance) {
            Log::info('PreviousDayOutPunchUpdateService two-day: previous attendance not found or is manual/corrected (protected)', [
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

        $this->applyEarlyOutIfNeeded($row, $date, $prevShift, $prevAttendance, $lastBeforeCutoff, $now);

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
     * Mirrors AttendanceProcessingService::applyEarlyOutStatus for the checkout
     * punch that just back-filled the previous day's attendance row. Without this,
     * a night/overnight-shift checkout that crosses midnight never gets evaluated
     * against the shift's clock_out window at all — it always lands as status
     * 12/13 only, even when the employee left well before their scheduled
     * clock_out. $prevShift is the shift that actually governed the day being
     * closed out (resolved for $prevDate, NOT $date), since that's whose
     * clock_out_start_time / clock_out the checkout must be measured against.
     */
    private function applyEarlyOutIfNeeded(
        object $row,
        string $date,
        ?object $prevShift,
        EmployeeAttendance $prevAttendance,
        object $lastBeforeCutoff,
        string $now
    ): void {
        if (!$prevShift || !$prevShift->clock_out_start_time || !$prevShift->clock_out) {
            return;
        }

        $punchTime = strtotime($lastBeforeCutoff->punch_datetime);
        $isEarlyOut = $punchTime >= strtotime($date . ' ' . $prevShift->clock_out_start_time)
            && $punchTime <  strtotime($date . ' ' . $prevShift->clock_out);

        if (!$isEarlyOut) {
            return;
        }

        $hasEarlyOutStatus = EmployeeAttendanceStatusLog::where('employee_attendance_id', $prevAttendance->id)
            ->where('attendance_status', 7)
            ->where('attendance_date', $date)
            ->exists();

        if ($hasEarlyOutStatus) {
            return;
        }

        Log::info('PreviousDayOutPunchUpdateService: Early Out detected on cross-midnight checkout', [
            'employee_user_id' => $row->employee_user_id,
            'prev_attendance_id' => $prevAttendance->id,
            'date' => $date,
            'checkout_punch' => $lastBeforeCutoff->punch_datetime,
            'clock_out_start_time' => $prevShift->clock_out_start_time,
            'clock_out' => $prevShift->clock_out,
        ]);

        EmployeeAttendanceStatusLog::insert([
            'employee_user_id'       => $row->employee_user_id,
            'employee_attendance_id' => $prevAttendance->id,
            'leave_application_id'   => null,
            'attendance_status'      => 7, // Early Out
            'attendance_date'        => $date,
            'created_user_id'        => $this->systemUserId,
            'created_at'             => $now,
        ]);

        $approvedEarlyOut = $row->hasEarlyOutRequests?->where('out_date', $date)->first();
        if (!$approvedEarlyOut) {
            return;
        }

        $hasEarlyOutAuthorizedStatus = EmployeeAttendanceStatusLog::where('employee_attendance_id', $prevAttendance->id)
            ->where('attendance_status', 23)
            ->where('attendance_date', $date)
            ->exists();

        if ($hasEarlyOutAuthorizedStatus) {
            return;
        }

        EmployeeAttendanceStatusLog::insert([
            'employee_user_id'       => $row->employee_user_id,
            'employee_attendance_id' => $prevAttendance->id,
            'leave_application_id'   => null,
            'attendance_status'      => 23, // Early Out Authorized
            'attendance_date'        => $date,
            'created_user_id'        => $this->systemUserId,
            'created_at'             => $now,
        ]);
    }

    /**
     * Resolve first / last / last-before-cutoff punches for a single day.
     * Ported from AttendanceProcessingService::resolveFirstLastPunch (debug logging
     * removed) to keep punch selection identical to the main service, EXCEPT for
     * lastBeforeCutoff: that candidate is selected using $prevShift's own
     * after-hours window (its clock_out through its next start_check_in_time), not
     * merely "before $date's cutoff". Roster assignments can change day to day, so
     * $date's shift may bear no relation to whatever shift actually governed
     * $prevDate — picking the chronologically-last pre-cutoff punch and validating
     * it afterwards would reject a bad candidate but never go back and try a
     * different, genuinely-plausible earlier one.
     */
    private function resolveFirstLastPunch(object $row, string $date, ?object $shift, ?object $prevShift = null, ?string $prevDate = null): array
    {
        if (!$shift || $row->employeeAttendanceTemps->isEmpty()) {
            return [null, null, null];
        }

        $start = Carbon::parse($date . ' ' . $shift->start_check_in_time);
        $end   = Carbon::parse($date . ' ' . $shift->end_check_in_time);

        // Scope to punches physically dated $date. employeeAttendanceTemps can span
        // the whole batch date range (multi-day cron runs), and without this filter
        // the before/after-cutoff split below only checks time-of-day — a stray
        // early-morning punch from an UNRELATED date in that range could then be
        // misread as "yesterday's overnight checkout" and wrongly grant night
        // allowance for a day the employee never actually worked past midnight on.
        $temps = $row->employeeAttendanceTemps
            ->unique('punch_datetime')
            ->sortBy('punch_datetime')
            ->values()
            ->filter(function ($t) use ($date) {
                return Carbon::parse($t->punch_datetime)->format('Y-m-d') === $date;
            })
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

        // Select the completion-punch candidate using $prevShift's own after-hours
        // window — scanned directly from $temps (the FULL date-scoped punch list),
        // not from $beforeCutoff. $beforeCutoff was already partitioned using
        // $shift's ($date's own) cutoff time; if $prevShift's window extends past
        // that cutoff (e.g. $date's shift starts earlier than $prevShift's did), a
        // genuinely valid completion punch would already have been shunted into
        // $afterCutoff and never reach this candidate pool at all. Scanning $temps
        // directly with $prevShift's own bounds avoids that gate entirely.
        //
        // Upper bound (both shift types): must be before $prevShift's own next
        // start_check_in_time — once that passes, the same shift would be starting
        // its next cycle, so an even-later punch can't still be "closing out"
        // $prevDate. But roster assignments can change day to day, so if $date's
        // OWN shift starts even earlier than that, the bound must tighten to
        // $shift's start_check_in_time instead — once $date's own shift window
        // opens, any later punch belongs to $date's own check-in/out, not to a
        // leftover checkout from $prevDate's (possibly different) shift.
        //
        // Lower bound differs by shift type:
        //   - single-day shift (is_overnight=0): the whole scenario IS overtime past
        //     the scheduled end, so the punch must be AT/AFTER $prevShift's own
        //     clock_out — anything earlier is just normal same-day activity, not a
        //     late checkout.
        //   - two-day/overnight shift (is_overnight=1): clock_out IS the scheduled,
        //     expected end of the shift itself — checking out on time or slightly
        //     early is completely normal, so no lower bound is imposed beyond the
        //     start of $date itself.
        if ($prevShift && $prevDate && $prevShift->clock_out && $prevShift->start_check_in_time) {
            $prevShiftIsOvernight = (int) ($prevShift->is_overnight ?? 0) === 1;
            $windowStart = $prevShiftIsOvernight
                ? strtotime($date . ' 00:00:00')
                : strtotime($prevDate . ' ' . $prevShift->clock_out);
            $windowEnd = strtotime($date . ' ' . $prevShift->start_check_in_time);
            if ($shift && $shift->start_check_in_time) {
                $windowEnd = min($windowEnd, strtotime($date . ' ' . $shift->start_check_in_time));
            }

            $plausibleForPrevDay = $temps->filter(function ($t) use ($windowStart, $windowEnd) {
                $punchTime = strtotime($t->punch_datetime);
                return $punchTime >= $windowStart && $punchTime < $windowEnd;
            })->values();

            $lastBeforeCutoff = $plausibleForPrevDay->last();
        } else {
            // No resolvable prevShift — fall back to the generic (today-cutoff-based)
            // candidate, same as before.
            $lastBeforeCutoff = $beforeCutoff->last();
        }

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
