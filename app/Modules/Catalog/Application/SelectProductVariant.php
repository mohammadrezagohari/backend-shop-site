<?php

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use Illuminate\Validation\ValidationException;

final class SelectProductVariant
{
    public function handle(Product $product, ?int $variantId): ?ProductVariant
    {
        if (! $product->is_active) {
            throw ValidationException::withMessages(['product_id' => 'این محصول قابل خرید نیست.']);
        }
        if (! $product->has_variants && $variantId === null) {
            return null;
        }
        $variant = $product->has_variants && $variantId ? $product->variants()->where('is_active', true)->find($variantId) : null;
        if (! $variant) {
            throw ValidationException::withMessages(['variant_id' => 'تنوع معتبر محصول را انتخاب کنید.']);
        }

        return $variant;
    }
}
