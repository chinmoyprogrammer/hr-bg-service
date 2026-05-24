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
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendBulkSMSToMultiUserOneSMSJob extends Job implements ShouldQueue
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
        $this->queue      = 'sendBulkSMSToMultiUserOneSMS_queue';
    }

    public function handle(): void
    {
        //.....i will send array of employee_use_id and sms body. find user's official number and send sms to them.
        //... sample payload that will we sent via rabbitmq queue
        // $samplePayload = [
        //     'users'=>[195,196,197],
        //     'smsBody' => 'Hello World!',
        // ];
        // payload will be like array of user ids and sms body

        $users = $this->payload['users'];
        $smsBody = $this->payload['smsBody'];

        $usersInfo = User::with(
                                [
                                    'phoneNumbers' => function ($query)
                                    {
                                        $query->where('contact_type', 'official');
                                    }
                                ])
                                ->whereIn('id', $users)
                                ->get()
                                ->mapWithKeys(function ($user) 
                                {
                                    $officialPhone = $user->phoneNumbers->first();
                                    return [$user->id => $officialPhone ? [$officialPhone->country_code, $officialPhone->phone_number] : [null, null]];
                                })->toArray();
                                
        
        foreach ($usersInfo as $userId => $officialPhoneInfo) 
        {
            $countryCode = $officialPhoneInfo[0];
            $receipentNos = $officialPhoneInfo[1];
            //... sms api call to send sms
            $stat = sendSMS($countryCode, $receipentNos, $smsBody);
            Log::info('SendBulkSMSJob: send sms to receipent: [' . $receipentNos . '], status: [' . $stat . ']');
        }
        
    }
}