<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\EmployeeAttendance;
use App\Traits\CommonRelationships;

class EmployeeAttendanceStatusLog extends Model
{
    //
    use CommonRelationships;
    protected $relationModel = \App\Models\EmployeeAttendanceStatusLog::class;

    protected $table = 'employee_attendance_status_logs';
    public $timestamps = false;
    public $guarded = [];

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(EmployeeAttendance::class, 'employee_attendance_id');
    }
    public function attendanceStatusInBusinessSetting(): BelongsTo
    {
        return $this->belongsTo(BusinessSetting::class, 'attendance_status', 'value')->where('settings_key', 'ATTENDANCE_STATUS');
    }

    
}
