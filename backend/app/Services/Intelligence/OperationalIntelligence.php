<?php

namespace App\Services\Intelligence;

use App\Models\Maintenance;
use App\Models\Recommendation;
use App\Models\RecommendationEvent;
use App\Models\User;
use App\Services\Knowledge\RepairSignatureClassifier;
use App\Services\RepairIntelligence\Query\RepairHistoryQuery;

/**
 * The delivery layer — where a decision moment in the workflow meets the intelligence pipeline.
 *
 * This is the ONLY class that knows both sides. The Decision Engine knows nothing about tickets,
 * routes or users; the arbitrator knows nothing about workflow states; capabilities know nothing
 * about any of it. This binds a live ticket at a workflow state to a ranked set of cards, applies
 * the registered orchestration policies, and records what was shown so the loop can close.
 *
 * IT IS DELIBERATELY DECLARATIVE. Nothing here is capability-specific — there is no `if
 * ($capability->id() === 'comeback-warning')` and there never should be. Adding a capability is:
 * implement evaluate(), register the capability, register a policy. No orchestration code is
 * written, which is the whole point of the policy abstraction.
 *
 * Intelligence is advisory. Every path is wrapped so a failure yields no cards rather than a broken
 * dispatch screen.
 */
class OperationalIntelligence
{
    public function __construct(
        private readonly DecisionEngine $engine,
        private readonly CardArbitrator $arbitrator,
        private readonly PolicyRegistry $policies,
        private readonly RecommendationRecorder $recorder,
        private readonly RepairSignatureClassifier $classifier,
        private readonly RepairHistoryQuery $history,
    ) {}

