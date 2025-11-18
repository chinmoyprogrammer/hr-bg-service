<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pr_committee', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('pr_id'); // problem register id (cross-module), keeping without FK
            $table->date('deadline');
            $table->unsignedBigInteger('created_user_id');
            $table->timestamp('created_at')->useCurrent();

            $table->string('remarks', 255)->nullable();

            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->string('child_data_identifier_key_incoming', 255)->nullable();
            $table->dateTime('child_data_identifier_key_outgoing')->nullable();

            $table->index(['pr_id']);
            $table->foreign('created_user_id')->references('id')->on('users')->onUpdate('cascade')->onDelete('restrict');
            $table->foreign('deleted_by')->references('id')->on('users')->onUpdate('cascade')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pr_committee');
    }
};