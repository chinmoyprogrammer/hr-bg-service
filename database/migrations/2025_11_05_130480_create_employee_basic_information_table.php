<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_basic_information', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('full_name', 50);
            $table->string('nickname', 50)->nullable();
            $table->string('father_name', 50);
            $table->unsignedBigInteger('father_occupation_id')->nullable();
            $table->string('mother_name', 50);
            $table->unsignedBigInteger('mother_occupation_id')->nullable();
            $table->date('date_of_birth');
            $table->string('nationality', 50);
            $table->unsignedBigInteger('religion_id');
            $table->unsignedBigInteger('gender_id');
            $table->string('nid_no', 17)->nullable();
            $table->boolean('nid_required')->default(false);
            $table->string('birth_certificate_no', 20)->nullable();
            $table->boolean('birth_certificate_required')->default(false);
            $table->string('passport_no', 20)->nullable();
            $table->string('driving_license', 20)->nullable();
            $table->string('tin_no', 20)->nullable();
            $table->enum('marital_status', ['Single', 'Married', 'Divorced', 'Widowed']);

            $table->string('child_data_identifier_key_incoming', 255)->nullable();

            $table->string('personal_primary_email')->nullable(false);
            $table->json('personal_other_email')->nullable();
            $table->json('official_email')->nullable();
            $table->boolean('pf_eligibility_status')->default(true);
            $table->boolean('is_draft')->default(false);

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->foreign('deleted_by')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_basic_information');
    }
};