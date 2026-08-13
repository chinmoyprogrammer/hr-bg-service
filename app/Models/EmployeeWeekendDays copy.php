<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\CommonRelationships;

class EmployeeWeekendDays extends Model
{
    protected $table = 'employee_weekend_days';
    public $timestamps = false;
    protected $fillable = [];
    use CommonRelationships;
    protected $relationModel = \App\Models\EmployeeWeekendDays::class;
}
