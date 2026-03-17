<?php

namespace App\Traits;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

trait CommonRelationships
{
    public static function bootCommonRelationships()
    {
        static::created(function ($model) {
            $model->queueUpdateChildDataIdentifierKeyOutgoing();
        });
    }
    /**
     * Relationship with creator - ALWAYS uses User model
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_user_id');
    }

    /**
     * Relationship with updater - ALWAYS uses User model
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_user_id');
    }

    /**
     * Relationship with deleter - ALWAYS uses User model
     */
    public function deleter()
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where($this->getTable() . '.status', true);
    }

    public function scopeInactive($query)
    {
        return $query->where($this->getTable() .'.status', false);
    }

    public function scopeDeleted($query)
    {
        return $query->whereNotNull($this->getTable() .'.deleted_by')->whereNotNull($this->getTable() .'.deleted_at');
    }

    public function scopeNotDeleted($query)
    {
        return $query->whereNull($this->getTable() .'.deleted_by')->whereNull($this->getTable() .'.deleted_at');
    }


    public function softDelete()
    {
        $this->deleted_by = getUserId();
        $this->deleted_at = date('Y-m-d H:i:s');
        $this->status = 0;
        return $this->whereNull($this->getTable() .'.deleted_by')->whereNull($this->getTable() .'.deleted_at')->save();
    }


    public function onlySoftDelete()
    {
        $this->deleted_by = getUserId();
        $this->deleted_at = date('Y-m-d H:i:s');
        return $this->whereNull($this->getTable() .'.deleted_by')->whereNull($this->getTable() .'.deleted_at')->save();
    }
    public function scopeSoftDelete($query)
    {
        $query->whereNull($this->getTable() .'.deleted_by')->whereNull($this->getTable() .'.deleted_at')->update([
            'deleted_by' => getUserId(),
            'deleted_at' => date('Y-m-d H:i:s'),
            'status' => 0,
        ]);
    }

    public function scopeUpdateChildDataIdentifierKeyOutgoing($query)
    {
        $table = $this->resolveTableName();
        $key = $table . '_' . $this->getKey();

        $query->update([
            'child_data_identifier_key_outgoing' => $key,
        ]);
    }

    //.... updating child_data_identifier_key_outgoing and employee_imposed_approval_chain column if available
    public function queueUpdateChildDataIdentifierKeyOutgoing(): void
    {
        $modelClass = static::class;
        $id = $this->getKey();
        $table = $this->resolveTableName();
        $keyName = $this->getKeyName();
        dispatch(function () use ($modelClass, $id, $table, $keyName) {
            if (DB::table($table)->first() && Schema::hasColumn($table, 'child_data_identifier_key_outgoing')) {
                DB::table($table)->where($keyName, $id)->update([
                    'child_data_identifier_key_outgoing' => $table . '_' . $id,
                ]);
            }

            //...if the table has employee_imposed_approval_chain column
            if (DB::table($table)->first() && Schema::hasColumn($table, 'employee_imposed_approval_chain')) {
                DB::table($table)->where($keyName, $id)->update([
                    'employee_imposed_approval_chain' => $table . '_' . $id,
                ]);
            }

        });
    }

    private function resolveTableName(): string
    {
        $table = $this->getTable();
        if (is_string($table) && $table !== '') {
            return strtolower($table);
        }
        $base = class_basename($this);
        $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $base));
        return Str::plural($snake);
    }
}
