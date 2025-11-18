<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('evaluation_template_questions', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('evaluation_template_id');
            $table->unsignedBigInteger('question_id');
            $table->boolean('status')->default(true);
            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->string('child_data_identifier_key_outgoing')->nullable();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            // Indexes (short names to avoid 64-char limit)
            $table->index(['evaluation_template_id'], 'idx_evaltempq_template');
            $table->index(['question_id'], 'idx_evaltempq_question');
            $table->index(['status'], 'idx_evaltempq_status');

            // Foreign keys (short names)
            $table->foreign('evaluation_template_id', 'fk_evaltempq_template')
                  ->references('id')
                  ->on('evaluation_templates')
                  ->onUpdate('cascade')
                  ->onDelete('cascade');

            $table->foreign('question_id', 'fk_evaltempq_question')
                  ->references('id')
                  ->on('evaluation_questions')
                  ->onUpdate('cascade')
                  ->onDelete('cascade');

            $table->foreign('created_user_id', 'fk_evaltempq_created_by')
                  ->references('id')
                  ->on('users')
                  ->onUpdate('cascade')
                  ->onDelete('restrict');

            $table->foreign('updated_user_id', 'fk_evaltempq_updated_by')
                  ->references('id')
                  ->on('users')
                  ->onUpdate('cascade')
                  ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_template_questions');
    }
};
