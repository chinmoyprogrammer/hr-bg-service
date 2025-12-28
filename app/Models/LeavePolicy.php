<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeavePolicy extends Model
{
    //
    protected $table = 'leave_policies';
    protected $primaryKey = 'id';
    protected $guarded = [];

    public function hasDetail()
    {
        return $this->hasMany(LeavePolicyDetail::class, 'leave_policy_id', 'id');
    }
}
