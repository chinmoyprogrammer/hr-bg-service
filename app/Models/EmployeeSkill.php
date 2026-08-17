<?php

namespace App\Models;

use App\Traits\CommonRelationships;
use Illuminate\Database\Eloquent\Model;

class EmployeeSkill extends Model
{
    use CommonRelationships;
    protected $relationModel = \App\Models\EmployeeSkill::class;
    protected $table = 'employee_skills';

    protected $fillable = [
        'employee_user_id',
        'skill_category_id',
        'skill_id',
        'skill_level',
        'is_draft',
        'created_user_id',
        'updated_user_id',
        'deleted_by',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    // has skill
    public function skill()
    {
        return $this->hasOne(Skill::class, 'id', 'skill_id');
    }

    public function hasUser()
    {
        return $this->hasOne(User::class, 'id', 'employee_user_id');
    }


    public function hasOfficialInformation()
    {
        return $this->hasOne(EmployeeOfficialInformation::class, 'employee_user_id', 'employee_user_id');
    }


}
