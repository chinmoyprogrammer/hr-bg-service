<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\CommonRelationships;

class PayrollSalaryAdvanceLoanNOtherInstallment extends Model
{
    //
    protected $table = 'payroll_salary_advance_loan_n_other_installments';
    protected $fillable = [
        'payroll_salary_advance_n_loan_id',
        'coa_id',
        'amount',
        'remaining_amount',
        'created_at',
        'adjustment_status',
        'is_bad_debt',
        'deleted_by',
        'deleted_at',
        'child_data_identifier_key_incoming',
        'child_data_identifier_key_outgoing',
    ];
    use CommonRelationships;
    protected $relationModel = \App\Models\PayrollSalaryAdvanceLoanNOtherInstallment::class;

}
