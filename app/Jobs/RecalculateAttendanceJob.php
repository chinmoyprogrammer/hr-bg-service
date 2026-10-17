<?php

namespace App\Jobs;

use App\Models\EmployeeAttendance;
use App\Models\EmployeeAttendanceStatusLog;
use App\Models\EmployeeLeaveAchieveLog;
use App\Models\EmployeeLeaveBalance;
use App\Models\EmployeeOfficialInformation;
use App\Models\Holiday;
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


class RecalculateAttendanceJob extends Job implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;
    protected array $payload;

    /**
     * Create a new job instance.
     *
     * @param array $payload
     */
    public function __construct(array $payload)
    {
        $this->payload    = $payload;
        $this->connection = 'rabbitmq';
        $this->queue      = 'recalculateAttendance_queue';
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        Log::info('RecalculateAttendance started for LeaveApplication ID: ', ['payload' => $this->payload]);
        try {
            $leaveApplicationId = $this->payload['leave_application_id'] ?? null;
            if (!$leaveApplicationId) {
                Log::error('RecalculateAttendanceJob: leave_application_id is missing in payload', ['payload' => $this->payload]);
                return;
            }

            $jobStart      = date('Y-m-d H:i:s');
            $systemUserId  = (int) env('SYSTEM_USER_ID', 1);


            // 1. Get Leave Details (dates and employee)
            $leaveDetails = LeaveApplicationDetail::where('leave_application_id', $leaveApplicationId)->get();

            if ($leaveDetails->isEmpty()) {
                Log::warning('RecalculateAttendanceJob: No details found for LeaveApplication ID: ' . $leaveApplicationId);
                return;
            }

            Log::warning('test', ['detail' => $leaveDetails]);


            $employeeUserId = $leaveDetails->first()->employee_user_id;
            $leaveDates = $leaveDetails->pluck('leave_date')->toArray();
            $keyedLeaveDetails = $leaveDetails->keyBy('leave_date');

            Log::warning('leaveDates', ['leaveDates' => $leaveDates]);

            if (empty($leaveDates)) {
                Log::warning('RecalculateAttendanceJob: No dates found for LeaveApplication ID: ' . $leaveApplicationId);
                return;
            }

            // Weekends/holidays merged into Absent around these dates by
            // ProcessSandwichedHolidaysJob. Must be read now: recalculating the
            // leave dates below wipes their absent_bridge rows.
            $bridgeRestore = app(\App\Services\AbsentBridgeRestoreService::class);
            $bridgedHolidays = $bridgeRestore->collect($employeeUserId, $leaveDates);
            // ── Process every employee × date ────────────────────────────────────
            $prepared            = [];
            $statusLogData       = [];
            $payrollAccruedItems = [];
            $leaveAchieveLogs    = [];
            $preparedKeys        = [];
            $statusLogKeyIndex   = [];
            $payrollKeyIndex     = [];
            $leaveLogKeyIndex    = [];


            // Manual / corrected punches (CSV import, attendance correction) live only on the
            // attendance row. Read them before the rows are deleted below, so a half-day
            // leave keeps the half the employee actually worked.
            $manualRows = EmployeeAttendance::where('employee_user_id', $employeeUserId)
                ->whereIn('date', $leaveDates)
                ->where(fn($q) => $q->where('is_manual', 1)->orWhere('is_corrected', 1)->orWhere('source', 'manual'))
                ->where(fn($q) => $q->whereNotNull('in_time')->orWhereNotNull('out_time'))
                ->get(['id', 'date', 'in_time', 'out_date', 'out_time', 'is_manual', 'is_corrected', 'remarks'])
                ->keyBy('date');
            $manualRowIdsUsed = []; // date => old attendance id, for re-linking manual_attendance_records

            $attRecordIds = EmployeeAttendance::where('employee_user_id', $employeeUserId)->whereIn('date', $leaveDates )->whereRaw('is_corrected = 0 AND is_manual = 0')->pluck('id');

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



            foreach ($leaveDates as $key => $date) {
                if( strtotime(date('Y-m-d')) < strtotime($date))
                {
                    Log::warning('RecalculateAttendanceJob: Date ' . $date . ' is in the future, skipping');
                    continue;
                }
                $row = EmployeeOfficialInformation::with([
                    'employeeAttendanceTemps' => function ($query) {
                        $query->whereRaw('1 = 0'); // Forces empty result
                    },
                    // Late deduction (reached when a half-day leave day has punches) counts
                    // this month's late days only — same scope as ProcessManualDataJob
                    'lateDays' => fn($q) => $q
                        ->whereBetween('attendance_date', [date('Y-m-01', strtotime($date)), date('Y-m-t', strtotime($date))])
                        ->where('attendance_status', 2)
                        ->whereHas('attendance')
                        ->with(['attendance:id,shift_id,shift_start_time,in_time,out_time']),
                ])->where('employee_user_id', $employeeUserId)->first();
                $shift = $row->shift_id ? Shift::find($row->shift_id) :  Shift::find(1);

                // Half-day leave (4 = 1st half, 6 = 2nd half): the other half may have been
                // worked, so the day's own punches are used — manual/corrected first, else
                // biometric. Full-day leave keeps ignoring punches as before.
                $manualPunch = null;
                $leaveDetail = $keyedLeaveDetails->get($date);
                if ($leaveDetail && in_array((int) $leaveDetail->first_second_half, [4, 6], true)) {
                    $manualRow = $manualRows->get($date);
                    if ($manualRow) {
                        $manualPunch = [
                            'in_time'      => $manualRow->in_time,
                            'out_date'     => $manualRow->out_date,
                            'out_time'     => $manualRow->out_time,
                            'is_manual'    => $manualRow->is_manual,
                            'is_corrected' => $manualRow->is_corrected,
                            'remarks'      => $manualRow->remarks,
                        ];
                        $manualRowIdsUsed[$date] = $manualRow->id;
                    } else {
                        $row->setRelation('employeeAttendanceTemps', $row->employeeAttendanceTemps()
                            ->whereRaw('DATE(punch_datetime) = ?', [$date])
                            ->orderBy('punch_datetime')
                            ->get());
                    }
                }

                EmployeeAttendanceStatusLog::where('attendance_date', $date)->where('employee_user_id', $employeeUserId)->delete();
                EmployeeAttendance::where('date', $date)->where('employee_user_id', $employeeUserId)->delete();
                $result = app(\App\Services\AttendanceProcessingService::class)->process(
                    $row,
                    $date,
                    $shift,
                    null,
                    null,
                    collect(), // holiday duty requisitions: the punch path requires a Collection
                    null,
                    $keyedLeaveDetails,
                    $manualPunch,
                    null,
                    null
                );

                Log::warning('ProcessTempDataJob: dates span different calendar months', ['payload' => $result]);

                if ($result['skip']) {
                    continue;
                }

                if ($result['attendance']) {
                    $rowKey = $result['rowKey'];
                    $prepared[]    = $result['attendance'];
                    $preparedKeys[] = $rowKey;

                    if ($result['statusLogs']) {
                        foreach ($result['statusLogs'] as $logRow) {
                            $statusLogData[]             = $logRow;
                            $statusLogKeyIndex[$rowKey][]    = count($statusLogData) - 1;
                        }
                    }
                    if ($result['payrollAccruedItems']) {
                        foreach ($result['payrollAccruedItems'] as $item) {
                            $empDateKey = $row->employee_user_id . '|' . $date;
                            $payrollAccruedItems[]           = $item;
                            $payrollKeyIndex[$empDateKey][]  = count($payrollAccruedItems) - 1;
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

            // ── Bulk insert attendance ────────────────────────────────────────────
            if (empty($prepared)) {
                $bridgeRestore->restore($employeeUserId, $bridgedHolidays);
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
                Log::error('RecalculateAttendanceJob bulk insert failed', ['error' => $e->getMessage()]);
                return;
            }

            // ── Resolve inserted IDs and back-fill attendance_id references ───────
            $jobEnd = date('Y-m-d H:i:s');
            $idMap         = [];
            $idMapByEmpDate = [];

            $insertedRows = EmployeeAttendance::where('employee_user_id', $employeeUserId)
                ->whereBetween('created_at', [$jobStart, $jobEnd])
                ->get(['id', 'employee_user_id', 'date', 'in_time', 'out_time', 'created_at', 'emp_code']);

            foreach ($insertedRows as $r) {
                $k = $r->emp_code . '|' . $r->employee_user_id . '|' . $r->date
                    . '|' . ($r->in_time ?? '') . '|' . ($r->out_time ?? '') . '|' . $r->created_at;
                $idMap[$k]                                                  = $r->id;
                $idMapByEmpDate[$r->employee_user_id . '|' . $r->date]     = $r->id;
            }

            // ── Point manual attendance records at the recreated attendance rows ──
            foreach ($manualRowIdsUsed as $date => $oldId) {
                if (isset($idMapByEmpDate[$employeeUserId . '|' . $date])) {
                    // toBase(): the table has no updated_at column for Eloquent to touch
                    ManualAttendanceRecord::where('employee_attendance_id', $oldId)
                        ->toBase()
                        ->update(['employee_attendance_id' => $idMapByEmpDate[$employeeUserId . '|' . $date]]);
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
                //Log::warning('payroll:', ['payrollAccruedItems'=>$payrollAccruedItems, 'payrollKeyIndex'=>$payrollKeyIndex, 'idMapByEmpDate'=>$idMapByEmpDate]);
                foreach ($payrollKeyIndex as $k => $indices) {
                    //Log::warning('payroll:', ['k'=>$k,'indics'=>$indices]);
                    if (isset($idMapByEmpDate[$k])) {
                        foreach ($indices as $idx) {
                            $payrollAccruedItems[$idx]['employee_attendance_id'] = $idMapByEmpDate[$k];
                        }
                    }
                }
                foreach (array_chunk($payrollAccruedItems, 500) as $chunk) {
                    $status = PayrollAccruedAllowanceIncome::insert($chunk);
                    //Log::warning('Payroll Allowance Income:', [$status, $chunk]);
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

            DB::commit();

            // Leave days are no longer Absent, so bring back the weekends/holidays
            // they had merged into Absent (re-merging any still sandwiched).
            $bridgeRestore->restore($employeeUserId, $bridgedHolidays);

            Log::info('RecalculateAttendanceJob completed successfully for LeaveApplication ID: ' . $leaveApplicationId);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('RecalculateAttendanceJob failed: ' . $e->getMessage());
            throw $e;
        }
    }
}
