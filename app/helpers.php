<?php

use App\Helpers\ApiResponse;
use App\Models\EmployeeOtData;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;




if (!function_exists('api_success')) {
    function api_success($data = null, $message = 'Success', $code = 200, $meta = [],$requestId = null) {
        return ApiResponse::success($data, $message, $code, $meta,$requestId );
    }
}

if (!function_exists('api_error')) {
    function api_error($message = 'Error', $code = 500, $errors = [], $meta = [],$requestId = null) {
        return ApiResponse::error($message, $code, $errors, $meta,$requestId );
    }
}

if (!function_exists('formatBDNumber')) 
{
    function formatBDNumber($n) {
        $dec = strpos($n, '.') !== false ? rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.') : $n;
        return preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', preg_replace('/\d(?=(\d{3})+\.)/', '$0,', $dec));

    }
    
}

if (!function_exists('safeCollection')) 
{
    function safeCollection($model, string $relation)
    {
        return $model->relationLoaded($relation) ? ($model->{$relation} ?? collect()) : collect();
    }
}


if (!function_exists('collectionHasNested')) 
{
    function collectionHasNested($collection, callable $checker): bool
    {
        return $collection->isNotEmpty() && $collection->some($checker);
    }
}

// helper function for calculate OT hours
if (!function_exists('calculateOtHours')) 
{
    function calculateOtHours($empOfficialDataRow, $otRequisition, $hasOtRequisition, $shift, $firstPunch, $lastPunch, $userId)
    {
        $time = explode(':', $shift->lunch_meal_time);
        $lunchMealHour = $time[0] ?? 0;
        $lunchMealMinute = $time[1] ?? 0;
        $lunchMealHour = intval($lunchMealHour) +   ((int)$lunchMealMinute / 60);

        $otPolicy = $empOfficialDataRow->employeeOtPolicy;

        $minimum_ot_hours = $otPolicy?->minimum_ot_hours ?? 0;
        $maximum_ot_hours = $otPolicy?->maximum_ot_hours ?? 0;
        $shift_break_duration = $otPolicy?->shift_break_duration ?? 0;
        $special_allowance_eligibility = $otPolicy?->special_allowance_eligibility ?? 0;
        
        $workingHours = strtotime($lastPunch->punch_datetime) - strtotime($firstPunch->punch_datetime);
        $workingHours = $workingHours ?  $workingHours / 60 / 60 : 0;
        $otHours = $workingHours - $lunchMealHour - intval($shift->total_working_hours) - $shift_break_duration ;


        
        
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
                        'employee_user_id' => $empOfficialDataRow->employee_user_id,
                        'employee_ot_policy_id' => $otPolicy ? $otPolicy->id : null,
                        'ot_requisition_id' => $otRequisition ? $otRequisition->id : null,
                        'ot_rate' => $otRate,
                        'ot_hours' => $calculatedOtHours,
                        'ot_multiplier' => $otMultiplier,
                        'ot_amount' => $otAmount,
                        'is_paid' => 0,
                        'ot_payment_date' => null,
                        'ot_date' => $firstPunch->punch_datetime->format('Y-m-d'),
                        'special_allowance' => $special_allowance,
                        'created_user_id' => $userId,
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);

                    return true;
            }else{
                return false;
            }
    }
}


if (!function_exists('sendSms'))
{
    function sendSMS($countryCode, $receipentNos, $smsBody)
    {


            if ($countryCode === '+880')
            {
                $countryCode = str_replace('+', '', $countryCode);
                $last10 = substr($receipentNos, -10);
            } else {
                $last10 = $receipentNos;
            }

            $phoneNumber = $countryCode . $last10;



            $data = array('token' => 'multibrand', 'receipentNo' => $phoneNumber, 'smsContent' => $smsBody, 'hostname' => env('SMS_HOSTNAME','mbw-chinmoy-pc'));

            $URL='182.163.102.203:8086/sms_api/send_sms.php';

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL,$URL);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30); //timeout after 30 seconds
            curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
            $result=curl_exec ($ch);
            curl_close($ch);
            return $result;
    }
}

if (!function_exists('getUserId'))
{
    function getUserId()
    {
        $defaultId = (int) env('SYSTEM_USER_ID', 195);
        try {
            if (app()->bound('x_user_id')) {
                $id = app('x_user_id');
                return is_numeric($id) ? (int) $id : $id;
            }
        } catch (\Throwable $e) {
            return $defaultId;
        }
        try {
            $req = app('request');
            $h = $req ? $req->header('X-User-Id') : null;
            if ($h !== null && $h !== '') {
                return is_numeric($h) ? (int) $h : $h;
            }
        } catch (\Throwable $e) {
            return $defaultId;
        }
        try {
            if (function_exists('auth') && auth() && auth()->id()) {
                return (int) auth()->id();
            }
        } catch (\Throwable $e) {
            return $defaultId;
        }
        return $defaultId;
    }
}

// get device token
if (!function_exists('getDeviceToken'))
{
    function getDeviceToken()
    {
            // Authenticate with ZKBio to get JWT token
            $device_user_name = env('DEVICE_USER_NAME');
            $device_password = env('DEVICE_PASSWORD');
            $jwt_api_url = env('JWT_API_URL');
            

            $tokenResponse = Http::timeout(30)
                ->withOptions(['verify' => false])
                ->post($jwt_api_url, [
                    'username' => $device_user_name,
                    'password' => $device_password
                ]);

            if (!$tokenResponse->successful()) {
                Log::error('EmployeeDeactivation: Failed to get JWT token from ZKBio.', ['response' => $tokenResponse->body()]);
                return;
            }

            $token = (string) $tokenResponse->json('token');
            if ($token === '') {
                Log::error('EmployeeDeactivation: JWT token missing from ZKBio response.', ['response' => $tokenResponse->body()]);
                return;
            }
            return $token;
    }
}



// deactivate employee from device through API
if (!function_exists('deactivateEmployeeFromDevice'))
{
    function deactivateEmployeeFromDevice($id)
    {

                $resign_api_url = env('RESIGN_API_URL');
                $token = getDeviceToken();
                if ($token === null) {
                    return;
                }
                
                // 3. Deactivate user in ZKBio time
                $payload = [
                    'employee' => $id,
                    'disableatt' => true,
                    'resign_type' => 1,
                    'resign_date' => date('Y-m-d'),
                    'reason' => ''
                ];

                $resignResponse = Http::timeout(60)
                    ->retry(3, 5000)
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'Authorization' => 'JWT ' . $token
                    ])
                    ->withOptions(['verify' => false])
                    ->post($resign_api_url, $payload);

                if ($resignResponse->successful()) {
                    Log::info("EmployeeDeactivation: Successfully deactivated employee in ZKBio.", ['employee_id' => $id, 'response' => $resignResponse->body()]);
                    // Mark as updated
                } else {
                    Log::error("EmployeeDeactivation: Failed to deactivate employee in ZKBio.", ['employee_id' => $id, 'response' => $resignResponse->body()]);
                }


    }
}
