<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One correction to what a shelf costs.
 *
 * Written only by {@see \App\Services\StoreService::correctPrice()}, which is the sole path that may
 * assign `store_items.avg_unit_cost` outside a priced receipt. Keeping both the old and the new
 * figure is what makes the change explicable later — see the migration for why this is not a row in
 * the movement ledger.
 */
class StorePriceCorrection extends Model
{
    protected $fillable = [
        'store_item_id', 'old_avg_unit_cost', 'new_avg_unit_cost', 'currency',
        'reason', 'actor_id', 'actor_name', 'occurred_at',
    ];

    protected $casts = [
        'old_avg_unit_cost' => 'decimal:2',
        'new_avg_unit_cost' => 'decimal:2',
        'occurred_at'       => 'datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(StoreItem::class, 'store_item_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** How much the shelf moved — negative when the correction brought a price down. */
    public function delta(): ?float
    {
        return $this->old_avg_unit_cost === null
            ? null
            : round((float) $this->new_avg_unit_cost - (float) $this->old_avg_unit_cost, 2);
    }
}
