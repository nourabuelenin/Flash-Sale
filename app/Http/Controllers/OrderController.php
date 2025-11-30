<?php

namespace App\Http\Controllers;

use App\Models\Hold;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'hold_id' => 'required|integer',
            'token' => 'required|string', // Security check
        ]);

        // 1. Find the Hold
        $hold = Hold::with('product')->find($request->hold_id);

        // 2. Validate Constraints
        if (!$hold || $hold->strHoldToken !== $request->token) {
            return response()->json(['error' => 'Invalid hold or token'], 403);
        }

        if (!$hold->isValid()) {
            return response()->json(['error' => 'Hold expired or already used'], 400);
        }

        // 3. Create Order Atomically
        $order = DB::transaction(function () use ($hold) {
            // Mark hold as used
            $hold->update(['tmConvertedToOrder' => now()]);

            // Create Order in "pre-payment" state
            return Order::create([
                'intHoldID' => $hold->id,
                'strStatus' => 'pending',
                'decTotalPrice' => $hold->intQuantity * $hold->product->decPrice,
            ]);
        });

        return response()->json([
            'order_id' => $order->id,
            'status' => $order->strStatus,
            'amount' => $order->decTotalPrice
        ], 201);
    }
}