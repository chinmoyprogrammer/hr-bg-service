<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payroll_salary_sheet', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('employee_user_id');
            $table->unsignedTinyInteger('month');
            $table->unsignedSmallInteger('year');

            $table->date('from_date');
            $table->date('to_date');

            $table->decimal('total_deductable', 22, 2);
            $table->decimal('total_earning', 22, 2);
            $table->decimal('net_salary_payable', 22, 2);

            $table->decimal('bank_payable', 22, 2)->nullable();
            $table->decimal('cash_payable', 22, 2)->nullable();
            $table->decimal('mfs_payable', 22, 2)->nullable();

            $table->unsignedBigInteger('bank_id')->nullable();
            $table->unsignedBigInteger('bank_branch_id')->nullable();
            $table->string('bank_acc_no', 255)->nullable();
            $table->string('mfs_acc_no', 255)->nullable();

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->string('child_data_identifier_key_outgoing', 255)->nullable();

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->index(['employee_user_id', 'month', 'year']);

            $table->foreign('employee_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('created_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('updated_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('deleted_by')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_salary_sheet');
    }
};