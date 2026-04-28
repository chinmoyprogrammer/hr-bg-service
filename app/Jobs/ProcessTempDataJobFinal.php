<?php

namespace App\Jobs;

use App\Models\EmployeeAttendance;
use App\Models\EmployeeAttendanceStatusLog;
use App\Models\EmployeeAttendanceTemp;
use App\Models\EmployeeLeaveAchieveLog;
use App\Models\EmployeeLeaveBalance;
use App\Models\EmployeeOfficialInformation;
use App\Models\EmployeeOtData;
use App\Models\EmployeeOtPolicy;
use App\Models\EmployeeOtRequisition;
use App\Models\Holiday;
use App\Models\HolidayDutyRequisition;
use App\Models\LateAttendanceRecord;
use App\Models\LateAttendanceRecordDetail;
use App\Models\LeaveApplicationDetail;
use App\Models\PayrollAccruedAllowanceIncome;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\UserStatusNSettings;
use function calculateOtHours;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessTempDataJobFinal extends Job implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    protected array $payload;

    public function __construct(array $payload)
    {
        $this->payload = $payload;
        // Set connection/queue via trait properties (do not redeclare them)
        $this->connection = 'rabbitmq';
        $this->queue = 'processTempData_queue';
    }

    /**
     * Handle the job: read temp rows within date range and insert into main attendance.
     */
    public function handle(): void
    {

        $startDate = $this->payload['start_date'] ?? null;
        $endDate = $this->payload['end_date'] ?? null;
        $messageText = $this->payload['message'] ?? '';

        if (!$startDate || !$endDate) {
            Log::warning('missing start_date or end_date', [
                'payload' => $this->payload,
            ]);
            return;
        }
        //... if dates not of same calender month, return
        if (date('m', strtotime($startDate)) != date('m', strtotime($endDate)) && date('Y', strtotime($startDate)) != date('Y', strtotime($endDate))) {
            Log::warning('dates not of same calender month', [
                'payload' => $this->payload,
            ]);
            return;
        }

        // dates should be in between a calender month
        $startBoundary = (new \Carbon\Carbon($startDate))->startOfDay()->toDateString();
        $endBoundary = (new \Carbon\Carbon($endDate))->endOfDay()->toDateString();
        $jobStart = date('Y-m-d H:i:s');

        // Get array of dates from two dates provided
        $startCarbon = \Carbon\Carbon::parse($startDate);
        $endCarbon   = \Carbon\Carbon::parse($endDate);
        $dates = collect($startCarbon->range($endCarbon))
            ->map(
                fn($date) => $date->format('Y-m-d')
            )->toArray();

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
        })->where('employee_user_id','=', 208)
        ->get();

        $shifts = \App\Models\Shift::where('effective_date', '<=', $startDate)
            ->orderBy('effective_date', 'desc')
            ->get()
            ->keyBy('id');

        $LeaveApplicationDetails = LeaveApplicationDetail::whereBetween('leave_date', [$startDate, $endDate])
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

        /* $officialInfos = EmployeeOfficialInformation::with(
            [
                'employeeAttendanceTemps' => function ($query) use ($startBoundary, $endBoundary) {
                    $query
                        ->whereRaw('DATE(punch_datetime) BETWEEN ? AND ?', [$startBoundary, $endBoundary])
                        ->orderBy('punch_datetime', 'asc');
                },
                'employeeOtPolicy' => function ($query) use ($startBoundary) {
                    $query
                        ->where('effective_date', '<=', $startBoundary)
                        ->where('status', 1);
                },
                'hasLeavePolicyDetail',
                'hasLateDeductionPolicy' => function ($query) use ($startBoundary) {
                    $query
                        ->where('effective_date', '<=', $startBoundary)
                        ->where('status', 1);
                },
                'lateDays' => function ($query) use ($startBoundary, $endBoundary) {
                    $query
                        ->whereBetween(
                            'attendance_date',
                            [date('Y-m-01', strtotime($startBoundary)), date('Y-m-t', strtotime($endBoundary))]
                        )
                        ->where('attendance_status', 2);
                },
            ]
        )
            ->get();

        // Pre-load all shifts keyed by id for quick lookup inside the loop
        $shifts = \App\Models\Shift::where('effective_date', '<=', $startDate)
            ->orderBy('effective_date', 'desc')
            ->get()
            ->keyBy('id');
        $LeaveApplicationDetails = LeaveApplicationDetail::whereBetween('leave_date', [$startDate, $endDate])
            ->get()
            ->keyBy('employee_user_id');


        $publicHolidays = Holiday::whereBetween('date', [$startDate, $endDate])
            ->whereNull('employee_user_id')
            ->get()
            ->keyBy('date');

        $employeeHolidaysByEmp = Holiday::whereBetween('date', [$startDate, $endDate])
            ->whereNotNull('employee_user_id')
            ->get()
            ->groupBy('employee_user_id')
            ->map(function ($c) {
                return $c->keyBy('date');
            });


        $holidayDutyRequisitions = HolidayDutyRequisition::where('date_from', '>=', $startDate)
            ->join('holiday_duty_requisition_details', 'holiday_duty_requisitions.id', '=', 'holiday_duty_requisition_details.holiday_duty_requisition_id')
            ->where('date_to', '<=', $endDate)
            ->where('holiday_duty_requisitions.status', 'Approved')
            ->whereNotNull('holiday_duty_requisitions.approved_at')
            ->get();

            // Log::info('holiday_duty_requisitions:', [
            //     'payload' => $holidayDutyRequisitions,
            // ]);
            // dd($holidayDutyRequisitions);
            // return;


        /* $rosters = RosterAssignment::where('from_date', '<=', $endDate)
            ->where(function ($q) use ($startDate) {
                $q->whereNull('to_date')->orWhere('to_date', '>=', $startDate);
            })
            ->whereNull('deleted_by')
            ->get()
            ->groupBy('employee_user_id');

        $rosterCatalog = \App\Models\Roster::orderBy('effective_from', 'desc')->get()->groupBy('shift_id'); */


        /* $employeeOtPolicies = EmployeeOtPolicy::where('effective_date', '<=', $startDate)->where('status', 1)
            ->get()
            ->keyBy('employee_user_id');

        $otRequisitions = EmployeeOtRequisition::where('ot_date_from', '>=', $startDate)
            ->where('ot_date_to', '<=', $endDate)
            ->get()
            ->keyBy('employee_user_id'); */
        $systemUserId = (int) env('SYSTEM_USER_ID', 1);
        $prepared = [];
        $skipped = [];
        $statusLogData = [];
        $preparedKeys = [];
        $statusLogKeyIndex = [];
        $PayrollAccruedAllowanceIncome = [];
        $EmployeeLeaveAchieveLog = [];
        $payrollAccruedKeyIndex = [];
        $leaveAchieveLogKeyIndex = [];

        // Build one attendance record per date for this employee
        //$grouped = $temps->groupBy(fn($t) => $t->punch_datetime->format('Y-m-d'));


        // delete data from EmployeeAttendance and EmployeeAttendanceStatusLog of $dates dates


        $attRecordIds = EmployeeAttendance::whereIn('date', $dates)->pluck('id');

        $totalLeaveAchieved =  EmployeeLeaveAchieveLog::whereIn('employee_attendance_id', $attRecordIds)->selectRaw('SUM(leave_count) as leave_count,employee_leave_balance_id')
            ->groupBy('employee_leave_balance_id')
            ->get();



        // delete previous late deduction data of the month of searching date from  late_attendance_records and late_attendance_record_details table
        if ($attRecordIds->isNotEmpty()) {

            // decrease leave balance those are added through this attendance process

            foreach ($totalLeaveAchieved as $item) {
                $leaveBalance = EmployeeLeaveBalance::where('id', $item->employee_leave_balance_id)->first();
                $leaveBalance->current_balance -= $item->leave_count;
                $leaveBalance->save();
            }

            EmployeeAttendance::whereIn('id', $attRecordIds)->delete();
            EmployeeAttendanceStatusLog::whereIn('employee_attendance_id', $attRecordIds)->delete();
            PayrollAccruedAllowanceIncome::whereIn('employee_attendance_id', $attRecordIds)->delete();
            EmployeeLeaveAchieveLog::whereIn('employee_attendance_id', $attRecordIds)->delete();
        }



        foreach ($officialInfos as $row) {
            $LeaveApplicationDetail = $LeaveApplicationDetails->get($row->employee_user_id);
            /*
            //.... # in out time

            //.... # shift info // done (over night shift)
            //.... # status (0=Absent,1=Present,2=Late,3=Late Permitted Present,4=Holiday Absent,8=On Leave)
            //.... # Status2 (5=Incomplete In,6=Incomplete Out,7=On Process,9=Early Out,10=Night Duty)
            //.... # leave status 
            //.... # OT Calculation
            //.... # Holiday
            //.... # join info
            //.... # manual punch
            //.... # roster info
            //.... # absent & leave bridge
            //.... # source ( machine manual)
            //.... # ccorrected or not
            //.... # employee_applied_attendance_correction_id
            */
            $otRequisition = $otRequisitions->get($row->employee_user_id);
            foreach ($dates as $date) {
                $now = date('Y-m-d H:i:s');
                //.... get employee's shift id
                $shift_id = $row->shift_id ?? null;
                $shift = $shifts->get($shift_id);
                $publicHoliday = $publicHolidays->get($date);
                $empHoliday = optional($employeeHolidaysByEmp->get($row->employee_user_id))->get($date);
                $has_halfday_leave = false;
                $leave_application_id = null;
                $statusesForLog = [];
                $onLeave = false;

                //.... This employee has OT on this date or not
                if ($otRequisition) {
                    $hasOtRequisition = $otRequisition->where('from_date', '>=', $date)
                        ->where('employee_user_id', $row->employee_user_id)
                        ->where('to_date', '<=', $date)
                        ->exists(); // OT Found or Not
                } else {
                    $hasOtRequisition = false;  // No OT found
                }

                //.... decide in-out time from shift start/end time
                if ($row->employeeAttendanceTemps->isEmpty()) {

                    $outDate = null;
                    //...... Absent/Leave [start]...................
                    if ($row->employeeAttendanceTemps->isEmpty()) { //weekend = 16, holiday = 9
                        //..... check Employee On Leave or not
                        //...check on LeaveApplicationDetail table
                        if ($LeaveApplicationDetail && $LeaveApplicationDetail->where('first_second_half', 8)->contains('leave_date', $date)) {
                            //8=full day leave, if no attendance on this date, then it is on leave
                            $statusesForLog[] = 8; // On Leave
                        } else {
                            if ($publicHoliday || $empHoliday) {
                                // weekend = 16, holiday = 9
                                if ($publicHoliday) {
                                    $statusesForLog[] = 9; // Public Holiday Absent
                                }
                                if ($empHoliday) {
                                    $statusesForLog[] = 16; // Weekend Absent (user-specific)
                                }
                            } else {
                                $statusesForLog[] = 0; // Absent
                            }
                        }
                        //continue; // continue to next date

                    }
                    //...... Absent/Leave [end]..........................
                }
                
                $first = $last = null;
                if($row->employeeAttendanceTemps->isNotEmpty()){
                    // remove duplicate rows based on punch datetime
                    $row->employeeAttendanceTemps = $row->employeeAttendanceTemps->unique('punch_datetime');
                    //....... Checkin punch time
                    $first = $row->employeeAttendanceTemps
                        ->filter(fn($t) => \Carbon\Carbon::parse($t->punch_datetime)->isSameDay($date))
                        ->sortBy('punch_datetime')
                        ->first();

                    // Ensure punch_datetime is a string for downstream usage
                    if ($first && $first->punch_datetime instanceof \Carbon\Carbon) {
                        $first->punch_datetime = $first->punch_datetime->toDateTimeString();
                    }
                    $last  = $row->employeeAttendanceTemps
                        ->filter(fn($t) => \Carbon\Carbon::parse($t->punch_datetime)->isSameDay($date))
                        ->sortByDesc('punch_datetime')
                        ->first();
                }
                
                // Ensure punch_datetime is a string for downstream usage
                if ($last && $last->punch_datetime instanceof \Carbon\Carbon) {
                    $last->punch_datetime = $last->punch_datetime->toDateTimeString();
                }

                if ($first && $first->punch_datetime === $last->punch_datetime) {
                    $last  = null; //.... if first punch and last punch is same then set last as null
                }

                //     strtotime($date . ' ' . $shift->start_check_in_time),
                //     strtotime($first->punch_datetime));

                //................................ Speccial over night checkout for normal shift duty [start] .................................................
                if (
                    $first && $shift && $shift->is_overnight == 0 && $first->punch_datetime &&
                    strtotime($date . ' ' . $shift->start_check_in_time) > strtotime($first->punch_datetime->format('Y-m-d H:i:s'))
                ) {
                    //... update previous day's checkout date & time
                    $employeeAttendance = EmployeeAttendance::where('employee_user_id', $row->employee_user_id)
                        ->where('date', date('Y-m-d', strtotime($date . " -1 day")))
                        ->whereNotNull('in_time')
                        ->whereNull('out_time')
                        ->whereNull('out_date')
                        ->update([
                            'out_time' => date('H:i:s', strtotime($last->punch_datetime ?? $first->punch_datetime)),
                            'out_date' => $date,
                        ]);
                    //.... update(insert) Employee Attendance Status Log data
                    EmployeeAttendanceStatusLog::insert([
                        'employee_user_id'        => $row->employee_user_id,
                        'employee_attendance_id'  => $employeeAttendance->id,
                        'leave_application_id'    => null,
                        'attendance_status'       => 12, // Night Duty
                        'attendance_date'         => $date,
                        'created_user_id'         => $systemUserId,
                        'created_at'              => $now,
                    ]);
                    
                    continue; // skip this date
                }
                //................................ Speccial over night checkout for normal shift duty [end] .................................................

                //........................... Night Shift [Start]............................................................................................
                //..... over night shift (accross 2 dates) [determine checkin time]
                // 
                if (
                    $first && $shift && $shift->is_overnight == 1 &&
                    strtotime($date . ' ' . $shift->start_check_in_time) <= strtotime($first->punch_datetime)
                    //strtotime($date . ' ' . $shift->start_check_in_time) >= strtotime($first->punch_datetime)
                ) {
                    $last  = null; // will be adjusted next day
                    $outDate = null; // will be adjusted next day
                    $outTime = null; // will be adjusted next day
                } else if ($last) {
                    $outDate = $last->punch_datetime->format('Y-m-d');
                    $outTime = $last->punch_datetime->format('H:i:s');
                }
                //..... For "overnight" shifting duty, on next day update the "Checkout time" of previous day to in " employee_attendance" table
                if (
                    $shift &&
                    $shift->is_overnight == 1 &&
                    (
                        strtotime($last->punch_datetime) >= strtotime($date . ' ' . $shift->clock_out . " - 4 hours") ||
                        strtotime($last->punch_datetime) >= strtotime($date . ' ' . $shift->clock_out)
                    )
                ) {
                    //... update previous day's checkout date & time
                    EmployeeAttendance::where('employee_user_id', $row->employee_user_id)
                        ->where('date', date('Y-m-d', strtotime($date . " -1 day")))
                        ->whereNotNull('in_time')
                        //->whereNull('out_time')
                        //->whereNull('out_date')
                        ->update([
                            'out_time' => date('H:i:s', strtotime($last->punch_datetime)),
                            'out_date' => $date,
                        ]);

                    //.... update(insert) Employee Attendance Status Log data
                    EmployeeAttendanceStatusLog::insert([
                        'employee_user_id'        => $row->employee_user_id,
                        'employee_attendance_id'  => $employeeAttendance->id,
                        'leave_application_id'    => null,
                        'attendance_status'       => 13, // Night Duty
                        'attendance_date'         => $date,
                        'created_user_id'         => $systemUserId,
                        'created_at'              => $now,
                    ]);

                    //...... Provide Night Duty Special Allowance
                    $grossSalary = $row->gross_salary;
                    $amount = ($grossSalary / date('t')) * 1; // todo:: ######## demo, need to confirm from shamim-admin

                    //... need to insert current attendance id at bottom of the code
                    $PayrollAccruedAllowanceIncome[] = [
                        'employee_user_id' => $row->employee_user_id,
                        'employee_attendance_id' => null, // insert it on bottom of the code
                        'amount' => $amount,
                        'type' => 6, // Night Duty Allowance // business_settings -> settings_key(PAYROLL_ACCRUED_ALLOWANCE_INCOME_TYPE) = 6
                        'month' => date('m'),
                        'year' => date('Y'),
                        'date' => $date,
                        'created_user_id' => $systemUserId,
                        'created_at' => $now,
                    ];
                    $payrollAccruedKeyIndex[$row->employee_user_id . '|' . $date][] = count($PayrollAccruedAllowanceIncome) - 1;

                    // //.... update Employee Attendance Status Log data
                    // EmployeeAttendanceStatusLog::where('employee_user_id', $row->employee_user_id)
                    // ->where('employee_attendance_id', $employeeAttendance->id)
                    // ->where('created_at', $now)
                    // ->where('created_user_id', $systemUserId) // Night Duty
                    // ->update([
                    //     'attendance_status' => 13, // Night Duty
                    // ]);
                    continue; // skip this date
                }
                //........................... Night Shift [End]..........................................................


                $shiftStart = $shift->clock_in ?? '09:00:00';
                $shiftEnd   = $shift->clock_out   ?? '18:00:00';
                $grace      = $shift->shift_grace_time ?? 0;
                $workingHours = 0;
                if ($first && $last && $shift) {
                    $time = explode(':', $shift->lunch_meal_time);
                    $lunchMealHour = $time[0] ?? 0;
                    $lunchMealMinute = $time[1] ?? 0;
                    $lunchMealHour = intval($lunchMealHour) +   ((int)$lunchMealMinute / 60);
                    if($first->punch_datetime->diffInHours($last->punch_datetime) >= 4){
                        $workingHours = $first->punch_datetime->diffInHours($last->punch_datetime) - $lunchMealHour;
                    }else{
                        $workingHours = $first->punch_datetime->diffInHours($last->punch_datetime);
                    }
                }

                $inTime  = $first && $first->punch_datetime ? $first->punch_datetime->format('H:i:s') : null;
                $outTime = $last && $last->punch_datetime ? $last->punch_datetime->format('H:i:s') : null;
                $outDate = $last && $last->punch_datetime ? $last->punch_datetime->format('Y-m-d') : null;

                $empCode = $row->emp_code;
                $rowKey = $empCode . '|' . $row->employee_user_id . '|' . $date . '|' . $inTime . '|' . ($outTime ?? '') . '|' . $now;

                // build multiple statuses for this attendance row
                if (
                    $first && $shift &&
                    strtotime($first->punch_datetime) <= strtotime($date . ' ' . $shiftStart . " + $grace minutes") &&
                    strtotime($first->punch_datetime) >= strtotime($date . ' ' . $shift->start_check_in_time)
                ) {
                    $statusesForLog[] = 1; // Present
                }
                if ($first && strtotime($first->punch_datetime) > strtotime($date . ' ' . $shiftStart . " + $grace minutes")) {
                    $statusesForLog[] = 2; // Late

                    //..... calculate late for 4 days or according to policy
                    // if crosses the 4 days or according to  late_deduction_policies  table then insert into late_attendance_records  and late_attendance_records _details table
                    $lateDeductionPolicy = $row->hasLateDeductionPolicy;
                    if ($lateDeductionPolicy) {

                        // for deduction basis -> "Day"
                        if ($lateDeductionPolicy->deduction_basis == "Day" && (($row->lateDays->count()+1) % ($lateDeductionPolicy->max_late_days + 1) == 0)) {
                            //... first check is there any any entry exists for this month for this employee or not
                            $lateAttendanceRecord = LateAttendanceRecord::with('lateAttendanceRecordDetails')
                                ->where('employee_user_id', $row->employee_user_id)
                                ->where('month',  date('m', strtotime($date)))
                                ->where('year',  date('Y', strtotime($date)))
                                ->first();


                            $recordIds = LateAttendanceRecord::where('employee_user_id', $row->employee_user_id)
                                ->where('month', date('m', strtotime($date)))
                                ->where('year', date('Y', strtotime($date)))
                                ->pluck('id');

                            // delete previous late deduction data of the month of searching date from  late_attendance_records and late_attendance_record_details table
                            if ($recordIds->isNotEmpty()) {
                                LateAttendanceRecordDetail::whereIn('late_attendance_record_id', $recordIds)->delete();
                                LateAttendanceRecord::whereIn('id', $recordIds)->delete();
                            }

                            $lateCount = $row->lateDays->count();
                            $cycle = $lateDeductionPolicy->max_late_days + 1;
                            $rowsToInsert = $lateCount / $cycle;
                            Log::warning('test', ['interable'=>$rowsToInsert, 'cycle'=>$cycle, 'lateCount'=> $lateCount ]);

                            for ($i = 0; $i < $rowsToInsert; $i++) {
                                
                                Log::warning('late attendance record', ['employee_user_id'=>$row->employee_user_id, 'cycle'=>$cycle, 'lateCount'=> $lateCount ]);
                                $lateRecord = LateAttendanceRecord::create([
                                    'employee_user_id' => $row->employee_user_id,
                                    'month'            => date('m', strtotime($date)),
                                    'year'             => date('Y', strtotime($date)),
                                    'shift_id'          => $shift_id,
                                    'created_user_id'  => $systemUserId,
                                    'created_at'       => $now,
                                ]);
                                $totalMinutes = 0;
                                // Insert first 5 late dates into LateAttendanceRecordDetail for this cycle
                                for ($j = 0; $j < $cycle && ($i * $cycle + $j) < $lateCount; $j++) {
                                    $lateDay = $row->lateDays->sortBy('attendance_date')->values()[$i * $cycle + $j];
                                    if($lateDay){
                                        $employeeAttendance = EmployeeAttendance::findOrFail($lateDay->employee_attendance_id);
                                        $shiftCheckintime = $date.' '.$shift->check_in ?? '09:00:00';
                                        $shiftCheckintime = Carbon::parse($shiftCheckintime)->toDateTimeString();
                                        $lateSeconds = $first->punch_datetime->diffInSeconds($shiftCheckintime);
                                        $totalMinutes += $lateSeconds;
                                        
                                        LateAttendanceRecordDetail::create([
                                            'late_attendance_record_id' => $lateRecord->id,
                                            'date' => $lateDay->attendance_date,
                                            'late_seconds' => $lateSeconds,
                                        ]);
                                    }
                                }
                                $totalMinutes = ($totalMinutes/60);
                                LateAttendanceRecord::where('id', $lateRecord->id)->update([
                                    'late_minutes' => $totalMinutes,
                                ]);
                            }
                        }
                    }
                }

                //..... Holiday Duty [start].........................

                // if has requisition and completed in-out then apply their supplimentery leave balance/ cash incentive etc
                // if holiday_types.id = 10 then 1 compensetory leave + cash or 2 compensetory leave
                $anyHoliday = ($publicHoliday || $empHoliday);
                $holidayDutyRequisition_all = $holidayDutyRequisitions
                    ->where('duty_date',$date);
                if ($anyHoliday && $row->employeeAttendanceTemps->count() > 0) {
                    if ($empHoliday) {
                        $statusesForLog[] = 20; // Weekend  Duty
                    } else {
                        $statusesForLog[] = 21; // Public Holiday Duty
                    }

                    $statusesForLog[] = 14; // Holiday Duty
                }

                if (count($holidayDutyRequisition_all) >0) {
                    $holidayDutyRequisition = $holidayDutyRequisition_all->where('requested_by_user_id', $row->employee_user_id)->isNotEmpty();

                    if ($holidayDutyRequisition) {
                        // todo :: some times for fastival holiday, employee gets compensetory leave and cash incentive or 2 compensetory leave
                        // increment and fetch the updated row in one go
                        EmployeeLeaveBalance::where('employee_user_id', $row->employee_user_id)
                            ->where('leave_head_id', 5)          // compensation leave type
                            ->where('fiscal_year', date('Y'))
                            ->increment('achived_this_year', 1);

                        EmployeeLeaveBalance::where('employee_user_id', $row->employee_user_id)
                            ->where('leave_head_id', 5)
                            ->where('fiscal_year', date('Y'))
                            ->increment('current_balance', 1);

                        // pull the freshly-updated record
                        $stat = EmployeeLeaveBalance::where('employee_user_id', $row->employee_user_id)
                            ->where('leave_head_id', 5)
                            ->where('fiscal_year', date('Y'))
                            ->first();

                        $leave_validity = date('Y-m-d', strtotime($date . ' + ' . $row->hasLeavePolicyDetail->where('leave_head_id', 5)->first()->leave_avail_validity_days . ' days'));
                        //...... record every single leave acchived after designated adding leaves
                        $EmployeeLeaveAchieveLog[] = [
                            'leave_head_id'            => 5, // compensation leave type
                            'validity_date'            => $leave_validity,
                            'employee_attendance_id'   => null, // will be inserted on bottom
                            'leave_count'              => 1,
                            'leave_final_destination'  => 'Compensatory leave earned from Festival Holiday duty',
                            'created_user_id'          => $systemUserId,
                            'created_at'               => $now,
                            'employee_user_id'         => $row->employee_user_id,
                            'employee_leave_balance_id' => $stat->id,
                        ];
                        $leaveAchieveLogKeyIndex[$rowKey][] = count($EmployeeLeaveAchieveLog) - 1;
                        // $statusesForLog[] = 22; // Leave Compensated

                        if ($publicHoliday->holiday_type_id == 10) // leave type is "Festival Holiday", then provide money
                        {
                            $grossSalary = $row->gross_salary;
                            //todo:: calculate by OT Policy. 
                            // calculate using monthly hrs or day of months
                            $amount = ($grossSalary / date('t')) * $row->employeeOtPolicy->multiplier * 2; // salary of 1 day

                            $PayrollAccruedAllowanceIncome[] = [
                                'employee_user_id' => $row->employee_user_id,
                                'employee_attendance_id' => null, // todo:: need to insert later
                                'amount' => $amount,
                                'type' => 8, // Festival Duty // business_settings -> settings_key(PAYROLL_ACCRUED_ALLOWANCE_INCOME_TYPE) = 8
                                'month' => date('m'),
                                'year' => date('Y'),
                                'date' => $date,
                                'created_user_id' => $systemUserId,
                                'created_at' => $now,
                            ];
                            $payrollAccruedKeyIndex[$row->employee_user_id . '|' . $date][] = count($PayrollAccruedAllowanceIncome) - 1;
                        }

                        if ($stat) {
                            $statusesForLog[] = 18; // Compensation Leave Earned
                        }

                        //
                    } else {
                        $statusesForLog[] = 19; // Unauthorized Holiday Duty
                    }
                } else {
                    $statusesForLog[] = 19; // Unauthorized Holiday Duty
                }

                //..... Holiday Duty [end]...........................


                //...... Incomplete In/Out [start]...................
                if ($row->employeeAttendanceTemps->count() == 1) {
                    // compare with shift in/out time
                    if (
                        $shift &&
                        strtotime($first->punch_datetime) >= strtotime($date . ' ' . $shift->start_check_in_time) &&
                        strtotime($first->punch_datetime) <= strtotime($date . ' ' . $shift->first_half_day)
                    ) {
                        $statusesForLog[] = 11; // Incomplete Out
                    } else {
                        $statusesForLog[] = 10; // Incomplete In
                    }
                }
                //...... Incomplete In/Out [end]...................


                //....... Early Out [start]...................
                if (
                    $last && $shift &&
                    strtotime($last->punch_datetime) >= strtotime($date . ' ' . $shift->clock_out_start_time) &&
                    strtotime($last->punch_datetime) < strtotime($date . ' ' . $shiftEnd)
                ) {
                    $statusesForLog[] = 7; // Early Out
                }
                //....... Early Out [end]...................


                //...... Half-day (1st) – Un-Approved (UA) [start]...................

                // If check-in time exceeds end_check_in_time from shift table, treat as half-day (1st) un-approved
                if (
                    $first && $shift &&
                    (
                        strtotime($first->punch_datetime) > strtotime($date . ' ' . $shift->end_check_in_time) &&
                        strtotime($first->punch_datetime) < strtotime($date . ' ' . $shift->first_half_day)
                    ) &&
                    strtotime($last->punch_datetime) >= strtotime($date . ' ' . $shift->clock_out)
                ) {

                    //..... look for "LeaveApplication" table for half-day leave
                    //$has_1st_halfday_leave = $LeaveApplicationDetail->where('leave_form_type',1)->where('first_second_half',1)->contains('leave_date', $date)->exists();
                    if ($LeaveApplicationDetail && $LeaveApplicationDetail->where('first_second_half', 4)->contains('leave_date', $date)) {
                        $statusesForLog[] = 4; // half day(1st) (A)
                    } else {
                        $statusesForLog[] = 3; // half day(1st) (UA)
                    }

                    $has_halfday_leave = true; // mark as half-day leave indicator
                }
                //...... Half-day (1st) – Un-Approved (UA) [end]...................



                //...... Half-day (2nd) – Un-Approved (UA) [start]...................
                if (
                    $has_halfday_leave == false &&
                    $last && $shift &&
                    strtotime($first->punch_datetime) > strtotime($date . ' ' . $shift->first_half_day) &&
                    (
                        strtotime($last->punch_datetime) < strtotime($date . ' ' . $shift->clock_out_start_time)
                        //strtotime($last->punch_datetime) > strtotime($date . ' ' . $shift->first_half_day) 
                    )
                ) {
                    //$has_1st_halfday_leave = $LeaveApplicationDetail->where('leave_form_type',1)->where('first_second_half',1)->contains('leave_date', $date)->exists();
                    if ($LeaveApplicationDetail && $LeaveApplicationDetail->where('first_second_half', 6)->contains('leave_date', $date)) {
                        $statusesForLog[] = 6; // half day(2nd) (A)
                    } else {
                        $statusesForLog[] = 5; // half day(2nd) (UA)
                    }
                }
                //...... Half-day (2nd) – Un-Approved (UA) [end]...................


                //.... Both half day (1st and 2nd) but present for few hours
                if (
                    (
                        $first && $shift &&
                        strtotime($first->punch_datetime) >= strtotime($date . ' ' . $shift->end_check_in_time) && // 10:31 - 3:59
                        $last &&
                        strtotime($last->punch_datetime) <= strtotime($date . ' ' . $shift->clock_out_start_time)
                    ) || ($first && $last && $shift &&
                        strtotime($first->punch_datetime) > strtotime($date . ' ' . $shift->first_half_day) && // 12:30 - 3:59 // old -> clock_out_start_time
                        strtotime($last->punch_datetime) <= strtotime($date . ' ' . $shift->clock_out_start_time)
                    ) ||
                    ($first && $last && $shift &&
                        (
                            strtotime($first->punch_datetime) >= strtotime($date . ' ' . $shift->start_check_in_time) && // 05:00 - 10:30
                            strtotime($first->punch_datetime) <= strtotime($date . ' ' . $shift->end_check_in_time)
                        ) &&
                        (
                            strtotime($last->punch_datetime) <= strtotime($date . ' ' . $shift->first_half_day)
                        )
                    ) && $leave_application_id !== null
                ) {
                    $statusesForLog[] = 0; // Absent
                    $statusesForLog[] = 15; // Absent (2 Half day ) [Present for few hours]
                }

                //...... Set status for an attendance entry [end]...................


                foreach ($statusesForLog as $st) {
                    $statusLogData[] = [
                        'employee_user_id'        => $row->employee_user_id,
                        'employee_attendance_id'  => null,
                        'leave_application_id'    => $leave_application_id, // default null
                        'attendance_status'       => $st,
                        'attendance_date'         => $date,
                        'created_user_id'         => $systemUserId,
                        'created_at'              => $now,
                    ];
                    $statusLogKeyIndex[$rowKey][] = count($statusLogData) - 1;
                }

                //...........absent_bridge [start]...................
                // todo:: yet to be implemented
                //...........absent_bridge [end].....................


                //.... is join date
                if ($date == $row->joining_date) {
                    $isJoin = 1;
                } else {
                    $isJoin = 0;
                }


                //.... OT Calculation [start]...................
                //..... Get OT Policy 
                //$otPolicy = $employeeOtPolicies[$row->employee_user_id]->where('ot_policy_date', $date)->first();
                if ($first && $last) {
                    $transferedToOTStatus = calculateOtHours($row, $otRequisition, $hasOtRequisition, $shift, $first, $last, $systemUserId);
                } else {
                    $transferedToOTStatus = false;
                }
                //.... OT Calculation [end]...................

                //...... Set status for an attendance entry [end]...................
                $prepared[] = [
                    'emp_code'                                      => $row->emp_code,
                    'employee_user_id'                             => $row->employee_user_id,
                    'department_id'                                => $row->department_id,
                    'section_id'                                   => $row->section_id,
                    'shift_id'                                     => $row->shift_id,
                    'shift_start_time'                             => $shiftStart,
                    'shift_grace_time'                             => $grace,
                    'shift_end_time'                               => $shiftEnd,
                    'date'                                         => $date,
                    'in_time'                                      => $inTime,
                    'out_date'                                     => $outDate,
                    'out_time'                                     => $outTime,
                    'on_leave_status'                              => null,
                    'transfered_to_ot'                             => ($transferedToOTStatus ? 1 : 0),
                    'is_holiday'                                   => (($publicHoliday || $empHoliday) ? 1 : 0),
                    'is_join'                                      => $isJoin,
                    'is_manual'                                    => 0,
                    /* 'is_roster'                                    => $isRosterAssigned,
                    'roster_id'                                    => $roster_id, */
                    'absent_bridge'                                => 0,
                    'source'                                       => 'biometric',
                    'is_corrected'                                 => 0,
                    'working_hours'                                 => $workingHours,
                    'employee_applied_attendance_correction_id'    => null,
                    'created_user_id'                              => $systemUserId,
                    'updated_user_id'                              => $systemUserId,
                    'deleted_user_id'                              => null,
                    'created_at'                                   => $now,
                ];
                //dd("Problem in this array only (above), other above codes are okay");
                $preparedKeys[] = $rowKey;
            }
        }

        if (!empty($prepared)) {
            DB::beginTransaction();
            try {
                foreach (array_chunk($prepared, 500) as $chunk) {
                    EmployeeAttendance::insert($chunk);
                }
                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                Log::error('ProcessTempDataJob bulk insert failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $jobEnd = date('Y-m-d H:i:s');
        $idMapByEmpDate = [];
        if (!empty($statusLogData) || !empty($PayrollAccruedAllowanceIncome) || !empty($EmployeeLeaveAchieveLog)) {
            $rows = EmployeeAttendance::where('created_user_id', $systemUserId)
                ->whereBetween('created_at', [$jobStart, $jobEnd])
                ->get(['id', 'employee_user_id', 'date', 'in_time', 'out_time', 'created_at', 'emp_code']);
            $idMap = [];
            foreach ($rows as $r) {
                $code = $r->emp_code;
                $k = $code . '|' . $r->employee_user_id . '|' . $r->date . '|' . ($r->in_time ?? '') . '|' . ($r->out_time ?? '') . '|' . $r->created_at;
                $idMap[$k] = $r->id;
                $idMapByEmpDate[$r->employee_user_id . '|' . $r->date] = $r->id;
            }
        }
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
        if (!empty($PayrollAccruedAllowanceIncome)) {
            foreach ($payrollAccruedKeyIndex as $k => $indices) {
                if (isset($idMapByEmpDate[$k])) {
                    foreach ($indices as $idx) {
                        $PayrollAccruedAllowanceIncome[$idx]['employee_attendance_id'] = $idMapByEmpDate[$k];
                    }
                }
            }
            foreach (array_chunk($PayrollAccruedAllowanceIncome, 500) as $chunk) {
                PayrollAccruedAllowanceIncome::insert($chunk);
            }
        }
        if (!empty($EmployeeLeaveAchieveLog)) {
            foreach ($leaveAchieveLogKeyIndex as $k => $indices) {
                if (isset($idMap[$k])) {
                    foreach ($indices as $idx) {
                        $EmployeeLeaveAchieveLog[$idx]['employee_attendance_id'] = $idMap[$k];
                    }
                }
            }
            foreach (array_chunk($EmployeeLeaveAchieveLog, 500) as $chunk) {
                EmployeeLeaveAchieveLog::insert($chunk);
            }
        }
    }
}
