<?php

namespace App\Http\Controllers;

use App\Models\IdempotencyLog;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        // 1. Extract Idempotency Key (Simulated header or body field)
        $key = $request->header('Idempotency-Key') ?? $request->input('idempotency_key');

        if (!$key) {
            return response()->json(['error' => 'Idempotency Key required'], 400);
        }

        // 2. CHECK: Have we seen this before?
        $existingLog = Log::where('strIdempotencyKey', $key)->first();

        if ($existingLog) {
            // RETURN CACHED RESPONSE immediately
            return response()->json(
                $existingLog->strResponseBody, 
                $existingLog->intResponseCode
            );
        }

        // 3. PROCESS: It's a new request
        // Assume payload has { "order_id": 123, "status": "success", "txn_id": "abc" }
        $orderId = $request->input('order_id');
        $status = $request->input('status');
        
        $order = Order::find($orderId);

        if (!$order) {
            // Handle "Out of Order" arrival
            // If the webhook arrives before the Order is created, we can't update it.
            // Returning 404/422 often prompts the provider to retry later.
            return response()->json(['error' => 'Order not found'], 404);
        }

        // Update Order State
        if ($status === 'success') {
            $order->update([
                'strStatus' => 'completed',
                'strTransactionID' => $request->input('txn_id')
            ]);
        } else {
            $order->update(['strStatus' => 'failed']);
            // OPTIONAL: If failed, you might want to release the stock immediately
            // But usually, we let the hold expiry job handle that to keep this fast.
        }

        // 4. SAVE RESULT for future duplicates
        $responseBody = ['status' => 'processed', 'order_status' => $order->strStatus];
        
        Log::create([
            'strIdempotencyKey' => $key,
            'strRequest' => $request->path(),
            'strResponseBody' => $responseBody,
            'intResponseCode' => 200
        ]);

        return response()->json($responseBody, 200);
    }
}