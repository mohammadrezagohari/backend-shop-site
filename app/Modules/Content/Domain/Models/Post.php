<?php

namespace App\Modules\Content\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['title', 'slug', 'excerpt', 'body', 'image', 'views_count', 'published_at', 'tags'])]
class Post extends Model
{
    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'tags' => 'array'];
    }
}
