<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One time we went back to the counterparty and said "this one is yours".
 *
 * A claim is separate from its warranty because the relationship is genuinely one-to-many, and
 * because a REJECTED claim is the most valuable row in this table — it is the evidence that a
 * supplier does not honour what he sells. Collapsing claims into columns on the warranty would keep
 * only the latest attempt and erase exactly that.
 *
 * `was_in_window` and `window_evidence` are FROZEN at claim time, computed by WarrantyService
 * against the odometer of that moment. They are deliberately not recomputed on read: "was it in
 * date when it failed" must not change its answer six months later when the car has done another
 * 40,000 km. That is the whole point of writing it down.
 */
class WarrantyClaim extends Model
{
    use SoftDeletes;

    public const OUTCOME_PENDING  = 'pending';
    public const OUTCOME_ACCEPTED = 'accepted';
    public const OUTCOME_REJECTED = 'rejected';
    public const OUTCOME_PARTIAL  = 'partial';
    public const OUTCOMES = [
        self::OUTCOME_PENDING, self::OUTCOME_ACCEPTED, self::OUTCOME_REJECTED, self::OUTCOME_PARTIAL,
    ];

    /** What we actually got back. 'none' is a real answer, not a missing one. */
    public const REMEDIES = ['replacement', 'repair', 'credit', 'refund', 'none'];

    /** Outcomes that are finished — anything else is still owed us an answer. */
    public const RESOLVED_OUTCOMES = [self::OUTCOME_ACCEPTED, self::OUTCOME_REJECTED, self::OUTCOME_PARTIAL];

    protected $fillable = [
        'warranty_id', 'vehicle_id', 'maintenance_id',
        'failure_description', 'claimed_on', 'claim_odometer',
        'was_in_window', 'window_evidence',
        'outcome', 'outcome_reason', 'resolved_on',
        'recovered_amount', 'currency', 'remedy',
        'created_by', 'created_by_name',
    ];

    protected $casts = [
        'claimed_on'       => 'date',
        'resolved_on'      => 'date',
        'claim_odometer'   => 'integer',
        'was_in_window'    => 'boolean',
        'recovered_amount' => 'decimal:2',
    ];

    public function warranty(): BelongsTo
    {
        return $this->belongsTo(Warranty::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function maintenance(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class);
    }

    public function scopePending(Builder $q): Builder
    {
        return $q->where('outcome', self::OUTCOME_PENDING);
    }

    /** Money actually recovered — accepted and partial claims only; a rejection recovers nothing. */
    public function scopeRecovered(Builder $q): Builder
    {
        return $q->whereIn('outcome', [self::OUTCOME_ACCEPTED, self::OUTCOME_PARTIAL]);
    }

    public function isResolved(): bool
    {
        return in_array($this->outcome, self::RESOLVED_OUTCOMES, true);
    }
}
