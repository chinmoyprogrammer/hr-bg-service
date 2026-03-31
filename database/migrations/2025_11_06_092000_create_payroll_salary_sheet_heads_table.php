<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payroll_salary_sheet_heads', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('salary_sheet_id');
            $table->unsignedBigInteger('head_id');
            $table->decimal('value', 22, 2);

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->string('child_data_identifier_key_outgoing', 255)->nullable();

            $table->index(['salary_sheet_id']);
            $table->index(['head_id']);

            $table->foreign('salary_sheet_id')->references('id')->on('payroll_salary_sheet')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('head_id')->references('id')->on('payroll_salary_heads')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('created_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('updated_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('deleted_by')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_salary_sheet_heads');
    }
};