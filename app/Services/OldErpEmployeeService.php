<?php

namespace App\Services;

use App\Models\EmployeeAddress;
use App\Models\EmployeeBasicInformation;
use App\Models\EmployeeFamilyNominee;
use App\Models\EmployeeOfficialInformation;
use App\Models\PhoneNumber;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Employee sync to the old ERP, copied from hr-admin-backend ApiIntegrationController::saveEmployeeDataToOldErp().
 * Keep both in step when the payload changes.
 * Field conversions are the old_erp_* helpers in app/helpers.php.
 */
class OldErpEmployeeService
{
    /**
     * Send the employee to the old ERP: POST {OLD_ERP_API_URL}/apiEmployees/save (create or update,
     * matched by emp_id_no).
     * Blocking: call it before the DB commit; it throws when the old ERP refuses, so the caller rolls back.
     * Skipped (no exception) when the employee has no user / basic / official info yet.
     */
    public function saveEmployeeDataToOldErp($employeeUserId): void
    {
        $user = User::find($employeeUserId);
        $official = EmployeeOfficialInformation::where('employee_user_id', $employeeUserId)->whereNull('deleted_at')->first();
        $basic = EmployeeBasicInformation::where('employee_user_id', $employeeUserId)->whereNull('deleted_at')->first();
        if (!$user || !$official || !$basic) {
            Log::warning('Old ERP employee save skipped: employee data not found', ['employee_user_id' => $employeeUserId]);
            return;
        }

        // stored as +880 / 1XXXXXXXXX, the old ERP keeps 01XXXXXXXXX
        $phone = function ($contactType) use ($employeeUserId) {
            $p = PhoneNumber::where('user_id', $employeeUserId)->where('contact_type', $contactType)
                ->whereNull('deleted_at')->orderByDesc('is_primary')->first();
            if (!$p || trim($p->phone_number) === '') {
                return null;
            }
            return $p->country_code === '+880' ? '0' . ltrim($p->phone_number, '0') : $p->country_code . $p->phone_number;
        };

        // address line, post office, upazila, district, post code as one text
        $address = function ($addressType) use ($employeeUserId) {
            $a = EmployeeAddress::where('employee_user_id', $employeeUserId)->where('address_type', $addressType)
                ->whereNull('deleted_at')->first();
            if (!$a) {
                return null;
            }
            return old_erp_text(implode(', ', array_filter([
                old_erp_text($a->address_line),
                old_erp_text($a->post_office),
                old_erp_id_to_text('upazilas', $a->upazila_id),
                old_erp_id_to_text('districts', $a->district_id),
                old_erp_text($a->post_code),
            ])));
        };

        $spouseName = EmployeeFamilyNominee::where('employee_user_id', $employeeUserId)->where('relationship', 'spouse')
            ->whereNull('deleted_at')->value('name');

        $payload = [
            'action_by_emp_id' => getUserId(), // system user here (SYSTEM_USER_ID)
            'id' => (int) $employeeUserId,     // old ERP employees.id = users.id
            'emp_id_no' => old_erp_text($official->emp_id_no_old) ?? old_erp_text($official->emp_code),
            'id_no' => old_erp_text($official->emp_id_no_old) ?? old_erp_text($official->emp_code),
            'device_id' => old_erp_text($official->emp_code_old) ?? old_erp_text($official->emp_code),
            'full_name' => old_erp_text($basic->full_name),
            'full_name_bn' => old_erp_text($basic->full_name_bn),
            'emp_short_name' => old_erp_text($basic->nickname),
            'national_id_no' => old_erp_national_id_no($basic),
            'contact_no' => $phone('personal'),
            'contact_no_office' => $phone('official'),
            'email' => old_erp_text($basic->official_email),
            'p_email' => old_erp_text($basic->personal_primary_email),
            'father_name' => old_erp_text($basic->father_name),
            'father_occupation' => old_erp_occupation_name($basic->father_occupation_id),
            'mother_name' => old_erp_text($basic->mother_name),
            'mother_occopation' => old_erp_occupation_name($basic->mother_occupation_id), // old ERP column spelling
            'spouse_name' => old_erp_text($spouseName),
            'religion' => $user->religion_id ?: null,           // religion / gender / blood group / employee type ids
            'gender' => $user->gender_id ?: null,               // are already the old ERP lookup codes
            'blood_group' => $user->blood_group_id ?: null,
            'marital_status' => old_erp_marital_status($basic->marital_status),
            'dob' => sql_date($basic->date_of_birth) ?? sql_date($user->date_of_birth),
            'address' => $address('present'),
            'permanent_address' => $address('permanent'),
            'branch_id' => $official->branch_id ?: null,        // branches keep the old ERP ids
            'employee_type' => $official->employee_type_id ?: null,
            'join_date' => sql_date($official->joining_date),
            'permanent_date' => sql_date($official->confirmation_date),
            'is_active' => (int) $user->status === 1 ? 1 : 2,
            'tin' => old_erp_text($basic->tin_no),
            'bank' => old_erp_bank_name($official->bank_name_id),
            'bank_branch' => old_erp_bank_branch_name($official->branch_name_id),
            'bank_ac_no' => old_erp_text($official->bank_account_no),
            'supervisor_id' => $official->reporting_supervisor_user_id ?: null, // users.id = old ERP employees.id
            'is_mealable' => (int) $official->is_mealable,      // NOT NULL in the old ERP
            'pf_eligibility' => (int) $official->pf_eligibility_status,
        ];

        $this->postToOldErp('employee save', 'employee_user_id', $employeeUserId, '/apiEmployees/save', $payload);
    }

