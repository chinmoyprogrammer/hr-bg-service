<?php
// app/Models/EducationLevel.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EducationLevel extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'name_bn',
        'status',
        'is_draft',
        'created_user_id',
        'updated_user_id',
        'deleted_by',
        'child_data_identifier_key_incoming',
        'child_data_identifier_key_outgoing'
    ];

    protected $casts = [
        'status' => 'boolean',
        'is_draft' => 'boolean',
        'child_data_identifier_key_outgoing' => 'datetime'
    ];

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

    public function examDegreeTitles()
    {
        return $this->hasMany(ExamDegreeTitle::class);
    }

    public function educationBoardsUniversities()
    {
        return $this->hasMany(EducationBoardUniversity::class);
    }

    public function educationGroupsSubjects()
    {
        return $this->hasMany(EducationGroupSubject::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function scopeSearch($query, $searchTerm)
    {
        return $query->where('name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('name_bn', 'LIKE', "%{$searchTerm}%");
    }
}
