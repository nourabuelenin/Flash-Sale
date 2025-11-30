<?php

namespace App\Http\Controllers;

use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Exception;

class HoldController extends Controller
{
    protected InventoryService $inventoryService;

    public function __construct(InventoryService $inventoryService)
    {
        $this->inventoryService = $inventoryService;
    }

    public function store(Request $request): JsonResponse
    {
        // 1. Validation
        $request->validate([
            'intProductID' => 'required|integer',
            'intQuantity' => 'required|integer|min:1',
        ]);

        try {
            // 2. Delegate "Heavy Lifting" to Service
            // This handles the locking and race conditions internally.
            $hold = $this->inventoryService->createHold(
                $request->product_id, 
                $request->qty
            );

            // 3. Success Response
            return response()->json([
                'hold_id' => $hold->id,
                'token' => $hold->strHoldToken, 
                'expires_at' => $hold->tmExpire,
            ], 201);

        } catch (Exception $e) {
            // Handle "Insufficient Stock" or "Product Not Found"
            // Using 409 Conflict is standard for resource contention
            return response()->json(['error' => $e->getMessage()], $e->getCode() ?: 400);
        }
    }
}