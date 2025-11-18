<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('name', 100);
            $table->unsignedTinyInteger('day');
            $table->unsignedTinyInteger('month');
            $table->unsignedSmallInteger('year');
            $table->date('date');

            $table->unsignedBigInteger('holiday_type_id')->nullable();
            $table->boolean('recurring')->default(false);
            $table->string('recurring_rule', 50)->nullable();
            $table->string('description', 500)->nullable();
            $table->enum('status', ['Draft','Active','Archived'])->default('Draft');

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->string('child_data_identifier_key_outgoing', 255)->nullable();

            $table->foreign('holiday_type_id')->references('id')->on('holiday_types');
            $table->foreign('created_user_id')->references('id')->on('users');
            $table->foreign('updated_user_id')->references('id')->on('users');
            $table->foreign('deleted_by')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};