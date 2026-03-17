<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_pf_policy', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('policy_name', 255);
            $table->decimal('amount_ptc', 5, 2);
            $table->unsignedInteger('max_deduction_amount');
            $table->unsignedSmallInteger('maturity_age_years');
            $table->date('effective_date');
            $table->unsignedSmallInteger('profit_enroll'); // months after which PF deduction starts
            $table->unsignedTinyInteger('contributed_by'); // 1=Employee,2=Both
            $table->decimal('pf_loan_interest_rate', 5, 2);
            $table->unsignedSmallInteger('deduction_age'); // max age till PF deducted
            $table->unsignedSmallInteger('deduction_time_limit'); // max years PF will be deducted
            $table->text('details')->nullable();

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->string('child_data_identifier_key_outgoing', 255)->nullable();

            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->index(['effective_date']);

            $table->foreign('deleted_by')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_pf_policy');
    }
};