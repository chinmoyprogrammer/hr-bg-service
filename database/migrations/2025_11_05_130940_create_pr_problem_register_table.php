<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pr_problem_register', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('employee_user_id');
            $table->unsignedInteger('pr_problem_category_id');
            $table->unsignedInteger('pr_problem_sub_category_id')->nullable();

            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('severity')->default(1); // 1=Low,2=Medium,3=High
            $table->dateTime('occurrence_date_time');
            $table->boolean('is_resolved')->default(false);
            $table->dateTime('resolution_date_time')->nullable();
            $table->text('remarks')->nullable();

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->string('child_data_identifier_key_outgoing', 255)->nullable();

            $table->foreign('employee_user_id')->references('id')->on('users');
            $table->foreign('pr_problem_category_id')->references('id')->on('pr_problem_categories');
            $table->foreign('pr_problem_sub_category_id')->references('id')->on('pr_problem_sub_categories');
            $table->foreign('created_user_id')->references('id')->on('users');
            $table->foreign('updated_user_id')->references('id')->on('users');
            $table->foreign('deleted_by')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pr_problem_register');
    }
};