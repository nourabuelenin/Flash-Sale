<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Product;
use App\Models\Hold;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Artisan;

class HoldExpiryTest extends TestCase
{
    use RefreshDatabase;

    protected InventoryService $inventoryService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inventoryService = app(InventoryService::class);
    }

    /**
     * Test that expired holds are released and stock is returned
     */
    public function test_expired_holds_are_released(): void
    {
        $product = Product::create([
            'strName' => 'Expiry Test Product',
            'strSku' => 'EXP-001',
            'strDescription' => 'Test',
            'decPrice' => 99.99,
            'intStock' => 10,
        ]);

        // Create a hold that's already expired
        $expiredHold = Hold::create([
            'intProductID' => $product->id,
            'intQuantity' => 3,
            'strHoldToken' => Str::random(32),
            'tmExpire' => now()->subMinutes(5), // Expired 5 minutes ago
        ]);

        // Manually decrement stock to simulate hold creation
        $product->decrement('intStock', 3);
        $product->refresh();
        $this->assertEquals(7, $product->intStock);

        // Run the release command
        $count = $this->inventoryService->releaseExpiredHolds();

        // Verify hold was released
        $this->assertEquals(1, $count);

        // Verify hold is marked as released
        $expiredHold->refresh();
        $this->assertNotNull($expiredHold->tmRelease);

        // Verify stock was returned
        $product->refresh();
        $this->assertEquals(10, $product->intStock);
    }

    /**
     * Test that non-expired holds are NOT released
     */
    public function test_non_expired_holds_are_not_released(): void
    {
        $product = Product::create([
            'strName' => 'Non-Expired Test Product',
            'strSku' => 'NEXP-001',
            'strDescription' => 'Test',
            'decPrice' => 49.99,
            'intStock' => 10,
        ]);

        // Create a hold that's still valid
        $validHold = Hold::create([
            'intProductID' => $product->id,
            'intQuantity' => 2,
            'strHoldToken' => Str::random(32),
            'tmExpire' => now()->addMinutes(2), // Still valid
        ]);

        // Manually decrement stock
        $product->decrement('intStock', 2);
        $product->refresh();
        $this->assertEquals(8, $product->intStock);

        // Run the release command
        $count = $this->inventoryService->releaseExpiredHolds();

        // No holds should be released
        $this->assertEquals(0, $count);

        // Hold should still be active
        $validHold->refresh();
        $this->assertNull($validHold->tmRelease);

        // Stock should remain the same
        $product->refresh();
        $this->assertEquals(8, $product->intStock);
    }

    /**
     * Test that already released holds are not processed again
     */
    public function test_already_released_holds_are_skipped(): void
    {
        $product = Product::create([
            'strName' => 'Already Released Test',
            'strSku' => 'REL-001',
            'strDescription' => 'Test',
            'decPrice' => 29.99,
            'intStock' => 10,
        ]);

        // Create an expired hold that was already released
        $releasedHold = Hold::create([
            'intProductID' => $product->id,
            'intQuantity' => 1,
            'strHoldToken' => Str::random(32),
            'tmExpire' => now()->subMinutes(10),
            'tmRelease' => now()->subMinutes(5), // Already released
        ]);

        $initialStock = $product->intStock;

        // Run the release command
        $count = $this->inventoryService->releaseExpiredHolds();

        // No holds should be released
        $this->assertEquals(0, $count);

        // Stock should remain unchanged
        $product->refresh();
        $this->assertEquals($initialStock, $product->intStock);
    }

    /**
     * Test that converted holds are not released
     */
    public function test_converted_holds_are_not_released(): void
    {
        $product = Product::create([
            'strName' => 'Converted Hold Test',
            'strSku' => 'CONV-001',
            'strDescription' => 'Test',
            'decPrice' => 39.99,
            'intStock' => 10,
        ]);

        // Create an expired hold that was converted to an order
        $convertedHold = Hold::create([
            'intProductID' => $product->id,
            'intQuantity' => 2,
            'strHoldToken' => Str::random(32),
            'tmExpire' => now()->subMinutes(10),
            'tmConvertedToOrder' => now()->subMinutes(8), // Already converted
        ]);

        // Manually decrement stock
        $product->decrement('intStock', 2);
        $initialStock = $product->intStock;

        // Run the release command
        $count = $this->inventoryService->releaseExpiredHolds();

        // No holds should be released
        $this->assertEquals(0, $count);

        // Stock should remain unchanged (not returned)
        $product->refresh();
        $this->assertEquals($initialStock, $product->intStock);
    }

    /**
     * Test batch release of multiple expired holds
     */
    public function test_multiple_expired_holds_are_released(): void
    {
        $product = Product::create([
            'strName' => 'Batch Release Test',
            'strSku' => 'BATCH-001',
            'strDescription' => 'Test',
            'decPrice' => 19.99,
            'intStock' => 20,
        ]);

        // Create multiple expired holds
        for ($i = 0; $i < 5; $i++) {
            Hold::create([
                'intProductID' => $product->id,
                'intQuantity' => 2,
                'strHoldToken' => Str::random(32),
                'tmExpire' => now()->subMinutes(rand(1, 10)),
            ]);
            $product->decrement('intStock', 2);
        }

        $product->refresh();
        $this->assertEquals(10, $product->intStock); // 20 - (5 * 2)

        // Run the release command
        $count = $this->inventoryService->releaseExpiredHolds();

        // All 5 holds should be released
        $this->assertEquals(5, $count);

        // Stock should be fully returned
        $product->refresh();
        $this->assertEquals(20, $product->intStock);
    }

    /**
     * Test manual hold release via service method
     */
    public function test_manual_hold_release_works(): void
    {
        $product = Product::create([
            'strName' => 'Manual Release Test',
            'strSku' => 'MAN-001',
            'strDescription' => 'Test',
            'decPrice' => 69.99,
            'intStock' => 10,
        ]);

        $holdToken = Str::random(32);

        $hold = Hold::create([
            'intProductID' => $product->id,
            'intQuantity' => 3,
            'strHoldToken' => $holdToken,
            'tmExpire' => now()->addMinutes(2), // Still valid
        ]);

        // Manually decrement stock
        $product->decrement('intStock', 3);
        $product->refresh();
        $this->assertEquals(7, $product->intStock);

        // Manually release the hold using service method
        $this->inventoryService->releaseHold($holdToken);

        // Verify hold is released
        $hold->refresh();
        $this->assertNotNull($hold->tmRelease);

        // Verify stock is returned
        $product->refresh();
        $this->assertEquals(10, $product->intStock);
    }

    /**
     * Test releasing non-existent hold throws exception
     */
    public function test_releasing_nonexistent_hold_throws_exception(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Hold not found');

        $this->inventoryService->releaseHold('nonexistent-token');
    }

    /**
     * Test releasing already released hold throws exception
     */
    public function test_releasing_already_released_hold_throws_exception(): void
    {
        $product = Product::create([
            'strName' => 'Double Release Test',
            'strSku' => 'DBL-001',
            'strDescription' => 'Test',
            'decPrice' => 89.99,
            'intStock' => 10,
        ]);

        $holdToken = Str::random(32);

        $hold = Hold::create([
            'intProductID' => $product->id,
            'intQuantity' => 1,
            'strHoldToken' => $holdToken,
            'tmExpire' => now()->addMinutes(2),
            'tmRelease' => now(), // Already released
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Hold already processed');

        $this->inventoryService->releaseHold($holdToken);
    }

    /**
     * Test command execution via Artisan
     */
    public function test_artisan_command_releases_holds(): void
    {
        $product = Product::create([
            'strName' => 'Artisan Test Product',
            'strSku' => 'ART-001',
            'strDescription' => 'Test',
            'decPrice' => 59.99,
            'intStock' => 15,
        ]);

        // Create expired holds
        for ($i = 0; $i < 3; $i++) {
            Hold::create([
                'intProductID' => $product->id,
                'intQuantity' => 1,
                'strHoldToken' => Str::random(32),
                'tmExpire' => now()->subMinutes(5),
            ]);
            $product->decrement('intStock', 1);
        }

        $product->refresh();
        $this->assertEquals(12, $product->intStock);

        // Run the artisan command
        Artisan::call('holds:release');

        // Verify stock is returned
        $product->refresh();
        $this->assertEquals(15, $product->intStock);
    }
}
