<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LateAttendanceConsiderationRequest extends Model
{
    protected $table = 'late_attendance_consideration_requests';
    protected $primaryKey = 'id';
    public $timestamps = true;
    
    protected $fillable = [
        'employee_user_id',
        'month',
        'year',
        'applied_days',
        'consider_days',
        'total_late_days',
        'approval_status',
        'approve_reject_date',
        'approval_remark',
        'created_user_id',
        'updated_user_id',
        'created_at',
        'updated_at',
        'deleted_by',
        'deleted_at',
    ];

    protected $casts = [
        'applied_days' => 'decimal:2',
        'consider_days' => 'decimal:2',
        'approve_reject_date' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(EmployeeOfficialInformation::class, 'employee_user_id', 'employee_user_id');
    }

    public function hasCreatedUser()
    {
        return $this->belongsTo(User::class, 'created_user_id', 'id');
    }

    public function hasUpdatedUser()
    {
        return $this->belongsTo(User::class, 'updated_user_id', 'id');
    }

    public function employeeUser()
    {
        return $this->belongsTo(User::class, 'employee_user_id', 'id');
    }
}
