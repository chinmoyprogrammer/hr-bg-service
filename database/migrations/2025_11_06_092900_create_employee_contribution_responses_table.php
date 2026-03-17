<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_contribution_responses', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('employee_user_id');
            $table->unsignedBigInteger('employee_contribution_issue_id');
            $table->decimal('amount', 22, 2);

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->string('child_data_identifier_key_outgoing', 255)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['employee_user_id'], 'idx_empconresp_user');
            $table->index(['employee_contribution_issue_id'], 'idx_empconresp_issue');

            $table->foreign('employee_user_id', 'fk_empconresp_user')
                  ->references('id')
                  ->on('users')
                  ->onUpdate('cascade')
                  ->onDelete('restrict');
            $table->foreign('employee_contribution_issue_id', 'fk_empconresp_issue')
                  ->references('id')
                  ->on('employee_contribution_issues')
                  ->onUpdate('cascade')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_contribution_responses');
    }
};