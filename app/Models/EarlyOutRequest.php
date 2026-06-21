<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\CommonRelationships;

class EarlyOutRequest extends Model
{
    protected $table = 'early_out_requests';
    protected $guarded = [];
    public $timestamps = false;

    // Relationships
    use CommonRelationships;

    protected $relationModel = \App\Models\EarlyOutRequest::class;

}
