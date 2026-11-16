<?php

namespace App\Modules\Marketplaces\Application;

use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Marketplaces\Jobs\SyncProductListing;

final class MarketplaceCatalog
{
    public const CHANNELS = ['torob' => 'ترب', 'basalam' => 'باسلام', 'digikala' => 'دیجی‌کالا', 'custom' => 'پلتفرم دیگر'];

    public static function rules(): array
    {
        return [
            'marketplaces' => ['sometimes', 'array', 'max:4'],
            'marketplaces.*' => ['array:channel,enabled,external_id,settings'],
            'marketplaces.*.channel' => ['required', 'in:torob,basalam,digikala,custom', 'distinct'],
            'marketplaces.*.enabled' => ['required', 'boolean'],
            'marketplaces.*.external_id' => ['nullable', 'string', 'max:255'],
            'marketplaces.*.settings' => ['nullable', 'array:category_id,brand_id,photo_id,weight,package_weight,preparation_days,product_code,variant_mapping'],
            'marketplaces.*.settings.category_id' => ['nullable', 'integer', 'min:1'],
            'marketplaces.*.settings.brand_id' => ['nullable', 'integer', 'min:1'],
            'marketplaces.*.settings.photo_id' => ['nullable', 'integer', 'min:1'],
            'marketplaces.*.settings.weight' => ['nullable', 'numeric', 'min:0'],
            'marketplaces.*.settings.package_weight' => ['nullable', 'integer', 'min:0'],
            'marketplaces.*.settings.preparation_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'marketplaces.*.settings.product_code' => ['nullable', 'string', 'max:100'],
            'marketplaces.*.settings.variant_mapping' => ['nullable', 'array'],
        ];
    }

    public function save(Product $product, ?array $rows): void
    {
        if ($rows === null) { return; }
        $channels = [];
        foreach ($rows as $row) {
            $channels[] = $row['channel'];
            $existing = $product->marketplaceListings()->where('channel', $row['channel'])->first();
            $uncertain = $existing?->status === 'uncertain' && ! $existing->external_id && empty($row['external_id']);
            $product->marketplaceListings()->updateOrCreate(['channel' => $row['channel']], [
                'enabled' => $row['enabled'], 'external_id' => $row['external_id'] ?? null,
                'settings' => $row['settings'] ?? [], 'status' => $uncertain ? 'uncertain' : 'not_configured',
                'last_error' => $uncertain ? $existing->last_error : null, 'payload_hash' => null,
            ]);
        }
        $product->marketplaceListings()->whereNotIn('channel', $channels)->update(['enabled' => false]);
    }

    public function queue(Product $product, bool $manual = false): void
    {
        if (! $manual && ! config('marketplaces.auto_sync')) { return; }
        foreach (array_keys(self::CHANNELS) as $channel) {
            if (! config("marketplaces.$channel.enabled")) { continue; }
            $listing = $product->marketplaceListings()->firstOrCreate(['channel' => $channel], ['enabled' => true]);
            if (! $listing->enabled) { continue; }
            SyncProductListing::dispatch($listing->id)->afterCommit();
        }
    }
}
