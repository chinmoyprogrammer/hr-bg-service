<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('agreement_templates', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('child_data_identifier_key_incoming', 255)->nullable();

            $table->string('title', 255);
            $table->string('short_name', 100);
            $table->enum('type', ['Employment','Compliance','Training','Freelance']);
            $table->date('effective_from');
            $table->longText('terms');
            $table->string('attachment', 255)->nullable();
            $table->boolean('is_draft')->default(false);
            $table->enum('status', ['draft','active','archived'])->default('draft');

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->nullable();

            $table->foreign('created_user_id')->references('id')->on('users');
            $table->foreign('updated_user_id')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agreement_templates');
    }
};
