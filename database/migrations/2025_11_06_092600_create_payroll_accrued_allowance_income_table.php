<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payroll_accrued_allowance_income', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('employee_user_id');
            $table->decimal('amount', 22, 2);
            $table->unsignedTinyInteger('type'); // 1=leave cash,2=Special Allowance,3=Incentive,4=Daily Allowance,5=OT,etc
            $table->unsignedTinyInteger('month')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->boolean('is_paid')->default(false);
            $table->dateTime('payment_date')->nullable();

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->string('child_data_identifier_key_outgoing', 255)->nullable();

            $table->unsignedBigInteger('created_user_id');
            $table->timestamp('created_at')->useCurrent();
            $table->unsignedBigInteger('updated_user_id')->nullable();
            $table->timestamp('updated_at')->useCurrent()->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->index(['employee_user_id','month','year'], 'idx_payacc_emp_m_y');

            $table->foreign('employee_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('created_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('updated_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('deleted_by')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_accrued_allowance_income');
    }
};