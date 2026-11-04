<?php

namespace Tests;

use App\Jobs\ConfirmProvisionalEmployeesJob;
use App\Models\EmployeeBasicInformation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

// Leave is given once, when the job confirms someone; it never refills a truncated table.
class ConfirmProvisionalLeaveTest extends TestCase
{
    use TrackingDatabase;

    private const PROBATIONARY = 10;
    private const REGULAR = 69;
    private const CONTRACTUAL = 4;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useTrackingDatabase();
        Queue::fake(); // regenerateEmployeeCache() pushes to rabbitmq
        DB::connection()->getPdo()->sqliteCreateFunction('DATEDIFF', fn ($a, $b) => (int) round((strtotime($a) - strtotime($b)) / 86400), 2);

        Schema::create('users', function ($t) {
            $t->integer('id');
            $t->integer('employee_type_id');
        });
        Schema::create('employee_official_information', function ($t) {
            $t->increments('id');
            $t->integer('employee_user_id');
            $t->date('joining_date')->nullable();
            $t->integer('provisioner_days')->nullable();
            $t->date('confirmation_date')->nullable();
            $t->integer('leave_policy_id')->nullable();
            $t->decimal('basic_salary')->default(30000);
            $t->decimal('gross_salary')->default(50000);
            $t->integer('employee_type_id');
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('leave_policy_details', function ($t) {
            $t->increments('id');
            $t->integer('leave_policy_id');
            $t->integer('leave_head_id');
            $t->decimal('days');
            $t->string('encashment_basis')->nullable();
            $t->decimal('encashment_basis_rate')->default(0);
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('employee_leave_balances', function ($t) {
            $t->increments('id');
            $t->integer('employee_user_id');
            $t->integer('leave_head_id');
            $t->integer('leave_policy_id');
            $t->decimal('achived_this_year')->default(0);
            $t->decimal('current_balance')->default(0);
            $t->date('valid_until')->nullable();
            $t->integer('fiscal_year');
            $t->integer('created_user_id')->nullable();
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });
        // Empty: the old ERP save stops at "employee data not found" before any HTTP call
        Schema::create((new EmployeeBasicInformation())->getTable(), function ($t) {
            $t->increments('id');
            $t->integer('employee_user_id');
            $t->timestamp('deleted_at')->nullable();
        });

        DB::table('leave_policy_details')->insert([
            ['leave_policy_id' => 1, 'leave_head_id' => 1, 'days' => 7, 'encashment_basis' => 'basic'], // CL
            ['leave_policy_id' => 1, 'leave_head_id' => 2, 'days' => 14, 'encashment_basis' => 'basic'], // SL
        ]);
    }

    private function employee(int $id, int $type, int $joinedDaysAgo, ?int $provisionerDays, ?string $confirmation): void
    {
        DB::table('users')->insert(['id' => $id, 'employee_type_id' => $type]);
        DB::table('employee_official_information')->insert([
            'employee_user_id' => $id,
            'joining_date' => date('Y-m-d', strtotime("-$joinedDaysAgo days")),
            'provisioner_days' => $provisionerDays,
            'confirmation_date' => $confirmation,
            'leave_policy_id' => 1,
            'employee_type_id' => $type,
        ]);
    }

    private function run_job(): void
    {
        (new ConfirmProvisionalEmployeesJob(['date' => date('Y-m-d')]))->handle();
    }

    private function balances(int $id): array
    {
        return DB::table('employee_leave_balances')->where('employee_user_id', $id)->orderBy('leave_head_id')
            ->pluck('current_balance')->map(fn ($v) => (float) $v)->all();
    }

    // hr-admin's rule: remaining days / days in year * annual leave; < .25 down, < .75 to .5, else up
    private function prorated(float $days, string $start): float
    {
        $remaining = (strtotime(date('Y-12-31')) - strtotime($start)) / 86400 + 1;
        $value = $days * round($remaining) / (date('L') ? 366 : 365);
        $whole = floor($value);
        $fraction = round($value - $whole, 6);
        return $whole + ($fraction < 0.25 ? 0 : ($fraction < 0.75 ? 0.5 : 1));
    }

    public function test_probationary_confirmed_today_or_yesterday_becomes_regular_with_prorated_leave(): void
    {
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $this->employee(1, self::PROBATIONARY, 180, 180, $today);
        $this->employee(2, self::PROBATIONARY, 181, 180, $yesterday);

        $this->run_job();

        $this->assertSame([$this->prorated(7, $today), $this->prorated(14, $today)], $this->balances(1));
        $this->assertSame([$this->prorated(7, $yesterday), $this->prorated(14, $yesterday)], $this->balances(2));
        foreach ([1, 2] as $id) {
            $this->assertSame(self::REGULAR, (int) DB::table('users')->where('id', $id)->value('employee_type_id'));
            $this->assertSame(self::REGULAR, (int) DB::table('employee_official_information')->where('employee_user_id', $id)->value('employee_type_id'));
        }
    }

    public function test_confirmation_tomorrow_changes_nothing(): void
    {
        $this->employee(3, self::PROBATIONARY, 10, 180, date('Y-m-d', strtotime('+1 day')));

        $this->run_job();

        $this->assertSame([], $this->balances(3));
        $this->assertSame(self::PROBATIONARY, (int) DB::table('users')->where('id', 3)->value('employee_type_id'));
    }

    public function test_truncated_table_is_not_refilled(): void
    {
        $today = date('Y-m-d');
        $this->employee(1, self::PROBATIONARY, 180, 180, $today);
        $this->employee(4, self::REGULAR, 30, 0, date('Y-m-d', strtotime('-30 days'))); // the case that used to refill

        $this->run_job();
        $this->assertCount(2, $this->balances(1));
        $this->assertSame([], $this->balances(4));

        DB::table('employee_leave_balances')->delete();
        $this->run_job();
        $this->run_job();

        $this->assertSame(0, DB::table('employee_leave_balances')->count());
    }

    public function test_repeat_runs_add_no_duplicates(): void
    {
        $this->employee(1, self::PROBATIONARY, 180, 180, date('Y-m-d'));

        $this->run_job();
        $this->run_job();

        $this->assertCount(2, $this->balances(1));
    }

    public function test_other_types_are_confirmed_without_paid_leave(): void
    {
        $this->employee(5, self::CONTRACTUAL, 30, 0, null);

        $this->run_job();

        $this->assertSame([], $this->balances(5));
        $this->assertSame(date('Y-m-d'), DB::table('employee_official_information')->where('employee_user_id', 5)->value('confirmation_date'));
    }
}
