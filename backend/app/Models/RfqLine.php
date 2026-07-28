<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One RFQ line — the bridge that makes an RFQ single- or multi-part (Phase 2, blueprint §3b). Each line
 * sources exactly one {@see PartRequest}; award happens PER LINE ({@see awardedQuote()}), so an RFQ can be
 * split across suppliers. The awarded_quote_id link is app-owned (no DB FK — it would be circular with
 * supplier_quotes.rfq_line_id).
 */
class RfqLine extends Model
{
    protected $table = 'rfq_lines';

    protected $fillable = [
        'part_rfq_id', 'part_request_id', 'quantity',
        'awarded_quote_id', 'awarded_by', 'awarded_by_name', 'awarded_at',
    ];

    protected $casts = [
        'quantity'   => 'decimal:2',
        'awarded_at' => 'datetime',
    ];

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(PartRfq::class, 'part_rfq_id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(PartRequest::class, 'part_request_id');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(SupplierQuote::class);
    }

    /** The winning bid for this line (app-set; no DB FK). */
    public function awardedQuote(): BelongsTo
    {
        return $this->belongsTo(SupplierQuote::class, 'awarded_quote_id');
    }
}
