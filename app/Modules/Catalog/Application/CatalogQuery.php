<?php

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use Illuminate\Support\Facades\Cache;

final class CatalogQuery
{
    public function products(array $filters): array
    {
        ksort($filters);
        $key = 'catalog:products:v'.Cache::get('catalog:version', 1).':'.sha1(json_encode($filters));

        return Cache::remember($key, now()->addMinutes(10), function () use ($filters): array {
            return Product::query()
                ->with(['category:id,name,slug', 'variants' => fn ($query) => $query->where('is_active', true)])
                ->where('is_active', true)
                ->when($filters['tag'] ?? null, fn ($query, $tag) => $query->whereJsonContains('tags', $tag))
                ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(function ($inner) use ($search): void {
                    $inner->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%");
                }))
                ->when($filters['category'] ?? null, fn ($query, $slug) => $query->whereIn('category_id', Category::query()->where('slug', $slug)->first()?->descendantIds() ?? []))
                ->when(($filters['featured'] ?? null) === '1', fn ($query) => $query->where('is_featured', true))
                ->when(($filters['sort'] ?? 'newest') === 'price_asc', fn ($query) => $query->orderBy('price'))
                ->when(($filters['sort'] ?? 'newest') === 'price_desc', fn ($query) => $query->orderByDesc('price'))
                ->when(($filters['sort'] ?? 'newest') === 'popular', fn ($query) => $query->orderByDesc('views_count'))
                ->when(($filters['sort'] ?? 'newest') === 'rating', fn ($query) => $query->orderByDesc('rating'))
                ->when(($filters['sort'] ?? 'newest') === 'newest', fn ($query) => $query->latest('id'))
                ->paginate(min((int) ($filters['per_page'] ?? 12), 48))
                ->toArray();
        });
    }

    public function categories(): array
    {
        $key = 'catalog:categories:v'.Cache::get('catalog:version', 1);

        return Cache::remember($key, now()->addHour(), fn (): array => Category::query()
            ->where('is_active', true)->withCount('products')->orderBy('sort_order')->get()->toArray());
    }
}
