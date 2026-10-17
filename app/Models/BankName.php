<?php

namespace App\Models;

use App\Traits\CommonRelationships;
use Illuminate\Database\Eloquent\Model;

// same model as hr-admin-backend; CacheRegenerateJob rebuilds all_bank_names from it
class BankName extends Model
{
    protected $guarded = [];
    public $timestamps = false;
    // Relationships
    use CommonRelationships;

    protected $relationModel = \App\Models\BankName::class;

    public function branches()
    {
        return $this->hasMany(BankBranch::class, 'bank_id');
    }

    public function scopeSearch($query, $searchTerm)
    {
        return $query->where('name', 'LIKE', "%{$searchTerm}%");
    }
}
