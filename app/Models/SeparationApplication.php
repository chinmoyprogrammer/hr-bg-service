<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SeparationApplication extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'request_number',
        'employee_id',
        'separation_type_id',
        'application_date',
        'effective_date',
        'reason',
        'status',
        'approval_date',
        'employee_status_updated',
        'created_user_id',
        'updated_user_id',
        'deleted_user_id',
        'deleted_by',
        'child_data_identifier_key_incoming',
        'child_data_identifier_key_outgoing',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'application_date' => 'date',
        'effective_date' => 'date',
        'approval_date' => 'datetime',
        'employee_status_updated' => 'boolean',
    ];
}
