<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pr_committee_judgement_report', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('pr_committee_id');
            $table->unsignedBigInteger('pr_id'); // cross-module; index only
            $table->unsignedBigInteger('judge_user_id');
            $table->text('judgement_details')->nullable();
            $table->unsignedTinyInteger('verdict')->nullable(); // 1=guilty,0=not guilty
            $table->decimal('penalty_amount', 22, 2)->nullable();
            $table->unsignedInteger('installment_count')->nullable();
            $table->decimal('installment_amount', 22, 2)->nullable();
            $table->boolean('is_fully_paid')->default(false);
            $table->decimal('balance', 22, 2)->nullable();
            $table->string('cs_no', 100)->nullable();

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->string('child_data_identifier_key_outgoing', 255)->nullable();

            $table->unsignedBigInteger('created_user_id');
            $table->dateTime('created_at');
            $table->unsignedBigInteger('updated_user_id')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->index(['pr_committee_id']);
            $table->index(['pr_id']);

            $table->foreign('pr_committee_id')->references('id')->on('pr_committee')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('judge_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('created_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('updated_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('deleted_by')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pr_committee_judgement_report');
    }
};