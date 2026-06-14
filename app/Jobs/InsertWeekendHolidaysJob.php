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



class InsertWeekendHolidaysJob extends Job implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    protected array $payload;

    public function __construct(array $payload)
    {
        $this->payload = $payload;
        $this->connection = 'rabbitmq';
        $this->queue = 'insertWeekendHolidays_queue';
    }

    public function handle(): void
    {
        
        /*
         * Payload inputs
         *
         * Example payload:
         * [
         *   'year' => 2026,
         *   'holiday_type_id' => 8,
         *   'employee_user_ids' => [101, 102] // optional
         * ]
         */
        //Log::info('InsertWeekendHolidaysJob started', ['payload' => $this->payload]);
        $year = (int) ($this->payload['year'] ?? (int) date('Y'));
        $weekendHolidayTypeId = (int) ($this->payload['holiday_type_id'] ?? 8);
        $employeeIds = $this->payload['employee_user_ids'] ?? null;
        $employeeIds = is_array($employeeIds) ? array_values(array_filter($employeeIds)) : [];

        $systemUserId = (int) env('SYSTEM_USER_ID', 1);
        $now = date('Y-m-d H:i:s');

        /*
         * Load employees with weekend rules.
         * `hasWeekendDays` can contain multiple weekend definitions per employee.
         */
        $employeesQuery = EmployeeOfficialInformation::with(['hasWeekendDays' => function ($query) {
            $query->whereNull('deleted_at')->whereNull('deleted_by');
        }]);
        if (!empty($employeeIds)) {
            $employeesQuery->whereIn('employee_user_id', $employeeIds);
        }
        $employees = $employeesQuery->get();

        $start = Carbon::parse(sprintf('%04d-01-01', $year))->startOfDay();
        $end = Carbon::parse(sprintf('%04d-12-31', $year))->endOfDay();

        /*
         * Build insert rows in-memory (deduped by employee/date/type).
         */
        $rowsByKey = [];
        $i = 0;
        // dd($employees);
        foreach ($employees as $employee) {
            
            foreach ($employee->hasWeekendDays as $rule) {
                
                $dow = (int) $rule->php_week_day_code;
                $isAlternated = (int) ($rule->is_alternated ?? 0) === 1;
                $altStart = !empty($rule->alternate_starting_date) ? Carbon::parse($rule->alternate_starting_date)->startOfDay() : Carbon::parse($employee->joining_date)->startOfDay();

                foreach ($this->generateWeekendDates($start, $end, $dow, $isAlternated, $altStart) as $date) {
                    $i++;
                    $dateStr = $date->toDateString();
                    $key = $dateStr . '|' . $weekendHolidayTypeId.$employee->employee_user_id;
                    $rowsByKey[$key] = $this->buildHolidayRow([
                        'employee_user_id' => $rule->employee_user_id,
                        'name' => 'Weekend',
                        'date' => $dateStr,
                        'holiday_type_id' => $weekendHolidayTypeId,
                        'recurring' => 0,
                        'recurring_rule' => null,
                        'description' => null,
                        'created_user_id' => $systemUserId,
                        'created_at' => $now,
                    ]);
                }
            }
        }
        // dd("Total rows: ", $i);

        /*
         * Public holidays (global-only): inserted once per date/type.
         * To avoid duplicates, only insert global public holidays when processing ALL employees.
         */
        //Log::info('Inserting weekend holidays for year ',[$employeeIds]);

        if (empty($employeeIds) || count($employeeIds) == 0) {
            // dd($this->buildGlobalPublicHolidayRows($year, $systemUserId, $now), $year,$systemUserId,$now);
            //Log::info('Inserting global public holidays for year ->' . $this->buildGlobalPublicHolidayRows($year, $systemUserId, $now));

            foreach ($this->buildGlobalPublicHolidayRows($year, $systemUserId, $now) as $row) {
                //Log::info('Inserting global public holiday: ' . $row['name'], $row);
                $key = 'global|' . $row['date'] . '|' . $row['holiday_type_id'];
                $rowsByKey[$key] = $row;
            }
        }

        /*
         * Delete old data of the given year, then insert the newly generated rows.
         *
         * - If `employee_user_ids` is provided: delete rows for those employees only.
         * - If not provided: delete all rows for that year (including global rows).
         */
        DB::transaction(function () use ($year, $employeeIds, $weekendHolidayTypeId, $rowsByKey) {
            $deleteQuery = DB::table('holidays')->where('year', $year);
           if (!empty($employeeIds) && count($employeeIds) > 0)  {
                $deleteQuery->where('holiday_type_id', $weekendHolidayTypeId)
                ->whereIn('employee_user_id', $employeeIds)
                ;
            }
            $deleteQuery->delete();

            $rows = array_values($rowsByKey);
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('holidays')->insert($chunk);
            }
        });
    }

    /*
     * Weekend date generator (supports alternated weekends with an optional starting date).
     *
     * - `$dow` is PHP/Carbon dayOfWeek (0=Sunday .. 6=Saturday).
     * - If alternated: include every other week; phase can restart from `$altStart`.
     */
    private function generateWeekendDates(Carbon $start, Carbon $end, int $dow, bool $isAlternated, ?Carbon $altStart): array
    {
        $first = $start->copy();
        while ((int) $first->dayOfWeek !== $dow) {
            $first->addDay();
        }

        if (!$isAlternated) {
            $dates = [];
            for ($d = $first->copy(); $d->lte($end); $d->addWeek()) {
                $dates[] = $d->copy();
            }
            return $dates;
        }

        $altStart = $altStart ?: $start->copy();
        $altStartDow = $altStart->copy();
        while ((int) $altStartDow->dayOfWeek !== $dow) {
            $altStartDow->addDay();
        }

        $dates = [];
        for ($d = $first->copy(); $d->lte($end); $d->addWeek()) {
            if ($d->lt($altStart)) {
                $weeks = $first->diffInWeeks($d);
                if (($weeks % 2) === 0) {
                    $dates[] = $d->copy();
                }
                continue;
            }

            $weeks = $altStartDow->diffInWeeks($d);
            if (($weeks % 2) === 0) {
                $dates[] = $d->copy();
            }
        }

        return $dates;
    }

    /*
     * Read holiday_types.day_month_collection and produce global holiday rows for the year.
     *
     * Supported formats for day_month_collection:
     * - JSON array: [{"date":"01-01","description":"New Year"}, ...]
     * - JSON array: ["01-01","02-21", ...]
     * - Plain string: 01-01, 02-21 (comma/space/newline separated)
     */
    private function buildGlobalPublicHolidayRows(int $year, int $systemUserId, string $now): array
    {
        $types = DB::table('holiday_types')
            ->select(['id', 'title', 'day_month_collection'])
            ->whereNotNull('day_month_collection')
            ->whereRaw('TRIM(day_month_collection) <> ""')
            ->where('status', 1)
            ->where('for_year', $year)
            ->get();

            // dd('types',$types);
        if ($types->isEmpty()) {
            Log::warning('No holiday types found for year ' . $year . ' (InsertWeekendHolidaysJob)');
            return [];
        }

        $rows = [];
        foreach ($types as $t) {

            $items = $this->parseDayMonthCollection($t->day_month_collection);
            foreach ($items as $item) {
                $mmdd = $item['date'] ?? null;
                // dd('items',is_string($mmdd));
                $description = $item['description'] ?? null;
                // if (trim($mmdd) === '' || $mmdd === 'null') {
                //     continue;
                // }

                //$dateStr = $this->toYearDateString($year, $mmdd);
                // if (!$dateStr) {
                //     continue;
                // }
                

                $rows[] = $this->buildHolidayRow([
                    'employee_user_id' => null,
                    'name' => (string) $t->title,
                    'date' => $mmdd,
                    'holiday_type_id' => (int) $t->id,
                    'recurring' => 1,
                    'recurring_rule' => 'Yearly',
                    'description' => $description,
                    'created_user_id' => $systemUserId,
                    'created_at' => $now,
                ]);
            }
        }
        // dd($rows);
        return $rows;
    }

    private function parseDayMonthCollection(mixed $raw): array
    {
        $items = [];

        if (is_string($raw)) {
            $trim = trim($raw);
            if ($trim === '' || strtolower($trim) === 'null') {
                return [];
            }

            $decoded = json_decode($trim, true);
            if (is_array($decoded)) {
                foreach ($decoded as $entry) {
                    if (is_array($entry)) {
                        $items[] = [
                            'date' => $entry['date'] ?? null,
                            'description' => $entry['description'] ?? null,
                        ];
                        continue;
                    }
                    if (is_string($entry)) {
                        $items[] = ['date' => $entry, 'description' => null];
                    }
                }
                return $items;
            }

            $norm = str_replace(['[', ']', '"'], '', $trim);
            $parts = preg_split('/[\s,\n\r]+/', $norm);
            foreach ($parts as $p) {
                $p = trim((string) $p);
                if ($p !== '') {
                    $items[] = ['date' => $p, 'description' => null];
                }
            }
            return $items;
        }

        if (is_array($raw)) {
            foreach ($raw as $entry) {
                if (is_array($entry)) {
                    $items[] = [
                        'date' => $entry['date'] ?? null,
                        'description' => $entry['description'] ?? null,
                    ];
                    continue;
                }
                if (is_string($entry)) {
                    $items[] = ['date' => $entry, 'description' => null];
                }
            }
        }

        return $items;
    }

    private function toYearDateString(int $year, string $mmdd): ?string
    {
        $s = str_replace(' ', '', $mmdd);
        $s = str_replace('/', '-', $s);
        if (preg_match('/^\d{2}-\d{2}$/', $s) !== 1) {
            return null;
        }

        [$mm, $dd] = explode('-', $s);
        $dateStr = sprintf('%04d-%02d-%02d', $year, (int) $mm, (int) $dd);

        try {
            Carbon::parse($dateStr);
            return $dateStr;
        } catch (\Throwable) {
            return null;
        }
    }

    private function buildHolidayRow(array $data): array
    {
        $date = Carbon::parse($data['date']);

        return [
            'employee_user_id' => $data['employee_user_id'],
            'name' => $data['name'],
            'day' => (int) $date->format('d'),
            'month' => (int) $date->format('m'),
            'year' => (int) $date->format('Y'),
            'date' => $data['date'],
            'holiday_type_id' => (int) $data['holiday_type_id'],
            'recurring' => (int) ($data['recurring'] ?? 0),
            'recurring_rule' => $data['recurring_rule'] ?? null,
            'description' => $data['description'] ?? null,
            'status' => 'Active',
            'created_user_id' => (int) ($data['created_user_id'] ?? 1),
            'updated_user_id' => null,
            'created_at' => $data['created_at'] ?? date('Y-m-d H:i:s'),
        ];
    }
}
