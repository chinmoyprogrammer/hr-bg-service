<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\EmployeeOfficialInformation;
use App\Models\Shift;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\Holiday;
use App\Models\EmployeeOtRequisition;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeAttendanceStatusLog;
use App\Models\LateAttendanceRecord;
use App\Models\LateAttendanceRecordDetail;
use App\Models\HolidayDutyRequisition;
use App\Models\EmployeeLeaveAchieveLog;
use App\Models\EmployeeLeaveBalance;
use App\Models\PayrollAccruedAllowanceIncome;
use App\Models\LeaveApplicationDetail;
use App\DTOs\AttendanceDTO;
use Illuminate\Support\Facades\Log;

class AttendanceProcessingService
{
    // ─────────────────────────────────────────────
    // Resolved data (populated during process())
    // ─────────────────────────────────────────────
    private EmployeeOfficialInformation $employee;
    private Shift $shift;
    private string $now;
    private int $systemUserId;

    // Shift time fields (resolved once, reused)
    private string $shiftStart;
    private string $shiftEnd;
    private int    $grace;          // in minutes
    private string $startCheckIn;
    private string $endCheckIn;
    private string $firstHalfDay;
    private string $clockOutStart;

    // ─────────────────────────────────────────────
    // Entry point
    // ─────────────────────────────────────────────

    public function process(AttendanceDTO $dto): array
    {
        Log::warning('test from service:', [
            'payload' => $dto,
        ]);
        $this->systemUserId = 1;
        $this->now          = Carbon::now()->toDateTimeString();

        $this->resolveEmployee($dto);
        $this->resolveShift($dto);
        $this->resolveShiftTimes();

        $rosterId        = $this->resolveRoster($dto);
        $holidayData     = $this->resolveHoliday($dto);
        $otRequisition   = $this->resolveOtRequisition($dto);

        $statuses        = $this->determineStatuses($dto, $holidayData);
        $lateResult      = $this->handleLateDeduction($dto, $statuses);
        $holidayComp     = $this->handleHolidayCompensation($dto, $holidayData);
        $otResult        = $this->calculateOvertime($dto, $otRequisition);

        $attendance      = $this->persistAttendance($dto, $statuses, $rosterId, $holidayData, $otResult);
        $this->persistStatusLogs($attendance->id, $dto, $statuses);
        $this->linkLeaveAchieveLog($attendance->id, $holidayComp['compensation_leave_earned']);

        return [
            'attendance_id'             => $attendance->id,
            'statuses'                  => $statuses,
            'is_late'                   => in_array(2, $statuses),
            'late_deduction_triggered'  => $lateResult['triggered'],
            'holiday_duty'              => $holidayData['is_holiday'] && ($dto->inTime !== null && $dto->outTime !== null),
            'compensation_leave_earned' => $holidayComp['compensation_leave_earned'],
            'cash_award'                => $holidayComp['cash_award'],
            'overtime_hours'            => $otResult['overtime_hours'],
            'transfered_to_ot'          => $otResult['transfered_to_ot'],
        ];
    }

    // ─────────────────────────────────────────────
    // Step 1 — Employee
    // ─────────────────────────────────────────────

    private function resolveEmployee(AttendanceDTO $dto): void
    {
        $date           = $dto->date;
        $startBoundary  = date('Y-m-01', strtotime($date));
        $endBoundary    = date('Y-m-t',  strtotime($date));

        $employee = EmployeeOfficialInformation::with([
            'hasLateDeductionPolicy' => fn($q) => $q
                ->where('effective_date', '<=', $date)
                ->where('status', 1),

            'lateDays' => fn($q) => $q
                ->whereBetween('attendance_date', [
                    date('Y-m-01', strtotime($startBoundary)),
                    date('Y-m-t',  strtotime($endBoundary)),
                ])
                ->where('attendance_status', 2),

            'employeeOtPolicy' => fn($q) => $q
                ->where('effective_date', '<=', $date)
                ->where('status', 1),

            'hasLeavePolicyDetail',
        ])
        ->where('employee_user_id', $dto->employeeId)
        ->first();

        if (!$employee) {
            throw new \Exception("Employee not found: {$dto->employeeId}");
        }

        $this->employee = $employee;
    }

