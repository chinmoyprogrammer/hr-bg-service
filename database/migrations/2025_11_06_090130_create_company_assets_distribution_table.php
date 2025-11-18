<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('company_assets_distribution', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('occupied_user_id');
            $table->unsignedBigInteger('company_assets_inventory_id');
            $table->unsignedBigInteger('company_assets_serial_id');
            $table->unsignedBigInteger('quantity');

            $table->string('child_data_identifier_key_outgoing', 255)->nullable();
            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->unsignedBigInteger('employee_imposed_approval_chain')->nullable();

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();

            $table->dateTime('created_at');
            $table->dateTime('updated_at')->nullable();

            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->foreign('occupied_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('company_assets_inventory_id')->references('id')->on('company_assets_inventory')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('company_assets_serial_id')->references('id')->on('company_assets_serials')->onUpdate('cascade')->onDelete('restrict');

            $table->foreign('created_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('updated_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('deleted_by')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_assets_distribution');
    }
};