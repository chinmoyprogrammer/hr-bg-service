<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\CommonRelationships;

class PayrollSalaryAdvanceNLoan extends Model
{
    //
    protected $table = 'payroll_salary_advance_n_loans';
    protected $primaryKey = 'id';
    protected $guarded = [];
    public $timestamps = false;
    use CommonRelationships;
    protected $relationModel = \App\Models\PayrollSalaryAdvanceNLoan::class;
}
