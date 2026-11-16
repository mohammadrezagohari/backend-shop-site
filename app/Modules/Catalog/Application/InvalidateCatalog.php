<?php

namespace App\Modules\Catalog\Application;

use Illuminate\Support\Facades\Cache;

final class InvalidateCatalog
{
    public static function handle(): void
    {
        // Queries use version 1 when the counter is absent; the first mutation must advance to 2.
        Cache::add('catalog:version', 1);
        Cache::increment('catalog:version');
        Cache::forget('reporting:dashboard');
    }
}
