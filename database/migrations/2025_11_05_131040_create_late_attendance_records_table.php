<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('late_attendance_records', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('employee_id');
            $table->date('attendance_date');
            $table->integer('late_minutes')->default(0);
            $table->decimal('late_days', 4, 2)->default(0);
            $table->unsignedBigInteger('shift_id');
            $table->decimal('calculated_deduction', 10, 2)->default(0);
            $table->decimal('applied_deduction', 10, 2)->nullable();
            $table->enum('deduction_status', ['Pending','Applied','Waived'])->default('Pending');
            $table->unsignedBigInteger('late_deduction_policy_id')->nullable();

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable();
            $table->unsignedBigInteger('deleted_user_id')->nullable();
            $table->tinyInteger('deleted_by')->default(0);
            $table->timestamp('deleted_at')->nullable();

            $table->foreign('employee_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('shift_id')->references('id')->on('shifts')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('late_deduction_policy_id')->references('id')->on('late_deduction_policies')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('created_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('updated_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
            $table->foreign('deleted_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('late_attendance_records');
    }
};