<?php

namespace App\Http\Controllers;

use App\Jobs\RabbitMQJob;
use App\Models\EmployeeAttendanceTemp;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeOfficialInformation;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Wire\AMQPTable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;

class AttendanceController extends Controller
{
    public function pullRawDataFromDeviceToTempTable(Request $request)
    {
        // Device credentials and API endpoint
        $device_user_name = env('DEVICE_USER_NAME');
        $device_password = env('DEVICE_PASSWORD');
        $jwt_api_url = env('JWT_API_URL');

        // get token from api
        $token = Http::post($jwt_api_url, [
            'username' => $device_user_name,
            'password' => $device_password
        ]);
        // output : { "token": "gP4K......biHUoy" }


        // Fetch attendance data from device API
        $attendanceApiUrl = env('ATTENDANCE_DATA_API_URL');
        $startTime = $request->input('start_time');
        $endTime   = $request->input('end_time');

        $attendance_data = Http::withHeaders([
            'Content-Type'  => 'application/json',
            'Authorization' => 'JWT ' . $token->json('token')
        ])->get($attendanceApiUrl, [
            'start_time' => $startTime,
            'end_time'   => $endTime
        ]);

        // Return list of attendances
        //return $attendance_data->json();

        //insert data to temp table
        $attendance_data = $attendance_data->json();

        $insert_data = [];

            // Group attendance records by emp_id and date to pair in/out times
            $grouped = [];
            foreach ($attendance_data as $record) {
                $empId = $record['id'];
                $datetime = \Carbon\Carbon::parse($record['time']);
                $date = $datetime->toDateString();

                $key = $empId . '_' . $date;
                if (!isset($grouped[$key])) {
                    $grouped[$key] = [
                        'emp_id' => $empId,
                        'in_datetime' => null,
                        'out_datetime' => null,
                    ];
                }

                // Assume first record is "in", second is "out" for the same day
                if ($grouped[$key]['in_datetime'] === null) {
                    $grouped[$key]['in_datetime'] = $datetime->toDateTimeString();
                } else {
                    $grouped[$key]['out_datetime'] = $datetime->toDateTimeString();
                }
            }

            // Prepare bulk insert data
            $insert_data = array_values($grouped);

            // Bulk insert into EmployeeAttendance table
            \App\Models\EmployeeAttendanceTemp::insert($insert_data);

            
        $payload = [
            'message' => "",
            'start_date' => $startTime,
            'end_date' => $endTime,
        ];

        $queueName = 'processTempData';
        dispatch(new RabbitMQJob($payload, $queueName));
        

    }

    /**
     * Consume one message from RabbitMQ queue 'processTempData'.
     * Useful for testing/triggered consumption via HTTP.
     */
    public function consumeProcessTempData(Request $request)
    {
        $queueName = 'processTempData';

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

        // Fetch a single message (auto-acknowledge)
        $msg = $channel->basic_get($queueName, true);

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
                    ->where('in_datetime', '>=', $startBoundary)
                    ->where('in_datetime', '<=', $endBoundary)
                    ->get();

                $systemUserId = (int) env('SYSTEM_USER_ID', 1);
                $prepared = [];
                $skipped = [];

                foreach ($tempRows as $row) {
                    // Resolve employee_user_id by emp_id from official info
                    $employeeUserId = EmployeeOfficialInformation::query()
                        ->where('emp_id', $row->emp_id)
                        ->value('employee_user_id');

                    // Choose base date from in_datetime
                    $dateStr = (new \Carbon\Carbon($row->in_datetime))->toDateString();
                    $inTime = (new \Carbon\Carbon($row->in_datetime))->format('H:i:s');

                    // Handle possible null out_datetime
                    $outDt = $row->out_datetime ? new \Carbon\Carbon($row->out_datetime) : null;
                    $outDate = $outDt ? $outDt->toDateString() : null;
                    $outTime = $outDt ? $outDt->format('H:i:s') : null;

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
                        'card_no' => $row->emp_id,
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


}