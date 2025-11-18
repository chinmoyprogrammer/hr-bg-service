<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('increment_promotion_batches', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Minimal table to satisfy foreign keys from related tables.
            // Additional fields can be added later if needed.

            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('increment_promotion_batches');
    }
};