<?php

namespace App\Modules\Catalog\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['product_id', 'sku', 'attributes', 'price', 'old_price', 'stock', 'is_active'])]
class ProductVariant extends Model
{
    protected function casts(): array
    {
        return ['attributes' => 'array', 'is_active' => 'boolean'];
    }
}
