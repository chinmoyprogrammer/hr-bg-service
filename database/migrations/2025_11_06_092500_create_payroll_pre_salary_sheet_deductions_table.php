<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payroll_pre_salary_sheet_deductions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('employee_user_id');
            $table->decimal('amount', 22, 2);
            $table->unsignedTinyInteger('type'); // 1=penalty

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->dateTime('child_data_identifier_key_outgoing')->nullable();

            $table->timestamp('created_at')->useCurrent();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->index(['employee_user_id']);
            $table->foreign('employee_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('deleted_by')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_pre_salary_sheet_deductions');
    }
};