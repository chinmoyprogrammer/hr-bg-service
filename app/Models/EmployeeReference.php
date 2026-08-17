<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\CommonRelationships;

class EmployeeReference extends Model
{

    protected $table = 'employee_references';
    protected $primaryKey = 'id';
    protected $guarded = [];
    use CommonRelationships;
    protected $relationModel = \App\Models\EmployeeReference::class;
    
    protected $fillable = [
        'employee_user_id',
        'name',
        'designation',
        'organization',
        'email',
        'mobile_no',
        'relationship',
        'address',
        'is_draft',
        'created_user_id',
        'updated_user_id',
        'created_at',
        'updated_at',
        'deleted_by',
        'deleted_at',
        'child_data_identifier_key_incoming',
        'child_data_identifier_key_outgoing',
    ];

    public function hasPhoneNumber()
    {
        return $this->hasOne(PhoneNumber::class, 'child_data_identifier_key_incoming', 'child_data_identifier_key_outgoing');
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