    // ─────────────────────────────────────────────
    // Step 2 — Shift
    // ─────────────────────────────────────────────

    private function resolveShift(AttendanceDTO $dto): void
    {
        $shift = null;

        if ($this->employee->shift_id) {
            $shift = Shift::where('id', $this->employee->shift_id)
                ->where('effective_date', '<=', $dto->date)
                ->orderBy('effective_date', 'desc')
                ->first();
        }

        if (!$shift) {
            throw new \Exception("No valid shift for employee {$dto->employeeId} on {$dto->date}");
        }

        $this->shift = $shift;
    }

    // ─────────────────────────────────────────────
    // Shift time helpers (computed once)
    // ─────────────────────────────────────────────

    private function resolveShiftTimes(): void
    {
        $shift = $this->shift;

        $this->shiftStart    = $shift->clock_in             ?? '09:00:00';
        $this->shiftEnd      = $shift->clock_out            ?? '18:00:00';
        $this->startCheckIn  = $shift->start_check_in_time  ?? $this->shiftStart;
        $this->endCheckIn    = $shift->end_check_in_time    ?? $this->shiftStart;
        $this->firstHalfDay  = $shift->first_half_day       ?? $this->shiftStart;
        $this->clockOutStart = $shift->clock_out_start_time ?? $this->shiftEnd;

        $graceParts  = explode(':', $shift->shift_grace_time ?? '00:00:00');
        $this->grace = ((int)$graceParts[0] * 60) + (int)$graceParts[1];
    }

    // ─────────────────────────────────────────────
    // Step 3 — Roster
    // ─────────────────────────────────────────────

    private function resolveRoster(AttendanceDTO $dto): ?int
    {
        $date       = $dto->date;
        $employeeId = $dto->employeeId;

        $assignment = RosterAssignment::where('employee_user_id', $employeeId)
            ->where('from_date', '<=', $date)
            ->where(fn($q) => $q->whereNull('to_date')->orWhere('to_date', '>=', $date))
            ->whereNull('deleted_by')
            ->first();

        if ($assignment) {
            return $assignment->roster_id;
        }

        // Fallback: effective roster from shift's roster catalog
        $rosterCatalog = Roster::where('shift_id', $this->employee->shift_id)
            ->orderBy('effective_from', 'desc')
            ->get();

        $effectiveRoster = $rosterCatalog->first(
            fn($roster) => $roster->effective_from <= $date
                && (is_null($roster->effective_to) || $roster->effective_to >= $date)
        );

        return $effectiveRoster?->id;
    }

    // ─────────────────────────────────────────────
    // Step 4 — Holiday
    // ─────────────────────────────────────────────

    private function resolveHoliday(AttendanceDTO $dto): array
    {
        $date       = $dto->date;
        $employeeId = $dto->employeeId;

        $publicHoliday   = Holiday::where('date', $date)->whereNull('employee_user_id')->first();
        $employeeHoliday = Holiday::where('date', $date)->where('employee_user_id', $employeeId)->first();

        return [
            'public_holiday'   => $publicHoliday,
            'employee_holiday' => $employeeHoliday,
            'is_holiday'       => (bool)($publicHoliday || $employeeHoliday),
            'holiday_type'     => $publicHoliday ? 'public' : ($employeeHoliday ? 'employee' : null),
        ];
    }

    // ─────────────────────────────────────────────
    // Step 5 — OT Requisition
    // ─────────────────────────────────────────────

    private function resolveOtRequisition(AttendanceDTO $dto): ?EmployeeOtRequisition
    {
        return EmployeeOtRequisition::where('employee_user_id', $dto->employeeId)
            ->where('ot_date_from', '<=', $dto->date)
            ->where('ot_date_to',   '>=', $dto->date)
            ->first();
    }

