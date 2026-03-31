<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payroll_salary_heads', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('head_name', 255);
            $table->boolean('status')->default(true);

            $table->unsignedBigInteger('head_category')->nullable();
            $table->unsignedTinyInteger('type'); // 1=earning,2=deduction
            $table->boolean('is_auto_generated')->default(false);
            $table->boolean('is_amount')->default(true);

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->string('child_data_identifier_key_outgoing', 255)->nullable();

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->index(['head_category', 'type']);

            $table->foreign('head_category')->references('id')->on('payroll_salary_head_category')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('created_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('updated_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('deleted_by')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_salary_heads');
    }
};