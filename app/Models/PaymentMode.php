<?php
// app/Models/PaymentMode.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentMode extends Model
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

    public function scopeSearch($query, $searchParams)
    {
        return $query->when(isset($searchParams['name']), function($q) use ($searchParams) {
                $q->where('name', 'LIKE', "%{$searchParams['name']}%");
            })
            ->when(isset($searchParams['code']), function($q) use ($searchParams) {
                $q->where('code', 'LIKE', "%{$searchParams['code']}%");
            });
    }
}
