<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\JsonResponse;

class ProductController extends Controller
{
    public function view(int $id): JsonResponse
    {
        $product = Cache::remember("product:{$id}", 60, function () use ($id) {
            return Product::findOrFail($id);
        });

        // If you need 100% real-time stock, refresh just the stock field from DB:
        // $product->refresh(); 
        // Or trust the cache if you invalidate it on every Hold creation.

        return response()->json([
            'id' => $product->id,
            'name' => $product->strName,
            'price' => $product->decPrice,
            'available_stock' => $product->intStock,
        ]);
    }
}