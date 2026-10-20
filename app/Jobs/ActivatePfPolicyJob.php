<?php

namespace App\Jobs;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Keeps exactly one PF policy active: the non-deleted policy with the latest
 * effective_date on or before the target date (ties go to the newest id).
 * Every other policy is set to status '0', and the PF salary head (id 12) takes
 * the active policy's percentage and deduction basis.
 *
 * Every active, PF-eligible employee is then synced to the active policy:
 *  - employee_official_information.employee_pf_policy_id = active policy
 *  - payroll_salary_heads PF row (head 12): when amount / percentage / basis differ,
 *    the live row is soft deleted and a new one inserted.
 *    amount = min(base * amount_ptc / 100, max_deduction_amount)
 *    base   = gross_salary (basis 1) or the employee's live Basic row, head 6 (basis 2)
 */
class ActivatePfPolicyJob extends Job implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    private const PF_SALARY_HEAD_ID = 12;
    private const BASIC_SALARY_HEAD_ID = 6;
    private const BASIS_GROSS = '1';
    private const BASIS_BASIC = '2';

    protected array $payload;

    public function __construct(array $payload = [])
    {
        $this->payload = $payload;
        $this->connection = 'rabbitmq';
        $this->queue = 'activatePfPolicy_queue';
    }

    public function handle(): void
    {
        $targetDate = Carbon::parse($this->payload['date'] ?? date('Y-m-d'))->toDateString();

        $policy = DB::table('employee_pf_policies')
            ->whereNull('deleted_at')
            ->whereDate('effective_date', '<=', $targetDate)
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->first(['id', 'status', 'amount_ptc', 'deduction_basis', 'max_deduction_amount']);

        // nothing effective yet: keep the current state instead of leaving no active policy
        if (!$policy) {
            Log::info('ActivatePfPolicyJob: no PF policy effective on ' . $targetDate);
            return;
        }

        [$policyChanged, $employeesChanged] = DB::transaction(function () use ($policy) {
            $deactivated = DB::table('employee_pf_policies')
                ->where('id', '!=', $policy->id)
                ->where(fn ($q) => $q->where('status', '!=', '0')->orWhereNull('status'))
                ->update(['status' => '0']);

            $activated = DB::table('employee_pf_policies')
                ->where('id', $policy->id)
                ->where(fn ($q) => $q->where('status', '!=', '1')->orWhereNull('status'))
                ->update(['status' => '1']);

            //..... update pf row in salary_heads (id 12 is hardcoded in the salary jobs, so it is updated in place)
            $headUpdated = DB::table('salary_heads')
                ->where('id', self::PF_SALARY_HEAD_ID)
                ->whereRaw('NOT (percentage <=> ? AND percentage_of_what <=> ?)', [$policy->amount_ptc, (string) $policy->deduction_basis])
                ->update([
                    'percentage' => $policy->amount_ptc,
                    'percentage_of_what' => (string) $policy->deduction_basis, // 1=gross, 2=basic
                    'updated_user_id' => getUserId(),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

            //..... update " payroll_salary_heads " table for all active pf eligible employee
            $employeesChanged = $this->syncEmployeePfHeads($policy);

            return [$deactivated + $activated + $headUpdated, $employeesChanged];
        });

        if ($policyChanged === 0 && $employeesChanged === 0) {
            return;
        }

        Log::info('ActivatePfPolicyJob: PF policy synced', [
            'policy_id' => $policy->id,
            'date' => $targetDate,
            'policy_rows_changed' => $policyChanged,
            'employees_changed' => $employeesChanged,
        ]);

        if ($policyChanged > 0) {
            (new CacheRegenerateJob(['type' => ['pf_policies']]))->handle();
        }
        if ($employeesChanged > 0) {
            regenerateEmployeeCache();
        }
    }

    /**
     * Point every active, PF-eligible employee at $policy and replace their PF salary
     * head row when it differs from what the policy gives. Returns the number of
     * employees whose policy id or PF row changed.
     */
    private function syncEmployeePfHeads(object $policy): int
    {
        $basis = (string) $policy->deduction_basis;
        $percentage = round((float) $policy->amount_ptc, 2);
        $maxDeduction = (float) $policy->max_deduction_amount;
        $now = date('Y-m-d H:i:s');
        $userId = getUserId();

        $showOnSalarySheet = DB::table('salary_heads')->where('id', self::PF_SALARY_HEAD_ID)->value('show_on_salary_sheet') ?? 1;

        // same "active employee" filter as ProcessTempSalaryJob, plus pf eligibility
        $employees = DB::table('users')
            ->join('employee_official_information as oi', 'oi.employee_user_id', '=', 'users.id')
            ->where('users.status', 1)
            ->where('users.user_type_id', 1) // 1 = employee
            ->where('users.is_draft', 0)
            ->whereNull('users.deleted_by')
            ->whereNull('users.deleted_at')
            ->whereNull('oi.deleted_at')
            ->whereNull('oi.deleted_by')
            ->where('oi.pf_eligibility_status', 1)
            ->get(['users.id as employee_user_id', 'oi.gross_salary', 'oi.salary_structure_id', 'oi.employee_pf_policy_id']);

        $changedEmployees = [];

        foreach ($employees->chunk(500) as $chunk) {
            $employeeIds = $chunk->pluck('employee_user_id')->all();

            //..... employee pf policy id -> active policy
            $policyIdStale = $chunk->filter(fn ($e) => (int) $e->employee_pf_policy_id !== (int) $policy->id)
                ->pluck('employee_user_id')->all();
            if ($policyIdStale) {
                DB::table('employee_official_information')
                    ->whereIn('employee_user_id', $policyIdStale)
                    ->whereNull('deleted_at')
                    ->update([
                        'employee_pf_policy_id' => $policy->id,
                        'updated_user_id' => $userId,
                        'updated_at' => $now,
                    ]);
                foreach ($policyIdStale as $id) {
                    $changedEmployees[$id] = true;
                }
            }

            $liveHeads = DB::table('payroll_salary_heads')
                ->whereIn('employee_user_id', $employeeIds)
                ->whereIn('salary_head_id', [self::PF_SALARY_HEAD_ID, self::BASIC_SALARY_HEAD_ID])
                ->whereNull('deleted_at')
                ->whereNull('deleted_by')
                ->orderByDesc('id')
                ->get(['id', 'employee_user_id', 'salary_head_id', 'amount', 'percentage', 'percentage_of_what'])
                ->groupBy('employee_user_id');

            $replaceFor = [];
            $inserts = [];

            foreach ($chunk as $employee) {
                $heads = $liveHeads->get($employee->employee_user_id, collect());
                $pfRows = $heads->where('salary_head_id', self::PF_SALARY_HEAD_ID);

                if ($basis === self::BASIS_BASIC) {
                    $basicRow = $heads->firstWhere('salary_head_id', self::BASIC_SALARY_HEAD_ID);
                    if (!$basicRow) {
                        Log::warning('ActivatePfPolicyJob: no live Basic salary head, PF row left unchanged', [
                            'employee_user_id' => $employee->employee_user_id,
                        ]);
                        continue;
                    }
                    $base = (float) $basicRow->amount;
                } else {
                    $base = (float) $employee->gross_salary;
                }

                $amount = round(min($base * $percentage / 100, $maxDeduction), 2);

                // already in sync: exactly one live PF row with the same values
                if ($pfRows->count() === 1) {
                    $current = $pfRows->first();
                    if (round((float) $current->amount, 2) === $amount
                        && round((float) $current->percentage, 2) === $percentage
                        && (string) $current->percentage_of_what === $basis) {
                        continue;
                    }
                }

                $replaceFor[] = $employee->employee_user_id;
                $inserts[] = [
                    'employee_user_id' => $employee->employee_user_id,
                    'salary_head_id' => self::PF_SALARY_HEAD_ID,
                    'amount' => $amount,
                    'percentage' => $percentage,
                    'percentage_of_what' => $basis,
                    'is_earning' => 0,
                    'show_on_salary_sheet' => $showOnSalarySheet,
                    'salary_structure_id' => $employee->salary_structure_id,
                    'created_user_id' => $userId,
                    'created_at' => $now,
                ];
            }

            if (!$replaceFor) {
                continue;
            }

            //..... soft delete old pf row of each employee, then insert the new one
            DB::table('payroll_salary_heads')
                ->whereIn('employee_user_id', $replaceFor)
                ->where('salary_head_id', self::PF_SALARY_HEAD_ID)
                ->whereNull('deleted_at')
                ->whereNull('deleted_by')
                ->update([
                    'deleted_by' => $userId,
                    'deleted_at' => $now,
                ]);

            DB::table('payroll_salary_heads')->insert($inserts);

            foreach ($replaceFor as $id) {
                $changedEmployees[$id] = true;
            }
        }

        return count($changedEmployees);
    }
}
