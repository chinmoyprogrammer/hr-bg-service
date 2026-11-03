<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Outgoing API calls made during a job run (Http:: client and sendSMS), shown in the job monitor's run details.
// Bodies and header blocks keep their first and last 8 KB; secrets are masked before saving (RunTracker::httpCall).
return new class extends Migration {
    public function up(): void
    {
        Schema::create('job_http_calls', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('job_run_id');
            $table->unsignedSmallInteger('seq');           // call number within the run, from 1
            $table->string('method', 10);
            $table->text('url');
            $table->unsignedSmallInteger('status')->nullable();  // null: no response (connection failed)
            $table->boolean('failed');                     // 4xx/5xx, no connection, or a 2xx body that reports an error
            $table->string('error', 255)->nullable();      // why it counts as failed
            $table->unsignedInteger('duration_ms')->nullable();
            $table->mediumText('request_headers')->nullable();
            $table->mediumText('request_body')->nullable();
            $table->unsignedInteger('request_bytes')->default(0);
            $table->mediumText('response_headers')->nullable();
            $table->mediumText('response_body')->nullable();
            $table->unsignedInteger('response_bytes')->default(0);
            $table->dateTime('started_at', 3);
            $table->index(['job_run_id', 'seq'], 'idx_job_http_calls_run');
            $table->index('started_at', 'idx_job_http_calls_started');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_http_calls');
    }
};
