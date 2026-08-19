<?php

namespace App\Services;

use App\Models\EmployeeAttendance;
use App\Models\EmployeeAttendanceStatusLog;
use App\Models\EmployeeLeaveBalance;
use App\Models\LateAttendanceRecord;
use App\Models\LateAttendanceRecordDetail;
use App\Models\LeaveApplicationDetail;
use App\Models\PayrollAccruedAllowanceIncome;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Psy\Readline\Hoa\Console;

use function calculateOtHours;

class AttendanceProcessingService
{
    private int $systemUserId;
    private bool $isManual;
    private bool $isCorrected;
    private string $source;
    private ?string $remarks;
    private bool $isAbsentInFirstHalf;
    private ?int $leave_application_id;

    public function __construct()
    {
        $this->systemUserId = (int) env('SYSTEM_USER_ID', 1);
        $this->isManual = 0;
        $this->isCorrected = 0;
        $this->source = 'biometric';
        $this->remarks = null;
    }

    /**
     * Process a single employee for a single date.
     *
     * Returns a result DTO-style array with keys:
     *   - attendance: array|null          → row to bulk-insert into employee_attendances
     *   - rowKey: string                  → composite key used to map back the inserted ID
     *   - statusLogs: array               → rows for employee_attendance_status_logs
     *   - payrollAccruedItems: array      → rows for payroll_accrued_allowance_incomes
     *   - leaveAchieveLogs: array         → rows for employee_leave_achieve_logs
     */
    public function process(
        object     $row,
        string     $date,
        ?object    $shift,
        ?object    $publicHoliday,
        ?object    $empHoliday,
        ?Collection $holidayDutyRequisitions,
        ?object    $otRequisition,
        ?Collection $leaveApplicationDetails, // keyed collection of LeaveApplicationDetail for employee
        ?array      $manualPunch = null,
        ?int        $carryOverNightStatus = null, // 12|13 when this date's only punch was consumed by PreviousDayOutPunchUpdateService to close out the previous day
        ?int        $createdUserId = null
    ): array {

        $this->systemUserId = $createdUserId ?? $this->systemUserId;

        Log::info('Attendance processing started for employee:', ['employee_user_id' => $row->employee_user_id, 'date' => $date]);
        $this->isAbsentInFirstHalf = false;
        $this->leave_application_id = null;

        // ── Reset per-call state ────────────────────────────────────────────────
        // This service is bound as a SINGLETON (see AttendanceServiceProvider), so
        // the same instance is reused across every process() call within a queue
        // worker's lifetime — across employees, dates, AND across different job
        // types (ProcessManualDataJob, ProcessTempDataJob). Without an explicit
        // reset here, a prior manual/corrected call's leftover
        // is_manual/is_corrected/source/remarks would leak into the next call
        // whenever $manualPunch is null, causing biometric-sourced attendance rows
        // to be wrongly marked as 'manual'.
        $this->isManual = 0;
        $this->isCorrected = 0;
        $this->source = 'biometric';
        $this->remarks = null;

        // ── Manual punch override ─────────────────────────────────────────────────
        if ($manualPunch) {
            $outDate = $manualPunch['out_date'] ?? $date;
            $fakeTemps = collect();
            if (!empty($manualPunch['in_time'])) {
                $fakeTemps->push((object)[
                    'punch_datetime' => $date . ' ' . $manualPunch['in_time'],
                ]);
            }
            if (!empty($manualPunch['out_time'])) {
                $fakeTemps->push((object)[
                    'punch_datetime' => $outDate . ' ' . $manualPunch['out_time'],
                ]);
            }
            $row->employeeAttendanceTemps = $fakeTemps;
            $this->isManual = $manualPunch['is_manual'] ?? 0;
            $this->isCorrected = $manualPunch['is_corrected'] ?? 0;
            $this->source = 'manual';
            $this->remarks = $manualPunch['remarks'] ?? null;
        }

        Log::warning('tempAtt:', ['tempAtt employeeAttendanceTemps:'=>$row->employeeAttendanceTemps]);

        $now = date('Y-m-d H:i:s');

        $result = [
            'attendance'          => null,
            'rowKey'              => '',
            'statusLogs'          => [],
            'payrollAccruedItems' => [],
            'leaveAchieveLogs'    => [],
            'skip'                => false,
        ];
        Log::warning('shift:', ['employee shift object'=>$shift]);

        if ($row->joining_date && strtotime($date) < strtotime($row->joining_date)) {
            Log::warning('Attendance processing skipped because employee joined after this date.', [
                'employee_user_id' => $row->employee_user_id ?? null,
                'emp_code' => $row->emp_code ?? null,
                'date' => $date,
            ]);
            $result['skip'] = true;
            return $result;
        }

        if (!$shift) {
            Log::warning('Attendance processing skipped because shift was not found.', [
                'employee_user_id' => $row->employee_user_id ?? null,
                'emp_code' => $row->emp_code ?? null,
                'date' => $date,
                'shift_id' => $shift->id ?? null,
            ]);
            $result['skip'] = true;
            return $result;
        }

        $statusesForLog     = [];
        $has_halfday_leave  = false;

        // ── Determine whether employee has an approved OT requisition on this date ──
        $hasOtRequisition = $otRequisition
            && $otRequisition->where('ot_date_from', '>=', $date)
                ->where('employee_user_id', $row->employee_user_id)
                ->where('ot_date_to', '<=', $date)
                ->exists();

        // ── Resolve first / last punches for this date ────────────────────────────
        // NOTE: The previous day's out-punch back-fill (overnight / night-shift
        // cross-day checkout) is NO LONGER handled here. It now runs as a dedicated
        // pre-pass in PreviousDayOutPunchUpdateService, before this service is called.
        // This service only ever handles a single day's own attendance.
        [$first, $last, $lastBeforeCutoff] = $this->resolveFirstLastPunch($row, $date, $shift);
        Log::info('first after resolveFirstLastPunch:', ['first'=>$first, 'last'=>$last, 'lastBeforeCutoff'=>$lastBeforeCutoff]);

        // ── Manual/corrected entries: bypass the cutoff-window heuristic ──────────
        // resolveFirstLastPunch() exists to disambiguate NOISY multi-punch biometric
        // data by time-of-day. A manual/correction submission already states exactly
        // which timestamp is in_time and which is out_time — running that heuristic
        // over it is not just unnecessary, it actively misclassifies an overnight
        // out_time (early-morning time-of-day) as a "before cutoff" punch belonging
        // to some other day, silently dropping the submitted checkout. Rebuild
        // first/last directly from the submission instead, for both shift types.
        if ($manualPunch) {
            [$first, $last, $lastBeforeCutoff] = $this->resolveManualFirstLast($manualPunch, $date);
            Log::info('first/last overridden from manual submission:', ['first'=>$first, 'last'=>$last]);
        }

        // ── No punches at all: absent / leave / holiday ───────────────────────────
        if (!$first) {
            // This date's only punch (if any) was already consumed by
            // PreviousDayOutPunchUpdateService to close out the PREVIOUS day's
            // overnight/night-duty checkout. The day itself is not "absent" — it's
            // still open, pending its own check-in — so tag Incomplete In (10) +
            // the carry-over night-duty status (12/13) instead of marking Absent.
            if ($carryOverNightStatus !== null) {
                Log::info('AttendanceProcessingService applying carry-over status instead of absent', [
                    'employee_user_id' => $row->employee_user_id ?? null,
                    'emp_code' => $row->emp_code ?? null,
                    'date' => $date,
                    'carry_over_status' => $carryOverNightStatus,
                ]);
                $statusesForLog = [10, $carryOverNightStatus];
                $result['statusLogs'] = $this->buildStatusLogRows(
                    $row->employee_user_id, $date, $now, $statusesForLog
                );
                $result['attendance'] = $this->buildAttendanceRow(
                    $row, $date, $shift, null, null, null, false, $publicHoliday, $empHoliday, 0, $now
                );
                $result['rowKey'] = $this->makeRowKey($row->emp_code, $row->employee_user_id, $date, null, null, $now);
                return $result;
            }

            Log::info('AttendanceProcessingService treating as absent: no first punch after cutoff', [
                'employee_user_id' => $row->employee_user_id ?? null,
                'emp_code' => $row->emp_code ?? null,
                'date' => $date,
                'last_before_cutoff' => $lastBeforeCutoff->punch_datetime ?? null,
                'is_overnight' => (int) ($shift->is_overnight ?? 0),
            ]);
            $statusesForLog = $this->resolveAbsentStatuses(
                $date, $leaveApplicationDetails, $publicHoliday, $empHoliday
            );

            //Log::warning('Debug Status Logs:', ['statusesForLog'=>$statusesForLog]);
            
            //Log::warning('leave_application_id:', ['leave_application_id'=>$this->leave_application_id]);


            $result['statusLogs'] = $this->buildStatusLogRows(
                $row->employee_user_id, $date, $now, $statusesForLog
            );
            Log::info('after buildStatusLogRows:', ['statusLogs'=>$result['statusLogs']]);
            // Still build a bare attendance row so the date is recorded
            $result['attendance'] = $this->buildAttendanceRow(
                $row, $date, $shift, null, null, null, false, $publicHoliday, $empHoliday, 0, $now
            );
            Log::info('after buildAttendanceRow:', ['attendance'=>$result['attendance']]);
            $result['rowKey'] = $this->makeRowKey($row->emp_code, $row->employee_user_id, $date, null, null, $now);
            Log::info('after makeRowKey:', ['rowKey'=>$result['rowKey']]);
            //Log::warning('Debug attendance:', ['attendance'=>$result['attendance']]);
            return $result;
        }
        


        // ── Overnight shift: resolve out-date / out-time or delegate to next day ──
        [$outDate, $outTime] = $this->resolveOutDateTime($row, $date, $shift, $first, $last);
        if ($manualPunch) {
            // Manual/corrected entries already carry an explicit last punch — never
            // defer it to "resolved on next day" the way the biometric cron flow does.
            $outDate = $last ? Carbon::parse($last->punch_datetime)->format('Y-m-d') : null;
        }
        Log::warning('before overnight shift', [$result]);
        if($this->isManual==1 || $this->isCorrected==1){
            $this->handleOvernightCheckoutForNormalShiftManualOrCurrection($row,$date,$outDate,$shift,$first,$last,$statusesForLog,
            $result,$now);
            Log::info('after handleOvernightCheckoutForNormalShift check', [$result]);
        }
        

        // ── Night shift cross-day checkout update ─────────────────────────────────
        // Removed: the previous-day checkout update + current-day skip for night
        // shifts is now performed by PreviousDayOutPunchUpdateService (pre-pass).
        // The caller skips such dates via the skip-keys returned by that service.
        Log::warning('after overnight shift', [$result]);
        // ── Shift defaults ────────────────────────────────────────────────────────
        $shiftStart = $shift->clock_in      ?? '09:00:00';
        $shiftEnd   = $shift->clock_out     ?? '18:00:00';
        //$graceParts = explode(':', $shift->shift_grace_time ?? '00:00:00');
        // $grace = ($graceParts[0] * 60) + $graceParts[1];
        $grace = intval($shift->shift_grace_time);
        $workingHours = $this->calculateWorkingHours($first, $last, $shift);
        $inTime  = $first ? Carbon::parse($first->punch_datetime)->format('H:i:s') : null;
        $outTime = $last  ? Carbon::parse($last->punch_datetime)->format('H:i:s')  : null;
        $outDate = $last  ? Carbon::parse($last->punch_datetime)->format('Y-m-d')  : null;

        //dd('--->>>>',$first, $last, $lastBeforeCutoff,'<<<<----');
        Log::info('first after resolveFirstLastPunch:', ['first'=>$first, 'last'=>$last, 'lastBeforeCutoff'=>$lastBeforeCutoff]);
        $this->applyHolidayDutyStatuses(
            $row, $date, $publicHoliday, $empHoliday, $holidayDutyRequisitions,
            $statusesForLog, $result, $now, $first
        );

        $this->applyIncompleteInOutStatus($date, $shift, $first, $last, $statusesForLog);
        $this->applyEarlyOutStatus($row, $date, $shift, $last, $shiftEnd, $statusesForLog);

        $has_halfday_leave = $this->applyFirstHalfDayStatus(
            $date, $shift, $first, $last, $leaveApplicationDetails, $statusesForLog
        );

        $this->applySecondHalfDayStatus(
            $date, $shift, $first, $last, $leaveApplicationDetails, $has_halfday_leave, $statusesForLog
        );

        // ── Attendance statuses ───
        $this->applyPresentOrLateStatus(
            $row, $date, $shift, $first, $last, $shiftStart, $grace, $now, $statusesForLog
        );

        $this->applyBothHalfDayAbsentStatus(
            $date, $shift, $first, $last, $statusesForLog
        );

        // ── OT calculation ────
        $transferedToOTStatus = false;
        /* $transferedToOTStatus = ($first && $last)
            ? calculateOtHours($row, $otRequisition, $hasOtRequisition, $shift, $first, $last, $this->systemUserId)
            : false; */

        // ── Join date flag ────────────────────────────────────────────────────────
        $isJoin = ($date === $row->joining_date) ? 1 : 0;

        [$inTime, $outDate, $outTime] = $this->normalizeAttendanceTimes(
            $date,
            $inTime,
            $outDate,
            $outTime,
            $statusesForLog
        );

        $rowKey = $this->makeRowKey($row->emp_code, $row->employee_user_id, $date, $inTime, $outTime, $now);

        // ── Assemble result ───────────────────────────────────────────────────────
        Log::info('before assemble result', [$row, $date, $shift, $inTime, $outDate, $outTime,
            $transferedToOTStatus, $publicHoliday, $empHoliday, $isJoin, $now, $workingHours]);
        $result['rowKey']    = $rowKey;
        $result['attendance'] = $this->buildAttendanceRow(
            $row, $date, $shift, $inTime, $outDate, $outTime,
            $transferedToOTStatus, $publicHoliday, $empHoliday, $isJoin, $now, $workingHours
        );
        $result['statusLogs'] = $this->buildStatusLogRows(
            $row->employee_user_id, $date, $now, $statusesForLog
        );


        //..... Deactivate user if mentioned in the separation application [start]

        if(strtotime(date('Y-m-d').' '.$shift->clock_out) <= strtotime($now) && $last != null)
        {
        
            //.. find the separation application
            $separationApplication = $row->hasSeparationApplication?->where('action_type', 'after_checkout')->first();
            if ($separationApplication?->action_type === 'after_checkout') {
                $separationApplicationInsertData = [
                    'status' => 0,
                    'login_eligibility' => 0,
                ];
                \App\Models\User::where('employee_user_id', $row->employee_user_id)->update($separationApplicationInsertData);
                // update the separation application status
                $separationApplication->update([
                    'status' => 'Approved',
                    'approval_status' => 1,
                    'approve_reject_date' => date('Y-m-d'),
                    'employee_status_updated' => 1,
                ]);
                // deactivate the employee from ZKBio
                deactivateEmployeeFromDevice($row->emp_code_old == 0 ? $row->emp_code_old : $row->emp_code);
            }
        }

        //..... Deactivate user if mentioned in the separation application [end]
        

        return $result;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Private helpers
    // ═══════════════════════════════════════════════════════════════════════════

    private function resolveFirstLastPunch(object $row, string $date, ?object $shift): array
    {
        Log::warning('resolveFirstLastPunch:9999', ['resolveFirstLastPunch'=>$row]);
        if (!$shift || $row->employeeAttendanceTemps->isEmpty()) {
            return [null, null, null];
        }
Log::warning('resolveFirstLastPunch 1 :', ['resolveFirstLastPunch'=>$row]);
        // Build a window for the check-in period
        $start = Carbon::parse($date.' '.$shift->start_check_in_time);
        $end   = Carbon::parse($date.' '.$shift->end_check_in_time);
Log::warning('resolveFirstLastPunch 2 :', ['resolveFirstLastPunch'=>$row]);
        // Split punches by a fixed 05:00 cutoff:
        // - keep punches at/after 05:00:00 for normal first/last selection
        // - from 00:00:00 to 04:59:59 keep only the latest one
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

        if ($temps->isEmpty())
        {
            return [null, null, null];
        }

         Log::warning('resolveFirstLastPunch 3 :', ['resolveFirstLastPunch'=>$row]);

        $cutoffTime = $shift->start_check_in_time;

        $beforeCutoff = $temps->filter(function ($t) use ($cutoffTime) {
            return Carbon::parse($t->punch_datetime)->format('H:i:s') < $cutoffTime;
        })->values();
Log::warning('resolveFirstLastPunch 4 :', ['resolveFirstLastPunch'=>$row]);
        $afterCutoff = $temps->filter(function ($t) use ($cutoffTime) {
            return Carbon::parse($t->punch_datetime)->format('H:i:s') >= $cutoffTime;
        })->values();
Log::warning('resolveFirstLastPunch 5 :', ['resolveFirstLastPunch'=>$row]);
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
Log::warning('resolveFirstLastPunch 6 :', ['resolveFirstLastPunch'=>$row]);
        if (!$first && $lastBeforeCutoff) {
            $first = null;
            $last = null;
        }
Log::warning('resolveFirstLastPunch 7 :', ['resolveFirstLastPunch'=>$row]);
        if ($first && $last) {
            $firstTrimmed = Carbon::parse((string) $first->punch_datetime)->startOfMinute();
            $lastTrimmed = Carbon::parse((string) $last->punch_datetime)->startOfMinute();
            if ($firstTrimmed->eq($lastTrimmed)) {
                $last = null;
            }
        }
Log::warning('resolveFirstLastPunch 8 :', [$first , $last]);


        if ($first && $last && strtotime($date.' '.$shift->clock_in.'+ 10 minutes') >= strtotime($last->punch_datetime)) {
            $last = null;
        }


        // $cleanedTemps = $afterCutoff;
        // if ($lastBeforeCutoff) {
        //     $cleanedTemps = $cleanedTemps->push($lastBeforeCutoff);
        // }
        // $row->employeeAttendanceTemps = $cleanedTemps
        //     ->unique('punch_datetime')
        //     ->sortBy('punch_datetime')
        //     ->values();



        Log::warning('In Out times with cutoff time', ['payload' => [$first, $last,$lastBeforeCutoff]]);

        return [$first, $last,$lastBeforeCutoff];
    }

    /**
     * Build first/last punch objects directly from a manual/correction submission,
     * bypassing the cutoff-window heuristic entirely. Both in_time and out_time
     * (with its own out_date, which may be the next calendar day for an overnight
     * checkout) are already explicit — no ambiguity to resolve.
     */
    private function resolveManualFirstLast(array $manualPunch, string $date): array
    {
        $first = !empty($manualPunch['in_time'])
            ? (object) ['punch_datetime' => $date . ' ' . $manualPunch['in_time']]
            : null;

        $outDate = $manualPunch['out_date'] ?? $date;
        $last = !empty($manualPunch['out_time'])
            ? (object) ['punch_datetime' => $outDate . ' ' . $manualPunch['out_time']]
            : null;

        return [$first, $last, null];
    }

    private function resolveAbsentStatuses(
        string      $date,
        ?Collection $leaveApplicationDetails,
        ?object     $publicHoliday,
        ?object     $empHoliday
    ): array {
        if ($leaveApplicationDetails && $leaveApplicationDetails->where('first_second_half', 8)->contains('leave_date', $date)) {
            $this->leave_application_id = $leaveApplicationDetails->where('first_second_half', 8)->where('leave_date', $date)->first()->leave_application_id;
            return [8];// Full-day approved leave
        }

        if ($publicHoliday) {
            return [9]; // Public Holiday Absent
        }

        if ($empHoliday) {
            return [17]; // Weekend / employee-specific holiday absent
        }

        // ── Half-day combinations (employee has NO punches on this day) ──
        if ($leaveApplicationDetails) {
            $hasFirstHalfLeave  = $leaveApplicationDetails->where('first_second_half', 4)->contains('leave_date', $date);
            $hasSecondHalfLeave = $leaveApplicationDetails->where('first_second_half', 6)->contains('leave_date', $date);

            $firstLeaveRow  = $leaveApplicationDetails->where('first_second_half', 4)->where('leave_date', $date)->first();
            $secondLeaveRow = $leaveApplicationDetails->where('first_second_half', 6)->where('leave_date', $date)->first();

            // Case 1: First-half leave (4) + second-half absent (5)
            if ($hasFirstHalfLeave && !$hasSecondHalfLeave) {
                $this->leave_application_id = $firstLeaveRow?->leave_application_id;
                return [4, 5];
            }

            // Case 2: Second-half leave (6) + first-half absent (3)
            if (!$hasFirstHalfLeave && $hasSecondHalfLeave) {
                $this->leave_application_id = $secondLeaveRow?->leave_application_id;
                return [3, 6];
            }

            // Case 3: Both halves approved leave (should be rare, 8 covers most, but safe-guard)
            if ($hasFirstHalfLeave && $hasSecondHalfLeave) {
                $this->leave_application_id = $firstLeaveRow?->leave_application_id ?? $secondLeaveRow?->leave_application_id;
                return [4, 6];
            }
        }

        return [0]; // Absent
    }

    /*
    * handleOvernightCheckoutForNormalShiftManualOrCurrection
    * Manual/corrected entry whose checkout crosses into the next calendar day.
    * Single-day shift (is_overnight=0)  -> status 13 (Over Night Duty) + night allowance.
    * Two-day/overnight shift (is_overnight=1) -> status 12 (Night Duty checkout), no
    * extra allowance — it's scheduled duty, not overtime (mirrors
    * PreviousDayOutPunchUpdateService's cron-flow behavior for the same distinction).
    */
    private function handleOvernightCheckoutForNormalShiftManualOrCurrection(
        object $row,
        string $date,
        ?string $outDate,
        ?object $shift,
        ?object $first,
        ?object $last,
        array &$statusesForLog,
        array &$result,
        string $now
    ): bool {
        if (
            ($this->isManual == 0 && $this->isCorrected == 0) ||
            !$first ||
            !$last ||
            !$outDate ||
            !$shift ||
            !$first->punch_datetime ||
            (strtotime($outDate . ' ' . $shift->start_check_in_time) <= strtotime($last->punch_datetime))
        ) {
            return false;
        }

        $isOvernightShift = (int) ($shift->is_overnight ?? 0) === 1;
        $carryOverStatus  = $isOvernightShift ? 12 : 13; // Night Duty (checkout) vs Over Night Duty

        Log::info('handleOvernightCheckoutForNormalShiftManualOrCurrection', [
            'is_overnight_shift' => $isOvernightShift,
            'carry_over_status'  => $carryOverStatus,
        ]);

        if (!in_array($carryOverStatus, $statusesForLog, true)) {
            $statusesForLog[] = $carryOverStatus;
        }

        if (!$isOvernightShift) {
            $result['payrollAccruedItems'][] = [
                'employee_user_id'       => $row->employee_user_id,
                'employee_attendance_id' => null,
                'amount'                 => ($row->gross_salary / date('t')) * 1,
                'type'                   => 6, // Night Duty Allowance
                'month'                  => date('m'),
                'year'                   => date('Y'),
                'date'                   => $date,
                'created_user_id'        => $this->systemUserId,
                'created_at'             => $now,
            ];
        }

        return true;
    }

    private function resolveOutDateTime(
        object $row, string $date, ?object $shift, ?object $first, ?object $last
    ): array {
        if (
            $first && $shift && $shift->is_overnight == 1 &&
            strtotime($date . ' ' . $shift->start_check_in_time) <= strtotime($first->punch_datetime)
        ) {
            return [null, null]; // Will be resolved on next day
        }

        $outDate = $last ? Carbon::parse($last->punch_datetime)->format('Y-m-d') : null;
        $outTime = $last ? Carbon::parse($last->punch_datetime)->format('H:i:s') : null;

        return [$outDate, $outTime];
    }

    private function calculateWorkingHours(?object $first, ?object $last, ?object $shift): float
    {
        if (!$first || !$last) {
            return 0;
        }
 
        // punch_datetime is always a string at this point (normalised in resolveFirstLastPunch)
        $firstCarbon = Carbon::parse((string) $first->punch_datetime);
        $lastCarbon  = Carbon::parse((string) $last->punch_datetime);
        $hours       = $firstCarbon->diffInHours($lastCarbon);
 
        if ($shift && $hours >= 4 && !empty($shift->lunch_meal_time)) {
            $parts = explode(':', $shift->lunch_meal_time . ':0');
            $h = (int) ($parts[0] ?? 0);
            $m = (int) ($parts[1] ?? 0);
            $hours -= ($h + ($m / 60));
        }
 
        return max(0, $hours);
    }

    private function applyPresentOrLateStatus(
        object $row, string $date, ?object $shift, ?object $first, ?object $last,
        string $shiftStart, int $grace, string $now, array &$statusesForLog
    ): void {
        if (!$first || !$shift) {
            return;
        }

        $punchTime   = strtotime($first->punch_datetime);
        if($this->isAbsentInFirstHalf){
            $secondHalfStart = $shift->second_half_day ?? date('H:i:s', strtotime($shift->first_half_day . ' + 1.5 hours'));
            $graceEnd    = strtotime($date . ' ' . $secondHalfStart . " +$grace minutes");
            $checkInStart = strtotime($date . ' ' . $shift->end);
        }else{
            $graceEnd    = strtotime($date . ' ' . $shiftStart . " +$grace minutes");
            $checkInStart = strtotime($date . ' ' . $shift->start_check_in_time);
        }

        //Log::warning('graceEnd:', ['grace'=>$grace,'shiftStart'=>$shiftStart,'date'=>$date,'graceEnd'=>$graceEnd, 'checkInStart'=>$checkInStart, 'punchTime'=>$punchTime]);

        if ($punchTime > 0) {
            $statusesForLog[] = 1; // Present
        }

        // In-punch missing (Incomplete In → status 10 is set by applyIncompleteInOutStatus,
        // which runs before this): $first is actually the out-punch, so there is no valid
        // check-in time to judge lateness. Keep Present, but skip Late + late deduction.
        if (in_array(10, $statusesForLog, true)) {
            Log::info('AttendanceProcessingService skipped Late: Incomplete In (status 10), keep Present', [
                'employee_user_id' => $row->employee_user_id ?? null,
                'date' => $date,
                'first_punch' => $first->punch_datetime ?? null,
                'last_punch' => $last->punch_datetime ?? null,
                'statuses_for_log' => $statusesForLog,
            ]);
            return;
        }

        if ($punchTime > $graceEnd) {
            $statusesForLog[] = 2; //Late
            Log::info('AttendanceProcessingService marked Late', [
                'employee_user_id' => $row->employee_user_id ?? null,
                'date' => $date,
                'first_punch' => $first->punch_datetime ?? null,
                'grace_end' => date('Y-m-d H:i:s', $graceEnd),
            ]);
            $this->processLateDeduction($row, $date, $shift, $first, $last, $now);
        }
    }

    private function processLateDeduction(
        object $row, string $date, ?object $shift, object $first, ?object $last, string $now
    ): void {
        $lateDeductionPolicy = $row->hasLateDeductionPolicy;
        if (!$lateDeductionPolicy) {
            return;
        }
        /* Log::info('lateDeductionPolicy:', [
            'deduction_basis'=>$lateDeductionPolicy->deduction_basis, 
            'check_exist'=>empty($row->lateDays),
            'count'=>$row->lateDays->count(),
            'max_late' => $lateDeductionPolicy->max_late_days + 1,
            $lateDeductionPolicy->deduction_basis === 'Day',
            !empty($row->lateDays),
            $row->lateDays->count() % ($lateDeductionPolicy->max_late_days + 1)===0
        ]); */

        if (
            $lateDeductionPolicy->deduction_basis === 'Day' &&
            (!empty($row->lateDays) && ($row->lateDays->count() % ($lateDeductionPolicy->max_late_days + 1)===0))
        ) {
            $employee_user_id = $row->employee_user_id;
            /* Log::info('lateDeductionPolicy:', [
                'deduction_basis'=>$lateDeductionPolicy->deduction_basis, 
                'check_exist'=>empty($row->lateDays),
                'count'=>$row->lateDays->count(),
                'max_late' => $lateDeductionPolicy->max_late_days + 1
            ]); */
            //Log::info('processLateDeduction:', [$row, $date, $shift, $first, $now]);
            // Purge existing month records and rebuild from scratch
            $recordIds = LateAttendanceRecord::where('employee_user_id', $row->employee_user_id)
                ->where('month', date('m', strtotime($date)))
                ->where('year', date('Y', strtotime($date)))
                ->pluck('id');

            if ($recordIds->isNotEmpty()) {
                LateAttendanceRecordDetail::whereIn('late_attendance_record_id', $recordIds)->delete();
                LateAttendanceRecord::whereIn('id', $recordIds)->delete();
            }

            

            $lateCount = !empty($row->lateDays) ? $row->lateDays->count() : 0;
            $cycle     = $lateDeductionPolicy->max_late_days + 1;
            $rowsToInsert = intdiv($lateCount, $cycle);

            for ($i = 0; $i < $rowsToInsert; $i++) {
                $lateRecordArr = [
                    'employee_user_id' => $employee_user_id,
                    'month'            => date('m', strtotime($date)),
                    'year'             => date('Y', strtotime($date)),
                    'created_user_id'  => $this->systemUserId,
                    'created_at'       => $now,
                    'shift_id'         => $shift->id,
                    'late_deduction_policy_id' => $lateDeductionPolicy->id,
                    'late_days'        => 5,
                    'late_minutes'     => 0,
                ];
                $lateRecord   = LateAttendanceRecord::create($lateRecordArr);
                $totalSeconds = 0;

                Log::info("loop_".$i, ['employee_user_id'=>$row->employee_user_id, 'lateRecordArr'=>$lateRecordArr, 'lateRecord'=>$lateRecord]);

                for ($j = 0; $j < $cycle && ($i * $cycle + $j) < $lateCount; $j++) {
                    $lateDay = $row->lateDays->sortBy('attendance_date')->values()[$i * $cycle + $j] ?? null;
                    if (!$lateDay) {
                        continue;
                    }

                    $shiftCheckinTime = Carbon::parse($date . ' ' . ($shift->check_in ?? '09:00:00'));
                    $lateSeconds      = Carbon::parse($first->punch_datetime)->diffInSeconds($shiftCheckinTime);
                    $totalSeconds    += $lateSeconds;

                    LateAttendanceRecordDetail::create([
                        'late_attendance_record_id' => $lateRecord->id,
                        'date'                      => $lateDay->attendance_date,
                        'late_seconds'              => $lateSeconds,
                        'shift_id'                  => $shift->id,
                        'in_time'                   => $first->punch_datetime,
                        'out_time'                  => $last->punch_datetime ?? null,
                        'created_at'                => $now,
                        'year'                      => date('Y', strtotime($date)),
                        'month'                     => date('m', strtotime($date)),

                    ]);  
                }

                LateAttendanceRecord::where('id', $lateRecord->id)
                    ->update(['late_minutes' => $totalSeconds / 60]);
            }
        }
    }

    private function applyHolidayDutyStatuses(
        object $row, string $date,
        ?object $publicHoliday, ?object $empHoliday,
        Collection $holidayDutyRequisitions,
        array &$statusesForLog, array &$result,
        string $now, ?object $first
    ): void {
        $anyHoliday = $publicHoliday || $empHoliday;
        if(!$anyHoliday){
            return;
        }
        Log::warning('anyHoliday: = >'.$anyHoliday.'<');
        if ($anyHoliday && $first && $row->employeeAttendanceTemps->count() > 0) {
            $statusesForLog[] = $empHoliday ? 20 : 21; // Weekend Duty / Public Holiday Duty
            $statusesForLog[] = 14;                     // Holiday Duty
        }

        $dutyOnDate = $holidayDutyRequisitions->where('duty_date', $date);

        Log::warning('Holiday Duty:', ['dutyOnDate'=>$dutyOnDate]);

        $hasApprovedRequisition = optional($dutyOnDate)
            ->where('employee_user_id', $row->employee_user_id)
            ->where('duty_date', $date)
            ->isNotEmpty() ?? false;

        if ($anyHoliday && $dutyOnDate->isEmpty() && !$hasApprovedRequisition ) {
            $statusesForLog[] = 19; // Unauthorized
            return;
        }

        $balanceStat = EmployeeLeaveBalance::where('employee_user_id', $row->employee_user_id)
            ->where('leave_head_id', 5)
            ->where('fiscal_year', date('Y'))
            ->first();

        // Grant compensatory leave
        $employeeLeaveBalance = EmployeeLeaveBalance::where('employee_user_id', $row->employee_user_id)
            ->where('leave_head_id', 5)
            ->where('fiscal_year', date('Y'))->first();

        if(!$employeeLeaveBalance){
            EmployeeLeaveBalance::create([
                'employee_user_id'=>$row->employee_user_id,
                'leave_head_id'=>5,
                'leave_policy_id'=>4,
                'fiscal_year'=> date('Y'),
                'created_user_id'=> $this->systemUserId,
                'achived_this_year'=>1,
                'current_balance'=>1
            ]);
        }else{
            $employeeLeaveBalance->achived_this_year = 1;
            $employeeLeaveBalance->current_balance = 1;
            $employeeLeaveBalance->save();
        }

        $balanceStat = $employeeLeaveBalance;

        $validityDays   = optional(
            $row->hasLeavePolicyDetail->where('leave_head_id', 5)->first()
        )->leave_avail_validity_days ?? 0;
        $leaveValidity  = date('Y-m-d', strtotime($date . " +$validityDays days"));

        $result['leaveAchieveLogs'][] = [
            'leave_head_id'             => 5,
            'validity_date'             => $leaveValidity,
            'employee_attendance_id'    => null, // filled after bulk insert
            'leave_count'               => 1,
            'leave_final_destination'   => 'Compensatory leave earned from Festival Holiday duty',
            'created_user_id'           => $this->systemUserId,
            'created_at'                => $now,
            'employee_user_id'          => $row->employee_user_id,
            'employee_leave_balance_id' => $balanceStat?->id,
        ];

        // Festival holiday cash incentive
        if ($publicHoliday && $publicHoliday->holiday_type_id == 10) {
            $result['payrollAccruedItems'][] = [
                'employee_user_id'       => $row->employee_user_id,
                'employee_attendance_id' => null,
                'amount'                 => ($row->gross_salary / date('t')) * ($row->employeeOtPolicy->multiplier ?? 1) * 2,
                'type'                   => 8, // Festival Duty Allowance
                'month'                  => date('m'),
                'year'                   => date('Y'),
                'date'                   => $date,
                'created_user_id'        => $this->systemUserId,
                'created_at'             => $now,
            ];
        }

        if ($balanceStat) {
            $statusesForLog[] = 18; // Compensation Leave Earned
        }
    }

    private function applyIncompleteInOutStatus(
        string $date, ?object $shift, ?object $first, ?object $last, array &$statusesForLog
    ): void {
        Log::info('AttendanceProcessingService IncompleteInOut check', [
            'date' => $date,
            'first' => $first->punch_datetime ?? null,
            'last' => $last->punch_datetime ?? null,
            'start_check_in_time' => $shift->start_check_in_time ?? null,
            'first_half_day' => $shift->first_half_day ?? null,
        ]);
        if (!$shift || !$first || $last) {
            return;
        }

        if (
            strtotime($first->punch_datetime) >= strtotime($date . ' ' . $shift->start_check_in_time) &&
            strtotime($first->punch_datetime) <= strtotime($date . ' ' . $shift->first_half_day)
        ) {
            $statusesForLog[] = 11; // Incomplete Out
            Log::info('AttendanceProcessingService marked Incomplete Out (11)', [
                'date' => $date,
                'first' => $first->punch_datetime ?? null,
            ]);
        } else {
            $statusesForLog[] = 10; // Incomplete In
            Log::info('AttendanceProcessingService marked Incomplete In (10)', [
                'date' => $date,
                'first' => $first->punch_datetime ?? null,
            ]);
        }
    }

    private function applyEarlyOutStatus(
        object $row, string $date, ?object $shift, ?object $last, string $shiftEnd, array &$statusesForLog
    ): void {
        if (!$last || !$shift) {
            return;
        }

        if (
            strtotime($last->punch_datetime) >= strtotime($date . ' ' . $shift->clock_out_start_time) &&
            strtotime($last->punch_datetime) <  strtotime($date . ' ' . $shiftEnd)
        ) {
            $statusesForLog[] = 7; // Early Out
            $approvedEarlyOut = $row->hasEarlyOutRequests->where('out_date', $date)->first();

            if($approvedEarlyOut){
                $statusesForLog[] = 23; // Early Out Authorized
            }
        }
    }

    /** Returns true if a first-half-day flag was set. */
    private function applyFirstHalfDayStatus(
        string $date, ?object $shift, ?object $first, ?object $last,
        ?Collection $leaveApplicationDetails, array &$statusesForLog
    ): bool {

        /* if (
            !$first || !$last || !$shift ||
            !(strtotime($first->punch_datetime) > strtotime($date . ' ' . $shift->end_check_in_time) && strtotime($first->punch_datetime) < strtotime($date . ' ' . $shift->first_half_day)) ||
            !(strtotime($last->punch_datetime) >= strtotime($date . ' ' . $shift->clock_out))
        ) */

            //Log::warning('Debug FirstHalfDayStatus:', ['punch_datetime1'=>$first->punch_datetime, 'punch_datetime2'=>$last->punch_datetime, 'end_check_in_time'=>$shift->end_check_in_time, 'second_half_day'=>$shift->second_half_day]);

        if (
            $first && $last && $shift &&
            (strtotime($first->punch_datetime) > strtotime($date . ' ' . $shift->end_check_in_time) && strtotime($first->punch_datetime) < strtotime($date . ' ' . $shift->first_half_day . ' +90 minutes')) &&
            (strtotime($last->punch_datetime) >= strtotime($date . ' ' . $shift->clock_out))
        ) {
            if ($leaveApplicationDetails && $leaveApplicationDetails->where('first_second_half', 4)->contains('leave_date', $date)) {
                $this->leave_application_id = $leaveApplicationDetails->where('first_second_half', 4)->where('leave_date', $date)->first()->leave_application_id;
                $statusesForLog[] = 4; // Half-day 1st (Approved)
            } else {
                $statusesForLog[] = 3; // Half-day 1st (Unapproved)
            }
            $this->isAbsentInFirstHalf = true;
            return true;
        }else{
            return false;
        }
    }

    private function applySecondHalfDayStatus(
        string $date, ?object $shift, ?object $first, ?object $last,
        ?Collection $leaveApplicationDetails, bool $has_halfday_leave, array &$statusesForLog
    ): void {
        //Log::info('Before Debug SecondHalfDayStatus:', ['date'=>$date, 'shift'=>$shift, 'first'=>$first, 'last'=>$last, 'has_halfday_leave'=>$has_halfday_leave, 'leaveApplicationDetails'=>$leaveApplicationDetails]);
        if (
            $has_halfday_leave || !$first || !$last || !$shift ||
            (strtotime($first->punch_datetime) > strtotime($date . ' ' . $shift->first_half_day . ' +90 minutes')) ||
            !(strtotime($last->punch_datetime)  < strtotime($date . ' ' . $shift->clock_out_start_time))
        ) {

            return;
        }
        //Log::info('After Debug SecondHalfDayStatus:', ['date'=>$date, 'shift'=>$shift, 'first'=>$first, 'last'=>$last, 'has_halfday_leave'=>$has_halfday_leave, 'leaveApplicationDetails'=>$leaveApplicationDetails]);

        if ($leaveApplicationDetails && $leaveApplicationDetails->where('first_second_half', 6)->contains('leave_date', $date)) {
            $this->leave_application_id = $leaveApplicationDetails->where('first_second_half', 6)->where('leave_date', $date)->first()->leave_application_id;
            $statusesForLog[] = 6; // Half-day 2nd (Approved)
        } else {
            $statusesForLog[] = 5; // Half-day 2nd (Unapproved)
        }
    }

    private function applyBothHalfDayAbsentStatus(
        string $date, ?object $shift, ?object $first, ?object $last,
        array &$statusesForLog
    ): void {
        //Log::warning('Debug BothHalfDayAbsentStatus:', ['date'=>$date, 'shift'=>$shift, 'first'=>$first, 'last'=>$last, 'leave_application_id'=>$this->leave_application_id]);
        if (!$first || !$last || !$shift) {
            return;
        }

        $condition1 = strtotime($first->punch_datetime) >= strtotime($date . ' ' . $shift->end_check_in_time)
            && $last
            && strtotime($last->punch_datetime) <= strtotime($date . ' ' . $shift->clock_out_start_time);

        $condition2 = strtotime($first->punch_datetime) > strtotime($date . ' ' . $shift->first_half_day)
            && $last
            && strtotime($last->punch_datetime) <= strtotime($date . ' ' . $shift->clock_out_start_time);

        $condition3 = strtotime($first->punch_datetime) >= strtotime($date . ' ' . $shift->start_check_in_time)
            && strtotime($first->punch_datetime) <= strtotime($date . ' ' . $shift->end_check_in_time)
            && $last
            && strtotime($last->punch_datetime) <= strtotime($date . ' ' . $shift->first_half_day);

        $condition4 = (strtotime($first->punch_datetime) > strtotime($date . ' ' . $shift->first_half_day) &&  strtotime($first->punch_datetime) < strtotime($date . ' ' . $shift->clock_out_start_time))
            && $last && strtotime($last->punch_datetime) <= strtotime($date . ' ' . $shift->clock_out . ' +30 minutes');

        if ($condition1 || $condition2 || $condition3 || $condition4) {
            $statusesForLog[] = 0;  // Absent
            $statusesForLog[] = 15; // Absent (2 half-days)
            //delete status from $statusesForLog[] if value 2 exist there
            $statusesForLog = array_values(array_diff($statusesForLog, [2,1]));
        }
    }

    // ─── Row builders ────────────────────────────────────────────────────────

    private function normalizeAttendanceTimes(
        string $date,
        ?string $inTime,
        ?string $outDate,
        ?string $outTime,
        array $statusesForLog
    ): array {
        if (in_array(10, $statusesForLog, true)) {
            $outTime = $inTime;
            $outDate = $outDate ?? $date;
            $inTime = null;
        }

        return [$inTime, $outDate, $outTime];
    }

    private function buildAttendanceRow(
        object $row, string $date, ?object $shift,
        ?string $inTime, ?string $outDate, ?string $outTime,
        bool $transferedToOTStatus,
        ?object $publicHoliday, ?object $empHoliday,
        int $isJoin, string $now, float $workingHours = 0
    ): array {
        Log::info('leave id: '.$this->leave_application_id);
        $rosterId = $row->hasRosterAssignment?->first()?->roster_id ?? null;
        return [
            'emp_code'                                   => $row->emp_code,
            'employee_user_id'                           => $row->employee_user_id,
            'department_id'                              => $row->department_id,
            'section_id'                                 => $row->section_id,
            'shift_id'                                   => $shift->id ?? null,
            'leave_id'                                   => $this->leave_application_id ?? null,
            'shift_start_time'                           => $shift->clock_in      ?? '09:00:00',
            'shift_grace_time'                           => $shift->shift_grace_time ?? 0,
            'shift_end_time'                             => $shift->clock_out     ?? '18:00:00',
            'date'                                       => $date,
            'in_time'                                    => $inTime,
            'out_date'                                   => $outDate,
            'out_time'                                   => $outTime,
            'on_leave_status'                            => null,
            'transfered_to_ot'                           => $transferedToOTStatus ? 1 : 0,
            'is_holiday'                                 => ($publicHoliday || $empHoliday) ? 1 : 0,
            'is_join'                                    => $isJoin,
            'is_manual'                                  => $this->isManual,
            'is_roster'                                  => $rosterId ? 1 : 0,
            'roster_id'                                  => $rosterId,
            'absent_bridge'                              => 0,
            'source'                                     => $this->source,
            'is_corrected'                               => $this->isCorrected,
            'working_hours'                              => $workingHours,
            'employee_applied_attendance_correction_id'  => null,
            'created_user_id'                            => $this->systemUserId,
            'updated_user_id'                            => $this->systemUserId,
            'deleted_user_id'                            => null,
            'created_at'                                 => $now,
            'remarks'                                    => $this->remarks,
        ];   
    }

    private function buildStatusLogRows(
        int $employeeUserId,
        string $date, string $now, array $statuses
    ): array {
        $rows = [];
        foreach ($statuses as $status) {
            $rows[] = [
                'employee_user_id'       => $employeeUserId,
                'employee_attendance_id' => null, // filled by the job after bulk insert
                'leave_application_id'   => $this->leave_application_id ?? null,
                'attendance_status'      => $status,
                'attendance_date'        => $date,
                'created_user_id'        => $this->systemUserId,
                'created_at'             => $now,
            ];
        }
        return $rows;
    }

    private function makeRowKey(
        string $empCode, int $employeeUserId, string $date,
        ?string $inTime, ?string $outTime, string $now
    ): string {
        return $empCode . '|' . $employeeUserId . '|' . $date . '|' . ($inTime ?? '') . '|' . ($outTime ?? '') . '|' . $now;
    }
}
