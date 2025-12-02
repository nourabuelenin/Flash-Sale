<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Hold;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
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
                throw new Exception("Insufficient stock", 409);
            }

            // 4. Decrement Stock
            $product->decrement('intStock', $qty);

            // 5. Invalidate product cache
            $product->invalidateCache();

            // 6. Create the Hold Record
            return Hold::create([
                'intProductID' => $product->id,
                'intQuantity' => $qty,
                'strHoldToken' => Str::random(32),
                'tmExpire' => now()->addMinutes(2),
            ]);
        });
    }

    /**
     * Release expired holds and return stock to the pool.
     * usage: Scheduled Task
     */
    public function releaseExpiredHolds(): int
    {
        $expiredHolds = Hold::where('tmExpire', '<', now())
            ->whereNull('tmRelease')
            ->whereNull('tmConvertedToOrder')
            ->lockForUpdate()
            ->get();

        $count = 0;

        foreach ($expiredHolds as $hold) {
            DB::transaction(function () use ($hold) {
                $hold->product()->increment('intStock', $hold->intQuantity);
                $hold->product->invalidateCache();
                $hold->update(['tmRelease' => now()]);
            });
            $count++;
        }

        return $count;
    }

    /**
     * Release a specific hold immediately (e.g., when payment fails).
     * 
     * @param string $holdToken The hold token to release
     * @throws Exception If hold not found or already processed
     */
    public function releaseHold(string $holdToken): void
    {
        DB::transaction(function () use ($holdToken) {
            $hold = Hold::where('strHoldToken', $holdToken)
                ->lockForUpdate()
                ->first();

            if (!$hold) {
                throw new Exception("Hold not found", 404);
            }

            if ($hold->tmRelease || $hold->tmConvertedToOrder) {
                throw new Exception("Hold already processed", 409);
            }

            $hold->product()->increment('intStock', $hold->intQuantity);
            
            $hold->product->invalidateCache();
            
            $hold->update(['tmRelease' => now()]);
        });
    }
}