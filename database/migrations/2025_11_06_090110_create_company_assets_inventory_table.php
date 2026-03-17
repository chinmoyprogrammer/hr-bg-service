<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('company_assets_inventory', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('department_id');
            $table->unsignedBigInteger('assets_category_id');
            $table->string('item_name', 255);
            $table->string('item_code', 255);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('unit_id');
            $table->boolean('status')->default(true);
            $table->decimal('quantity', 22, 2);
            $table->decimal('service_life', 5, 2)->nullable();
            $table->boolean('is_single_use')->default(false);

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();

            $table->dateTime('created_at');
            $table->dateTime('updated_at')->nullable();

            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->string('child_data_identifier_key_outgoing', 255)->nullable();

            $table->index('item_code');

            $table->foreign('assets_category_id')->references('id')->on('company_assets_category')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('created_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('updated_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('deleted_by')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_assets_inventory');
    }
};