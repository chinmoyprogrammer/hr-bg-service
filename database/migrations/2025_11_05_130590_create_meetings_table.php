<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->date('date')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->unsignedTinyInteger('type')->nullable();
            $table->string('site_location')->nullable();
            $table->json('target_audiances')->nullable();
            $table->unsignedTinyInteger('invitation_via')->nullable();
            $table->unsignedBigInteger('followup_meeting_id')->nullable();
            $table->foreign('followup_meeting_id')->references('id')->on('meetings');
            $table->enum('priority', ['high', 'low', 'medium', 'casual'])->nullable();
            $table->longText('meeting_minutes')->nullable();
            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->string('child_data_identifier_key_outgoing', 255)->nullable();
            $table->boolean('is_draft')->default(false);
            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();
            $table->foreign('created_user_id')->references('id')->on('users');
            $table->foreign('updated_user_id')->references('id')->on('users');
            $table->foreign('deleted_by')->references('id')->on('users');
            $table->string('attachment', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meetings');
    }
};
