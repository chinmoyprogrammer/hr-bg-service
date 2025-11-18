<?php
// app/Models/Department.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Department extends Model
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

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function departmentHead()
    {
        return $this->belongsTo(User::class, 'department_head_id');
    }

    public function sections()
    {
        return $this->hasMany(Section::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function scopeSearch($query, $searchTerm)
    {
        return $query->where('department_name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('department_name_bn', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('department_short_name', 'LIKE', "%{$searchTerm}%");
    }
}
