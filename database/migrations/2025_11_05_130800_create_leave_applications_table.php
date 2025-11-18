<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('leave_applications', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('application_number', 50);

            $table->unsignedBigInteger('employee_user_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('department_id');
            $table->unsignedBigInteger('section_id');
            $table->unsignedBigInteger('sub_section_id');
            $table->unsignedBigInteger('designation_id');
            $table->unsignedBigInteger('leave_policy_id');

            $table->date('leave_from');
            $table->enum('leave_from_type', ['First Half','Second Half','Full Day'])->default('Full Day');
            $table->date('leave_to');
            $table->enum('leave_to_type', ['First Half','Second Half','Full Day'])->nullable();
            $table->decimal('applied_days', 5, 2)->default(0);
            $table->boolean('is_bridge_leave')->default(false);

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->string('child_data_identifier_key_outgoing', 255)->nullable();

            $table->text('reason');
            $table->text('remarks')->nullable();

            $table->boolean('is_approved')->default(false);
            $table->dateTime('approve_date')->nullable();

            $table->string('employee_imposed_approval_chain', 255)->nullable();
            $table->integer('current_approval_level')->default(1);

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable();

            $table->unsignedBigInteger('deleted_user_id')->nullable();
            $table->tinyInteger('deleted_by')->default(0);
            $table->timestamp('deleted_at')->nullable();

            $table->index('application_number');

            $table->foreign('employee_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('branch_id')->references('id')->on('branches')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('department_id')->references('id')->on('departments')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('section_id')->references('id')->on('sections')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('sub_section_id')->references('id')->on('subsections')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('designation_id')->references('id')->on('employee_designations')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('leave_policy_id')->references('id')->on('leave_policies')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('created_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('updated_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('deleted_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_applications');
    }
};