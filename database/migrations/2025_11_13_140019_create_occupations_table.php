<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('occupations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 255);
            $table->string('code', 50)->nullable();
            $table->text('description')->nullable();
            $table->boolean('status')->default(1)->comment('1 = Active, 0 = Inactive');
            $table->string('child_data_identifier_key_incoming', 255)->nullable()->comment('Key for multiple media or attachments. Pattern: <table>_<id>');
            $table->string('child_data_identifier_key_outgoing', 255)->nullable();
            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Foreign Keys
            $table->foreign('created_user_id')->references('id')->on('users');
            $table->foreign('updated_user_id')->references('id')->on('users');
            $table->foreign('deleted_by')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('occupations');
    }
};
