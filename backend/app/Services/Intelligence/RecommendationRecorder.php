<?php

namespace App\Services\Intelligence;

use App\Models\Recommendation;
use App\Models\RecommendationEvent;
use Illuminate\Support\Facades\DB;

/**
 * The learning loop's write side — generic across every capability.
 *
 * A capability never touches this. Operational Intelligence records what it presented; the workflow
 * records what the human did; a later process records what actually happened. Because the lifecycle
 * is capability-agnostic, a capability written next year starts contributing training data the day
 * it ships, without writing a line of feedback code.
 *
 * Everything here is an INSERT. There is no update path, by design and by model guard.
 */
class RecommendationRecorder
{
    /** The pipeline itself — arbitration rules, budget, tiering. Bump when those change. */
    public const ENGINE_VERSION = 'v1';

    /**
     * Freeze a card that is about to be shown, and open its lifecycle.
     *
     * The full text is copied in rather than referenced: capabilities improve, projections are
     * rebuilt, and the answer to "why did it say that?" must remain the reasoning actually shown.
     */
    public function present(
        DecisionCard $card,
        CapabilityContext $context,
        ?int $userId = null,
        array $versions = [],
        ?\Illuminate\Support\Carbon $expiresAt = null,
    ): Recommendation {
        return DB::transaction(function () use ($card, $context, $userId, $versions, $expiresAt) {
            $recommendation = Recommendation::create([
                'capability_id'      => $card->capabilityId !== '' ? $card->capabilityId : $card->id,
                'card_id'            => $card->id,
                // WHICH GENERATION SAID THIS. Four versions that move independently, so a stored
                // recommendation can later be compared against what today's platform would produce
                // without any of it being rewritten.
                'capability_version'      => $versions['capability'] ?? 'v1',
                'engine_version'          => $versions['engine'] ?? self::ENGINE_VERSION,
                'query_layer_version'     => $versions['query_layer'] ?? null,
                'evidence_schema_version' => $versions['evidence_schema'] ?? Evidence::SCHEMA_VERSION,
                'classifier_version'      => $versions['classifier'] ?? null,
                'expires_at'         => $expiresAt,
                'subject_type'       => $context->ticket ? $context->ticket::class : null,
                'subject_id'         => $context->ticketId(),
                'vehicle_id'         => $context->vehicleId,
                'workflow_state'     => $context->workflowState,
                'tier'               => $card->tier,
                'confidence'         => $card->confidence()->value,
                'strength'           => $card->strength(),
                'observation'        => $card->observation,
                'recommendation'     => $card->recommendation,
                'reasoning'          => $card->reasoning,
                'evidence'           => $card->evidence->toArray(),
                'actions'            => $card->actions,
                'presented_to'       => $userId,
                'presented_at'       => now(),
            ]);

            $this->event($recommendation, RecommendationEvent::PRESENTED, $userId);

            return $recommendation;
        });
    }

    /** The human answered. `overridden` without a reason is accepted but noticeably less useful. */
    public function respond(
        Recommendation $recommendation,
        string $event,
        ?int $actorId = null,
        ?string $reason = null,
        array $payload = [],
    ): RecommendationEvent {
        if (! in_array($event, RecommendationEvent::RESPONSES, true)) {
            throw new \InvalidArgumentException("[$event] is not a response event.");
        }

        return $this->event($recommendation, $event, $actorId, $reason, $payload);
    }

    /**
     * What actually happened — recorded whenever it becomes knowable, which for the 90-day watch is
     * three months after anyone stopped thinking about the case.
     *
     * This is the event that turns the platform from one that recommends into one that learns, and
     * it is what makes recommendation ACCURACY (and the overridden-beats-accepted tripwire)
     * computable at all.
     */
    public function recordOutcome(
        Recommendation $recommendation,
        string $result,
        ?string $outcomeType = null,
        ?int $outcomeId = null,
        array $payload = [],
    ): RecommendationEvent {
        return $this->event(
            $recommendation,
            RecommendationEvent::OUTCOME_RECORDED,
            null,
            null,
            $payload,
            $outcomeType,
            $outcomeId,
            $result,
        );
    }

    public function viewed(Recommendation $recommendation, ?int $actorId = null): RecommendationEvent
    {
        return $this->event($recommendation, RecommendationEvent::VIEWED, $actorId);
    }

    public function expire(Recommendation $recommendation): RecommendationEvent
    {
        return $this->event($recommendation, RecommendationEvent::EXPIRED);
    }

    /**
     * Same-reason overrides of a card in the trailing 30 days — the anti-fatigue signal the
     * arbitrator uses to decay and eventually suppress a card nobody accepts.
     *
     * Scoped per actor when one is given: a card one supervisor keeps rejecting should quieten for
     * them, not for the colleague who finds it useful.
     */
    public function recentOverrides(string $cardId, ?int $actorId = null): int
    {
        return RecommendationEvent::query()
            ->where('event', RecommendationEvent::OVERRIDDEN)
            ->where('occurred_at', '>=', now()->subDays(30))
            ->when($actorId, fn ($q) => $q->where('actor_id', $actorId))
            ->whereHas('recommendation', fn ($q) => $q->where('card_id', $cardId))
            ->count();
    }

    /**
     * How many times this card has been frozen for one subject — the lifetime cap a policy applies.
     */
    public function presentationCount(string $cardId, string $subjectType, int $subjectId): int
    {
        return Recommendation::query()
            ->where('card_id', $cardId)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->count();
    }

    /**
     * The most recent recommendation of this card for one subject, whatever its state.
     *
     * Operational Intelligence needs the whole row rather than a timestamp: whether the card may be
     * shown again depends on whether it was ANSWERED, and when — which lives in its events.
     */
    public function lastFor(string $cardId, string $subjectType, int $subjectId, ?string $workflowState = null): ?Recommendation
    {
        return Recommendation::query()
            ->where('card_id', $cardId)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->when($workflowState !== null, fn ($q) => $q->where('workflow_state', $workflowState))
            ->latest('presented_at')
            ->first();
    }

    /** A closure the DecisionEngine can be handed directly. */
    public function overrideCounter(?int $actorId = null): callable
    {
        return fn (string $cardId): int => $this->recentOverrides($cardId, $actorId);
    }

    private function event(
        Recommendation $recommendation,
        string $event,
        ?int $actorId = null,
        ?string $reason = null,
        array $payload = [],
        ?string $outcomeType = null,
        ?int $outcomeId = null,
        ?string $outcomeResult = null,
    ): RecommendationEvent {
        return RecommendationEvent::create([
            'recommendation_id' => $recommendation->id,
            'event'             => $event,
            'actor_id'          => $actorId,
            'reason'            => $reason,
            'outcome_type'      => $outcomeType,
            'outcome_id'        => $outcomeId,
            'outcome_result'    => $outcomeResult,
            'payload'           => $payload !== [] ? $payload : null,
            'occurred_at'       => now(),
        ]);
    }
}
