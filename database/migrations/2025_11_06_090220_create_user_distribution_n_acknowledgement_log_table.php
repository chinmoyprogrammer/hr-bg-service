<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_distribution_n_acknowledgement_log', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('child_data_identifier_key_incoming', 255);
            $table->string('child_data_identifier_key_outgoing', 255)->nullable();

            $table->dateTime('read_at')->nullable();
            $table->unsignedBigInteger('employee_user_id')->nullable();

            $table->unsignedTinyInteger('acknowledgement_answer')->nullable(); // 1=yes,2=no,3=other
            $table->dateTime('acknowledgement_answer_at')->nullable();

            $table->json('user_device_n_location_info')->nullable();

            $table->unsignedBigInteger('created_by');
            $table->dateTime('created_at');

            // Use short explicit index names to avoid MySQL 64-char identifier limit
            $table->index('child_data_identifier_key_incoming', 'idx_udl_child_in');
            $table->index(['employee_user_id', 'acknowledgement_answer'], 'idx_udl_emp_ack');

            $table->foreign('employee_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('created_by')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_distribution_n_acknowledgement_log');
    }
};