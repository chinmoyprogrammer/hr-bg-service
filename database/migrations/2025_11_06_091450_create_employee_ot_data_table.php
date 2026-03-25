<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_ot_data', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('employee_ot_policy_id');
            $table->unsignedBigInteger('employee_user_id');
            $table->decimal('ot_rate', 22, 2);
            $table->decimal('ot_hours', 10, 2); // 1 hour 45 min => 1.75
            $table->decimal('ot_multiplier', 5, 2);
            $table->decimal('ot_amount', 22, 2);
            $table->boolean('is_paid')->default(false);
            $table->dateTime('ot_payment_date')->nullable();
            $table->date('ot_date');
            $table->unsignedTinyInteger('is_manual')->nullable();

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->string('child_data_identifier_key_outgoing', 255)->nullable();

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->index(['employee_user_id', 'ot_date']);

            $table->foreign('employee_ot_policy_id')->references('id')->on('employee_ot_policies')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('employee_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('created_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('updated_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('deleted_by')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_ot_data');
    }
};