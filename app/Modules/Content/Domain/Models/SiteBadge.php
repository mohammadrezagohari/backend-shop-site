<?php

namespace App\Modules\Content\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['title', 'image_url', 'link_url', 'sort_order', 'is_active'])]
class SiteBadge extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
