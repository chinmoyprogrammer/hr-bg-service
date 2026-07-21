<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EmployeeOtData extends Model
{
    public $timestamps = false;
    protected $relationModel = \App\Models\EmployeeOtData::class;
    protected $table = 'employee_ot_data';
    public $guarded = [];
}
