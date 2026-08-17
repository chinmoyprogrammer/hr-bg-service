<?php
// app/Models/Roster.php

namespace App\Models;

use App\Traits\CommonRelationships;
use Illuminate\Database\Eloquent\Model;

class Roster extends Model
{
    protected $guarded = [];
    public $timestamps = false;

    // Relationships
    use CommonRelationships;

    protected $relationModel = \App\Models\Roster::class;

    public function shift()
    {
        return $this->belongsTo(Shift::class, 'shift_id', 'id');
    }

    public function scopeSearch($query, $searchTerm)
    {
        return $query->where('title', 'LIKE', "%{$searchTerm}%");
    }

    public function rosterAssignments()
    {
        return $this->hasMany(RosterAssignment::class, 'roster_id', 'id');
    }

    public function uniqueEmployeesRosterAssignment()
    {
        return $this->hasMany(RosterAssignment::class, 'roster_id', 'id')->groupBy('employee_user_id');
    }

    public function uniqueEmployeesRosterAssignmentCount()
    {
        return $this->hasMany(RosterAssignment::class, 'roster_id', 'id')->groupBy('employee_user_id')->count();
    }

}