    // ─────────────────────────────────────────────
    // Step 6 — Statuses
    // ─────────────────────────────────────────────

    private function determineStatuses(AttendanceDTO $dto, array $holidayData): array
    {
        $date       = $dto->date;
        $employeeId = $dto->employeeId;
        $inTime     = $dto->inTime;
        $outTime    = $dto->outTime;

        $hasIn         = $inTime  !== null;
        $hasOut        = $outTime !== null;
        $hasAttendance = $hasIn && $hasOut;

        $ts = fn($time) => $time ? strtotime("$date $time") : null;

        $inTs  = $ts($inTime);
        $outTs = $ts($outTime);

        $statuses = [];

        if ($hasAttendance) {
            $statuses = array_merge(
                $statuses,
                $this->resolvePresenceStatuses($date, $inTs, $outTs)
            );
        } else {
            $statuses = array_merge(
                $statuses,
                $this->resolveAbsenceStatuses($employeeId, $date, $holidayData)
            );
        }

        // Holiday duty statuses (only if attendance exists)
        if ($holidayData['is_holiday'] && $hasAttendance) {
            $statuses[] = 14; // Holiday Duty

            if ($holidayData['public_holiday'])   $statuses[] = 21; // Public Holiday Duty
            if ($holidayData['employee_holiday']) $statuses[] = 20; // Weekend Duty
        }

        return $statuses;
    }

    private function resolvePresenceStatuses(string $date, int $inTs, int $outTs): array
    {
        $statuses = [];

        $graceEnd        = strtotime("$date {$this->shiftStart} +{$this->grace} minutes");
        $startCheckInTs  = strtotime("$date {$this->startCheckIn}");
        $endCheckInTs    = strtotime("$date {$this->endCheckIn}");
        $shiftEndTs      = strtotime("$date {$this->shiftEnd}");
        $firstHalfDayTs  = strtotime("$date {$this->firstHalfDay}");
        $clockOutStartTs = strtotime("$date {$this->clockOutStart}");

        // Present or Late
        if ($inTs >= $startCheckInTs && $inTs <= $graceEnd) {
            $statuses[] = 1; // Present
        } else {
            $statuses[] = 2; // Present Late (PL)
        }

        // Early Out
        if ($outTs >= $clockOutStartTs && $outTs < $shiftEndTs) {
            $statuses[] = 7; // Early Out
        }

        // Half-day 1st (late in, but full out)
        if ($inTs > $endCheckInTs && $inTs < $firstHalfDayTs && $outTs >= $shiftEndTs) {
            $statuses[] = 3; // Half-day 1st UA
        }

        // Half-day 2nd (on time in, early out after first half)
        if ($inTs < $firstHalfDayTs && $outTs < $clockOutStartTs && $outTs > $firstHalfDayTs) {
            $statuses[] = 5; // Half-day 2nd UA
        }

        return $statuses;
    }

    private function resolveAbsenceStatuses(int $employeeId, string $date, array $holidayData): array
    {
        $statuses = [];

        $onLeave = LeaveApplicationDetail::where('employee_user_id', $employeeId)
            ->where('leave_date', $date)
            ->where('first_second_half', 3) // full day
            ->exists();

        if ($onLeave) {
            $statuses[] = 8; // On Leave
        } elseif ($holidayData['is_holiday']) {
            if ($holidayData['public_holiday'])   $statuses[] = 9;  // Public Holiday Absent
            if ($holidayData['employee_holiday']) $statuses[] = 16; // Weekend Absent
        } else {
            $statuses[] = 0; // Absent
        }

        return $statuses;
    }

    // ─────────────────────────────────────────────
    // Step 7 — Late Deduction
    // ─────────────────────────────────────────────

