<?php

namespace App\Jobs;

use App\Models\SeparationApplication;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;

class EmployeeDeactivation extends Job implements ShouldQueue
{
    protected array $payload;
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(array $payload)
    {
        $this->payload    = $payload;
        $this->connection = 'rabbitmq';
        $this->queue      = 'employeeDeactivation_queue';
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        try {
            // Get today's date
            $deactivateDate = $this->payload['date'] ?? null;

            // 1. Retrieve approved SeparationApplication matching effective_date
            $applications = SeparationApplication::where('status', 'Approved')
                ->where('effective_date', '<=', $deactivateDate)
                ->where('employee_status_updated', 0)
                ->get();

            if ($applications->isEmpty()) {
                Log::info('EmployeeDeactivation: No approved separation applications found for today.');
                return;
            }

            // Authenticate with ZKBio to get JWT token
            $device_user_name = env('DEVICE_USER_NAME');
            $device_password = env('DEVICE_PASSWORD');
            $jwt_api_url = env('JWT_API_URL');
            $resign_api_url = env('RESIGN_API_URL');

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

            foreach ($applications as $application) {
                // 2. Update user with status 0
                $employeeUser = User::find($application->employee_id);
                $emp_code = '';
                if ($employeeUser) {
                    $employeeUser->status = 0;
                    $employeeUser->save();
                    $emp_code = $employeeUser->username;
                    Log::info("EmployeeDeactivation: User status updated to 0 for employee_id: {$application->employee_id}");
                } else {
                    Log::warning("EmployeeDeactivation: User not found for employee_id: {$application->employee_id}");
                }

                if ($emp_code === '') {
                    Log::warning('EmployeeDeactivation: emp_code missing for application user; cannot resolve device employee_id.', [
                        'employee_id' => $application->employee_id,
                    ]);
                    continue;
                }

                $deviceEmployeeId = $this->resolveDeviceEmployeeId($emp_code, $token);
                if ($deviceEmployeeId === null) {
                    Log::warning('EmployeeDeactivation: Device employee_id not found for emp_code.', [
                        'employee_id' => $application->employee_id,
                        'emp_code' => $emp_code,
                    ]);
                    continue;
                }

                // 3. Deactivate user in ZKBio time
                $payload = [
                    'employee' => $deviceEmployeeId,
                    'disableatt' => true,
                    'resign_type' => $application->separation_type_id ?? 1,
                    'resign_date' => $application->effective_date->format('Y-m-d'),
                    'reason' => $application->reason ?? ''
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
                    Log::info("EmployeeDeactivation: Successfully deactivated employee in ZKBio.", ['employee_id' => $application->employee_id, 'response' => $resignResponse->body()]);
                    // Mark as updated
                    $application->employee_status_updated = 1;
                    $application->save();
                } else {
                    Log::error("EmployeeDeactivation: Failed to deactivate employee in ZKBio.", ['employee_id' => $application->employee_id, 'response' => '']);
                }
            }

        } catch (\Exception $e) {
            Log::error('EmployeeDeactivation: Exception occurred.', ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Resolves the internal employee ID used by the ZKBio access control system by querying the device's employee API.
     * 
     * This method is required because ZKBio maintains its own internal numeric ID for each employee, which is
     * separate from the organization's internal employee ID (stored as the `username` field on the User model).
     * This ZKBio-specific ID must be provided to the ZKBio resignation API to successfully deactivate an employee.
     * 
     * Workflow:
     * 1. Validates that the ZKBio employee API endpoint is configured in environment variables
     * 2. Sends an authenticated GET request to the ZKBio API, filtering by the organization's employee code
     * 3. Parses the API response to extract the ZKBio internal employee ID, supporting multiple common API response formats
     * 4. Validates that the extracted ID is a positive integer before returning it
     * 
     * @param string $empCode The organization's internal employee ID, stored as the `username` field on the User model, used to look up the employee in ZKBio
     * @param string $token Valid JWT authentication token obtained from the ZKBio API, used to authorize the request
     * 
     * @return int|null Returns the ZKBio internal numeric employee ID if successfully resolved, null on any failure (missing config, API error, ID not found/invalid)
     * 
     * Environment Dependencies:
     * - DEVICE_EMPLOYEE_API_URL: The base URL of the ZKBio API endpoint for listing/querying employee records
     * 
     * API Request Details:
     * - Timeout: 30 seconds to account for potential network latency with on-premise ZKBio devices
     * - Retries: 2 retries with 1 second delay between attempts to handle transient API failures
     * - Query Parameters: `emp_code` (the employee's code to filter by) and `page_size=1` to minimize response size
     * - SSL Verification: Disabled to support common self-signed certificate setups used with on-premise ZKBio servers
     * 
     * Supported API Response Formats:
     * - Laravel pagination: `data[0].id`
     * - Django REST Framework pagination: `results[0].id`
     * - Single record response: `id` (for APIs that return a single object instead of a list)
     */
    private function resolveDeviceEmployeeId(string $empCode, string $token): ?int
    {
        $employeesUrl = (string) env('DEVICE_EMPLOYEE_API_URL', '');
        if ($employeesUrl === '') {
            Log::error('EmployeeDeactivation: DEVICE_EMPLOYEE_API_URL is not set and could not be derived.');
            return null;
        }

        $request = Http::timeout(30)
            ->retry(2, 1000)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'JWT ' . $token,
            ])
            ->withOptions(['verify' => false]);

        $response = $request->get($employeesUrl, ['emp_code' => $empCode, 'page_size' => 1]);
        if ($response->successful()) {
            $json = $response->json();
            $id = data_get($json, 'data.0.id') ?? data_get($json, 'results.0.id') ?? data_get($json, 'id');
            if (is_numeric($id) && (int) $id > 0) {
                return (int) $id;
            }
        }
        $json = $response->json();
        $id = data_get($json, 'data.0.id') ?? data_get($json, 'results.0.id') ?? data_get($json, 'id');
        return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
    }
}
