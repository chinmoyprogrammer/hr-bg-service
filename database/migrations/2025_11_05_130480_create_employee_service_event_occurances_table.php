<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_service_event_occurances', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('employee_user_id');
            $table->unsignedBigInteger('employee_service_event_id');
            $table->json('detail_value');
            $table->dateTime('event_date');
            $table->unsignedBigInteger('evaluatee_list_id')->nullable();

            $table->timestamp('created_at')->useCurrent();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->dateTime('child_data_identifier_key_outgoing')->nullable();

            // Shorter, explicit foreign key name to avoid MySQL 64-char limit
            $table->foreign('employee_service_event_id', 'fk_emp_serv_occ_event')
                  ->references('id')->on('employee_service_events')
                  ->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('employee_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('created_by')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('evaluatee_list_id')->references('id')->on('evaluatee_list')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('deleted_by')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_service_event_occurances');
    }
};