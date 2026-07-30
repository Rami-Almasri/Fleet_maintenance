<?php

namespace App\Services\Intelligence\Capabilities;

use App\Services\Intelligence\CapabilityContext;
use App\Services\Intelligence\Contracts\IntelligenceCapability;
use App\Services\Intelligence\DecisionCard;
use App\Services\Intelligence\Readiness\PromotionGate;
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
 * ── v2, set by backtest over 26,705 outcome-verified opportunities ────────────────────────────
 * Base rate: a repair comes back 39.5% of the time. v1 fired on a 90-day look-back with any prior
 * and reached 47.6% precision — a lift of 1.20×, at 219 cards a month. That is close enough to
 * guessing to be wallpaper, and wallpaper is how a platform loses the right to interrupt anyone.
 *
 * v2 fires only on TWO VISITS FOR THE SAME FAULT INSIDE A FORTNIGHT, on signatures where a prior
 * occurrence is actually predictive: 52 cards a month, 55.5% precision, lift 1.40×. Recall drops to
 * 12.4% and that is the deliberate trade — a card nobody reads catches nothing.
 *
 * The window and exclusions live in config (`features.intelligence.comeback`) so the operating point
 * can be re-derived from the backtest without a deploy.
 *
 * ── The proxy, and how it ends ────────────────────────────────────────────────────────────────
 * "Came back" is not "the repair failed" — a car returning may be a botched repair, or a second
 * unrelated fault in the same system. That conflation is what caps this card at moderate confidence
 * no matter how it is tuned. The `repair_inspections` gate now records a real per-fault verdict, so
 * once enough have accumulated the card promotes itself off the proxy automatically. Nobody has to
 * remember to flip it; it reads its own sample size.
 *
 * NOTE WHAT THIS CLASS DOES NOT CONTAIN: no model, no query builder, no table name, no cache key, no
 * confidence arithmetic. It asks questions and turns answers into words.
 */
class ComebackCapability implements IntelligenceCapability
{
    public function __construct(
        private readonly RepairHistoryQuery $history,
        private readonly PromotionGate $promotions,
    ) {}

    public function id(): string
    {
        return 'comeback-warning';
    }

    /** Bumped from v1: the firing rule changed, so recommendations from the two must be comparable. */
    public function version(): string
    {
        return 'v2';
    }

    /**
     * Purely "do I have the inputs to answer?". The feature flag, the eligible workflow states and
     * the audience all live in this capability's registered [[CapabilityPolicy]].
     */
    public function appliesTo(CapabilityContext $context): bool
    {
        return $context->vehicleId !== null && $context->signatures !== [];
    }

