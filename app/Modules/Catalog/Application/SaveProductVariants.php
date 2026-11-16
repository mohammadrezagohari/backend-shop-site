<?php

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use Illuminate\Validation\ValidationException;

final class SaveProductVariants
{
    public function handle(Product $product, array $rows): void
    {
        $kept = [];
        $combinations = [];
        $attributeNames = null;
        foreach ($rows as $index => $row) {
            $attributes = $row['attributes'];
            ksort($attributes);
            $keys = array_keys($attributes);
            if ($attributeNames !== null && $attributeNames !== $keys) {
                throw ValidationException::withMessages(['variants' => 'نام ویژگی‌های تمام تنوع‌های محصول باید یکسان باشد.']);
            }
            $attributeNames = $keys;
            $signature = json_encode($attributes, JSON_UNESCAPED_UNICODE);
            if (in_array($signature, $combinations, true)) {
                throw ValidationException::withMessages(['variants' => 'ترکیب ویژگی‌ها تکراری است.']);
            }
            $combinations[] = $signature;
            $variant = isset($row['id']) ? $product->variants()->find($row['id']) : new ProductVariant;
            if (! $variant) {
                throw ValidationException::withMessages(["variants.$index.id" => 'تنوع متعلق به این محصول نیست.']);
            }
            if (ProductVariant::query()->where('sku', $row['sku'])->when($variant->exists, fn ($q) => $q->where('id', '!=', $variant->id))->exists()) {
                throw ValidationException::withMessages(["variants.$index.sku" => 'شناسه تنوع تکراری است.']);
            }
            unset($row['id']);
            $variant->fill($row + ['is_active' => true]);
            $variant->attributes = $attributes;
            $product->variants()->save($variant);
            $kept[] = $variant->id;
        }
        // Keep historical IDs for carts and orders; removed options become unavailable.
        $product->variants()->whereNotIn('id', $kept)->update(['is_active' => false]);
        $active = $product->variants()->where('is_active', true)->get();
        if ($rows !== [] && $active->isEmpty()) {
            throw ValidationException::withMessages(['variants' => 'حداقل یک تنوع فعال لازم است.']);
        }
        $product->has_variants = $rows !== [];
        if ($product->has_variants) {
            $product->price = $active->min('price');
            $product->stock = $active->sum('stock');
            $product->old_price = null;
        }
        $product->save();
    }
}
