<?php

namespace App\Modules\Catalog\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Application\SaveProductVariants;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Support\TagRules;
use App\Modules\Marketplaces\Application\MarketplaceCatalog;

final class AdminCatalogController extends Controller
{
    public function products(Request $request): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 20), 1), 100);

        return response()->json(Product::query()->with(['category:id,name,slug', 'marketplaceListings', 'variants' => fn ($q) => $q->where('is_active', true)])->latest('id')->paginate($perPage));
    }

    public function storeProduct(Request $request): JsonResponse
    {
        $data = $this->productData($request);
        $product = DB::transaction(function () use ($data): Product {
            $rows = $data['variants'] ?? [];
            $channels = $data['marketplaces'] ?? null;
            unset($data['variants'], $data['marketplaces']);
            $product = Product::query()->create($data);
            app(SaveProductVariants::class)->handle($product, $rows);
            app(MarketplaceCatalog::class)->save($product, $channels);
            app(MarketplaceCatalog::class)->queue($product);

            return $product;
        });
        $this->invalidateCatalog();

        return response()->json(['data' => $product->load(['category:id,name,slug', 'variants', 'marketplaceListings'])], 201);
    }

    public function updateProduct(Request $request, Product $product): JsonResponse
    {
        $data = $this->productData($request, $product);
        DB::transaction(function () use ($product, $data): void {
            $product = Product::query()->lockForUpdate()->findOrFail($product->id);
            $rows = $data['variants'] ?? null;
            $channels = $data['marketplaces'] ?? null;
            unset($data['variants'], $data['marketplaces']);
            $product->update($data);
            if ($rows !== null) {
                app(SaveProductVariants::class)->handle($product, $rows);
            } elseif ($product->has_variants) {
                $active = $product->variants()->where('is_active', true)->get();
                $product->update(['price' => $active->min('price'), 'stock' => $active->sum('stock'), 'old_price' => null]);
            }
            app(MarketplaceCatalog::class)->save($product, $channels);
            app(MarketplaceCatalog::class)->queue($product);
        });
        $this->invalidateCatalog();

        return response()->json(['data' => $product->refresh()->load(['category:id,name,slug', 'marketplaceListings', 'variants' => fn ($q) => $q->where('is_active', true)])]);
    }

    public function destroyProduct(Product $product): JsonResponse
    {
        $product->delete();
        $this->invalidateCatalog();

        return response()->json(status: 204);
    }

    public function categories(): JsonResponse
    {
        return response()->json(['data' => Category::query()->withCount('products')->orderBy('sort_order')->get()]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $category = Category::query()->create($this->categoryData($request));
        $this->invalidateCatalog();

        return response()->json(['data' => $category], 201);
    }

    public function updateCategory(Request $request, Category $category): JsonResponse
    {
        $category->update($this->categoryData($request, $category));
        $this->invalidateCatalog();

        return response()->json(['data' => $category->refresh()]);
    }

    public function destroyCategory(Category $category): JsonResponse
    {
        if ($category->products()->exists() || $category->children()->exists()) {
            return response()->json(['message' => 'A category with products cannot be deleted.'], 409);
        }
        $category->delete();
        $this->invalidateCatalog();

        return response()->json(status: 204);
    }

    private function productData(Request $request, ?Product $product = null): array
    {
        $data = $request->validate([
            'category_id' => [$product ? 'sometimes' : 'required', 'integer', 'exists:categories,id'],
            'name' => [$product ? 'sometimes' : 'required', 'string', 'max:255'],
            'slug' => [$product ? 'sometimes' : 'required', 'string', 'max:255', Rule::unique('products')->ignore($product)],
            'sku' => [$product ? 'sometimes' : 'required', 'string', 'max:100', Rule::unique('products')->ignore($product)],
            'summary' => ['nullable', 'string'], 'description' => ['nullable', 'string'],
            'specifications' => ['nullable', 'array'], 'specifications.*.label' => ['required_with:specifications', 'string', 'max:100'],
            'specifications.*.value' => ['required_with:specifications', 'string', 'max:500'],
            'additional_description' => ['nullable', 'string'], 'usage' => ['nullable', 'string'],
            'price' => [$product ? 'sometimes' : 'required', 'integer', 'min:0'], 'old_price' => ['nullable', 'integer', 'min:0'],
            'stock' => [$product ? 'sometimes' : 'required', 'integer', 'min:0'], 'rating' => ['nullable', 'numeric', 'between:0,5'],
            'image' => ['nullable', 'string', 'max:2048'], 'gallery' => ['nullable', 'array'],
            'is_featured' => ['sometimes', 'boolean'], 'is_active' => ['sometimes', 'boolean'],
            'variants' => ['sometimes', 'array', 'max:200'],
            'variants.*' => ['array:id,sku,attributes,price,old_price,stock,is_active'],
            'variants.*.id' => ['sometimes', 'integer', 'distinct'],
            'variants.*.sku' => ['required', 'string', 'max:100', 'distinct'],
            'variants.*.attributes' => ['required', 'array', 'min:1', 'max:10', function ($attribute, $value, $fail): void {
                if (! is_array($value) || array_is_list($value)) { $fail('ویژگی‌ها باید نام و مقدار داشته باشند.'); return; }
                foreach (array_keys($value) as $key) {
                    if (! is_string($key) || trim($key) === '' || mb_strlen($key) > 80) { $fail('نام ویژگی معتبر نیست.'); }
                }
            }],
            'variants.*.attributes.*' => ['required', 'string', 'max:100'],
            'variants.*.price' => ['required', 'integer', 'min:0'],
            'variants.*.old_price' => ['nullable', 'integer', 'min:0'],
            'variants.*.stock' => ['required', 'integer', 'min:0'],
            'variants.*.is_active' => ['sometimes', 'boolean'],
            ...TagRules::rules(),
            ...MarketplaceCatalog::rules(),
        ]);
        if (array_key_exists('tags', $data) || ! $product) {
            $data['tags'] = ($data['tags'] ?? []) ?: [mb_substr($data['name'] ?? $product?->name ?? '', 0, 80)];
        }

        return $data;
    }

    private function categoryData(Request $request, ?Category $category = null): array
    {
        $data = $request->validate([
            'name' => [$category ? 'sometimes' : 'required', 'string', 'max:255'],
            'slug' => [$category ? 'sometimes' : 'required', 'string', 'max:255', Rule::unique('categories')->ignore($category)],
            'description' => ['nullable', 'string'], 'image' => ['nullable', 'string', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'], 'sort_order' => ['sometimes', 'integer', 'min:0'],
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
        ]);
        if (isset($data['parent_id'])) { $data['parent_id'] = (int) $data['parent_id']; }
        $parent = $data['parent_id'] ?? null;
        $visited = [];
        while ($parent !== null) {
            if ($parent === $category?->id || in_array($parent, $visited, true)) {
                throw ValidationException::withMessages(['parent_id' => 'دسته‌بندی نمی‌تواند زیرمجموعه خودش یا فرزندش باشد.']);
            }
            $visited[] = $parent;
            $parent = Category::query()->find($parent)?->parent_id;
        }

        return $data;
    }

    private function invalidateCatalog(): void
    {
        \App\Modules\Catalog\Application\InvalidateCatalog::handle();
    }
}
