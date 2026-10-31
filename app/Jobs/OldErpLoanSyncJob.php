<?php

namespace App\Jobs;

use Illuminate\Support\Facades\DB;

/**
 * Brings one new-ERP loan up to date in the old ERP (/apiLoans). Queued by
 * hr-admin-backend old_erp_loan_sync() on oldErpLoanSync_queue after every loan
 * change in scope; runs in its own worker loop (start-worker.sh).
 *
 * State based: reads the loan as it is NOW and sends only what the old ERP
 * lacks, so retries, duplicate and out-of-order messages are harmless.
 *
 * Synced:     create (pending) -> approve / deny, direct payments (receive +
 *             approve), approved pause months, stop on close / write-off /
 *             restructure.
 * Not synced: salary deductions, hold / resume, waiver, reschedule.
 * Loans created before OLD_ERP_LOAN_SYNC_FROM (incl. the opening-balance
 * import) are not in the old ERP and are skipped.
 *
 * Old API refusals (422) are logged and end this sync; network errors / 5xx /
 * 401 throw so the worker retries and the message finally lands in failed_jobs.
 * Log: storage/logs/old-erp-loan-sync-YYYY-MM-DD.log
 */
class OldErpLoanSyncJob extends Job
{
    use OldErpApi;

    private const RESOURCE    = 'apiLoans';
    private const LOG_CHANNEL = 'old_erp_loan_sync';
    private const TYPES    = ['advance' => 20, 'loan' => 21, 'pf_loan' => 24];   // old adv_pay_type
    private const LABELS   = ['advance' => 'Salary advance', 'loan' => 'Long-term loan', 'pf_loan' => 'PF loan'];
    private const TERMINAL = ['closed', 'written_off', 'restructured'];

    protected array $payload;
    private int $loanId = 0;

    public function __construct(array $payload = [])
    {
        $this->payload = $payload;
    }

    public function handle(): void
    {
        $this->loanId = (int) ($this->payload['loan_id'] ?? 0);
        $loan = DB::table('payroll_salary_advance_n_loans')->find($this->loanId);
        if (!$loan) {
            $this->log('warning', 'loan not found');
            return;
        }

        $from = env('OLD_ERP_LOAN_SYNC_FROM');
        if (!$from) {
            $this->log('warning', 'sync disabled: OLD_ERP_LOAN_SYNC_FROM is not set');
            return;
        }
        if ($loan->created_at < $from) {
            $this->log('info', "skipped: created {$loan->created_at}, before {$from} -- not in the old ERP");
            return;
        }
        if (!isset(self::TYPES[$loan->type])) {
            $this->log('warning', "skipped: type {$loan->type} has no old ERP type");
            return;
        }

        $ext = 'NEWERP-LN-' . $loan->id;
        $cancelled = $loan->deleted_at || (int) $loan->approval_status === 2;

        $old = $this->get(['external_id' => $ext]);
        if ($old === null) {
            if ($cancelled) {
                $this->log('info', 'rejected / deleted before it reached the old ERP -- nothing to deny');
                return;
            }
            $created = $this->post('create', [], $this->createBody($loan, $ext));
            if ($created === null) {
                return;
            }
            $old = $created + ['status' => 'pending'];
        } elseif ($diff = $this->identityMismatch($loan, $old)) {
            // the new ERP id now names another loan (DB restored / copied) -- never touch someone else's record
            $this->log('error', "{$ext} in the old ERP is a different loan ({$diff}) -- nothing sent", ['old_loan_no' => $old['loan_no'] ?? null]);
            return;
        }

        $status = $this->oldStatus($old);
        if ($status === 'pending') {
            if ($cancelled) {
                $this->post('deny', ['external_id' => $ext], [
                    'action_by_emp_id' => $this->actor(['rejected', 'application_cancelled', 'restructure_cancelled']) ?? $loan->deleted_by,
                ]);
                return;
            }
            if ((int) $loan->approval_status !== 1) {
                return;   // pending on both sides
            }
            $body = [
                'action_by_emp_id' => $this->actor(['approved']),
                'approved_at'      => $loan->approve_reject_date ?: date('Y-m-d'),
                'effective_month'  => $this->effectiveMonth($loan),
            ];
            if ($loan->type !== 'advance') {
                $body['amount'] = (float) $loan->amount;
                $body['no_of_month'] = $this->installments($loan);
            }
            if ($this->post('approve', ['external_id' => $ext], $body) === null) {
                return;
            }
        } elseif ($status !== 'approved') {
            $this->log('info', "old record is {$status} -- nothing more to send");
            return;
        }

        $this->syncPayments($loan, $ext, $old['loan_no'] ?? null);

        if (!in_array($loan->status, self::TERMINAL, true)) {
            $this->syncPauses($loan, $ext, $old);
            return;
        }
        // re-read: a receive that cleared the due already made the old loan full paid + stopped
        $old = $this->get(['external_id' => $ext]) ?? [];
        // old flags: is_stopped 1 = yes, 2 = no; the API also returns plain booleans
        $stopped  = $old['stopped'] ?? ((string) ($old['is_stopped'] ?? '') === '1');
        $fullPaid = $old['full_paid'] ?? ((string) ($old['is_full_paid'] ?? '') === '1');
        if (!$stopped && !$fullPaid) {
            $this->post('stop', ['external_id' => $ext], [
                'action_by_emp_id' => $this->actor(['closed', 'written_off', 'restructured']),
            ]);
        }
    }

