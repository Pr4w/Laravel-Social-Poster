<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('social.idempotency_table', 'social_idempotency'), function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('status')->index(); // pending | published
            $table->string('platform')->nullable();
            $table->string('platform_post_id')->nullable();
            $table->text('url')->nullable();
            $table->json('payload')->nullable();
            $table->json('state')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('social.idempotency_table', 'social_idempotency'));
    }
};
