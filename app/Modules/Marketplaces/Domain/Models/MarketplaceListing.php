<?php

namespace App\Modules\Marketplaces\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['product_id', 'channel', 'enabled', 'external_id', 'settings', 'status', 'last_error', 'payload_hash', 'synced_at'])]
class MarketplaceListing extends Model
{
    protected function casts(): array
    {
        return ['settings' => 'array', 'enabled' => 'boolean', 'synced_at' => 'datetime'];
    }
}
