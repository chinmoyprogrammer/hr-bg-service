<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_official_information', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('department_id');
            $table->unsignedBigInteger('section_id')->nullable();
            $table->unsignedBigInteger('sub_section_id')->nullable();
            $table->unsignedBigInteger('designation_level_id');
            $table->unsignedBigInteger('designation_id');

            $table->enum('employee_type', ['Probationary', 'Permanent', 'Intern', 'Contractual', 'Observation']);
            $table->date('joining_date')->nullable();
            $table->unsignedInteger('provisioner_days')->default(0)->nullable();
            $table->date('confirmation_date')->nullable();
            $table->date('observation_start_date')->nullable();
            $table->date('observation_end_date')->nullable();
            $table->unsignedInteger('observation_days')->default(0)->nullable();
            $table->unsignedBigInteger('reporting_supervisor_user_id')->nullable();
            $table->decimal('gross_salary', 12, 2);
            $table->boolean('ait_eligible')->default(false)->nullable();
            $table->json('salary_payment_mode');
            $table->decimal('cash_pay_amount', 12, 2)->default(0)->nullable();
            $table->decimal('bank_pay_amount', 12, 2)->default(0)->nullable();
            $table->decimal('mobile_pay_amount', 12, 2)->default(0)->nullable();
            $table->unsignedBigInteger('bank_name_id')->nullable();
            $table->unsignedBigInteger('branch_name_id')->nullable();
            $table->string('bank_account_no', 30)->nullable();
            $table->string('mobile_banking_type', 30)->nullable();
            $table->string('payment_mobile_number', 11)->nullable();
            $table->text('facilities')->nullable();
            $table->boolean('is_mealable');
            $table->boolean('is_free_meal');
            $table->date('meal_effective_date')->nullable();
            $table->unsignedTinyInteger('pay_period_basis');
            $table->decimal('employee_pf_employer_contribution_balance', 12, 2)->nullable();
            $table->decimal('employee_pf_balance', 12, 2)->nullable();
            $table->unsignedBigInteger('employee_pf_policy_id')->nullable();
            $table->unsignedBigInteger('employee_ot_policy_id')->nullable();
            $table->unsignedInteger('allotted_mobile_balance')->nullable();

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->unsignedBigInteger('leave_policy_id')->nullable();
            $table->boolean('status')->default(true);
            $table->boolean('is_draft')->default(false);

            $table->unsignedBigInteger('created_user_id');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();
            $table->unsignedBigInteger('updated_user_id')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->foreign('user_id')->references('id')->on('users');
            // Short explicit name to avoid MySQL's 64-character identifier limit
            $table->foreign('reporting_supervisor_user_id', 'fk_emp_off_info_supervisor')
                  ->references('id')->on('users');
            $table->foreign('created_user_id')->references('id')->on('users');
            $table->foreign('updated_user_id')->references('id')->on('users');
            $table->foreign('deleted_by')->references('id')->on('users');
            $table->foreign('bank_name_id')->references('id')->on('bank_names');
            $table->foreign('branch_name_id')->references('id')->on('bank_branches');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_official_information');
    }
};