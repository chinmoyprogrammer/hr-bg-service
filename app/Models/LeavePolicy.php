<?php

namespace App\Models;

use App\Traits\CommonRelationships;
use Illuminate\Database\Eloquent\Model;
use App\Models\LeavePolicyDetail;

class LeavePolicy extends Model
{
    //
    protected $table = 'leave_policies';
    protected $primaryKey = 'id';
    protected $guarded = [];
    use CommonRelationships;

    protected $relationModel = \App\Models\LeavePolicyDetail::class;


    public function hasDetail()
    {
        return $this->hasMany(LeavePolicyDetail::class, 'leave_policy_id', 'id');
    }

    public function hasEmployees(){
        return $this->hasMany(EmployeeOfficialInformation::class, 'leave_policy_id', 'id');
    }
}
