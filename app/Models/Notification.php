<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\CommonRelationships;

class Notification extends Model
{
    //
    protected $table = 'notifications';
    protected $primaryKey = 'id';
    protected $guarded = [];
    public $timestamps = false;
    use CommonRelationships;
    protected $relationModel = \App\Models\Notification::class;
}
