<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payroll_salary_loan_advance_n_other_installments', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('payroll_salary_advance_n_loan_id')->nullable();
            $table->string('child_data_identifier_key', 255)->nullable(); // allows linking from other modules
            $table->unsignedBigInteger('coa_id')->nullable(); // chart_of_accounts.id, optional
            $table->decimal('amount', 22, 2)->default(0);
            $table->decimal('remaining_amount', 22, 3)->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->unsignedTinyInteger('adjustment_status')->default(1); // 1=with salary,2=manual payment,3=other
            $table->boolean('is_bad_debt')->default(false);

            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();
            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->string('child_data_identifier_key_outgoing', 255)->nullable();

            $table->index(['payroll_salary_advance_n_loan_id'], 'idx_payloan_inst_loan_id');

            $table->foreign('payroll_salary_advance_n_loan_id', 'fk_payloan_inst_loan')
                  ->references('id')
                  ->on('payroll_salary_advance_n_loans')
                  ->onUpdate('cascade')
                  ->onDelete('set null');
            $table->foreign('deleted_by', 'fk_payloan_inst_deleted_by')
                  ->references('id')
                  ->on('users')
                  ->onUpdate('cascade')
                  ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_salary_loan_advance_n_other_installments');
    }
};