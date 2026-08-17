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
        if($this->payload['pullDeviceData'] == 1)
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

        $systemUserId  = (int) env('SYSTEM_USER_ID', 1);

        $startBoundary = $this->dates[0];
        $endBoundary   = $this->dates[count($this->dates) - 1];


        // ── Pre-load shared look-up data (same pattern as ProcessTempDataJob) ─
        $officialInfos = EmployeeOfficialInformation::with([
            'employeeAttendanceTemps' => fn($q) => $q
                ->whereRaw('DATE(punch_datetime) BETWEEN ? AND ?', [$startBoundary, $endBoundary])
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
                ->where('attendance_status', 2)
        ])
        ->when(
                !empty($this->employeeUserIds), 
                fn($q) => $q->whereIn('employee_user_id', $this->employeeUserIds)
            )
        ->get();
        $shifts = Shift::where('effective_date', '<=', $startBoundary)
            ->orderBy('effective_date', 'desc')
            ->get()
            ->keyBy('id');

        $leaveApplicationDetails = LeaveApplicationDetail::whereIn('employee_user_id', $this->employeeUserIds)
            ->whereBetween('leave_date', [$startBoundary, $endBoundary])
            ->get()
            ->groupBy('employee_user_id');

        $publicHolidays = Holiday::whereBetween('date', [$startBoundary, $endBoundary])
            ->whereNull('employee_user_id')
            ->get()
            ->keyBy('date');

        $employeeHolidaysByEmp = Holiday::whereIn('employee_user_id', $this->employeeUserIds)
            ->whereBetween('date', [$startBoundary, $endBoundary])
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
        $attRecordIds = EmployeeAttendance::whereIn('employee_user_id', $this->employeeUserIds)    
            ->whereIn('date', $this->dates)
            ->pluck('id');

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
                $shiftId       = $row->shift_id ?? Shift::find(1)->id;
                $shift         = $shifts->get($shiftId);
                $publicHoliday = $publicHolidays->get($date);
                $empHoliday    = optional($employeeHolidaysByEmp->get($row->employee_user_id))->get($date);
                

                //Log::warning('manual:', ['manualPunch'=>$manualPunch]);

                $result = app(\App\Services\AttendanceProcessingService::class)->process(
                    $row,
                    $date,
                    $shift,
                    $publicHoliday,
                    $empHoliday,
                    $holidayDutyRequisitions,
                    $otRequisition, 
                    $empLeaveDetails
                );

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
            Log::error('ProcessManualDataJob bulk insert failed', ['error' => $e->getMessage()]);
            return;
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
    }
}
