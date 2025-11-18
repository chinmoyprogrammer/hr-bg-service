<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('accommodation_requests', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('employee_user_id');
            $table->enum('requested_unit_type', ['SingleRoom', 'FamilyApartment', 'SharedQuarters']);
            $table->date('required_from_date');
            $table->text('reason')->nullable();
            $table->enum('status', ['Pending', 'Approved', 'Rejected', 'Cancelled'])->default('Pending');

            // nullable per CSV; references accommodation_units.id but unit table is not yet defined
            $table->unsignedBigInteger('accomodation_unit_id')->nullable();
            $table->date('assigned_date')->nullable();
            $table->date('expected_vacating_date')->nullable();
            $table->boolean('is_approved')->default(false);

            $table->string('employee_imposed_approval_chain', 255)->nullable();

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->string('child_data_identifier_key_outgoing', 255)->nullable();

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->foreign('employee_user_id')->references('id')->on('users');
            $table->foreign('created_user_id')->references('id')->on('users');
            $table->foreign('updated_user_id')->references('id')->on('users');
            $table->foreign('deleted_by')->references('id')->on('users');
            // accomodation_unit_id FK intentionally omitted until accommodation_units table exists
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accommodation_requests');
    }
};