<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Creates logs table for IdempotencyLog model to track webhook idempotency.
     */
    public function up(): void
    {
        Schema::create('idempotency_logs', function (Blueprint $table) {
            $table->id();
            $table->string('strIdempotencyKey')->unique();
            $table->string('strRequest');
            $table->json('strResponseBody');
            $table->integer('intResponseCode');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('idempotency_logs');
    }
};