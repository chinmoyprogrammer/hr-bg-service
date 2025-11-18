<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('rosters', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('title', 150);
            $table->enum('roster_type', ['general','special'])->default('general');
            $table->unsignedBigInteger('shift_id');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->text('description')->nullable();

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();
            $table->unsignedBigInteger('deleted_user_id')->nullable();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable();
            $table->tinyInteger('deleted_by')->default(0);
            $table->timestamp('deleted_at')->nullable();

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->dateTime('child_data_identifier_key_outgoing')->nullable();

            $table->foreign('shift_id')->references('id')->on('shifts');
            $table->foreign('created_user_id')->references('id')->on('users');
            $table->foreign('updated_user_id')->references('id')->on('users');
            $table->foreign('deleted_user_id')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rosters');
    }
};