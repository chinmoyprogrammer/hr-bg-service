<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_ta_da', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('visit_requests_id')->nullable();
            $table->unsignedBigInteger('employee_user_id');
            $table->unsignedTinyInteger('travel_type'); // 1=local,2=Domestic,3=International
            $table->text('purpose')->nullable();
            $table->string('location_from', 255)->nullable();
            $table->string('location_to', 255)->nullable();
            $table->dateTime('travel_start_date_time')->nullable();
            $table->dateTime('travel_end_date_time')->nullable();

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->string('child_data_identifier_key_outgoing', 255)->nullable();

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->index(['employee_user_id', 'travel_type']);

            $table->foreign('visit_requests_id')->references('id')->on('visit_requests')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('employee_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('created_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('updated_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('deleted_by')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_ta_da');
    }
};