    public function evaluate(CapabilityContext $context): ?DecisionCard
    {
        $window    = (int) config('features.intelligence.comeback.window_days', 14);
        $minPriors = (int) config('features.intelligence.comeback.min_priors', 1);
        $excluded  = (array) config('features.intelligence.comeback.excluded_signatures', []);

        // Signatures where recurrence measurably means nothing — a service interval, a symptom, the
        // classifier's fallback bucket, or a pattern the backtest showed to be anti-predictive.
        $signatures = array_values(array_diff($context->signatures, $excluded));

        if ($signatures === []) {
            return null;
        }

        $asOf = $context->ticket?->out_date ? Carbon::parse($context->ticket->out_date)->toDateString() : null;

        $episodes = $this->history->findPreviousEpisodes(
            vehicleId: $context->vehicleId,
            signatures: $signatures,
            excludeTicketId: $context->ticketId(),
            asOf: $asOf,
            windowDays: $window,
        );

        if ($episodes->isEmpty()) {
            return null; // the normal case — silence costs nothing
        }

        // Worst offender first: the signature that has recurred most is the one worth interrupting for.
        $signature = collect($episodes->value)->sortByDesc(fn ($rows) => $rows->count())->keys()->first();
        $rows      = $episodes->value[$signature];

        if ($rows->count() < $minPriors) {
            return null;
        }

        $mostRecent   = $rows->first();
        $daysAgo      = (int) Carbon::parse($mostRecent->occurred_at)->diffInDays($asOf ? Carbon::parse($asOf) : now());
        $occurrence   = $rows->count() + 1;
        $isEscalation = $rows->count() >= 2;
        $priorIds     = $rows->pluck('maintenance_id')->all();

        // ── Was the previous repair actually signed off as fixed? ────────────────────────────────
        // If so this is not "the fault reappeared", it is "a repair a human passed has failed" — a
        // materially stronger claim, and a different conversation with the garage.
        $verdicts     = $this->history->repairVerdictsFor($priorIds);
        $wasSignedOff = in_array('fixed', (array) ($verdicts->isEmpty() ? [] : $verdicts->value), true);

        // The fleet base rate the card quotes. MUST use the same window the card fired on — a card
        // may never cite a statistic it was not computed the same way as.
        // PROMOTION IS EVIDENCE-DRIVEN, NOT CALENDAR-DRIVEN — and not sample-size-driven either.
        // Reaching the verdict threshold only buys the right to run the comparison; the card may use
        // measured evidence when a recorded backtest says it predicts at least as well as the proxy
        // it replaces. Better-quality evidence that predicts worse is still worse.
        $verified    = $this->history->verifiedFailureRate($signature);
        $useVerified = $verified->sampleSize > 0 && $this->promotions->isPromoted($this->id());

        $fleet = $useVerified ? $verified : $this->history->signatureReturnRate($signature, $window);
        $rate  = $fleet->value['rate'] ?? 0.0;
        $n     = $fleet->value['n'] ?? 0;

        $evidence = $fleet->evidence(
            facts: [
                'signature'        => $signature,
                'occurrence'       => $occurrence,
                'days_since_last'  => $daysAgo,
                'prior_case_ids'   => $priorIds,
                'fleet_rate'       => round($rate * 100, 1),
                'is_escalation'    => $isEscalation,
                'window_days'      => $window,
                'basis'            => $useVerified ? 'verified QC verdicts' : 'return rate (proxy)',
                'prior_signed_off' => $wasSignedOff,
            ],
            sourceIds: $episodes->sourceIds,
        );

        return new DecisionCard(
            id: $this->id(),
            tier: DecisionCard::TIER_REWORK,
            // Left empty deliberately — the policy owns the audience. See withAudience().
            audience: [],
            observation: $wasSignedOff
                ? sprintf(
                    'The last %s repair on this vehicle was signed off as fixed, and the fault is back %d day%s later.',
                    $signature, $daysAgo, $daysAgo === 1 ? '' : 's',
                )
                : sprintf(
                    'Same fault, second visit inside %d days — occurrence %d of %s on this vehicle, %d day%s after the last one.',
                    $window, $occurrence, $signature, $daysAgo, $daysAgo === 1 ? '' : 's',
                ),
            recommendation: $isEscalation
                ? 'Escalate to supervisor review and run a diagnostic reset. Do not re-dispatch without sign-off.'
                : 'Run a diagnostic reset before dispatching, and treat the garage conversation as rework rather than new work.',
            reasoning: $this->reasoning($signature, $rate, $n, $window, $isEscalation, $useVerified, $wasSignedOff),
            evidence: $evidence,
            actions: [
                ['label' => 'Start diagnostic reset', 'effect' => 'diagnostic_reset'],
                ['label' => 'Link to previous case',  'effect' => 'link_prior_case', 'case_ids' => $priorIds],
                ['label' => 'Dispatch anyway',        'effect' => 'override', 'requires_reason' => true],
            ],
            // Rework prevention is the highest-ROI tier measured on this fleet; an escalation case
            // is the strongest instance of it.
            leverage: $isEscalation ? 0.95 : 0.85,
            actionable: true,
            capabilityId: $this->id(),
            // This is a LOOKUP, not a statistic: the priors ARE the finding. The fleet rate quoted
            // alongside carries its own sample size in the evidence block.
            minimumSample: 1,
        );
    }

    /**
     * The paragraph that has to convince a supervisor. It states the measured rate, then answers the
     * objection they are actually about to raise — "fine, I'll send it somewhere else".
     */
    private function reasoning(
        string $signature,
        float $rate,
        int $n,
        int $window,
        bool $isEscalation,
        bool $useVerified,
        bool $wasSignedOff,
    ): string {
        $basis = $useVerified
            ? sprintf('Of %s repairs inspected at the QC gate, %.1f%% were found not actually fixed (n=%d).', $signature, $rate * 100, $n)
            : sprintf('Fleet-wide, %s returns within %d days in %.1f%% of cases (n=%d).', $signature, $window, $rate * 100, $n);

        $signedOff = $wasSignedOff
            ? ' The previous repair was inspected and passed, so this is a failed repair rather than a new complaint.'
            : '';

        if ($isEscalation) {
            return $basis.$signedOff.
                ' A repair that has already come back returns AGAIN 56.9% of the time, against 46.7% for a first occurrence — a 1.22× escalation.'.
                ' Changing garage historically moves this very little (81.3% vs 77.5%), so the evidence points at the diagnosis rather than the workshop.';
        }

        return $basis.$signedOff.
            ' Two visits for the same fault inside a fortnight is what precedes a third: sending a repeat to a different garage fails again 77.5% of the time, against 81.3% at the same one.';
    }
}
