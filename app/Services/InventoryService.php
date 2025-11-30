<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Hold;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Exception;

class InventoryService
{
    /**
     * Create a hold with pessimistic locking to prevent overselling.
     * * @throws Exception If stock is insufficient
     */
    public function createHold(int $productId, int $qty): Hold
    {
        // 1. Start a Database Transaction
        return DB::transaction(function () use ($productId, $qty) {
            
            // 2. LOCK the product row. 
            $product = Product::lockForUpdate()->find($productId);

            if (!$product) {
                throw new Exception("Product not found", 404);
            }

            // 3. The Concurrency Check
            if ($product->intStock < $qty) {
                throw new Exception("Insufficient stock", 409); // 409 Conflict
            }

            // 4. Decrement Stock (Atomic update within the lock)
            $product->decrement('intStock', $qty);

            // 5. Create the Hold Record
            return Hold::create([
                'intProductID' => $product->id,
                'intQuantity' => $qty,
                'strHoldToken' => Str::random(32), // Secure token for the frontend
                'tmExpire' => now()->addMinutes(2), // Hold for 2 mins
            ]);
        });
        // Transaction automatically commits here if no exception is thrown.
        // If Exception is thrown, it automatically rolls back.
    }

    /**
     * Release expired holds and return stock to the pool.
     * usage: Scheduled Task
     */
    public function releaseExpiredHolds(): int
    {
        // Find holds that are expired AND not yet converted/released
        $expiredHolds = Hold::where('tmExpire', '<', now())
            ->whereNull('tmRelease')
            ->whereNull('tmConvertedToOrder')
            ->lockForUpdate() // Lock them so we don't process them twice in parallel
            ->get();

        $count = 0;

        foreach ($expiredHolds as $hold) {
            DB::transaction(function () use ($hold) {
                // Return stock to product
                $hold->product()->increment('intStock', $hold->intQuantity);
                
                // Mark as released
                $hold->update(['tmRelease' => now()]);
            });
            $count++;
        }

        return $count;
    }
}