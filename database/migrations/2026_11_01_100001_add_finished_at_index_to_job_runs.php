<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// jobs:watchdog reads finished runs by finished_at every minute (flags, retention)
return new class extends Migration {
    public function up(): void
    {
        Schema::table('job_runs', fn (Blueprint $table) => $table->index('finished_at', 'idx_job_runs_finished'));
    }

    public function down(): void
    {
        Schema::table('job_runs', fn (Blueprint $table) => $table->dropIndex('idx_job_runs_finished'));
    }
};
