<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * One recommendation the platform made, frozen at the moment it made it.
 *
 * IMMUTABLE BY CONSTRUCTION. The model throws on update and delete, because a recommendation is a
 * historical observation: it records what the platform said, on what evidence, with what confidence,
 * on a given day. When Version 2 of a capability disagrees with Version 1, both must remain
 * explainable — and that is impossible if the row was edited in place.
 *
 * Anything that "changes" is a new RecommendationEvent, never a mutation here.
 */
class Recommendation extends Model
{
    protected $fillable = [
        'capability_id', 'card_id', 'capability_version', 'engine_version',
        'query_layer_version', 'evidence_schema_version', 'classifier_version',
        'subject_type', 'subject_id', 'vehicle_id', 'workflow_state',
        'tier', 'confidence', 'strength',
        'observation', 'recommendation', 'reasoning', 'evidence', 'actions',
        'presented_to', 'presented_at', 'expires_at',
    ];

    protected $casts = [
        'evidence'     => 'array',
        'actions'      => 'array',
        'presented_at' => 'datetime',
        'expires_at'   => 'datetime',
        'tier'         => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException(
                'Recommendations are append-only. Record a RecommendationEvent instead of editing the recommendation.'
            );
        });

        static::deleting(function () {
            throw new RuntimeException(
                'Recommendations are append-only. A superseded recommendation is expired via an event, never deleted.'
            );
        });
    }

    public function events(): HasMany
    {
        return $this->hasMany(RecommendationEvent::class)->orderBy('occurred_at');
    }

    /** The response the user actually gave, if they have given one yet. */
    public function response(): ?RecommendationEvent
    {
        return $this->events()
            ->whereIn('event', [
                RecommendationEvent::ACCEPTED,
                RecommendationEvent::OVERRIDDEN,
                RecommendationEvent::DISMISSED,
            ])
            ->latest('occurred_at')
            ->first();
    }

    public function outcome(): ?RecommendationEvent
    {
        return $this->events()->where('event', RecommendationEvent::OUTCOME_RECORDED)->latest('occurred_at')->first();
    }

    /**
     * Which generation of the platform produced this — the four independently-moving versions.
     *
     * @return array<string, string|null>
     */
    public function versions(): array
    {
        return [
            'capability'      => $this->capability_version,
            'engine'          => $this->engine_version,
            'query_layer'     => $this->query_layer_version,
            'evidence_schema' => $this->evidence_schema_version,
            'classifier'      => $this->classifier_version,
        ];
    }

    /**
     * Was this produced by a generation the platform no longer runs?
     *
     * The cheap half of "would this be different today?" — a match is not proof the answer would be
     * identical (the DATA has moved on too), but a mismatch is proof the LOGIC has, which is what
     * makes a recommendation worth re-examining rather than trusting at face value.
     *
     * @param array<string, string|null> $current
     */
    public function isStaleAgainst(array $current): bool
    {
        foreach ($current as $key => $version) {
            if (($this->versions()[$key] ?? null) !== $version) {
                return true;
            }
        }

        return false;
    }
}
