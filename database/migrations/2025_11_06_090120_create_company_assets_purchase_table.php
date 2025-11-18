<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('company_assets_purchase', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('company_assets_inventory_id');
            $table->decimal('quantity', 22, 2);
            $table->tinyInteger('type'); // 1=purchase,2=return
            $table->date('occourance_date');
            $table->decimal('service_life', 5, 2)->nullable();
            $table->unsignedBigInteger('occoured_by');

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();

            $table->dateTime('created_at');
            $table->dateTime('updated_at')->nullable();

            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->dateTime('child_data_identifier_key_outgoing')->nullable();

            $table->foreign('company_assets_inventory_id')->references('id')->on('company_assets_inventory')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('occoured_by')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('created_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('updated_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('deleted_by')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_assets_purchase');
    }
};