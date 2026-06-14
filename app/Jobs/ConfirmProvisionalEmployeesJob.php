<?php

namespace App\Jobs;

use App\Models\EmployeeLeaveBalance;
use App\Models\EmployeeOfficialInformation;
use App\Models\LeavePolicyDetail;
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
            ->whereNotNull('provisioner_days')
            ->whereNull('confirmation_date')
            ->whereRaw('DATEDIFF(?, joining_date) >= provisioner_days', [$targetDate])
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
            $employee->confirmation_date = $targetDate;
            if($employee->save()){
                $allowedDays = $this->calculateAllowedLeaveDays($employee->provisioner_days, $employee->joining_date);
                $this->assignLeaveBalanceToEmployee($employee);

            }
        }
    }

    private function calculateAllowedLeaveDays($leavePolicyDetailDays, $joiningDate)
    {
        // Usage example
        $businessDaysRemaining = $this->getDaysRemainingInBusinessYear($joiningDate);

        if($businessDaysRemaining >= 365)
        {
            return $leavePolicyDetailDays;
        }

        $allowedDays = ($leavePolicyDetailDays*$businessDaysRemaining) / 365;
        $fraction = $allowedDays - floor($allowedDays);
    
        if ($fraction < 0.25) {
            $allowedDays = floor($allowedDays);
        } elseif ($fraction >= 0.25 && $fraction <= 0.74) {
            $allowedDays = floor($allowedDays) + 0.5;
        } else {
            $allowedDays = ceil($allowedDays);
        }

        return $allowedDays;
    }

    private function getDaysRemainingInBusinessYear($joiningDate) 
    {
        $currentTimestamp = strtotime($joiningDate);

        $yearEndTimestamp = strtotime(date('Y-12-31'));
        $interval = $yearEndTimestamp - $currentTimestamp;
        return $interval / 86400;
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

            // for new Employee, calculate employee leave days comparing to their joining date , according to HR policy
            $leaveDays = $this->calculateAllowedLeaveDays($leavePolicyDetail->days, $employeeOfficialInfo->joiningDate); // todo:: calculate according to formula // Done

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
