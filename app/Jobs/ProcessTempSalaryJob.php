<?php
namespace App\Jobs;

use App\Models\User;
use App\Models\SalaryHead;
use Illuminate\Bus\Queueable;
use App\Models\PayrollSalaryHead;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\LateAttendanceRecord;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use App\Models\PayrollSalaryAdvanceNLoan;
use App\Models\EmployeeOfficialInformation;
use App\Models\EmployeeOtData;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Models\PayrollPreSalarySheetDeduction;
use App\Models\PrProblemRegisterAccousedPerson;
use App\Models\PayrollSalaryAdvanceLoanNOtherInstallment;


class ProcessTempSalaryJob extends Job implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    protected array $payload;

    public function __construct(array $payload)
    {
        $this->payload = $payload;
        $this->connection = 'rabbitmq';
        $this->queue = 'processTempSalary_queue';
    }

    public function handle(): void
    {
        $payload = $this->payload;
        Log::info('Ki re vai!!!', ['payload' => $payload]);
        $PayrollPreSalarySheetDeduction = [];
        $PayrollSalaryAdvanceLoanNOtherInstallment = [];
        $PayrollSalarySheetHeads = [];


        User::with(['hasOfficialInformation','loans' => function($query){
                $query->where('is_fully_paid', 0);
            }])
            ->whereHas('hasOfficialInformation')
            ->where('status', 1)
            ->where('user_type_id', 1) // 1 = employee
            ->where('is_draft', 0)
            ->where('deleted_by', null)
            ->lazy()
            ->each(function ($user) use(&$PayrollPreSalarySheetDeduction, &$PayrollSalaryAdvanceLoanNOtherInstallment, &$PayrollSalarySheetHeads)
            {
                // Log::info('Line 52', ['payload' => $user]);
                if($user->loans->count() > 0)
                {
                    foreach($user->loans as $loan){
                        // Log::info('Line 56', ['payload' => var_dump($loan)]);
                        // Log::info('Line 57', ['payload' => $user->loans->count()]);
                        // Log::info('Line 58', ['payload' => $user->loans]);
                        //.... process loan installments ( user can make a request for pausing the installment deduction for the month, need a history/ settings table )
                        if($loan != null && gettype($loan) == 'object')
                        {

                        
                            // insert data to payroll_pre_salary_sheet_deductions table
                            $deduct_loan_this_month =1;
                            if($deduct_loan_this_month)
                            {
                                // PayrollPreSalarySheetDeduction::create([
                                //     'child_data_identifier_key_incoming' => 'payroll_salary_advance_n_loans_'.$loan->id,
                                //     'amount' => $loan->primary_installment_amount,
                                //     'type' => 'loan',
                                //     'created_at' => date('Y-m-d H:i:s'),
                                // ]);
                                $PayrollPreSalarySheetDeduction[] = [
                                    'child_data_identifier_key_incoming' => 'payroll_salary_advance_n_loans_'.$loan->id,
                                    'amount' => $loan->primary_installment_amount,
                                    'type' => 'loan',
                                    'created_at' => date('Y-m-d H:i:s'),
                                ];

                                // insert data to PayrollSalaryAdvanceLoanNOtherInstallments
                                // Use Eloquent model instead of raw DB insert for consistency
                                // PayrollSalaryAdvanceLoanNOtherInstallment::create([
                                //     'payroll_salary_advance_n_loan_id' => $loan->id,
                                //     'coa_id' => 0,
                                //     'amount' => $loan->primary_installment_amount,
                                //     'remaining_amount' => $loan->outstanding_balance,
                                //     'adjustment_status' => 'with_salary',
                                //     'is_bad_debt' => 0,
                                // ]);
                                $PayrollSalaryAdvanceLoanNOtherInstallment[] = [
                                    'payroll_salary_advance_n_loan_id' => $loan->id,
                                    'coa_id' => 0,
                                    'amount' => $loan->primary_installment_amount,
                                    'remaining_amount' => $loan->outstanding_balance,
                                    'adjustment_status' => 'with_salary',
                                    'is_bad_debt' => 0,
                                ];

                                //...todo::this update should be made after final salary generation
                                // $loan->outstanding_balance -= $loan->primary_installment_amount;
                                // $loan->is_fully_paid = $loan->outstanding_balance == 0? 1 : 0;
                                // $loan->save();

                            }
                        }
                    }
                }
                // Log::info('Line 103', ['payload' => $user]);
                //.... process meal cost
                //prent days x meal cost = meal cost deduction
                //insert into payroll_pre_salary_sheet_deductions table

                $total_present_days = 1; //todo::
                $absent_days = 0; //todo::
                $late_days = 0; //todo::
                $deductable_late_days = 0; //todo::


                if($total_present_days > 0)
                {
                    $total_meal_cost_this_month = $total_present_days * $user->hasOfficialInformation->per_meal_cost;

                    $PayrollPreSalarySheetDeduction[] = [
                        'child_data_identifier_key_incoming' => null,
                        'amount' => $total_meal_cost_this_month,
                        'type' => 'meal',
                        'created_at' => date('Y-m-d H:i:s'),
                    ];
                }

                //.... process PR (Jorimana) installments
                //.... get PR data of this employee
                // loop through each PR
                $pr = PrProblemRegisterAccousedPerson::with('hasJudgementReport')
                    ->where('user_id', $user->id)
                    ->where('is_fully_paid', 0)
                    ->where('balance', '>', 0)
                    ->whereNotNull('deleted_by')
                    ->get();

                if($pr->count() > 0)
                {
                    // loop through each PR
                    foreach($pr as $item)
                    {
                        $PayrollPreSalarySheetDeduction[] = [
                            'child_data_identifier_key_incoming' => 'payroll_pre_salary_sheet_deductions_'.$item->id,
                            'amount' => $item->installment_amount,
                            'type' => 'pr',
                            'created_at' => date('Y-m-d H:i:s'),
                        ];

                    }
                }





                    //..... calculate absent deductions

                    //
                    $lateAttendanceRecord = LateAttendanceRecord::where('employee_user_id', $user->id)
                        ->where('attendance_date', $this->payload['attendance_date']) //todo:: ??? why date ? when calculating for whole month
                        ->where('month', $this->payload['month'] ?? date('m'))
                        ->where('year', $this->payload['year'] ?? date('Y'))
                        ->where('deduction_status', 'Pending')
                        ->where('calculated_deduction', '>',0)
                        ->whereNull('deleted_by')
                        ->whereNull('deleted_at')
                        ->selectRaw('SUM(applied_deduction) as total_applied_deduction, COUNT(*) as total_records')
                        ->first();
                        ;

                    //...... calculate late deductions


                    //.... send notification to every employee that his/her salary is processed

                    //deduction type -> 'loan','pr','absent','pf','meal','late','ait', 'ot'




                $total_present_days = 1; //todo::
                $absent_days = 0; //todo::
                $late_days = $lateAttendanceRecord->total_records ?? 0; //todo::
                $deductable_late_days = $lateAttendanceRecord->total_records ?? 0; //todo::

                //.... process meal cost
                //prent days x meal cost = meal cost deduction
                //insert into payroll_pre_salary_sheet_deductions table
                if($total_present_days > 0 && $user->hasOfficialInformation->is_mealable == 1)
                {
                    $total_meal_cost_this_month = $total_present_days * $user->hasOfficialInformation->per_meal_cost;

                    $PayrollPreSalarySheetDeduction[] = [
                        'child_data_identifier_key_incoming' => null,
                        'amount' => $total_meal_cost_this_month,
                        'type' => 'meal',
                        'created_at' => date('Y-m-d H:i:s'),
                    ];
                }

                // employee AIT calculaiton
                if($user->hasOfficialInformation->ait_eligible == 1)
                {
                    if ($user->hasOfficialInformation->ait_deduction_basis == 'fixed') {
                        $ait_amount = $user->hasOfficialInformation->ait_amount;
                    } elseif ($user->hasOfficialInformation->ait_deduction_basis == 'basic') {
                        $basic = SalaryHead::where('is_percentage_determiner', true)->orderBy('id', 'desc')->first();
                        $basicAmount = PayrollSalaryHead::where('employee_user_id', $user->id)->where('salary_head_id', $basic->id)->first()->amount;

                        $ait_amount = ($user->hasOfficialInformation->ait_ptc / 100) * $basicAmount; //calculation to be updated

                    } else {
                        $ait_amount = ($user->hasOfficialInformation->ait_ptc / 100) * $user->hasOfficialInformation->gross_salary;
                    }

                    $PayrollPreSalarySheetDeduction[] = [
                        'child_data_identifier_key_incoming' => null,
                        'amount' => $ait_amount,
                        'type' => 'ait',
                        'created_at' => date('Y-m-d H:i:s'),
                    ];
                }


                // employee PF calculaiton
                if($user->hasOfficialInformation->pf_eligibility_status == 1)
                {
                    $employeePfPolicyMaxDeduction = $user->hasOfficialInformation->employeePfPolicy->max_deduction_amount; // 1500
                    $employeePfPercentage = SalaryHead::where('id', 12)->first()->percentage;
                    $employeePfAmount = $user->hasOfficialInformation->gross_salary * ($employeePfPercentage / 100);
                    if ($employeePfAmount > $employeePfPolicyMaxDeduction) {
                        $pf_amount = $employeePfPolicyMaxDeduction;
                    } else {
                        $pf_amount = $employeePfAmount;
                    }

                    $PayrollPreSalarySheetDeduction[] = [
                        'child_data_identifier_key_incoming' => null,
                        'amount' => $pf_amount,
                        'type' => 'pf',
                        'created_at' => date('Y-m-d H:i:s'),
                    ];
                }

                // employee OT calculaiton
                if($user->hasOfficialInformation->employee_ot_policy_id != null)
                {
                    $employeeOtPolicy = $user->hasOfficialInformation->employeeOtPolicy;
                    if(now()->toDateString() >= $employeeOtPolicy->effective_date && $employeeOtPolicy->status == 1)
                    {
                        $employeeOtAmount = EmployeeOtData::where('employee_user_id', $user->id)
                                            ->whereYear('ot_date', now()->year)
                                            ->whereMonth('ot_date', now()->month)
                                            ->sum('ot_amount');
                        $PayrollPreSalarySheetDeduction[] = [
                            'child_data_identifier_key_incoming' => null,
                            'amount' => $employeeOtAmount,
                            'type' => 'ot',
                            'created_at' => date('Y-m-d H:i:s'),
                        ];
                    }

                }



                //.... process and gather other regular salary components(like basic salary, allowances, deductions(attendance related), etc.)
                    //get Payroll salary heads ( individual employee employee salary heads like: basic, medical, House rent etc)
                    //.... get salary components data of this employee
                    $head_amounts = SalaryHead::select('payroll_salary_heads.amount','salary_heads.id','payroll_salary_heads.is_earning')
                                    ->leftJoin('payroll_salary_heads', 'payroll_salary_heads.salary_head_id', '=', 'salary_heads.id')
                                    ->where('payroll_salary_heads.employee_user_id', $user->id)
                                    ->get();

                    // loop through each head
                    //.... insert salary to TempSalary
                    foreach($head_amounts as $head)
                    {
                        $PayrollSalarySheetHeads[] = [
                            'salary_sheet_temp_id' => $this->payload['salary_sheet_temp_id'] ?? null,
                            'head_id' => $head->id,
                            'value' => $head->amount,
                            'is_earning' => $head->is_earning,
                            'created_user_id' => 1, // todo:: need a system user id
                            'created_at' => date('Y-m-d H:i:s'),
                        ];
                    }

            }
        );


    }

}
