<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('uuid', 255)->unique('uniq_failed_jobs_uuid');
            $table->string('connection', 255);
            $table->string('queue', 255);
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();

            $table->index(['connection', 'queue'], 'idx_failed_jobs_conn_queue');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_jobs');
    }
};