<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('evaluatee_list', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('evaluator_user_id');
            $table->unsignedBigInteger('evaluator_employee_approval_chain_id');
            $table->unsignedBigInteger('evaluatee_user_id');
            $table->unsignedBigInteger('evaluation_template_id');

            $table->enum('overall_recommendation', ['Promote', 'Extend', 'Reject', 'Hold', 'Recommend', 'transfer', 'NA'])->nullable();
            $table->text('comments')->nullable();
            $table->decimal('total_marks', 6, 2)->nullable();
            $table->decimal('obtained_marks', 6, 2)->nullable();
            $table->decimal('pass_mark', 6, 2)->nullable();
            $table->date('effective_date')->nullable();
            $table->date('valid_till')->nullable();
            $table->enum('status', ['ongoing', 'suspend', 'completed'])->default('ongoing');
            $table->boolean('is_draft')->default(false);

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->string('child_data_identifier_key_outgoing', 255)->nullable();

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
        Schema::dropIfExists('evaluatee_list');
    }
};
