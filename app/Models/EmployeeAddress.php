<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeAddress extends Model
{
    //
    protected $table = 'employee_addresses';
    protected $primaryKey = 'id';
    public $timestamps = false;

    public function division()
    {
        return $this->belongsTo(Division::class, 'division_id');
    }

    public function district()
    {
        return $this->belongsTo(District::class, 'district_id');
    }

    public function upazila()
    {
        return $this->belongsTo(Upazila::class, 'upazila_id');
    }
}
