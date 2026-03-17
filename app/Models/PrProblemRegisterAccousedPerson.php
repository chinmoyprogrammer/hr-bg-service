<?php
// app/Models/PrProblemRegisterAccousedPerson.php

namespace App\Models;


use Illuminate\Database\Eloquent\Model;

class PrProblemRegisterAccousedPerson extends Model
{

    protected $table = 'pr_problem_register_accoused_persons';
    protected $guarded = [];

    protected $casts = [
        'created_at' => 'datetime',
        'deleted_at' => 'datetime'
    ];

   

    // Relationships
    public function problemRegister()
    {
        return $this->belongsTo(PrProblemRegister::class, 'pr_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // Scopes
    public function scopeSearch($query, $searchTerm)
    {
        return $query->whereHas('problemRegister', function($q) use ($searchTerm) {
            $q->where('title', 'LIKE', "%{$searchTerm}%")
              ->orWhere('description', 'LIKE', "%{$searchTerm}%");
        })->orWhereHas('user', function($q) use ($searchTerm) {
            $q->where('username', 'LIKE', "%{$searchTerm}%")
              ->orWhere('email', 'LIKE', "%{$searchTerm}%");
        });
    }

    public function scopeByProblemRegister($query, $prId)
    {
        return $query->where('pr_id', $prId);
    }

    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeActive($query)
    {
        return $query->whereNull('deleted_at');
    }

    // Check if user is already accused for this problem
    public static function isUserAccoused($prId, $userId)
    {
        return self::where('pr_id', $prId)
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->exists();
    }

    // has judement report
    public function hasJudgementReport()
    {
        return $this->hasOne(PrCommitteeJudgementReport::class, 'pr_id', 'pr_id');
    }
}
