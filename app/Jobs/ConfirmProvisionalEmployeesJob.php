<?php

namespace App\Jobs;

use App\Models\EmployeeLeaveBalance;
use App\Models\EmployeeOfficialInformation;
use App\Models\LeavePolicyDetail;
use App\Services\OldErpEmployeeService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ConfirmProvisionalEmployeesJob extends Job implements ShouldQueue
{

    use InteractsWithQueue, Queueable, SerializesModels;


    private const PROBATIONARY = 10; // employee_types.id
    private const REGULAR = 69; // employee_types.id, permanent employee

    protected array $payload;

    public function __construct(array $payload = [])
    {
        $this->payload = $payload;
        $this->connection = 'rabbitmq';
        $this->queue = 'confirmProvisionalEmployees_queue';
    }

    public function handle(): void
    {
        $targetDate = Carbon::parse($this->payload['date'] ?? date('Y-m-d'))->toDateString();
        //DB::enableQueryLog();
        $employees = EmployeeOfficialInformation::query()
            ->whereNotNull('joining_date')
            ->where(function ($query) use ($targetDate) {
                // probationary employee whose confirmation date has arrived
                $query->where(function ($q) use ($targetDate) {
                    $q->where('employee_type_id', self::PROBATIONARY)
                        ->where('confirmation_date', '<=', $targetDate);
                })
                // no confirmation date yet and provision period is over
                ->orWhere(function ($q) use ($targetDate) {
                    $q->whereNotNull('provisioner_days')
                        ->whereNull('confirmation_date')
                        ->whereRaw('DATEDIFF(?, joining_date) >= provisioner_days', [$targetDate]);
                });
            })
            ->get([
                'id',
                'employee_user_id',
                'joining_date',
                'provisioner_days',
                'confirmation_date',
                'leave_policy_id',
                'basic_salary',
                'gross_salary',
                'employee_type_id',
            ]);
        //$queries = DB::getQueryLog();
        /* Log::info('ConfirmProvisionalEmployeesJob: queries', [
            'queries' => $queries,
        ]); */
        if ($employees->isEmpty()) {
            Log::info('ConfirmProvisionalEmployeesJob: no employees found for confirmation on date ' . $targetDate);
            return;
        }

        foreach ($employees as $employee) {
            DB::beginTransaction();
            try {
                $employee->confirmation_date = $employee->confirmation_date ?: $targetDate;
                $promoted = $employee->employee_type_id == self::PROBATIONARY;
                if ($promoted) {
                    $employee->employee_type_id = self::REGULAR;
                    DB::table('users')->where('id', $employee->employee_user_id)->update(['employee_type_id' => self::REGULAR]);
                }
                $employee->save();
                // only regular employees get paid leave; other types keep the unpaid row hr-admin gave them.
                // runs once per employee: this save stops them matching, so deleted balances are not re-created
                if ($employee->employee_type_id == self::REGULAR) {
                    $this->assignLeaveBalanceToEmployee($employee);
                }

                //... the old ERP must take the type change too, otherwise nothing is saved here
                if ($promoted) {
                    (new OldErpEmployeeService())->saveEmployeeDataToOldErp($employee->employee_user_id);
                }
                DB::commit();
            } catch (\Throwable $e) {
                // stays probationary, so the next run tries this employee again
                DB::rollBack();
                Log::error('ConfirmProvisionalEmployeesJob: employee not confirmed', [
                    'employee_user_id' => $employee->employee_user_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        regenerateEmployeeCache();
    }

    // same rule as hr-admin proratedLeaveDays(): remaining calendar days (start day included) / days in year * annual leave,
    // fraction < .25 => down, .25 to < .75 => .5, >= .75 => up; start on or before 1 Jan => full year
    private function calculateAllowedLeaveDays($leavePolicyDetailDays, $startDate)
    {
        $days = (float) $leavePolicyDetailDays;
        $start = Carbon::parse($startDate)->startOfDay();
        $yearStart = Carbon::create((int) date('Y'), 1, 1);
        $yearEnd = $yearStart->copy()->endOfYear()->startOfDay();
        if ($start->lte($yearStart)) {
            return $days;
        }
        if ($start->gt($yearEnd)) {
            return 0;
        }

        $allowedDays = $days * ($start->diffInDays($yearEnd) + 1) / $yearStart->daysInYear;
        $whole = floor($allowedDays);
        $fraction = round($allowedDays - $whole, 6);
        return $whole + ($fraction < 0.25 ? 0 : ($fraction < 0.75 ? 0.5 : 1));
    }

    private function assignLeaveBalanceToEmployee($employeeOfficialInfo)
    {
        $basic = $employeeOfficialInfo->basic_salary;
        $gross = $employeeOfficialInfo->gross_salary;
        $leavePolicyId = $employeeOfficialInfo->leave_policy_id;
        
        $days_of_month = date('t');
        //..... if new leave policy assigned for the first time , then insert them as like insert 
        $leavePolicyDetails = LeavePolicyDetail::query()->whereNull('deleted_at')
            ->where('leave_policy_id', $leavePolicyId)->get();
        if (!$leavePolicyDetails) {
            throw new \Exception("Leave policy details not found.");
        }
        //... insert data into employee_leave_balances table
        foreach ($leavePolicyDetails as $leavePolicyDetail) {
            // Check if leave balance record already exists for this combination
            $existingBalance = EmployeeLeaveBalance::query()
                ->where('employee_user_id', $employeeOfficialInfo->employee_user_id)
                ->where('leave_head_id', $leavePolicyDetail->leave_head_id)
                ->where('leave_policy_id', $leavePolicyId)
                ->where('fiscal_year', date('Y'))
                ->first();
            
            if ($existingBalance) {
                Log::info('test', ['existingBalance' => $existingBalance]);
                continue;
            }
            
            // for new Employee, calculate employee leave days comparing to their joining date , according to HR policy
            // prorated from the day probation ends: joining_date + provisioner_days
            $leaveDays = $this->calculateAllowedLeaveDays($leavePolicyDetail->days,Carbon::parse($employeeOfficialInfo->joining_date)->addDays((int) $employeeOfficialInfo->provisioner_days)
            );

            $encashment_basis_rate = $leavePolicyDetail->encashment_basis_rate ==0 ? 1 : $leavePolicyDetail->encashment_basis_rate/100;
            if($leavePolicyDetail->encashment_basis =='basic' )
            {
                $leave_encashment_rate = $encashment_basis_rate * $basic;   
            }else if($leavePolicyDetail->encashment_basis =='gross' ){
                $leave_encashment_rate = $encashment_basis_rate * $gross;
            }else{
                $leave_encashment_rate = $encashment_basis_rate * $basic;
            }
            
            $employeeLeaveBalance = EmployeeLeaveBalance::query()->create([
                'employee_user_id' => $employeeOfficialInfo->employee_user_id,
                'leave_head_id' => $leavePolicyDetail->leave_head_id,
                'leave_policy_id' => $leavePolicyId,
                'achived_this_year' => $leaveDays,
                'current_balance' => $leaveDays,
                'valid_until' => date('Y-12-31'),
                'fiscal_year' => date('Y'),
                'created_at' => date('Y-m-d H:i:s'),
                'created_user_id' => getUserId(),
                'leave_encashment_rate' => $leave_encashment_rate / $days_of_month,
            ]);
            if (!$employeeLeaveBalance) {
                throw new \Exception("Employee leave balance not created.");
            }
        }
    }
}
