<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\CommonRelationships;

class PayrollSalaryHead extends Model
{
    //
    protected $table = 'payroll_salary_heads';
    protected $fillable = [
        'employee_user_id',
        'salary_head_id',
        'amount',
        'child_data_identifier_key_incoming',
        'child_data_identifier_key_outgoing',
        'created_user_id',
        'created_at',
        'updated_at',
        'updated_user_id',
        'deleted_by',
        'deleted_at',
    ];
    public $timestamps = false;
    use CommonRelationships;
    protected $relationModel = \App\Models\PayrollSalaryHead::class;


    
}
