<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One thing that happened to a recommendation.
 *
 * The lifecycle is generic on purpose — every capability, present and future, participates in
 * learning simply by producing a Decision Card. None of them implement their own feedback model.
 *
 *   created → presented → viewed → accepted | overridden | dismissed | expired → outcome_recorded
 *
 * Append-only, like the recommendation itself: a correction is another event.
 */
class RecommendationEvent extends Model
{
    public const CREATED          = 'created';
    public const PRESENTED        = 'presented';
    public const VIEWED           = 'viewed';
    public const ACCEPTED         = 'accepted';
    public const OVERRIDDEN       = 'overridden';
    public const DISMISSED        = 'dismissed';
    public const EXPIRED          = 'expired';
    public const OUTCOME_RECORDED = 'outcome_recorded';

    /** The states that represent a human answering the recommendation. */
    public const RESPONSES = [self::ACCEPTED, self::OVERRIDDEN, self::DISMISSED];

    public const RESULT_CORRECT      = 'correct';
    public const RESULT_INCORRECT    = 'incorrect';
    public const RESULT_INCONCLUSIVE = 'inconclusive';

    protected $fillable = [
        'recommendation_id', 'event', 'actor_id', 'reason',
        'outcome_type', 'outcome_id', 'outcome_result', 'payload', 'occurred_at',
    ];

    protected $casts = [
        'payload'     => 'array',
        'occurred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('Recommendation events are append-only. Record a new event instead.');
        });

        static::deleting(function () {
            throw new RuntimeException('Recommendation events are append-only and cannot be deleted.');
        });
    }

    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(Recommendation::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
