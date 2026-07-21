<?php
// app/Models/PrProblemRegister.php

namespace App\Models;

use App\Traits\CommonRelationships;
use Illuminate\Database\Eloquent\Model;

class PrProblemRegister extends Model
{
    use CommonRelationships;

    protected $table = 'pr_problem_register';
    protected $guarded = [];

    protected $casts = [
        'is_resolved' => 'boolean',
        'severity' => 'integer',
        'occurrence_date_time' => 'datetime',
        'resolution_date_time' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime'
    ];

    protected $relationModel = \App\Models\PrProblemRegister::class;

    // Relationships
    public function employee()
    {
        return $this->belongsTo(User::class, 'employee_user_id');
    }

    public function problemCategory()
    {
        return $this->belongsTo(PrProblemCategory::class, 'pr_problem_category_id');
    }

    public function problemSubCategory()
    {
        return $this->belongsTo(PrProblemSubCategory::class, 'pr_problem_sub_category_id');
    }

    // Scopes
    public function scopeSearch($query, $searchTerm)
    {
        return $query->where('title', 'LIKE', "%{$searchTerm}%")
            ->orWhere('description', 'LIKE', "%{$searchTerm}%")
            ->orWhere('remarks', 'LIKE', "%{$searchTerm}%")
            ->orWhereHas('employee', function($q) use ($searchTerm) {
                $q->where('username', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('email', 'LIKE', "%{$searchTerm}%");
            })
            ->orWhereHas('problemCategory', function($q) use ($searchTerm) {
                $q->where('category_name', 'LIKE', "%{$searchTerm}%");
            })
            ->orWhereHas('problemSubCategory', function($q) use ($searchTerm) {
                $q->where('sub_category_name', 'LIKE', "%{$searchTerm}%");
            });
    }

    public function scopeBySeverity($query, $severity)
    {
        return $query->where('severity', $severity);
    }

    public function scopeResolved($query)
    {
        return $query->where('is_resolved', true);
    }

    public function scopeUnresolved($query)
    {
        return $query->where('is_resolved', false);
    }

    public function scopeByEmployee($query, $employeeId)
    {
        return $query->where('employee_user_id', $employeeId);
    }

    public function scopeByCategory($query, $categoryId)
    {
        return $query->where('pr_problem_category_id', $categoryId);
    }

    public function scopeBySubCategory($query, $subCategoryId)
    {
        return $query->where('pr_problem_sub_category_id', $subCategoryId);
    }

    public function scopeLowSeverity($query)
    {
        return $query->where('severity', 1);
    }

    public function scopeMediumSeverity($query)
    {
        return $query->where('severity', 2);
    }

    public function scopeHighSeverity($query)
    {
        return $query->where('severity', 3);
    }

    // Helper methods
    public function getSeverityTextAttribute()
    {
        $severityMap = [
            1 => 'Low',
            2 => 'Medium',
            3 => 'High'
        ];

        return $severityMap[$this->severity] ?? 'Unknown';
    }

    public function getSeverityColorAttribute()
    {
        $colorMap = [
            1 => 'success',  // Green for Low
            2 => 'warning',  // Yellow for Medium
            3 => 'danger'    // Red for High
        ];

        return $colorMap[$this->severity] ?? 'secondary';
    }

    public function getStatusAttribute()
    {
        return $this->is_resolved ? 'Resolved' : 'Open';
    }

    public function getStatusColorAttribute()
    {
        return $this->is_resolved ? 'success' : 'warning';
    }

    public function markAsResolved($resolutionDateTime = null, $remarks = null)
    {
        $this->update([
            'is_resolved' => true,
            'resolution_date_time' => $resolutionDateTime ?: date('Y-m-d H:i:s'),
            'remarks' => $remarks ?? $this->remarks,
            'updated_user_id' => getUserId(),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        return $this;
    }

    public function markAsUnresolved()
    {
        $this->update([
            'is_resolved' => false,
            'resolution_date_time' => null,
            'updated_user_id' => getUserId(),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        return $this;
    }

    // Relationship to get pr problem register wise pr committee list
    public function prCommittees()
    {
        return $this->hasMany(PrCommittee::class, 'pr_id');
    }
}
