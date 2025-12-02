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

        return response()->json([
            'id' => $product->id,
            'name' => $product->strName,
            'price' => $product->decPrice,
            'available_stock' => $product->intStock,
        ]);
    }
}