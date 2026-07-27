<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\CommonRelationships;
use Illuminate\Support\Facades\DB;

class LeaveApplication extends Model
{
    //
    protected $table = 'leave_applications';
    protected $primaryKey = 'id';
    public $timestamps = false;

    use CommonRelationships;
    protected $relationModel = \App\Models\LeaveApplication::class;

    protected $fillable = [
        'application_number',
        'uuid',
        'employee_user_id',
        'branch_id',
        'department_id',
        'section_id',
        'sub_section_id',
        'designation_id',
        'leave_policy_id',
        'leave_head_id',
        'leave_from',
        'leave_half_full_type',
        'leave_to',
        'leave_to_leave_half_full_type',
        'applied_days',
        'is_bridge_leave',
        'child_data_identifier_key_incoming',
        'child_data_identifier_key_outgoing',
        'reason',
        'remarks',
        'approval_status',
        'approve_reject_date',
        'employee_imposed_approval_chain',
        'current_approval_level',
        'created_user_id',
        'updated_user_id',
        'created_at',
        'updated_at',
        'deleted_user_id',
        'deleted_by',
        'deleted_at'
    ];

    public function leaveApplicationDetails()
    {
        return $this->hasMany(LeaveApplicationDetail::class, 'leave_application_id', 'id');
    }

    /**
     * leaveHead()
     * -------------
     * Defines a one-to-one relationship between this LeaveApplication model
     * and the LeaveHead model.
     *
     * Eloquent will look for a column named `leave_head_id` in the
     * `leave_applications` table.  That value is expected to match the `id`
     * column in the `leave_heads` table, thereby linking a single leave
     * application to its corresponding leave-head record.
     *
     * Usage:
     *   $leaveApp = LeaveApplication::find(1);
     *   $head     = $leaveApp->leaveHead; // returns the LeaveHead instance
     */
    public function leaveHead()
    {
        return $this->hasOne(LeaveHead::class, 'id', 'leave_head_id');
    }

    public function employeeImposedApprovalChain()
    {
        return $this->hasMany(EmployeeImposedApprovalChain::class, 'uuid', 'child_data_identifier_key_outgoing');
    }

    public function hasEmployee()
    {
        return $this->hasOne(User::class, 'id', 'employee_user_id');
    }

    public static function insertGetIds(array $records, string $idColumn = 'id'): array
    {
        if (empty($records)) {
            return [];
        }

        $model = new static();
        $connection = $model->getConnection();
        $table = $model->getTable();

        $connection->table($table)->insert($records);

        $firstId = (int) $connection->getPdo()->lastInsertId();
        $count = count($records);

        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = $firstId + $i;
        }

        return $ids;
    }

    // get employee's leave balance
    public function getEmployeeLeaveBalance()
    {
        // has many -> leave_applications.employee_user_id = employee_leave_balances.employee_user_id
        return $this->hasMany(EmployeeLeaveBalance::class, 'employee_user_id', 'employee_user_id')
                    //->where('employee_leave_balances.leave_policy_id', $this->leave_policy_id)
                    ->where('employee_leave_balances.status', 1)
                    ->whereNull('deleted_by')
                    ->whereNull('deleted_at')
                    ;
    }

    // leave history :: need data of current year of leave_application with leave type name
    public function getLeaveHistory()
    {
        // has many -> leave_applications.employee_user_id = leave_applications.employee_user_id
        return $this->hasMany(LeaveApplication::class, 'employee_user_id', 'employee_user_id')
                    ->whereDate('leave_from', '>=', date('Y-01-01'))
                    ->whereNull('deleted_by')
                    ->whereNull('deleted_at')
                    ->orderBy('id', 'desc')
                    ->with([
                        'leaveHead' => function ($query) {
                            $query->withoutGlobalScope('not_deleted');
                        }
                    ])
                    ;
    }

    // leave media files
    public function getXDaysDocs()
    {
        return $this->hasManyThrough(
            MediaUpload::class,
            MediaUploadUsage::class,
            'child_data_identifier_key_incoming',
            'id',
            'child_data_identifier_key_outgoing_x_days',
            'media_upload_id'
        )
        ->addSelect(DB::raw('CONCAT(media_servers.base_url, \'~/\',media_uploads.file_name) as file_url'))
        ->leftJoin('media_servers', 'media_uploads.media_server_id', '=', 'media_servers.id')
        ->whereNull('media_upload_usage.deleted_at')
        ->whereNull('media_uploads.deleted_at')
        ->orderBy('media_upload_usage.id', 'desc');
    }

    public function getChildDataIdentifierKeyOutgoingXDaysAttribute()
    {
        $base = (string) $this->getRawOriginal('child_data_identifier_key_outgoing');
        if ($base === '') {
            return null;
        }
        return $base . '_leave_application_x_days_file';
    }


}
