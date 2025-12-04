<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeAttendanceTemp extends Model
{
    protected $table = 'employee_attendance_temp';

    protected $casts = [
        'punch_datetime' => 'datetime',
    ];
}
