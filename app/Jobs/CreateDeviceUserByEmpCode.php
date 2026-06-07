<?php
namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CreateDeviceUserByEmpCode extends Job implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;
    protected array $payload;

    public function __construct(array $payload)
    {
        $this->payload = $payload;
        $this->connection = 'rabbitmq';
        $this->queue = 'createDeviceUserByEmpCode_queue';
    }

    public function handle(): void
    {
        $empCode = trim((string) ($this->payload['emp_code'] ?? ''));
        if ($empCode === '') {
            Log::error('CreateDeviceUserByEmpCode: emp_code is required.');
            return;
        }

        $token = $this->getToken();
        Log::info('CreateDeviceUserByEmpCode: token = ' . $token);
        if ($token === '') {
            return;
        }

        $employeesUrl = $this->getEmployeesUrl();
        Log::info('CreateDeviceUserByEmpCode: employeesUrl = ' . $employeesUrl);
        if ($employeesUrl === '') {
            return;
        }

        $http = Http::timeout(30)
            ->retry(2, 1000)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'JWT ' . $token,
            ])
            ->withOptions(['verify' => false]);

        $existing = $http->get($employeesUrl, ['emp_code' => $empCode, 'page_size' => 1]);
        if ($existing->successful()) {
            $json = $existing->json();
            $id = data_get($json, 'data.0.id') ?? data_get($json, 'results.0.id') ?? data_get($json, 'id');
            if (is_numeric($id) && (int) $id > 0) {
                return;
            }
        }

        $departmentId = (int) env('DEVICE_DEFAULT_DEPARTMENT_ID', 1);
        $areaId = (int) env('DEVICE_DEFAULT_AREA_ID', 1);
        $payload = array_merge([
            'emp_code' => $empCode,
            'first_name' => $empCode,
            'last_name' => '',
            'department' => $departmentId,
            'area' => [$areaId],
            'hire_date' => date('Y-m-d'),
            'app_status' => 0,
        ], array_filter(
            $this->payload,
            fn($v, $k) => $k !== 'emp_code' && !($v === null || $v === ''),
            ARRAY_FILTER_USE_BOTH
        ));

        $created = $http->post($employeesUrl, $payload);
        if (!$created->successful()) {
            Log::error('CreateDeviceUserByEmpCode: create failed.', [
                'emp_code' => $empCode,
                'status' => $created->status(),
                'response' => $created->body(),
            ]);
        }
    }

    private function getToken(): string
    {
        $deviceUserName = (string) env('DEVICE_USER_NAME');
        $devicePassword = (string) env('DEVICE_PASSWORD');
        $jwtApiUrl = (string) env('JWT_API_URL');

        if ($jwtApiUrl === '' || $deviceUserName === '' || $devicePassword === '') {
            Log::error('CreateDeviceUserByEmpCode: device auth env missing.');
            return '';
        }

        $tokenResponse = Http::timeout(30)
            ->withOptions(['verify' => false])
            ->post($jwtApiUrl, [
                'username' => $deviceUserName,
                'password' => $devicePassword,
            ]);

        if (!$tokenResponse->successful()) {
            Log::error('CreateDeviceUserByEmpCode: failed to get JWT token.', [
                'status' => $tokenResponse->status(),
                'response' => $tokenResponse->body(),
            ]);
            return '';
        }

        $token = (string) $tokenResponse->json('token');
        if ($token === '') {
            Log::error('CreateDeviceUserByEmpCode: token missing from response.', [
                'response' => $tokenResponse->body(),
            ]);
        }

        return $token;
    }

    private function getEmployeesUrl(): string
    {
        $employeesUrl = trim((string) env('DEVICE_EMPLOYEE_API_URL', ''));
        if ($employeesUrl !== '') {
            return rtrim($employeesUrl, '/') . '/';
        }

        $resignApiUrl = trim((string) env('RESIGN_API_URL', ''));
        if ($resignApiUrl === '') {
            Log::error('CreateDeviceUserByEmpCode: DEVICE_EMPLOYEE_API_URL/RESIGN_API_URL missing.');
            return '';
        }

        $derived = (string) preg_replace('#/resigns/?$#', '/employees/', $resignApiUrl);
        return rtrim($derived, '/') . '/';
    }
}