    /**
     * POST a payload to the old ERP and log the result.
     * Throws a RuntimeException carrying the old ERP's message when it fails or can't be reached.
     * Skipped (no exception) when OLD_ERP_API_KEY is not set, so emptying the key switches the sync off.
     */
    private function postToOldErp(string $action, string $idName, $id, string $endpoint, array $payload, int $timeout = 30): void
    {
        $resource = explode('/', ltrim($endpoint, '/'))[0];   // '/apiEmployees/save' => 'apiEmployees'
        if (!old_erp_sync_enabled($resource)) {
            Log::warning("Old ERP {$action} skipped: SYNC_STATUS {$resource} = 0", [$idName => $id]);
            return;
        }

        $apiKey = env('OLD_ERP_API_KEY');
        if (empty($apiKey)) {
            Log::warning("Old ERP {$action} skipped: OLD_ERP_API_KEY is not set", [$idName => $id]);
            return;
        }

        try {
            $url = rtrim(env('OLD_ERP_API_URL', 'http://30.30.28.73:8080/erp/erp/api'), '/') . $endpoint;
            $response = Http::timeout($timeout)
                ->withHeaders(['X-API-KEY' => $apiKey])
                ->asJson()
                ->post($url, $payload);
        } catch (\Throwable $e) {
            Log::error("Old ERP {$action} error", [$idName => $id, 'error' => $e->getMessage()]);
            throw new \RuntimeException("Old ERP {$action} failed: " . $e->getMessage(), 0, $e);
        }

        if ($response->successful()) {
            Log::info("Old ERP {$action} done", [$idName => $id, 'response' => $response->json()]);
            return;
        }

        Log::error("Old ERP {$action} failed", [
            $idName => $id,
            'status' => $response->status(),
            'response' => $response->json() ?? $response->body(),
            'payload' => $payload,
        ]);
        $message = $response->json('message') ?? 'HTTP ' . $response->status();
        $fieldErrors = collect($response->json('errors') ?? [])->flatten()->implode(' ');
        throw new \RuntimeException(trim("Old ERP {$action} failed: {$message} {$fieldErrors}"));
    }
}
