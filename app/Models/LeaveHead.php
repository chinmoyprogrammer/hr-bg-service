<?php

namespace App\Models;

use App\Traits\CommonRelationships;
use Illuminate\Database\Eloquent\Model;

// same model as hr-admin-backend; CacheRegenerateJob rebuilds all_leave_heads from it
class LeaveHead extends Model
{
    protected $guarded = [];
    public $timestamps = false;
    protected $table = 'leave_heads';
    protected $primaryKey = 'id';

    // Relationships
    use CommonRelationships;

    protected $relationModel = \App\Models\LeaveHead::class;

    public function scopeSearch($query, $searchTerm)
    {
        return $query->where('leave_head', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('short_name', 'LIKE', "%{$searchTerm}%");
    }
}
