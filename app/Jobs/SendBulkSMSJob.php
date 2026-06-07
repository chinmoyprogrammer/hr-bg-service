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
use App\Models\PayrollAccruedAllowanceIncome;
use App\Models\Shift;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendBulkSMSJob extends Job implements ShouldQueue
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
        $this->queue      = 'sendBulkSMS_queue';
    }

    public function handle(): void
    {
        //..... i need to send array of country code, receipent nos, sms body
        //... generate a sample payload that will we sent via rabbitmq queue
        // $samplePayload = [
        //     ['countryCode' => '+880', 'receipentNos' => '1701234567890', 'smsBody' => 'Hello World!'],
        //     ['countryCode' => '+880', 'receipentNos' => '1711234567890', 'smsBody' => 'Hello World!'],
        // ];
        //send sms to each receipent
        foreach ($this->payload as $item) {
            $countryCode = $item['countryCode'];
            $receipentNos = $item['receipentNos'];
            $smsBody = $item['smsBody'];
            //... sms api call to send sms
            $stat = sendSMS($countryCode, $receipentNos, $smsBody);
            Log::info('SendBulkSMSJob: send sms to receipent: [' . $receipentNos . '], status: [' . $stat . ']');
        }
    }
}