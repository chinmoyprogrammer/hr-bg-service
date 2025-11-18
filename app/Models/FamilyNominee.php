<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FamilyNominee extends Model
{
    protected $table = 'employee_family_nominee';
    protected $primaryKey = 'id';
    public $timestamps = false;
    //

    public function hasPhoneNumbers()
    {
        //.... 
        return $this->hasMany(PhoneNumber::class, 'family_nominee_id');
    }
}
