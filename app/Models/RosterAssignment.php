<?php
// app/Models/RosterAssignment.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\CommonRelationships;

class RosterAssignment extends Model
{
    public $guarded = [];
    public $timestamps = false;
    protected $primaryKey = 'id';

    // Relationships
    use CommonRelationships;

    protected $relationModel = \App\Models\RosterAssignment::class;

    public function shift()
    {
        return $this->belongsTo(Shift::class, 'shift_id', 'id');
    }

    public function roster()
    {
        return $this->belongsTo(Roster::class, 'roster_id', 'id');
    }

    public function employee()
    {
        return $this->belongsTo(User::class, 'employee_user_id');
    } 

    public function employeeOfficialInformation()
    {
        return $this->belongsTo(EmployeeOfficialInformation::class, 'employee_user_id', 'employee_user_id');
    }
}
