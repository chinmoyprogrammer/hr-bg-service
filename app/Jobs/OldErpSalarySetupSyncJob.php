<?php

namespace App\Jobs;

use Illuminate\Support\Facades\DB;

/**
 * Sends one employee's gross salary to the old ERP (/apiSalarySetup/create). The old ERP
 * works out its own heads from the gross (Basic = gross x %, others = Basic x %) and that
 * breakdown is accepted as is.
 * Queued by hr-admin-backend old_erp_salary_setup_sync() after employee create and after an
 * increment approval whose effective month has come; increments with a later effective month
 * are sent by old-erp:resync on that date.
 *
 * State based: gross = employee_official_information.gross_salary now; nothing is sent when the
 * old ERP's active setup already has that gross, so retries and repeats are harmless.
 * With increment_history_id: sent only while the increment record is still approved and its
 * effective month has come.
 * Log: storage/logs/old-erp-salary-setup-sync-YYYY-MM-DD.log
 */
class OldErpSalarySetupSyncJob extends Job
{
    use OldErpApi;

    private const RESOURCE    = 'apiSalarySetup';
    private const LOG_CHANNEL = 'old_erp_salary_setup_sync';

    protected array $payload;
    private int $employeeId = 0;

    public function __construct(array $payload = [])
    {
        $this->payload = $payload;
    }

    public function handle(): void
    {
        $this->employeeId = (int) ($this->payload['employee_user_id'] ?? 0);
        if ($this->syncDisabled()) {
            return;
        }
        $actor = (int) ($this->payload['action_by'] ?? 0);

        if ($historyId = (int) ($this->payload['increment_history_id'] ?? 0)) {
            $inc = DB::table('increment_promotion_history as h')
                ->join('increment_promotion_records as r', 'r.id', '=', 'h.record_id')
                ->where('h.id', $historyId)
                ->first(['h.effective_month', 'h.approved_by_user_id', 'r.status', 'r.deleted_at']);
            if (!$inc || $inc->status !== 'approved' || $inc->deleted_at) {
                $this->log('info', "skipped: increment history {$historyId} is no longer approved");
                return;
            }
            if ($inc->effective_month > date('Y-m-d')) {
                $this->log('info', "skipped: increment effective {$inc->effective_month}, not yet");
                return;
            }
            $actor = $actor ?: (int) $inc->approved_by_user_id;
        }

        $gross = DB::table('employee_official_information')->where('employee_user_id', $this->employeeId)->value('gross_salary');
        if (!$gross || (float) $gross <= 0) {
            $this->log('warning', 'skipped: no gross salary');
            return;
        }
        $gross = round((float) $gross, 2);

        $old = $this->get(['employee_id' => $this->employeeId]);
        if ($old !== null && round((float) ($old['gross_salary'] ?? 0), 2) === $gross) {
            $this->log('info', "skipped: old ERP already has gross {$gross}");
            return;
        }

        $this->post('create', [], [
            'action_by_emp_id' => $actor,
            'external_id'      => 'SS-' . $this->employeeId . '-' . date('YmdHis'),
            'employee_id'      => $this->employeeId,   // users.id = old employees.id
            'gross_salary'     => $gross,
        ]);
    }

    private function logSubject(): string
    {
        return "employee {$this->employeeId}";
    }
}
