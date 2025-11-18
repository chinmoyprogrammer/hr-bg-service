<?php
// app/Models/EmployeeDesignation.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeDesignation extends Model
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

    public function designationLevel()
    {
        return $this->belongsTo(EmployeeDesignationLevel::class, 'designation_level_id');
    }

    public function salaryGrade()
    {
        return $this->belongsTo(SalaryGrade::class, 'salary_grade_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function scopeSearch($query, $searchTerm)
    {
        return $query->where('title', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('title_bn', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('short_name', 'LIKE', "%{$searchTerm}%");
    }
}
