<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('holiday_facilities', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('holiday_type_id');
            $table->string('title', 100);
            $table->boolean('grants_compensatory_leave')->default(false);
            $table->decimal('gross_multiplier', 6, 2)->nullable();
            $table->decimal('other_benefit_amount', 12, 2)->nullable();
            $table->text('description')->nullable();
            $table->date('effective_from')->nullable();
            $table->unsignedTinyInteger('facility_type'); // 1=leave, 2=cash, 3=leave and cash

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->string('child_data_identifier_key_outgoing', 255)->nullable();

            $table->unique('title');

            $table->foreign('holiday_type_id')->references('id')->on('holiday_types');
            $table->foreign('created_user_id')->references('id')->on('users');
            $table->foreign('updated_user_id')->references('id')->on('users');
            $table->foreign('deleted_by')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holiday_facilities');
    }
};