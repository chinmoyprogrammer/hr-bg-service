<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\EmployeeAttendanceStatusLog;
use App\Models\Shift;
use App\Traits\CommonRelationships;

class EmployeeAttendance extends Model
{
    //
    use CommonRelationships;
    protected $relationModel = \App\Models\EmployeeAttendance::class;

    protected $table = 'employee_attendance';
    protected $primaryKey = 'id';
    public $timestamps = false;
    public $guarded = [];

    public function employeeAttendanceStatusLog(): HasMany
    {
        return $this->hasMany(EmployeeAttendanceStatusLog::class, 'employee_attendance_id');
    }

    public function leaveApplications() {
        return $this->hasOne(LeaveApplication::class, 'id', 'leave_id');
    }

    public function leaveApplicationDetail() {
        return $this->belongsTo(LeaveApplicationDetail::class, 'leave_id', 'leave_application_id');
    }
    public function shift() {
        return $this->belongsTo(Shift::class, 'shift_id', 'id');
    }
}

