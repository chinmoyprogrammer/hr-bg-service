<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    protected $table = 'holidays';
    public $timestamps = false;

    /**
     * Soft-deleted rows (deleted_at / deleted_by set) are hidden from every
     * query, matching hr-admin-backend's CommonRelationships 'not_deleted'
     * scope. ProcessSandwichedHolidaysJob soft-deletes a weekend/holiday it
     * turns into an absent day, so it can be brought back later; use
     * Holiday::withoutGlobalScope('not_deleted') to reach those rows.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('not_deleted', function (Builder $builder) {
            $builder->whereNull($builder->getModel()->getTable() . '.deleted_at')
                    ->whereNull($builder->getModel()->getTable() . '.deleted_by');
        });
    }
}
