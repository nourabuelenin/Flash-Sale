<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Product;
use App\Models\Hold;
use App\Models\Order;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;

class StockConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected InventoryService $inventoryService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inventoryService = app(InventoryService::class);
    }

    /**
     * Test stock consistency after hold creation and release cycle
     */
    public function test_stock_consistency_after_full_cycle(): void
    {
        $product = Product::create([
            'strName' => 'Consistency Test Product',
            'strSku' => 'CONS-001',
            'strDescription' => 'Test',
            'decPrice' => 99.99,
            'intStock' => 100,
        ]);

        $initialStock = $product->intStock;

        // Create 10 holds
        $holds = [];
        for ($i = 0; $i < 10; $i++) {
            $holds[] = $this->inventoryService->createHold($product->id, 5);
        }

        // Stock should be reduced
        $product->refresh();
        $this->assertEquals($initialStock - 50, $product->intStock);

        // Release all holds manually
        foreach ($holds as $hold) {
            $this->inventoryService->releaseHold($hold->strHoldToken);
        }

        // Stock should be back to initial
        $product->refresh();
        $this->assertEquals($initialStock, $product->intStock);
    }

    /**
     * Test that database and cache stay in sync
     */
    public function test_cache_invalidation_on_stock_change(): void
    {
        $product = Product::create([
            'strName' => 'Cache Test Product',
            'strSku' => 'CACHE-001',
            'strDescription' => 'Test',
            'decPrice' => 49.99,
            'intStock' => 50,
        ]);

        // Cache the product
        Cache::put("product:{$product->id}", $product, 60);

        // Create a hold
        $this->inventoryService->createHold($product->id, 10);

        // Cache should be invalidated
        $cachedProduct = Cache::get("product:{$product->id}");
        $this->assertNull($cachedProduct, 'Cache should be invalidated after hold creation');

        // Fetch fresh data
        $product->refresh();
        $this->assertEquals(40, $product->intStock);
    }

    /**
     * Test stock consistency across transaction rollbacks
     */
    public function test_stock_consistency_after_rollback(): void
    {
        $product = Product::create([
            'strName' => 'Rollback Consistency Test',
            'strSku' => 'ROLL-001',
            'strDescription' => 'Test',
            'decPrice' => 79.99,
            'intStock' => 10,
        ]);

        $initialStock = $product->intStock;

        // Attempt to create hold with insufficient stock (should rollback)
        try {
            $this->inventoryService->createHold($product->id, 20);
        } catch (\Exception $e) {
            // Expected
        }

        // Stock should remain unchanged
        $product->refresh();
        $this->assertEquals($initialStock, $product->intStock);
    }

    /**
     * Test no negative stock values
     */
    public function test_stock_never_goes_negative(): void
    {
        $product = Product::create([
            'strName' => 'Negative Stock Test',
            'strSku' => 'NEG-001',
            'strDescription' => 'Test',
            'decPrice' => 29.99,
            'intStock' => 5,
        ]);

        // Try to create hold exceeding stock
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Insufficient stock');
        
        $this->inventoryService->createHold($product->id, 10);

        // Verify stock is still positive
        $product->refresh();
        $this->assertGreaterThanOrEqual(0, $product->intStock);
    }

    /**
     * Test hold count matches actual stock reduction
     */
    public function test_hold_count_matches_stock_reduction(): void
    {
        $product = Product::create([
            'strName' => 'Hold Count Test',
            'strSku' => 'COUNT-001',
            'strDescription' => 'Test',
            'decPrice' => 39.99,
            'intStock' => 100,
        ]);

        $initialStock = $product->intStock;

        // Create holds
        $hold1 = $this->inventoryService->createHold($product->id, 10);
        $hold2 = $this->inventoryService->createHold($product->id, 15);
        $hold3 = $this->inventoryService->createHold($product->id, 25);

        // Calculate total held
        $totalHeld = $hold1->intQuantity + $hold2->intQuantity + $hold3->intQuantity;

        // Verify stock reduction matches holds
        $product->refresh();
        $this->assertEquals($initialStock - $totalHeld, $product->intStock);
        $this->assertEquals(50, $product->intStock);
    }

    /**
     * Test stock consistency with partial releases
     */
    public function test_partial_hold_releases(): void
    {
        $product = Product::create([
            'strName' => 'Partial Release Test',
            'strSku' => 'PART-001',
            'strDescription' => 'Test',
            'decPrice' => 59.99,
            'intStock' => 50,
        ]);

        // Create 5 holds of 5 items each
        $holds = [];
        for ($i = 0; $i < 5; $i++) {
            $holds[] = $this->inventoryService->createHold($product->id, 5);
        }

        $product->refresh();
        $this->assertEquals(25, $product->intStock); // 50 - 25

        // Release only 3 of them
        for ($i = 0; $i < 3; $i++) {
            $this->inventoryService->releaseHold($holds[$i]->strHoldToken);
        }

        // Stock should increase by 15 (3 * 5)
        $product->refresh();
        $this->assertEquals(40, $product->intStock); // 25 + 15
    }

    /**
     * Test stock consistency after payment failures
     */
    public function test_stock_returned_after_payment_failure(): void
    {
        $product = Product::create([
            'strName' => 'Payment Failure Test',
            'strSku' => 'PAY-001',
            'strDescription' => 'Test',
            'decPrice' => 89.99,
            'intStock' => 20,
        ]);

        $initialStock = $product->intStock;

        // Create hold
        $hold = $this->inventoryService->createHold($product->id, 5);

        $product->refresh();
        $this->assertEquals(15, $product->intStock);

        // Create order
        $order = Order::create([
            'intHoldID' => $hold->id,
            'decTotalPrice' => 449.95,
            'strStatus' => 'pending',
        ]);

        // Simulate payment failure via webhook
        $this->postJson('/api/payments/webhook', [
            'order_id' => $order->id,
            'status' => 'failed',
        ], [
            'Idempotency-Key' => 'payment-failure-test-' . Str::random(16),
        ]);

        // Stock should be returned immediately
        $product->refresh();
        $this->assertEquals($initialStock, $product->intStock);

        // Hold should be released
        $hold->refresh();
        $this->assertNotNull($hold->tmRelease);
    }

    /**
     * Test available stock calculation
     */
    public function test_available_stock_calculation(): void
    {
        $product = Product::create([
            'strName' => 'Available Stock Test',
            'strSku' => 'AVAIL-001',
            'strDescription' => 'Test',
            'decPrice' => 19.99,
            'intStock' => 100,
        ]);

        // Create some active holds
        $this->inventoryService->createHold($product->id, 10);
        $this->inventoryService->createHold($product->id, 20);

        $product->refresh();
        
        // Available stock should be reduced
        $this->assertEquals(70, $product->intStock);

        // Active holds count
        $activeHolds = Hold::where('intProductID', $product->id)
            ->whereNull('tmRelease')
            ->whereNull('tmConvertedToOrder')
            ->sum('intQuantity');

        // Verify math: initial - active holds = available
        $this->assertEquals(30, $activeHolds);
        $this->assertEquals(100 - 30, $product->intStock);
    }

    /**
     * Test stock integrity across multiple products
     */
    public function test_stock_integrity_across_products(): void
    {
        // Create multiple products
        $products = [];
        for ($i = 0; $i < 3; $i++) {
            $products[] = Product::create([
                'strName' => "Product $i",
                'strSku' => "MULTI-00$i",
                'strDescription' => 'Test',
                'decPrice' => 49.99,
                'intStock' => 50,
            ]);
        }

        // Create holds on each product
        foreach ($products as $product) {
            $this->inventoryService->createHold($product->id, 10);
        }

        // Verify each product's stock independently
        foreach ($products as $product) {
            $product->refresh();
            $this->assertEquals(40, $product->intStock);
        }
    }

    /**
     * Test that completed orders don't return stock
     */
    public function test_completed_orders_dont_return_stock(): void
    {
        $product = Product::create([
            'strName' => 'Completed Order Test',
            'strSku' => 'COMP-001',
            'strDescription' => 'Test',
            'decPrice' => 99.99,
            'intStock' => 30,
        ]);

        // Create hold and reduce stock
        $hold = $this->inventoryService->createHold($product->id, 5);

        $product->refresh();
        $this->assertEquals(25, $product->intStock);

        // Mark hold as converted to order
        $hold->update(['tmConvertedToOrder' => now()]);

        // Run expiry process (should skip converted holds)
        $count = $this->inventoryService->releaseExpiredHolds();

        $this->assertEquals(0, $count);

        // Stock should NOT be returned
        $product->refresh();
        $this->assertEquals(25, $product->intStock);
    }
}
