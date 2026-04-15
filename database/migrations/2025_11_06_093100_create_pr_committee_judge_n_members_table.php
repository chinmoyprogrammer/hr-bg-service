<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pr_committee_judge_n_members', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('pr_committee_id');
            $table->unsignedBigInteger('member_user_id');
            $table->unsignedTinyInteger('type'); // 1=judge,2=member

            $table->unsignedBigInteger('created_user_id');
            $table->timestamp('created_at')->useCurrent();
            $table->unsignedBigInteger('updated_user_id')->nullable();
            $table->timestamp('updated_at')->useCurrent()->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->string('child_data_identifier_key_outgoing', 255)->nullable();

            $table->index(['pr_committee_id']);
            $table->index(['member_user_id']);

            $table->foreign('pr_committee_id')->references('id')->on('pr_committee')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('member_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('created_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('updated_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('deleted_by')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pr_committee_judge_n_members');
    }
};
