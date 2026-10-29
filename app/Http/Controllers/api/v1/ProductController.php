<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GMS: the products (revenue lines) of each game, their limits and winnings tax rate.
 */
class ProductController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $game = $request->validate([
            'game' => ['nullable', 'string', 'max:30'],
        ])['game'] ?? null;

        $products = Product::query()
            ->with('game')
            ->when($game !== null, fn ($query) => $query->whereRelation('game', 'code', $game))
            ->orderBy('id')
            ->get();

        return ProductResource::collection($products);
    }

    public function update(UpdateProductRequest $request, string $code): JsonResponse
    {
        $product = Product::with('game')->where('code', $code)->first();

        if (! $product) {
            return response()->json(['success' => false, 'message' => 'Product not found'], 404);
        }

        $product->update($request->validated());

        return response()->json(['success' => true, 'message' => 'Product updated', 'data' => ProductResource::make($product)]);
    }
}
