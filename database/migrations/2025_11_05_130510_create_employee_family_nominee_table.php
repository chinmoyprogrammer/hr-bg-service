<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_family_nominee', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->string('child_data_identifier_key_outgoing', 255)->nullable();

            $table->string('name', 50);
            $table->enum('relationship', ['Spouse','Father','Mother','Brother','Sister','Son','Daughter','Other']);
            $table->enum('identification_type', ['NID','Birth Certificate','Driving License','Passport'])->nullable();
            $table->string('identification_no', 20)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('contact_no', 11)->nullable();
            $table->string('address', 120)->nullable();
            $table->boolean('is_nominee')->default(false);
            $table->unsignedTinyInteger('nominee_benefits')->nullable();
            $table->boolean('is_emergency_contact')->default(false);

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('created_user_id')->references('id')->on('users');
            $table->foreign('updated_user_id')->references('id')->on('users');
            $table->foreign('deleted_by')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_family_nominee');
    }
};