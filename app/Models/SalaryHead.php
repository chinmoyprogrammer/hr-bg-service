<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\CommonRelationships;

class SalaryHead extends Model
{
    //
    protected $table = 'salary_heads';
    protected $fillable = [
        'head_name',
        'status',
        'head_category',
        'type',
        'is_auto_generated',
        'is_amount',
        'child_data_identifier_key_incoming',
        'child_data_identifier_key_outgoing',
        'created_user_id',
        'updated_user_id',
        'created_at',
        'updated_at',
        'deleted_by',
        'deleted_at',
    ];
    public $timestamps = false;
    use CommonRelationships;
    protected $relationModel = \App\Models\SalaryHead::class;

    public function category()
    {
        return $this->belongsTo(\App\Models\SalaryHeadCategory::class, 'head_category', 'id');
    }


    
}
