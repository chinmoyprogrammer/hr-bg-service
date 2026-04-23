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
use App\DTOs\AttendanceDTO;

class ProcessManualDataJob extends Job implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The array payload passed directly from RabbitMQ
     */
    protected array $payload;

    public function __construct()
    {
        $this->connection = 'rabbitmq';
        $this->queue = 'processManualData_queue';
    }

    /**
     * Set the payload dynamically when invoked with a raw array payload.
     * This is useful if your queue worker is passing the raw array payload via a setter
     * or if you are resolving the payload dynamically from the raw RabbitMQ job payload.
     * 
     * If using laravel-rabbitmq, the payload is often injected directly.
     */
    public function setPayload(array $payload)
    {
        $this->payload = $payload;
    }

    /**
     * Handle the job: read temp rows within date range and insert into main attendance.
     */
    public function handle(): void
    {
        // Ensure payload contains an array of objects
        /* if (!isset($this->payload) || !is_array($this->payload)) {
            Log::warning('ProcessManualDataJob missing or invalid payload', [
                'payload' => $this->payload,
            ]);
            return;
        } */

        // Loop through each object and call AttendanceProcessingService
        foreach ($this->payload as $item) {
            /* Log::warning('test:', [
                'payload' => $item,
            ]); */
            // Instantiate and call AttendanceProcessingService with the current item
            $attendanceDTO = new AttendanceDTO(
                intval($item['employee_user_id']),
                strval($item['date']),
                strval($item['in_time']),
                strval($item['out_time']),
                'manual'
            );
            app(\App\Services\AttendanceProcessingService::class)->process($attendanceDTO);
        }
        
        

/*         $startDate = $this->payload['start_date'] ?? null;
        $endDate = $this->payload['end_date'] ?? null;
        $messageText = $this->payload['message'] ?? '';

        if (!$startDate || !$endDate) {
            Log::warning('ProcessTempDataJob missing start_date or end_date', [
                'payload' => $this->payload,
            ]);
            return;
        }
        Log::warning('ProcessTempDataJob missing start_date or end_date', [
            'payload' => $this->payload,
        ]);
        return; */


    }
}
