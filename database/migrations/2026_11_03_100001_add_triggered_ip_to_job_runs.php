<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The browser address of the user who started a run (from the api-gateway's X-Real-IP), shown in the job monitor.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('job_runs', fn (Blueprint $table) => $table->string('triggered_ip', 45)->nullable()->after('triggered_by'));
    }

    public function down(): void
    {
        Schema::table('job_runs', fn (Blueprint $table) => $table->dropColumn('triggered_ip'));
    }
};
