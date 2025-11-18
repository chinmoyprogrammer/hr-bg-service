<?php
// app/Models/EmployeeType.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeType extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'employee_type',
        'status',
        'created_user_id',
        'updated_user_id',
        'deleted_by',
        'child_data_identifier_key_incoming',
        'child_data_identifier_key_outgoing'
    ];

    protected $casts = [
        'status' => 'boolean',
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

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function scopeSearch($query, $searchTerm)
    {
        return $query->where('employee_type', 'LIKE', "%{$searchTerm}%");
    }
}
