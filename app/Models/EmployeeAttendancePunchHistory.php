<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeAttendancePunchHistory extends Model
{
    protected $table = 'employee_attendance_punch_histories';
    protected $primaryKey = 'id';
    public $timestamps = false;
    //
}
