<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Phone the customer and get the car back" — the operational task a recall produces.
 *
 * The whole job is a conversation: reach the customer, explain that the car is about to run past
 * what its oil is good for, agree a day to bring it in. There is no vehicle movement to plan here
 * and no driver to dispatch — see the migration for why that is deliberate.
 *
 * Anchored to the ContractOilDecision that ordered it (one task per decision, enforced by a unique
 * index). The decision is the judgement; this is the follow-up on it.
 */
class OilRecallTask extends Model
{
    use HasFactory;

    /** Raised, nobody has spoken to the customer yet. */
    public const STATUS_OPEN = 'open';

    /** Customer reached; a return is being arranged. */
    public const STATUS_CONTACTED = 'contacted';

    /** Done with — the car is back, or the return is firmly agreed and the ticket will take over. */
    public const STATUS_DONE = 'done';

    /** The recall was revised (usually to "do it on return"), so the call is no longer wanted. */
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_CONTACTED, self::STATUS_DONE, self::STATUS_CANCELLED];

    /** Still needing a human: raised or part-way through. */
    public const OPEN_STATUSES = [self::STATUS_OPEN, self::STATUS_CONTACTED];

    /**
     * WHY the car is being recalled. A code, not a sentence — the engine never emits English, so the
     * same task can be read in either language and the wording can change without a migration.
     */
    public const REASON_OIL_TOLERANCE = 'oil_tolerance_exceeded_before_return';

    protected $fillable = [
        'contract_oil_decision_id',
        'contract_id',
        'vehicle_id',
        'status',
        'reason_code',
        'customer_reading',
        'customer_reading_on',
        'oil_limit',
        'allowed_max',
        'expected_return_odometer',
        'remaining_days',
        'created_by',
        'created_by_name',
        'decided_at',
        'assigned_user_ids',
        'claimed_by',
        'claimed_at',
        'note',
        'outcome_note',
        'completed_at',
        'completed_by',
    ];

    protected $casts = [
        'customer_reading'         => 'integer',
        'customer_reading_on'      => 'date',
        'oil_limit'                => 'integer',
        'allowed_max'              => 'integer',
        'expected_return_odometer' => 'integer',
        'remaining_days'           => 'integer',
        'assigned_user_ids'        => 'array',
        'decided_at'               => 'datetime',
        'claimed_at'               => 'datetime',
        'completed_at'             => 'datetime',
    ];

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', self::OPEN_STATUSES);
    }

    public function decision(): BelongsTo
    {
        return $this->belongsTo(ContractOilDecision::class, 'contract_oil_decision_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function claimedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /** How far past its allowance the car is heading — the number the call is really about. */
    public function overToleranceKm(): ?int
    {
        if ($this->expected_return_odometer === null || $this->allowed_max === null) {
            return null;
        }

        return max(0, $this->expected_return_odometer - $this->allowed_max);
    }
}
