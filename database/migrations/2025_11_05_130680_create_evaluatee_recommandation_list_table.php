<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('evaluatee_recommandation_list', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('evaluator_user_id')->nullable();
            $table->unsignedBigInteger('evaluatee_user_id');
            $table->unsignedBigInteger('evaluatee_list_id');
            $table->unsignedBigInteger('recommandation_name_id');
            $table->unsignedBigInteger('value')->nullable();
            $table->json('recommandation_details')->nullable();
            $table->enum('recommandation_status', ['recommanded','final','declined'])->default('recommanded');
            $table->text('comments')->nullable();

            $table->unsignedInteger('increment_amount')->nullable();
            $table->decimal('increment_ptc', 6, 2)->nullable();
            $table->unsignedBigInteger('designation_id')->nullable();
            $table->unsignedBigInteger('transfer_dept')->nullable();
            $table->unsignedBigInteger('transfer_sec')->nullable();
            $table->unsignedBigInteger('transfer_sub_sec')->nullable();

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();
            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->string('child_data_identifier_key_outgoing', 255)->nullable();

            $table->foreign('evaluator_user_id')->references('id')->on('users');
            $table->foreign('evaluatee_list_id')->references('id')->on('evaluatee_list');
            $table->foreign('recommandation_name_id')->references('id')->on('recommandation_name_list');
            $table->foreign('value')->references('id')->on('recommandation_name_list');
            $table->foreign('designation_id')->references('id')->on('employee_designations');
            $table->foreign('transfer_dept')->references('id')->on('departments');
            $table->foreign('transfer_sec')->references('id')->on('sections');
            $table->foreign('transfer_sub_sec')->references('id')->on('subsections');
            $table->foreign('created_user_id')->references('id')->on('users');
            $table->foreign('updated_user_id')->references('id')->on('users');
            $table->foreign('deleted_by')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluatee_recommandation_list');
    }
};
