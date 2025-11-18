<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_trainings', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('employee_user_id');
            $table->enum('training_type', ['training', 'certification']);
            $table->string('title', 100);
            $table->text('topics_covered')->nullable();
            $table->string('institute_name', 100);
            $table->integer('duration_value')->nullable();
            $table->enum('duration_unit', ['Hour','Day','Week','Month'])->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('location', 100)->nullable();
            $table->string('course_type', 50)->nullable();
            $table->string('trainer_name', 50)->nullable();
            $table->string('link', 255)->nullable();
            $table->string('file_attachment', 255)->nullable();

            $table->boolean('is_draft')->default(false);

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->string('child_data_identifier_key_outgoing', 255)->nullable();

            $table->foreign('employee_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('created_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('updated_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('deleted_by')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_trainings');
    }
};