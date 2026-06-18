<?php

namespace App\Jobs;

use App\Models\EmployeeOfficialInformation;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class FiscalYearClosingJob extends Job implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;
    protected array $payload;

    public function __construct(array $payload = [])
    {
        $this->payload = $payload;
        $this->connection = 'rabbitmq';
        $this->queue = 'fiscalYearClosing_queue';
    }

    public function handle(): void
    {
        $runDate = Carbon::parse($this->payload['date'] ?? date('Y-m-d'))->startOfDay();
        $previousYear = (int) $runDate->copy()->subYear()->format('Y');
        $newYear = (int) $runDate->format('Y');
        $lastDayOfPastYear = Carbon::create($previousYear, 12, 31)->toDateString();
        $newYearValidUntil = Carbon::create($newYear, 12, 31)->toDateString();
        $userId = getUserId();
        /* $leaveBalanceEmployeeColumn = $this->resolveEmployeeColumn('employee_leave_balances');
        $carryForwardEmployeeColumn = $this->resolveEmployeeColumn('leave_carry_forwards');
        $carryForwardHasLeaveHead = Schema::hasColumn('leave_carry_forwards', 'leave_head_id'); */

        $employees = EmployeeOfficialInformation::with(['hasLeavePolicy','hasLeavePolicy.hasDetail'])
            ->whereNotNull('leave_policy_id')
            ->whereNull('deleted_at')
            ->get();
            
        if ($employees->isEmpty()) {
            Log::info('FiscalYearClosingJob: no employees found with leave policy.', ['date' => $runDate->toDateString()]);
            return;
        }
        
        DB::transaction(function () use (
            $employees,
            $previousYear,
            $newYear,
            $lastDayOfPastYear,
            $newYearValidUntil,
            $userId
        ) {
            foreach ($employees as $employee) {
                $details = optional($employee->hasLeavePolicy)->hasDetail ?? collect();
                if ($details->isEmpty()) {
                    continue;
                }

                foreach ($details as $detail) {
                    //DB::enableQueryLog();
                    $lastBalance = DB::table('employee_leave_balances')
                        ->where('employee_user_id', $employee->employee_user_id)
                        ->where('leave_head_id', $detail->leave_head_id)
                        ->where('leave_policy_id', $employee->leave_policy_id)
                        ->whereDate('valid_until', '<=', $lastDayOfPastYear)
                        ->orderByDesc('valid_until')
                        ->orderByDesc('id')
                        ->first();
                    //Log::info('message:', ['query' => DB::getQueryLog()]);

                    $currentBalance = $lastBalance ? (float) $lastBalance->current_balance : 0.0;
                    Log::info('current leave: '. $currentBalance);
                    $forwarded = 0;
                    if($detail->carry_forward == 1){
                        $limit = $detail->carry_forward_limit ?? 0;
                        $forwarded = min($currentBalance, $limit);
                        Log::info('forwarded leave: '. $forwarded . ' limit: '. $limit);
                    }
                    $forwarded = (float) $forwarded;
                    $achievedThisYear = $detail->days ? (float) $detail->days : 0;
                    $newCurrentBalance = $forwarded + $achievedThisYear;

                    if ($currentBalance > 0) {
                        $carryData = [
                            'employee_user_id' => $employee->employee_user_id,
                            'leave_policy_id' => $employee->leave_policy_id,
                            'fiscal_year' => $previousYear,
                            'carried_balance' => $forwarded,
                            'new_fiscal_year' => $newYear,
                            'created_user_id' => $userId,
                            'created_at' => date('Y-m-d H:i:s'),
                            'leave_head_id' => $detail->leave_head_id,
                        ];
                        $carryForwardLookup = array(
                            'employee_user_id' => $employee->employee_user_id,
                            'leave_policy_id' => $employee->leave_policy_id,
                            'fiscal_year' => $previousYear,
                            'new_fiscal_year' => $newYear,
                            'leave_head_id' => $detail->leave_head_id,
                        );
                        //select if exists then continue else insert
                        $carryForwardExists = DB::table('leave_carry_forwards')->where($carryForwardLookup)->exists();
                        if (!$carryForwardExists) {
                            DB::table('leave_carry_forwards')->insert($carryData);
                        }
                    }

                    $newBalanceData = [
                        'employee_user_id' => $employee->employee_user_id,
                        'leave_head_id' => $detail->leave_head_id,
                        'leave_policy_id' => $employee->leave_policy_id,
                        'forwarded' => $forwarded,
                        'achived_this_year' => $achievedThisYear,
                        'current_balance' => $newCurrentBalance,
                        'used' => 0,
                        'encashed_balance' => 0,
                        'valid_until' => $newYearValidUntil,
                        'fiscal_year' => $newYear,
                        'updated_user_id' => $userId,
                        'updated_at' => date('Y-m-d H:i:s'),
                        'created_user_id' => $userId,
                        'created_at' => date('Y-m-d H:i:s'),
                    ];
                    $newBalanceLookup = array(
                        'employee_user_id' => $employee->employee_user_id,
                        'leave_head_id' => $detail->leave_head_id,
                        'leave_policy_id' => $employee->leave_policy_id,
                        'fiscal_year' => $newYear,
                    );
                    //select if exists then continue else insert
                    $newBalanceExists = DB::table('employee_leave_balances')->where($newBalanceLookup)->exists();
                    if (!$newBalanceExists) {
                        DB::table('employee_leave_balances')->insert($newBalanceData);
                    }
                }
            }
        });
    }

    /* private function resolveEmployeeColumn(string $table): string
    {
        if (Schema::hasColumn($table, 'employee_user_id')) {
            return 'employee_user_id';
        }

        return 'employee_id';
    } */

    /* private function resolveForwardedBalance(float $currentBalance, $carryForwardLimit): float
    {
        if ($currentBalance <= 0) {
            return 0.0;
        }

        $limit = is_numeric($carryForwardLimit) ? (float) $carryForwardLimit : null;
        if ($limit !== null && $limit >= 0) {
            return min($currentBalance, $limit);
        }

        return $currentBalance;
    } */

    /* private function resolveUserId(): int
    {
        $id = $this->payload['created_user_id'] ?? $this->payload['user_id'] ?? null;
        if (is_numeric($id) && (int) $id > 0) {
            return (int) $id;
        }

        return (int) env('SYSTEM_USER_ID', 195);
    } */
}
