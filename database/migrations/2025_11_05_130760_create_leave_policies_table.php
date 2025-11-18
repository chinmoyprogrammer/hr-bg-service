<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('leave_policies', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('policy_title', 255);
            $table->integer('leave_balance')->nullable();
            $table->integer('medical_doc_required_after_days')->nullable();
            $table->date('effective_month');
            $table->boolean('status')->default(true);

            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->dateTime('child_data_identifier_key_outgoing')->nullable();

            $table->foreign('deleted_by')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_policies');
    }
};