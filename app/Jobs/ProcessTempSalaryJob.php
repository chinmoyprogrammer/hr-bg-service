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
        $EmployeePfContributionPreparedData = [];
        $payrollAccruedAllowanceIncomeData = [];



        User::with(
                [
                    'hasOfficialInformation' => function ($query) {
                        $query->whereNull('deleted_at')->whereNull('deleted_by');
                    },
                    'hasOfficialInformation.employeePfPolicy' => function ($query) {
                        $query->whereNull('deleted_at')->whereNull('deleted_by');
                    },
                    'hasOfficialInformation.hasPfContribution' => function($query) use($payload){
                        $query->whereNull('deleted_at')
                            ->whereNull('deleted_by')
                            ->whereYear('effective_date', date('Y', strtotime($payload['salary_calculate_month_year'])))
                            ->orderBy('effective_date', 'desc');
                    },
                    'loans' => function($query){
                        $query->whereNull('deleted_at')
                            ->whereNull('deleted_by')
                            ->where('is_fully_paid', 0);
                    },
                    'hasOfficialInformation.attendanceLogs' => function($query) use($payload) {
                            $query->whereNull('deleted_at')
                                ->whereNull('deleted_by')
                                ->whereBetween('attendance_date',  [
                                    date('Y-m-01', strtotime($payload['salary_calculate_month_year'])),
                                    date('Y-m-t', strtotime($payload['salary_calculate_month_year']))
                                ]);
                    },
                    'hasOfficialInformation.hasLateAttendanceRecords' => function($query) use($payload) { //// this is basically for "deductable" late attendance records
                        $query->whereNull('deleted_at')
                            ->whereNull('deleted_by')
                            ->where('month',
                                date('m', strtotime($payload['salary_calculate_month_year']))
                            )
                            ->where('year', date('Y', strtotime($payload['salary_calculate_month_year'])));
                    },
                    'hasOfficialInformation.hasSalaryStructure' => function ($query) {
                        $query->whereNull('deleted_at')->whereNull('deleted_by');
                    },
                    'hasOfficialInformation.hasPrProblemRegisterAccousedPerson' => function ($query) {
                        $query->whereNull('deleted_at')->whereNull('deleted_by');
                    },
                    'hasOfficialInformation.hasOTData' => function ($query) {
                        $query->whereNull('deleted_at')->whereNull('deleted_by');
                    },
                    'hasOfficialInformation.hasLateDeductionPolicy' => function ($query) {
                        $query->whereNull('deleted_at')->whereNull('deleted_by');
                    },
                ]
            )
            ->where('status', 1)
            ->where('user_type_id', 1) // 1 = employee
            ->where('is_draft', 0)
            ->whereNull('deleted_by')
            ->whereNull('deleted_at')
            ->lazy()
            ->each(function ($user) use(&$PayrollPreSalarySheetDeduction, &$PayrollSalaryAdvanceLoanNOtherInstallment, &$PayrollSalarySheetHeads, $payload, &$payrollAccruedAllowanceIncomeData,&$EmployeePfContributionPreparedData)
            {
                if($user->id <> 200){
                    return;
                }
                Log::info("Main User Row: ", ['user' => $user]);
                $basicSalary = $user->hasOfficialInformation->hasSalaryStructure->where('salary_head_id',6)->first()->amount; // basic salary, 6 = basic head
                Log::info("Basic Salary: ", ['basicSalary' => $basicSalary]);

                $gross_salary_of_1_day = $user->hasOfficialInformation->gross_salary / date('t', strtotime($payload['salary_calculate_month_year']));
                $basic_salary_of_1_day =  $basicSalary/ date('t', strtotime($payload['salary_calculate_month_year'])); 
                // Log::info('Line 52', ['payload' => $user]);
                // Log::info('Line 52', ['payload' => $user]);
                Log::info('Loan data: ', ['user->loans' => $user->loans]);
                if($user->loans->count() > 0)
                {
                    $total_loan_amount = 0;
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
                                    'created_at' => date('Y-m-d H:i:s'),
                                    'child_data_identifier_key_incoming' => null,
                                ];

                                //...todo::this update should be made after final salary generation
                                // $loan->outstanding_balance -= $loan->primary_installment_amount;
                                // $loan->is_fully_paid = $loan->outstanding_balance == 0? 1 : 0;
                                // $loan->save();
                                $total_loan_amount += $loan->primary_installment_amount;

                            }
                        }
                    }

                    $PayrollSalarySheetHeads[] = [
                            'salary_sheet_temp_id' => $this->payload['salary_sheet_temp_id'] ?? null,
                            'head_id' => 18, //loan
                            'value' => $total_loan_amount,
                            'is_earning' => 0,
                            'created_user_id' => 1, // todo:: need a system user id
                            'created_at' => date('Y-m-d H:i:s'),
                        ];


                }
                // Log::info('Line 103', ['payload' => $user]);
                //.... process meal cost
                //prent days x meal cost = meal cost deduction
                //insert into payroll_pre_salary_sheet_deductions table

                $total_present_days = 0; //todo::
                    //days of calender month - absent days - leave taken - weekend days -holidays = present days of the month
                $calender_days = date('t', strtotime($payload['salary_calculate_month_year'] ));
                $absent_days = $user->hasOfficialInformation->attendanceLogs->where('attendance_status', 0)->count();
                $absent_days_2half_days = $user->hasOfficialInformation->attendanceLogs->where('attendance_status', 15)->count();


                $leave_full_days = $user->hasOfficialInformation->attendanceLogs->where('attendance_status', 8)->count(); 
                $leave_first_half_days = $user->hasOfficialInformation->attendanceLogs->where('attendance_status', 4)->count(); 
                $leave_second_half_days = $user->hasOfficialInformation->attendanceLogs->where('attendance_status', 5)->count(); 
                $weekend_days = $user->hasOfficialInformation->attendanceLogs->where('attendance_status', 17)->count();
                $holidays_days = $user->hasOfficialInformation->attendanceLogs->where('attendance_status', 21)->count();


                $total_present_days = $calender_days - $absent_days - $absent_days_2half_days - $leave_full_days - $leave_first_half_days - $leave_second_half_days - $weekend_days - $holidays_days;


                $absent_days = $absent_days + $absent_days_2half_days; //todo::
                $late_days = $user->hasOfficialInformation->attendanceLogs->where('attendance_status', 2)->count(); //todo::
                $deductable_late_days = $user->hasOfficialInformation->hasLateAttendanceRecords->count(); // this is basically for "deductable" late attendance records
                $deductable_late_minutes = $user->hasOfficialInformation->hasLateAttendanceRecords->sum('late_minutes'); // this is basically for "deductable" late attendance records

                if($deductable_late_days > 0)
                {
                    $late_deduction_policy = $user->hasOfficialInformation->hasLateDeductionPolicy;
                    if($late_deduction_policy && $late_deduction_policy->deduction_from == 'Gross')
                    {
                        $salary = $gross_salary_of_1_day;
                    }

                    if($late_deduction_policy && $late_deduction_policy->deduction_from == 'Basic')
                    {
                        $salary = $basic_salary_of_1_day;
                    }



                    if($late_deduction_policy && $late_deduction_policy->deduction_basis == 'Day')
                    {
                        $late_deductable_amount = $basic_salary_of_1_day * $deductable_late_days;
                    }

                    if($late_deduction_policy && $late_deduction_policy->deduction_basis == 'Minutes')
                    {
                        $late_deductable_amount = $basic_salary_of_1_day * ($deductable_late_minutes/60);
                    }



                    $PayrollPreSalarySheetDeduction[] = [
                        'child_data_identifier_key_incoming' => null,
                        'amount' => $late_deductable_amount,
                        'type' => 'late',
                        'created_at' => date('Y-m-d H:i:s'),
                    ];


                    $PayrollSalarySheetHeads[] = [
                        'salary_sheet_temp_id' => $this->payload['salary_sheet_temp_id'] ?? null,
                        'head_id' => 15, //late
                        'value' => $late_deductable_amount,
                        'is_earning' => 0,
                        'created_user_id' => 1, // todo:: need a system user id
                        'created_at' => date('Y-m-d H:i:s'),
                    ];
                }




                $total_meal_cost_this_month = 0;


                if($total_present_days > 0 && $user->hasOfficialInformation->is_mealable == 1 && $user->hasOfficialInformation->is_free_meal == 0)
                {
                    $total_meal_cost_this_month = $total_present_days * $user->hasOfficialInformation->per_meal_cost;

                    $PayrollPreSalarySheetDeduction[] = [
                        'child_data_identifier_key_incoming' => null,
                        'amount' => $total_meal_cost_this_month,
                        'type' => 'meal',
                        'created_at' => date('Y-m-d H:i:s'),
                    ];


                    $PayrollSalarySheetHeads[] = [
                        'salary_sheet_temp_id' => $this->payload['salary_sheet_temp_id'] ?? null,
                        'head_id' => 13, //meal
                        'value' => $total_meal_cost_this_month,
                        'is_earning' => 0,
                        'created_user_id' => 1, // todo:: need a system user id
                        'created_at' => date('Y-m-d H:i:s'),
                    ];


                }

                //.... process PR (Jorimana) installments
                //.... get PR data of this employee
                // loop through each PR
                // $pr = PrProblemRegisterAccousedPerson::with('hasJudgementReport')
                //     ->where('user_id', $user->id)
                //     ->where('is_fully_paid', 0)
                //     ->where('balance', '>', 0)
                //     ->whereNotNull('deleted_by')
                //     ->get();

                $pr = $user->hasOfficialInformation->hasPrProblemRegisterAccousedPerson;

                $total_pr_installment_amount = 0;
                if($pr != null && $pr->count() > 0)
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

                        $PayrollSalaryAdvanceLoanNOtherInstallment[] = [
                            'payroll_salary_advance_n_loan_id' => null,
                            'coa_id' => 0,
                            'amount' => $item->installment_amount,
                            'remaining_amount' => $item->balance - $item->installment_amount,
                            'adjustment_status' => 'with_salary',
                            'is_bad_debt' => 0,
                            'created_at' => date('Y-m-d H:i:s'),
                            'child_data_identifier_key_incoming' => $item->child_data_identifier_key_outgoing,
                        ];
                        $total_pr_installment_amount += $item->installment_amount;

                    }

                    $PayrollSalarySheetHeads[] = [
                            'salary_sheet_temp_id' => $this->payload['salary_sheet_temp_id'] ?? null,
                            'head_id' => 14, //pr
                            'value' => $total_pr_installment_amount,
                            'is_earning' => 0,
                            'created_user_id' => 1, // todo:: need a system user id
                            'created_at' => date('Y-m-d H:i:s'),
                        ];
                }

                    //..... calculate absent deductions

                    //
                    // $lateAttendanceRecord = LateAttendanceRecord::where('employee_user_id', $user->id)
                    //     ->where('attendance_date', $this->payload['attendance_date']) //todo:: ??? why date ? when calculating for whole month
                    //     ->where('month', $this->payload['month'] ?? date('m'))
                    //     ->where('year', $this->payload['year'] ?? date('Y'))
                    //     ->where('deduction_status', 'Pending')
                    //     ->where('calculated_deduction', '>',0)
                    //     ->whereNull('deleted_by')
                    //     ->whereNull('deleted_at')
                    //     ->selectRaw('SUM(applied_deduction) as total_applied_deduction, COUNT(*) as total_records')
                    //     ->first();
                    //     ;

                    //...... calculate late deductions


                    //.... send notification to every employee that his/her salary is processed

                    //deduction type -> 'loan','pr','absent','pf','meal','late','ait', 'ot'




                // $total_present_days = 1; //todo::
                // $absent_days = 0; //todo::
                // $late_days = $lateAttendanceRecord->total_records ?? 0; //todo::
                // $deductable_late_days = $lateAttendanceRecord->total_records ?? 0; //todo::

                // //.... process meal cost
                // //prent days x meal cost = meal cost deduction
                // //insert into payroll_pre_salary_sheet_deductions table
                // if($total_present_days > 0 && $user->hasOfficialInformation->is_mealable == 1)
                // {
                //     $total_meal_cost_this_month = $total_present_days * $user->hasOfficialInformation->per_meal_cost;

                //     $PayrollPreSalarySheetDeduction[] = [
                //         'child_data_identifier_key_incoming' => null,
                //         'amount' => $total_meal_cost_this_month,
                //         'type' => 'meal',
                //         'created_at' => date('Y-m-d H:i:s'),
                //     ];
                // }

                // employee AIT calculaiton
                if($user->hasOfficialInformation->ait_eligible == 1)
                {
                    if ($user->hasOfficialInformation->ait_deduction_basis == 'fixed') {
                        $ait_amount = $user->hasOfficialInformation->ait_amount;
                    } elseif ($user->hasOfficialInformation->ait_deduction_basis == 'basic') {
                        // $basicAmount = PayrollSalaryHead::join('salary_heads', 'payroll_salary_heads.salary_head_id', '=', 'salary_heads.id')
                        //                 ->where('employee_user_id', $user->id)
                        //                 ->where('salary_heads.is_percentage_determiner', true)
                        //                 ->first()->amount;

                        $ait_amount = ($user->hasOfficialInformation->ait_ptc / 100) * $basicSalary; //calculation to be updated

                    } else {
                        $ait_amount = ($user->hasOfficialInformation->ait_ptc / 100) * $user->hasOfficialInformation->gross_salary;
                    }

                    $PayrollPreSalarySheetDeduction[] = [
                        'child_data_identifier_key_incoming' => null,
                        'amount' => $ait_amount,
                        'type' => 'ait',
                        'created_at' => date('Y-m-d H:i:s'),
                    ];


                    $PayrollSalarySheetHeads[] = [
                            'salary_sheet_temp_id' => $this->payload['salary_sheet_temp_id'] ?? null,
                            'head_id' => 11, //ait
                            'value' => $ait_amount,
                            'is_earning' => 0,
                            'created_user_id' => 1, // todo:: need a system user id
                            'created_at' => date('Y-m-d H:i:s'),
                        ];
                }


                // employee PF calculaiton
                if($user->hasOfficialInformation->pf_eligibility_status == 1 && $user->hasOfficialInformation->employeePfPolicy != null)
                {
                    $employeePfPolicyMaxDeduction = $user->hasOfficialInformation->employeePfPolicy->max_deduction_amount; // 1500
                    // $employeePfPercentage = SalaryHead::where('id', 12)->first()->percentage;
                    // $employeePfAmount = $user->hasOfficialInformation->gross_salary * ($employeePfPercentage / 100);
                    $employeePfAmount = $user->hasOfficialInformation->hasSalaryStructure->where('salary_head_id',12)->first()->amount;
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

                    //....prepare data for employee pf contribution table
                    $EmployeePfContributionPreparedData[] = [
                        'employee_pf_policy_id' => $user->hasOfficialInformation->employeePfPolicy->id,
                        'employee_user_id' => $user->id,
                        'employee_contribution_amount' => $pf_amount,
                        'employer_contribution_amount' => $pf_amount,
                        'cumulative_amount' => $pf_amount+$user->hasOfficialInformation->hasPfContribution->first()->cumulative_amount,
                        'created_at' => date('Y-m-d H:i:s'),
                    ];


                    $PayrollSalarySheetHeads[] = [
                            'salary_sheet_temp_id' => $this->payload['salary_sheet_temp_id'] ?? null,
                            'head_id' => 12, //pf
                            'value' => $pf_amount,
                            'is_earning' => 0,
                            'created_user_id' => 1, // todo:: need a system user id
                            'created_at' => date('Y-m-d H:i:s'),
                        ];


                }

                // employee OT calculaiton
                if($user->hasOfficialInformation->employee_ot_policy_id != null)
                {
                    $employeeOtPolicy = $user->hasOfficialInformation->employeeOtPolicy;
                    if(time() >= strtotime($user->hasOfficialInformation->employeeOtPolicy->effective_date) && $employeeOtPolicy->status == 1 && $user->hasOfficialInformation->hasOTData != null)
                    {
                        $employeeOtAmount = $user->hasOfficialInformation->hasOTData
                                            ->where('ot_date', date('Y'))
                                            ->where('ot_date', date('m'))
                                            ->sum('ot_amount');
                        // $PayrollPreSalarySheetDeduction[] = [
                        //     'child_data_identifier_key_incoming' => null,
                        //     'amount' => $employeeOtAmount,
                        //     'type' => 'ot',
                        //     'created_at' => date('Y-m-d H:i:s'),
                        // ];
                    $payrollAccruedAllowanceIncomeData[] = [
                        'employee_user_id' => $user->id,
                        'ot_amount' => $employeeOtAmount,
                        'created_at' => date('Y-m-d H:i:s'),
                    ];


                    $PayrollSalarySheetHeads[] = [
                            'salary_sheet_temp_id' => $this->payload['salary_sheet_temp_id'] ?? null,
                            'head_id' => 17, //ot
                            'value' => $employeeOtAmount,
                            'is_earning' => 0,
                            'created_user_id' => 1, // todo:: need a system user id
                            'created_at' => date('Y-m-d H:i:s'),
                        ];

                    }

                }



                //.... process and gather other regular salary components(like basic salary, allowances, deductions(attendance related), etc.)
                    //get Payroll salary heads ( individual employee employee salary heads like: basic, medical, House rent etc)
                    //.... get salary components data of this employee
                    // $head_amounts = SalaryHead::select('payroll_salary_heads.amount','salary_heads.id','payroll_salary_heads.is_earning')
                    //                 ->leftJoin('payroll_salary_heads', 'payroll_salary_heads.salary_head_id', '=', 'salary_heads.id')
                    //                 ->where('payroll_salary_heads.employee_user_id', $user->id)
                    //                 ->get();
                    $head_amounts = $user->hasOfficialInformation->hasSalaryStructure->where('is_earning',1);

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
