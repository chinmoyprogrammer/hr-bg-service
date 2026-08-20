<?php

namespace App\Jobs;

use App\Models\EmployeeAttendance;
use App\Models\EmployeeAttendanceStatusLog;
use App\Models\EmployeeAttendanceTemp;
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
use Illuminate\Support\Facades\Http;
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
        try{
            $startDate = $this->payload['start_date'] ?? null;
            $endDate   = $this->payload['end_date']   ?? null;



            //...............start processing temp data...............
            $device_user_name = env('DEVICE_USER_NAME');
            $device_password = env('DEVICE_PASSWORD');
            $jwt_api_url = env('JWT_API_URL');

            // get token from api (disable SSL verification if needed, no custom handler)
            $token = Http::timeout(30)
                ->withOptions([
                    'verify' => false,
                ])
                ->post($jwt_api_url, [
                    'username' => $device_user_name,
                    'password' => $device_password
                ]);
            // output : { "token": "gP4K......biHUoy" }


            // Fetch attendance data from device API
            $attendanceApiUrl = env('ATTENDANCE_DATA_API_URL');
            $startTime = $startDate;
            $endTime   = $endDate;


            // $payload = [
            //     'message' => "HHH",
            //     'start_date' => $startTime,
            //     'end_date' => $endTime,
            // ];
            // Increase timeout to 120 seconds and add retry logic to handle transient network issues
            $attendance_data = Http::timeout(120)
                ->retry(3, 5000) // 3 retries, 5 second delay between retries
                ->withHeaders([
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'JWT ' . $token->json('token')
                ])
                ->get($attendanceApiUrl, [
                    'start_time' => $startTime,
                    'end_time'   => date('Y-m-d', strtotime($startTime . ' +1 day')),
                    'page'       => 1,
                    'page_size'  => 50000,
                    'departments' => 1,
                    'areas' => [2,3],
                ]);

            // Return list of attendances
            //return $attendance_data->json();

            //insert data to temp table
            $responseJson = $attendance_data->json();

            $records = [];
            if (is_array($responseJson)) {
                $records = $responseJson['data'] ?? $responseJson; // handle both wrapped and raw arrays
            }
            // Manual/correction attendance can replace/update device (biometric) data,
            // but device data must NEVER replace/update a manual or corrected record.
            // Guard this per (employee, date) across the WHOLE job window — not just
            // $startDate — so a manual/corrected entry on any one date doesn't block
            // (or get overwritten on) that employee's other, unprotected dates.
            $manualOrCorrectedRows = EmployeeAttendance::query()
                ->whereBetween('date', [$startDate, $endDate])
                ->where(function ($q) {
                    $q->where('is_manual', 1)->orWhere('is_corrected', 1);
                })
                ->get(['employee_user_id', 'emp_code', 'date']);

            $manualOrCorrectedByEmpId   = []; // "employee_user_id|date" => true
            $manualOrCorrectedByEmpCode = []; // "emp_code|date" => true
            foreach ($manualOrCorrectedRows as $r) {
                $manualOrCorrectedByEmpId[$r->employee_user_id . '|' . $r->date]   = true;
                $manualOrCorrectedByEmpCode[(string) $r->emp_code . '|' . $r->date] = true;
            }
            Log::info('ProcessTempDataJob: manual/corrected protection keys', [
                'protected_date_count' => count($manualOrCorrectedByEmpId),
            ]);

            $grouped = [];
            foreach ($records as $record) {
                // expecting keys: emp_code, att_date (YYYY-MM-DD), punch_time (HH:MM)
                if (!isset($record['emp_code'], $record['punch_time'])) {
                    continue;
                }
                $empCode = $record['emp_code'];
                // combine date + time to build proper datetime for temp table
                //$attDate = trim($record['att_date']);
                $punchTime = trim($record['punch_time']);
                // Use att_date + punch_time to avoid defaulting to today
                $datetime = \Carbon\Carbon::parse($punchTime);

                // Never import a device punch for a date already protected by a
                // manual/corrected attendance record for this employee.
                if (isset($manualOrCorrectedByEmpCode[(string) $empCode . '|' . $datetime->format('Y-m-d')])) {
                    continue;
                }

                $grouped[] = [
                    'emp_code' => intval($empCode),
                    'punch_datetime' => $datetime->toDateTimeString(),
                ];
            }
            // Prepare bulk insert data for temp table
            $insert_data = array_values($grouped);
            // Log::info('pullRawDataFromDeviceToTempTable: records fetched', [
            //     'count' => is_array($records) ? count($records) : 0,
            // ]);
            if (!empty($insert_data)) {
                //dd('GGGGGGGG');
                //...Delete all previous data
                EmployeeAttendanceTemp::truncate();
                
                //...Insert into temp table
                EmployeeAttendanceTemp::insert($insert_data);

                //.... Punch history insert
                // Build a single INSERT ... ON DUPLICATE KEY UPDATE statement so duplicates are silently skipped
                $columns = ['emp_code', 'punch_datetime'];
                $values  = implode(',', array_fill(0, count($insert_data), '(' . implode(',', array_fill(0, count($columns), '?')) . ')'));
                $updates = implode(',', array_map(fn($c) => "$c = VALUES($c)", $columns));

                $sql = "INSERT INTO employee_attendance_punch_histories (emp_code, punch_datetime) VALUES $values ON DUPLICATE KEY UPDATE $updates";

                // Flatten the data for parameter binding
                $bindings = [];
                foreach ($insert_data as $row) {
                    $bindings[] = $row['emp_code'];
                    $bindings[] = $row['punch_datetime'];
                }

                DB::insert($sql, $bindings);
                // Log::info('pullRawDataFromDeviceToTempTable: temp insert done', [
                //     'inserted' => count($insert_data),
                // ]);
            }
            else
            {
                Log::warning('ProcessTempDataJob: no data to insert', ['payload' => $this->payload]);
                return;
            }
            //...............end processing temp data...............

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

            // The previous-day-checkout pre-pass backfills date D using date D+1's
            // early punches. When the pull range ends at $endDate, D+1's punches
            // must still be visible (peeked, not persisted) or $endDate's own
            // out-punch stays null until D+1 is pulled separately.
            $peekEndBoundary = \Carbon\Carbon::parse($endDate)->addDay()->endOfDay()->toDateString();

            // ── Date range array ──────────────────────────────────────────────────
            $dates = collect(\Carbon\Carbon::parse($startDate)->range(\Carbon\Carbon::parse($endDate)))
                ->map(fn($d) => $d->format('Y-m-d'))
                ->toArray();
            //Log::info('ProcessTempDataJob: dates: quader', ['dates' => $dates, 'startDate' => $startDate, 'endDate' => $endDate]);

            // ── Pre-load shared look-up data ──────────────────────────────────────
            Log::warning('start end boundary', ['payload' => [$startBoundary, $endBoundary]]);
            $officialInfos = EmployeeOfficialInformation::with([
                'hasSeparationApplication',
                'employeeAttendanceTemps' => fn($q) => $q
                    // Upper bound extended to $peekEndBoundary (endDate+1): the
                    // pre-pass needs D+1's early punches to backfill $endDate's own
                    // checkout, even though D+1 itself is not in $dates and gets no
                    // attendance row this run.
                    ->whereRaw('DATE(punch_datetime) BETWEEN ? AND ?', [$startBoundary, $peekEndBoundary])
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
                    ->where('attendance_status', 2)
                    ->whereHas('attendance'),
                'hasEarlyOutRequests' => fn($q) => $q
                    ->whereNull('deleted_at')
                    ->whereBetween('out_date', [$startBoundary, $endBoundary])
                    ->where('approval_status', 1),
                'hasRosterAssignment' => fn($q) => $q
                    // Extend one day before $startBoundary: the pre-pass resolves the
                    // shift for $prevDate (= the day BEFORE whichever date is being
                    // processed) to classify how the previous day's overnight/night-duty
                    // checkout is closed out. When $startDate is the first date in the
                    // batch, $prevDate falls one day short of this boundary — without
                    // the extra day, that roster assignment never gets eager-loaded, so
                    // the resolver silently falls back to actual_shift_id instead of the
                    // employee's true roster-assigned shift for that day.
                    ->whereBetween('from_date', [date('Y-m-d', strtotime($startBoundary . ' -1 day')), $peekEndBoundary])
                    ->whereHas('roster', function($q) {
                        $q->whereNull('deleted_by')->whereNull('deleted_at');
                    }),
                'hasNonWeekendHolidays' => fn($q) => $q
                    ->whereBetween('date', [$startBoundary, $endBoundary])
                    ->where('holiday_type_id', '<>', 8)
            ])
            ->where(function ($q) use ($endDate) {
                $q->whereRaw('joining_date IS NOT NULL AND  joining_date <= ?', [$endDate]);
            })
            //->where('employee_user_id',516)
            ->get();
            // dd($officialInfos);
            /* ->whereIn('emp_code', function ($q) {
                $q->select('emp_code')
                ->from('employee_attendance_temp')
                ->distinct();
            }) */
            //->whereIn('emp_code', ['2508032'])
            
            //208, 395, ->where('employee_user_id','=', 208)

            $shifts = Shift::whereNull('deleted_at')->whereNull('deleted_by')
                ->orderBy('effective_date', 'desc')
                ->get()
                ->keyBy('id');

            $leaveApplicationDetails = LeaveApplicationDetail::whereBetween('leave_date', [$startDate, $endDate])
                ->whereHas('leaveApplication', function($q) {
                    $q->where('approval_status', 1);
                })
                ->get()
                ->groupBy('employee_user_id');

            // $publicHolidays = Holiday::whereBetween('date', [$startDate, $endDate])
            //     ->whereNull('employee_user_id')
            //     ->get()->keyBy('date');
            /* if($publicHolidays->isNotEmpty()){
                Log::warning('public holiday:', ['public holiday:' => $publicHolidays]);
                return;
            } */

            $employeeHolidaysByEmp = Holiday::whereBetween('date', [$startDate, $endDate])
                ->whereNotNull('employee_user_id')
                ->where('holiday_type_id', 8)
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
            $attRecordIds = EmployeeAttendance::whereIn('date', $dates)->whereRaw('is_corrected = 0 AND is_manual = 0')->pluck('id');

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

            // ── Pre-pass: back-fill the PREVIOUS day's missing out-punch ──────────
            // Must run before AttendanceProcessingService. Returns skip keys for
            // dates whose punch was consumed as the previous day's night checkout.
            $shiftId = $rosterAssignment = null ;
            $shiftResolver = function ($row, $date) use ($shifts) {
                $rosterAssignment = $row->hasRosterAssignment?->where('from_date', $date)?->first();
                if ($rosterAssignment && $rosterAssignment->shift_id) {
                    $shiftId = $rosterAssignment->shift_id;
                } else {
                    $shiftId = $row->actual_shift_id;
                }
                Log::info('ProcessTempDataJob shift resolver', [
                    'row' => $row,
                    'date' => $date,
                    'shiftId' => $shiftId,
                ]);
                return $shiftId ? $shifts->get($shiftId) : null;
            };
            // Peek one day past $endDate so $endDate's own out-punch can be
            // backfilled from D+1's early punches in the same run. This date is
            // used ONLY by the pre-pass (to resolve prevDate=$endDate); the main
            // attendance-creation loop below still iterates $dates unchanged, so no
            // attendance row is created/touched for the peek date itself.
            $backfillDates = collect($dates)
                ->push(\Carbon\Carbon::parse($endDate)->addDay()->format('Y-m-d'))
                ->unique()
                ->values()
                ->toArray();

            Log::info('ProcessTempDataJob previous-day out-punch pre-pass start', [
                'official_info_count' => count($officialInfos),
                'dates' => $dates,
                'backfill_dates' => $backfillDates,
                'shift_resolver' => $shiftResolver,
                'shiftId' => $shiftId,
                'rosterAssignment' => $rosterAssignment,
            ]);
            $carryOverKeys = app(\App\Services\PreviousDayOutPunchUpdateService::class)
                ->process($officialInfos, $backfillDates, $shiftResolver);
            Log::info('ProcessTempDataJob previous-day out-punch pre-pass done', [
                'carry_over_key_count' => count($carryOverKeys),
                'carry_over_keys' => $carryOverKeys,
            ]);

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
                    // Device data must never replace/update a manual or corrected
                    // attendance record — skip this date entirely for this employee.
                    if (isset($manualOrCorrectedByEmpId[$row->employee_user_id . '|' . $date])) {
                        Log::info('ProcessTempDataJob skipped date: protected by manual/corrected record', [
                            'employee_user_id' => $row->employee_user_id,
                            'date' => $date,
                        ]);
                        continue;
                    }

                    // Dates whose only punch was consumed as the previous day's night-shift
                    // checkout carry over as Incomplete In + 12/13 instead of Absent.
                    $carryOverNightStatus = $carryOverKeys[$row->employee_user_id . '|' . $date] ?? null;

                    //check roster assignment exist on date = from_date
                    $rosterAssignment = $row->hasRosterAssignment?->where('from_date', $date)?->first();
                    //Log::info('Roster Assignment: ', ['rosterAssignment' => $rosterAssignment]);
                    if($rosterAssignment && $rosterAssignment->shift_id){
                        $shiftId = $rosterAssignment->shift_id;
                    }else{
                        $shiftId = $row->actual_shift_id;
                    }
                    $shift   = $shiftId ? $shifts->get($shiftId) : null;
                    // $publicHoliday  = $publicHolidays->get($date);
                    $publicHoliday  = $row->hasNonWeekendHolidays?->where('date', $date)?->first();
                    $empHoliday     = optional($employeeHolidaysByEmp->get($row->employee_user_id))->get($date);
                    Log::info('ProcessTempDataJob iteration start', [
                        'employee_user_id' => $row->employee_user_id,
                        'emp_code' => $row->emp_code ?? null,
                        'date' => $date,
                        'shift_id' => $shiftId,
                        'shift_found' => (bool) $shift,
                        'public_holiday' => (bool) $publicHoliday,
                        'employee_holiday' => (bool) $empHoliday,
                    ]);

                    $result = app(\App\Services\AttendanceProcessingService::class)->process(
                        $row,
                        $date,
                        $shift,
                        $publicHoliday,
                        $empHoliday,
                        $holidayDutyRequisitions,
                        $otRequisition,
                        $empLeaveDetails,
                        null,
                        $carryOverNightStatus,
                        null
                    );

                    Log::info('ProcessTempDataJob iteration result', [
                        'employee_user_id' => $row->employee_user_id,
                        'date' => $date,
                        'skip' => $result['skip'] ?? null,
                        'has_attendance' => !empty($result['attendance']),
                        'status_log_count' => count($result['statusLogs'] ?? []),
                        'payroll_item_count' => count($result['payrollAccruedItems'] ?? []),
                        'leave_log_count' => count($result['leaveAchieveLogs'] ?? []),
                        'row_key' => $result['rowKey'] ?? null,
                    ]);

                    if ($result['skip']) {
                        Log::warning('ProcessTempDataJob iteration skipped', [
                            'employee_user_id' => $row->employee_user_id,
                            'date' => $date,
                            'shift_id' => $shiftId,
                        ]);
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
                Log::warning('ProcessTempDataJob no attendance rows prepared', [
                    'prepared_count' => count($prepared),
                    'status_log_count' => count($statusLogData),
                    'payroll_item_count' => count($payrollAccruedItems),
                    'leave_log_count' => count($leaveAchieveLogs),
                ]);
                return;
            }
            Log::info('ProcessTempDataJob bulk insert starting', [
                'prepared_count' => count($prepared),
                'status_log_count' => count($statusLogData),
                'payroll_item_count' => count($payrollAccruedItems),
                'leave_log_count' => count($leaveAchieveLogs),
            ]);
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
            Log::info('ProcessTempDataJob inserted attendance rows fetched', [
                'inserted_row_count' => $insertedRows->count(),
                'job_start' => $jobStart,
                'job_end' => $jobEnd,
            ]);

            foreach ($insertedRows as $r) {
                $k = $r->emp_code . '|' . $r->employee_user_id . '|' . $r->date
                    . '|' . ($r->in_time ?? '') . '|' . ($r->out_time ?? '') . '|' . $r->created_at;
                $idMap[$k]                                                  = $r->id;
                $idMapByEmpDate[$r->employee_user_id . '|' . $r->date]     = $r->id;
            }

            Log::info('ProcessTempDataJob idMap:', ['idMap'=>$idMap]);
            Log::info('ProcessTempDataJob idMapByEmpDate:', ['idMapByEmpDate'=>$idMapByEmpDate]);

            // ── Insert status logs ────────────────────────────────────────────────
            if (!empty($statusLogData)) {
                $unmatchedStatusLogKeys = [];
                foreach ($statusLogKeyIndex as $k => $indices) {
                    if (isset($idMap[$k])) {
                        foreach ($indices as $idx) {
                            $statusLogData[$idx]['employee_attendance_id'] = $idMap[$k];
                        }
                    } else {
                        $unmatchedStatusLogKeys[] = $k;
                    }
                }
                if (!empty($unmatchedStatusLogKeys)) {
                    Log::warning('ProcessTempDataJob unmatched status log keys', [
                        'count' => count($unmatchedStatusLogKeys),
                        'keys' => array_slice($unmatchedStatusLogKeys, 0, 20),
                    ]);
                }
                foreach (array_chunk($statusLogData, 500) as $chunk) {
                    EmployeeAttendanceStatusLog::insert($chunk);
                }
                Log::info('ProcessTempDataJob status logs inserted', [
                    'count' => count($statusLogData),
                    'unmatched_key_count' => count($unmatchedStatusLogKeys),
                ]);
            } else {
                Log::info('ProcessTempDataJob no status logs to insert');
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
                Log::info('ProcessTempDataJob payroll accrued items inserted', [
                    'count' => count($payrollAccruedItems),
                ]);
            } else {
                Log::info('ProcessTempDataJob no payroll accrued items to insert');
            }

            // ── Insert leave achieve logs ─────────────────────────────────────────
            if (!empty($leaveAchieveLogs)) {
                $unmatchedLeaveLogKeys = [];
                foreach ($leaveLogKeyIndex as $k => $indices) {
                    if (isset($idMap[$k])) {
                        foreach ($indices as $idx) {
                            $leaveAchieveLogs[$idx]['employee_attendance_id'] = $idMap[$k];
                        }
                    } else {
                        $unmatchedLeaveLogKeys[] = $k;
                    }
                }
                if (!empty($unmatchedLeaveLogKeys)) {
                    Log::warning('ProcessTempDataJob unmatched leave achieve log keys', [
                        'count' => count($unmatchedLeaveLogKeys),
                        'keys' => array_slice($unmatchedLeaveLogKeys, 0, 20),
                    ]);
                }
                foreach (array_chunk($leaveAchieveLogs, 500) as $chunk) {
                    EmployeeLeaveAchieveLog::insert($chunk);
                }
                Log::info('ProcessTempDataJob leave achieve logs inserted', [
                    'count' => count($leaveAchieveLogs),
                    'unmatched_key_count' => count($unmatchedLeaveLogKeys),
                ]);
            } else {
                Log::info('ProcessTempDataJob no leave achieve logs to insert');
            }
            Log::info('ProcessTempDataJob completed successfully', [
                'attendance_count' => count($prepared),
                'status_log_count' => count($statusLogData),
                'payroll_item_count' => count($payrollAccruedItems),
                'leave_log_count' => count($leaveAchieveLogs),
            ]);
        }catch(\Exception $e){
            Log::error('ProcessTempDataJob handle failed', [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);
            return;
        }
    }//
}
