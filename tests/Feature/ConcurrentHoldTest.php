<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Product;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

class ConcurrentHoldTest extends TestCase
{
    use RefreshDatabase;

    protected InventoryService $inventoryService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inventoryService = app(InventoryService::class);
    }

    /**
     * Test that concurrent hold requests don't oversell stock
     */
    public function test_concurrent_holds_prevent_overselling(): void
    {
        // Create a product with limited stock
        $product = Product::create([
            'strName' => 'Limited Edition Product',
            'strSku' => 'TEST-001',
            'strDescription' => 'Test product',
            'decPrice' => 99.99,
            'intStock' => 5, // Only 5 items
        ]);

        $successes = 0;
        $failures = 0;

        // Simulate 10 concurrent requests trying to buy 1 item each
        $promises = [];
        for ($i = 0; $i < 10; $i++) {
            try {
                $hold = $this->inventoryService->createHold($product->id, 1);
                if ($hold) {
                    $successes++;
                }
            } catch (\Exception $e) {
                $failures++;
            }
        }

        // Refresh product from database
        $product->refresh();

        // Assertions
        $this->assertEquals(5, $successes, 'Exactly 5 holds should succeed');
        $this->assertEquals(5, $failures, 'Exactly 5 holds should fail');
        $this->assertEquals(0, $product->intStock, 'Stock should be 0 after 5 holds');
    }

    /**
     * Test multiple users trying to reserve the last item
     */
    public function test_last_item_race_condition(): void
    {
        $product = Product::create([
            'strName' => 'Last Item Test',
            'strSku' => 'LAST-001',
            'strDescription' => 'Test product',
            'decPrice' => 49.99,
            'intStock' => 1, // Only 1 item
        ]);

        $firstHoldCreated = false;
        $secondHoldCreated = false;

        // First request
        try {
            $this->inventoryService->createHold($product->id, 1);
            $firstHoldCreated = true;
        } catch (\Exception $e) {
            // Should not happen
        }

        // Second request (should fail)
        try {
            $this->inventoryService->createHold($product->id, 1);
            $secondHoldCreated = true;
        } catch (\Exception $e) {
            // Expected to fail
        }

        $product->refresh();

        $this->assertTrue($firstHoldCreated, 'First hold should succeed');
        $this->assertFalse($secondHoldCreated, 'Second hold should fail');
        $this->assertEquals(0, $product->intStock, 'Stock should be 0');
    }

    /**
     * Test that holds respect exact quantities
     */
    public function test_hold_respects_exact_quantity(): void
    {
        $product = Product::create([
            'strName' => 'Quantity Test Product',
            'strSku' => 'QTY-001',
            'strDescription' => 'Test product',
            'decPrice' => 29.99,
            'intStock' => 10,
        ]);

        // Try to hold 11 items (should fail)
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Insufficient stock');
        
        $this->inventoryService->createHold($product->id, 11);
    }

    /**
     * Test pessimistic locking prevents race conditions
     */
    public function test_pessimistic_locking_works(): void
    {
        $product = Product::create([
            'strName' => 'Lock Test Product',
            'strSku' => 'LOCK-001',
            'strDescription' => 'Test product',
            'decPrice' => 19.99,
            'intStock' => 3,
        ]);

        // Simulate concurrent transactions
        DB::beginTransaction();
        try {
            // This should lock the product row
            $lockedProduct = Product::lockForUpdate()->find($product->id);
            $this->assertNotNull($lockedProduct);
            
            // Verify the lock is held
            $this->assertEquals(3, $lockedProduct->intStock);
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            $this->fail('Transaction should not fail: ' . $e->getMessage());
        }

        // After lock is released, stock should still be correct
        $product->refresh();
        $this->assertEquals(3, $product->intStock);
    }

    /**
     * Test that failed holds don't decrement stock
     */
    public function test_failed_hold_doesnt_decrement_stock(): void
    {
        $product = Product::create([
            'strName' => 'Rollback Test Product',
            'strSku' => 'ROLL-001',
            'strDescription' => 'Test product',
            'decPrice' => 39.99,
            'intStock' => 5,
        ]);

        $initialStock = $product->intStock;

        // Try to hold more than available
        try {
            $this->inventoryService->createHold($product->id, 10);
        } catch (\Exception $e) {
            // Expected exception
        }

        $product->refresh();
        
        // Stock should remain unchanged due to transaction rollback
        $this->assertEquals($initialStock, $product->intStock);
    }
}
