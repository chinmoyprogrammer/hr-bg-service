<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\CommonRelationships;


class EmployeeWeekendDay extends Model
{
    //
    use CommonRelationships;
    protected $relationModel = \App\Models\EmployeeWeekendDay::class;
    public $timestamps = false;
    protected $fillable = [
        'employee_user_id',
        'php_week_day_code',
        'day_name',
        'is_alternated',
        'alternate_starting_date',
        'updated_user_id',
        'created_user_id',
        'created_at',
        'updated_at',
        'remarks',
        'deleted_by',
        'deleted_at',
    ];
}
