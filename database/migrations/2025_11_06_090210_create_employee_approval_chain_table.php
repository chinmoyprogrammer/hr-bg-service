<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_approval_chain', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('employee_user_id');
            $table->unsignedBigInteger('approver_user_id');

            $table->unsignedBigInteger('employee_approval_type_id');
            $table->unsignedTinyInteger('approver_sequence');
            $table->boolean('is_auxiliary')->default(false);

            $table->unsignedBigInteger('approver_type');

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();

            $table->dateTime('created_at');
            $table->dateTime('updated_at')->nullable();

            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->string('child_data_identifier_key_outgoing', 255)->nullable();

            $table->index(['employee_user_id', 'approver_sequence']);

            $table->foreign('employee_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('approver_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('employee_approval_type_id')->references('id')->on('employee_approval_types')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('approver_type')->references('id')->on('employee_approval_types')->onUpdate('cascade')->onDelete('restrict');

            $table->foreign('created_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('updated_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('deleted_by')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_approval_chain');
    }
};