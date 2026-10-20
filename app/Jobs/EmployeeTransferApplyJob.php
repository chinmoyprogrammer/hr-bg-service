<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Moves employees of CONFIRMED (HR 2nd approval) Periodic transfers once effective_date arrives
 * (task-scheduler fires it at 00:00). Mirrors hr-admin-backend
 * EmployeeTransferRequestController::applyTransfer().
 */
class EmployeeTransferApplyJob extends Job implements ShouldQueue
{
    // hr-admin-backend EmployeeTransferRequest constants
    private const TYPE_PERIODIC = 2;
    private const STATUS_CONFIRMED = 7;
    private const STATUS_COMPLETED = 4;

    protected array $payload;

    public function __construct(array $payload = [])
    {
        $this->payload = $payload;
        $this->connection = 'rabbitmq';
        $this->queue = 'employeeTransferApply_queue';
    }

    public function handle(): void
    {
        $date = $this->payload['date'] ?? date('Y-m-d');
        Log::warning('EmployeeTransferApplyJob:', ['date'=>$date]);

        $transfers = DB::table('employee_transfer_requests')
            ->where('transfer_type', self::TYPE_PERIODIC)
            ->where('status', self::STATUS_CONFIRMED)
            ->whereDate('effective_date', '<=', $date)
            ->whereNull('deleted_at')
            ->get();

        $done = 0;
        foreach ($transfers as $transfer) {
            try {
                $applied = DB::transaction(function () use ($transfer) {
                    $now = date('Y-m-d H:i:s');

                    // status in the where: a re-run never applies the same transfer twice
                    $claimed = DB::table('employee_transfer_requests')
                        ->where('id', $transfer->id)
                        ->where('status', self::STATUS_CONFIRMED)
                        ->update(['status' => self::STATUS_COMPLETED, 'completed_at' => $now, 'updated_at' => $now]);
                    if (!$claimed) {
                        return false;
                    }

                    $updated = DB::table('employee_official_information')
                        ->where('employee_user_id', $transfer->employee_user_id)
                        ->whereNull('deleted_at')
                        ->update([
                            'branch_id'                    => $transfer->to_branch_id,
                            'department_id'                => $transfer->to_department_id,
                            'section_id'                   => $transfer->to_section_id,
                            'sub_section_id'               => $transfer->to_sub_section_id,
                            'reporting_supervisor_user_id' => $transfer->to_reporting_supervisor_user_id,
                            'updated_user_id'              => $transfer->updated_user_id,
                            'updated_at'                   => $now,
                        ]);
                    if (!$updated) {
                        throw new \RuntimeException('Official information not found for employee ' . $transfer->employee_user_id);
                    }

                    return true;
                });
            } catch (\Throwable $e) {
                Log::error('EmployeeTransferApplyJob: transfer failed', ['id' => $transfer->id, 'error' => $e->getMessage()]);
                continue;
            }

            if ($applied) {
                $done++;
                pushNotificationToRabbitMQ(
                    $transfer->employee_user_id,
                    'Transfer request ' . $transfer->transfer_number . ': Employee transferred.',
                    'notification1'
                );
            }
        }

        Log::info("EmployeeTransferApplyJob: {$done} of {$transfers->count()} transfers applied for {$date}");
        if ($done) {
            regenerateEmployeeCache();
        }
    }
}
