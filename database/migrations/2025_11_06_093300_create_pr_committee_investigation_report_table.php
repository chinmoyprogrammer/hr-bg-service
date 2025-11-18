<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pr_committee_investigation_report', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('pr_committee_id');
            $table->unsignedBigInteger('committee_user_id');
            $table->text('findings')->nullable();
            $table->text('recommandations')->nullable();

            $table->unsignedBigInteger('created_user_id');
            $table->dateTime('created_at');
            $table->unsignedBigInteger('updated_user_id')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->dateTime('child_data_identifier_key_outgoing')->nullable();

            $table->index(['pr_committee_id']);

            $table->foreign('pr_committee_id')->references('id')->on('pr_committee')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('committee_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('created_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('updated_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('deleted_by')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pr_committee_investigation_report');
    }
};