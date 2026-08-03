<?php

namespace App\Models;

use App\Traits\CommonRelationships;
use Illuminate\Database\Eloquent\Model;

class Skill extends Model
{
    protected $guarded = [];
    public $timestamps = false;

    // Relationships
    use CommonRelationships;

    protected $relationModel = \App\Models\Skill::class;

    public function skillCategory()
    {
        return $this->belongsTo(SkillCategory::class, 'category_id');
    }

    public function scopeSearch($query, $searchTerm)
    {
        return $query->where('name', 'LIKE', "%{$searchTerm}%");
    }

    // Override active scope from CommonRelationships if it uses 'status' column
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    public function softDelete()
    {
        $this->deleted_by = getUserId();
        $this->deleted_at = date('Y-m-d H:i:s');
        $this->is_active = 0;
        return $this->whereNull('deleted_by')->whereNull('deleted_at')->save();
    }
}
