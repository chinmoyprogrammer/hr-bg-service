<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\CommonRelationships;

class MediaServer extends Model
{
    //
    use CommonRelationships;
    protected $relationModel = \App\Models\MediaServer::class;

    use HasFactory;

    protected $table = 'media_servers';

    protected $fillable = [
        'server_name',
        'base_url',
        'server_type',
        'status',
        'created_user_id',
        'updated_user_id',
        'created_at',
        'updated_at',
        'deleted_by',
        'deleted_at',
        'child_data_identifier_key_incoming',
        'child_data_identifier_key_outgoing',
    ];
}
