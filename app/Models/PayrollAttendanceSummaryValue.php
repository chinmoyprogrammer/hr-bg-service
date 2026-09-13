<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\CommonRelationships;

class PayrollAttendanceSummaryValue extends Model
{
    use CommonRelationships;
    protected $table = 'payroll_attendance_summary_values';
    protected $primaryKey = 'id';
    public $timestamps = false;
    protected $relationModel = \App\Models\PayrollAttendanceSummaryValue::class;



}
