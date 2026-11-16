<?php

namespace App\Modules\Catalog\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'description', 'image', 'is_active', 'sort_order', 'parent_id'])]
class Category extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function descendantIds(): array
    {
        $ids = [$this->id];
        for ($pending = [$this->id]; $pending !== []; ) {
            $pending = self::query()->where('is_active', true)->whereIn('parent_id', $pending)->pluck('id')->diff($ids)->all();
            $ids = array_merge($ids, $pending);
        }

        return $ids;
    }
}
