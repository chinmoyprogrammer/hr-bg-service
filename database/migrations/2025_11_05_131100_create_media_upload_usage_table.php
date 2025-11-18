<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('media_upload_usage', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('media_upload_id');
            $table->string('child_data_identifier_key_incoming', 255);

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at')->nullable();

            $table->foreign('media_upload_id')->references('id')->on('media_uploads');
            $table->foreign('created_user_id')->references('id')->on('users');
            $table->foreign('updated_user_id')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_upload_usage');
    }
};