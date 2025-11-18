<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_leave_balances', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('leave_head_id');
            $table->unsignedBigInteger('leave_policy_id');

            $table->decimal('forwarded', 6, 2)->default(0);
            $table->decimal('achived_this_year', 6, 2)->default(0);
            $table->decimal('used', 6, 2)->default(0);
            $table->decimal('current_balance', 6, 2)->default(0);
            $table->decimal('encashed_balance', 6, 2)->default(0);

            $table->date('valid_until')->nullable();
            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->string('child_data_identifier_key_outgoing', 255)->nullable();

            $table->unsignedSmallInteger('fiscal_year');

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable();
            $table->unsignedBigInteger('deleted_user_id')->nullable();
            $table->tinyInteger('deleted_by')->default(0);
            $table->timestamp('deleted_at')->nullable();

            $table->foreign('employee_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('leave_head_id')->references('id')->on('leave_heads')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('leave_policy_id')->references('id')->on('leave_policies')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('created_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('updated_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('deleted_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_leave_balances');
    }
};