<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ONE FIGURE on an accident case, with its certainty and its bearer both stated.
 *
 * Rows are never edited and never deleted. A correction supersedes its predecessor, which stays on
 * the ledger and stays readable — because "the garage said 12,000 and the insurer approved 7,400" is
 * the argument, and a schema that overwrites can only ever remember the end of it.
 *
 * @see the create_accident_financial_entries_table migration for the full reasoning.
 */
class AccidentFinancialEntry extends Model
{
    // ── PHASE: how certain the figure is. Never sum across these. ─────────────────────────────
    public const PHASE_ESTIMATE         = 'estimate';
    public const PHASE_REVISED_ESTIMATE = 'revised_estimate';
    public const PHASE_APPROVED         = 'approved';   // what the insurer agreed to
    public const PHASE_ACTUAL           = 'actual';     // what the repair actually cost
    public const PHASE_PAID             = 'paid';       // what has changed hands
    public const PHASES = [
        self::PHASE_ESTIMATE, self::PHASE_REVISED_ESTIMATE,
        self::PHASE_APPROVED, self::PHASE_ACTUAL, self::PHASE_PAID,
    ];

    // ── PARTY: who bears it. ──────────────────────────────────────────────────────────────────
    public const PARTY_INSURANCE   = 'insurance';
    public const PARTY_CUSTOMER    = 'customer';
    public const PARTY_COMPANY     = 'company';
    public const PARTY_OTHER_PARTY = 'other_party';
    /** The excess. Its own party because it is the one amount an approval always leaves with us. */
    public const PARTY_DEDUCTIBLE  = 'deductible';
    /** Not a gap — a decision nobody has taken yet, carried so no total can quietly omit it. */
    public const PARTY_UNRESOLVED  = 'unresolved';
    public const PARTIES = [
        self::PARTY_INSURANCE, self::PARTY_CUSTOMER, self::PARTY_COMPANY,
        self::PARTY_OTHER_PARTY, self::PARTY_DEDUCTIBLE, self::PARTY_UNRESOLVED,
    ];

    protected $fillable = [
        'accident_case_id', 'phase', 'party', 'amount', 'currency', 'note',
        'maintenance_id', 'vehicle_document_id', 'external_ref',
        'recorded_by', 'recorded_by_name', 'recorded_at',
    ];

    protected $casts = [
        'amount'        => 'decimal:2',
        'recorded_at'   => 'datetime',
        'superseded_at' => 'datetime',
    ];

    public function accidentCase(): BelongsTo
    {
        return $this->belongsTo(AccidentCase::class);
    }

    public function maintenance(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class);
    }

    /** The document that proves it — the insurer's decision letter, the invoice, the receipt. */
    public function document(): BelongsTo
    {
        return $this->belongsTo(VehicleDocument::class, 'vehicle_document_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** The figure that replaced this one. Null while this IS the figure. */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_entry_id');
    }

    /** The current picture — what the breakdown is built from. */
    public function scopeLive(Builder $q): Builder
    {
        return $q->whereNull('superseded_at');
    }

    public function isLive(): bool
    {
        return $this->superseded_at === null;
    }
}
