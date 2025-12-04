<?php

namespace App\Jobs;

use App\Models\EmployeeAttendance;
use App\Models\EmployeeAttendanceStatusLog;
use App\Models\EmployeeAttendanceTemp;
use App\Models\EmployeeOfficialInformation;
use App\Models\EmployeeOtData;
use App\Models\EmployeeOtPolicy;
use App\Models\EmployeeOtRequisition;
use App\Models\Holiday;
use App\Models\LeaveApplicationDetail;
use App\Models\RosterAssignment;
use App\Models\UserStatusNSettings;
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

            //         Log::warning('PPPPPPPPPPPPPPPPPPPPPPPPPPPPP', [
            //     'payload' => $this->payload,
            // ]);
            //dd('ff');

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
                }
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


        $holidays = Holiday::where('date', '>=', $startDate)
                     ->where('date', '<=', $endDate)
                     ->get()
                     ->keyBy('date');



        $roasters = RosterAssignment::where('from_date', '<=', $endDate)
            ->where('to_date', '>=', $startDate)
            ->whereNull('deleted_by')
            ->get()
            ->keyBy('employee_user_id');


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
        // dd($officialInfos);

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
                $holiday = $holidays->get($date);
                $has_halfday_leave = false;
                $leave_application_id = null;
                $statusesForLog = [];
                        

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
                    // remove duplicate rows based on punch datetime
                    $row->employeeAttendanceTemps = $row->employeeAttendanceTemps->unique('punch_datetime');
                    
                    

                    $leaveApplicationDetailExists = $LeaveApplicationDetail->contains('leave_date', $date)->exists();
                    if($leaveApplicationDetailExists)
                    {
                        $leave_application_id = $LeaveApplicationDetail->first()->leave_application_id;
                        if($leave_application_id !== null)
                        {

                        }
                    }
                    

                    //...... Absent/Leave [start]...................
                    if ($row->employeeAttendanceTemps->isEmpty()) 
                    {
                        //..... check Employee On Leave or not
                        //...check on LeaveApplicationDetail table
                        $onLeave = $LeaveApplicationDetail->where('first_second_half',3)->contains('leave_date', $date)->exists(); // checking full day or not
                        if ($onLeave) {
                            $statusesForLog[] = 8; // On Leave
                        }else{
                            
                            if ($holiday) 
                            {
                                $statusesForLog[] = 9; // Holiday Absent
                            }else{
                                $statusesForLog[] = 0; // Absent
                            }
                        }

                        //todo:: Check alternate holiday schedule for this employee and add status

                        continue; // continue to next date

                    }
                    //...... Absent/Leave [end]..........................
                }

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
                                
                if($first->punch_datetime === $last->punch_datetime)
                {
                    $last  = null; //.... if first punch and last punch is same then set last as null
                }
                // dd("XXXXXXXXXXXXXXXXXXXXXXXXXXX",
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

                        //.... update Employee Attendance Status Log data
                        EmployeeAttendanceStatusLog::where('employee_user_id', $row->employee_user_id)
                            ->where('employee_attendance_id', $employeeAttendance->id)
                            ->where('created_at', $now)
                            ->where('created_user_id', $systemUserId) // Night Duty
                            ->update([
                                'attendance_status' => 12, // Night Duty
                            ]);

                        continue; // skip this date

                }
                //................................ Speccial over night checkout for normal shift duty [end] .................................................



                //........................... Night Shift [Start]............................................................................................
                    //..... over night shift (accross 2 dates) [determine checkin time]
                    if (
                        $shift->is_overnight == 1 && 
                        strtotime($date . ' ' . $shift->start_check_in_time) >= strtotime($first->punch_datetime) && 
                        strtotime($date . ' ' . $shift->end_check_in_time) <= strtotime($last->punch_datetime)
                    )
                    {
                        $last  = null; // will be adjusted next day
                        $outDate = null; // will be adjusted next day
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
                                ->whereNull('out_time')
                                ->whereNull('out_date')
                                ->update([
                                    'out_time' => date('H:i:s', strtotime($last->punch_datetime)),
                                    'out_date' => $date,
                                ]);
                                
                                //.... update Employee Attendance Status Log data
                                EmployeeAttendanceStatusLog::where('employee_user_id', $row->employee_user_id)
                                ->where('employee_attendance_id', $employeeAttendance->id)
                                ->where('created_at', $now)
                                ->where('created_user_id', $systemUserId) // Night Duty
                                ->update([
                                    'attendance_status' => 13, // Night Duty
                                ]);
                                continue; // skip this date
                            }
                            //........................... Night Shift [End]............................................................................................
                            
                            
                            $shiftStart = $row->clock_in ?? '09:00:00';
                            $shiftEnd   = $row->clock_out   ?? '18:00:00';
                            $grace      = $shift->shift_grace_time ?? 0;
                            
                            $workingHours = $first->punch_datetime->diffInHours($last->punch_datetime) - $shift->lunch_meal_hour;
                            
                            $inTime  = $first->punch_datetime->format('H:i:s');
                            $outTime = $last->punch_datetime->format('H:i:s');
                            
                            $empCode = $row->emp_code ?? $row->card_no;
                            $rowKey = $empCode . '|' . $row->employee_user_id . '|' . $date . '|' . $inTime . '|' . ($outTime ?? '') . '|' . $now;
                            
                            
                            
                            //...... Set status for an attendance entry [start]...................
                            
                            $businessSettings = Cache::get('all_business_settings'); 
                            
                            
                            
                            // build multiple statuses for this attendance row
                            
                            if (
                                strtotime($first->punch_datetime) <= strtotime($date . ' ' . $shiftStart ." + $grace minutes") && 
                                strtotime($first->punch_datetime) >= strtotime($date . ' ' . $shift->start_check_in_time)
                                )  {
                                    $statusesForLog[] = 1; // Present
                                }
                                if (strtotime($first->punch_datetime) > strtotime($date . ' ' . $shiftStart ." + $grace minutes")) {
                                    $statusesForLog[] = 2; // Late
                                }
                                
                                
                                //..... Holiday Duty [start].........................
                                if ($holiday && $row->employeeAttendanceTemps->count() > 0) 
                                    {
                                        $statusesForLog[] = 14; // Holiday Duty
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
                                            if (
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
                                                            $has_1st_halfday_leave = $LeaveApplicationDetail->where('leave_form_type',1)->where('first_second_half',1)->contains('leave_date', $date)->exists();
                                                            if($has_1st_halfday_leave)
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
                                                                        $has_1st_halfday_leave = $LeaveApplicationDetail->where('leave_form_type',1)->where('first_second_half',1)->contains('leave_date', $date)->exists();
                                                                        if($has_1st_halfday_leave)
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
                                                                                strtotime($first->punch_datetime) >= strtotime($date . ' ' . $shift->end_check_in_time ) && // 10:31 - 3:59
                        strtotime($last->punch_datetime) <= strtotime($date . ' ' . $shift->clock_out_start_time)
                        ) || 
                        (
                            strtotime($first->punch_datetime) > strtotime($date . ' ' . $shift->clock_out_start_time ) && // 12:30 - 3:59
                            strtotime($last->punch_datetime) <= strtotime($date . ' ' . $shift->clock_out_start_time)
                            ) ||
                            (
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
                                                
                                                
                                                //.... is roster assigned date
                                                if(isset($roasters[$row->employee_user_id]) && $roasters[$row->employee_user_id]->contains('roster_date', $date))
                                                    {
                                                        $isRosterAssigned = 1;
                                                        $roster_id = $roasters[$row->employee_user_id]->where('roster_date', $date)->first()->roster_id;
                                                    }else{
                                                        $isRosterAssigned = 0;
                                                        $roster_id = 0;
                                                    }
                                                    
                                                    
                                                    //.... OT Calculation [start]...................
                                                    //..... Get OT Policy 
                                                    //$otPolicy = $employeeOtPolicies[$row->employee_user_id]->where('ot_policy_date', $date)->first();
                                                    $otPolicy = $row->employeeOtPolicy;
                                                    $minimum_ot_hours = $otPolicy?->minimum_ot_hours ?? 0;
                                                    $maximum_ot_hours = $otPolicy?->maximum_ot_hours ?? 0;
                                                    $shift_break_duration = $otPolicy?->shift_break_duration ?? 0;
                                                    $special_allowance_eligibility = $otPolicy?->special_allowance_eligibility ?? 0;
                                                    
                                                    $workingHours = strtotime($last->punch_datetime) - strtotime($first->punch_datetime);
                                                    $workingHours = $workingHours / 60 / 60;
                                                    $otHours = $workingHours - $shift->lunch_meal_hour - $shift->total_working_hours - $shift_break_duration ;


                                                    
                                                    
                                                    if($hasOtRequisition)
                                                        {
                                                            // Fetch the active OT policy for this employee on the given date
                                                            //$otPolicy = $employeeOtPolicies->get($row->employee_user_id);
                                                            $otRate = $otPolicy ? $otPolicy->ot_rate : 0;
                                                            $otMultiplier = $otPolicy ? $otPolicy->ot_multiplier : 1;
                                                            
                                                            if($otHours >= $minimum_ot_hours && $otHours <= $maximum_ot_hours)
                                                                {
                                                                    $calculatedOtHours = $otHours;
                                                                }elseif($otHours > $maximum_ot_hours)
                                                                {
                                                                    $calculatedOtHours = $maximum_ot_hours;
                                                                }
                                                                // Calculate OT amount
                                                                $otAmount = $calculatedOtHours * $otRate * $otMultiplier;
                                                                
                                                                
                                                                // Calculate special allowance
                                                                $special_allowance = $special_allowance_eligibility ? 0 : 0;
                                                                
                                                                
                                                                EmployeeOtData::create([
                                                                    'employee_user_id' => $row->employee_user_id,
                                                                    'employee_ot_policy_id' => $otPolicy ? $otPolicy->id : null,
                                                                    'ot_requisition_id' => $otRequisition->id,
                                                                    'ot_rate' => $otRate,
                                                                    'ot_hours' => $calculatedOtHours,
                                                                    'ot_multiplier' => $otMultiplier,
                                                                    'ot_amount' => $otAmount,
                                                                    'is_paid' => 0,
                                                                    'ot_payment_date' => null,
                                                                    'ot_date' => $date,
                                                                    'special_allowance' => $special_allowance,
                                                                    'created_user_id' => $systemUserId,
                                                                    'created_at' => $now,
                                                                ]);
                                                            }
                                                            
                                                            //.... OT Calculation [end]...................
                                                            
                                                            
                                                            
                                                            
                                                            
                                                            
                                                            
                                                            //...... Set status for an attendance entry [end]...................
                                                            
                                                            
                                                            $prepared[] = [
                                                                'card_no'                                      => $row->card_no,
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
                                                                'on_leave_status'                              => $onLeave,
                                                                'transfered_to_ot'                             => 0, //...ektu por
                                                                'is_holiday'                                   => $holiday, 
                                                                'is_join'                                      => $isJoin,
                                                                'is_manual'                                    => 0,
                                                                'is_roster'                                    => $isRosterAssigned,
                                                                'roster_id'                                    => $roster_id,
                                                                'absent_bridge'                                => 0,
                                                                'source'                                       => 'machine',
                                                                'is_corrected'                                 => 0,
                                                                'working_hours'                                 => $workingHours,
                                                                'employee_applied_attendance_correction_id'    => null,
                                                                'created_user_id'                              => $systemUserId,
                                                                'updated_user_id'                              => $systemUserId,
                                                                'deleted_user_id'                              => null,
                                                                'created_at'                                   => $now,
                                                            ];
                                                            dd("Problem in this array only (above), other above codes are okay");
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
                ->get(['id','employee_user_id','date','in_time','out_time','created_at','emp_code','card_no']);
            $idMap = [];
            foreach ($rows as $r) {
                $code = $r->emp_code ?? $r->card_no;
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
