<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_agreements', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('template_id');
            $table->longText('custom_terms')->nullable();
            $table->date('effective_from');
            $table->date('expiry_date')->nullable();
            $table->tinyInteger('is_digital_acknowledgement');
            $table->tinyInteger('is_acknowledgement_mandatory');

            $table->string('child_data_identifier_key_incoming', 255)->nullable();

            $table->enum('status', ['pending','active','expired','revoked'])->default('pending');
            $table->text('remarks')->nullable();
            $table->boolean('is_draft')->default(false);

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('template_id')->references('id')->on('agreement_templates');
            $table->foreign('created_user_id')->references('id')->on('users');
            $table->foreign('updated_user_id')->references('id')->on('users');
            $table->foreign('deleted_by')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_agreements');
    }
};