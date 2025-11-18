<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_attendance', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('card_no', 255)->nullable();
            $table->unsignedBigInteger('employee_user_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('section_id')->nullable();
            $table->unsignedBigInteger('shift_id')->nullable();

            $table->string('shift_start_time', 255)->nullable();
            $table->string('shift_grace_time', 255)->nullable();
            $table->string('shift_end_time', 255)->nullable();

            $table->string('date', 255)->nullable();
            $table->string('in_time', 255)->nullable();
            $table->date('out_date')->nullable();
            $table->string('out_time', 255)->nullable();

            $table->tinyInteger('status')->default(0);
            $table->tinyInteger('status_2')->nullable();
            $table->unsignedBigInteger('on_leave_status')->nullable();

            $table->tinyInteger('transfered_to_ot')->default(0);
            $table->tinyInteger('is_holiday')->default(0);
            $table->tinyInteger('is_join')->default(0);
            $table->tinyInteger('is_manual')->default(0);
            $table->tinyInteger('is_roster')->default(0);

            $table->unsignedBigInteger('roster_id');
            $table->tinyInteger('absent_bridge')->default(0);

            $table->enum('source', ['biometric','manual','import','api'])->default('manual');
            $table->tinyInteger('is_corrected')->default(0);

            $table->unsignedBigInteger('employee_applied_attendance_correction_id')->nullable();

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();
            $table->unsignedBigInteger('deleted_user_id')->nullable();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable();
            $table->tinyInteger('deleted_by')->default(0);
            $table->timestamp('deleted_at')->nullable();

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->dateTime('child_data_identifier_key_outgoing')->nullable();

            $table->foreign('employee_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('department_id')->references('id')->on('departments')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('section_id')->references('id')->on('sections')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('shift_id')->references('id')->on('shifts')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('on_leave_status')->references('id')->on('leave_heads')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('roster_id')->references('id')->on('rosters')->onUpdate('cascade')->onDelete('restrict');
            // Defer FK to a later migration to avoid circular dependency and long auto-name
            // FK will be added with a shorter explicit name after both tables exist
            // $table->foreign('employee_applied_attendance_correction_id')->references('id')->on('employee_applied_attendance_corrections')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('created_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('updated_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('deleted_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_attendance');
    }
};