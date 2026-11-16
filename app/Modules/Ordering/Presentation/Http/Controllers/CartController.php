<?php

namespace App\Modules\Ordering\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Application\SelectProductVariant;
use App\Modules\Ordering\Domain\Models\Cart;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

final class CartController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->cart($request)->load(['items.product', 'items.variant'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['product_id' => ['required', 'integer', 'exists:products,id'], 'variant_id' => ['nullable', 'integer'], 'quantity' => ['required', 'integer', 'between:1,20']]);
        $cart = $this->cart($request);
        DB::transaction(function () use ($cart, $data): void {
            $cart->newQuery()->whereKey($cart->id)->lockForUpdate()->firstOrFail();
            $product = Product::findOrFail($data['product_id']);
            $variant = app(SelectProductVariant::class)->handle($product, $data['variant_id'] ?? null);
            $item = $cart->items()->firstOrNew(['product_id' => $product->id, 'variant_key' => $variant?->id ?? 0]);
            $quantity = ($item->exists ? $item->quantity : 0) + $data['quantity'];
            abort_if($quantity > 20 || $quantity > ($variant?->stock ?? $product->stock), 422, 'تعداد درخواستی از موجودی یا سقف ۲۰ عدد بیشتر است.');
            $item->variant_id = $variant?->id;
            $item->quantity = $quantity;
            $item->save();
        });

        return response()->json(['data' => $cart->load(['items.product', 'items.variant'])], 201);
    }

    public function update(Request $request, int $item): JsonResponse
    {
        $data = $request->validate(['quantity' => ['required', 'integer', 'between:1,20']]);
        $cart = $this->cart($request);
        $row = $cart->items()->findOrFail($item);
        $variant = app(SelectProductVariant::class)->handle($row->product, $row->variant_id);
        abort_if(($variant?->stock ?? $row->product->stock) < $data['quantity'], 422, 'Insufficient stock.');
        $row->update($data);

        return response()->json(['data' => $cart->load(['items.product', 'items.variant'])]);
    }

    public function destroy(Request $request, int $item): JsonResponse
    {
        $this->cart($request)->items()->findOrFail($item)->delete();

        return response()->json(status: 204);
    }

    private function cart(Request $request): Cart
    {
        $token = $request->header('X-Cart-Token') ?: $request->query('cart_token');

        return $token ? Cart::firstOrCreate(['token' => $token]) : Cart::create(['token' => (string) Str::uuid()]);
    }
}
