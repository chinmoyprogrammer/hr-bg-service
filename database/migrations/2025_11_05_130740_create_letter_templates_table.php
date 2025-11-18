<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('letter_templates', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('template_name', 255);
            $table->string('template_code', 255);
            $table->unsignedBigInteger('letter_type_id');
            $table->text('content');
            $table->text('placeholders')->nullable();
            $table->boolean('status')->default(true);

            $table->unsignedBigInteger('created_user_id');
            $table->unsignedBigInteger('updated_user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->nullable();
            $table->tinyInteger('deleted_by')->default(0);
            $table->timestamp('deleted_at')->nullable();

            $table->foreign('letter_type_id')->references('id')->on('letter_types');
            $table->foreign('created_user_id')->references('id')->on('users');
            $table->foreign('updated_user_id')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('letter_templates');
    }
};