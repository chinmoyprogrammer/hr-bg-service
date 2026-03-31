<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('late_deduction_policies', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('policy_title', 255);
            $table->enum('deduction_basis', ['Minutes','Day']);
            $table->enum('deduction_from', ['Basic','Gross']);
            $table->integer('max_late_days')->default(0);
            $table->decimal('deduction_amount', 10, 2)->nullable();
            $table->decimal('deduction_rate', 5, 2)->nullable();
            $table->date('effective_date');
            $table->boolean('status')->default(true);
            $table->text('description')->nullable();

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable();
            $table->unsignedBigInteger('deleted_user_id')->nullable();
            $table->tinyInteger('deleted_by')->default(0);
            $table->timestamp('deleted_at')->nullable();

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->string('child_data_identifier_key_outgoing', 255)->nullable();

            $table->foreign('created_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('updated_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('deleted_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('late_deduction_policies');
    }
};