<?php

namespace App\Models;
use App\Traits\CommonRelationships;
use Illuminate\Database\Eloquent\Model;

class LeaveApplicationDetail extends Model
{
    protected $table = 'leave_application_details';

    protected $fillable = [];

    use CommonRelationships;
    protected $relationModel = \App\Models\LeaveApplicationDetail::class;

    public function leaveHead()
    {
        return $this->belongsTo(LeaveHead::class, 'leave_head_id', 'id');
    }

    public function leaveApplication()
    {
        return $this->belongsTo(LeaveApplication::class, 'leave_application_id', 'id');
    }
}