<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_imposed_approval_chain', function (Blueprint $table) {
            $table->increments('id'); // int(11)
            $table->string('uuid', 255);
            $table->string('approval_subject', 255)->nullable();
            // Use BIGINT to match employee_approval_types.id
            $table->unsignedBigInteger('employee_approval_type_id');
            $table->unsignedTinyInteger('acceptance_status')->default(2); // 0=denied,1=accepted,2=default/not yet responded
            $table->unsignedSmallInteger('approver_sequence');
            $table->boolean('is_auxiliary')->default(false);
            $table->dateTime('occourance_date_time')->nullable();
            $table->dateTime('seen_date')->nullable();
            $table->string('remarks', 150)->nullable();
            $table->dateTime('created_at');

            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->string('child_data_identifier_key_outgoing', 255)->nullable();

            // Provide a shorter explicit FK name to avoid MySQL 64-char limit
            $table->foreign('employee_approval_type_id', 'fk_emp_imp_chain_type')
                  ->references('id')->on('employee_approval_types')
                  ->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('deleted_by')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_imposed_approval_chain');
    }
};