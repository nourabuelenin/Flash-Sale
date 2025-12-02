<?php

use App\Http\Controllers\ProductController;
use App\Http\Controllers\HoldController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::middleware(['throttle:api'])->group(function () {
    Route::get('/products/{id}', [ProductController::class, 'view'])
        ->middleware('throttle:100,1');
    
    Route::post('/holds', [HoldController::class, 'store'])
        ->middleware('throttle:20,1');
    
    Route::post('/orders', [OrderController::class, 'store'])
        ->middleware('throttle:20,1');
});

Route::post('/payments/webhook', [WebhookController::class, 'handle'])
    ->middleware('throttle:1000,1');