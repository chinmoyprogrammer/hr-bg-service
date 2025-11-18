<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_ot_policies', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('salary_grade_id');
            $table->string('policy_name', 255);
            $table->decimal('ot_rate', 22, 2);
            $table->decimal('multiplier', 5, 2);
            $table->decimal('minimum_ot_hours', 5, 2)->nullable();
            $table->decimal('maximum_ot_hours', 5, 2)->nullable();
            $table->unsignedInteger('shift_break_duration')->nullable(); // minutes
            $table->date('effective_date')->nullable();
            $table->boolean('special_allowance_eligibility')->nullable();

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->dateTime('child_data_identifier_key_outgoing')->nullable();

            $table->boolean('status')->default(true);

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->index(['salary_grade_id', 'effective_date'], 'idx_otpol_grade_date');

            $table->foreign('salary_grade_id', 'fk_otpol_salary_grade')
                  ->references('id')
                  ->on('salary_grades')
                  ->onUpdate('cascade')
                  ->onDelete('restrict');
            $table->foreign('created_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('updated_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('deleted_by')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_ot_policies');
    }
};