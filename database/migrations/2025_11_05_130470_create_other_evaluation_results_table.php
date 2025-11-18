<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('other_evaluation_results', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('evaluator_user_id')->nullable();
            $table->unsignedBigInteger('evaluatee_list_id');
            $table->unsignedInteger('other_evaluation_type_id');
            $table->unsignedInteger('user_obtained_value');
            $table->unsignedInteger('standard_value');
            $table->double('ptc', 5, 2);
            $table->text('comments')->nullable();
            $table->boolean('status')->default(true);

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->foreign('evaluator_user_id')->references('id')->on('users');
            $table->foreign('evaluatee_list_id')->references('id')->on('evaluatee_list');
            $table->foreign('other_evaluation_type_id')->references('id')->on('other_evaluation_types');
            $table->foreign('created_user_id')->references('id')->on('users');
            $table->foreign('updated_user_id')->references('id')->on('users');
            $table->foreign('deleted_by')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('other_evaluation_results');
    }
};