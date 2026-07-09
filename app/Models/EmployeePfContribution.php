<?php

namespace App\Models;

use App\Traits\CommonRelationships;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeePfContribution extends Model
{
    //set table name
    protected $table = 'employee_pf_contribution';

    public $timestamps = false;
    use CommonRelationships;
    protected $relationModel = \App\Models\EmployeePfContribution::class;

    protected $fillable = [
        'employee_pf_policy_id',
        'employee_user_id',
        'effective_date',
        'created_at',
        'deleted_by',
        'deleted_at',
        'child_data_identifier_key_incoming',
        'child_data_identifier_key_outgoing',
        'cumulative_amount',
        'yearly_interests_monthly_amount_distribution',
        'transaction_type',
        'amount_in',
        'amount_out',
    ];
    
}
