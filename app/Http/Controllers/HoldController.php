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
            'product_id' => 'required|integer',
            'qty' => 'required|integer|min:1',
        ]);

        try {
            // 2. Delegate "Heavy Lifting" to Service
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
            return response()->json(['error' => $e->getMessage()], $e->getCode() ?: 400);
        }
    }
}