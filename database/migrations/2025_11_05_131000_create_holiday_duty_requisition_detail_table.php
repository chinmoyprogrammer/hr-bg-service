<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('holiday_duty_requisition_detail', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('holiday_duty_requisition_id');
            $table->unsignedBigInteger('employee_user_id');
            $table->date('duty_date');
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->enum('status', ['Pending','Approved','Rejected'])->default('Pending');
            $table->string('employee_imposed_approval_chain', 255)->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->text('remarks')->nullable();

            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->string('child_data_identifier_key_outgoing', 255)->nullable();

            // Use a shorter explicit FK name to avoid MySQL 64-char limit
            $table->foreign('holiday_duty_requisition_id', 'fk_hol_req_det_req')
                  ->references('id')->on('holiday_duty_requisition')
                  ->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('employee_user_id')->references('id')->on('users');
            $table->foreign('shift_id')->references('id')->on('shifts');
            $table->foreign('deleted_by')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holiday_duty_requisition_detail');
    }
};