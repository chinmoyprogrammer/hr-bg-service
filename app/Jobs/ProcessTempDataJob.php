<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\EmployeeAttendanceTemp;
use App\Models\EmployeeOfficialInformation;
use App\Models\EmployeeAttendance;

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
            Log::warning('ProcessTempDataJob missing start_date or end_date', [
                'payload' => $this->payload,
            ]);
            return;
        }

        $startBoundary = (new \Carbon\Carbon($startDate))->startOfDay()->toDateTimeString();
        $endBoundary = (new \Carbon\Carbon($endDate))->endOfDay()->toDateTimeString();


        // Get array of dates from two dates provided
        $startCarbon = \Carbon\Carbon::parse($startDate);
        $endCarbon   = \Carbon\Carbon::parse($endDate);
        $dates       = $startCarbon->range($endCarbon)->map->format('Y-m-d')->toArray();

        
        $officialInfos = EmployeeOfficialInformation::with
        (
            [
                'employeeAttendanceTemps' =>function($query) use ($startBoundary, $endBoundary)
                {
                    $query->where('punch_datetime', '>=', $startBoundary)
                        ->where('punch_datetime', '<=', $endBoundary)
                        ->orderBy('punch_datetime', 'asc');   
                }
            ]
        )
        ->get();

        // Pre-load all shifts keyed by id for quick lookup inside the loop
        $shifts = \App\Models\Shift::where('effective_date', '>=', $startDate)
                     ->orderBy('effective_date', 'desc')
                     ->get()
                     ->keyBy('id');
        
        $systemUserId = (int) env('SYSTEM_USER_ID', 1);
        $prepared = [];
        $skipped = [];


            // Build one attendance record per date for this employee
            //$grouped = $temps->groupBy(fn($t) => $t->punch_datetime->format('Y-m-d'));

            foreach ($officialInfos as $row) {

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

                foreach ($dates as $date)
                {
                    //.... get employee's shift id
                    $shift_id = $row->shift_id ?? null;
                    $shift = $shifts->get($shift_id);
                    


                    //.... decide in-out time from shift start/end time
                    
                    
                    // if(strtotime($date . ' ' . $shift->clock_out_start_time) >= strtotime($first->punch_datetime))
                    // {
                    //     $first = 0;
                    //     $last  = $row->employeeAttendanceTemps->where('punch_datetime', 'like', $date . '%')->last();
                    // }else{


                    $first = $row->employeeAttendanceTemps->where('punch_datetime', 'like', $date . '%')->first();

                    if($first === $last)
                    {
                        $last  = null;
                    }else{
                        $last  = $row->employeeAttendanceTemps->where('punch_datetime', 'like', $date . '%')->last();
                    }


//........................... Night Shift [Start]............................................................................................
                    //..... over night shift (accross 2 dates) [determine checkin time]
                    if(
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
                                'status_2' => 10, // Night Duty
                            ]);
                            continue; // skip this date
                    }
//........................... Night Shift [End]............................................................................................


                    $shiftStart = $row->clock_in ?? '09:00:00';
                    $shiftEnd   = $row->clock_out   ?? '18:00:00';
                    $grace      = $shift->shift_grace_time ?? 0;

                    $workingHours = $first->punch_datetime->diffInHours($last->punch_datetime);

                    $inTime  = $first->punch_datetime->format('H:i:s');
                    $outTime = $last->punch_datetime->format('H:i:s');
                    

                    // Basic status logic (extend as needed)
                    $status    = 1; // Present
                    $status2   = 0;
                    $onLeave   = 0;

                    if (!$outTime) {
                        $status2 = 5; // Incomplete Out
                    }

                    $now = date('Y-m-d H:i:s');
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
                        'status'                                       => $status,
                        'status_2'                                     => $status2,
                        'on_leave_status'                              => $onLeave,
                        'transfered_to_ot'                             => 0,
                        'is_holiday'                                   => 0,
                        'is_join'                                      => 0,
                        'is_manual'                                    => 0,
                        'is_roster'                                    => 0,
                        'roster_id'                                    => null,
                        'absent_bridge'                                => 0,
                        'source'                                       => 'machine',
                        'is_corrected'                                 => 0,
                        'working_hours'                                 => $workingHours,
                        'employee_applied_attendance_correction_id'    => null,
                        'created_user_id'                              => $systemUserId,
                        'updated_user_id'                              => $systemUserId,
                        'deleted_user_id'                              => null,
                        'created_at'                                   => $now,
                        'updated_at'                                   => $now,
                        'deleted_by'                                   => null,
                        'deleted_at'                                   => null,
                        'child_data_identifier_key_incoming'           => $first->id,
                        'child_data_identifier_key_outgoing'           => $last->id,
                    ];
                }
            }

        foreach ($officialInfos as $row) {
            //.... process each row for employee_attendance


                //.... # in out time

                //.... # shift info
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

        Log::info('ProcessTempDataJob FFF completed', [
            'message' => $messageText,
            'counts' => [
                'temp_rows' => count($tempRows),
                'prepared' => count($prepared),
                'skipped' => count($skipped),
            ],
        ]);
    }
}