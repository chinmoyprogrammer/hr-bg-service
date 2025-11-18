<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('salary_grades', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();

            $table->string('grade_name', 50);
            $table->string('grade_name_bn', 50)->nullable();
            $table->string('grade_short_name', 15)->nullable();
            $table->decimal('min_salary', 12, 2)->default(0);
            $table->decimal('max_salary', 12, 2)->default(0);
            $table->date('effective_date')->nullable();
            $table->boolean('status')->default(true);

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable();

            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();
            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->dateTime('child_data_identifier_key_outgoing')->nullable();

            $table->unique(['grade_name']);
            $table->unique(['grade_short_name']);

            $table->foreign('created_user_id')->references('id')->on('users');
            $table->foreign('updated_user_id')->references('id')->on('users');
            $table->foreign('deleted_by')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_grades');
    }
};