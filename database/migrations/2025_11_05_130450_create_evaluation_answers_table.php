<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('evaluation_answers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('evaluatee_list_id');
            $table->unsignedBigInteger('question_id');
            $table->decimal('marks', 6, 2)->nullable();
            $table->text('comments')->nullable();

            $table->unsignedBigInteger('evaluator_user_id');
            $table->unsignedBigInteger('evaluatee_user_id');
            $table->unsignedBigInteger('evaluation_template_id');

            $table->boolean('is_draft')->default(false);
            $table->enum('status', ['Answered', 'Unasnwered'])->default('Unasnwered');

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->dateTime('child_data_identifier_key_outgoing')->nullable();

            $table->foreign('evaluatee_list_id')->references('id')->on('evaluatee_list');
            $table->foreign('question_id')->references('id')->on('evaluation_questions');
            $table->foreign('evaluator_user_id')->references('id')->on('users');
            $table->foreign('evaluatee_user_id')->references('id')->on('users');
            $table->foreign('evaluation_template_id')->references('id')->on('evaluation_templates');
            $table->foreign('created_user_id')->references('id')->on('users');
            $table->foreign('updated_user_id')->references('id')->on('users');
            $table->foreign('deleted_by')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_answers');
    }
};