    private function createBody(object $loan, string $ext): array
    {
        $label = self::LABELS[$loan->type] . ' - New ERP #' . $loan->id;
        $comments = trim((string) $loan->comments);

        $body = [
            'action_by_emp_id' => (int) $loan->created_user_id,
            'external_id'      => $ext,
            'employee_id'      => (int) $loan->employee_user_id,   // users.id = old employees.id
            'adv_pay_type'     => self::TYPES[$loan->type],
            'amount'           => (float) $loan->amount,
            'effective_month'  => $this->effectiveMonth($loan),
            'title'            => $comments !== '' ? $comments : $label,
            'details'          => $comments !== '' ? $label : null,
            'transaction_date' => substr((string) $loan->created_at, 0, 10),
        ];
        if ($loan->type !== 'advance') {   // CURRENT is always 1 month on the old side
            $body['no_of_month'] = $this->installments($loan);
        }
        return $body;
    }

    /**
     * Direct payments (incl. cash paid with a pause request) -> receive + approve, oldest first.
     * A payment the old ERP refuses (e.g. dated in a paused month) is logged and skipped, so it
     * never blocks the payments, pauses and stop after it; it is tried again on every later sync.
     */
    private function syncPayments(object $loan, string $loanExt, ?string $loanNo): void
    {
        $rows = DB::table('payroll_salary_loan_advance_n_other_installments')
            ->where('payroll_salary_advance_n_loan_id', $loan->id)
            ->where('transaction_type', 'direct_payment')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $ext  = 'NEWERP-PAY-' . $row->id;
            $date = $row->payment_date ?: substr((string) $row->created_at, 0, 10);

            $rec = $this->get(['external_id' => $ext]);
            if ($rec === null) {
                $rec = $this->post('receive', [], [
                    'action_by_emp_id' => (int) $row->created_user_id,
                    'loan_external_id' => $loanExt,
                    'external_id'      => $ext,
                    'received_amount'  => (float) $row->amount,
                    'effective_month'  => substr($date, 0, 7),
                    'transaction_date' => $date,
                    'title'            => 'Payment - New ERP #' . $loan->id,
                    'details'          => $row->reference_no ? 'Ref ' . $row->reference_no : null,
                ]);
                if ($rec === null) {
                    continue;
                }
                $rec += ['status' => 'pending'];
            } elseif (round((float) ($rec['received_amount'] ?? 0), 2) !== round((float) $row->amount, 2)
                || ($loanNo !== null && ($rec['loan_no'] ?? null) !== $loanNo)) {
                $this->log('error', "{$ext} in the old ERP is a different payment (" . ($rec['loan_no'] ?? '?') . ' ' . ($rec['received_amount'] ?? '?') . ') -- nothing sent');
                continue;
            }

            if ($this->oldStatus($rec) === 'pending') {
                $this->post('approve', ['external_id' => $ext], [
                    'action_by_emp_id' => (int) $row->created_user_id,
                    'approved_at'      => $date,
                ]);
            }
        }
    }

    /** Approved pause months; the old API replaces its whole list, so the full set is sent. */
    private function syncPauses(object $loan, string $ext, array $old): bool
    {
        $months = DB::table('meal_loan_ait_pf_etc_pauses')
            ->where('child_data_identifier_key_incoming', $loan->child_data_identifier_key_outgoing)
            ->where('pause_type', 2)
            ->where('approval_status', 1)
            ->whereNull('deleted_at')
            ->get(['pause_month', 'pause_year'])
            ->map(fn ($p) => sprintf('%04d-%02d', $p->pause_year, $p->pause_month))
            ->all();
        $months = self::monthList($months);

        if (!$months || (array_key_exists('pause_months', $old) && self::monthList($old['pause_months']) === $months)) {
            return true;
        }
        return $this->post('pauseDeduction', ['external_id' => $ext], [
            'action_by_emp_id' => $this->actor(['paused']),
            'months'           => $months,
        ]) !== null;
    }

    /** Why an old record found by external_id is not this loan ('' = it is). */
    private function identityMismatch(object $loan, array $old): string
    {
        $diff = [];
        if ((string) ($old['employee_id'] ?? '') !== (string) $loan->employee_user_id) {
            $diff[] = 'employee ' . ($old['employee_id'] ?? '?') . " vs {$loan->employee_user_id}";
        }
        if ((int) ($old['adv_pay_type'] ?? 0) !== self::TYPES[$loan->type]) {
            $diff[] = 'type ' . ($old['adv_pay_type'] ?? '?') . ' vs ' . self::TYPES[$loan->type];
        }
        if (round((float) ($old['amount'] ?? 0), 2) !== round((float) $loan->amount, 2)) {
            $diff[] = 'amount ' . ($old['amount'] ?? '?') . " vs {$loan->amount}";
        }
        return implode(', ', $diff);
    }

    private function effectiveMonth(object $loan): string
    {
        if ($loan->type === 'advance' && $loan->salary_year && $loan->salary_month) {
            return sprintf('%04d-%02d', $loan->salary_year, $loan->salary_month);
        }
        if ($loan->first_installment_year && $loan->first_installment_month) {
            return sprintf('%04d-%02d', $loan->first_installment_year, $loan->first_installment_month);
        }
        return substr((string) $loan->effective_date, 0, 7);
    }

    private function installments(object $loan): int
    {
        return (int) ($loan->current_number_of_installments ?: $loan->primary_number_of_installments ?: 1);
    }

    /** Who did it: the latest audit entry of these actions on this loan (null -> old API refuses, logged). */
    private function actor(array $actions): ?int
    {
        $id = DB::table('payroll_loan_audit_logs')
            ->where('payroll_salary_advance_n_loan_id', $this->loanId)
            ->whereIn('action', $actions)
            ->orderByDesc('id')
            ->value('performed_by');
        return $id ? (int) $id : null;
    }

    /** pending | approved | denied, from either the text status or the old is_approved code. */
    public static function oldStatus(array $record): string
    {
        $s = strtolower(trim((string) ($record['status'] ?? $record['is_approved'] ?? '')));
        return ['15' => 'pending', '16' => 'approved', 'paid' => 'approved', '17' => 'denied', 'rejected' => 'denied'][$s] ?? $s;
    }

    /** Sorted unique YYYY-MM list from an array or a comma separated string. */
    public static function monthList($value): array
    {
        $list = is_array($value) ? $value : preg_split('/[\s,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY);
        $list = array_map(fn ($m) => substr(is_array($m) ? (string) ($m['month'] ?? '') : (string) $m, 0, 7), $list);
        $list = array_values(array_unique(array_filter($list)));
        sort($list);
        return $list;
    }

    private function logSubject(): string
    {
        return "loan {$this->loanId}";
    }
}
