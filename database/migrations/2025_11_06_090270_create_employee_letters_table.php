<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_letters', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('document_number', 100);
            $table->unsignedBigInteger('letter_type_id');
            $table->unsignedBigInteger('template_id');
            $table->unsignedBigInteger('employee_id');
            $table->string('subject', 500);
            $table->longText('letter_content');
            $table->date('effective_date');
            $table->date('issue_date')->nullable();
            $table->enum('status', ['Draft', 'Pending Approval', 'Approved', 'Rejected', 'Issued', 'Withdrawn', 'Superseded'])->default('Draft');
            $table->boolean('is_confidential')->default(false);
            $table->boolean('is_approved')->default(false);
            $table->dateTime('approve_date')->nullable();

            // Using UUID string for imposed approval chain reference per CSV
            $table->string('employee_imposed_approval_chain', 36)->nullable();

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->dateTime('child_data_identifier_key_outgoing')->nullable();

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();

            $table->dateTime('created_at');
            $table->dateTime('updated_at')->nullable();

            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->index('document_number');
            $table->index(['employee_id', 'status']);

            $table->foreign('letter_type_id')->references('id')->on('letter_types')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('template_id')->references('id')->on('letter_templates')->onUpdate('cascade')->onDelete('restrict');
            // Correct FK: employees are represented by users
            $table->foreign('employee_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');

            $table->foreign('created_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('updated_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('deleted_by')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_letters');
    }
};