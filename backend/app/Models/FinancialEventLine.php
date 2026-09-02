<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One postable line of a {@see FinancialEvent} — a pointer at the operational row, plus what Odoo needs.
 *
 * See the create_financial_event_lines migration for why the figures are stored rather than summed live
 * (they are frozen when the event is sent, because what Odoo was told is history).
 */
class FinancialEventLine extends Model
{
    protected $table = 'financial_event_lines';

    /** A physical part. Needs an Odoo product mapping. */
    public const KIND_PART = 'part';
    /** Garage labour — hours × rate. */
    public const KIND_LABOR = 'labor';
    /** A service billed as itself (a tow, a wash, a registration fee). No catalogued part. */
    public const KIND_SERVICE = 'service';

    public const KINDS = [self::KIND_PART, self::KIND_LABOR, self::KIND_SERVICE];

    protected $fillable = [
        'financial_event_id',
        'origin_type', 'origin_id',
        'component_catalog_id',
        'kind', 'description', 'quantity', 'uom', 'unit_price', 'line_total',
        'odoo_product_id', 'odoo_product_ref',
    ];

    protected $casts = [
        'quantity'        => 'decimal:2',
        'unit_price'      => 'decimal:2',
        'line_total'      => 'decimal:2',
        'odoo_product_id' => 'integer',
    ];

    protected static function booted(): void
    {
        // Same discipline as MaintenanceLineItem: the derived figure is kept honest on every write so
        // callers only ever set the raw inputs, and a line's total can never disagree with its parts.
        static::saving(function (self $line) {
            $line->line_total = round((float) $line->quantity * (float) $line->unit_price, 2);
        });
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(FinancialEvent::class, 'financial_event_id');
    }

    /** The operational row this line IS — usually a MaintenanceLineItem. Null for a plain service. */
    public function origin(): MorphTo
    {
        return $this->morphTo();
    }

    public function catalogPart(): BelongsTo
    {
        return $this->belongsTo(ComponentCatalog::class, 'component_catalog_id');
    }

    /**
     * Does this line have to name an Odoo product before it can be posted?
     *
     * Only a PART does. Labour and services post against the expense account directly, which is how Odoo
     * itself records a charge that is not a catalogue item — requiring a product for them would block
     * every tow and every hour of garage time behind a fabricated product record.
     */
    public function requiresProductMapping(): bool
    {
        return $this->kind === self::KIND_PART;
    }
}
