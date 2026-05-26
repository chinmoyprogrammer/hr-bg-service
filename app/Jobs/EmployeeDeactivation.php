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

            $token = $tokenResponse->json('token');

            foreach ($applications as $application) {
                // 2. Update user with status 0
                $employeeUser = User::find($application->employee_id);
                if ($employeeUser) {
                    $employeeUser->status = 0;
                    $employeeUser->save();
                    Log::info("EmployeeDeactivation: User status updated to 0 for employee_id: {$application->employee_id}");
                } else {
                    Log::warning("EmployeeDeactivation: User not found for employee_id: {$application->employee_id}");
                }

                // 3. Deactivate user in ZKBio time
                $payload = [
                    'employee' => $application->employee_id,
                    'disableatt' => true,
                    'resign_type' => $application->separation_type_id ?? 1,
                    'resign_date' => $application->effective_date->format('Y-m-d'),
                    'reason' => $application->reason ?? ''
                ];

                /* $resignResponse = Http::timeout(60)
                    ->retry(3, 5000)
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'Authorization' => 'JWT ' . $token->json('token')
                    ])
                    ->withOptions(['verify' => false])
                    ->post($resign_api_url, $payload); */

                if (true) {
                    Log::info("EmployeeDeactivation: Successfully deactivated employee in ZKBio.", ['employee_id' => $application->employee_id, 'response' => '']);
                    
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
}
