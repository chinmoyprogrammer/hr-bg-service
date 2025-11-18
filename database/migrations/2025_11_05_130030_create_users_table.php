<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('username', 255);
            $table->string('name', 255);
            $table->string('email', 255);

            // Will be wired via alter migration after user_types exists
            $table->unsignedBigInteger('user_type_id')->nullable(false);

            $table->boolean('status')->default(true);
            $table->date('date_of_birth')->nullable();

            // As per CSV, keep names as-is; add as integers per spec
            $table->unsignedTinyInteger('religion_id')->nullable();
            $table->unsignedTinyInteger('gender_id')->nullable(false);

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();

            $table->dateTime('created_at');
            $table->dateTime('updated_at')->nullable();

            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            // Indexes as appropriate
            $table->index('email');
            $table->index('user_type_id');
            $table->index('religion_id');
            $table->index('gender_id');

            // FKs resolved in follow-up alter migration to avoid circular dependency
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
