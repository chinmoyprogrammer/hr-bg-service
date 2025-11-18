<?php
// app/Models/Company.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
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

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function scopeSearch($query, $searchTerm)
    {
        return $query->where('company_name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('company_name_bn', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('bin_tax_vat_number', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('contact_person', 'LIKE', "%{$searchTerm}%");
    }
}
