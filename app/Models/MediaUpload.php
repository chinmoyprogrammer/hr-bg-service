<?php

namespace App\Models;

use App\Traits\CommonRelationships;
use Illuminate\Database\Eloquent\Model;

class MediaUpload extends Model
{
    protected $table = 'media_uploads';
    public $timestamps = false; // because you have created_at/updated_at as datetime fields

    use CommonRelationships;

    protected $relationModel = \App\Models\MediaUpload::class;

    protected $fillable = [
        'media_server_id',
        'company_id',
        'title',
        'file_original_name',
        'file_name',
        'file_size',
        'extension',
        'type',
        'external_link',
        'description',
        'child_data_identifier_key_incoming',
        'child_data_identifier_key_outgoing',
        'created_by',
        'updated_by',
        'created_at',
        'updated_at',
    ];


    public function mediaUploadUsages()
    {
        return $this->hasMany(MediaUploadUsage::class, 'media_upload_id', 'id');
    }

    public function mediaServer()
    {
        return $this->hasOne(MediaServer::class, 'id', 'media_server_id');
    }

    // If you want to handle timestamps manually, you can set them in the controller.
}
