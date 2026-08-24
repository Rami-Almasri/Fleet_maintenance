<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One thing that happened to a [[VehicleCheckRequirement]].
 *
 *   raised → attached → viewed → inspected → decided → action_created → action_completed → resolved
 *                                                          (or: cancelled | superseded | expired)
 *
 * APPEND-ONLY, ENFORCED. Update and delete both throw, exactly as [[RecommendationEvent]] does, and
 * for the same reason: this trail is the evidence that a check was answered — or that it never was.
 * A record that can be edited afterwards cannot settle "did anyone actually look at the battery?",
 * which is the question the whole mechanism exists to answer. A correction is another event.
 */
class VehicleCheckEvent extends Model
{
    /** The system raised the obligation. */
    public const RAISED = 'raised';
    /** It was attached to a live inspection ticket. */
    public const ATTACHED = 'attached';
    /** An inspector opened the ticket and saw it. */
    public const VIEWED = 'viewed';
    /** A human recorded a structured result. */
    public const INSPECTED = 'inspected';
    /** A human chose what to do about an action-bearing result. */
    public const DECIDED = 'decided';
    /** A real maintenance action was opened through the existing workflow. */
    public const ACTION_CREATED = 'action_created';
    /** That action finished. */
    public const ACTION_COMPLETED = 'action_completed';
    /** The obligation ended. */
    public const RESOLVED = 'resolved';
    public const CANCELLED = 'cancelled';
    public const SUPERSEDED = 'superseded';
    public const EXPIRED = 'expired';

    /** The events that represent a HUMAN answering. Everything else is the system moving state. */
    public const HUMAN_EVENTS = [self::INSPECTED, self::DECIDED];

    protected $fillable = [
        'vehicle_check_requirement_id', 'event', 'actor_id',
        'reason_code', 'payload', 'occurred_at',
    ];

    protected $casts = [
        'payload'     => 'array',
        'occurred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException(
                'Vehicle check events are append-only. Record a new event instead of editing this one.'
            );
        });

        static::deleting(function () {
            throw new RuntimeException(
                'Vehicle check events are append-only and cannot be deleted — "nobody ever checked it" '
                . 'is a fact this trail exists to keep.'
            );
        });
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(VehicleCheckRequirement::class, 'vehicle_check_requirement_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
