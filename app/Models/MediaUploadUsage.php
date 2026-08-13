<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\CommonRelationships;

class MediaUploadUsage extends Model
{
    //
    use CommonRelationships;
    protected $relationModel = \App\Models\MediaUploadUsage::class;

    protected $table = 'media_upload_usage';
    public $timestamps = false; // adjust if you have timestamps

    protected $fillable = [
        'media_upload_id',
        'child_data_identifier_key_incoming',
        'created_user_id',
        'created_at',
        'deleted_at',
        'deleted_by',
    ];

    public function mediaUpload()
    {
        return $this->hasOne(MediaUpload::class, 'id', 'media_upload_id');
    }

}
