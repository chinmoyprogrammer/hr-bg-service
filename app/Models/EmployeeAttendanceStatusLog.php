<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeAttendanceStatusLog extends Model
{
    protected $table = 'employee_attendance_status_logs';
    public $timestamps = false;

    public $guarded = [];
}
