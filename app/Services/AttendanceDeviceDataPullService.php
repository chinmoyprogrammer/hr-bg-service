<?php
namespace App\Services;
use App\Models\EmployeeAttendanceTemp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;


class AttendanceDeviceDataPullService
{
    public function __construct()
    {
        //
    }

    // process method
    public function process(array $dates, array $emp_codes = [])
    {
            $startDate = $dates[0] ?? null;
            $endDate   = $dates[1]   ?? null;


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
            //dd($records);
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
                Log::warning('ProcessTempDataJob: no data to insert', ['dates' => $dates]);
                return;
            }
            //...............end processing temp data...............

    }

}