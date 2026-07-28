<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A supplier's bid on one RFQ line (Phase 2, blueprint §3c) — the multi-supplier comparison substrate.
 * "Cheapest / fastest / best value" are DERIVED from these rows at read time, never stored.
 */
class SupplierQuote extends Model
{
    protected $table = 'supplier_quotes';

    public const STATUS_PENDING   = 'pending';   // invited, awaiting the supplier's bid
    public const STATUS_SUBMITTED = 'submitted'; // bid received
    public const STATUS_SELECTED  = 'selected';  // won this line
    public const STATUS_DECLINED  = 'declined';  // not chosen
    public const STATUSES = [self::STATUS_PENDING, self::STATUS_SUBMITTED, self::STATUS_SELECTED, self::STATUS_DECLINED];

    protected $fillable = [
        'rfq_line_id', 'vendor_id',
        'unit_price', 'quantity', 'currency',
        'lead_time_days', 'expected_delivery_date',
        'status', 'notes',
        'submitted_by', 'submitted_by_name', 'submitted_at',
    ];

    protected $casts = [
        'unit_price'             => 'decimal:2',
        'quantity'               => 'decimal:2',
        'lead_time_days'         => 'integer',
        'expected_delivery_date' => 'date',
        'submitted_at'           => 'datetime',
    ];

    public function line(): BelongsTo
    {
        return $this->belongsTo(RfqLine::class, 'rfq_line_id');
    }

    /** The supplier that made this bid (a Vendor row, type parts_supplier). */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }
}
