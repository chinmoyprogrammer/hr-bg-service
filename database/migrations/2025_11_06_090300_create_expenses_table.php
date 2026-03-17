<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('expense_head_id');
            $table->unsignedBigInteger('expense_category_id');
            $table->decimal('amount', 22, 2);
            $table->unsignedBigInteger('expense_by_user_id')->nullable();

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->string('child_data_identifier_key_outgoing', 255)->nullable();

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();

            $table->dateTime('created_at');
            $table->dateTime('updated_at')->nullable();

            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->index(['expense_head_id', 'expense_category_id']);

            $table->foreign('expense_head_id')->references('id')->on('expense_heads')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('expense_category_id')->references('id')->on('expense_categories')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('expense_by_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');

            $table->foreign('created_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('updated_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('deleted_by')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};