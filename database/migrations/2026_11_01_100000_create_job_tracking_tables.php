<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Job failure tracking (docs/job-failure-tracking-plan.html). Timestamps are written from PHP (Asia/Dhaka), never by MySQL defaults.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('job_runs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('run_uuid', 36)->unique('uniq_job_runs_uuid');
            $table->string('job', 100);
            $table->string('job_class', 191)->nullable();
            $table->string('queue', 100)->nullable();
            $table->string('trigger_source', 20)->nullable();
            $table->unsignedBigInteger('triggered_by')->nullable();
            $table->unsignedBigInteger('parent_run_id')->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 30);
            $table->json('flags')->nullable();
            $table->unsignedSmallInteger('attempt')->default(0);
            $table->unsignedSmallInteger('max_attempts')->nullable();
            $table->dateTime('queued_at', 3)->nullable();
            $table->dateTime('started_at', 3)->nullable();
            $table->dateTime('finished_at', 3)->nullable();
            $table->unsignedInteger('wait_ms')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('worker_host', 100)->nullable();
            $table->unsignedInteger('worker_pid')->nullable();
            $table->string('error_class', 191)->nullable();
            $table->text('error_message')->nullable();
            $table->mediumText('error_trace')->nullable();
            foreach (['tx_committed', 'tx_rolled_back', 'writes_kept', 'writes_undone', 'http_calls', 'http_failed',
                         'errors', 'warnings', 'log_lines', 'log_dropped'] as $counter) {
                $table->unsignedInteger($counter)->default(0);
            }
            $table->json('metrics')->nullable();

            $table->index(['job', 'started_at'], 'idx_job_runs_job_started');
            $table->index(['status', 'started_at'], 'idx_job_runs_status_started');
            $table->index('queued_at', 'idx_job_runs_queued');
        });
        // gzipped JSON lines; Blueprint::binary() is only a 64 KB BLOB on MySQL (sqlite: tests)
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE job_runs ADD log MEDIUMBLOB NULL AFTER log_dropped');
        } else {
            Schema::table('job_runs', fn (Blueprint $table) => $table->binary('log')->nullable());
        }

        Schema::create('job_schedules', function (Blueprint $table) {
            $table->string('job', 100)->primary();
            $table->string('queue', 100)->nullable();
            $table->string('cron', 100)->nullable();
            $table->string('timezone', 50)->default('Asia/Dhaka');
            $table->unsignedSmallInteger('grace_minutes')->default(5);
            $table->unsignedInteger('max_runtime_s')->default(600);
            $table->unsignedInteger('max_wait_s')->default(300);
            $table->string('expect_metric', 100)->nullable();
            $table->boolean('enabled')->default(true);
            $table->string('source', 20)->nullable();
            $table->dateTime('synced_at')->nullable();
            $table->dateTime('last_expected_at')->nullable();
            $table->dateTime('last_checked_at')->nullable();
        });

        Schema::create('job_alerts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('job', 100);
            $table->string('kind', 30);
            $table->unsignedBigInteger('first_run_id')->nullable();
            $table->unsignedBigInteger('last_run_id')->nullable();
            $table->dateTime('opened_at');
            $table->dateTime('last_sent_at')->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->dateTime('resolved_at')->nullable();

            $table->index(['job', 'kind', 'resolved_at'], 'idx_job_alerts_open');
        });

        Schema::create('heartbeats', function (Blueprint $table) {
            $table->string('name', 50)->primary();
            $table->dateTime('beat_at', 3);
            $table->json('info')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('heartbeats');
        Schema::dropIfExists('job_alerts');
        Schema::dropIfExists('job_schedules');
        Schema::dropIfExists('job_runs');
    }
};
