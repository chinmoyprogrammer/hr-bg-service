<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payroll_salary_advance_n_loans', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('employee_user_id');
            $table->unsignedTinyInteger('type')->default(1); // 1=advance,2=loan,3=pf loan
            $table->unsignedBigInteger('term_type')->nullable(); // FK to payroll_loan_terms.id
            $table->decimal('amount', 22, 2);
            $table->date('effective_date');
            $table->text('comments')->nullable();
            $table->decimal('outstanding_balance', 22, 2)->default(0);
            $table->boolean('approval_status')->default(false);
            $table->unsignedInteger('primary_number_of_installments')->default(1);
            $table->decimal('primary_installment_amount', 22, 2);
            $table->boolean('is_fully_paid')->default(false);
            $table->string('employee_imposed_approval_chain', 255)->nullable(); // optional FK to employee_imposed_approval_chain.uuid

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->string('child_data_identifier_key_outgoing', 255)->nullable();

            $table->index(['employee_user_id']);
            $table->index(['term_type']);

            $table->foreign('employee_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('term_type')->references('id')->on('payroll_loan_terms')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('created_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('updated_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('deleted_by')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_salary_advance_n_loans');
    }
};