<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_pf_contribution', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('employee_pf_policy_id');
            $table->unsignedBigInteger('employee_user_id');
            $table->decimal('employee_contribution_amount', 22, 2);
            $table->decimal('employer_contribution_amount', 22, 2)->nullable();
            $table->date('effective_date');

            $table->dateTime('created_at');

            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->string('child_data_identifier_key_outgoing', 255)->nullable();

            $table->index(['employee_pf_policy_id', 'employee_user_id', 'effective_date'], 'idx_pfcontrib_policy_emp_date');

            $table->foreign('employee_pf_policy_id')->references('id')->on('employee_pf_policy')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('employee_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('deleted_by')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_pf_contribution');
    }
};