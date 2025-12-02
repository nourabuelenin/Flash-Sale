<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Order;
use App\Models\Product;
use App\Models\Hold;
use App\Models\IdempotencyLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

class WebhookIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test webhook idempotency - duplicate requests return same response
     */
    public function test_duplicate_webhook_returns_cached_response(): void
    {
        // Create test data
        $product = Product::create([
            'strName' => 'Test Product',
            'strSku' => 'WH-001',
            'strDescription' => 'Test',
            'decPrice' => 99.99,
            'intStock' => 10,
        ]);

        $hold = Hold::create([
            'intProductID' => $product->id,
            'intQuantity' => 1,
            'strHoldToken' => Str::random(32),
            'tmExpire' => now()->addMinutes(2),
        ]);

        $order = Order::create([
            'intHoldID' => $hold->id,
            'decTotalPrice' => 99.99,
            'strStatus' => 'pending',
        ]);

        $idempotencyKey = 'test-key-' . Str::random(16);

        // First request
        $response1 = $this->postJson('/api/payments/webhook', [
            'order_id' => $order->id,
            'status' => 'success',
            'txn_id' => 'TXN-123',
        ], [
            'Idempotency-Key' => $idempotencyKey,
        ]);

        $response1->assertStatus(200);
        $response1->assertJson([
            'status' => 'processed',
            'order_status' => 'completed',
        ]);

        // Second request with same key
        $response2 = $this->postJson('/api/payments/webhook', [
            'order_id' => $order->id,
            'status' => 'success',
            'txn_id' => 'TXN-123',
        ], [
            'Idempotency-Key' => $idempotencyKey,
        ]);

        $response2->assertStatus(200);
        $response2->assertJson([
            'status' => 'processed',
            'order_status' => 'completed',
        ]);

        // Verify response is identical
        $this->assertEquals($response1->json(), $response2->json());

        // Verify idempotency log was created
        $this->assertDatabaseHas('idempotency_logs', [
            'strIdempotencyKey' => $idempotencyKey,
        ]);

        // Verify only one log entry exists
        $this->assertEquals(1, IdempotencyLog::where('strIdempotencyKey', $idempotencyKey)->count());
    }

    /**
     * Test that missing idempotency key returns error
     */
    public function test_missing_idempotency_key_returns_error(): void
    {
        $response = $this->postJson('/api/payments/webhook', [
            'order_id' => 1,
            'status' => 'success',
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'Idempotency Key required',
        ]);
    }

    /**
     * Test concurrent webhook requests with same key
     */
    public function test_concurrent_webhooks_with_same_key_only_process_once(): void
    {
        $product = Product::create([
            'strName' => 'Concurrent Test Product',
            'strSku' => 'CONC-001',
            'strDescription' => 'Test',
            'decPrice' => 49.99,
            'intStock' => 10,
        ]);

        $hold = Hold::create([
            'intProductID' => $product->id,
            'intQuantity' => 1,
            'strHoldToken' => Str::random(32),
            'tmExpire' => now()->addMinutes(2),
        ]);

        $order = Order::create([
            'intHoldID' => $hold->id,
            'decTotalPrice' => 49.99,
            'strStatus' => 'pending',
        ]);

        $idempotencyKey = 'concurrent-key-' . Str::random(16);

        // Simulate 5 concurrent requests
        $responses = [];
        for ($i = 0; $i < 5; $i++) {
            $responses[] = $this->postJson('/api/payments/webhook', [
                'order_id' => $order->id,
                'status' => 'success',
                'txn_id' => 'TXN-CONCURRENT-123',
            ], [
                'Idempotency-Key' => $idempotencyKey,
            ]);
        }

        // All should return 200
        foreach ($responses as $response) {
            $response->assertStatus(200);
        }

        // Only one idempotency log should exist
        $this->assertEquals(1, IdempotencyLog::where('strIdempotencyKey', $idempotencyKey)->count());

        // Order should be completed once
        $order->refresh();
        $this->assertEquals('completed', $order->strStatus);
    }

    /**
     * Test webhook with non-existent order
     */
    public function test_webhook_with_nonexistent_order_returns_404(): void
    {
        $idempotencyKey = 'nonexistent-order-key';

        $response = $this->postJson('/api/payments/webhook', [
            'order_id' => 99999, // Non-existent
            'status' => 'success',
            'txn_id' => 'TXN-456',
        ], [
            'Idempotency-Key' => $idempotencyKey,
        ]);

        $response->assertStatus(404);
        $response->assertJson([
            'error' => 'Order not found',
        ]);

        // Verify this failure is also logged for idempotency
        $this->assertDatabaseHas('idempotency_logs', [
            'strIdempotencyKey' => $idempotencyKey,
            'intResponseCode' => 404,
        ]);
    }

    /**
     * Test payment success updates order correctly
     */
    public function test_successful_payment_webhook_updates_order(): void
    {
        $product = Product::create([
            'strName' => 'Success Test Product',
            'strSku' => 'SUC-001',
            'strDescription' => 'Test',
            'decPrice' => 79.99,
            'intStock' => 10,
        ]);

        $hold = Hold::create([
            'intProductID' => $product->id,
            'intQuantity' => 1,
            'strHoldToken' => Str::random(32),
            'tmExpire' => now()->addMinutes(2),
        ]);

        $order = Order::create([
            'intHoldID' => $hold->id,
            'decTotalPrice' => 79.99,
            'strStatus' => 'pending',
        ]);

        $response = $this->postJson('/api/payments/webhook', [
            'order_id' => $order->id,
            'status' => 'success',
            'txn_id' => 'TXN-SUCCESS-789',
        ], [
            'Idempotency-Key' => 'success-key-' . Str::random(16),
        ]);

        $response->assertStatus(200);

        $order->refresh();
        $this->assertEquals('completed', $order->strStatus);
        $this->assertEquals('TXN-SUCCESS-789', $order->strTransactionID);
    }

    /**
     * Test payment failure updates order and releases stock
     */
    public function test_failed_payment_webhook_releases_stock(): void
    {
        $product = Product::create([
            'strName' => 'Failure Test Product',
            'strSku' => 'FAIL-001',
            'strDescription' => 'Test',
            'decPrice' => 59.99,
            'intStock' => 5,
        ]);

        $initialStock = $product->intStock;

        $hold = Hold::create([
            'intProductID' => $product->id,
            'intQuantity' => 2,
            'strHoldToken' => Str::random(32),
            'tmExpire' => now()->addMinutes(2),
        ]);

        // Manually decrement stock to simulate hold creation
        $product->decrement('intStock', 2);

        $order = Order::create([
            'intHoldID' => $hold->id,
            'decTotalPrice' => 119.98,
            'strStatus' => 'pending',
        ]);

        $response = $this->postJson('/api/payments/webhook', [
            'order_id' => $order->id,
            'status' => 'failed',
        ], [
            'Idempotency-Key' => 'failure-key-' . Str::random(16),
        ]);

        $response->assertStatus(200);

        // Order should be marked as failed
        $order->refresh();
        $this->assertEquals('failed', $order->strStatus);

        // Stock should be released immediately
        $hold->refresh();
        $this->assertNotNull($hold->tmRelease, 'Hold should be marked as released');

        // Stock should be returned
        $product->refresh();
        $this->assertEquals($initialStock, $product->intStock);
    }
}
