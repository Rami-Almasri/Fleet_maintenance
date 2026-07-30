<?php

namespace App\Services\Intelligence\Capabilities;

use App\Services\Intelligence\CapabilityContext;
use App\Services\Intelligence\Contracts\IntelligenceCapability;
use App\Services\Intelligence\DecisionCard;
use App\Services\RepairIntelligence\Query\RepairHistoryQuery;
use Illuminate\Support\Carbon;

/**
 * The comeback warning — the first capability on the shared pipeline, and the card that argues
 * AGAINST the instinctive action.
 *
 * The instinct when a repair fails is to send the car to a different garage. The history says that
 * buys almost nothing (81.3% fail again at the same garage, 77.5% at a different one), which means
 * the failure is concentrated in the DIAGNOSIS. So the recommended action is a diagnostic reset,
 * and re-dispatch requires a stated reason.
 *
 * NOTE WHAT THIS CLASS DOES NOT CONTAIN: no model, no query builder, no table name, no cache key, no
 * confidence arithmetic. It asks [[RepairHistoryQuery]] two questions and turns the answers into
 * words. That is the entire job of a capability — "what does history tell us?" — and it is why this
 * file would survive the projection being replaced by a warehouse tomorrow.
 *
 * It also does not decide whether the card is shown, in what order, or how confident it sounds. The
 * Decision Engine owns the first two and the evidence owns the third.
 */
class ComebackCapability implements IntelligenceCapability
{
    public function __construct(private readonly RepairHistoryQuery $history) {}

    public function id(): string
    {
        return 'comeback-warning';
    }

    public function version(): string
    {
        return 'v1';
    }

    /**
     * Purely "do I have the inputs to answer?". The feature flag, the eligible workflow states and
     * the audience all live in this capability's registered [[CapabilityPolicy]] — a capability that
     * gated on those would be making orchestration decisions it has no view of.
     */
    public function appliesTo(CapabilityContext $context): bool
    {
        return $context->vehicleId !== null && $context->signatures !== [];
    }

    public function evaluate(CapabilityContext $context): ?DecisionCard
    {
        $asOf = $context->ticket?->out_date ? Carbon::parse($context->ticket->out_date)->toDateString() : null;

        $episodes = $this->history->findPreviousEpisodes(
            vehicleId: $context->vehicleId,
            signatures: $context->signatures,
            excludeTicketId: $context->ticketId(),
            asOf: $asOf,
        );

        if ($episodes->isEmpty()) {
            return null; // the normal case — silence costs nothing
        }

        // Worst offender first: the signature that has recurred most is the one worth interrupting for.
        $signature = collect($episodes->value)->sortByDesc(fn ($rows) => $rows->count())->keys()->first();
        $rows      = $episodes->value[$signature];

        $mostRecent  = $rows->first();
        $daysAgo     = (int) Carbon::parse($mostRecent->occurred_at)->diffInDays($asOf ? Carbon::parse($asOf) : now());
        $occurrence  = $rows->count() + 1;    // this case is the next one in the chain
        $isEscalation = $rows->count() >= 2;  // already came back once ⇒ the 56.9% case

        $fleet = $this->history->signatureReturnRate($signature);
        $rate  = $fleet->value['rate'] ?? 0.0;
        $n     = $fleet->value['n'] ?? 0;

        // The base rate carries the proxy caveat and the population size, so it is the answer the
        // card's confidence rests on; the episodes contribute the rows to drill into.
        $evidence = $fleet->evidence(
            facts: [
                'signature'       => $signature,
                'occurrence'      => $occurrence,
                'days_since_last' => $daysAgo,
                'prior_case_ids'  => $rows->pluck('maintenance_id')->all(),
                'fleet_rate'      => round($rate * 100, 1),
                'is_escalation'   => $isEscalation,
            ],
            sourceIds: $episodes->sourceIds,
        );

        return new DecisionCard(
            id: $this->id(),
            tier: DecisionCard::TIER_REWORK,
            // Left empty deliberately — the policy owns the audience. See withAudience().
            audience: [],
            observation: sprintf(
                'This is a comeback — occurrence %d of %s on this vehicle, %d day%s after the last one.',
                $occurrence,
                $signature,
                $daysAgo,
                $daysAgo === 1 ? '' : 's',
            ),
            recommendation: $isEscalation
                ? 'Escalate to supervisor review and run a diagnostic reset. Do not re-dispatch without sign-off.'
                : 'Run a diagnostic reset before dispatching, and treat the garage conversation as rework rather than new work.',
            reasoning: $isEscalation
                ? sprintf(
                    'A repair that has already come back returns AGAIN 56.9%% of the time, against 46.7%% for a first occurrence — a 1.22× escalation. '.
                    'Fleet-wide, %s returns within 90 days in %.1f%% of cases (n=%d). '.
                    'Changing garage historically moves this very little (81.3%% vs 77.5%%), so the evidence points at the diagnosis rather than the workshop.',
                    $signature, $rate * 100, $n,
                )
                : sprintf(
                    'Fleet-wide, %s returns within 90 days in %.1f%% of cases (n=%d). '.
                    'Re-dispatching without re-diagnosing is what produces a third visit: sending a repeat to a different garage fails again 77.5%% of the time, against 81.3%% at the same one.',
                    $signature, $rate * 100, $n,
                ),
            evidence: $evidence,
            actions: [
                ['label' => 'Start diagnostic reset', 'effect' => 'diagnostic_reset'],
                ['label' => 'Link to previous case',  'effect' => 'link_prior_case', 'case_ids' => $rows->pluck('maintenance_id')->all()],
                ['label' => 'Dispatch anyway',        'effect' => 'override', 'requires_reason' => true],
            ],
            // Rework prevention is the highest-ROI tier measured on this fleet; an escalation case
            // is the strongest instance of it.
            leverage: $isEscalation ? 0.95 : 0.85,
            actionable: true,
            capabilityId: $this->id(),
            // This is a LOOKUP, not a statistic: one prior case is enough to warrant the warning.
            // The fleet rate quoted alongside it carries its own sample size in the evidence block.
            minimumSample: 1,
        );
    }
}
