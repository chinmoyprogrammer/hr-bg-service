<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->string('child_data_identifier_key_outgoing', 255)->nullable();

            $table->string('country_name', 100);
            $table->string('country_name_bn', 100)->nullable();
            $table->string('country_code', 4); // ISO-like code (e.g., BD, US)

            $table->boolean('status')->default(true);

            $table->dateTime('created_at');
            $table->dateTime('updated_at')->nullable();

            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->unique('country_code');
            $table->unique('country_name');

            $table->foreign('created_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('updated_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('deleted_by')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('countries');
    }
};