<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EmployeeOfficialInformation extends Model
{
    protected $table = 'employee_official_information';
    protected $fillable = [
        'id',
        'employee_user_id',
        'company_id',
        'branch_id',
        'department_id',
        'section_id',
        'sub_section_id',
        'designation_level_id',
        'designation_id',
        'employee_type',
        'joining_date',
        'provisioner_days',
        'confirmation_date',
        'observation_start_date',
        'observation_end_date',
        'observation_days',
        'reporting_supervisor_user_id',
        'gross_salary',
        'ait_eligible',
        'salary_payment_mode',
        'cash_pay_amount',
        'bank_pay_amount',
        'mobile_pay_amount',
        'bank_name_id',
        'branch_name_id',
        'bank_account_no',
        'mobile_banking_type',
        'payment_mobile_number',
        'facilities',
        'is_mealable',
        'is_free_meal',
        'meal_effective_date',
        'pay_period_basis',
        'employee_pf_employer_contribution_balance',
        'employee_pf_balance',
        'employee_pf_policy_id',
        'employee_ot_policy_id',
        'allotted_mobile_balance',
        'child_data_identifier_key_incoming',
        'leave_policy_id',
        'status',
        'is_draft',
        'created_user_id',
        'created_at',
        'updated_at',
        'updated_user_id',
        'deleted_by',
        'deleted_at',
        'shift_id'
    ];

    public $timestamps = false;


    public function employeeAttendanceTemps()
    {
        return $this->hasMany(EmployeeAttendanceTemp::class, 'emp_code', 'emp_code');
    }

    public function employeeOtPolicy()
    {
        return $this->hasOne(EmployeeOtPolicy::class, 'id', 'employee_ot_policy_id');
    }

    public function hasWeekendDays()
    {
        return $this->hasMany(EmployeeWeekendDays::class, 'employee_user_id', 'employee_user_id');
    }

    public function hasLeavePolicy()
    {
        return $this->hasOne(LeavePolicy::class, 'id', 'leave_policy_id');
    }

    public function hasLeavePolicyDetail()
    {
        return $this->hasMany(LeavePolicyDetail::class, 'leave_policy_id', 'leave_policy_id');
    }

    public function hasLateDeductionPolicy()
    {
        return $this->hasOne(LateDeductionPolicy::class, 'id', 'late_deduction_policy_id');
    }

    //.... count late days till the date
    /**
     * Count late days for the employee in the last 35 days
     *
     * @return int
     */
    public function lateDays()
    {
        return DB::table('employee_attendance_status_logs')
            ->where('employee_user_id', $this->employee_user_id)
            // ->whereBetween('attendance_date', 
            //                 [ date('Y-m-d', strtotime('-35 days')), date('Y-m-d')]
            //             )
            // ->where('attendance_status', 2)->get()
            ;
    }

}

