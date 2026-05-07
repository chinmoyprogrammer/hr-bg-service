<?php

namespace App\Jobs;

use App\Models\EmployeeAttendance;
use App\Models\EmployeeAttendanceStatusLog;
use App\Models\LeaveApplicationDetail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AbsentAdjustmentWithLeave extends Job implements ShouldQueue
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
        $this->queue      = 'absentAdjustmentWithLeave_queue';
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        Log::info('AbsentAdjustmentWithLeave started for LeaveApplication ID: ', ['payload:'=> $this->payload]);
        die;
        try {
            // 1. Get Leave Details (dates and employee)
            $leaveDetails = LeaveApplicationDetail::where('leave_application_id', $this->leaveApplicationId)->get();

            if ($leaveDetails->isEmpty()) {
                Log::warning('RecalculateAttendanceJob: No details found for LeaveApplication ID: ' . $this->leaveApplicationId);
                return;
            }

            $employeeUserId = $leaveDetails->first()->employee_user_id;
            $leaveDates = $leaveDetails->pluck('leave_date')->toArray();

            DB::beginTransaction();

            // 2. Find Attendance records for these dates that are currently marked as 'Absent'
            // We look for records where the status log has attendance_status = 3 (Absent)
            $attendanceRecords = EmployeeAttendance::where('employee_user_id', $employeeUserId)
                ->whereIn('date', $leaveDates)
                ->get();

            foreach ($attendanceRecords as $attendance) {

                $statusLog = EmployeeAttendanceStatusLog::where('employee_attendance_id', $attendance->id)->first();

                // If currently Absent (3), update to Leave (4)
                // Note: The status codes (3 for Absent, 4 for Leave) are assumed based on standard patterns 
                // seen in ProcessManualDataJob and common HR systems.
                if ($statusLog && $statusLog->attendance_status == 3) {
                    $statusLog->update([
                        'attendance_status' => 4, // 4 = Leave
                        'remarks' => ($statusLog->remarks ? $statusLog->remarks . ' | ' : '') . 'Status updated from Absent to Leave after leave approval.'
                    ]);

                    // Also update the main attendance record if it has a summary status column
                    // Some systems keep a redundant status on the main table for performance
                    if (isset($attendance->attendance_status)) {
                        $attendance->update(['attendance_status' => 4]);
                    }

                    Log::info("RecalculateAttendanceJob: Updated status to Leave for Employee: {$employeeUserId} on Date: {$attendance->date}");
                }
            }

            DB::commit();
            Log::info('RecalculateAttendanceJob completed successfully for LeaveApplication ID: ' . $this->leaveApplicationId);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('RecalculateAttendanceJob failed: ' . $e->getMessage());
            throw $e;
        }
    }
}