    private function handleLateDeduction(AttendanceDTO $dto, array $statuses): array
    {
        $result = ['triggered' => false];

        $latePolicy = $this->employee->hasLateDeductionPolicy;

        if (!in_array(2, $statuses) || !$latePolicy || $latePolicy->deduction_basis !== 'Day') {
            return $result;
        }

        $date           = $dto->date;
        $employeeId     = $dto->employeeId;
        $currentLateCount = $this->employee->lateDays->count();
        $maxLateDays    = $latePolicy->max_late_days ?? 0;
        $cycle          = $maxLateDays + 1;

        if (($currentLateCount + 1) % $cycle !== 0) {
            return $result;
        }

        // Delete existing late records for this month
        $recordIds = LateAttendanceRecord::where('employee_user_id', $employeeId)
            ->where('month', Carbon::parse($date)->month)
            ->where('year',  Carbon::parse($date)->year)
            ->pluck('id');

        if ($recordIds->isNotEmpty()) {
            LateAttendanceRecordDetail::whereIn('late_attendance_record_id', $recordIds)->delete();
            LateAttendanceRecord::whereIn('id', $recordIds)->delete();
        }

        // All late days this month (existing + today)
        $existingLateDays = EmployeeAttendanceStatusLog::where('employee_user_id', $employeeId)
            ->whereMonth('attendance_date', Carbon::parse($date)->month)
            ->whereYear('attendance_date',  Carbon::parse($date)->year)
            ->where('attendance_status', 2)
            ->pluck('attendance_date')
            ->unique()
            ->values()
            ->toArray();

        $allLateDays = array_unique(array_merge($existingLateDays, [$date]));
        $totalLateDays = count($allLateDays);
        $numCycles = (int)ceil($totalLateDays / $cycle);

        for ($i = 0; $i < $numCycles; $i++) {
            $header = LateAttendanceRecord::create([
                'employee_user_id' => $employeeId,
                'month'            => Carbon::parse($date)->month,
                'year'             => Carbon::parse($date)->year,
                'created_user_id'  => $this->systemUserId,
                'created_at'       => $this->now,
            ]);

            $start = $i * $cycle;
            $end   = min(($i + 1) * $cycle, $totalLateDays);

            for ($j = $start; $j < $end; $j++) {
                LateAttendanceRecordDetail::create([
                    'late_attendance_record_id' => $header->id,
                    'date'                      => $allLateDays[$j],
                    'late_hours'                => 0,
                    'created_user_id'           => $this->systemUserId,
                    'created_at'                => $this->now,
                ]);
            }
        }

        $result['triggered'] = true;
        return $result;
    }

    // ─────────────────────────────────────────────
    // Step 8 — Holiday Compensation
    // ─────────────────────────────────────────────

