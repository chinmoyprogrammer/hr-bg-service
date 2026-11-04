<?php

namespace App\Jobs;

use App\Models\EmployeeOfficialInformation;
use App\Services\OldErpEmployeeService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ConfirmProvisionalEmployeesJob extends Job implements ShouldQueue
{

    use InteractsWithQueue, Queueable, SerializesModels;


    private const PROBATIONARY = 10; // employee_types.id
    private const REGULAR = 69; // employee_types.id, permanent employee

    protected array $payload;

    public function __construct(array $payload = [])
    {
        $this->payload = $payload;
        $this->connection = 'rabbitmq';
        $this->queue = 'confirmProvisionalEmployees_queue';
    }

    public function handle(): void
    {
        $targetDate = Carbon::parse($this->payload['date'] ?? date('Y-m-d'))->toDateString();
        //DB::enableQueryLog();
        $employees = EmployeeOfficialInformation::query()
            ->whereNotNull('joining_date')
            ->where(function ($query) use ($targetDate) {
                // probationary employee whose confirmation date has arrived
                $query->where(function ($q) use ($targetDate) {
                    $q->where('employee_type_id', self::PROBATIONARY)
                        ->where('confirmation_date', '<=', $targetDate);
                })
                // no confirmation date yet and provision period is over
                ->orWhere(function ($q) use ($targetDate) {
                    $q->whereNotNull('provisioner_days')
                        ->whereNull('confirmation_date')
                        ->whereRaw('DATEDIFF(?, joining_date) >= provisioner_days', [$targetDate]);
                });
            })
            ->get([
                'id',
                'employee_user_id',
                'confirmation_date',
                'employee_type_id',
            ]);
        //$queries = DB::getQueryLog();
        /* Log::info('ConfirmProvisionalEmployeesJob: queries', [
            'queries' => $queries,
        ]); */
        if ($employees->isEmpty()) {
            Log::info('ConfirmProvisionalEmployeesJob: no employees found for confirmation on date ' . $targetDate);
            return;
        }

        foreach ($employees as $employee) {
            DB::beginTransaction();
            try {
                $employee->confirmation_date = $employee->confirmation_date ?: $targetDate;
                $promoted = $employee->employee_type_id == self::PROBATIONARY;
                if ($promoted) {
                    $employee->employee_type_id = self::REGULAR;
                    DB::table('users')->where('id', $employee->employee_user_id)->update(['employee_type_id' => self::REGULAR]);
                }
                $employee->save();
                // no leave here: paid leave is generated automatically only by FiscalYearClosingJob

                //... the old ERP must take the type change too, otherwise nothing is saved here
                if ($promoted) {
                    (new OldErpEmployeeService())->saveEmployeeDataToOldErp($employee->employee_user_id);
                }
                DB::commit();
            } catch (\Throwable $e) {
                // stays probationary, so the next run tries this employee again
                DB::rollBack();
                Log::error('ConfirmProvisionalEmployeesJob: employee not confirmed', [
                    'employee_user_id' => $employee->employee_user_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        regenerateEmployeeCache();
    }
}
