<?php

namespace App\Services;

use App\Jobs\ProcessSandwichedHolidaysJob;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeAttendanceStatusLog;
use App\Models\EmployeeLeaveAchieveLog;
use App\Models\EmployeeOfficialInformation;
use App\Models\Holiday;
use App\Models\LeaveApplicationDetail;
use App\Models\PayrollAccruedAllowanceIncome;
use App\Models\Shift;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Undoes ProcessSandwichedHolidaysJob merges that no longer hold.
 *
 * Example: 1st Absent, 2nd Weekend, 3rd Absent -> the job turns the 2nd into
 * Absent (absent_bridge = 1) and soft-deletes its holidays row. When leave on
 * the 1st or the 3rd is approved the 2nd is no longer sandwiched, so:
 *   1. collect()  - BEFORE the leave dates are recalculated (that wipes their
 *                   absent_bridge rows), note every holiday row the bridge
 *                   soft-deleted near the leave dates.
 *   2. restore()  - AFTER the leave dates are recalculated, un-delete those
 *                   rows, rebuild their attendance as Weekend/Holiday, then
 *                   re-run ProcessSandwichedHolidaysJob for the employee so a
 *                   holiday that is still sandwiched by other absences gets
 *                   merged again.
 *
 * Restoring every bridged holiday in the window and re-running the job keeps
 * the rules in one place (the job) instead of re-deriving here which merge
 * depended on which absence.
 */
class AbsentBridgeRestoreService
{
    // Same buffer ProcessSandwichedHolidaysJob uses around a month, so any
    // holiday chain / full-week weekend a leave date could have fed is covered.
    private const WINDOW_DAYS = 14;

    private const WEEKEND_HOLIDAY_TYPE_ID = 8;

    protected int $systemUserId;

    public function __construct()
    {
        $this->systemUserId = (int) env('SYSTEM_USER_ID', 1);
    }

    /**
     * Holiday rows soft-deleted by the bridge job within WINDOW_DAYS of the
     * given dates, keyed by date. A soft-deleted row counts only if that date
     * still carries an absent_bridge attendance row - that row is what proves
     * the bridge merged the day. deleted_by is not checked, so rows soft-deleted
     * by hand to back-fill merges made before soft delete existed qualify too.
     *
     * @param array<string> $dates
     */
    public function collect(int $employeeUserId, array $dates): Collection
    {
        if (empty($dates)) {
            return collect();
        }

        sort($dates);
        $from = Carbon::parse($dates[0])->subDays(self::WINDOW_DAYS)->toDateString();
        $to = Carbon::parse(end($dates))->addDays(self::WINDOW_DAYS)->toDateString();

        $bridgeDates = EmployeeAttendance::where('employee_user_id', $employeeUserId)
            ->whereBetween('date', [$from, $to])
            ->where('absent_bridge', 1)
            ->pluck('date')
            ->unique()
            ->all();

        if (empty($bridgeDates)) {
            return collect();
        }

        $holidays = Holiday::withoutGlobalScope('not_deleted')
            ->where('employee_user_id', $employeeUserId)
            ->whereIn('date', $bridgeDates)
            ->where(function ($q) {
                $q->whereNotNull('deleted_at')->orWhereNotNull('deleted_by');
            })
            ->get()
            ->groupBy('date');

        Log::info('AbsentBridgeRestoreService collected bridged holidays', [
            'employee_user_id' => $employeeUserId,
            'window' => [$from, $to],
            'dates' => $holidays->keys()->all(),
        ]);

        return $holidays;
    }

