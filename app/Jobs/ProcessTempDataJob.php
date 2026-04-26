<?php

namespace App\Jobs;

use App\Models\EmployeeAttendance;
use App\Models\EmployeeAttendanceStatusLog;
use App\Models\EmployeeLeaveAchieveLog;
use App\Models\EmployeeLeaveBalance;
use App\Models\EmployeeOfficialInformation;
use App\Models\EmployeeOtPolicy;
use App\Models\EmployeeOtRequisition;
use App\Models\Holiday;
use App\Models\HolidayDutyRequisition;
use App\Models\LeaveApplicationDetail;
use App\Models\PayrollAccruedAllowanceIncome;
use App\Models\Shift;
use App\Services\AttendanceDateProcessorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessTempDataJob extends Job implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    protected array $payload;

    public function __construct(array $payload)
    {
        $this->payload    = $payload;
        $this->connection = 'rabbitmq';
        $this->queue      = 'processTempData_queue';
    }

    public function handle(): void
    {
        $startDate = $this->payload['start_date'] ?? null;
        $endDate   = $this->payload['end_date']   ?? null;

        if (!$startDate || !$endDate) {
            Log::warning('ProcessTempDataJob: missing start_date or end_date', ['payload' => $this->payload]);
            return;
        }

        if (
            date('m', strtotime($startDate)) !== date('m', strtotime($endDate)) &&
            date('Y', strtotime($startDate)) !== date('Y', strtotime($endDate))
        ) {
            Log::warning('ProcessTempDataJob: dates span different calendar months', ['payload' => $this->payload]);
            return;
        }

        $startBoundary = \Carbon\Carbon::parse($startDate)->startOfDay()->toDateString();
        $endBoundary   = \Carbon\Carbon::parse($endDate)->endOfDay()->toDateString();
        $jobStart      = date('Y-m-d H:i:s');
        $systemUserId  = (int) env('SYSTEM_USER_ID', 1);

        // ── Date range array ──────────────────────────────────────────────────
        $dates = collect(\Carbon\Carbon::parse($startDate)->range(\Carbon\Carbon::parse($endDate)))
            ->map(fn($d) => $d->format('Y-m-d'))
            ->toArray();

        // ── Pre-load shared look-up data ──────────────────────────────────────
        $officialInfos = EmployeeOfficialInformation::with([
            'employeeAttendanceTemps' => fn($q) => $q
                ->whereRaw('DATE(punch_datetime) BETWEEN ? AND ?', [$startBoundary, $endBoundary])
                ->orderBy('punch_datetime'),
            'employeeOtPolicy'        => fn($q) => $q
                ->where('effective_date', '<=', $startBoundary)->where('status', 1),
            'hasLeavePolicyDetail',
            'hasLateDeductionPolicy'  => fn($q) => $q
                ->where('effective_date', '<=', $startBoundary)->where('status', 1),
            'lateDays'                => fn($q) => $q
                ->whereBetween('attendance_date', [
                    date('Y-m-01', strtotime($startBoundary)),
                    date('Y-m-t',  strtotime($endBoundary)),
                ])
                ->where('attendance_status', 2),
        ])
        ->whereIn('emp_code', function ($q) {
            $q->select('emp_code')
            ->from('employee_attendance_temp')
            ->distinct();
        })
        ->get();

        $shifts = \App\Models\Shift::where('effective_date', '<=', $startDate)
            ->orderBy('effective_date', 'desc')
            ->get()
            ->keyBy('id');

        $leaveApplicationDetails = LeaveApplicationDetail::whereBetween('leave_date', [$startDate, $endDate])
            ->get()
            ->groupBy('employee_user_id');

        $publicHolidays = Holiday::whereBetween('date', [$startDate, $endDate])
            ->whereNull('employee_user_id')
            ->get()->keyBy('date');
        /* if($publicHolidays->isNotEmpty()){
            Log::warning('public holiday:', ['public holiday:' => $publicHolidays]);
            return;
        } */

        $employeeHolidaysByEmp = Holiday::whereBetween('date', [$startDate, $endDate])
            ->whereNotNull('employee_user_id')
            ->get()
            ->groupBy('employee_user_id')
            ->map(fn($c) => $c->keyBy('date'));
        /* if($employeeHolidaysByEmp->isNotEmpty()){
            Log::warning('employee holiday:', ['employee holiday:' => $employeeHolidaysByEmp]);
            return;
        } */
        

        $holidayDutyRequisitions = HolidayDutyRequisition::whereBetween('duty_date', [$startDate, $endDate])
            ->join('holiday_duty_requisition_details', 'holiday_duty_requisitions.id', '=', 'holiday_duty_requisition_details.holiday_duty_requisition_id')
            ->where('holiday_duty_requisitions.status', 'Approved')
            ->whereNotNull('holiday_duty_requisitions.approved_at')
            ->get();
        /* if($holidayDutyRequisitions->isNotEmpty()){
            Log::warning('holiday Requisition:', ['holiday Requisition:' => $holidayDutyRequisitions]);
            return;
        } */

        $otRequisitions = EmployeeOtRequisition::whereRaw('? BETWEEN ot_date_from AND ot_date_to', [$startDate])
            ->whereRaw('? BETWEEN ot_date_from AND ot_date_to', [$endDate])
            ->whereNotNull('approval_date')
            ->get()
            ->keyBy('employee_user_id');
        /* if($otRequisitions->isNotEmpty()){
            Log::warning('OT Requisition:', ['OT Requisition:' => $otRequisitions]);
            return;
        } */

        // ── Delete existing records for the date range ────────────────────────
        $attRecordIds = EmployeeAttendance::whereIn('date', $dates)->pluck('id');

        if ($attRecordIds->isNotEmpty()) {
            $totalLeaveAchieved = EmployeeLeaveAchieveLog::whereIn('employee_attendance_id', $attRecordIds)
                ->selectRaw('SUM(leave_count) as leave_count, employee_leave_balance_id')
                ->groupBy('employee_leave_balance_id')
                ->get();
            /* if($totalLeaveAchieved->isNotEmpty()){
                Log::warning('totalLeaveAchieved:', ['totalLeaveAchieved:' => $totalLeaveAchieved]);
                return;
            } */
            foreach ($totalLeaveAchieved as $item) {
                EmployeeLeaveBalance::where('id', $item->employee_leave_balance_id)
                    ->decrement('current_balance', $item->leave_count);
            }

            EmployeeAttendanceStatusLog::whereIn('employee_attendance_id', $attRecordIds)->delete();
            PayrollAccruedAllowanceIncome::whereIn('employee_attendance_id', $attRecordIds)->delete();
            EmployeeLeaveAchieveLog::whereIn('employee_attendance_id', $attRecordIds)->delete();
            EmployeeAttendance::whereIn('id', $attRecordIds)->delete();
        }

        // ── Process every employee × date ────────────────────────────────────
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

            foreach ($dates as $date) {
                $shiftId        = $row->shift_id ?? Shift::find(1)->id;
                $shift          = $shifts->get($shiftId);
                $publicHoliday  = $publicHolidays->get($date);
                $empHoliday     = optional($employeeHolidaysByEmp->get($row->employee_user_id))->get($date);

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

                Log::warning('ProcessTempDataJob: dates span different calendar months', ['payload' => $result]);

                if ($result['skip']) {
                    continue;
                }

                if ($result['attendance']) {
                    $rowKey = $result['rowKey'];
                    $prepared[]    = $result['attendance'];
                    $preparedKeys[] = $rowKey;

                    if($result['statusLogs']){
                        foreach ($result['statusLogs'] as $logRow) {
                            $statusLogData[]                 = $logRow;
                            $statusLogKeyIndex[$rowKey][]    = count($statusLogData) - 1;
                        }
                    }
                    if($result['payrollAccruedItems']){
                        foreach ($result['payrollAccruedItems'] as $item) {
                            $empDateKey = $row->employee_user_id . '|' . $date;
                            $payrollAccruedItems[]           = $item;
                            $payrollKeyIndex[$empDateKey][]  = count($payrollAccruedItems) - 1;
                        }
                    }
                    if($result['leaveAchieveLogs']){
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
            Log::error('ProcessTempDataJob bulk insert failed', ['error' => $e->getMessage()]);
            return;
        }

        // ── Resolve inserted IDs and back-fill attendance_id references ───────
        $jobEnd = date('Y-m-d H:i:s');
        $idMap         = [];
        $idMapByEmpDate = [];

        $insertedRows = EmployeeAttendance::where('created_user_id', $systemUserId)
            ->whereBetween('created_at', [$jobStart, $jobEnd])
            ->get(['id', 'employee_user_id', 'date', 'in_time', 'out_time', 'created_at', 'emp_code']);

        foreach ($insertedRows as $r) {
            $k = $r->emp_code . '|' . $r->employee_user_id . '|' . $r->date
                . '|' . ($r->in_time ?? '') . '|' . ($r->out_time ?? '') . '|' . $r->created_at;
            $idMap[$k]                                                  = $r->id;
            $idMapByEmpDate[$r->employee_user_id . '|' . $r->date]     = $r->id;
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