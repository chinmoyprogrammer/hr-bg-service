<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\CommonRelationships;

class SalaryHeadCategory extends Model
{
    //
    protected $table = 'salary_head_categories';
    protected $fillable = [
        'name',
        'status',
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
    protected $relationModel = \App\Models\SalaryHeadCategory::class;

    public function salaryHeads()
    {
        return $this->hasMany(\App\Models\SalaryHead::class, 'head_category', 'id');
    }


}
