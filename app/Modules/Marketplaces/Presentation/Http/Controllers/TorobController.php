<?php

namespace App\Modules\Marketplaces\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Marketplaces\Application\TorobToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TorobController extends Controller
{
    public function __invoke(Request $request, TorobToken $token): JsonResponse
    {
        abort_unless(config('marketplaces.torob.enabled'), 404);
        abort_unless($token->validate($request), 401);
        $validator = validator($request->all(), [
            'page' => ['sometimes', 'integer', 'min:1'], 'sort' => ['sometimes', 'in:date_added_desc,date_updated_desc,product_id_desc'],
            'cursor' => ['sometimes', 'string', 'max:200'], 'page_uniques' => ['sometimes', 'array', 'min:1', 'max:100'],
            'page_uniques.*' => ['required', 'string', 'max:200'], 'page_urls' => ['sometimes', 'array', 'min:1', 'max:100'],
            'page_urls.*' => ['required', 'string', 'max:1500'],
        ]);
        if ($validator->fails()) { return response()->json(['error' => $validator->errors()->first()], 400); }
        $data = $validator->validated();
        $byIds = isset($data['page_uniques']); $byUrls = isset($data['page_urls']);
        $single = $byIds || $byUrls;
        if (($byIds && $byUrls) || ($single && (isset($data['page']) || isset($data['cursor']) || isset($data['sort'])))
            || (! $single && ! isset($data['sort'])) || (! $single && $data['sort'] !== 'product_id_desc' && ! isset($data['page']))
            || (isset($data['cursor']) && (isset($data['page']) || $data['sort'] !== 'product_id_desc'))
            || $request->has('limit') || $request->has('size')) {
            return response()->json(['error' => 'invalid parameter combination'], 400);
        }
        $base = rtrim(config('integrations.site_url'), '/');
        // All products are included by default unless explicitly opted out of Torob.
        $products = Product::query()->where('is_active', true)->whereDoesntHave('marketplaceListings', fn ($q) => $q->where('channel', 'torob')->where('enabled', false))
            ->with(['category', 'variants' => fn ($q) => $q->where('is_active', true)])->get();
        $rows = collect();
        foreach ($products as $product) {
            foreach ($product->has_variants ? $product->variants : [null] as $variant) {
                $id = (string) $product->id.($variant ? '_'.$variant->id : '');
                $url = $base.'/product/'.rawurlencode($product->slug).($variant ? '?variant='.$variant->id : '');
                $stock = $variant?->stock ?? $product->stock;
                $images = array_filter([$product->image, ...($product->gallery ?? [])]);
                $rows->push([
                    'page_unique' => $id, 'page_url' => $url, 'product_group_id' => (string) $product->id,
                    'title' => mb_substr($product->name.($variant ? ' · '.implode(' / ', $variant->attributes) : ''), 0, 500),
                    'current_price' => $stock > 0 ? (int) ($variant?->price ?? $product->price) : 0,
                    'old_price' => (int) ($variant?->old_price ?? $product->old_price ?? $variant?->price ?? $product->price),
                    'availability' => $stock > 0, 'category_name' => $product->category?->name,
                    'image_links' => array_values(array_map(fn ($image) => str_starts_with($image, 'http') ? $image : $base.'/'.ltrim($image, '/'), $images)),
                    'spec' => (object) array_merge(collect($product->specifications ?? [])->pluck('value', 'label')->all(), $variant?->attributes ?? []),
                    'short_desc' => mb_substr($product->summary ?? '', 0, 500), 'date_added' => $product->created_at->toIso8601String(),
                    'date_updated' => $product->updated_at->toIso8601String(),
                    '_order' => [$product->id, $variant?->id ?? 0],
                ]);
            }
        }
        if ($single) { $rows = $rows->filter(fn ($row) => $byIds ? in_array($row['page_unique'], $data['page_uniques'], true) : in_array($row['page_url'], $data['page_urls'], true)); }
        else { $rows = $rows->sortByDesc(match ($data['sort']) { 'date_added_desc' => 'date_added', 'date_updated_desc' => 'date_updated', default => '_order' }); }
        $rows = $rows->values(); $total = $rows->count();
        $cursorMode = ! $single && $data['sort'] === 'product_id_desc' && ! isset($data['page']);
        $page = $data['page'] ?? 1;
        if ($cursorMode && isset($data['cursor'])) {
            $cursor = json_decode(base64_decode($data['cursor'], true) ?: '', true);
            if (! is_array($cursor) || count($cursor) !== 2 || ! is_int($cursor[0] ?? null) || ! is_int($cursor[1] ?? null)) {
                return response()->json(['error' => 'invalid cursor'], 400);
            }
            $rows = $rows->filter(fn ($row) => $row['_order'] < $cursor)->values();
        }
        $selection = $single ? $rows : $rows->slice($cursorMode ? 0 : ($page - 1) * 100, 100);
        $nextCursor = $cursorMode && $rows->count() > 100 ? base64_encode(json_encode($selection->last()['_order'])) : null;
        $selected = $selection->map(function ($row) { unset($row['_order']); return $row; })->values();

        return response()->json(['api_version' => 'torob_api_v3', 'current_page' => $cursorMode ? null : $page,
            'total' => $total, 'max_pages' => max(1, (int) ceil($total / 100)),
            'next_cursor' => $nextCursor, 'products' => $selected]);
    }
}
