<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeAttendance extends Model
{
    protected $table = 'employee_attendance';
    protected $primaryKey = 'id';
    public $timestamps = false;

    public $guarded = [];

    

}
