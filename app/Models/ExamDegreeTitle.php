<?php
// app/Models/ExamDegreeTitle.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ExamDegreeTitle extends Model
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

    public function educationLevel()
    {
        return $this->belongsTo(EducationLevel::class);
    }

    public function educationGroupsSubjects()
    {
        return $this->hasMany(EducationGroupSubject::class, 'exam_degree_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function scopeSearch($query, $searchTerm)
    {
        return $query->where('title', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('title_bn', 'LIKE', "%{$searchTerm}%");
    }
}
