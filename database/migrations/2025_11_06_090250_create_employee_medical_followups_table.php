<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_medical_followups', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('employee_medical_record_id');
            $table->unsignedTinyInteger('treatment_status'); // 1=initial,2=Ongoing,3=Completed
            $table->date('next_followup_date')->nullable();
            $table->date('expected_retuen_date')->nullable();
            $table->text('remarks')->nullable();

            $table->unsignedBigInteger('child_data_identifier_key_incoming')->nullable();
            $table->dateTime('child_data_identifier_key_outgoing')->nullable();

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();

            $table->dateTime('created_at');
            $table->dateTime('updated_at')->nullable();

            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->foreign('employee_medical_record_id')->references('id')->on('employee_medical_records')->onUpdate('cascade')->onDelete('cascade');

            $table->foreign('created_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('updated_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('deleted_by')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_medical_followups');
    }
};