<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LateAttendanceRecordDetail extends Model
{
        public $timestamps = false;
        protected $fillable = [
        'late_attendance_record_id',
        'late_seconds',
        'date',
        'in_time',
        'shift_id',
        'out_time',
        'month',
        'year',
        'created_at'
    ];
}
