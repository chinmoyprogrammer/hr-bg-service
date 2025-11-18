<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('evaluation_templates', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('template_name', 150);
            $table->text('comment')->nullable();
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->decimal('pass_mark', 5, 2)->default(0);
            $table->date('effective_from');
            $table->date('valid_till')->nullable();

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->dateTime('child_data_identifier_key_outgoing')->nullable();

            $table->foreign('created_user_id')->references('id')->on('users');
            $table->foreign('updated_user_id')->references('id')->on('users');
            $table->foreign('deleted_by')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_templates');
    }
};