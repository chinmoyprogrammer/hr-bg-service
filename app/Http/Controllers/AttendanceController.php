<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessTempDataJob;
use App\Jobs\RabbitMQJob;
use App\Jobs\SmokeJob;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeAttendancePunchHistory;
use App\Models\EmployeeAttendanceTemp;
use App\Models\EmployeeOfficialInformation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Wire\AMQPTable;

class AttendanceController extends Controller
{
    public function pullRawDataFromDeviceToTempTable(Request $request)
    {
        // Log::info('pullRawDataFromDeviceToTempTable: start', [
        //     'query' => [
        //         'start_time' => $request->input('start_time'),
        //         'end_time' => $request->input('end_time'),
        //     ],
        // ]);
        // Device credentials and API endpoint
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
        $startTime = $request->input('start_time');
        $endTime   = $request->input('end_time');

        // Increase timeout to 120 seconds and add retry logic to handle transient network issues
        $attendance_data = Http::timeout(120)
            ->retry(3, 5000) // 3 retries, 5 second delay between retries
            ->withHeaders([
                'Content-Type'  => 'application/json',
                'Authorization' => 'JWT ' . $token->json('token')
            ])
            ->get($attendanceApiUrl, [
                'start_time' => $startTime,
                'end_time'   => $endTime,
                'page'       => 1,
                'page_size'  => 50000,
                'departments' => 1,
                'areas' => [2,3],
            ]);

        /*
            sample : output of $attendance_data ->
                {
                "count": 1027,
                "next": null,
                "previous": null,
                "msg": "",
                "code": 0,
                "data": [
                    {
                    "id": 474078,
                    "emp_code": "0005",
                    "first_name": "Md. Rajaul Karim",
                    "last_name": null,
                    "nick_name": "",
                    "gender": "Male",
                    "dept_code": "1",
                    "dept_name": "Department",
                    "position_code": null,
                    "position_name": null,
                    "work_code": "0",
                    "att_date": "2025-11-19",
                    "work_code_alias": "0",
                    "punch_time": "08:49",
                    "punch_state": "Check In",
                    "verify_type": "Face",
                    "source": "Device"
                    },
                    ....
                    ]

        */

        // Return list of attendances
        //return $attendance_data->json();

        //insert data to temp table
        $responseJson = $attendance_data->json();

        $records = [];
        if (is_array($responseJson)) {
            $records = $responseJson['data'] ?? $responseJson; // handle both wrapped and raw arrays
        }

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
                'emp_code' => $empCode,
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
            //EmployeeAttendanceTemp::truncate();
            
            //...Insert into temp table
            //EmployeeAttendanceTemp::insert($insert_data);

            //.... Punch history insert
            // Build a single INSERT ... ON DUPLICATE KEY UPDATE statement so duplicates are silently skipped
            /* 
            ------------- Punch history insert ------------- 
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

            DB::insert($sql, $bindings); */
            // Log::info('pullRawDataFromDeviceToTempTable: temp insert done', [
            //     'inserted' => count($insert_data),
            // ]);
            

        $payload = [
            'message' => "HHH",
            'start_date' => $startTime,
            'end_date' => $endTime,
        ];

        $queueName = 'processTempData_queue';
        // Dispatch a proper queued job that a RabbitMQ worker can consume automatically
        dispatch((new ProcessTempDataJob($payload))
            ->onQueue($queueName)
            ->onConnection('rabbitmq'));
        // Log::info('pullRawDataFromDeviceToTempTable: dispatched ProcessTempDataJob', [
        //     'queue' => $queueName,
        //     'payload' => $payload,
        // ]);            


        }else{
            dd('No data found',$insert_data,$records);
        }

            



    }

    /**
     * Consume one message from RabbitMQ queue 'processTempData'.
     * Useful for testing/triggered consumption via HTTP.
     */
    
    /*
    public function consumeProcessTempData(Request $request)
    {
        Log::info('consumeProcessTempData invoked');
        $queueName = 'processTempData_queue';

        // RabbitMQ connection details from env
        $host = env('RABBITMQ_HOST', '127.0.0.1');
        $port = (int) env('RABBITMQ_PORT', 5672);
        $user = env('RABBITMQ_USER', 'guest');
        $pass = env('RABBITMQ_PASSWORD', 'guest');
        $vhost = env('RABBITMQ_VHOST', '/');

        $connection = new AMQPStreamConnection($host, $port, $user, $pass, $vhost);
        $channel = $connection->channel();

        // Ensure the queue exists; durable so it matches common setup
        $channel->queue_declare($queueName, false, true, false, false);

        // Bind queue to the default exchange used by the Laravel RabbitMQ driver
        $exchange = env('RABBITMQ_EXCHANGE', 'amq.direct');
        try {
            $channel->queue_bind($queueName, $exchange, $queueName);
        } catch (\Throwable $e) {
            // binding can fail if exchange missing; continue so basic_get still works when messages are directly routed
            Log::warning('Queue bind failed: '.$e->getMessage());
        }

        // Fetch a single message (auto-acknowledge)
        $msg = $channel->basic_get($queueName, true);
        if ($msg) {
            Log::info('Consumed message: ' . $msg->getBody());
        } else {
            Log::info('consumeProcessTempData: queue empty for '.$queueName);
        }
        $result = null;
        if ($msg === null) {
            $result = [
                'status' => 'empty',
                'queue' => $queueName,
                'message' => null,
            ];
        } else {
            $body = $msg->getBody();
            $decoded = null;
            try {
                $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable $e) {
                $decoded = null;
            }

            // Validate payload
            $startDate = $decoded['start_date'] ?? null;
            $endDate = $decoded['end_date'] ?? null;
            $messageText = $decoded['message'] ?? '';

            if (!$startDate || !$endDate) {
                $result = [
                    'status' => 'error',
                    'queue' => $queueName,
                    'message_raw' => $body,
                    'error' => 'Missing start_date or end_date in message payload.',
                ];
            } else {
                // Normalize date boundaries
                $startBoundary = (new \Carbon\Carbon($startDate))->startOfDay()->toDateTimeString();
                $endBoundary = (new \Carbon\Carbon($endDate))->endOfDay()->toDateTimeString();

                // Fetch temp rows within range
                $tempRows = EmployeeAttendanceTemp::query()
                    ->where('punch_datetime', '>=', $startBoundary)
                    ->where('punch_datetime', '<=', $endBoundary)
                    ->get();

                $systemUserId = (int) env('SYSTEM_USER_ID', 1);
                $prepared = [];
                $skipped = [];

                foreach ($tempRows as $row) {
                    // Resolve employee_user_id by emp_id (matched from device emp_code)
                    $employeeUserId = EmployeeOfficialInformation::query()
                        ->where('emp_id', $row->emp_code)
                        ->value('employee_user_id');

                    // Choose base date/time from punch_datetime (temp table has single punch entries)
                    $dt = new \Carbon\Carbon($row->punch_datetime);
                    $dateStr = $dt->toDateString();
                    $inTime = $dt->format('H:i:s');
                    $outDate = null;
                    $outTime = null;

                    // Find roster_id for employee on date
                    $rosterId = null;
                    if ($employeeUserId) {
                        $rosterId = DB::table('roster_assignments')
                            ->where('employee_user_id', $employeeUserId)
                            ->where('from_date', '<=', $dateStr)
                            ->where(function($q) use ($dateStr) {
                                $q->where('to_date', '>=', $dateStr)
                                  ->orWhereNull('to_date');
                            })
                            ->value('roster_id');
                    }

                    // Skip insert if mandatory roster_id cannot be resolved
                    if (!$rosterId) {
                        $skipped[] = [
                            'emp_id' => $row->emp_id,
                            'date' => $dateStr,
                            'reason' => 'Missing roster_id for employee/date',
                        ];
                        continue;
                    }

                    $prepared[] = [
                        'card_no' => $row->emp_code,
                        'employee_user_id' => $employeeUserId,
                        'date' => $dateStr,
                        'in_time' => $inTime,
                        'out_date' => $outDate,
                        'out_time' => $outTime,
                        'roster_id' => $rosterId,
                        'created_user_id' => $systemUserId,
                        'source' => 'import',
                    ];
                }

                // Bulk insert in chunks
                DB::beginTransaction();
                try {
                    foreach (array_chunk($prepared, 500) as $chunk) {
                        if (!empty($chunk)) {
                            EmployeeAttendance::insert($chunk);
                        }
                    }
                    DB::commit();
                } catch (\Throwable $e) {
                    DB::rollBack();
                    $result = [
                        'status' => 'error',
                        'queue' => $queueName,
                        'message_raw' => $body,
                        'error' => 'Bulk insert failed',
                        'exception' => $e->getMessage(),
                    ];
                    // Cleanup below then return
                    $channel->close();
                    $connection->close();
                    return response()->json($result, 500);
                }

                $result = [
                    'status' => 'processed',
                    'queue' => $queueName,
                    'message_raw' => $body,
                    'payload' => [
                        'message' => $messageText,
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                    ],
                    'counts' => [
                        'temp_rows' => count($tempRows),
                        'prepared' => count($prepared),
                        'skipped' => count($skipped),
                    ],
                    'skipped_details' => $skipped,
                ];
            }
        }

        // Cleanup
        $channel->close();
        $connection->close();

        return response()->json($result);
    }
    */


}