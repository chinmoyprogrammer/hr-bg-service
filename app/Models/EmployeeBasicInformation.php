<?php

namespace App\Models;

use App\Traits\CommonRelationships;
use Illuminate\Database\Eloquent\Model;

class EmployeeBasicInformation extends Model
{
    //
    protected $fillable = [
        'employee_user_id',
        'full_name',
        'full_name_bn',
        'nickname',
        'nickname_bn',
        'father_name',
        'father_name_bn',
        'father_occupation_id',
        'mother_name',
        'mother_name_bn',
        'mother_occupation_id',
        'date_of_birth',
        'nationality',
        'religion_id',
        'gender_id',
        'nid_no',
        'nid_required',
        'birth_certificate_no',
        'birth_certificate_required',
        'passport_no',
        'driving_license',
        'tin_no',
        'marital_status',
        'child_data_identifier_key_incoming',
        'child_data_identifier_key_outgoing',
        'personal_primary_email',
        'personal_other_email',
        'official_email',
        'pf_eligibility_status',
        'is_draft',
        'created_at',
        'updated_at',
        'deleted_by',
        'deleted_at',
    ];
    use CommonRelationships;
    protected $relationModel = \App\Models\EmployeeBasicInformation::class;
    //belongs to user
    public function user()
    {
        return $this->belongsTo(\App\Models\User::class, 'employee_user_id', 'id');
    }
    public function hasUser()
    {
        return $this->belongsTo(\App\Models\User::class, 'employee_user_id', 'id');
    }

    public function phoneNumbers()
    {
        return $this->hasMany(PhoneNumber::class, 'user_id', 'employee_user_id')->whereNull('deleted_at')->whereIn('contact_type',['official','personal']);
    }
    // employeePhoto has many
    // 
    public function employeePhotos()
    {
        return $this->hasManyThrough(
            MediaUpload::class,
            MediaUploadUsage::class,
            'child_data_identifier_key_incoming',
            'id',
            'child_data_identifier_key_outgoing',
            'media_upload_id'
        )
        ->selectRaw('CONCAT(media_servers.base_url, \'~/\',media_uploads.file_name) as file_url')
        ->leftJoin('media_servers','media_uploads.media_server_id','=','media_servers.id')
        ->whereNull('media_upload_usage.deleted_at')->orderBy('media_upload_usage.id','desc');
    }

    public function employeePhoto()
    {
        return $this->hasOneThrough(
            MediaUpload::class,
            MediaUploadUsage::class,
            'child_data_identifier_key_incoming',
            'id',
            'child_data_identifier_key_outgoing',
            'media_upload_id'
        )
        ->addSelect(\Illuminate\Support\Facades\DB::raw('CONCAT(media_servers.base_url, \'~/\',media_uploads.file_name) as file_url'))
        ->leftJoin('media_servers', 'media_uploads.media_server_id', '=', 'media_servers.id')
        ->whereNull('media_upload_usage.deleted_at')
        ->whereNull('media_uploads.deleted_at')
        ->orderBy('media_upload_usage.id', 'desc');
    }

    public function hasOfficialInformation()
    {
        return $this->hasOne(EmployeeOfficialInformation::class, 'employee_user_id', 'employee_user_id');
    }

}
