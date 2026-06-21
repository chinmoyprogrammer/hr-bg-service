<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\CommonRelationships;

class LeavePolicyDetail extends Model
{
    protected $guarded = [];
    public $timestamps = false;
    use CommonRelationships;

    protected $relationModel = \App\Models\LeavePolicyDetail::class;

    protected $fillable = [
        'leave_head_id',
        'leave_policy_id',
        'days',
        'carry_forward_limit',
        'carry_forward',
        'encashment',
        'encashment_basis',
        'encashment_basis_rate',
        'is_compensatory',
        'leave_avail_validity_days',
        'document_req_greater_than_x_days_of_leave',
        'eligible_after_days',
        'allow_negative_balance',
        'created_user_id',
        'updated_user_id',
        'created_at',
        'updated_at',
        'deleted_user_id',
        'deleted_by',
        'deleted_at',
        'child_data_identifier_key_incoming',
        'child_data_identifier_key_outgoing'
    ];
}
