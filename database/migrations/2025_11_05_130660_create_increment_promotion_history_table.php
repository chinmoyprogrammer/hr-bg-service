<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('increment_promotion_history', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('record_id');
            $table->unsignedBigInteger('employee_user_id');

            $table->decimal('previous_salary', 10, 2)->default(0);
            $table->decimal('new_salary', 10, 2)->default(0);
            $table->unsignedBigInteger('previous_grade_id')->nullable();
            $table->unsignedBigInteger('new_grade_id')->nullable();
            $table->unsignedBigInteger('previous_designation_id')->nullable();
            $table->unsignedBigInteger('new_designation_id')->nullable();
            $table->date('effective_month');
            $table->unsignedBigInteger('approved_by_user_id');
            $table->text('remarks')->nullable();

            $table->unsignedBigInteger('created_user_id');
            $table->timestamp('created_at')->useCurrent();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();
            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->string('child_data_identifier_key_outgoing', 255)->nullable();

            $table->foreign('record_id')->references('id')->on('increment_promotion_records');
            $table->foreign('employee_user_id')->references('id')->on('users');
            $table->foreign('previous_grade_id')->references('id')->on('salary_grades');
            $table->foreign('new_grade_id')->references('id')->on('salary_grades');
            $table->foreign('previous_designation_id')->references('id')->on('employee_designations');
            $table->foreign('new_designation_id')->references('id')->on('employee_designations');
            $table->foreign('approved_by_user_id')->references('id')->on('users');
            $table->foreign('created_user_id')->references('id')->on('users');
            $table->foreign('deleted_by')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('increment_promotion_history');
    }
};