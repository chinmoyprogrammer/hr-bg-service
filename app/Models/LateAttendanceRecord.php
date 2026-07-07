<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LateAttendanceRecord extends Model
{
    //
    protected $table = 'late_attendance_records';
    protected $fillable = [
        'employee_user_id',
        'late_days',
        'shift_id',
        'calculated_deduction',
        'applied_deduction',
        'deduction_status',
        'late_deduction_policy_id',
        'created_user_id',
        'updated_user_id',
        'created_at',
        'updated_at',
        'deleted_user_id',
        'deleted_by',
        'deleted_at',
    ];

    protected $hidden = [
        'created_user_id',
        'updated_user_id',
        'deleted_user_id',
    ];

    // has  late_attendance_record_details
    public function lateAttendanceRecordDetails()
    {
        return $this->hasMany(LateAttendanceRecordDetail::class, 'late_attendance_record_id', 'id');
    }

}
