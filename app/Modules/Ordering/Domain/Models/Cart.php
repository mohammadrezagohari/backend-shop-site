<?php

namespace App\Modules\Ordering\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['token', 'user_id'])]
class Cart extends Model
{
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }
}
