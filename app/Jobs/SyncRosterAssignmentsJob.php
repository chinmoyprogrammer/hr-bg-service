<?php

namespace App\Jobs;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class SyncRosterAssignmentsJob extends Job implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;
    protected array $payload;

    public function __construct(array $payload = [])
    {
        $this->payload = $payload;
        $this->connection = 'rabbitmq';
        $this->queue = 'syncRosterAssignments_queue';
    }

    public function handle(): void
    {
        if (!Schema::hasColumn('employee_official_information', 'employee_user_id') || !Schema::hasColumn('employee_official_information', 'shift_id')) {
            Log::error('SyncRosterAssignmentsJob: required columns missing on employee_official_information.');
            return;
        }
        if (!Schema::hasColumn('employee_official_information', 'actual_shift_id')) {
            Log::error('SyncRosterAssignmentsJob: actual_shift_id column missing on employee_official_information.');
            return;
        }

        $date = Carbon::parse($this->payload['date'] ?? date('Y-m-d'))->toDateString();
        $previousDate = Carbon::parse($date)->subDay()->toDateString();
        //Log::info('SyncRosterAssignmentsJob: date = ' . $date . ', previousDate = ' . $previousDate);
        
        $now = date('Y-m-d H:i:s');
        $userId = getUserId();

        $assignmentsToday = DB::table('roster_assignments')
            ->whereNull('deleted_at')
            ->whereNotNull('employee_user_id')
            ->whereDate('from_date', $date)
            ->whereDate('to_date', $date)
            ->get(['employee_user_id', 'shift_id']);

        $updatedTodayIds = [];

        DB::transaction(function () use ($assignmentsToday, $previousDate, $now, $userId, &$updatedTodayIds) {
            foreach ($assignmentsToday as $row) {
                $employeeUserId = (int) $row->employee_user_id;
                $shiftId = (int) $row->shift_id;
                if ($employeeUserId <= 0 || $shiftId <= 0) {
                    continue;
                }

                DB::table('employee_official_information')
                    ->where('employee_user_id', $employeeUserId)
                    ->update([
                        'actual_shift_id' => DB::raw('IFNULL(actual_shift_id, shift_id)'),
                        'shift_id' => $shiftId,
                        'updated_at' => $now,
                        'updated_user_id' => $userId,
                    ]);
                $updatedTodayIds[$employeeUserId] = true;
            }

            $ids = array_keys($updatedTodayIds);

            $restoreQuery = DB::table('employee_official_information')
                ->whereNotNull('actual_shift_id')
                ->whereRaw('shift_id <> actual_shift_id')
                ->whereExists(function ($q) use ($previousDate) {
                    $q->select(DB::raw(1))
                        ->from('roster_assignments')
                        ->whereNull('roster_assignments.deleted_at')
                        ->whereColumn('roster_assignments.employee_user_id', 'employee_official_information.employee_user_id')
                        ->whereDate('roster_assignments.from_date', $previousDate)
                        ->whereDate('roster_assignments.to_date', $previousDate);
                });
            if ($ids) {
                $restoreQuery->whereNotIn('employee_user_id', $ids);
            }

            $toRestore = $restoreQuery->pluck('employee_user_id')->all();
            if ($toRestore) {
                DB::table('employee_official_information')
                    ->whereIn('employee_user_id', $toRestore)
                    ->update([
                        'shift_id' => DB::raw('actual_shift_id'),
                        'updated_at' => $now,
                        'updated_user_id' => $userId,
                    ]);
            }
        });

        Log::info('SyncRosterAssignmentsJob done', [
            'date' => $date,
            'today_count' => count($updatedTodayIds),
        ]);
    }
}