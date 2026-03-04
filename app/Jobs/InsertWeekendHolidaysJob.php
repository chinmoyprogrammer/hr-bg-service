<?php

namespace App\Jobs;

use App\Models\EmployeeOfficialInformation;
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

        $year = (int) ($this->payload['year'] ?? (int) date('Y'));
        $holidayTypeId = (int) ($this->payload['holiday_type_id'] ?? 8); // Default to Weekend Holiday
        $employeeIds = $this->payload['employee_user_ids'] ?? null;
        $systemUserId = (int) env('SYSTEM_USER_ID', 1);

        $q = EmployeeOfficialInformation::with(['hasWeekendDays' => function ($query) {
            $query->whereNull('deleted_at');
        }]);
        if (is_array($employeeIds) && !empty($employeeIds)) {
            $q->whereIn('employee_user_id', $employeeIds);
        }
        $employees = $q->get();

        $start = new \Carbon\Carbon("$year-01-01");
        $end = (new \Carbon\Carbon("$year-12-31"))->endOfDay();

        $map = [];
        $deleteFromDates = [];

        //..... Inserting employee Weekends / Holidays
        foreach ($employees as $e) {
            $days = $e->hasWeekendDays;
            foreach ($days as $d) {
                $dow = (int) $d->php_week_day_code;
                $isAlt = (int) ($d->is_alternated ?? 0);
                $altStartStr = $d->alternate_starting_date ?? null;
                $altStart = $altStartStr ? new \Carbon\Carbon($altStartStr) : null;
                if ($isAlt === 1 && !$altStart) {
                    $altStart = $start->copy();
                }
                $altStartDow = null;
                $yearStartDow = $start->copy();
                while ((int) $yearStartDow->dayOfWeek !== $dow) {
                    $yearStartDow->addDay();
                }
                if ($altStart) {
                    $altStartDow = $altStart->copy();
                    while ((int) $altStartDow->dayOfWeek !== $dow) {
                        $altStartDow->addDay();
                    }
                    if ($isAlt === 1) {
                        $deleteFromDates[] = $altStart->toDateString();
                    }
                }
                $cursor = $start->copy();
                while ($cursor->lte($end)) {
                    if ((int) $cursor->dayOfWeek === $dow) {
                        $include = true;
                        if ($isAlt === 1) {
                            if ($altStartDow) {
                                if ($cursor->lt($altStart)) {
                                    $weeks = $yearStartDow->diffInWeeks($cursor);
                                    $include = ($weeks % 2) === 0;
                                } else {
                                    $weeks = $altStartDow->diffInWeeks($cursor);
                                    $include = ($weeks % 2) === 0;
                                }
                            } else {
                                $weeks = $yearStartDow->diffInWeeks($cursor);
                                $include = ($weeks % 2) === 0;
                            }
                        }
                        if ($include) {
                            $dateStr = $cursor->toDateString();
                            $key = $dateStr . '|' . $holidayTypeId;
                            $map[$key] = [
                                'employee_user_id' => $e->employee_user_id,
                                'name' => 'Weekend',
                                'day' => (int) $cursor->format('d'),
                                'month' => (int) $cursor->format('m'),
                                'year' => $year,
                                'date' => $dateStr,
                                'holiday_type_id' => $holidayTypeId,
                                'recurring' => 0,
                                'recurring_rule' => null,
                                'description' => null,
                                'status' => 'Active',
                                'created_user_id' => $systemUserId,
                                'updated_user_id' => null,
                                'created_at' => date('Y-m-d H:i:s'),
                                'updated_at' => null,
                                'deleted_by' => null,
                                'deleted_at' => null,
                                'child_data_identifier_key_incoming' => null,
                                'child_data_identifier_key_outgoing' => null,
                            ];
                        }
                    }
                    $cursor->addDay();
                }
            }
        }

        try {

            //.... Inserting 


            $types = DB::table('holiday_types')
                ->select(['id','title','day_month_collection'])
                ->whereNotNull('day_month_collection')
            ->whereRaw('TRIM(day_month_collection) <> ""')
            ->where('status',1)
            ->where('for_year',date('Y'))
                ->get();
            if (empty($types)) 
            { 
                Log::error('No holiday types found for the year ' . date('Y').', File: InsertWeekendHolidaysJob, Line: ' . __LINE__);
                throw new \Exception('No holiday types found for the year ' . date('Y'));

            }
            foreach ($types as $t) {
                $raw = $t->day_month_collection;
                $items = [];
                if (is_string($raw)) {
                    $trim = trim($raw);
                    if ($trim !== '' && strtolower($trim) !== 'null') {
                        $decoded = json_decode($trim, true);
                        if (is_array($decoded)) {
                            $items = $decoded;
                        } else {
                            $norm = str_replace(['[',']','"'], '', $trim);
                            $parts = preg_split('/[\s,\n\r]+/', $norm);
                            foreach ($parts as $p) {
                                $p = trim($p);
                                if ($p !== '') { $items[] = ['date' => $p, 'description' => null]; }
                            }
                        }
                    }
                } elseif (is_array($raw)) {
                    $items = $raw;
                }
                foreach ($items as $item) {
                    $mmdd = is_array($item) ? ($item['date'] ?? null) : $item;
                    $description = is_array($item) ? ($item['description'] ?? null) : null;
                    if (!is_string($mmdd)) { continue; }
                    $s = str_replace(' ', '', $mmdd);
                    $s = str_replace('/', '-', $s);
                    if (preg_match('/^\d{2}-\d{2}$/', $s) !== 1) { continue; }
                    [$mm, $dd] = explode('-', $s);
                    $dateStr = sprintf('%04d-%02d-%02d', $year, (int)$mm, (int)$dd);
                    try {
                        $c = new \Carbon\Carbon($dateStr);
                    } catch (\Throwable $e2) {
                        continue;
                    }
                    $key = $dateStr . '|' . $t->id;
                    $map[$key] = [
                        'employee_user_id' => $e->employee_user_id,
                        'name' => $t->title,
                        'day' => (int) $c->format('d'),
                        'month' => (int) $c->format('m'),
                        'year' => $year,
                        'date' => $dateStr,
                        'holiday_type_id' => (int) $t->id,
                        'recurring' => 1,
                        'recurring_rule' => 'Yearly',
                        'description' => $description,
                        'status' => 'Active',
                        'created_user_id' => $systemUserId,
                        'updated_user_id' => null,
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => null,
                        'deleted_by' => null,
                        'deleted_at' => null,
                        'child_data_identifier_key_incoming' => null,
                        'child_data_identifier_key_outgoing' => null,
                    ];
                }
            }
        } catch (\Throwable $e) {
        }

        if (!empty($map)) {
            if (!empty($deleteFromDates)) {
                $from = min($deleteFromDates);
                DB::table('holidays')
                    ->where('name', 'Weekend')
                    ->where('date', '>=', $from)
                    ->delete();
            }
            foreach (array_chunk(array_values($map), 500) as $chunk) {
                DB::table('holidays')->insertOrIgnore($chunk);
            }
        }
    }
}
