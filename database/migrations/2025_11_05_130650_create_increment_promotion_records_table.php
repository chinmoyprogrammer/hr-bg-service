<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('increment_promotion_records', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('batch_id');
            $table->unsignedBigInteger('employee_user_id');

            $table->decimal('current_salary', 10, 2)->default(0);
            $table->decimal('kpi_score', 5, 2)->nullable();
            $table->decimal('suggested_increment_amt', 10, 2)->nullable();
            $table->decimal('suggested_increment_pct', 5, 2)->nullable();
            $table->unsignedBigInteger('new_grade_id')->nullable();
            $table->unsignedBigInteger('new_designation_id')->nullable();
            $table->decimal('final_increment_amt', 10, 2)->nullable();
            $table->decimal('final_increment_pct', 5, 2)->nullable();
            $table->unsignedBigInteger('final_grade_id')->nullable();
            $table->unsignedBigInteger('final_designation_id')->nullable();
            $table->enum('status', ['draft','approved','rejected','pending'])->default('draft');
            $table->text('remarks')->nullable();

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();
            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->dateTime('child_data_identifier_key_outgoing')->nullable();

            $table->foreign('batch_id')->references('id')->on('increment_promotion_batches');
            $table->foreign('employee_user_id')->references('id')->on('users');
            $table->foreign('new_grade_id')->references('id')->on('salary_grades');
            $table->foreign('new_designation_id')->references('id')->on('employee_designations');
            $table->foreign('final_grade_id')->references('id')->on('salary_grades');
            $table->foreign('final_designation_id')->references('id')->on('employee_designations');
            $table->foreign('created_user_id')->references('id')->on('users');
            $table->foreign('updated_user_id')->references('id')->on('users');
            $table->foreign('deleted_by')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('increment_promotion_records');
    }
};