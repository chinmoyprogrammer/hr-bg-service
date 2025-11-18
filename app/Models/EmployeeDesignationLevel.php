<?php
// app/Models/EmployeeDesignationLevel.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeDesignationLevel extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    // Relationships
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_user_id');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_user_id');
    }

    public function deleter()
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function designations()
    {
        return $this->hasMany(EmployeeDesignation::class, 'designation_level_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function scopeSearch($query, $searchTerm)
    {
        return $query->where('level_name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('level_name_bn', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('level_short_name', 'LIKE', "%{$searchTerm}%");
    }
}
