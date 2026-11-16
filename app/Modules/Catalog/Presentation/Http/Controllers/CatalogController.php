<?php

namespace App\Modules\Catalog\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Application\CatalogQuery;
use App\Modules\Catalog\Domain\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CatalogController extends Controller
{
    public function __construct(private readonly CatalogQuery $catalog) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:100'],
            'featured' => ['nullable', 'in:0,1'],
            'per_page' => ['nullable', 'integer', 'between:1,48'],
            'page' => ['nullable', 'integer', 'min:1'],
            'tag' => ['nullable', 'string', 'max:80'],
            'sort' => ['nullable', 'in:newest,price_asc,price_desc,popular,rating'],
        ]);

        return response()->json($this->catalog->products($filters));
    }

    public function categories(): JsonResponse
    {
        return response()->json(['data' => $this->catalog->categories()]);
    }

    public function show(Product $product): JsonResponse
    {
        abort_unless($product->is_active, 404);

        $product->increment('views_count');

        return response()->json(['data' => $product->load(['category:id,name,slug', 'variants' => fn ($query) => $query->where('is_active', true), 'comments' => fn ($query) => $query->where('is_approved', true)->latest()])]);
    }
}
