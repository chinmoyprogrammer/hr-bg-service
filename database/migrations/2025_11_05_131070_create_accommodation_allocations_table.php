<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('accommodation_allocations', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('request_id');
            $table->unsignedBigInteger('unit_id'); // FK to accommodation_units.id omitted until table exists
            $table->unsignedBigInteger('employee_user_id');
            $table->date('allocation_date');
            $table->date('vacate_date')->nullable();

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->string('child_data_identifier_key_outgoing', 255)->nullable();

            $table->enum('status', ['Active', 'Vacated', 'Terminated'])->default('Active');

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->foreign('request_id')->references('id')->on('accommodation_requests');
            $table->foreign('employee_user_id')->references('id')->on('users');
            $table->foreign('created_user_id')->references('id')->on('users');
            $table->foreign('updated_user_id')->references('id')->on('users');
            $table->foreign('deleted_by')->references('id')->on('users');
            // unit_id FK intentionally omitted until accommodation_units table exists
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accommodation_allocations');
    }
};