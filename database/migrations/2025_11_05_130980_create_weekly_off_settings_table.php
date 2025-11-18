<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('weekly_off_settings', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->enum('entity_type', ['global','branch','department','section','employee','grade','designation'])->default('global');
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->unsignedBigInteger('shift_id')->nullable();

            // MySQL SET is not available in Laravel schema builder; store as comma-separated string
            $table->string('off_days', 30)->default('Sat,Sun');
            $table->string('alternate_rule', 100)->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->dateTime('child_data_identifier_key_outgoing')->nullable();

            $table->index(['entity_type','entity_id']);

            $table->foreign('shift_id')->references('id')->on('shifts');
            $table->foreign('created_user_id')->references('id')->on('users');
            $table->foreign('updated_user_id')->references('id')->on('users');
            $table->foreign('deleted_by')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_off_settings');
    }
};