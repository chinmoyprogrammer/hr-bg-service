<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_increment_promotion_batches', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('batch_id');

            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('section_id')->nullable();
            $table->unsignedBigInteger('sub_section_id')->nullable();
            $table->unsignedBigInteger('designation_id')->nullable();
            $table->unsignedBigInteger('grade_id')->nullable();

            $table->decimal('min_kpi_score', 5, 2)->nullable();
            $table->date('effective_month');
            $table->enum('status', ['draft','submitted','completed'])->default('draft');
            $table->text('remarks')->nullable();

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();
            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->dateTime('child_data_identifier_key_outgoing')->nullable();

            $table->foreign('batch_id')->references('id')->on('increment_promotion_batches');
            $table->foreign('branch_id')->references('id')->on('branches');
            $table->foreign('department_id')->references('id')->on('departments');
            $table->foreign('section_id')->references('id')->on('sections');
            $table->foreign('sub_section_id')->references('id')->on('subsections');
            $table->foreign('designation_id')->references('id')->on('employee_designations');
            $table->foreign('grade_id')->references('id')->on('salary_grades');
            $table->foreign('created_user_id')->references('id')->on('users');
            $table->foreign('updated_user_id')->references('id')->on('users');
            $table->foreign('deleted_by')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_increment_promotion_batches');
    }
};