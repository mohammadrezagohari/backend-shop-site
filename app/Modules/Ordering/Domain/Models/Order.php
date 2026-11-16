<?php

namespace App\Modules\Ordering\Domain\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'public_id', 'customer_name', 'phone', 'address', 'status', 'payment_method', 'payment_status', 'payment_reference', 'payment_token_hash', 'paid_at', 'subtotal', 'shipping', 'total'])]
#[Hidden(['payment_token_hash', 'notification_sent_at'])]
class Order extends Model
{
    protected function casts(): array
    {
        return ['paid_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
