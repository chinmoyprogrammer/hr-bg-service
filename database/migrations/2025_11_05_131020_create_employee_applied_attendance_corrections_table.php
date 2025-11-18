<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_applied_attendance_corrections', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('employee_user_id')->nullable();
            $table->date('attendance_date');
            $table->enum('correction_type', ['missed_in','missed_out','incorrect_hours','other','half day','OT','Consideration'])->default('other');

            $table->time('original_in')->nullable();
            $table->time('original_out')->nullable();
            $table->time('requested_in')->nullable();
            $table->time('requested_out')->nullable();

            $table->text('reason');
            $table->enum('status', ['pending','approved','rejected','modified','withdrawn'])->default('pending');
            $table->dateTime('submitted_at')->useCurrent();
            $table->enum('submitted_from', ['web','mobile','email'])->default('web');
            $table->tinyInteger('has_attachment')->default(0);

            $table->unsignedBigInteger('last_action_by')->nullable();
            $table->dateTime('last_action_at')->nullable();

            $table->unsignedBigInteger('related_attendance_id')->nullable();

            $table->string('employee_imposed_approval_chain', 255)->nullable();
            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->string('child_data_identifier_key_outgoing', 255)->nullable();

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable();
            $table->tinyInteger('deleted_by')->default(0);
            $table->unsignedBigInteger('deleted_user_id')->nullable();
            $table->timestamp('deleted_at')->nullable();

            $table->foreign('employee_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('last_action_by')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
            // Short explicit FK name to avoid MySQL's 64-character identifier limit
            $table->foreign('related_attendance_id', 'fk_emp_corr_att_rel')
                  ->references('id')->on('employee_attendance')
                  ->onUpdate('cascade')->onDelete('set null');
            $table->foreign('created_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('updated_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('deleted_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_applied_attendance_corrections');
    }
};