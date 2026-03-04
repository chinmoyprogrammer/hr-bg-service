<?php
namespace App\Jobs;

use App\Models\EmployeeOfficialInformation;
use App\Models\LateAttendanceRecord;
use App\Models\PayrollPreSalarySheetDeduction;
use App\Models\PayrollSalaryAdvanceLoanNOtherInstallment;
use App\Models\PayrollSalaryAdvanceNLoan;
use App\Models\PayrollSalaryHead;
use App\Models\PrProblemRegisterAccousedPerson;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


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

        $PayrollPreSalarySheetDeduction = [];
        $PayrollSalaryAdvanceLoanNOtherInstallment = [];
        $PayrollSalarySheetHeads = [];
        User::select('id')
            ->with(['hasOfficialInformation','loans' => function($query){
                $query->where('is_fully_paid', 0);
            }])
            ->where('status', 1)
            ->where('user_type_id', 1) // 1 = employee
            ->where('is_draft', 0)
            ->where('deleted_by', null)
            ->lazy()
            ->each(function ($user) use(&$PayrollPreSalarySheetDeduction, &$PayrollSalaryAdvanceLoanNOtherInstallment, &$PayrollSalarySheetHeads)
            {
        
                if($user->loans->count() > 0)
                {
                    foreach($user->loans as $loan){
                        //.... process loan installments ( user can make a request for pausing the installment deduction for the month, need a history/ settings table )

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


                
                //.... process and gather other regular salary components(like basic salary, allowances, deductions(attendance related), etc.)
                    //get Payroll salary heads ( individual employee employee salary heads like: basic, medical, House rent etc)
                    //.... get salary components data of this employee
                    $head_amounts = PayrollSalaryHead::where('status', 1)->get();
                    
                    // loop through each head
                    //.... insert salary to TempSalary
                    foreach($head_amounts as $head)
                    {
                        $PayrollSalarySheetHeads[] = [
                            'salary_sheet_temp_id' => $this->payload['salary_sheet_temp_id'] ?? null,
                            'head_id' => $head->salary_head_id,
                            'value' => $head->amount,
                            'is_earning' => $head->is_earning,
                            'created_user_id' => 1, // todo:: need a system user id
                            'created_at' => date('Y-m-d H:i:s'),
                        ];
                    }


                    //..... calculate absent deductions

                    LateAttendanceRecord::where('user_id', $user->id)
                        ->where('attendance_date', $this->payload['attendance_date'])
                        ->update([
                            'is_late' => 1,
                        ]);

                    //...... calculate late deductions
                    

                    //.... send notification to every employee that his/her salary is processed

                    //deduction type -> 'loan','pr','absent','pf','meal','late','ait'

            }
        );


    }

}