<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\CommonRelationships;

class PayrollPreSalarySheetDeduction extends Model
{
    //
    protected $table = 'payroll_pre_salary_sheet_deductions';
    protected $primaryKey = 'id';
    protected $guarded = [];
    public $timestamps = false;
    use CommonRelationships;
    protected $relationModel = \App\Models\PayrollPreSalarySheetDeduction::class;
}