    private function handleHolidayCompensation(AttendanceDTO $dto, array $holidayData): array
    {
        $result = ['compensation_leave_earned' => false, 'cash_award' => 0.0];

        $hasAttendance = $dto->inTime !== null && $dto->outTime !== null;

        if (!$holidayData['is_holiday'] || !$hasAttendance) {
            return $result;
        }

        $date           = $dto->date;
        $employeeId     = $dto->employeeId;
        $publicHoliday  = $holidayData['public_holiday'];

        $approved = HolidayDutyRequisition::where('date_from', '<=', $date)
            ->where('date_to', '>=', $date)
            ->where('holiday_duty_requisitions.status', 'Approved')
            ->whereNotNull('holiday_duty_requisitions.approved_at')
            ->join('holiday_duty_requisition_details', function ($join) use ($employeeId) {
                $join->on('holiday_duty_requisitions.id', '=', 'holiday_duty_requisition_details.holiday_duty_requisition_id')
                    ->where('holiday_duty_requisition_details.employee_user_id', $employeeId)
                    ->where('holiday_duty_requisition_details.status', 'Approved')
                    ->whereNotNull('holiday_duty_requisition_details.approved_at');
            })
            ->exists();

        if (!$approved) {
            return $result;
        }

        // Determine leave validity
        $leaveValidityDays = $this->employee->hasLeavePolicyDetail
            ->where('leave_head_id', 5)->first()?->leave_avail_validity_days;

        $validityDate = $leaveValidityDays
            ? Carbon::parse($date)->addDays($leaveValidityDays)->toDateString()
            : date('Y-12-31');

        // Log earned leave
        EmployeeLeaveAchieveLog::create([
            'leave_head_id'           => 5,
            'validity_date'           => $validityDate,
            'employee_attendance_id'  => null, // Linked after attendance created
            'leave_count'             => 1,
            'leave_final_destination' => 2,
            'created_user_id'         => $this->systemUserId,
            'created_at'              => $this->now,
        ]);

        // Update or create leave balance
        $leaveBalance = EmployeeLeaveBalance::where('employee_user_id', $employeeId)
            ->where('leave_head_id', 5)
            ->where('fiscal_year', Carbon::parse($date)->year)
            ->first();

        if ($leaveBalance) {
            $leaveBalance->increment('achived_this_year', 1);
        } else {
            EmployeeLeaveBalance::create([
                'employee_user_id'  => $employeeId,
                'leave_head_id'     => 5,
                'leave_policy_id'   => $this->employee->leave_policy_id,
                'achived_this_year' => 1,
                'current_balance'   => 1,
                'valid_until'       => $validityDate,
                'fiscal_year'       => Carbon::parse($date)->year,
                'created_user_id'   => $this->systemUserId,
                'created_at'        => $this->now,
            ]);
        }

        $result['compensation_leave_earned'] = true;

        // Festival Holiday cash award
        if ($publicHoliday && $publicHoliday->holiday_type_id == 10) {
            $grossSalary  = $this->employee->gross_salary ?? 0;
            $daysInMonth  = Carbon::parse($date)->daysInMonth;
            $cashAward    = $grossSalary / $daysInMonth;

            PayrollAccruedAllowanceIncome::create([
                'employee_user_id' => $employeeId,
                'amount'           => $cashAward,
                'type'             => 8,
                'month'            => Carbon::parse($date)->month,
                'year'             => Carbon::parse($date)->year,
                'date'             => $date,
                'created_user_id'  => $this->systemUserId,
                'created_at'       => $this->now,
            ]);

            $result['cash_award'] = $cashAward;
        }

        return $result;
    }

    // ─────────────────────────────────────────────
    // Step 9 — Overtime
    // ─────────────────────────────────────────────

    private function calculateOvertime(AttendanceDTO $dto, ?EmployeeOtRequisition $otRequisition): array
    {
        $result = ['overtime_hours' => 0.0, 'transfered_to_ot' => false];

        $hasAttendance = $dto->inTime !== null && $dto->outTime !== null;

        if (!$otRequisition || !$hasAttendance) {
            return $result;
        }

        // Only process if requisition is approved
        $approved = EmployeeOtRequisition::where('ot_date_from', '<=', $dto->date)
            ->where('ot_date_to', '>=', $dto->date)
            ->where('employee_user_id', $dto->employeeId)
            ->whereNotNull('approval_date')
            ->whereNull('rejection_date')
            ->exists();

        if (!$approved) {
            return $result;
        }

        $date  = $dto->date;
        $inTs  = strtotime("$date {$dto->inTime}");
        $outTs = strtotime("$date {$dto->outTime}");

        $workingHours = ($outTs - $inTs) / 3600;

        $lunchBreak = 0;
        if (!empty($this->shift->lunch_meal_hour)) {
            [$h, $m, $s] = array_map('intval', explode(':', $this->shift->lunch_meal_hour));
            $lunchBreak = $h + ($m / 60) + ($s / 3600);
        }

        $effectiveWorking  = $workingHours - $lunchBreak;
        $shiftWorkingHours = (strtotime("$date {$this->shiftEnd}") - strtotime("$date {$this->shiftStart}")) / 3600;
        $overtimeHours     = max(0, $effectiveWorking - $shiftWorkingHours);

        $result['overtime_hours']  = $overtimeHours;
        $result['transfered_to_ot'] = $overtimeHours > 0;

        return $result;
    }

