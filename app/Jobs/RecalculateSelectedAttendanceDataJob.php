<?php

namespace App\Jobs;

use App\Models\EmployeeAttendance;
use App\Models\EmployeeAttendanceStatusLog;
use App\Models\EmployeeLeaveAchieveLog;
use App\Models\EmployeeLeaveBalance;
use App\Models\EmployeeOfficialInformation;
use App\Models\EmployeeOtRequisition;
use App\Models\Holiday;
use App\Models\HolidayDutyRequisition;
use App\Models\LeaveApplicationDetail;
use App\Models\ManualAttendanceRecord;
use App\Models\PayrollAccruedAllowanceIncome;
use App\Models\Shift;
use App\Services\AttendanceDeviceDataPullService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


class RecalculateSelectedAttendanceDataJob extends Job implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;
    protected array $payload;
    protected array $dates;
    protected array $employeeUserIds;

    /**
     * $payload = [
     * "dates" => ['2026-05-01', '2026-05-02'],
     * "employee_user_ids" => [1, 2, 3],
     * ]
     */
    public function __construct(array $payload)
    {
        $this->payload    = $payload;
        $this->dates = $payload['dates'] ?? [];
        $this->employeeUserIds = $payload['employee_user_ids'] ?? [];
        $this->connection = 'rabbitmq';
        $this->queue      = 'recalculateSelectedAttendanceData_queue';
    }

    public function handle(): void
    {
        Log::warning('RecalculateSelectedAttendanceDataJob:', ['payload' => $this->payload]);
        if (empty($this->payload)) {
            Log::warning('RecalculateSelectedAttendanceDataJob: empty payload');
            return;
        }


        //..... Pull attendance device data
        if (($this->payload['pullDeviceData'] ?? 0) == 1)
        {
            $attendanceDeviceDataPullService = new AttendanceDeviceDataPullService();
            $attendanceDeviceDataPullService->process($this->dates);
        }

                    
        $validRows = $this->payload['dates'] ?? [];
        if (empty($validRows)) 
        {
            Log::warning('RecalculateSelectedAttendanceDataJob: no valid rows after validation');
            return;
        }

        $systemUserId  = (int) env('SYSTEM_USER_ID', 566);

        $startBoundary = $this->dates[0];
        $endBoundary   = $this->dates[count($this->dates) - 1];
        $nextDayBoundary = date('Y-m-d', strtotime($endBoundary . ' +1 day'));


        // ── Pre-load shared look-up data (same pattern as ProcessTempDataJob) ─
        $officialInfos = EmployeeOfficialInformation::with([
            'employeeAttendanceTemps' => fn($q) => $q
                ->whereRaw('DATE(punch_datetime) BETWEEN ? AND ?', [$startBoundary, $endBoundary])
                ->orderBy('punch_datetime'),
            // +1 day: the next morning holds a night shift's checkout (post-pass at the end)
            'employeeAttendancePunchHistories' => fn($q) => $q
                ->whereRaw('DATE(punch_datetime) BETWEEN ? AND ?', [$startBoundary, $nextDayBoundary])
                ->orderBy('punch_datetime'),
            'employeeOtPolicy'       => fn($q) => $q
                ->where('effective_date', '<=', $startBoundary)->where('status', 1),
            'hasLeavePolicyDetail',
            'hasLateDeductionPolicy' => fn($q) => $q
                ->where('effective_date', '<=', $startBoundary)->where('status', 1),
            'lateDays'=> fn($q) => $q
                ->whereBetween('attendance_date', [
                    date('Y-m-01', strtotime($startBoundary)),
                    date('Y-m-t',  strtotime($endBoundary)),
                ])
                ->where('attendance_status', 2),
            // roster shift per date, same as ProcessTempDataJob (one day earlier for the pre-pass's previous day)
            'hasRosterAssignment' => fn($q) => $q
                ->whereBetween('from_date', [date('Y-m-d', strtotime($startBoundary . ' -1 day')), $nextDayBoundary])
                ->whereHas('roster', fn($q) => $q->whereNull('deleted_at')->whereNull('deleted_by')),
            // approved only, same as ProcessManualDataJob (lazy-loading would include pending/rejected)
            'hasEarlyOutRequests' => fn($q) => $q
                ->whereNull('deleted_at')
                ->whereBetween('out_date', [$startBoundary, $endBoundary])
                ->where('approval_status', 1),
        ])
        ->when(
                !empty($this->employeeUserIds), 
                fn($q) => $q->whereIn('employee_user_id', $this->employeeUserIds)
            )
        ->get();
        // all live shifts, same as ProcessTempDataJob / ProcessManualDataJob. Filtering on
        // effective_date <= start dropped a shift created later (E, from 2026-11-01): its employees'
        // earlier days were deleted above and never rebuilt (process() skips a day without a shift).
        $shifts = Shift::whereNull('deleted_at')->whereNull('deleted_by')
            ->get()
            ->keyBy('id');

        // approved, not deleted only — same as ProcessTempDataJob / ProcessManualDataJob
        $leaveApplicationDetails = LeaveApplicationDetail::whereIn('employee_user_id', $this->employeeUserIds)
            ->whereBetween('leave_date', [$startBoundary, $endBoundary])
            ->whereNull('deleted_at')
            ->whereHas('leaveApplication', fn ($q) => $q->where('approval_status', 1)->whereNull('deleted_at'))
            ->get()
            ->groupBy('employee_user_id');

        // Public holidays are stored per employee; type 8 is the weekend.
        $publicHolidaysByEmp = Holiday::whereIn('employee_user_id', $this->employeeUserIds)
            ->whereBetween('date', [$startBoundary, $endBoundary])
            ->where('holiday_type_id', '<>', 8)
            ->get()
            ->groupBy('employee_user_id')
            ->map(fn($c) => $c->keyBy('date'));

        $employeeHolidaysByEmp = Holiday::whereIn('employee_user_id', $this->employeeUserIds)
            ->whereBetween('date', [$startBoundary, $endBoundary])
            ->where('holiday_type_id', 8)
            ->get()
            ->groupBy('employee_user_id')
            ->map(fn($c) => $c->keyBy('date'));

        $holidayDutyRequisitions = HolidayDutyRequisition::whereBetween('duty_date', [$startBoundary, $endBoundary])
            ->join('holiday_duty_requisition_details', 'holiday_duty_requisitions.id', '=', 'holiday_duty_requisition_details.holiday_duty_requisition_id')
            ->where('holiday_duty_requisitions.status', 'Approved')
            ->whereNotNull('holiday_duty_requisitions.approved_at')
            ->get();

        $otRequisitions = EmployeeOtRequisition::whereIn('employee_user_id', $this->employeeUserIds)
            ->whereRaw('? BETWEEN ot_date_from AND ot_date_to', [$startBoundary])
            ->whereRaw('? BETWEEN ot_date_from AND ot_date_to', [$endBoundary])
            ->whereNotNull('approval_date')
            ->get()
            ->keyBy('employee_user_id');

        // ── Delete existing attendance records for the submitted emp × date pairs
        $existingRows = EmployeeAttendance::whereIn('employee_user_id', $this->employeeUserIds)
            ->whereIn('date', $this->dates)
            ->get(['id', 'employee_user_id', 'date', 'in_time', 'out_date', 'out_time', 'source', 'is_manual', 'is_corrected', 'remarks']);
        $attRecordIds = $existingRows->pluck('id');

        // Manual / corrected punches (CSV import, attendance correction) live only on the
        // attendance row, not in employee_attendance_temp. Keep them so the recalculation
        // (e.g. after a leave approval) re-applies them instead of marking the day absent.
        $manualPunchMap = [];
        $oldIdByEmpDate = [];
        foreach ($existingRows as $existing) {
            $empDateKey = $existing->employee_user_id . '|' . $existing->date;
            $oldIdByEmpDate[$empDateKey] = $existing->id;
            $isManualSource = $existing->source === 'manual' || $existing->is_manual || $existing->is_corrected;
            if ($isManualSource && ($existing->in_time || $existing->out_time)) {
                $manualPunchMap[$empDateKey] = [
                    'in_time'      => $existing->in_time,
                    'out_date'     => $existing->out_date,
                    'out_time'     => $existing->out_time,
                    'is_manual'    => $existing->is_manual,
                    'is_corrected' => $existing->is_corrected,
                    'remarks'      => $existing->remarks,
                ];
            }
        }

        if ($attRecordIds->isNotEmpty()) {
            $totalLeaveAchieved = EmployeeLeaveAchieveLog::whereIn('employee_attendance_id', $attRecordIds)
                ->selectRaw('SUM(leave_count) as leave_count, employee_leave_balance_id')
                ->groupBy('employee_leave_balance_id')
                ->get();

            foreach ($totalLeaveAchieved as $item) {
                EmployeeLeaveBalance::where('id', $item->employee_leave_balance_id)
                    ->decrement('current_balance', $item->leave_count);
            }

            EmployeeAttendanceStatusLog::whereIn('employee_attendance_id', $attRecordIds)->delete();
            PayrollAccruedAllowanceIncome::whereIn('employee_attendance_id', $attRecordIds)->delete();
            EmployeeLeaveAchieveLog::whereIn('employee_attendance_id', $attRecordIds)->delete();
            EmployeeAttendance::whereIn('id', $attRecordIds)->delete();
        }

        // ── Pre-pass: back-fill the PREVIOUS day's missing out-punch ──────────
        // Runs before AttendanceProcessingService (centralised overnight / night-shift
        // cross-day checkout handling). Returns skip keys for dates consumed as the
        // previous day's night checkout.
        // roster-assigned shift for the date wins over the employee's default shift (as in the cron);
        // without it a rostered day shift is recalculated as the night shift and punches land on the wrong day
        $shiftResolver = function ($row, $date) use ($shifts) {
            $rosterShiftId = $row->hasRosterAssignment?->where('from_date', $date)->first()?->shift_id;
            // no roster → actual_shift_id, as ProcessTempDataJob / ProcessManualDataJob do
            return $shifts->get($rosterShiftId ?: ($row->actual_shift_id ?: ($row->shift_id ?? 1)));
        };
        $prevDaySkipKeys = app(\App\Services\PreviousDayOutPunchUpdateService::class)
            ->process($officialInfos, $this->dates, $shiftResolver);
        Log::info('RecalculateSelectedAttendanceDataJob previous-day out-punch pre-pass done', [
            'skip_key_count' => count($prevDaySkipKeys),
            'skip_keys' => array_keys($prevDaySkipKeys),
        ]);

        // employee_attendance_temp only holds today's device punches; every raw punch is also kept in
        // employee_attendance_punch_histories. Merge them, or recalculating a past biometric day
        // (e.g. after a leave / early-out approval) wipes its punches. Duplicates are dropped by
        // unique('punch_datetime') in the punch resolvers.
        // Merged AFTER the pre-pass: that pass can only close days that still exist, and the submitted
        // days were deleted above — their night checkouts are back-filled by the post-pass at the end.
        foreach ($officialInfos as $info) {
            $info->setRelation('employeeAttendanceTemps', $info->employeeAttendanceTemps
                ->concat($info->employeeAttendancePunchHistories)
                ->sortBy('punch_datetime')
                ->values());
            $info->unsetRelation('employeeAttendancePunchHistories');
        }

        // ── Process every submitted employee × date ───────────────────────────
        $prepared            = [];
        $statusLogData       = [];
        $payrollAccruedItems = [];
        $leaveAchieveLogs    = [];
        $preparedKeys        = [];
        $statusLogKeyIndex   = [];
        $payrollKeyIndex     = [];
        $leaveLogKeyIndex    = [];

        foreach ($officialInfos as $row) {
            $empLeaveDetails = $leaveApplicationDetails->get($row->employee_user_id);
            $otRequisition   = $otRequisitions->get($row->employee_user_id);

            // Only process dates that were submitted for this employee
            $empDates = $this->dates;

            foreach ($empDates as $date) {
                $shift         = $shiftResolver($row, $date);
                $publicHoliday = optional($publicHolidaysByEmp->get($row->employee_user_id))->get($date);
                $empHoliday    = optional($employeeHolidaysByEmp->get($row->employee_user_id))->get($date);
                

                $manualPunch = $manualPunchMap[$row->employee_user_id . '|' . $date] ?? null;
                // process() replaces $row->employeeAttendanceTemps with the manual punches;
                // keep the real biometric temps for this employee's other dates
                $biometricTemps = $row->employeeAttendanceTemps;

                $result = app(\App\Services\AttendanceProcessingService::class)->process(
                    $row,
                    $date,
                    $shift,
                    $publicHoliday,
                    $empHoliday,
                    $holidayDutyRequisitions,
                    $otRequisition,
                    $empLeaveDetails,
                    $manualPunch,
                    null,
                    $systemUserId
                );

                if ($manualPunch) {
                    unset($row->employeeAttendanceTemps);
                    $row->setRelation('employeeAttendanceTemps', $biometricTemps);
                }

                if ($result['skip']) {
                    continue;
                }

                Log::warning('manual result:', ['result'=>$result]);

                if ($result['attendance']) {
                    $rowKey         = $result['rowKey'];
                    $prepared[]     = $result['attendance'];
                    $preparedKeys[] = $rowKey;

                    if ($result['statusLogs']) {
                        foreach ($result['statusLogs'] as $logRow) {
                            $statusLogData[]              = $logRow;
                            $statusLogKeyIndex[$rowKey][] = count($statusLogData) - 1;
                        }
                    }
                    if ($result['payrollAccruedItems']) {
                        foreach ($result['payrollAccruedItems'] as $item) {
                            $empDateKey = $row->employee_user_id . '|' . $date;
                            $payrollAccruedItems[]            = $item;
                            $payrollKeyIndex[$empDateKey][]   = count($payrollAccruedItems) - 1;
                        }
                    }
                    if ($result['leaveAchieveLogs']) {
                        foreach ($result['leaveAchieveLogs'] as $logRow) {
                            $leaveAchieveLogs[]              = $logRow;
                            $leaveLogKeyIndex[$rowKey][]     = count($leaveAchieveLogs) - 1;
                        }
                    }
                }
            }
        }

        // ── Bulk insert attendance ────────────────────────────────────────────
        if (empty($prepared)) {
            Log::info('ProcessManualDataJob: nothing to insert after processing');
            return;
        }

        DB::beginTransaction();
        try {
            foreach (array_chunk($prepared, 500) as $chunk) {
                EmployeeAttendance::insert($chunk);
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('RecalculateSelectedAttendanceDataJob bulk insert failed', ['error' => $e->getMessage()]);
            throw $e;  // the run fails (and alerts) instead of passing with no attendance written
        }

        // ── Resolve inserted IDs ──────────────────────────────────────────────
        $jobEnd         = date('Y-m-d H:i:s');
        $idMap          = [];
        $idMapByEmpDate = [];

        $insertedRows = EmployeeAttendance::where('created_user_id', $systemUserId)
            ->whereBetween('created_at', [date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59')])
            ->get(['id', 'employee_user_id', 'date', 'in_time', 'out_time', 'created_at', 'emp_code']);

        foreach ($insertedRows as $r) {
            $k = $r->emp_code . '|' . $r->employee_user_id . '|' . $r->date
                . '|' . ($r->in_time ?? '') . '|' . ($r->out_time ?? '') . '|' . $r->created_at;
            $idMap[$k]                                              = $r->id;
            $idMapByEmpDate[$r->employee_user_id . '|' . $r->date] = $r->id;
        }

        // ── Point manual attendance records at the recreated attendance rows ──
        foreach ($oldIdByEmpDate as $empDateKey => $oldId) {
            if (isset($idMapByEmpDate[$empDateKey])) {
                // toBase(): the table has no updated_at column for Eloquent to touch
                ManualAttendanceRecord::where('employee_attendance_id', $oldId)
                    ->toBase()
                    ->update(['employee_attendance_id' => $idMapByEmpDate[$empDateKey]]);
            }
        }

        // ── Insert status logs ────────────────────────────────────────────────
        if (!empty($statusLogData)) {
            foreach ($statusLogKeyIndex as $k => $indices) {
                if (isset($idMap[$k])) {
                    foreach ($indices as $idx) {
                        $statusLogData[$idx]['employee_attendance_id'] = $idMap[$k];
                    }
                }
            }
            foreach (array_chunk($statusLogData, 500) as $chunk) {
                EmployeeAttendanceStatusLog::insert($chunk);
            }
        }

        // ── Insert payroll accrued allowances ─────────────────────────────────
        if (!empty($payrollAccruedItems)) {
            foreach ($payrollKeyIndex as $k => $indices) {
                if (isset($idMapByEmpDate[$k])) {
                    foreach ($indices as $idx) {
                        $payrollAccruedItems[$idx]['employee_attendance_id'] = $idMapByEmpDate[$k];
                    }
                }
            }
            foreach (array_chunk($payrollAccruedItems, 500) as $chunk) {
                PayrollAccruedAllowanceIncome::insert($chunk);
            }
        }

        // ── Insert leave achieve logs ─────────────────────────────────────────
        if (!empty($leaveAchieveLogs)) {
            foreach ($leaveLogKeyIndex as $k => $indices) {
                if (isset($idMap[$k])) {
                    foreach ($indices as $idx) {
                        $leaveAchieveLogs[$idx]['employee_attendance_id'] = $idMap[$k];
                    }
                }
            }
            foreach (array_chunk($leaveAchieveLogs, 500) as $chunk) {
                EmployeeLeaveAchieveLog::insert($chunk);
            }
        }

        // ── Post-pass: night checkouts of the recalculated days ───────────────
        // A night shift's checkout is the next morning's punch; the cron back-fills it while
        // processing the next day. Do the same for every submitted date + 1, now that the
        // recalculated rows exist (the pre-pass above ran while they were deleted).
        $nextDates = array_values(array_unique(array_map(
            fn ($d) => date('Y-m-d', strtotime($d . ' +1 day')), $this->dates
        )));
        app(\App\Services\PreviousDayOutPunchUpdateService::class)
            ->process($officialInfos, $nextDates, $shiftResolver);
    }
}
