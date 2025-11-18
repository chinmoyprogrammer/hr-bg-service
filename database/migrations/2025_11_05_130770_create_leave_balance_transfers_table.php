<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('leave_balance_transfers', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('employee_user_id');
            $table->unsignedBigInteger('leave_policy_id');
            $table->unsignedBigInteger('old_department_id');
            $table->unsignedBigInteger('new_department_id');

            $table->decimal('old_balance', 6, 2)->default(0);
            $table->decimal('new_balance', 6, 2)->default(0);
            $table->decimal('adjusted_balance', 6, 2)->default(0);

            $table->date('transfer_date');
            $table->text('calculation_formula')->nullable();
            $table->text('remarks')->nullable();

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();
            $table->unsignedBigInteger('deleted_user_id')->nullable();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable();
            $table->tinyInteger('deleted_by')->default(0);
            $table->timestamp('deleted_at')->nullable();

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->dateTime('child_data_identifier_key_outgoing')->nullable();

            $table->foreign('employee_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('leave_policy_id')->references('id')->on('leave_policies')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('old_department_id')->references('id')->on('departments')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('new_department_id')->references('id')->on('departments')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('created_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('updated_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('deleted_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_balance_transfers');
    }
};