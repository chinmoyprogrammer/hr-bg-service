<?php

namespace App\Console\Commands;

use App\Jobs\OldErpLateConsiderSyncJob;
use App\Jobs\OldErpLoanSyncJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Catch-up for the old ERP sync: re-runs the sync jobs for recently changed records, so a
 * message that was missed (worker down, sync disabled, retries used up) still lands.
 * The jobs are state based, so a record already in sync costs one GET and sends nothing.
 * Run by the old ERP sync loop in start-worker.sh (daily), or by hand after an outage:
 *   php artisan old-erp:resync --days=30
 */
class OldErpResync extends Command
{
    protected $signature = 'old-erp:resync {--days=7 : records changed in the last N days}';

    protected $description = 'Re-sync recently changed loans and late consider requests to the old ERP.';

    public function handle(): int
    {
        $since = date('Y-m-d H:i:s', strtotime('-' . max(1, (int) $this->option('days')) . ' days'));

        // loans: open ones (payments / pauses don't always touch the loan row) + any changed lately
        $loanIds = !env('OLD_ERP_LOAN_SYNC_FROM') ? [] : DB::table('payroll_salary_advance_n_loans')
            ->where('created_at', '>=', env('OLD_ERP_LOAN_SYNC_FROM'))
            ->where(fn ($q) => $q->where(fn ($open) => $open->whereNull('deleted_at')
                    ->whereNotIn('status', ['closed', 'written_off', 'restructured', 'rejected']))
                ->orWhere('updated_at', '>=', $since)
                ->orWhere('deleted_at', '>=', $since))
            ->pluck('id');

        // late consider: every change (apply / decision / delete) sets updated_at
        $from = substr((string) env('OLD_ERP_LATE_CONSIDER_SYNC_FROM'), 0, 7);
        $lateIds = !$from ? [] : DB::table('late_attendance_consideration_requests')
            ->whereRaw("CONCAT(year, '-', LPAD(month, 2, '0')) >= ?", [$from])
            ->where(fn ($q) => $q->where('updated_at', '>=', $since)->orWhere('deleted_at', '>=', $since))
            ->pluck('id');

        $failed = 0;
        foreach ($loanIds as $id) {
            $failed += $this->syncOne(fn () => (new OldErpLoanSyncJob(['loan_id' => $id]))->handle(), "loan {$id}");
        }
        foreach ($lateIds as $id) {
            $failed += $this->syncOne(fn () => (new OldErpLateConsiderSyncJob(['late_consider_id' => $id]))->handle(), "late consider {$id}");
        }

        $this->info('old-erp:resync ' . count($loanIds) . ' loans, ' . count($lateIds) . " late consider, {$failed} failed (see the sync logs)");
        return $failed ? 1 : 0;
    }

    /** One record; a failure (old ERP down, ...) is already logged by the job and must not stop the rest. */
    private function syncOne(callable $sync, string $label): int
    {
        try {
            $sync();
            return 0;
        } catch (\Throwable $e) {
            $this->error("{$label}: {$e->getMessage()}");
            return 1;
        }
    }
}
