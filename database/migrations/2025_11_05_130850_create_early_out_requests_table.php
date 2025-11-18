<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('early_out_requests', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('employee_user_id')->nullable();
            $table->date('out_date');
            $table->time('out_time');
            $table->enum('purpose', ['Official', 'Personal']);
            $table->string('workplace_of_job', 500)->nullable();
            $table->string('mobile_no', 20);
            $table->boolean('gate_pass_printed')->default(false);

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();
            $table->unsignedBigInteger('deleted_user_id')->nullable();

            $table->boolean('is_approved')->default(false);
            $table->dateTime('approve_date')->nullable();

            $table->boolean('is_deductable')->default(false);
            $table->boolean('is_considered')->default(false);
            $table->dateTime('consider_date')->nullable();

            $table->string('employee_imposed_approval_chain', 255)->nullable();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable();
            $table->tinyInteger('deleted_by')->default(0);
            $table->timestamp('deleted_at')->nullable();

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->string('child_data_identifier_key_outgoing', 255)->nullable();

            $table->foreign('employee_user_id')->references('id')->on('users');
            $table->foreign('created_user_id')->references('id')->on('users');
            $table->foreign('updated_user_id')->references('id')->on('users');
            $table->foreign('deleted_user_id')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('early_out_requests');
    }
};