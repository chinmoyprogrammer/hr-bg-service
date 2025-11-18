<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_contracts', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('user_id');
            $table->string('contract_title', 150);
            $table->date('contract_start_date');
            $table->date('contract_end_date');
            $table->string('contract_duration', 50);
            $table->enum('contract_type', ['Fixed Term','Project-Based','Continuous']);
            $table->longText('scope_of_work')->nullable();
            $table->longText('terms_and_conditions')->nullable();
            $table->enum('payment_mode', ['Full at Completion','Instalment','Project-Based']);
            $table->string('instalment_term', 100)->nullable();
            $table->decimal('payment_amount', 12, 2)->nullable();
            $table->date('due_date')->nullable();
            $table->decimal('contract_amount', 12, 2);

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->string('child_data_identifier_key_outgoing', 255)->nullable();

            $table->enum('status', ['Active','Expiring Soon','Expired','Terminated']);
            $table->boolean('is_draft')->default(false);

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->foreign('user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('created_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('updated_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('deleted_by')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_contracts');
    }
};