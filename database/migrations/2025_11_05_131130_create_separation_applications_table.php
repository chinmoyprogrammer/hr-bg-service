<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('separation_applications', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('request_number', 100);

            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('separation_type_id');
            $table->date('application_date');
            $table->date('effective_date');
            $table->text('reason');
            $table->enum('status', ['Draft', 'Pending Approval', 'Approved', 'Rejected', 'Clarification Needed', 'Completed'])->default('Draft');
            $table->timestamp('approval_date')->nullable();
            $table->tinyInteger('employee_status_updated')->default(0);

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();
            $table->unsignedBigInteger('deleted_user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable();
            $table->tinyInteger('deleted_by')->default(0);
            $table->timestamp('deleted_at')->nullable();
            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->string('child_data_identifier_key_outgoing', 255)->nullable();

            $table->foreign('separation_type_id')->references('id')->on('separation_types');
            $table->foreign('created_user_id')->references('id')->on('users');
            $table->foreign('updated_user_id')->references('id')->on('users');
            $table->foreign('deleted_user_id')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('separation_applications');
    }
};