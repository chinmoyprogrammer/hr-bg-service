<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_service_events', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('employee_id');
            $table->integer('department_id')->nullable();
            $table->integer('section_id')->nullable();
            $table->integer('sub_section_id')->nullable();
            $table->integer('designation_id')->nullable();
            $table->integer('salary_grade_id')->nullable();
            $table->integer('designation_level_id')->nullable();
            $table->text('gross_salary')->nullable();
            $table->text('comments')->nullable();
            $table->string('event_category', 50);
            $table->string('event_type', 100)->nullable();
            $table->date('effective_date')->nullable();
            $table->timestamp('recorded_date')->useCurrent();
            $table->enum('status', ['Pending','Approved','Rejected','Cancelled'])->default('Pending');
            $table->integer('type')->default(1);
            $table->json('approval_log')->nullable();

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->string('child_data_identifier_key_outgoing', 255)->nullable();

            $table->foreign('employee_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('created_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('updated_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('deleted_by')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_service_events');
    }
};