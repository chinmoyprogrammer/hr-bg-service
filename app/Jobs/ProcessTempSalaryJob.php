<?php

namespace App\Jobs;

use App\Models\BusinessSetting;
use App\Models\EmployeeOfficialInformation;
use App\Models\EmployeeOtData;
use App\Models\LateAttendanceRecord;
use App\Models\MealLoanAitPfEtcPause;
use App\Models\PayrollPreSalarySheetDeduction;
use App\Models\PayrollSalaryAdvanceLoanNOtherInstallment;
use App\Models\PayrollSalaryAdvanceNLoan;
use App\Models\PayrollSalaryHead;
use App\Models\PrProblemRegisterAccousedPerson;
use App\Models\SalaryHead;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


class ProcessTempSalaryJob extends Job implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    protected array $payload;

    // seconds; overrides the worker's --timeout (salary for all employees runs long)
    public $timeout = 600;

    // salary head per loan type
    private const LOAN_HEADS = [
        'loan'    => 18, // Loan Installment(Long)
        'advance' => 20, // Loan Installment(Advance)
        'pf_loan' => 21, // Loan Installment(PF)
    ];

    // order loans are recovered in when net salary can't cover them all
    private const LOAN_PRIORITY = ['advance' => 1, 'loan' => 2, 'pf_loan' => 3];

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
        $PayrollAttendanceSummaryValues = [];
        $generateMonth = date('n', strtotime($payload['salary_calculate_month_year']));
        $generateYear  = date('Y', strtotime($payload['salary_calculate_month_year']));
        $meal_rate = BusinessSetting::where('settings_key', 'PER_MEAL_COST')->first()->value;

        // loan / pf_loan / advance installments due this month, grouped by employee.
        // Read from the repayment schedule (hr-admin LoanService owns it), so carried
        // amounts, reschedules, waivers and a smaller last installment are honoured.
        $dueLoanRowsByUser = $this->dueLoanRows((int) $generateMonth, (int) $generateYear, $payload['employeeIds'] ?? []);

        // PR damages paused for this month -- only pauses linked to one PR count
        $pausedPrKeys = DB::table('meal_loan_ait_pf_etc_pauses')
            ->where('pause_type', 3) // 3 = pr installment deduction pause
            ->where('pause_month', (int) $generateMonth)
            ->where('pause_year', (int) $generateYear)
            ->where('approval_status', 1)
            ->whereNull('deleted_at')
            ->where('child_data_identifier_key_incoming', 'like', 'pr\_problem\_register\_accoused\_persons\_%')
            ->pluck('child_data_identifier_key_incoming')
            ->all();

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
                'hasOfficialInformation.attendanceLogs' => function ($query) use ($payload) {
                    $query->whereNull('employee_attendance_status_logs.deleted_at')
                        ->whereNull('employee_attendance_status_logs.deleted_by')
                        ->whereBetween('employee_attendance_status_logs.attendance_date',  [
                            date('Y-m-01', strtotime($payload['salary_calculate_month_year'])),
                            date('Y-m-t', strtotime($payload['salary_calculate_month_year']))
                        ])
                        ->whereHas('attendance', function ($query) use ($payload) {
                            $query->whereNull('deleted_at')
                                ->whereNull('deleted_by');
                        })
                        ->join('employee_attendance','employee_attendance_status_logs.employee_attendance_id','=','employee_attendance.id')
                        ;
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
                    // only PR damages that still have something to collect
                    $query->whereNull('deleted_at')
                        ->whereNull('deleted_by')
                        ->where('is_fully_paid', 0)
                        ->where('balance', '>', 0)
                        ->where('installment_amount', '>', 0)
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
                'hasOfficialInformation.hasUnpaidLeave' => function ($query) use ($payload) {
                    $query->whereNull('deleted_at')
                        ->whereNull('deleted_by')
                        ->whereBetween('leave_date',  [
                            date('Y-m-01', strtotime($payload['salary_calculate_month_year'])),
                            date('Y-m-t', strtotime($payload['salary_calculate_month_year']))
                        ])
                    ;
                },
                'hasOfficialInformation.hasLateAttendanceConsiderationRequests' => function ($query) use ($payload) {
                    $query->whereNull('deleted_at')
                    ->whereNull('deleted_by')
                    ->where('month', date('m', strtotime($payload['salary_calculate_month_year'])))
                    ->where('year', date('Y', strtotime($payload['salary_calculate_month_year'])))
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
            ->each(function ($user) use (&$PayrollPreSalarySheetDeduction, &$PayrollSalaryAdvanceLoanNOtherInstallment, &$PayrollSalarySheetHeadsTemp, $payload, &$EmployeePfContribution, &$PayrollSalarySheetTemp, &$PayrollAttendanceSummaryValues,$meal_rate, $dueLoanRowsByUser, $pausedPrKeys) {
                $total_earning = $total_deductable = $net_salary_payable = 0;
                $generateMonth = date('n', strtotime($payload['salary_calculate_month_year']));
                $generateYear  = date('Y', strtotime($payload['salary_calculate_month_year']));

                $basicSalary = $user->hasOfficialInformation->hasSalaryStructure?->where('salary_head_id', 6)->first()?->amount ?? 0; // basic salary, 6 = basic head
                //Log::info("Basic Salary: ", ['basicSalary' => $basicSalary]);
                $gross_salary_of_1_day = $user->hasOfficialInformation->gross_salary / date('t', strtotime($payload['salary_calculate_month_year']));
                $basic_salary_of_1_day =  $basicSalary / date('t', strtotime($payload['salary_calculate_month_year']));


                //... calculate late deductions
                $total_present_days = $user->hasOfficialInformation->attendanceLogs?->where('attendance_status', 1)->count() ?? 0;
                //$deductable_late_days = $user->hasOfficialInformation->hasLateAttendanceRecords?->count() ?? 0; // this is basically for "deductable" late attendance records


                //... calculate payable days
                $unpaidLeaveDeduction = $user->hasOfficialInformation->hasUnpaidLeave->count() ?? 0;
                $late_deduction_policy = $user->hasOfficialInformation->hasLateDeductionPolicy;
                $late_attendance_consideration_requests = $user->hasOfficialInformation->hasLateAttendanceConsiderationRequests?->first()->consider_days ?? 0;
                $absentDays = $user->hasOfficialInformation->attendanceLogs?->where('attendance_status', 0)->count() ?? 0; 
                $weekendDays = $user->hasOfficialInformation->attendanceLogs?->where('attendance_status', 17)->count() ?? 0; 
                $holidayDays = $user->hasOfficialInformation->attendanceLogs?->where('attendance_status', 9)->count() ?? 0; 
                $paidLeaveDays = $user->hasOfficialInformation->attendanceLogs?->where('attendance_status', 8)->count() ?? 0; 

                //.... extract deductable late days, weekends, holidays, paid leave, absent days
                $late_days = $user->hasOfficialInformation->attendanceLogs?->where('attendance_status',2)->count() ?? 0;
                $deductable_late_days = (int) ($late_days / ($late_deduction_policy->max_late_days+1));


                $payable_days = $total_present_days 
                                - $unpaidLeaveDeduction 
                                + $late_attendance_consideration_requests 
                                - $deductable_late_days 
                                - $absentDays 
                                + $weekendDays 
                                + $holidayDays 
                                + $paidLeaveDays 
                                ;
                $attendance_summary_value_list = [
                    ['type'=>'0', 'value'=>$absentDays.(-1), 'year'=>$generateYear, 'month'=>$generateMonth, 'employee_user_id'=>$user->id, 'created_at'=>date('Y-m-d H:i:s'), 'created_by'=>getUserId()],
                    ['type'=>'1', 'value'=>$total_present_days, 'year'=>$generateYear, 'month'=>$generateMonth, 'employee_user_id'=>$user->id, 'created_at'=>date('Y-m-d H:i:s'), 'created_by'=>getUserId()],
                    ['type'=>'2', 'value'=>$deductable_late_days.(-1), 'year'=>$generateYear, 'month'=>$generateMonth, 'employee_user_id'=>$user->id, 'created_at'=>date('Y-m-d H:i:s'), 'created_by'=>getUserId()],
                    ['type'=>'8', 'value'=>$paidLeaveDays, 'year'=>$generateYear, 'month'=>$generateMonth, 'employee_user_id'=>$user->id, 'created_at'=>date('Y-m-d H:i:s'), 'created_by'=>getUserId()],
                    ['type'=>'9', 'value'=>$holidayDays, 'year'=>$generateYear, 'month'=>$generateMonth, 'employee_user_id'=>$user->id, 'created_at'=>date('Y-m-d H:i:s'), 'created_by'=>getUserId()],
                    ['type'=>'17', 'value'=>$weekendDays, 'year'=>$generateYear, 'month'=>$generateMonth, 'employee_user_id'=>$user->id, 'created_at'=>date('Y-m-d H:i:s'), 'created_by'=>getUserId()],
                    ['type'=>'24', 'value'=>$payable_days, 'year'=>$generateYear, 'month'=>$generateMonth, 'employee_user_id'=>$user->id, 'created_at'=>date('Y-m-d H:i:s'), 'created_by'=>getUserId()],
                    ['type'=>'25', 'value'=>$unpaidLeaveDeduction.(-1), 'year'=>$generateYear, 'month'=>$generateMonth, 'employee_user_id'=>$user->id, 'created_at'=>date('Y-m-d H:i:s'), 'created_by'=>getUserId()],
                    ['type'=>'26', 'value'=>$late_attendance_consideration_requests, 'year'=>$generateYear, 'month'=>$generateMonth, 'employee_user_id'=>$user->id, 'created_at'=>date('Y-m-d H:i:s'), 'created_by'=>getUserId()],
                ];


                //... insert data to PayrollAttendanceSummaryValues
                foreach($attendance_summary_value_list as $item)
                {
                    $PayrollAttendanceSummaryValues[] = [
                        'type'=>$item['type'],
                        'value'=>$item['value'],
                        'year'=>$item['year'],
                        'month'=>$item['month'],
                        'employee_user_id'=>$item['employee_user_id'],
                        'created_at'=>$item['created_at'],
                        'created_by'=>$item['created_by'],
                    ];
                }

                
                $gross_payable = $gross_salary_of_1_day * $payable_days;
                
                
                
                if ($deductable_late_days > 0) {
                    $deductable_late_minutes = $user->hasOfficialInformation->attendanceLogs?->where('attendance_status', 2)->sum(function ($log) {
                        if ($log->attendance?->shift_start_time && $log->in_time) {
                            $shiftStart = \Carbon\Carbon::parse($log->attendance->shift_start_time);
                            $clockIn = \Carbon\Carbon::parse($log->in_time);
                            return max(0, $clockIn->diffInMinutes($shiftStart, false));
                        }
                        return 0;
                    });
                    //$deductable_late_minutes = $user->hasOfficialInformation->hasLateAttendanceRecords?->sum('late_minutes') ?? 0; // this is basically for "deductable" late attendance records

                    $late_deductable_amount = 0;
                    $salary = $gross_salary_of_1_day;
                    
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
                                'child_data_identifier_key_incoming' => '',
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
                            'employee_user_id' => $user->id,
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

                    $meal_rate = $user->hasOfficialInformation->per_meal_cost == 0 || $user->hasOfficialInformation->per_meal_cost == null ? $meal_rate : $user->hasOfficialInformation->per_meal_cost;

                    $total_meal_cost_this_month = ($total_present_days - $mealPauseCount) * $meal_rate;
                    Log::info('total_meal_cost_this_month', ['present_days'=>$total_present_days,'meal_cost'=>$total_meal_cost_this_month]);

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
                            'employee_user_id' => $user->id,
                            'head_id' => 13, //meal
                            'value' => $total_meal_cost_this_month,
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
                        'employee_user_id' => $user->id,
                        'head_id' => 16, //absent
                        'value' => $absentDeduction,
                        'is_earning' => 0,
                        'created_user_id' => getUserId(), // todo:: need a system user id
                        'created_at' => date('Y-m-d H:i:s'),
                    ];


                }


                // calculate unpaid leave deduction 
                //... salary_head = 19 = unpaid leave


                 $existedUnPaidDeductionRows = $user
                        ->hasOfficialInformation
                        ->hasPayrollPreSalarySheetDeductions?->
                        where('employee_user_id', $user->id)->where('type', 'unpaid_leave')
                        ;

                if($existedUnPaidDeductionRows->count() > 0){
                   
                }else{
                    $unpaidLeaveDeduction = $user->hasOfficialInformation->hasUnpaidLeave->count();
                    if($unpaidLeaveDeduction > 0)
                    {
                        $total_deductable += $unpaidLeaveDeduction * $gross_salary_of_1_day; // $basic_salary_of_1_day

                    }
                }





                // employee AIT calculaiton
                Log::info("AIT user id: $user->id, ait_eligible: $user->hasOfficialInformation->ait_eligible");
                if ($user->hasOfficialInformation->ait_eligible);
                if ($user->hasOfficialInformation->ait_eligible == 1) {
                    Log::info("AIT");
                    $hasAitPause = $user->hasOfficialInformation->hasMealLoanAitPfEtcPauses()
                        ?->where('pause_month', $generateMonth)
                        ->where('pause_year', $generateYear)
                        ->where(function ($q) {
                            $q->where('pause_type', 4)      // 4 = ait deduction pause
                              ->orWhere('approval_status', 1); // 1 = approved
                        })
                        ->exists() ?? 0;
                        Log::info("AIT hasAitPause: ",[$hasAitPause]);



                    if ($user->hasOfficialInformation->ait_deduction_basis == 'fixed') {
                        $ait_amount = $user->hasOfficialInformation->ait_amount;
                    } elseif ($user->hasOfficialInformation->ait_deduction_basis == 'basic') {
                        // $basicAmount = PayrollSalaryHead::join('salary_heads', 'payroll_salary_heads.salary_head_id', '=', 'salary_heads.id')
                        //                 ->where('employee_user_id', $user->id)
                        //                 ->where('salary_heads.is_percentage_determiner', true)
                        //                 ->first()->amount;

                        $ait_amount = ($user->hasOfficialInformation->ait_ptc / 100) * $basicSalary; //calculation to be updated
                    } elseif ($user->hasOfficialInformation->ait_deduction_basis == 'gross') {
                        $ait_amount = ($user->hasOfficialInformation->ait_ptc / 100) * $user->hasOfficialInformation->gross_salary;
                    } else {
                        $ait_amount = ($user->hasOfficialInformation->ait_ptc / 100) * $user->hasOfficialInformation->gross_salary;
                    }

                    Log::info("AIT ait_amount: $ait_amount");
                    if ($ait_amount > 0 && ($hasAitPause == false || $hasAitPause == 0 || $hasAitPause == null || trim($hasAitPause) == '')) {
                        Log::info("AIT ait_amount: $ait_amount");
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
                            'employee_user_id' => $user->id,
                            'head_id' => 11, //ait
                            'value' => $ait_amount,
                            'is_earning' => 0,
                            'created_user_id' => getUserId(), // todo:: need a system user id
                            'created_at' => date('Y-m-d H:i:s'),
                        ];
                    }
                }

                // employee PF calculaiton
                Log::info('user->hasOfficialInformation->employeePfPolicy', [$user->hasOfficialInformation->employeePfPolicy,$user->hasOfficialInformation->pf_eligibility_status]);
                if ($user->hasOfficialInformation->pf_eligibility_status == 1 && $user->hasOfficialInformation->employeePfPolicy != null) {
                    Log::info("Yesss");
                    $employeePfPolicyMaxDeduction = $user->hasOfficialInformation->employeePfPolicy?->max_deduction_amount ?? 1500; // 1500
                    // $employeePfPercentage = SalaryHead::where('id', 12)->first()->percentage;
                    // $employeePfAmount = $user->hasOfficialInformation->gross_salary * ($employeePfPercentage / 100);
                    $employeePfAmount = $user->hasOfficialInformation->hasSalaryStructure?->where('salary_head_id', 12)->first()?->amount ?? 0;
                    if ($employeePfAmount > $employeePfPolicyMaxDeduction) {
                        $pf_amount = $employeePfPolicyMaxDeduction;
                    } else {
                        $pf_amount = $employeePfAmount;
                    }

                    Log::info("pf_amount",[$pf_amount]);

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
                            'employee_user_id' => $user->id,
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
                            'employee_user_id' => $user->id,
                            'head_id' => $head->salary_head_id,
                            'value' => $head->amount,
                            'is_earning' => $head->is_earning,
                            'created_user_id' => getUserId(), // todo:: need a system user id
                            'created_at' => date('Y-m-d H:i:s'),
                        ];
                        $total_earning += $head->amount;
                    }
                }

                //.... loan / pf_loan / advance installments -- taken LAST, capped at what
                // is left of net salary after every other deduction. Only the pending
                // deduction is written here (is_auto_clean = 1, regenerated on every temp
                // run); the loan balance, schedule row and ledger are settled when the
                // final salary sheet is generated. Any unrecovered part carries forward
                // there.
                $netLeft = max(0, round($total_earning - $total_deductable, 2));
                $loanHeadTotals = [];
                foreach ($dueLoanRowsByUser[$user->id] ?? [] as $due) {
                    $deduct = round(min((float) $due->remaining, $netLeft), 2);
                    if ($deduct <= 0) {
                        continue;
                    }
                    $netLeft = round($netLeft - $deduct, 2);
                    $total_deductable += $deduct;

                    $PayrollPreSalarySheetDeduction[] = [
                        'salary_sheet_temp_id' => null,
                        'employee_id_for_mapping' => $user->id,
                        'employee_user_id' => $user->id,
                        'child_data_identifier_key_incoming' => $due->loan_key,
                        'amount' => $deduct,
                        'type' => 'loan',
                        'year' => $generateYear,
                        'month' => $generateMonth,
                        'is_auto_clean' => 1,
                        'created_at' => date('Y-m-d H:i:s'),
                        'created_user_id' => getUserId(),
                    ];

                    $headId = self::LOAN_HEADS[$due->type];
                    $loanHeadTotals[$headId] = round(($loanHeadTotals[$headId] ?? 0) + $deduct, 2);
                }
                foreach ($loanHeadTotals as $headId => $value) {
                    $PayrollSalarySheetHeadsTemp[] = [
                        'salary_sheet_temp_id' => null,
                        'employee_id_for_mapping' => $user->id,
                        'employee_user_id' => $user->id,
                        'head_id' => $headId,
                        'value' => $value,
                        'is_earning' => 0,
                        'created_user_id' => getUserId(), // todo:: need a system user id
                        'created_at' => date('Y-m-d H:i:s'),
                    ];
                }

                //.... PR (problem register / damage) installments -- after the
                // loans, from what is still left of net salary. Each PR deducts
                // min(installment, balance); an unrecovered part simply stays in
                // the balance (the PR runs a month longer). Only a pause linked
                // to that PR skips it. The balance is reduced at final salary.
                $prTotal = 0;
                foreach ($user->hasOfficialInformation->hasPrProblemRegisterAccousedPerson ?? [] as $item) {
                    $prKey = 'pr_problem_register_accoused_persons_' . $item->id;
                    if (in_array($prKey, $pausedPrKeys, true)) {
                        continue;
                    }
                    $deduct = round(min((float) $item->installment_amount, (float) $item->balance, $netLeft), 2);
                    if ($deduct <= 0) {
                        continue;
                    }
                    $netLeft = round($netLeft - $deduct, 2);
                    $total_deductable += $deduct;
                    $prTotal = round($prTotal + $deduct, 2);

                    $PayrollPreSalarySheetDeduction[] = [
                        'salary_sheet_temp_id' => null,
                        'employee_id_for_mapping' => $user->id,
                        'employee_user_id' => $user->id,
                        'child_data_identifier_key_incoming' => $prKey,
                        'amount' => $deduct,
                        'type' => 'pr',
                        'year' => $generateYear,
                        'month' => $generateMonth,
                        'is_auto_clean' => 1,
                        'created_at' => date('Y-m-d H:i:s'),
                        'created_user_id' => getUserId(),
                    ];
                }
                if ($prTotal > 0) {
                    $PayrollSalarySheetHeadsTemp[] = [
                        'salary_sheet_temp_id' => null,
                        'employee_id_for_mapping' => $user->id,
                        'employee_user_id' => $user->id,
                        'head_id' => 14, //pr
                        'value' => $prTotal,
                        'is_earning' => 0,
                        'created_user_id' => getUserId(), // todo:: need a system user id
                        'created_at' => date('Y-m-d H:i:s'),
                    ];
                }

                // get cash , bank , mfs amount
                $cashBankMfs = $this->getCashBankMfsAmount($user->hasOfficialInformation, $netLeft);


                // employee total salary calculation
                $PayrollSalarySheetTemp[] = [
                    'employee_user_id' => $user->id,
                    // unit snapshot at generation time; salarySheetGenerate copies it to the final sheet
                    'branch_id' => $user->hasOfficialInformation->branch_id,
                    'department_id' => $user->hasOfficialInformation->department_id,
                    'section_id' => $user->hasOfficialInformation->section_id,
                    'sub_section_id' => $user->hasOfficialInformation->sub_section_id,
                    'total_earning' => $total_earning,
                    'total_deductable' => $total_deductable,
                    'gross_payable' => $gross_payable,
                    'net_salary_payable' => $total_earning - $total_deductable,
                    'month' => $generateMonth,
                    'year' => $generateYear,
                    'cash_payable' => $cashBankMfs['cash'],
                    'bank_payable' => $cashBankMfs['bank'],
                    'mfs_payable' => $cashBankMfs['mfs'],
                    'from_date' => date("Y-m-01", strtotime($payload['salary_calculate_month_year'])),
                    'to_date' => date("Y-m-t", strtotime($payload['salary_calculate_month_year'])),
                    'created_user_id' => getUserId(), // todo:: need a system user id
                    'created_at' => date('Y-m-d H:i:s'),
                ];
            });
        if ($PayrollSalarySheetTemp && count($PayrollSalarySheetTemp) > 0) {
            DB::transaction(function () use (
                $PayrollSalarySheetTemp,
                $PayrollAttendanceSummaryValues,
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

                    // branch-wise regenerate: payload employeeIds = employees of the selected branch(es).
                    // only their old rows for this month get deleted, other branches stay untouched.
                    // no employeeIds in payload = whole month regenerate.
                    $scopeEmployeeIds = (isset($payload['employeeIds']) && count($payload['employeeIds']) > 0)
                        ? array_values(array_unique(array_map('intval', $payload['employeeIds'])))
                        : null;

                    // ধাপ ০: এই মাস-বছরের scope এর মধ্যে থাকা আগের data মুছে ফেলো (child আগে, parent পরে)
                    $oldIds = DB::table('payroll_salary_sheet_temp')
                        ->where('month', $month)
                        ->where('year', $year)
                        ->when($scopeEmployeeIds !== null, fn ($q) => $q->whereIn('employee_user_id', $scopeEmployeeIds))
                        ->pluck('id');

                    // scope এর মধ্যে থাকা আগের এই মাস-বছরের attendance summary values মুছে ফেলো
                    DB::table('payroll_attendance_summary_values')
                        ->where('month', $month)
                        ->where('year', $year)
                        ->when($scopeEmployeeIds !== null, fn ($q) => $q->whereIn('employee_user_id', $scopeEmployeeIds))
                        ->delete();
                        

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

                    // ধাপ ২: mapping বানাও — employee_user_id => id (শুধু এই run এ insert হওয়া row গুলো)
                    $generatedEmployeeIds = array_values(array_unique(array_column($PayrollSalarySheetTemp, 'employee_user_id')));
                    $idMap = DB::table('payroll_salary_sheet_temp')
                        ->where('month', $month)
                        ->where('year', $year)
                        ->whereIn('employee_user_id', $generatedEmployeeIds)
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
                        // a batch insert takes its column list from the first row, so
                        // every row needs the same keys in the same order (only loan
                        // rows carry year/month)
                        $row += ['year' => null, 'month' => null, 'is_auto_clean' => 1];
                        ksort($row);
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

                    // ধাপ 7: attendance summary values batch insert (chunk করে) — পুরনো data ধাপ ০ তে মুছে ফেলা হয়েছে
                    Log::info("Attendance summary values: ".json_encode($PayrollAttendanceSummaryValues));
                    if (count($PayrollAttendanceSummaryValues) > 0) {
                        foreach (array_chunk($PayrollAttendanceSummaryValues, 500) as $chunk) {
                            DB::table('payroll_attendance_summary_values')->insert($chunk);
                        }
                    }
                }
            });

            createNotification("Salary sheet generated successfully for month $generateMonth/$generateYear",$payload['created_by'],);


        }
    }


    // get cash , bank , mfs amount
    private function getCashBankMfsAmount($data, $salaryPayable)
    {
        $cashLimit = max(0, (float) ($data->cash_pay_amount ?? 0));
        $bankLimit = max(0, (float) ($data->bank_pay_amount ?? 0));
        $remaining = max(0, round((float) $salaryPayable, 2));

        // first pay cash , if some amount rest after cash pay max of bank, after bank fills rest will go to mfs
        $cash = min($cashLimit, $remaining);
        $remaining = round($remaining - $cash, 2);

        $bank = min($bankLimit, $remaining);
        $remaining = round($remaining - $bank, 2);

        $mfs = $remaining;

        return [
            'cash' => round($cash, 2),
            'bank' => round($bank, 2),
            'mfs' => round($mfs, 2),
        ];
    }

    /**
     * The open schedule row due in $month/$year for every running loan, keyed by
     * employee_user_id and ordered by recovery priority. A loan on hold has no
     * row to find (activateHold removes them); a loan paused for the month
     * through the loan module is skipped. Generic Service-Pause rows
     * (pause_type 2 without a loan link) are deliberately ignored -- they carry
     * nothing forward.
     *
     * @return array<int, object[]> each: loan_id, loan_key, type, schedule_id, remaining
     */
    private function dueLoanRows(int $month, int $year, array $employeeIds): array
    {
        $rows = DB::table('payroll_loan_installment_schedules as s')
            ->join('payroll_salary_advance_n_loans as l', 'l.id', '=', 's.payroll_salary_advance_n_loan_id')
            ->where('s.due_month', $month)
            ->where('s.due_year', $year)
            ->whereIn('s.status', ['pending', 'partially_paid'])
            ->whereNull('s.deleted_at')->whereNull('s.deleted_by')
            ->where('l.status', 'running')
            ->where('l.approval_status', 1)
            ->where('l.is_deduction_held', 0)
            ->whereNull('l.deleted_at')->whereNull('l.deleted_by')
            ->when(count($employeeIds) > 0, fn ($q) => $q->whereIn('l.employee_user_id', $employeeIds))
            ->whereNotExists(function ($q) use ($month, $year) {
                $q->select(DB::raw(1))
                    ->from('meal_loan_ait_pf_etc_pauses as p')
                    ->whereColumn('p.child_data_identifier_key_incoming', 'l.child_data_identifier_key_outgoing')
                    ->where('p.pause_type', 2) // 2 = loan installment deduction pause
                    ->where('p.pause_month', $month)
                    ->where('p.pause_year', $year)
                    ->where('p.approval_status', 1)
                    ->whereNull('p.deleted_at');
            })
            ->orderBy('s.installment_no')
            ->get([
                'l.id as loan_id',
                'l.employee_user_id',
                'l.type',
                'l.child_data_identifier_key_outgoing as loan_key',
                's.id as schedule_id',
                // shortfall_amount was already carried to a later row
                DB::raw('ROUND(s.scheduled_amount + s.carried_forward_in - s.paid_amount - s.waived_amount - s.shortfall_amount, 2) as remaining'),
            ]);

        $byUser = [];
        $seenLoan = [];
        foreach ($rows as $row) {
            // one row per loan per month (the earliest, same as LoanService::dueRow)
            if (isset($seenLoan[$row->loan_id]) || (float) $row->remaining <= 0) {
                continue;
            }
            $seenLoan[$row->loan_id] = true;
            $byUser[$row->employee_user_id][] = $row;
        }

        foreach ($byUser as &$list) {
            usort($list, fn ($a, $b) => [self::LOAN_PRIORITY[$a->type] ?? 9, $a->loan_id] <=> [self::LOAN_PRIORITY[$b->type] ?? 9, $b->loan_id]);
        }
        unset($list);

        return $byUser;
    }
}
