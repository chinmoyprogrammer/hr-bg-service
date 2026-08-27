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
use App\Models\MealLoanAitPfEtcPause;
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
        $PayrollSalarySheetTemp = [];
        $PayrollPreSalarySheetDeduction = [];
        $PayrollSalaryAdvanceLoanNOtherInstallment = [];
        $PayrollSalarySheetHeadsTemp = [];
        $EmployeePfContribution = [];
        $payrollAccruedAllowanceIncome = [];

        $userQuery = User::with(
            [
                'hasOfficialInformation' => function ($query) {
                    $query->whereNull('deleted_at')->whereNull('deleted_by');
                },
                'hasOfficialInformation.employeePfPolicy' => function ($query) {
                    $query->whereNull('deleted_at')->whereNull('deleted_by');
                },
                'hasOfficialInformation.hasPfContribution' => function ($query) use ($payload) {
                    $query->whereNull('deleted_at')
                        ->whereNull('deleted_by')
                        ->orderBy('effective_date', 'desc');
                },
                'loans' => function ($query) {
                    $query->whereNull('deleted_at')
                        ->whereNull('deleted_by')
                        ->where('is_fully_paid', 0);
                },
                'hasOfficialInformation.attendanceLogs' => function ($query) use ($payload) {
                    $query->whereNull('deleted_at')
                        ->whereNull('deleted_by')
                        ->whereBetween('attendance_date',  [
                            date('Y-m-01', strtotime($payload['salary_calculate_month_year'])),
                            date('Y-m-t', strtotime($payload['salary_calculate_month_year']))
                        ])
                        ->whereHas('attendance', function ($query) use ($payload) {
                            $query->whereNull('deleted_at')
                                ->whereNull('deleted_by');
                        });
                },
                'hasOfficialInformation.hasLateAttendanceRecords' => function ($query) use ($payload) { //// this is basically for "deductable" late attendance records
                    $query->whereNull('deleted_at')
                        ->whereNull('deleted_by')
                        ->where(
                            'month',
                            date('m', strtotime($payload['salary_calculate_month_year']))
                        )
                        ->where('year', date('Y', strtotime($payload['salary_calculate_month_year'])))
                        ->whereRaw('deduction_status != "Waived"');
                },
                'hasOfficialInformation.hasSalaryStructure' => function ($query) {
                    $query->whereNull('deleted_at')->whereNull('deleted_by');
                },
                'hasOfficialInformation.hasPrProblemRegisterAccousedPerson' => function ($query) {
                    $query->whereNull('deleted_at')
                        ->whereNull('deleted_by')
                        ->whereHas('problemRegister', function ($query) {
                            $query->whereNull('deleted_at')
                                ->whereNull('deleted_by');
                        });
                },
                'hasOfficialInformation.hasLateDeductionPolicy' => function ($query) {
                    $query->whereNull('deleted_at')->whereNull('deleted_by');
                },
                'hasOfficialInformation.hasMealLoanAitPfEtcPauses' => function ($query) {
                    $query->whereNull('deleted_at')->whereNull('deleted_by');
                },
                'hasOfficialInformation.hasPayrollPreSalarySheetDeductions' => function ($query) use ($payload) {
                    $query->whereNull('deleted_at')
                    ->whereNull('deleted_by')
                    ->where('year', date('Y', strtotime($payload['salary_calculate_month_year'])))
                    ->where('month', date('m', strtotime($payload['salary_calculate_month_year'])))
                    ;
                },
            ]
        )
            ->where('status', 1)
            ->where('user_type_id', 1) // 1 = employee
            ->where('is_draft', 0)
            ->whereNull('deleted_by')
            ->whereNull('deleted_at')
            ->whereHas('hasOfficialInformation');
        if (isset($payload['employeeIds']) && count($payload['employeeIds']) > 0) {
            $userQuery->whereIn('id', $payload['employeeIds']);
        }
        $userQuery->lazy()
            ->each(function ($user) use (&$PayrollPreSalarySheetDeduction, &$PayrollSalaryAdvanceLoanNOtherInstallment, &$PayrollSalarySheetHeadsTemp, $payload, &$EmployeePfContribution, &$PayrollSalarySheetTemp) {
                $total_earning = $total_deductable = $net_salary_payable = 0;
                $generateMonth = date('n', strtotime($payload['salary_calculate_month_year']));
                $generateYear  = date('Y', strtotime($payload['salary_calculate_month_year']));

                $basicSalary = $user->hasOfficialInformation->hasSalaryStructure?->where('salary_head_id', 6)->first()?->amount ?? 0; // basic salary, 6 = basic head
                //Log::info("Basic Salary: ", ['basicSalary' => $basicSalary]);
                $gross_salary_of_1_day = $user->hasOfficialInformation->gross_salary / date('t', strtotime($payload['salary_calculate_month_year']));
                $basic_salary_of_1_day =  $basicSalary / date('t', strtotime($payload['salary_calculate_month_year']));


                //.... todo:: need to reconstruct this part with new logic, kaaj cholche
                if ($user->loans?->count() > 0) {
                    $total_loan_amount = 0;
                    foreach ($user->loans as $loan) {
                        //.... process loan installments ( user can make a request for pausing the installment deduction for the month, need a history/ settings table )
                        if ($loan != null && gettype($loan) == 'object') {
                            //Log::info('\n hasMealLoanAitPfEtcPauses: ', ['hasMealLoanAitPfEtcPauses' => $user->hasOfficialInformation->hasMealLoanAitPfEtcPauses()->get()]);
                            $hasLoanPause = $user->hasOfficialInformation->hasMealLoanAitPfEtcPauses()
                                ?->where('pause_month', $generateMonth)
                                ->where('pause_year', $generateYear)
                                ->where('pause_type', 2)       // 2 = loan installment deduction pause
                                ->where('approval_status', 1)  // 1 = approved
                                ->exists();
                            //Log::info('\n hasLoanPause: ', ['hasLoanPause' => $hasLoanPause]);
                            $deduct_loan_this_month = 1;
                            if ($deduct_loan_this_month && !$hasLoanPause) {
                                $PayrollPreSalarySheetDeduction[] = [
                                    'salary_sheet_temp_id' => null,
                                    'employee_id_for_mapping' => $user->id,
                                    'employee_user_id' => $user->id,
                                    'child_data_identifier_key_incoming' => 'payroll_salary_advance_n_loans_' . $loan->id,
                                    'amount' => $loan->primary_installment_amount,
                                    'type' => 'loan',
                                    'created_at' => date('Y-m-d H:i:s'),
                                    'created_user_id' => getUserId(),
                                ];
                                // insert data to PayrollSalaryAdvanceLoanNOtherInstallments
                                $PayrollSalaryAdvanceLoanNOtherInstallment[] = [
                                    'salary_sheet_temp_id' => null,
                                    'employee_id_for_mapping' => $user->id,
                                    'payroll_salary_advance_n_loan_id' => $loan->id,
                                    'coa_id' => 0,
                                    'amount' => $loan->primary_installment_amount,
                                    'remaining_amount' => $loan->outstanding_balance,
                                    'adjustment_status' => 'with_salary',
                                    'is_bad_debt' => 0,
                                    'created_at' => date('Y-m-d H:i:s'),
                                    'created_user_id' => getUserId(),
                                    'child_data_identifier_key_incoming' => null,
                                ];
                                $total_loan_amount += $loan->primary_installment_amount;
                            }
                        }
                    }
                    if ($total_loan_amount > 0) {
                        $total_deductable += $total_loan_amount;
                        $PayrollSalarySheetHeadsTemp[] = [
                            'salary_sheet_temp_id' => null,
                            'employee_id_for_mapping' => $user->id,
                            'head_id' => 18, //loan
                            'value' => $total_loan_amount,
                            'is_earning' => 0,
                            'created_user_id' => getUserId(), // todo:: need a system user id
                            'created_at' => date('Y-m-d H:i:s'),
                        ];
                    }
                }

                //... calculate late deductions
                $total_present_days = $user->hasOfficialInformation->attendanceLogs?->where('attendance_status', 1)->count() ?? 0;
                $deductable_late_days = $user->hasOfficialInformation->hasLateAttendanceRecords?->count() ?? 0; // this is basically for "deductable" late attendance records

                if ($deductable_late_days > 0) {
                    $hasLateAttendanceRecordsIds = $user->hasOfficialInformation->hasLateAttendanceRecords?->pluck('id')->toArray();
                    $hasLateAttendanceRecordsIds = implode('_', $hasLateAttendanceRecordsIds);
                    $deductable_late_minutes = $user->hasOfficialInformation->hasLateAttendanceRecords?->sum('late_minutes') ?? 0; // this is basically for "deductable" late attendance records

                    $late_deductable_amount = 0;
                    $salary = $gross_salary_of_1_day;
                    $late_deduction_policy = $user->hasOfficialInformation->hasLateDeductionPolicy;
                    if ($late_deduction_policy && $late_deduction_policy->deduction_from == 'Gross') {
                        $salary = $gross_salary_of_1_day;
                    }

                    if ($late_deduction_policy && $late_deduction_policy->deduction_from == 'Basic') {
                        $salary = $basic_salary_of_1_day;
                    }

                    if ($late_deduction_policy && $late_deduction_policy->deduction_basis == 'Day') {
                        $late_deductable_amount = $salary * $deductable_late_days;
                    }

                    if ($late_deduction_policy && $late_deduction_policy->deduction_basis == 'Minutes') {
                        $late_deductable_amount = $salary * ($deductable_late_minutes / 60);
                    }

                    //... need to check that late deduction amount already in "payroll_pre_salary_sheet_deductions" table or not
                    $existedLateDeductionRows = $user
                        ->hasOfficialInformation
                        ->hasPayrollPreSalarySheetDeductions?->
                        where('employee_user_id', $user->id)->where('type', 'late')
                        ;


                    $isLateDeductionExist = $existedLateDeductionRows->count() > 0;

                    if ($late_deductable_amount > 0) {

                        if($isLateDeductionExist == false)
                        {
                            $total_deductable += $late_deductable_amount;
                            $PayrollPreSalarySheetDeduction[] = [
                                'salary_sheet_temp_id' => null,
                                'employee_id_for_mapping' => $user->id,
                                'employee_user_id' => $user->id,
                                'child_data_identifier_key_incoming' => 'late_attendance_records_' . $hasLateAttendanceRecordsIds,
                                'amount' => $late_deductable_amount,
                                'type' => 'late',
                                'created_at' => date('Y-m-d H:i:s'),
                                'created_user_id' => getUserId(),
                            ];
                        }
                        else
                        {
                            $total_deductable += $existedLateDeductionRows->amount;
                            $late_deductable_amount = $existedLateDeductionRows->amount;
                        }

                        $PayrollSalarySheetHeadsTemp[] = [
                            'salary_sheet_temp_id' => null,
                            'employee_id_for_mapping' => $user->id,
                            'head_id' => 15, //late
                            'value' => $late_deductable_amount,
                            'is_earning' => 0,
                            'created_user_id' => getUserId(), // todo:: need a system user id
                            'created_at' => date('Y-m-d H:i:s'),
                        ];
                    }
                }

                //... calculate meal deductions
                $total_meal_cost_this_month = 0;
                if ($total_present_days > 0 && $user->hasOfficialInformation->is_mealable == 1 && $user->hasOfficialInformation->is_free_meal == 0) {
                    $mealPauseCount = $user->hasOfficialInformation->hasMealLoanAitPfEtcPauses()
                        ?->select('pause_date')->distinct()
                        ->whereMonth('pause_date', $generateMonth)
                        ->whereYear('pause_date', $generateYear)
                        ->where('pause_type', 1)       // 1 = meal deduction pause
                        ->where('approval_status', 1)  // 1 = approved
                        ->count() ?? 0;

                    $total_meal_cost_this_month = ($total_present_days - $mealPauseCount) * $user->hasOfficialInformation->per_meal_cost;

                    if ($total_meal_cost_this_month > 0) {
                        $total_deductable += $total_meal_cost_this_month;
                        $PayrollPreSalarySheetDeduction[] = [
                            'salary_sheet_temp_id' => null,
                            'employee_id_for_mapping' => $user->id,
                            'employee_user_id' => $user->id,
                            'child_data_identifier_key_incoming' => 'meal_cost_' . $user->id . '_' . $payload['salary_calculate_month_year'],
                            'amount' => $total_meal_cost_this_month,
                            'type' => 'meal',
                            'created_at' => date('Y-m-d H:i:s'),
                            'created_user_id' => getUserId(),
                        ];
                        $PayrollSalarySheetHeadsTemp[] = [
                            'salary_sheet_temp_id' => null,
                            'employee_id_for_mapping' => $user->id,
                            'head_id' => 13, //meal
                            'value' => $total_meal_cost_this_month,
                            'is_earning' => 0,
                            'created_user_id' => getUserId(), // todo:: need a system user id
                            'created_at' => date('Y-m-d H:i:s'),
                        ];
                    }
                }

                //.... process PR (Jorimana) installments
                //.... get PR data of this employee
                // loop through each PR

                $pr = $user->hasOfficialInformation->hasPrProblemRegisterAccousedPerson;

                $total_pr_installment_amount = 0;
                if ($pr != null && $pr->count() > 0) {
                    // loop through each PR
                    foreach ($pr as $item) {
                        $hasPrPause = $user->hasOfficialInformation->hasMealLoanAitPfEtcPauses()
                            ?->where('pause_month', $generateMonth)
                            ->where('pause_year', $generateYear)
                            ->where('pause_type', 3)       // 3 = pr installment deduction pause
                            ->where('approval_status', 1)  // 1 = approved
                            ->exists();

                        if ($hasPrPause) {
                            continue;
                        }
                        $total_deductable += $item->installment_amount;
                        $PayrollPreSalarySheetDeduction[] = [
                            'salary_sheet_temp_id' => null,
                            'employee_id_for_mapping' => $user->id,
                            'employee_user_id' => $user->id,
                            'child_data_identifier_key_incoming' => 'pr_problem_register_accoused_persons_' . $item->id,
                            'amount' => $item->installment_amount,
                            'type' => 'pr',
                            'created_at' => date('Y-m-d H:i:s'),
                            'created_user_id' => getUserId(),
                        ];

                        $PayrollSalaryAdvanceLoanNOtherInstallment[] = [
                            'salary_sheet_temp_id' => null,
                            'employee_id_for_mapping' => $user->id,
                            'payroll_salary_advance_n_loan_id' => null,
                            'coa_id' => 0,
                            'amount' => $item->installment_amount,
                            'remaining_amount' => $item->balance - $item->installment_amount,
                            'adjustment_status' => 'with_salary',
                            'is_bad_debt' => 0,
                            'created_at' => date('Y-m-d H:i:s'),
                            'created_user_id' => getUserId(), // todo:: need a system user id
                            'child_data_identifier_key_incoming' => $item->child_data_identifier_key_outgoing,
                        ];
                        $total_pr_installment_amount += $item->installment_amount;
                    }
                    if ($total_pr_installment_amount > 0) {
                        $PayrollSalarySheetHeadsTemp[] = [
                            'salary_sheet_temp_id' => null,
                            'employee_id_for_mapping' => $user->id,
                            'head_id' => 14, //pr
                            'value' => $total_pr_installment_amount,
                            'is_earning' => 0,
                            'created_user_id' => getUserId(), // todo:: need a system user id
                            'created_at' => date('Y-m-d H:i:s'),
                        ];
                    }
                }
                //...... calculate late deductions
                //.... send notification to every employee that his/her salary is processed
                //deduction type -> 'loan','pr-done','absent-done','pf-done','meal-done','late-done','ait-done', 'ot'

                //...... calculate absent deductions
                $absentDays = $user->hasOfficialInformation->attendanceLogs->where('attendance_status', 0)->count();
                $absentDeduction = $absentDays * $gross_salary_of_1_day; // $basic_salary_of_1_day
                if($absentDeduction > 0){
                    $total_deductable += $absentDeduction;

                    $PayrollPreSalarySheetDeduction[] = [
                        'salary_sheet_temp_id' => null,
                        'employee_id_for_mapping' => $user->id,
                        'employee_user_id' => $user->id,
                        'child_data_identifier_key_incoming' => 'absent_deduction_' . $user->id . '_' . $payload['salary_calculate_month_year'],
                        'amount' => $absentDeduction,
                        'type' => 'absent',
                        'created_at' => date('Y-m-d H:i:s'),
                        'created_user_id' => getUserId(),
                    ];

                    $PayrollSalarySheetHeadsTemp[] = [
                        'salary_sheet_temp_id' => null,
                        'employee_id_for_mapping' => $user->id,
                        'head_id' => 16, //absent
                        'value' => $absentDeduction,
                        'is_earning' => 0,
                        'created_user_id' => getUserId(), // todo:: need a system user id
                        'created_at' => date('Y-m-d H:i:s'),
                    ];


                }



                // employee AIT calculaiton
                if ($user->hasOfficialInformation->ait_eligible == 1) {
                    $hasAitPause = $user->hasOfficialInformation->hasMealLoanAitPfEtcPauses()
                        ?->where('pause_month', $generateMonth)
                        ->where('pause_year', $generateYear)
                        ->where('pause_type', 4)       // 4 = ait deduction pause
                        ->where('approval_status', 1)  // 1 = approved
                        ->exists() ?? 0;

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
                    if ($ait_amount > 0 && !$hasAitPause) {
                        $total_deductable += $ait_amount;
                        $PayrollPreSalarySheetDeduction[] = [
                            'salary_sheet_temp_id' => null,
                            'employee_id_for_mapping' => $user->id,
                            'employee_user_id' => $user->id,
                            'child_data_identifier_key_incoming' => 'ait_deduction_' . $user->id . '_' . $payload['salary_calculate_month_year'],
                            'amount' => $ait_amount,
                            'type' => 'ait',
                            'created_at' => date('Y-m-d H:i:s'),
                            'created_user_id' => getUserId(),
                        ];

                        $PayrollSalarySheetHeadsTemp[] = [
                            'salary_sheet_temp_id' => null,
                            'employee_id_for_mapping' => $user->id,
                            'head_id' => 11, //ait
                            'value' => $ait_amount,
                            'is_earning' => 0,
                            'created_user_id' => getUserId(), // todo:: need a system user id
                            'created_at' => date('Y-m-d H:i:s'),
                        ];
                    }
                }

                // employee PF calculaiton
                if ($user->hasOfficialInformation->pf_eligibility_status == 1 && $user->hasOfficialInformation->employeePfPolicy != null) {
                    $employeePfPolicyMaxDeduction = $user->hasOfficialInformation->employeePfPolicy?->max_deduction_amount ?? 1500; // 1500
                    // $employeePfPercentage = SalaryHead::where('id', 12)->first()->percentage;
                    // $employeePfAmount = $user->hasOfficialInformation->gross_salary * ($employeePfPercentage / 100);
                    $employeePfAmount = $user->hasOfficialInformation->hasSalaryStructure?->where('salary_head_id', 12)->first()?->amount ?? 0;
                    if ($employeePfAmount > $employeePfPolicyMaxDeduction) {
                        $pf_amount = $employeePfPolicyMaxDeduction;
                    } else {
                        $pf_amount = $employeePfAmount;
                    }

                    $hasPfPause = $user->hasOfficialInformation->hasMealLoanAitPfEtcPauses()
                        ?->where('pause_month', $generateMonth)
                        ->where('pause_year', $generateYear)
                        ->where('pause_type', 5)       // 5 = pf deduction pause
                        ->where('approval_status', 1)  // 1 = approved
                        ->exists() ?? 0;

                    if ($pf_amount > 0 && !$hasPfPause) {
                        $PayrollPreSalarySheetDeduction[] = [
                            'salary_sheet_temp_id' => null,
                            'employee_id_for_mapping' => $user->id,
                            'employee_user_id' => $user->id,
                            'child_data_identifier_key_incoming' => 'pf_deduction_' . $user->id . '_' . $payload['salary_calculate_month_year'],
                            'amount' => $pf_amount,
                            'type' => 'pf',
                            'created_at' => date('Y-m-d H:i:s'),
                            'created_user_id' => getUserId(),
                        ];
                        $total_deductable += $pf_amount;

                        //....prepare data for employee pf contribution table
                        $latestPfContribution = $user->hasOfficialInformation->hasPfContribution()
                            ?->whereNull('deleted_at')
                            ->whereNull('deleted_by')
                            ->orderBy('effective_date', 'desc')
                            ->first();

                        $previousCumulativeAmount = $latestPfContribution?->cumulative_amount ?? 0;

                        $EmployeePfContribution[] = [
                            'salary_sheet_temp_id' => null,
                            'employee_id_for_mapping' => $user->id,
                            'child_data_identifier_key_incoming' => 'pf_deduction_' . $user->id . '_' . $payload['salary_calculate_month_year'],
                            'effective_date' => date('Y-m-t', strtotime("$generateYear-$generateMonth")),
                            'employee_pf_policy_id' => $user->hasOfficialInformation->employeePfPolicy?->id,
                            'employee_user_id' => $user->id,
                            'employee_contribution_amount' => $pf_amount,
                            'employer_contribution_amount' => $pf_amount,
                            'cumulative_amount' => $pf_amount + $previousCumulativeAmount,
                            'created_user_id' => getUserId(), // todo:: need a system user id
                            'created_at' => date('Y-m-d H:i:s'),
                        ];

                        $PayrollSalarySheetHeadsTemp[] = [
                            'salary_sheet_temp_id' => null,
                            'employee_id_for_mapping' => $user->id,
                            'head_id' => 12, //pf
                            'value' => $pf_amount,
                            'is_earning' => 0,
                            'created_user_id' => getUserId(), // todo:: need a system user id
                            'created_at' => date('Y-m-d H:i:s'),
                        ];
                    }
                }
                //.... process and gather other regular salary components(like basic salary, allowances, deductions(attendance related), etc.)
                //get Payroll salary heads ( individual employee employee salary heads like: basic, medical, House rent etc)
                //.... get salary components data of this employee
                $head_amounts = $user->hasOfficialInformation->hasSalaryStructure?->where('is_earning', 1);
                //Log::info('head_amounts', ['head_amounts' => $head_amounts]);

                // loop through each head
                //.... insert salary to TempSalary
                foreach ($head_amounts as $head) {
                    if ($head->amount > 0) {
                        $PayrollSalarySheetHeadsTemp[] = [
                            'salary_sheet_temp_id' => null,
                            'employee_id_for_mapping' => $user->id,
                            'head_id' => $head->salary_head_id,
                            'value' => $head->amount,
                            'is_earning' => $head->is_earning,
                            'created_user_id' => getUserId(), // todo:: need a system user id
                            'created_at' => date('Y-m-d H:i:s'),
                        ];
                        $total_earning += $head->amount;
                    }
                }
                // employee total salary calculation
                $PayrollSalarySheetTemp[] = [
                    'employee_user_id' => $user->id,
                    'total_earning' => $total_earning,
                    'total_deductable' => $total_deductable,
                    'net_salary_payable' => $total_earning - $total_deductable,
                    'month' => $generateMonth,
                    'year' => $generateYear,
                    'from_date' => date("Y-m-01", strtotime($payload['salary_calculate_month_year'])),
                    'to_date' => date("Y-m-t", strtotime($payload['salary_calculate_month_year'])),
                    'created_user_id' => getUserId(), // todo:: need a system user id
                    'created_at' => date('Y-m-d H:i:s'),
                ];
            });
        if ($PayrollSalarySheetTemp && count($PayrollSalarySheetTemp) > 0) {
            DB::transaction(function () use (
                $PayrollSalarySheetTemp,
                $PayrollSalarySheetHeadsTemp,
                $PayrollPreSalarySheetDeduction,
                $PayrollSalaryAdvanceLoanNOtherInstallment,
                $payrollAccruedAllowanceIncome,
                $EmployeePfContribution,
                $payload
            ) {
                $month = date('n', strtotime($payload['salary_calculate_month_year']));
                $year  = date('Y', strtotime($payload['salary_calculate_month_year']));

                if (count($PayrollSalarySheetTemp) > 0) {

                    // ধাপ ০: আগের এই মাস-বছরের data থাকলে মুছে ফেলো (child আগে, parent পরে)
                    $oldIds = DB::table('payroll_salary_sheet_temp')
                        ->where('month', $month)
                        ->where('year', $year)
                        ->pluck('id');

                    if ($oldIds->isNotEmpty()) {
                        DB::table('payroll_salary_sheet_heads_temp')->whereIn('salary_sheet_temp_id', $oldIds)->delete();
                        DB::table('payroll_salary_sheet_temp')->whereIn('id', $oldIds)->delete();
                        DB::table('payroll_pre_salary_sheet_deductions')->whereIn('salary_sheet_temp_id', $oldIds)->delete(); // todo:: need to reconstruct this part with new logic
                        DB::table('payroll_salary_loan_advance_n_other_installments')->whereIn('salary_sheet_temp_id', $oldIds)->delete(); // todo:: need to reconstruct this part with new logic
                        //DB::table('payroll_accrued_allowance_income')->whereIn('salary_sheet_temp_id', $oldIds)->delete(); // todo:: need to reconstruct this part with new logic
                        //DB::table('employee_pf_contribution')->whereIn('salary_sheet_temp_id', $oldIds)->delete();
                    }
                    // insert payroll salary sheet temp
                    foreach (array_chunk($PayrollSalarySheetTemp, 500) as $chunk) {
                        DB::table('payroll_salary_sheet_temp')->insert($chunk);
                    }

                    // ধাপ ২: mapping বানাও — employee_user_id => id
                    $idMap = DB::table('payroll_salary_sheet_temp')
                        ->where('month', $month)
                        ->where('year', $year)
                        ->pluck('id', 'employee_user_id');

                    // ধাপ ৩: child array গুলোতে salary_sheet_temp_id বসাও, mapping key ফেলে দাও
                    $PayrollSalarySheetHeadsFinal = array_map(function ($row) use ($idMap) {
                        $row['salary_sheet_temp_id'] = $idMap[$row['employee_id_for_mapping']] ?? null;
                        unset($row['employee_id_for_mapping']);
                        return $row;
                    }, $PayrollSalarySheetHeadsTemp);

                    // ধাপ ৪: child table batch insert (chunk করে)
                    foreach (array_chunk($PayrollSalarySheetHeadsFinal, 500) as $chunk) {
                        DB::table('payroll_salary_sheet_heads_temp')->insert($chunk);
                    }
                    // ধাপ 5: child array গুলোতে salary_sheet_temp_id বসাও, mapping key ফেলে দাও
                    $PayrollPreSalarySheetDeductionFinal = array_map(function ($row) use ($idMap) {
                        $row['salary_sheet_temp_id'] = $idMap[$row['employee_id_for_mapping']] ?? null;
                        unset($row['employee_id_for_mapping']);
                        return $row;
                    }, $PayrollPreSalarySheetDeduction);

                    // ধাপ 6: child table batch insert (chunk করে)
                    foreach (array_chunk($PayrollPreSalarySheetDeductionFinal, 500) as $chunk) {
                        DB::table('payroll_pre_salary_sheet_deductions')->insert($chunk);
                    }

                    // ধাপ 5: child array গুলোতে salary_sheet_temp_id বসাও, mapping key ফেলে দাও
                    $PayrollSalaryAdvanceLoanNOtherInstallmentFinal = array_map(function ($row) use ($idMap) {
                        $row['salary_sheet_temp_id'] = $idMap[$row['employee_id_for_mapping']] ?? null;
                        unset($row['employee_id_for_mapping']);
                        return $row;
                    }, $PayrollSalaryAdvanceLoanNOtherInstallment);

                    // ধাপ 6: child table batch insert (chunk করে)
                    foreach (array_chunk($PayrollSalaryAdvanceLoanNOtherInstallmentFinal, 500) as $chunk) {
                        DB::table('payroll_salary_loan_advance_n_other_installments')->insert($chunk);
                    }

                    // // ধাপ 5: child array গুলোতে salary_sheet_temp_id বসাও, mapping key ফেলে দাও
                    // $payrollAccruedAllowanceIncomeFinal = array_map(function ($row) use ($idMap) {
                    //     $row['salary_sheet_temp_id'] = $idMap[$row['employee_id_for_mapping']] ?? null;
                    //     unset($row['employee_id_for_mapping']);
                    //     return $row;
                    // }, $payrollAccruedAllowanceIncome);

                    // // ধাপ 6: child table batch insert (chunk করে)
                    // foreach (array_chunk($payrollAccruedAllowanceIncomeFinal, 500) as $chunk) {
                    //     DB::table('payroll_accrued_allowance_income')->insert($chunk);
                    // }

                    // // ধাপ 5: child array গুলোতে salary_sheet_temp_id বসাও, mapping key ফেলে দাও
                    // $EmployeePfContributionFinal = array_map(function ($row) use ($idMap) {
                    //     $row['salary_sheet_temp_id'] = $idMap[$row['employee_id_for_mapping']] ?? null;
                    //     unset($row['employee_id_for_mapping']);
                    //     return $row;
                    // }, $EmployeePfContribution);

                    // // ধাপ 6: child table batch insert (chunk করে)
                    // foreach (array_chunk($EmployeePfContributionFinal, 500) as $chunk) {
                    //     DB::table('employee_pf_contribution')->insert($chunk);
                    // }
                }
            });
        }
    }
}