    // ─────────────────────────────────────────────
    // Step 10 & 11 — Persist Attendance
    // ─────────────────────────────────────────────

    private function persistAttendance(
        AttendanceDTO $dto,
        array $statuses,
        ?int $rosterId,
        array $holidayData,
        array $otResult
    ): EmployeeAttendance {
        $date       = $dto->date;
        $employeeId = $dto->employeeId;
        $inTime     = $dto->inTime;
        $outTime    = $dto->outTime;

        $hasAttendance = $inTime !== null && $outTime !== null;

        $inTs  = $hasAttendance ? strtotime("$date $inTime")  : null;
        $outTs = $hasAttendance ? strtotime("$date $outTime") : null;

        // Delete existing record for this date to avoid duplicates
        EmployeeAttendance::where('employee_user_id', $employeeId)
            ->where('date', $date)
            ->delete();

        return EmployeeAttendance::create([
            'emp_code'                                  => $this->employee->emp_code,
            'employee_user_id'                          => $employeeId,
            'department_id'                             => $this->employee->department_id,
            'section_id'                                => $this->employee->section_id,
            'shift_id'                                  => $this->employee->shift_id,
            'shift_start_time'                          => $this->shiftStart,
            'shift_grace_time'                          => $this->grace,
            'shift_end_time'                            => $this->shiftEnd,
            'date'                                      => $date,
            'in_time'                                   => $inTime,
            'out_date'                                  => $date,
            'out_time'                                  => $outTime,
            'on_leave_status'                           => null,
            'transfered_to_ot'                          => $otResult['transfered_to_ot'] ? 1 : 0,
            'is_holiday'                                => $holidayData['is_holiday'] ? 1 : 0,
            'is_join'                                   => ($date == $this->employee->joining_date) ? 1 : 0,
            'is_manual'                                 => $dto->source === 'manual' ? 1 : 0,
            'is_roster'                                 => $rosterId ? 1 : 0,
            'roster_id'                                 => $rosterId,
            'absent_bridge'                             => 0,
            'source'                                    => $dto->source,
            'is_corrected'                              => 0,
            'working_hours'                             => $hasAttendance ? (($outTs - $inTs) / 3600) : 0,
            'employee_applied_attendance_correction_id' => null,
            'created_user_id'                           => $this->systemUserId,
            'updated_user_id'                           => $this->systemUserId,
            'created_at'                                => $this->now,
        ]);
    }

    // ─────────────────────────────────────────────
    // Step 12 — Status Logs
    // ─────────────────────────────────────────────

    private function persistStatusLogs(int $attendanceId, AttendanceDTO $dto, array $statuses): void
    {
        foreach ($statuses as $status) {
            EmployeeAttendanceStatusLog::create([
                'employee_user_id'       => $dto->employeeId,
                'employee_attendance_id' => $attendanceId,
                'leave_application_id'   => null,
                'attendance_status'      => $status,
                'attendance_date'        => $dto->date,
                'created_user_id'        => $this->systemUserId,
                'created_at'             => $this->now,
            ]);
        }
    }

    // ─────────────────────────────────────────────
    // Step 13 — Link Leave Achieve Log
    // ─────────────────────────────────────────────

    private function linkLeaveAchieveLog(int $attendanceId, bool $compensationLeaveEarned): void
    {
        if (!$compensationLeaveEarned) {
            return;
        }

        EmployeeLeaveAchieveLog::where('created_user_id', $this->systemUserId)
            ->where('created_at', $this->now)
            ->whereNull('employee_attendance_id')
            ->update(['employee_attendance_id' => $attendanceId]);
    }
}