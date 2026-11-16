<?php

namespace App\Modules\Catalog\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['category_id', 'name', 'slug', 'sku', 'summary', 'description', 'specifications', 'additional_description', 'usage', 'price', 'old_price', 'stock', 'rating', 'image', 'gallery', 'is_featured', 'is_active', 'views_count', 'has_variants', 'tags'])]
class Product extends Model
{
    protected function casts(): array
    {
        return [
            'gallery' => 'array',
            'tags' => 'array',
            'specifications' => 'array',
            'is_featured' => 'boolean',
            'is_active' => 'boolean',
            'has_variants' => 'boolean',
            'rating' => 'float',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function marketplaceListings(): HasMany
    {
        return $this->hasMany(\App\Modules\Marketplaces\Domain\Models\MarketplaceListing::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ProductComment::class);
    }
}
