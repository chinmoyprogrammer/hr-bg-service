<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('meal_pauses', function (Blueprint $table) {
            $table->increments('id'); // int(11)

            $table->unsignedBigInteger('user_id');
            $table->date('pause_date');

            $table->string('employee_imposed_approval_chain', 255)->nullable();

            $table->unsignedBigInteger('created_user_id');
            $table->timestamp('created_at')->useCurrent();

            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->string('child_data_identifier_key_outgoing', 255)->nullable();

            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('created_user_id')->references('id')->on('users');
            $table->foreign('deleted_by')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_pauses');
    }
};