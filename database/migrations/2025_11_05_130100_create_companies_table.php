<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->string('child_data_identifier_key_outgoing', 255)->nullable();

            $table->string('company_name', 150);
            $table->string('company_name_bn', 150)->nullable();
            $table->string('bin_tax_vat_number', 50);
            $table->string('contact_person', 100)->nullable();
            $table->string('contact_person_bn', 100)->nullable();
            $table->string('contact_no', 20)->nullable();
            $table->string('email', 100)->nullable();
            $table->text('address')->nullable();
            $table->text('address_bn')->nullable();

            $table->boolean('status')->default(true);

            $table->dateTime('created_at');
            $table->dateTime('updated_at')->nullable();

            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->unique('bin_tax_vat_number');

            $table->foreign('created_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('updated_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('deleted_by')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};