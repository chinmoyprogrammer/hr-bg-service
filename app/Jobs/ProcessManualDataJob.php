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
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessManualDataJob extends Job implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;
    protected array $payload;

    /**
     * $payload = [
     *   ['employee_user_id' => 170, 'date' => '2026-05-01', 'in_time' => '09:05:00', 'out_time' => '18:25:00'],
     *   ['employee_user_id' => 171, 'date' => '2026-04-10', 'in_time' => '09:05:00', 'out_time' => '18:15:00'],
     * ]
     */
    public function __construct(array $payload)
    {
        $this->payload    = $payload;
        $this->connection = 'rabbitmq';
        $this->queue      = 'processManualData_queue';
    }

    public function handle(): void
    {
        Log::warning('ProcessManualDataJob:', ['payload' => $this->payload]);
        if (empty($this->payload)) {
            Log::warning('ProcessManualDataJob: empty payload');
            return;
        }

        /* Log::warning('Manual Post:', ['test'=>$this->payload]);
        return; */
        // ── Normalise & validate each row ─────────────────────────────────────
        $validRows = [];
        foreach ($this->payload as $key => $item) {
            if (empty($item['employee_user_id']) || empty($item['date']) || is_string($key)) {
                Log::warning('ProcessManualDataJob: skipping row with missing employee_user_id or date', ['row' => $item, 'key' => $key]);
                continue;
            }
            $validRows[] = [
                'employee_user_id' => (int) $item['employee_user_id'],
                'date'             => $item['date'],
                'in_time'          => $item['in_time']  ?? null,
                'out_date'         => $item['out_date'] ?? null,
                'out_time'         => $item['out_time'] ?? null,
                'is_corrected'     => $item['is_corrected'] ?? 0,
                'is_manual'        => $item['is_manual'] ?? 0,
                'created_user_id'  =>$this->payload['created_user_id'] ?? 1,
                'remarks'          => $item['remarks'] ?? null
            ];
        }
        Log::info('validRows:', ['validRows'=>$validRows]);
        //dd($validRows);


        if (empty($validRows)) {
            Log::warning('ProcessManualDataJob: no valid rows after validation');
            return;
        }

        // ── Derive lookup boundaries from the submitted rows ──────────────────
        $empUserIds  = array_unique(array_column($validRows, 'employee_user_id'));
        $dates       = array_unique(array_column($validRows, 'date'));
        sort($dates);
        //Log::warning('dates:', [$dates]);

        $startDate     = $dates[0];
        $endDate       = $dates[count($dates) - 1];
        $startBoundary = \Carbon\Carbon::parse($startDate)->startOfDay()->toDateString();
        $endBoundary   = \Carbon\Carbon::parse($endDate)->endOfDay()->toDateString();
        $jobStart      = date('Y-m-d H:i:s');
        $systemUserId  = $this->payload['created_user_id'] ?? (int) env('SYSTEM_USER_ID', 1);

        // ── Build a quick lookup: [employee_user_id|date => row] ──────────────
        // Used later to pass in_time / out_time into the service as "manual punch"
        $manualPunchMap = [];
        foreach ($validRows as $r) {
            $manualPunchMap[$r['employee_user_id'] . '|' . $r['date']] = $r;
        }

        Log::warning('manual punch map:', ['manualPunchMap'=>$manualPunchMap]);

        // ── Pre-load shared look-up data (same pattern as ProcessTempDataJob) ─
        $officialInfos = EmployeeOfficialInformation::with([
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
            'hasEarlyOutRequests' => fn($q) => $q
                ->whereNull('deleted_at')
                ->whereBetween('out_date', [$startBoundary, $endBoundary])
                ->where('approval_status', 1),
            'hasRosterAssignment' => fn($q) => $q
                ->whereBetween('from_date', [$startBoundary, $endBoundary])
                ->whereHas('roster', fn($q) =>
                    $q->whereNull('deleted_at')->whereNull('deleted_by')
                ),
                'hasNonWeekendHolidays' => fn($q) => $q
                    ->whereBetween('date', [$startBoundary, $endBoundary])
                    ->where('holiday_type_id', 8)
        ])
        ->whereIn('employee_user_id', $empUserIds)
        ->where(function ($q) use ($endDate) {
                $q->whereRaw('joining_date IS NOT NULL AND  joining_date <= ?', [$endDate]);
        })
        ->get();

        $shifts = Shift::whereNull('deleted_at')->whereNull('deleted_by')
                ->orderBy('effective_date', 'desc')
                ->get()
                ->keyBy('id');

        $leaveApplicationDetails = LeaveApplicationDetail::whereIn('employee_user_id', $empUserIds)
            ->whereBetween('leave_date', [$startDate, $endDate])
            ->whereHas('leaveApplication', function ($q) {
                $q->where('approval_status', 1);
            })
            ->get()
            ->groupBy('employee_user_id');

        // $publicHolidays = Holiday::whereBetween('date', [$startDate, $endDate])
        //     ->whereNull('employee_user_id')
        //     ->get()
        //     ->keyBy('date');

        $employeeHolidaysByEmp = Holiday::whereIn('employee_user_id', $empUserIds)
            ->whereBetween('date', [$startDate, $endDate])
            ->where('holiday_type_id', 8)
            ->get()
            ->groupBy('employee_user_id')
            ->map(fn($c) => $c->keyBy('date'));

        $holidayDutyRequisitions = HolidayDutyRequisition::whereBetween('duty_date', [$startDate, $endDate])
            ->join('holiday_duty_requisition_details', 'holiday_duty_requisitions.id', '=', 'holiday_duty_requisition_details.holiday_duty_requisition_id')
            ->where('holiday_duty_requisitions.status', 'Approved')
            ->whereNotNull('holiday_duty_requisitions.approved_at')
            ->get();

        $otRequisitions = EmployeeOtRequisition::whereIn('employee_user_id', $empUserIds)
            ->whereRaw('? BETWEEN ot_date_from AND ot_date_to', [$startDate])
            ->whereRaw('? BETWEEN ot_date_from AND ot_date_to', [$endDate])
            ->whereNotNull('approval_date')
            ->get()
            ->keyBy('employee_user_id');

        // ── Delete existing attendance records for the submitted emp × date pairs
        $attRecordIds = EmployeeAttendance::whereIn('employee_user_id', $empUserIds)
            ->whereIn('date', $dates)
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
            ManualAttendanceRecord::whereIn('employee_attendance_id', $attRecordIds)->delete();
            PayrollAccruedAllowanceIncome::whereIn('employee_attendance_id', $attRecordIds)->delete();
            EmployeeLeaveAchieveLog::whereIn('employee_attendance_id', $attRecordIds)->delete();
            EmployeeAttendance::whereIn('id', $attRecordIds)->delete();
        }

        // ── Pre-pass: back-fill the PREVIOUS day's missing out-punch ──────────
        // Runs before AttendanceProcessingService. On this manual/correction flow
        // biometric temps are not eager-loaded, so this is a safe no-op per employee
        // (submitted rows already carry their own out_date/out_time explicitly).
        $shiftResolver = function ($row, $date) use ($shifts) {
            $rosterAssignment = $row->hasRosterAssignment?->where('from_date', $date)?->first();
            $shiftId = $rosterAssignment ? $rosterAssignment->shift_id : $row->actual_shift_id;
            return $shiftId ? $shifts->get($shiftId) : null;
        };
        $carryOverKeys = app(\App\Services\PreviousDayOutPunchUpdateService::class)
            ->process($officialInfos, $dates, $shiftResolver);
        Log::info('ProcessManualDataJob previous-day out-punch pre-pass done', [
            'carry_over_key_count' => count($carryOverKeys),
            'carry_over_keys' => $carryOverKeys,
        ]);

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
            $empDates = array_filter(
                $dates,
                fn($d) => isset($manualPunchMap[$row->employee_user_id . '|' . $d])
            );

            foreach ($empDates as $date) {
                // Dates whose only punch was consumed as the previous day's night-shift
                // checkout carry over as Incomplete In + 12/13 instead of Absent.
                $carryOverNightStatus = $carryOverKeys[$row->employee_user_id . '|' . $date] ?? null;

                //check roster assignment exist on date = from_date
                $rosterAssignment = $row->hasRosterAssignment?->where('from_date', $date)?->first();
                if($rosterAssignment){
                    $shiftId = $rosterAssignment->shift_id;
                }else{
                    $shiftId = $row->actual_shift_id;
                }
                $shift   = $shiftId ? $shifts->get($shiftId) : null;
                $manualPunch   = $manualPunchMap[$row->employee_user_id . '|' . $date];
                // $publicHoliday = $publicHolidays->get($date);
                $publicHoliday = $row->hasNonWeekendHolidays?->where('date', $date)?->first();
                $empHoliday    = optional($employeeHolidaysByEmp->get($row->employee_user_id))->get($date);
                

                Log::warning('manual:', ['manualPunch'=>$manualPunch]);
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
                    $carryOverNightStatus,
                    $systemUserId
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
        $manualAttendanceRecords = [];

        $insertedRows = EmployeeAttendance::where('created_user_id', $systemUserId)
            ->whereBetween('created_at', [$jobStart, $jobEnd])
            ->get(['id', 'employee_user_id', 'date', 'in_time', 'out_time', 'created_at', 'emp_code']);

        foreach ($insertedRows as $r) {
            $k = $r->emp_code . '|' . $r->employee_user_id . '|' . $r->date
                . '|' . ($r->in_time ?? '') . '|' . ($r->out_time ?? '') . '|' . $r->created_at;
            $idMap[$k]                                              = $r->id;
            $idMapByEmpDate[$r->employee_user_id . '|' . $r->date] = $r->id;
        }

        foreach ($idMapByEmpDate as $employeeDateKey => $employeeAttendanceId) {
            if (!isset($manualPunchMap[$employeeDateKey])) {
                continue;
            }

            [$employeeUserId] = explode('|', $employeeDateKey, 2);

            $manualAttendanceRecords[] = [
                'employee_attendance_id' => $employeeAttendanceId,
                'created_at' => $jobEnd,
                'created_by' => $this->payload[0]['created_user_id'] ?? 0,
                'employee_user_id' => $r->employee_user_id,
            ];
        }

        if (!empty($manualAttendanceRecords)) {
            foreach (array_chunk($manualAttendanceRecords, 500) as $chunk) {
                ManualAttendanceRecord::insert($chunk);
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
    }
}
