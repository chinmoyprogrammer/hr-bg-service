<?php

namespace App\Models;

use App\Traits\CommonRelationships;
use Illuminate\Database\Eloquent\Model;

// same model as hr-admin-backend; CacheRegenerateJob rebuilds all_bank_branches from it
class BankBranch extends Model
{
    protected $guarded = [];
    public $timestamps = false;
    // Relationships
    use CommonRelationships;

    protected $relationModel = \App\Models\BankBranch::class;

    public function bank()
    {
        return $this->belongsTo(BankName::class, 'bank_id');
    }

    public function scopeSearch($query, $searchTerm)
    {
        return $query->where('branch_name', 'LIKE', "%{$searchTerm}%");
    }
}
