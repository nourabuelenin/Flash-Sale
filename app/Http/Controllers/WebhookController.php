<?php

namespace App\Http\Controllers;

use App\Models\IdempotencyLog;
use App\Models\Order;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    protected $inventoryService;

    public function __construct(InventoryService $inventoryService)
    {
        $this->inventoryService = $inventoryService;
    }

    public function handle(Request $request): JsonResponse
    {
        // 1. Extract Idempotency Key (Simulated header or body field)
        $key = $request->header('Idempotency-Key') ?? $request->input('idempotency_key');

        if (!$key) {
            return response()->json(['error' => 'Idempotency Key required'], 400);
        }

        // 2. CHECK: Have we seen this before?
        $existingLog = IdempotencyLog::where('strIdempotencyKey', $key)->first();

        if ($existingLog) {
            return response()->json(
                $existingLog->strResponseBody, 
                $existingLog->intResponseCode
            );
        }

        // 3. PROCESS: It's a new request - Use DB transaction with locking
        return DB::transaction(function () use ($request, $key) {
            $existingLog = IdempotencyLog::where('strIdempotencyKey', $key)
                ->lockForUpdate()
                ->first();

            if ($existingLog) {
                return response()->json(
                    $existingLog->strResponseBody, 
                    $existingLog->intResponseCode
                );
            }

            $orderId = $request->input('order_id');
            $status = $request->input('status');
            
            $order = Order::find($orderId);

            if (!$order) {
                $responseBody = ['error' => 'Order not found'];
                $responseCode = 404;
                
                IdempotencyLog::create([
                    'strIdempotencyKey' => $key,
                    'strRequest' => $request->path(),
                    'strResponseBody' => $responseBody,
                    'intResponseCode' => $responseCode
                ]);
                
                return response()->json($responseBody, $responseCode);
            }

            if ($status === 'success') {
                $order->update([
                    'strStatus' => 'completed',
                    'strTransactionID' => $request->input('txn_id')
                ]);
            } else {
                $order->update(['strStatus' => 'failed']);
                
                $hold = $order->hold;
                if ($hold && $hold->strHoldToken) {
                    try {
                        $this->inventoryService->releaseHold($hold->strHoldToken);
                        Log::info("Released hold immediately due to payment failure", [
                            'order_id' => $order->id,
                            'hold_token' => $hold->strHoldToken
                        ]);
                    } catch (\Exception $e) {
                        Log::warning("Failed to release hold immediately", [
                            'order_id' => $order->id,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }

            // 4. SAVE RESULT for future duplicates
            $responseBody = ['status' => 'processed', 'order_status' => $order->strStatus];
            $responseCode = 200;
            
            IdempotencyLog::create([
                'strIdempotencyKey' => $key,
                'strRequest' => $request->path(),
                'strResponseBody' => $responseBody,
                'intResponseCode' => $responseCode
            ]);

            return response()->json($responseBody, $responseCode);
        });
    }
}