    /**
     * @param Collection $bridgedHolidays result of collect(), keyed by date
     */
    public function restore(int $employeeUserId, Collection $bridgedHolidays): void
    {
        if ($bridgedHolidays->isEmpty()) {
            return;
        }

        $employee = EmployeeOfficialInformation::with([
            'employeeAttendanceTemps' => function ($query) {
                $query->whereRaw('1 = 0'); // no punches: a bridged day had none
            },
        ])->where('employee_user_id', $employeeUserId)->first();

        if (!$employee) {
            Log::warning("AbsentBridgeRestoreService: employee {$employeeUserId} not found");
            return;
        }

        $dates = $bridgedHolidays->keys()->all();

        // A restored date that is itself approved leave (e.g. a leave bridge day)
        // keeps its leave attendance - only the holidays row comes back.
        $leaveDates = LeaveApplicationDetail::where('employee_user_id', $employeeUserId)
            ->whereIn('leave_date', $dates)
            ->whereHas('leaveApplication', function ($q) {
                $q->where('approval_status', 1);
            })
            ->pluck('leave_date')
            ->flip();

        foreach ($bridgedHolidays as $date => $holidayRows) {
            DB::transaction(function () use ($employee, $date, $holidayRows, $leaveDates) {
                Holiday::withoutGlobalScope('not_deleted')
                    ->whereIn('id', $holidayRows->pluck('id'))
                    ->update([
                        'deleted_at' => null,
                        'deleted_by' => null,
                        'updated_user_id' => $this->systemUserId,
                    ]);

                Log::info("Restored holiday for employee {$employee->employee_user_id} on {$date}");

                if ($leaveDates->has($date)) {
                    return;
                }

                $this->rebuildHolidayAttendance($employee, $date, $holidayRows);
            });
        }

        // Re-merge whatever is still sandwiched by the remaining absences.
        $months = collect($dates)
            ->map(fn ($d) => Carbon::parse($d)->format('Y-m'))
            ->unique();

        foreach ($months as $ym) {
            [$year, $month] = explode('-', $ym);
            (new ProcessSandwichedHolidaysJob([
                'year' => (int) $year,
                'month' => (int) $month,
                'employee_user_ids' => [$employee->employee_user_id],
            ]))->handle();
        }
    }

    /**
     * Replace the bridge's Absent row for $date with a normal no-punch
     * attendance row, which AttendanceProcessingService marks as
     * Weekend (17) or Holiday (9).
     */
    private function rebuildHolidayAttendance(EmployeeOfficialInformation $employee, string $date, Collection $holidayRows): void
    {
        $employeeUserId = $employee->employee_user_id;

        $existing = EmployeeAttendance::where('employee_user_id', $employeeUserId)
            ->where('date', $date)
            ->get(['id', 'shift_id']);

        $shiftId = $existing->pluck('shift_id')->filter()->first() ?? $employee->shift_id;
        $shift = $shiftId ? Shift::find($shiftId) : Shift::find(1);

        $attendanceIds = $existing->pluck('id');
        if ($attendanceIds->isNotEmpty()) {
            EmployeeAttendanceStatusLog::whereIn('employee_attendance_id', $attendanceIds)->delete();
            EmployeeLeaveAchieveLog::whereIn('employee_attendance_id', $attendanceIds)->delete();
            PayrollAccruedAllowanceIncome::whereIn('employee_attendance_id', $attendanceIds)->delete();
            EmployeeAttendance::whereIn('id', $attendanceIds)->delete();
        }

        $publicHoliday = $holidayRows->first(fn ($h) => (int) $h->holiday_type_id !== self::WEEKEND_HOLIDAY_TYPE_ID);
        $empHoliday = $holidayRows->first(fn ($h) => (int) $h->holiday_type_id === self::WEEKEND_HOLIDAY_TYPE_ID);

        $result = app(AttendanceProcessingService::class)->process(
            $employee,
            $date,
            $shift,
            $publicHoliday,
            $empHoliday,
            collect(),
            null,
            null
        );

        if ($result['skip'] || !$result['attendance']) {
            Log::warning("AbsentBridgeRestoreService: attendance not rebuilt for employee {$employeeUserId} on {$date}", [
                'skip' => $result['skip'],
            ]);
            return;
        }

        $attendanceId = EmployeeAttendance::insertGetId($result['attendance']);

        foreach (['statusLogs' => EmployeeAttendanceStatusLog::class,
                  'leaveAchieveLogs' => EmployeeLeaveAchieveLog::class,
                  'payrollAccruedItems' => PayrollAccruedAllowanceIncome::class] as $key => $model) {
            $rows = array_map(function ($row) use ($attendanceId) {
                $row['employee_attendance_id'] = $attendanceId;
                return $row;
            }, $result[$key] ?? []);

            if (!empty($rows)) {
                $model::insert($rows);
            }
        }

        Log::info("Rebuilt holiday attendance for employee {$employeeUserId} on {$date}, attendance ID: {$attendanceId}");
    }
}
