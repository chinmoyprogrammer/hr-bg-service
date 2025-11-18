<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_medical_records', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('employee_user_id');
            $table->dateTime('sickness_date_time');
            $table->string('incident_location', 255);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('employee_medical_urgency_type_id')->nullable();
            $table->unsignedTinyInteger('sickness_type'); // 1=sickness,2=accident,3=both,4=undefined
            $table->dateTime('treatment_date')->nullable();
            $table->string('hospital_name', 255)->nullable();
            $table->string('external_doctor_name', 255)->nullable();
            $table->unsignedBigInteger('inhouse_doctor_user_id')->nullable();
            $table->decimal('treatment_expense', 22, 2)->nullable();
            $table->decimal('compensation_amount', 22, 2)->nullable();

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->dateTime('child_data_identifier_key_outgoing')->nullable();

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();

            $table->dateTime('created_at');
            $table->dateTime('updated_at')->nullable();

            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->index(['employee_user_id', 'sickness_date_time'], 'idx_medrec_emp_sick');

            $table->foreign('employee_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('inhouse_doctor_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('employee_medical_urgency_type_id', 'fk_medrec_urg_type')->references('id')->on('employee_medical_urgency_types')->onUpdate('cascade')->onDelete('set null');

            $table->foreign('created_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('updated_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('deleted_by')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_medical_records');
    }
};