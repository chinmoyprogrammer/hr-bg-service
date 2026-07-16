<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MealLoanAitPfEtcPause extends Model
{
    //meal loan ait pf etc pause
    protected $table = 'meal_loan_ait_pf_etc_pauses';
    protected $guarded = [];

    //employee has meal loan ait pf etc pauses
    public function hasEmployeeOfficialInformation()
    {
        return $this->hasOne(EmployeeOfficialInformation::class, 'employee_user_id', 'employee_user_id');
    }
}
