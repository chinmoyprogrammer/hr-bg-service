<?php

namespace App\Models;

use App\Traits\CommonRelationships;
use Illuminate\Database\Eloquent\Model;

class EmployeeLeaveAchieveLog extends Model
{
    protected $table = 'employee_leave_achieve_log';
    public $timestamps = false;
    protected $guarded = [];
    use CommonRelationships;
    protected $relationModel = \App\Models\EmployeeLeaveAchieveLog::class;
}
