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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessTempDataJob extends Job implements ShouldQueue
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
            // Log::warning('ProcessTempDataJob missing start_date or end_date', [
            //     'payload' => $this->payload,
            // ]);
            return;
        }

        //... if dates not of same calender month, return
        if(date('m', strtotime($startDate)) != date('m', strtotime($endDate)) && date('Y', strtotime($startDate)) != date('Y', strtotime($endDate)))
        {
            Log::warning('ProcessTempDataJob dates not of same calender month', [
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
                fn ($date) => $date->format('Y-m-d')
            )->toArray();
            array_pop($dates);

        $officialInfos = EmployeeOfficialInformation::with
        (
            [
                'employeeAttendanceTemps' =>function($query) use ($startBoundary, $endBoundary)
                {
                    $query
                        ->whereRaw('DATE(punch_datetime) >= ?', [$startBoundary])
                        ->whereRaw('DATE(punch_datetime) <= ?', [$endBoundary])
                        ->orderBy('punch_datetime', 'asc');   
                },
                'employeeOtPolicy' => function($query) use ($startBoundary, $endBoundary)
                {
                    $query
                        ->where('effective_date', '<=', $startBoundary)
                        ->where('status', 1);
                },
                'hasLeavePolicyDetail',
                'hasLateDeductionPolicy'=>function($query) use ($startBoundary)
                {
                    $query
                        ->where('effective_date', '<=', $startBoundary)
                        ->where('status', 1);
                },
                'lateDays' => function($query) use ($startBoundary, $endBoundary)
                {
                    $query
                        ->whereBetween('attendance_date', 
                                        [ date('Y-m-01', strtotime($startBoundary)), date('Y-m-t', strtotime($endBoundary))]
                                    )
                        ->where('attendance_status', 2)
                        ;
                },
            ]
        )
        ->get();

        // Pre-load all shifts keyed by id for quick lookup inside the loop
        $shifts = \App\Models\Shift::where('effective_date', '<=', $startDate)
                     ->orderBy('effective_date', 'desc')
                     ->get()
                     ->keyBy('id');
        $LeaveApplicationDetails = LeaveApplicationDetail::where('leave_date', '>=', $startDate)
                     ->where('leave_date', '<=', $endDate)
                     ->get()
                     ->keyBy('employee_user_id');


        $publicHolidays = Holiday::where('date', '>=', $startDate)
                     ->where('date', '<=', $endDate)
                     ->whereNull('employee_user_id')
                     ->get()
                     ->keyBy('date');

        $employeeHolidaysByEmp = Holiday::where('date', '>=', $startDate)
                     ->where('date', '<=', $endDate)
                     ->whereNotNull('employee_user_id')
                     ->get()
                     ->groupBy('employee_user_id')
                     ->map(function($c){ return $c->keyBy('date'); });


        $holidayDutyRequisitions = HolidayDutyRequisition::where('date_from', '>=', $startDate)
                        ->join('holiday_duty_requisition_details', 'holiday_duty_requisitions.id', '=', 'holiday_duty_requisition_details.holiday_duty_requisition_id')
                        ->where('date_to', '<=', $endDate)
                        ->where('status','Approved')
                        ->whereNotNull('approved_at')
                        ->get()
                        ->keyBy('duty_date');



        $rosters = RosterAssignment::where('from_date', '<=', $endDate)
            ->where(function($q) use ($startDate) { $q->whereNull('to_date')->orWhere('to_date', '>=', $startDate); })
            ->whereNull('deleted_by')
            ->get()
            ->groupBy('employee_user_id');

        $rosterCatalog = \App\Models\Roster::orderBy('effective_from', 'desc')->get()->groupBy('shift_id');


        $employeeOtPolicies = EmployeeOtPolicy::where('effective_date', '<=', $startDate)->where('status', 1)
                     ->get()
                     ->keyBy('employee_user_id');

        $otRequisitions = EmployeeOtRequisition::where('ot_date_from', '<=', $endDate)
                     ->where('ot_date_to', '>=', $startDate)
                     ->get()
                     ->keyBy('employee_user_id');

        
        $systemUserId = (int) env('SYSTEM_USER_ID', 1);
        $prepared = [];
        $skipped = [];
        $statusLogData = [];
        $preparedKeys = [];
        $statusLogKeyIndex = [];


        // Build one attendance record per date for this employee
        //$grouped = $temps->groupBy(fn($t) => $t->punch_datetime->format('Y-m-d'));


        // delete data from EmployeeAttendance and EmployeeAttendanceStatusLog of $dates dates

        EmployeeAttendance::whereIn('date', $dates)->delete();
        EmployeeAttendanceStatusLog::whereIn('attendance_date', $dates)->delete();



        foreach ($officialInfos as $row) 
        {
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
            foreach ($dates as $date)
            {
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
                if($otRequisition)
                {
                    $hasOtRequisition = $otRequisition->where('from_date', '<=', $date)
                        ->where('employee_user_id', $row->employee_user_id)
                        ->where('to_date', '>=', $date)
                        ->exists(); // OT Found or Not
                }else{
                    $hasOtRequisition = false;  // No OT found
                }
                            

                //.... decide in-out time from shift start/end time


                            
                if($row->employeeAttendanceTemps->isEmpty())
                {

                    $outDate = null;
                    // $leaveApplicationDetailExists = $LeaveApplicationDetail->contains('leave_date', $date)->exists();
                    // if($leaveApplicationDetailExists)
                    // {
                    //     $leave_application_id = $LeaveApplicationDetail->first()->leave_application_id;
                    //     if($leave_application_id !== null)
                    //     {

                    //     }
                    // }
                    

                    //...... Absent/Leave [start]...................
                    if ($row->employeeAttendanceTemps->isEmpty())  
                    { //weekend = 16, holiday = 9
                        //..... check Employee On Leave or not
                        //...check on LeaveApplicationDetail table
                        //$onLeave = $LeaveApplicationDetail->where('first_second_half',3)->contains('leave_date', $date)->exists(); // checking full day or not
                        if ($LeaveApplicationDetail && $LeaveApplicationDetail->where('first_second_half',3)->contains('leave_date', $date)->exists()) {
                            $statusesForLog[] = 8; // On Leave
                        }else{

                            if($publicHoliday || $empHoliday)
                            {
                            // weekend = 16, holiday = 9
                                if ($publicHoliday) {
                                    $statusesForLog[] = 9; // Public Holiday Absent
                                } 
                                if ($empHoliday) {
                                    $statusesForLog[] = 16; // Weekend Absent (user-specific)
                                } 
                            }else {
                                $statusesForLog[] = 0; // Absent
                            }
                        }

                        //todo:: Check alternate holiday schedule for this employee and add status

                        //continue; // continue to next date

                    }
                    //...... Absent/Leave [end]..........................
                }


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
                // Ensure punch_datetime is a string for downstream usage
                if ($last && $last->punch_datetime instanceof \Carbon\Carbon) {
                    $last->punch_datetime = $last->punch_datetime->toDateTimeString();
                }
                                
                if($first && $first->punch_datetime === $last->punch_datetime)
                {
                    $last  = null; //.... if first punch and last punch is same then set last as null
                }

                //     strtotime($date . ' ' . $shift->start_check_in_time),
                //     strtotime($first->punch_datetime));

                //................................ Speccial over night checkout for normal shift duty [start] .................................................
                if(
                    $first && $shift->is_overnight == 0 && $first->punch_datetime &&
                    strtotime($date . ' ' . $shift->start_check_in_time) > strtotime($first->punch_datetime->format('Y-m-d H:i:s'))
                )
                {

                    //... update previous day's checkout date & time
                    $employeeAttendance = EmployeeAttendance::where('employee_user_id', $row->employee_user_id)
                        ->where('date', date('Y-m-d', strtotime($date." -1 day")))
                        ->whereNotNull('in_time')
                        ->whereNull('out_time')
                        ->whereNull('out_date')
                        ->update([
                            'out_time' => date('H:i:s', strtotime($last->punch_datetime)),
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

                        // //.... update Employee Attendance Status Log data
                        // EmployeeAttendanceStatusLog::where('employee_user_id', $row->employee_user_id)
                        //     ->where('employee_attendance_id', $employeeAttendance->id)
                        //     ->where('created_at', $now)
                        //     ->where('created_user_id', $systemUserId) // Night Duty
                        //     ->update([
                        //         'attendance_status' => 12, // Night Duty
                        //     ]);

                        continue; // skip this date

                }
                //................................ Speccial over night checkout for normal shift duty [end] .................................................



                //........................... Night Shift [Start]............................................................................................
                    //..... over night shift (accross 2 dates) [determine checkin time]
                    if (
                        $first && $shift->is_overnight == 1 && 
                        strtotime($date . ' ' . $shift->start_check_in_time) >= strtotime($first->punch_datetime) 
                        //&& strtotime($date . ' ' . $shift->end_check_in_time) <= strtotime($last->punch_datetime)
                    )
                    {
                        $last  = null; // will be adjusted next day
                        $outDate = null; // will be adjusted next day
                        $outTime = null; // will be adjusted next day
                    }else if($last){
                        $outDate = $last->punch_datetime->format('Y-m-d');
                        $outTime = $last->punch_datetime->format('H:i:s');
                    }
                    //..... For "overnight" shifting duty, on next day update the "Checkout time" of previous day to in " employee_attendance" table
                    if(
                        $shift->is_overnight == 1 && 
                        (
                            strtotime($last->punch_datetime) >= strtotime($date . ' ' . $shift->clock_out ." - 4 hours") ||
                            strtotime($last->punch_datetime) >= strtotime($date . ' ' . $shift->clock_out)
                            )
                            )
                            {
                                //... update previous day's checkout date & time
                                EmployeeAttendance::where('employee_user_id', $row->employee_user_id)
                                ->where('date', date('Y-m-d', strtotime($date." -1 day")))
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
                                
                                PayrollAccruedAllowanceIncome::create([
                                    'employee_user_id' => $row->employee_user_id,
                                    'amount' => $amount,
                                    'type' => 6, // Night Duty Allowance // business_settings -> settings_key(PAYROLL_ACCRUED_ALLOWANCE_INCOME_TYPE) = 6
                                    'month' => date('m'),
                                    'year' => date('Y'),
                                    'date' => $date,
                                    'created_user_id' => $systemUserId,
                                    'created_at' => $now,
                                ]);

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
                            //........................... Night Shift [End]............................................................................................
                            
                            
                            $shiftStart = $row->clock_in ?? '09:00:00';
                            $shiftEnd   = $row->clock_out   ?? '18:00:00';
                            $grace      = $shift->shift_grace_time ?? 0;
                            
                            $workingHours = $first && $last ? ($first->punch_datetime->diffInHours($last->punch_datetime) - $shift->lunch_meal_hour) : 0;
                            
                            $inTime  = $first && $first->punch_datetime ? $first->punch_datetime->format('H:i:s') : null;
                            $outTime = $last && $last->punch_datetime ? $last->punch_datetime->format('H:i:s') : null;  
                            
                            $empCode = $row->emp_code;
                            $rowKey = $empCode . '|' . $row->employee_user_id . '|' . $date . '|' . $inTime . '|' . ($outTime ?? '') . '|' . $now;
                            
                            
                            
                            //...... Set status for an attendance entry [start]...................
                            
                            $businessSettings = Cache::get('all_business_settings'); 
                            
                            
                            
                            // build multiple statuses for this attendance row
                            
                            if ($first && 
                                strtotime($first->punch_datetime) <= strtotime($date . ' ' . $shiftStart ." + $grace minutes") && 
                                strtotime($first->punch_datetime) >= strtotime($date . ' ' . $shift->start_check_in_time)
                                )  {
                                    $statusesForLog[] = 1; // Present
                                }
                                if ($first && strtotime($first->punch_datetime) > strtotime($date . ' ' . $shiftStart ." + $grace minutes")) {
                                    $statusesForLog[] = 2; // Late

                                    //..... calculate late for 4 days or according to policy
                                    // if crosses the 4 days or according to  late_deduction_policies  table then insert into late_attendance_records  and late_attendance_records _details table
                                    $lateDeductionPolicy = $row->hasLateDeductionPolicy;
                                    if($lateDeductionPolicy)
                                    {

                                        // for deduction basis -> "Day"
                                        if($lateDeductionPolicy->deduction_basis == "Day" && ( ($row->lateDays->count()+1) % ($lateDeductionPolicy->max_late_days+1) == 0 ) ) 
                                        {
                                            //... first check is there any any entry exists for this month for this employee or not
                                            $lateAttendanceRecord = LateAttendanceRecord::with('lateAttendanceRecordDetails')
                                            ->where('employee_user_id', $row->employee_user_id)
                                            ->where('month',  date('m', strtotime($date)))
                                            ->where('year',  date('Y', strtotime($date)))
                                            ->first();

                                            // LateAttendanceRecord::with('lateAttendanceRecordDetails')
                                            // ->where('employee_user_id', $row->employee_user_id)
                                            // ->where('month',  date('m', strtotime($date)))
                                            // ->where('year',  date('Y', strtotime($date)))
                                            // ->delete();
                                            
                                            $recordIds = LateAttendanceRecord::where('employee_user_id', $row->employee_user_id)
                                            ->where('month', date('m', strtotime($date)))
                                            ->where('year', date('Y', strtotime($date)))
                                            ->pluck('id');
                                            
                                            // delete previous late deduction data of the month of searching date from  late_attendance_records and late_attendance_record_details table
                                            if ($recordIds->isNotEmpty()) 
                                            {
                                                LateAttendanceRecordDetail::whereIn('late_attendance_record_id', $recordIds)->delete();
                                                LateAttendanceRecord::whereIn('id', $recordIds)->delete();
                                            }






                                            // $lateAttendanceRecord = LateAttendanceRecord::create([
                                            //     'employee_user_id' => $row->employee_user_id,
                                            //     'month' => date('m', strtotime($date)),
                                            //     'year' => date('Y', strtotime($date)),
                                            //     'total_late_days' => $row->lateDays->count()+1,
                                            //     'total_late_hours' => $row->lateHours,
                                            // ]);
                                            //.... insert 

                                            $lateCount = $row->lateDays->count() + 1;
                                            $cycle = $lateDeductionPolicy->max_late_days + 1;
                                            $rowsToInsert = $lateCount / $cycle;

                                            for ($i = 0; $i < $rowsToInsert; $i++) {

                                                $lateRecord = LateAttendanceRecord::create([
                                                    'employee_user_id' => $row->employee_user_id,
                                                    'month'            => date('m', strtotime($date)),
                                                    'year'             => date('Y', strtotime($date)),
                                                    'created_user_id'  => $systemUserId,
                                                    'created_at'       => $now,
                                                ]);  

                                                // Insert first 5 late dates into LateAttendanceRecordDetail for this cycle
                                                for ($j = 0; $j < $cycle && ($i * $cycle + $j) < $lateCount; $j++) 
                                                {
                                                    LateAttendanceRecordDetail::create([
                                                        'late_attendance_record_id' => $lateRecord->id,
                                                        'date' => $row->lateDays->sortBy('date')->values()[$i * $cycle + $j]->date,
                                                        'late_hours' => $row->lateHours,
                                                    ]);
                                                }
                                            }

                                            if ($rowsToInsert === 0) {
                                                // // collect the last $cycle late days for this employee
                                                // $lateAttendances = EmployeeAttendance::select('employee_attendance.*')
                                                //     ->join('employee_attendance_status_logs as log', function ($join) {
                                                //         $join->on('log.employee_attendance_id', '=', 'employee_attendance.id')
                                                //             ->where('log.attendance_status', 2); // Late
                                                //     })
                                                //     ->where('employee_attendance.employee_user_id', $row->employee_user_id)
                                                //     ->whereBetween('employee_attendance.date', [
                                                //         now()->startOfMonth()->toDateString(),
                                                //         now()->endOfMonth()->toDateString()
                                                //     ])
                                                //     ->orderByDesc('employee_attendance.date')
                                                //     ->limit($cycle)
                                                //     ->get();

                                                // create late_attendance_records header
                                                $lateRecord = LateAttendanceRecord::create([
                                                    'employee_user_id' => $row->employee_user_id,
                                                    'month'            => date('m', strtotime($date)),
                                                    'year'             => date('Y', strtotime($date)),
                                                    'created_user_id'  => $systemUserId,
                                                    'created_at'       => $now,
                                                ]);

                                                // insert each late day into late_attendance_record_details
                                                foreach ($row->lateDays as $late) {
                                                    LateAttendanceRecordDetail::create([
                                                        'late_attendance_record_id' => $lateRecord->id,
                                                        'attendance_date'           => $late->date,
                                                        'created_user_id'           => $systemUserId,
                                                        'created_at'                => $now,
                                                    ]);
                                                }
                                            }

                                            
                                        }
                                    }


                                }
                                
                                
                                //..... Holiday Duty [start].........................
                                // todo ::

                                // if has requisition and completed in-out then apply their supplimentery leave balance/ cash incentive etc
                                // if holiday_types.id = 10 then 1 compensetory leave + cash or 2 compensetory leave
                                $anyHoliday = ($publicHoliday || $empHoliday);
                                $holidayDutyRequisition = $holidayDutyRequisitions
                                                        ->get($date)
                                                        ->where('requested_by_user_id ', $row->employee_user_id)
                                                        ->exists();
                                if ($anyHoliday && $row->employeeAttendanceTemps->count() > 0)
                                    {
                                        if($empHoliday)
                                        {
                                            $statusesForLog[] = 20; // Weekend  Duty
                                        }else{
                                            $statusesForLog[] = 21; // Public Holiday Duty
                                        }
                                        
                                        $statusesForLog[] = 14; // Holiday Duty
                                    }

                                if($holidayDutyRequisition)
                                {
                                    // todo :: some times for fastival holiday, employee gets compensetory leave and cash incentive or 2 compensetory leave
                                    $stat = EmployeeLeaveBalance::where('employee_user_id', $row->employee_user_id)
                                    ->where('leave_head_id', 5) // compensation leave type
                                    ->where('fiscal_year', date('Y'))
                                    ->increment('achived_this_year', 1);

                                    $leave_validity = date('Y-m-d', strtotime($date . ' + ' . $row->hasLeavePolicyDetail->where('leave_head_id', 5)->first()->leave_avail_validity_days . ' days'));
                                    //...... record every single leave acchived after designated adding leaves
                                    EmployeeLeaveAchieveLog::create([
                                        'leave_head_id'            => 5, // compensation leave type
                                        'validity_date'            => $leave_validity,
                                        'employee_attendance_id'   => $employeeAttendance->id,
                                        'leave_count'              => 1,
                                        'leave_final_destination'  => 'Compensatory leave earned from Festival Holiday duty',
                                        'created_user_id'          => $systemUserId,
                                        'created_at'               => $now,
                                    ]);
                                   // $statusesForLog[] = 22; // Leave Compensated

                                    if($publicHoliday->holiday_type_id == 10) // leave type is "Festival Holiday", then provide money
                                    {
                                        $grossSalary = $row->gross_salary; 
                                        //todo:: calculate by OT Policy. 
                                        // calculate using monthly hrs or day of months
                                        $amount = ($grossSalary / date('t')) * $row->employeeOtPolicy->multiplier * 2; // salary of 1 day
                                        
                                        PayrollAccruedAllowanceIncome::create([
                                            'employee_user_id' => $row->employee_user_id,
                                            'amount' => $amount,
                                            'type' => 8, // Festival Duty // business_settings -> settings_key(PAYROLL_ACCRUED_ALLOWANCE_INCOME_TYPE) = 8
                                            'month' => date('m'),
                                            'year' => date('Y'),
                                            'date' => $date,
                                            'created_user_id' => $systemUserId,
                                            'created_at' => $now,
                                        ]);
                                    }

                                    if($stat)
                                    {
                                     $statusesForLog[] = 18; // Compensation Leave Earned
                                    }

                                    //
                                }else{
                                     $statusesForLog[] = 19; // Unauthorized Holiday Duty
                                }
                                //..... Holiday Duty [end]...........................
                                    
                                    
                                    
                                    //...... Incomplete In/Out [start]...................
                                    if ($row->employeeAttendanceTemps->count() == 1) 
                                        { 
                                            // compare with shift in/out time
                                            if(
                                                strtotime($first->punch_datetime) >= strtotime($date . ' ' . $shift->start_check_in_time) &&
                                                strtotime($first->punch_datetime) <= strtotime($date . ' ' . $shift->first_half_day)
                                                )
                                                {
                                                    $statusesForLog[] = 11; // Incomplete Out
                                                }else{
                                                    $statusesForLog[] = 10; // Incomplete In
                                                }
                                        }
                                    //...... Incomplete In/Out [end]...................
                                            
                                            
                                            //....... Early Out [start]...................
                                            if ( $last &&
                                                strtotime($last->punch_datetime) >= strtotime($date . ' ' . $shift->clock_out_start_time ) && 
                                                strtotime($last->punch_datetime) < strtotime($date . ' ' . $shiftEnd)
                                                )
                                                {
                                                    $statusesForLog[] = 7; // Early Out
                                                }
                                                //....... Early Out [end]...................
                                                
                                                
                                                //...... Half-day (1st) – Un-Approved (UA) [start]...................
                                                
                                                // If check-in time exceeds end_check_in_time from shift table, treat as half-day (1st) un-approved
                                                if 
                                                (
                                                    $first &&
                                                    (
                                                        strtotime($first->punch_datetime) > strtotime($date . ' ' . $shift->end_check_in_time ) && 
                                                        strtotime($first->punch_datetime) < strtotime($date . ' ' . $shift->first_half_day ) 
                                                    ) && 
                                                    strtotime($last->punch_datetime) >= strtotime($date . ' ' . $shift->clock_out)
                                                )
                                                {
                                                    
                                                    //..... look for "LeaveApplication" table for half-day leave
                                                    //$has_1st_halfday_leave = $LeaveApplicationDetail->where('leave_form_type',1)->where('first_second_half',1)->contains('leave_date', $date)->exists();
                                                    if($LeaveApplicationDetail && $LeaveApplicationDetail->where('leave_form_type',1)->where('first_second_half',1)->contains('leave_date', $date)->exists())
                                                        {
                                                            $statusesForLog[] = 4; // half day(1st) (A)
                                                        }else{
                                                            $statusesForLog[] = 3; // half day(1st) (UA)
                                                        }
                                                        
                                                        $has_halfday_leave = true; // mark as half-day leave indicator
                                                }
                                                            //...... Half-day (1st) – Un-Approved (UA) [end]...................
                                                            
                                                            
                                                            
                                                            //...... Half-day (2nd) – Un-Approved (UA) [start]...................
                                                            if (
                                                                $has_halfday_leave == false &&
                                                                $last &&
                                                                strtotime($first->punch_datetime) < strtotime($date . ' ' . $shift->first_half_day ) &&
                                                                (
                                                                    strtotime($last->punch_datetime) < strtotime($date . ' ' . $shift->clock_out_start_time)
                                                                    //strtotime($last->punch_datetime) > strtotime($date . ' ' . $shift->first_half_day) 
                                                                )
                                                               ) 
                                                            {
                                                                //$has_1st_halfday_leave = $LeaveApplicationDetail->where('leave_form_type',1)->where('first_second_half',1)->contains('leave_date', $date)->exists();
                                                                if($LeaveApplicationDetail && $LeaveApplicationDetail->where('leave_form_type',1)->where('first_second_half',1)->contains('leave_date', $date)->exists())
                                                                    {
                                                                        $statusesForLog[] = 6; // half day(2nd) (A)
                                                                    }else{
                                                                        $statusesForLog[] = 5; // half day(2nd) (UA)
                                                                    }
                                                            }
                                                            //...... Half-day (2nd) – Un-Approved (UA) [end]...................
                                                                        
                                                                        
                                                            //.... Both half day (1st and 2nd) but present for few hours
                                                            if
                                                            (
                                                                (
                                                                $first &&
                                                                strtotime($first->punch_datetime) >= strtotime($date . ' ' . $shift->end_check_in_time ) && // 10:31 - 3:59
                                                                $last &&
                                                                strtotime($last->punch_datetime) <= strtotime($date . ' ' . $shift->clock_out_start_time)
                                                                ) || ($first && $last &&
                                                                    strtotime($first->punch_datetime) > strtotime($date . ' ' . $shift->first_half_day ) && // 12:30 - 3:59 // old -> clock_out_start_time
                                                                    strtotime($last->punch_datetime) <= strtotime($date . ' ' . $shift->clock_out_start_time)
                                                                ) ||
                                                                ( $first && $last &&
                                                                    (
                                                                        strtotime($first->punch_datetime) >= strtotime($date . ' ' . $shift->start_check_in_time  ) && // 05:00 - 10:30
                                                                        strtotime($first->punch_datetime) <= strtotime($date . ' ' . $shift->end_check_in_time  )
                                                                    ) &&
                                                                    (
                                                                        strtotime($last->punch_datetime) <= strtotime($date . ' ' . $shift->first_half_day)
                                                                    )
                                                                ) && $leave_application_id !== null
                                                            )
                                                            {
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
                                        if($date == $row->joining_date)
                                            {
                                                $isJoin = 1;
                                            }else{
                                                $isJoin = 0;
                                            }
                                            
                                            
                                            //.... is roster date
                                            if($date == $row->roster_date)
                                                {
                                                    $isRoster = 1;
                                                }else{
                                                    $isRoster = 0;
                                                }
                                                
                                                
                                                $employeeAssignments = $roasters[$row->employee_user_id] ?? collect();
                                                $assignmentForDate = $employeeAssignments->first(function ($a) use ($date) {
                                                    return ($a->from_date <= $date) && (is_null($a->to_date) || $a->to_date >= $date);
                                                });
                                                if ($assignmentForDate) {
                                                    $isRosterAssigned = 1;
                                                    $roster_id = $assignmentForDate->roster_id;
                                                } else {
                                                    $isRosterAssigned = 0;
                                                    $fallbackByShift = $rosterCatalog[$row->shift_id] ?? collect();
                                                    $effectiveRoster = $fallbackByShift->first(function ($r) use ($date) {
                                                        return ($r->effective_from <= $date) && (is_null($r->effective_to) || $r->effective_to >= $date);
                                                    }) ?: $fallbackByShift->first();
                                                    if ($effectiveRoster) {
                                                        $roster_id = $effectiveRoster->id;
                                                    } else {
                                                        continue;
                                                    }
                                                }
                                                    
                                                    
                                                    //.... OT Calculation [start]...................
                                                    //..... Get OT Policy 
                                                    //$otPolicy = $employeeOtPolicies[$row->employee_user_id]->where('ot_policy_date', $date)->first();
                                                    if($first && $last)
                                                    {
                                                        $transferedToOTStatus= calculateOtHours($row, $otRequisition, $hasOtRequisition, $shift, $first, $last, $systemUserId);
                                                    }else{
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
                                                        'is_roster'                                    => $isRosterAssigned,
                                                        'roster_id'                                    => $roster_id,
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

            Log::warning('data prepared', [
                'payload' => $prepared,
            ]);

        foreach ($officialInfos as $row) 
        {
            //.... process each row for employee_attendance


                //.... # in out time

                //.... # shift info // done
                //.... # status (0=Absent,1=Present,2=Late,3=Late Permitted Present,4=Holiday Absent,8=On Leave)
                //.... # Status2 (5=Incomplete In,6=Incomplete Out,7=On Process,9=Early Out,10=Night Duty)
                //.... # leave status // done
                //.... # OT Calculation // done
                //.... # Holiday // done
                //.... # join info // done
                //.... # manual punch
                //.... # roster info // done
                //.... # absent & leave bridge 
                //.... # source ( machine manual)
                //.... # ccorrected or not
                //.... # employee_applied_attendance_correction_id

        }

        if (!empty($prepared)) 
        {
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
        if (!empty($statusLogData)) {
            $rows = EmployeeAttendance::where('created_user_id', $systemUserId)
                ->whereBetween('created_at', [$jobStart, $jobEnd])
                ->get(['id','employee_user_id','date','in_time','out_time','created_at','emp_code']);
            $idMap = [];
            foreach ($rows as $r) {
                $code = $r->emp_code;
                $k = $code . '|' . $r->employee_user_id . '|' . $r->date . '|' . ($r->in_time ?? '') . '|' . ($r->out_time ?? '') . '|' . $r->created_at;
                $idMap[$k] = $r->id;
            }
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

        // Log::info('ProcessTempDataJob FFF completed', [
        //     'message' => $messageText,
        //     'counts' => [
        //         'temp_rows' => count($tempRows),
        //         'prepared' => count($prepared),
        //         'skipped' => count($skipped),
        //     ],
        // ]);
    }
}
