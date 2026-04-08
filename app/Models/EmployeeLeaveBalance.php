<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeLeaveBalance extends Model
{
    protected $table = 'employee_leave_balances';
    protected $primaryKey = 'id';
    protected $timestamp = false;
    protected $fillable = [
        'employee_user_id',
        'leave_head_id',
        'leave_policy_id',
        'forwarded',
        'achived_this_year',
        'used',
        'current_balance',
        'encashed_balance',
        'valid_until',
        'child_data_identifier_key_incoming',
        'child_data_identifier_key_outgoing',
        'fiscal_year',
        'created_user_id',
        'updated_user_id',
        'created_at',
        'updated_at',
        'deleted_user_id',
        'deleted_by',
        'deleted_at',
    ];

    public function hasLeaveHead()
    {
        return $this->hasOne(LeaveHead::class, 'id', 'leave_head_id');
    }
        
}