    /**
     * The cards for this ticket, for this user, right now — gated, ranked, budgeted and recorded.
     *
     * @return array<int, array> serialised cards, each carrying its recommendation id so the user's
     *                           answer attaches to the exact text they were shown
     */
    public function forTicket(Maintenance $ticket, ?User $actor = null): array
    {
        try {
            $context = CapabilityContext::forTicket(
                ticket: $ticket,
                signatures: $this->signaturesFor($ticket),
                actorId: $actor?->id,
            );

            $permissions = $actor?->getAllPermissions()->pluck('name')->all() ?? [];

            // ORCHESTRATION GATE — applied before a capability runs at all. The engine is handed a
            // closure and never learns what it is gating on.
            $cards = $this->engine->collect(
                $context,
                fn ($capability) => $this->isDeliverable($this->policies->for($capability->id()), $context, $permissions),
            );

            // The policy owns the audience, so it is stamped on here — after the capability has
            // spoken, before arbitration filters on it. The arbitrator stays generic.
            $cards = array_map(
                fn (DecisionCard $card) => $card->withAudience($this->policies->for($card->capabilityId ?: $card->id)->roles),
                $cards,
            );

            $ranked = $this->arbitrator->arbitrate(
                $cards,
                $permissions,
                $this->recorder->overrideCounter($actor?->id),
            );

            return array_values(array_filter(array_map(
                fn (DecisionCard $card) => $this->deliver($card, $context, $actor),
                $ranked,
            )));
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * Record the human's answer against the recommendation as it was shown.
     *
     * An override without a reason is accepted — refusing it would simply teach people to type "n/a"
     * — but the reason is the single most valuable field in the loop, because it is the only place
     * the platform learns WHY it was wrong rather than merely THAT it was.
     */
    public function respond(
        Recommendation $recommendation,
        string $event,
        ?User $actor = null,
        ?string $reason = null,
        array $payload = [],
    ): RecommendationEvent {
        return $this->recorder->respond($recommendation, $event, $actor?->id, $reason, $payload);
    }

    /**
     * The versions of every layer that contributed to a recommendation made right now.
     *
     * Also the yardstick for reading an old one: `$recommendation->isStaleAgainst($this->versions($id))`
     * answers "was this produced by a generation we no longer run?".
     *
     * @return array<string, string|null>
     */
    public function versions(string $capabilityId): array
    {
        return [
            'capability'      => ($this->engine->capabilities()[$capabilityId] ?? null)?->version() ?? 'unknown',
            'engine'          => RecommendationRecorder::ENGINE_VERSION,
            'query_layer'     => $this->history->version(),
            'evidence_schema' => Evidence::SCHEMA_VERSION,
            'classifier'      => RepairSignatureClassifier::VERSION,
        ];
    }

    /**
     * Whether a capability may run and be shown here — state, role, cooldown and lifetime cap.
     *
     * @param string[] $permissions
     */
    private function isDeliverable(CapabilityPolicy $policy, CapabilityContext $context, array $permissions): bool
    {
        if (! $policy->allowsState($context->workflowState) || ! $policy->allowsActor($permissions)) {
            return false;
        }

        if ($context->ticket === null) {
            return true;
        }

        $subjectType = $context->ticket::class;
        $subjectId   = $context->ticketId();

        if ($policy->maxPresentations !== null
            && $this->recorder->presentationCount($policy->capabilityId, $subjectType, $subjectId) >= $policy->maxPresentations) {
            return false;
        }

        // COOLDOWN. Once someone has considered a warning and answered it, showing it again is
        // nagging rather than informing — and nagging erodes trust in every other card too. With no
        // cooldown configured the answer stands for good, which is the honest default for advice.
        $last = $this->recorder->lastFor($policy->capabilityId, $subjectType, $subjectId);
        $answered = $last?->response();

        if ($answered !== null) {
            $mayReturn = $policy->mayReturnAfter($answered->occurred_at);

            if ($mayReturn === null || now()->lt($mayReturn)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Freeze the card if this moment has not been recorded yet, then serialise it for the client.
     */
    private function deliver(DecisionCard $card, CapabilityContext $context, ?User $actor): ?array
    {
        $policy = $this->policies->for($card->capabilityId ?: $card->id);

        $recommendation = $this->openRecommendationFor($card, $context, $policy)
            ?? $this->recorder->present(
                card: $card,
                context: $context,
                userId: $actor?->id,
                versions: $this->versions($card->capabilityId ?: $card->id),
                expiresAt: $policy->expiresAt(),
            );

        return $card->toArray() + [
            'recommendation_id' => $recommendation->id,
            'presented_at'      => $recommendation->presented_at?->toIso8601String(),
            'expires_at'        => $recommendation->expires_at?->toIso8601String(),
            'answered'          => $recommendation->response()?->event,
        ];
    }

    /**
     * An already-frozen recommendation for this exact decision moment, if one is still live.
     *
     * PRESENT-ONCE-PER-MOMENT. Without it every page refresh mints a recommendation, and acceptance
     * rate ends up computed against a denominator made of reloads. What counts as a "moment" is the
     * policy's refresh strategy — by default the workflow state, because the same warning at
     * dispatch and at QC are two different decisions with two different possible outcomes.
     */
    private function openRecommendationFor(DecisionCard $card, CapabilityContext $context, CapabilityPolicy $policy): ?Recommendation
    {
        $moment = $policy->momentKey($context->workflowState);

        if ($context->ticketId() === null || $moment === null) {
            return null; // REFRESH_ALWAYS, or nothing to key on
        }

        $existing = $this->recorder->lastFor(
            cardId: $card->id,
            subjectType: $context->ticket::class,
            subjectId: $context->ticketId(),
            workflowState: $moment === '*' ? null : $moment,
        );

        // Expired advice is not reused — it is superseded by a fresh recommendation, and the old row
        // stays exactly as it was.
        if ($existing?->expires_at !== null && $existing?->expires_at->isPast()) {
            $this->recorder->expire($existing);

            return null;
        }

        return $existing;
    }

    /**
     * The canonical signatures in play on a LIVE ticket.
     *
     * The projection covers history; an open ticket's faults have not been projected yet, so they
     * are classified here from the same vocabulary — the rules classifier that agrees with human
     * labels 80.4% of the time. Using one classifier for both halves is what makes "this happened
     * before" a meaningful comparison rather than two systems talking past each other.
     *
     * @return string[]
     */
    private function signaturesFor(Maintenance $ticket): array
    {
        $signatures = [];

        foreach ($ticket->tasks as $task) {
            $text = trim(($task->symptom ?? '').' '.($task->notes ?? ''));

            if ($text === '') {
                continue;
            }

            $signatures = [...$signatures, ...$this->classifier->classify($text)['signatures']];
        }

        return array_values(array_unique($signatures));
    }
}
