<?php

namespace App\Modules\Ordering\Domain\Models;

use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['cart_id', 'product_id', 'variant_id', 'variant_key', 'quantity'])]
#[Appends(['unit_price', 'selected_attributes', 'available_stock'])]
#[Hidden(['variant_key'])]
class CartItem extends Model
{
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function getUnitPriceAttribute(): int
    {
        return (int) ($this->variant?->price ?? $this->product?->price ?? 0);
    }

    public function getSelectedAttributesAttribute(): object
    {
        return (object) ($this->variant?->attributes ?? []);
    }

    public function getAvailableStockAttribute(): int
    {
        if (! $this->product?->is_active || ($this->product->has_variants && (! $this->variant || ! $this->variant->is_active))
            || ($this->variant_id && ! $this->product->has_variants)) {
            return 0;
        }

        return (int) ($this->variant?->stock ?? $this->product->stock);
    }
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
