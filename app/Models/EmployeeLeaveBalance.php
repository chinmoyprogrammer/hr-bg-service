<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeLeaveBalance extends Model
{
    protected $table = 'employee_leave_balances';
    protected $primaryKey = 'id';
    protected $timestamp = false;
}
