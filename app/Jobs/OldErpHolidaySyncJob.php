<?php

namespace App\Jobs;

use Illuminate\Support\Facades\DB;

/**
 * Brings one holiday date up to date in the old ERP (/apiHolidays, table calendar_days).
 * Queued by hr-admin-backend old_erp_holiday_sync() when HolidayTypeController store /
 * update adds or removes a date; runs in the old ERP sync worker loop (start-worker.sh).
 *
 * State based, keyed by date (the old ERP keeps one holiday per date):
 *   date is a holiday in holiday_types now -> create, or update the description
 *   date is no longer a holiday            -> delete, only if we created it
 * external_id = NEWERP-HD-YYYY-MM-DD. A date the old ERP already has from its own
 * screen (409 on create) is left alone: logged, never overwritten or deleted.
 * Log: storage/logs/old-erp-holiday-sync-YYYY-MM-DD.log
 */
class OldErpHolidaySyncJob extends Job
{
    use OldErpApi;

    private const RESOURCE    = 'apiHolidays';
    private const LOG_CHANNEL = 'old_erp_holiday_sync';

    protected array $payload;
    private string $date = '';

    public function __construct(array $payload = [])
    {
        $this->payload = $payload;
    }

    public function handle(): void
    {
        $this->date = (string) ($this->payload['date'] ?? '');
        if ($this->syncDisabled()) {
            return;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date)) {
            $this->log('warning', 'bad date');
            return;
        }
        $actor = (int) ($this->payload['action_by'] ?? 0);
        $ext = 'NEWERP-HD-' . $this->date;

        $description = $this->description();
        $old = $this->get(['external_id' => $ext]);

        if ($description === null) {
            if ($old !== null) {
                $this->post('delete', ['external_id' => $ext], ['action_by_emp_id' => $actor]);
            }
            return;
        }

        if ($old === null) {
            $this->post('create', [], [
                'action_by_emp_id'    => $actor,
                'external_id'         => $ext,
                'date'                => $this->date,
                'holyday_description' => $description,
            ]);
        } elseif ((string) ($old['holyday_description'] ?? '') !== $description) {
            $this->post('update', ['external_id' => $ext], ['action_by_emp_id' => $actor, 'holyday_description' => $description]);
        }
    }

    /** The date's description in holiday_types now; null when it is not a holiday. */
    private function description(): ?string
    {
        $types = DB::table('holiday_types')
            ->where('for_year', (int) substr($this->date, 0, 4))
            ->where('title', '!=', 'weekend')
            ->whereNull('deleted_at')
            ->get(['title', 'day_month_collection']);

        foreach ($types as $type) {
            foreach (json_decode($type->day_month_collection ?: '[]', true) ?: [] as $item) {
                if (($item['date'] ?? null) === $this->date) {
                    return trim((string) ($item['description'] ?? '')) ?: ucwords(str_replace('_', ' ', $type->title));
                }
            }
        }
        return null;
    }

    private function logSubject(): string
    {
        return "holiday {$this->date}";
    }
}
