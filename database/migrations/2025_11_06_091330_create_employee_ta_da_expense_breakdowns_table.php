<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_ta_da_expense_breakdowns', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('employee_user_id');
            $table->unsignedBigInteger('employee_ta_da_id');
            $table->date('expense_date');
            $table->unsignedTinyInteger('expense_category'); // 1=transport,2=accomodation,3=meal,4=misc
            $table->unsignedTinyInteger('travel_mode')->nullable(); // 1=bus,2=train,3=flight,4=car,5=cng,6=rickshaw,7=motor cycle,8=other
            $table->string('travel_mode_other_description', 255)->nullable();
            $table->text('description')->nullable();
            $table->decimal('amount', 22, 2);

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->dateTime('child_data_identifier_key_outgoing')->nullable();

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            // Short explicit name to avoid MySQL 64-char limit
            $table->index(['employee_ta_da_id', 'expense_date'], 'idx_tada_exp_date');

            $table->foreign('employee_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('employee_ta_da_id')->references('id')->on('employee_ta_da')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('created_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('updated_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('deleted_by')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_ta_da_expense_breakdowns');
    }
};