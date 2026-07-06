<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkingDay extends Model
{
    protected $table = 'working_days';
    public $timestamps = false;
    protected $fillable = [
        'employee_user_id',
        'year',
        'month',
        'total_days',
        'holidays_count',
        'weekends_count',
        'working_days_count',
        'created_user_id',
        'created_at'
    ];
}
