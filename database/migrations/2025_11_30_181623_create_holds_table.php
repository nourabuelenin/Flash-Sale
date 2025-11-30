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
        Schema::create('holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intProductID')->constrained('products')->onDelete('cascade');
            $table->integer('intQuantity');
            $table->timestamp('tmExpire')->index();
            $table->timestamp('tmRelease')->nullable();
            $table->timestamp('tmConvertedToOrder')->nullable();
            $table->string('strHoldToken')->unique()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('holds');
    }
};