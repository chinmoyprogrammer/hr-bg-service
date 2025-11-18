<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();

            $table->unsignedBigInteger('company_id');

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->string('child_data_identifier_key_outgoing', 255)->nullable();

            $table->string('branch_name', 150);
            $table->string('branch_name_bn', 150)->nullable();
            $table->string('branch_short_name', 20);
            $table->text('address')->nullable();
            $table->text('address_bn')->nullable();
            // Override to users.id as per instruction
            $table->unsignedBigInteger('branch_in_charge_id')->nullable();
            $table->string('contact_no', 20)->nullable();

            $table->boolean('status')->default(true);

            $table->dateTime('created_at');
            $table->dateTime('updated_at')->nullable();

            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->unique('branch_name');
            $table->unique('branch_short_name');

            $table->foreign('company_id')->references('id')->on('companies')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('branch_in_charge_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('created_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('updated_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('deleted_by')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};