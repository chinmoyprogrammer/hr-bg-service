<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('media_uploads', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('media_server_id');
            $table->unsignedInteger('company_id');
            $table->string('title', 255);
            $table->string('file_original_name', 255);
            $table->string('file_name', 255);
            $table->integer('file_size');
            $table->string('extension', 255);
            $table->enum('type', ['image','file','etc'])->default('image');
            $table->string('external_link', 255)->nullable();

            $table->dateTime('created_at');
            $table->dateTime('updated_at')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->unsignedInteger('created_by');
            $table->unsignedInteger('updated_by')->nullable();
            $table->unsignedInteger('deleted_by')->nullable();

            $table->string('description', 255)->nullable();
            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->dateTime('child_data_identifier_key_outgoing')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_uploads');
    }
};