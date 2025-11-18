<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('recommandation_name_list', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('type')->nullable();
            $table->string('recom_name', 255);
            $table->unsignedBigInteger('for_employee_type')->nullable();
            $table->enum('status', ['draft','approved','rejected','pending'])->default('draft');
            $table->text('remarks')->nullable();

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();
            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->dateTime('child_data_identifier_key_outgoing')->nullable();

            $table->foreign('for_employee_type')->references('id')->on('employee_types');
            $table->foreign('created_user_id')->references('id')->on('users');
            $table->foreign('updated_user_id')->references('id')->on('users');
            $table->foreign('deleted_by')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommandation_name_list');
    }
};