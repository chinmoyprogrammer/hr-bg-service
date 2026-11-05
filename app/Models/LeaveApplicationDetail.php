<?php

namespace App\Models;
use App\Traits\CommonRelationships;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class LeaveApplicationDetail extends Model
{
    protected $table = 'leave_application_details';

    protected $fillable = [];

    use CommonRelationships;
    protected $relationModel = \App\Models\LeaveApplicationDetail::class;

    /**
     * A deleted or withdrawn leave must not count as leave when any job rebuilds attendance.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('not_deleted', function (Builder $builder) {
            $builder->whereNull($builder->getModel()->getTable() . '.deleted_at')
                    ->whereNull($builder->getModel()->getTable() . '.deleted_by');
        });
    }

    public function leaveHead()
    {
        return $this->belongsTo(LeaveHead::class, 'leave_head_id', 'id');
    }

    public function leaveApplication()
    {
        return $this->belongsTo(LeaveApplication::class, 'leave_application_id', 'id');
    }
}