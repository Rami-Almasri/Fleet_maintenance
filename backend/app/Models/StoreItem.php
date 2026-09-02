<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One shelf in the storehouse: a part TYPE we keep in stock, and how many we hold right now.
 *
 * WRITE DISCIPLINE. `qty_on_hand`, `avg_unit_cost` and `last_unit_cost` are deliberately NOT
 * fillable. They are the running totals of the movement ledger and are assigned only inside
 * {@see \App\Services\StoreService}, in a transaction that locks this row and writes the matching
 * {@see StoreMovement}. A mass-assigned quantity is a stock level with no evidence behind it —
 * exactly the drift this table exists to prevent.
 */
class StoreItem extends Model
{
    protected $fillable = [
        'component_catalog_id', 'stock_key', 'part_name', 'part_name_key', 'part_number',
        'category_key', 'specs', 'min_qty', 'currency', 'location', 'notes', 'is_active',
    ];

    protected $casts = [
        // What is actually on the shelf — two shelves of "battery" that differ only in Ah are two
        // different parts to the person collecting one. @see \App\Support\PartSpecs
        'specs'          => 'array',
        'qty_on_hand'    => 'decimal:2',
        'min_qty'        => 'decimal:2',
        'avg_unit_cost'  => 'decimal:2',
        'last_unit_cost' => 'decimal:2',
        'is_active'      => 'boolean',
    ];

    /**
     * The identity of a shelf, derived and never typed — 'cat:12' for a catalogued part, else
     * 'name:oil filter' from the normalised wording.
     *
     * The two rungs mirror {@see \App\Services\PartIdentityService} exactly: a human's pick from the
     * catalog outranks any spelling, so a part with a catalog id can only ever have ONE shelf, no
     * matter how many ways its name is written.
     */
    public static function keyFor(?int $catalogId, ?string $partNameKey): string
    {
        return $catalogId ? "cat:{$catalogId}" : 'name:' . (string) $partNameKey;
    }

    public function catalogPart(): BelongsTo
    {
        return $this->belongsTo(ComponentCatalog::class, 'component_catalog_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StoreMovement::class, 'store_item_id');
    }

    public function stockRequests(): HasMany
    {
        return $this->hasMany(StoreStockRequest::class, 'store_item_id');
    }

    /** Anything we actually hold — the list a technician looking for a part wants to see. */
    public function scopeInStock(Builder $q): Builder
    {
        return $q->where('qty_on_hand', '>', 0);
    }

    /**
     * At or below its reorder level — the shelves worth restocking.
     *
     * A shelf with NO min_qty is excluded on purpose: nobody has said what "enough" means for it, so
     * calling it low would be the system inventing a threshold and then alerting on its own guess.
     */
    public function scopeLow(Builder $q): Builder
    {
        return $q->whereNotNull('min_qty')->whereColumn('qty_on_hand', '<=', 'min_qty');
    }

    public function isLow(): bool
    {
        return $this->min_qty !== null && (float) $this->qty_on_hand <= (float) $this->min_qty;
    }

    /** What the shelf is worth at the average we paid — null while no receipt has priced it. */
    public function stockValue(): ?float
    {
        return $this->avg_unit_cost === null
            ? null
            : round((float) $this->qty_on_hand * (float) $this->avg_unit_cost, 2);
    }
}
