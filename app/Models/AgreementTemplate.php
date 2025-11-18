<?php
// app/Models/AgreementTemplate.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AgreementTemplate extends Model
{
    use HasFactory;

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

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeSearch($query, $searchTerm)
    {
        return $query->where('title', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('short_name', 'LIKE', "%{$searchTerm}%");
    }
}
