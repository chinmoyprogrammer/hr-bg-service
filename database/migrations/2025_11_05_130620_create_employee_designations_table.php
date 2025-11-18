<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_designations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('title', 50);
            $table->string('title_bn', 50)->nullable();
            $table->string('short_name', 15)->nullable();
            $table->string('short_name_bn', 15)->nullable();

            $table->unsignedBigInteger('designation_level_id');
            $table->unsignedBigInteger('salary_grade_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();

            $table->string('description', 100)->nullable();
            $table->string('description_bn', 100)->nullable();
            $table->boolean('status')->default(true);

            $table->unsignedBigInteger('created_user_id');
            $table->timestamp('created_at')->useCurrent();
            $table->unsignedBigInteger('updated_user_id')->nullable();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->dateTime('child_data_identifier_key_outgoing')->nullable();

            $table->unique(['title']);
            $table->unique(['short_name']);

            $table->foreign('designation_level_id')->references('id')->on('employee_designation_levels');
            $table->foreign('salary_grade_id')->references('id')->on('salary_grades');
            $table->foreign('department_id')->references('id')->on('departments');
            $table->foreign('created_user_id')->references('id')->on('users');
            $table->foreign('updated_user_id')->references('id')->on('users');
            $table->foreign('deleted_by')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_designations');
    }
};