<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('phone_numbers', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();

            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('user_type_id');

            $table->string('country_code', 10);
            $table->string('phone_number', 20);

            $table->boolean('is_primary')->default(false);
            $table->boolean('is_nominee')->default(false);
            $table->boolean('is_whatsapp')->default(false);
            $table->boolean('is_emergency')->default(false);

            $table->enum('contact_type', ['personal', 'official'])->default('personal');

            $table->dateTime('created_at');
            $table->dateTime('updated_at')->nullable();

            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            // Indexes per CSV (no unique on phone_number)
            $table->index('phone_number');

            // Foreign keys
            $table->foreign('country_code')->references('country_code')->on('countries')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('user_type_id')->references('id')->on('user_types')->onUpdate('cascade')->onDelete('restrict');

            $table->foreign('created_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('updated_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('deleted_by')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_numbers');
    }
};