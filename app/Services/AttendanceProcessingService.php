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

use function calculateOtHours;

class AttendanceProcessingService
{
    private int $systemUserId;

    public function __construct()
    {
        $this->systemUserId = (int) env('SYSTEM_USER_ID', 1);
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
        object    $shift,
        ?object    $publicHoliday,
        ?object    $empHoliday,
        ?Collection $holidayDutyRequisitions,
        ?object    $otRequisition,
        ?Collection $leaveApplicationDetails // keyed collection of LeaveApplicationDetail for employee
    ): array {
        $now = date('Y-m-d H:i:s');

        $result = [
            'attendance'          => null,
            'rowKey'              => '',
            'statusLogs'          => [],
            'payrollAccruedItems' => [],
            'leaveAchieveLogs'    => [],
            'skip'                => false,
        ];

        $statusesForLog     = [];
        $leave_application_id = null;
        $has_halfday_leave  = false;

        // ── Determine whether employee has an approved OT requisition on this date ──
        $hasOtRequisition = $otRequisition
            && $otRequisition->where('ot_date_from', '>=', $date)
                ->where('employee_user_id', $row->employee_user_id)
                ->where('ot_date_to', '<=', $date)
                ->exists();

        // ── Resolve first / last punches for this date ────────────────────────────
        [$first, $last] = $this->resolveFirstLastPunch($row, $date, $shift);

        // ── No punches at all: absent / leave / holiday ───────────────────────────
        if ($row->employeeAttendanceTemps->isEmpty()) {
            $statusesForLog = $this->resolveAbsentStatuses(
                $date, $leaveApplicationDetails, $publicHoliday, $empHoliday
            );

            $result['statusLogs'] = $this->buildStatusLogRows(
                $row->employee_user_id, $leave_application_id, $date, $now, $statusesForLog
            );
            // Still build a bare attendance row so the date is recorded
            $result['attendance'] = $this->buildAttendanceRow(
                $row, $date, $shift, null, null, null, false, $publicHoliday, $empHoliday, 0, $now
            );
            $result['rowKey'] = $this->makeRowKey($row->emp_code, $row->employee_user_id, $date, null, null, $now);

            return $result;
        }

        // ── Overnight checkout: update *previous* day's record and skip this date ─
        if ($this->handleOvernightCheckoutForNormalShift($row, $date, $shift, $first, $last, $now, $statusesForLog)) {
            $result['skip'] = true;
            return $result;
        }
        Log::warning('after overnight check', [$result]);

        // ── Overnight shift: resolve out-date / out-time or delegate to next day ──
        [$outDate, $outTime] = $this->resolveOutDateTime($row, $date, $shift, $first, $last);
        Log::warning('before overnight shift', [$result]);
        // ── Night shift cross-day checkout update ─────────────────────────────────
        if ($this->handleNightShiftCheckout($row, $date, $shift, $first, $last, $now, $result)) {
            $result['skip'] = true;
            return $result;
        }
        Log::warning('after overnight shift', [$result]);

        // ── Shift defaults ────────────────────────────────────────────────────────
        $shiftStart = $shift->clock_in      ?? '09:00:00';
        $shiftEnd   = $shift->clock_out     ?? '18:00:00';
        $graceParts = explode(':', $shift->shift_grace_time ?? '00:00:00');
        $grace = ($graceParts[0] * 60) + $graceParts[1];
        $workingHours = $this->calculateWorkingHours($first, $last, $shift);
        $inTime  = $first ? Carbon::parse($first->punch_datetime)->format('H:i:s') : null;
        $outTime = $last  ? Carbon::parse($last->punch_datetime)->format('H:i:s')  : null;
        $outDate = $last  ? Carbon::parse($last->punch_datetime)->format('Y-m-d')  : null;

        $rowKey = $this->makeRowKey($row->emp_code, $row->employee_user_id, $date, $inTime, $outTime, $now);
        // ── Attendance statuses ───────────────────────────────────────────────────
        $this->applyPresentOrLateStatus(
            $row, $date, $shift, $first, $shiftStart, $grace, $now, $statusesForLog
        );

        $this->applyHolidayDutyStatuses(
            $row, $date, $publicHoliday, $empHoliday, $holidayDutyRequisitions,
            $statusesForLog, $result, $now, $rowKey
        );

        $this->applyIncompleteInOutStatus($row, $date, $shift, $first, $statusesForLog);
        $this->applyEarlyOutStatus($date, $shift, $last, $shiftEnd, $statusesForLog);

        $has_halfday_leave = $this->applyFirstHalfDayStatus(
            $date, $shift, $first, $last, $leaveApplicationDetails, $statusesForLog
        );

        $this->applySecondHalfDayStatus(
            $date, $shift, $first, $last, $leaveApplicationDetails, $has_halfday_leave, $statusesForLog
        );

        $this->applyBothHalfDayAbsentStatus(
            $date, $shift, $first, $last, $leave_application_id, $statusesForLog
        );

        // ── OT calculation ────────────────────────────────────────────────────────
        $transferedToOTStatus = false;
        /* $transferedToOTStatus = ($first && $last)
            ? calculateOtHours($row, $otRequisition, $hasOtRequisition, $shift, $first, $last, $this->systemUserId)
            : false; */

        // ── Join date flag ────────────────────────────────────────────────────────
        $isJoin = ($date === $row->joining_date) ? 1 : 0;

        // ── Assemble result ───────────────────────────────────────────────────────
        $result['rowKey']    = $rowKey;
        $result['attendance'] = $this->buildAttendanceRow(
            $row, $date, $shift, $inTime, $outDate, $outTime,
            $transferedToOTStatus, $publicHoliday, $empHoliday, $isJoin, $now, $workingHours
        );
        $result['statusLogs'] = $this->buildStatusLogRows(
            $row->employee_user_id, $leave_application_id, $date, $now, $statusesForLog
        );

        return $result;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Private helpers
    // ═══════════════════════════════════════════════════════════════════════════

    private function resolveFirstLastPunch(object $row, string $date, object $shift): array
    {
        if ($row->employeeAttendanceTemps->isEmpty()) {
            return [null, null];
        }

        $temps = $row->employeeAttendanceTemps->unique('punch_datetime');

        $forDate = $temps->filter(fn($t) => Carbon::parse($t->punch_datetime)->isSameDay($date));

        Log::warning('test', ['forDate'=>$forDate]);


        $first = $forDate->sortBy('punch_datetime')->first();
        $last  = $forDate->sortByDesc('punch_datetime')->first();

        Log::warning('test', [$first, $last, $first->punch_datetime, $last->punch_datetime]);

        if ($first && $first->punch_datetime instanceof Carbon) {
            $first->punch_datetime = $first->punch_datetime->toDateTimeString();
        }
        if ($last && $last->punch_datetime instanceof Carbon) {
            $last->punch_datetime = $last->punch_datetime->toDateTimeString();
        }

        // Single punch: treat out as unknown
        if ($first && $last && $first->punch_datetime->eq($last->punch_datetime)) {
            $last = null; 
        }

        Log::warning('test', [$first, $last]);

        return [$first, $last];
    }

    private function resolveAbsentStatuses(
        string      $date,
        ?Collection $leaveApplicationDetails,
        ?object     $publicHoliday,
        ?object     $empHoliday
    ): array {
        if ($leaveApplicationDetails && $leaveApplicationDetails->where('first_second_half', 8)->contains('leave_date', $date)) {
            return [8]; // Full-day approved leave
        }

        if ($publicHoliday) {
            return [9]; // Public Holiday Absent
        }

        if ($empHoliday) {
            return [17]; // Weekend / employee-specific holiday absent
        }

        return [0]; // Absent
    }

    /**
     * Handle the edge case where an overnight punch from a *normal* shift
     * belongs to the previous day. Updates the previous day's record and
     * returns true to signal "skip current date".
     */
    private function handleOvernightCheckoutForNormalShift(
        object $row, string $date, ?object $shift,
        ?object $first, ?object $last, string $now, array &$statusesForLog
    ): bool {
        Log::warning('test before:', ['first'=>$first, '$shift'=>$shift, 'str'=>strtotime($date . ' ' . $shift->start_check_in_time) . '<=' . strtotime($first->punch_datetime)]);
        if (
            !$first || !$shift || $shift->is_overnight != 0 || !$first->punch_datetime ||
            strtotime($date . ' ' . $shift->start_check_in_time) <= strtotime($first->punch_datetime)
        ) {
            return false;
        }

        Log::warning('test after:', ['first'=>$first, '$shift'=>$shift, 'str'=>strtotime($date . ' ' . $shift->start_check_in_time) <= strtotime($first->punch_datetime)]);

        $prevAttendance = EmployeeAttendance::where('employee_user_id', $row->employee_user_id)
            ->where('date', date('Y-m-d', strtotime($date . ' -1 day')))
            ->whereNotNull('in_time')
            ->whereNull('out_time')
            ->whereNull('out_date')
            ->first();

        if ($prevAttendance) {
            $prevAttendance->update([
                'out_time' => date('H:i:s', strtotime($first->punch_datetime?? $last->punch_datetime)),
                'out_date' => $date,
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
            $amount = ($row->gross_salary / date('t')) * 1;
            PayrollAccruedAllowanceIncome::insert(
                [
                'employee_user_id'       => $row->employee_user_id,
                'employee_attendance_id' => $prevAttendance->id,
                'amount'                 => $amount,
                'type'                   => 6, // Night Duty Allowance
                'month'                  => date('m'),
                'year'                   => date('Y'),
                'date'                   => $date,
                'created_user_id'        => $this->systemUserId,
                'created_at'             => $now,
                ]
            );
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

    /**
     * For overnight shifts: if the last punch belongs to the next day checkout window,
     * update previous day's record, generate allowance, and signal skip.
     */
    private function handleNightShiftCheckout(
        object $row, string $date, ?object $shift, ?object $first,
        ?object $last, string $now, array &$result
    ): bool {
        Log::warning('Before overnight duty check:', [$row, $date, $shift, $last]);
        $last = $last ?? $first;
        if (
            !$shift || $shift->is_overnight != 1 || !$last ||
            !(
                strtotime($last->punch_datetime) >= strtotime($date . ' ' . $shift->clock_out . ' -4 hours') ||
                strtotime($last->punch_datetime) >= strtotime($date . ' ' . $shift->clock_out)
            )
        ) {
            return false;
        }
        Log::warning('after overnight duty check:', [$row, $date, $shift, $last]);

        $prevAttendance = EmployeeAttendance::where('employee_user_id', $row->employee_user_id)
            ->where('date', date('Y-m-d', strtotime($date . ' -1 day')))
            ->whereNotNull('in_time')
            ->first();

        Log::warning('Previous att:', [$prevAttendance]);

        if ($prevAttendance) {
            $prevAttendance->update([
                'out_time' => date('H:i:s', strtotime($last->punch_datetime)),
                'out_date' => $date,
            ]);

            EmployeeAttendanceStatusLog::insert([
                'employee_user_id'       => $row->employee_user_id,
                'employee_attendance_id' => $prevAttendance->id,
                'leave_application_id'   => null,
                'attendance_status'      => 13, // Night Duty confirmed
                'attendance_date'        => $date,
                'created_user_id'        => $this->systemUserId,
                'created_at'             => $now,
            ]);
        }

        return true;
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
        object $row, string $date, ?object $shift, ?object $first,
        string $shiftStart, int $grace, string $now, array &$statusesForLog
    ): void {
        if (!$first || !$shift) {
            return;
        }

        $punchTime   = strtotime($first->punch_datetime);
        $graceEnd    = strtotime($date . ' ' . $shiftStart . " +$grace minutes");
        $checkInStart = strtotime($date . ' ' . $shift->start_check_in_time);

        if ($punchTime <= $graceEnd && $punchTime >= $checkInStart) {
            $statusesForLog[] = 1; // Present
            return;
        }

        if ($punchTime > $graceEnd) {
            $statusesForLog[] = 2; // Late
            $this->processLateDeduction($row, $date, $shift, $first, $now);
        }
    }

    private function processLateDeduction(
        object $row, string $date, ?object $shift, object $first, string $now
    ): void {
        $lateDeductionPolicy = $row->hasLateDeductionPolicy;
        if (!$lateDeductionPolicy) {
            return;
        }

        if (
            $lateDeductionPolicy->deduction_basis === 'Day' &&
            (!empty($row->lateDays) && $row->lateDays->count() % ($lateDeductionPolicy->max_late_days + 1)) === 0
        ) {
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
                $lateRecord   = LateAttendanceRecord::create([
                    'employee_user_id' => $row->employee_user_id,
                    'month'            => date('m', strtotime($date)),
                    'year'             => date('Y', strtotime($date)),
                    'created_user_id'  => $this->systemUserId,
                    'created_at'       => $now,
                ]);
                $totalSeconds = 0;

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
        string $now, string $rowKey
    ): void {
        $anyHoliday = $publicHoliday || $empHoliday;

        if ($anyHoliday && $row->employeeAttendanceTemps->count() > 0) {
            $statusesForLog[] = $empHoliday ? 20 : 21; // Weekend Duty / Public Holiday Duty
            $statusesForLog[] = 14;                     // Holiday Duty
        }

        $dutyOnDate = $holidayDutyRequisitions->where('duty_date', $date);

        Log::warning('Holiday Duty:', ['dutyOnDate'=>$dutyOnDate]);

        $hasApprovedRequisition = optional($dutyOnDate)
            ->where('employee_user_id', $row->employee_user_id)
            ->where('duty_date', $date)
            ->isNotEmpty() ?? false;

        if ($dutyOnDate->isEmpty() && !$hasApprovedRequisition ) {
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
        object $row, string $date, ?object $shift, ?object $first, array &$statusesForLog
    ): void {
        if ($row->employeeAttendanceTemps->count() !== 1 || !$shift || !$first) {
            return;
        }

        if (
            strtotime($first->punch_datetime) >= strtotime($date . ' ' . $shift->start_check_in_time) &&
            strtotime($first->punch_datetime) <= strtotime($date . ' ' . $shift->first_half_day)
        ) {
            $statusesForLog[] = 11; // Incomplete Out
        } else {
            $statusesForLog[] = 10; // Incomplete In
        }
    }

    private function applyEarlyOutStatus(
        string $date, ?object $shift, ?object $last, string $shiftEnd, array &$statusesForLog
    ): void {
        if (!$last || !$shift) {
            return;
        }

        if (
            strtotime($last->punch_datetime) >= strtotime($date . ' ' . $shift->clock_out_start_time) &&
            strtotime($last->punch_datetime) <  strtotime($date . ' ' . $shiftEnd)
        ) {
            $statusesForLog[] = 7; // Early Out
        }
    }

    /** Returns true if a first-half-day flag was set. */
    private function applyFirstHalfDayStatus(
        string $date, ?object $shift, ?object $first, ?object $last,
        ?Collection $leaveApplicationDetails, array &$statusesForLog
    ): bool {
        if (
            !$first || !$last || !$shift ||
            !(strtotime($first->punch_datetime) > strtotime($date . ' ' . $shift->end_check_in_time) &&
              strtotime($first->punch_datetime) < strtotime($date . ' ' . $shift->first_half_day)) ||
            !(strtotime($last->punch_datetime) >= strtotime($date . ' ' . $shift->clock_out))
        ) {
            return false;
        }

        if ($leaveApplicationDetails && $leaveApplicationDetails->where('first_second_half', 4)->contains('leave_date', $date)) {
            $statusesForLog[] = 4; // Half-day 1st (Approved)
        } else {
            $statusesForLog[] = 3; // Half-day 1st (Unapproved)
        }

        return true;
    }

    private function applySecondHalfDayStatus(
        string $date, ?object $shift, ?object $first, ?object $last,
        ?Collection $leaveApplicationDetails, bool $has_halfday_leave, array &$statusesForLog
    ): void {
        if (
            $has_halfday_leave || !$first || !$last || !$shift ||
            !(strtotime($first->punch_datetime) > strtotime($date . ' ' . $shift->first_half_day)) ||
            !(strtotime($last->punch_datetime)  < strtotime($date . ' ' . $shift->clock_out_start_time))
        ) {
            return;
        }

        if ($leaveApplicationDetails && $leaveApplicationDetails->where('first_second_half', 6)->contains('leave_date', $date)) {
            $statusesForLog[] = 6; // Half-day 2nd (Approved)
        } else {
            $statusesForLog[] = 5; // Half-day 2nd (Unapproved)
        }
    }

    private function applyBothHalfDayAbsentStatus(
        string $date, ?object $shift, ?object $first, ?object $last,
        mixed $leave_application_id, array &$statusesForLog
    ): void {
        if (!$first || !$last || !$shift || $leave_application_id !== null) {
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

        if ($condition1 || $condition2 || $condition3) {
            $statusesForLog[] = 0;  // Absent
            $statusesForLog[] = 15; // Absent (2 half-days)
        }
    }

    // ─── Row builders ────────────────────────────────────────────────────────

    private function buildAttendanceRow(
        object $row, string $date, ?object $shift,
        ?string $inTime, ?string $outDate, ?string $outTime,
        bool $transferedToOTStatus,
        ?object $publicHoliday, ?object $empHoliday,
        int $isJoin, string $now, float $workingHours = 0
    ): array {
        return [
            'emp_code'                                   => $row->emp_code,
            'employee_user_id'                           => $row->employee_user_id,
            'department_id'                              => $row->department_id,
            'section_id'                                 => $row->section_id,
            'shift_id'                                   => $row->shift_id,
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
            'is_manual'                                  => 0,
            'absent_bridge'                              => 0,
            'source'                                     => 'biometric',
            'is_corrected'                               => 0,
            'working_hours'                              => $workingHours,
            'employee_applied_attendance_correction_id'  => null,
            'created_user_id'                            => $this->systemUserId,
            'updated_user_id'                            => $this->systemUserId,
            'deleted_user_id'                            => null,
            'created_at'                                 => $now,
        ];
    }

    private function buildStatusLogRows(
        int $employeeUserId, mixed $leave_application_id,
        string $date, string $now, array $statuses
    ): array {
        $rows = [];
        foreach ($statuses as $status) {
            $rows[] = [
                'employee_user_id'       => $employeeUserId,
                'employee_attendance_id' => null, // filled by the job after bulk insert
                'leave_application_id'   => $leave_application_id,
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