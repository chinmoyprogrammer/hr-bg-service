<?php
// app/Models/Section.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Section extends Model
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

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function sectionHead()
    {
        return $this->belongsTo(User::class, 'section_head_id');
    }

    public function subsections()
    {
        return $this->hasMany(Subsection::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function scopeSearch($query, $searchTerm)
    {
        return $query->where('section_name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('section_name_bn', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('section_short_name', 'LIKE', "%{$searchTerm}%");
    }
}
