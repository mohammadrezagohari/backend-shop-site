<?php

namespace App\Modules\Ordering\Domain\Models;

use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\ProductVariant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_id', 'product_name', 'quantity', 'unit_price', 'total', 'variant_id', 'variant_sku', 'selected_attributes'])]
class OrderItem extends Model
{
    protected function casts(): array
    {
        return ['selected_attributes' => 'array'];
    }
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
