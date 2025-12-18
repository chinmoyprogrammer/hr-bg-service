<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HolidayDutyRequisition extends Model
{
    protected $table = 'holiday_duty_requisitions';
    protected $primaryKey = 'id';
    public $timestamps = false;


    public function hasDetails()
    {
        return $this->hasMany(HolidayDutyRequisitionDetail::class, 'holiday_duty_requisition_id', 'id');
    }

}
