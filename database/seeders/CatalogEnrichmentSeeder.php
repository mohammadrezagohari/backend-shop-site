<?php

namespace Database\Seeders;

use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Content\Domain\Models\Post;
use Illuminate\Database\Seeder;

class CatalogEnrichmentSeeder extends Seeder
{
    public function run(): void
    {
        $names = ['sports-supplements' => 'مکمل‌های بدنسازی', 'skin-hair' => 'مراقبت روزانه پوست و مو',
            'medical-equipment' => 'تجهیزات پزشکی خانگی', 'vitamins' => 'ویتامین‌های روزانه'];
        Category::query()->whereNull('parent_id')->orderBy('id')->get()->each(function (Category $parent) use ($names): void {
            if (! $parent->children()->exists()) {
                Category::firstOrCreate(['slug' => $parent->slug.'-selection'], [
                    'name' => $names[$parent->slug] ?? 'انواع '.$parent->name,
                    'parent_id' => $parent->id, 'is_active' => $parent->is_active, 'sort_order' => 0,
                ]);
            }
        });
        Product::query()->with('category')->each(function (Product $product): void {
            if (! $product->tags) {
                $product->update(['tags' => array_values(array_unique(array_map(fn ($tag) => mb_substr($tag, 0, 80), array_filter([$product->category?->name, $product->name]))))]);
            }
        });
        Post::query()->each(function (Post $post): void {
            if (! $post->tags) { $post->update(['tags' => [mb_substr($post->title, 0, 80)]]); }
        });
        \App\Modules\Catalog\Application\InvalidateCatalog::handle();
    }
}
