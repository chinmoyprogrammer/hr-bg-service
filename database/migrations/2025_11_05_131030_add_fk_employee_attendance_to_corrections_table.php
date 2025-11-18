<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('employee_attendance', function (Blueprint $table) {
            // Add FK with a short explicit name after both tables exist
            $table->foreign('employee_applied_attendance_correction_id', 'fk_emp_att_corr')
                  ->references('id')
                  ->on('employee_applied_attendance_corrections')
                  ->onUpdate('cascade')
                  ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('employee_attendance', function (Blueprint $table) {
            $table->dropForeign('fk_emp_att_corr');
        });
    }
};