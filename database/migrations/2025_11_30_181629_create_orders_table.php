<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intHoldID')->constrained('holds')->onDelete('cascade');
            $table->decimal('decTotalPrice', 10, 2);
            $table->enum('strStatus', ['pending', 'completed', 'failed', 'cancelled'])
                ->default('pending')
                ->index();
            $table->string('strTransactionID')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};

