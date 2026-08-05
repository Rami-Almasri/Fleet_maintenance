<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a person decided about a rental that will finish past its oil tolerance — Evidence class
 * **J** (a judgement; the numbers it was made against are class P, and are snapshotted here).
 *
 * Written by OilChangeProjectionService::decide() from the Oil Follow-up board, and closed out by
 * settleOnReturn() when the rental ends and the owed oil change becomes a real ticket.
 */
class ContractOilDecision extends Model
{
    use HasFactory;

    /** Get the car back before it exceeds the safe tolerance. */
    public const DECISION_RECALL = 'recall';

    /** Accept the overrun; service it the moment the contract closes. */
    public const DECISION_DEFER = 'defer';

    public const DECISIONS = [self::DECISION_RECALL, self::DECISION_DEFER];

    protected $fillable = [
        'contract_id',
        'vehicle_id',
        'decision',
        'anchor_reading_id',
        'oil_limit',
        'allowed_max',
        'expected_return_odometer',
        'remaining_days',
        'decided_by',
        'decided_by_name',
        'note',
        'is_auto',
        'settled_at',
        'settled_ticket_id',
    ];

    protected $casts = [
        'oil_limit'                => 'integer',
        'allowed_max'              => 'integer',
        'expected_return_odometer' => 'integer',
        'remaining_days'           => 'integer',
        'is_auto'                  => 'boolean',
        'settled_at'               => 'datetime',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** The routine ticket this decision finally became, once the car came back. */
    public function settledTicket(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class, 'settled_ticket_id');
    }

    /** Still owed: nobody has turned it into a ticket yet. */
    public function isOpen(): bool
    {
        return $this->settled_at === null;
    }
}
