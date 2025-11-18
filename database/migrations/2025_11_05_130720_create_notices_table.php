<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('notices', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->string('title', 255);
            $table->text('content');
            $table->json('target_audiances');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->enum('status', ['Draft', 'Published', 'Expired', 'Cancelled'])->default('Draft');
            $table->string('attachment', 255)->nullable();
            $table->enum('sent_status', ['Pending', 'Sent', 'Failed'])->default('Pending');

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable();
            $table->tinyInteger('deleted_by')->default(0);
            $table->timestamp('deleted_at')->nullable();

            $table->foreign('created_user_id')->references('id')->on('users');
            $table->foreign('updated_user_id')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notices');
    }
};