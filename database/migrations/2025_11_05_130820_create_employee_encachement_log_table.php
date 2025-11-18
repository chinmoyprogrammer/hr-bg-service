<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_encachement_log', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('employee_leave_balance_id');

            // Numeric fields per CSV (BIGINT 20)
            $table->unsignedBigInteger('enached_leaves');
            $table->unsignedBigInteger('encashed_balance');
            $table->unsignedBigInteger('encachement_rate');
            $table->unsignedBigInteger('encachement_amount');

            $table->unsignedBigInteger('created_user_id');
            $table->dateTime('created_at');

            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            // Sync keys per user instruction as VARCHAR(255)
            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->string('child_data_identifier_key_outgoing', 255)->nullable();

            // Foreign keys corrected to existing tables
            $table->foreign('employee_id')->references('id')->on('users');
            $table->foreign('employee_leave_balance_id')->references('id')->on('employee_leave_balances');
            $table->foreign('created_user_id')->references('id')->on('users');
            $table->foreign('deleted_by')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_encachement_log');
    }
};