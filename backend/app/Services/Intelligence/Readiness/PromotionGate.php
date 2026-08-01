<?php

namespace App\Services\Intelligence\Readiness;

use App\Models\CapabilityPromotion;
use App\Services\Intelligence\DecisionEngine;
use App\Services\RepairIntelligence\Backtest\ComebackBacktest;
use App\Services\RepairIntelligence\Query\ProjectionRepairHistoryQuery;
use Illuminate\Support\Facades\Cache;

/**
 * Decides whether a capability may stop reasoning from a proxy — by measuring, not by waiting.
 *
 * The rule the owner set, and the one worth defending: promotion is EVIDENCE-DRIVEN, NOT
 * CALENDAR-DRIVEN. Reaching the evidence threshold buys the right to run the comparison; it does not
 * buy the promotion. The measured model must predict at least as well as the proxy it replaces.
 *
 * That last clause matters more than it looks. It is tempting to assume better-quality evidence must
 * produce a better model, and it often does not: the QC verdict is scarcer, arrives later, and
 * covers a different slice of tickets. If it predicts worse, the honest outcome is to keep the proxy
 * AND record why — which is what makes a refusal the most informative row in the table.
 *
 * Every decision is written append-only with both sides of the comparison frozen.
 */
class PromotionGate
{
    /** How much better/worse the measured model may be and still count as "at least as good". */
    private const TOLERANCE_POINTS = 2.0;

    public function __construct(
        private readonly EvidenceLedger $ledger,
        private readonly ComebackBacktest $backtest,
    ) {}

    /**
     * Is this capability currently cleared to use measured evidence?
     *
     * Cached briefly: it is read on every card evaluation, and the answer only changes when a
     * promotion run writes a new row.
     */
    public function isPromoted(string $capabilityId): bool
    {
        return Cache::remember(
            "intel:promotion:{$capabilityId}",
            now()->addMinutes(15),
            fn () => CapabilityPromotion::current($capabilityId)?->promoted ?? false,
        );
    }

    /**
     * Run the comparison and record the decision.
     *
     * @param  bool $force run even below the evidence threshold (reporting only — still recorded)
     * @return array{decision:string, promoted:bool, reason:string, proxy:?array, measured:?array}
     */
    public function evaluate(string $capabilityId = 'comeback-warning', bool $force = false, bool $persist = true): array
    {
        $requirement = $this->ledger->for($capabilityId);

        if ($requirement === null) {
            return ['decision' => 'unknown-capability', 'promoted' => false, 'reason' => "No evidence requirement registered for {$capabilityId}.", 'proxy' => null, 'measured' => null, 'provenance' => []];
        }

        // 1. Threshold — the right to run the comparison, not the promotion itself.
        //
        // Recorded like any other outcome. "We asked on this date, against this corpus, and there was
        // not enough evidence to look" is part of the history of the decision; a gate that only wrote
        // rows when it had something interesting to say would leave gaps exactly where someone later
        // wonders whether anybody was paying attention.
        if (! $requirement->isReady() && ! $force) {
            return $this->record($capabilityId, false, sprintf(
                'Below the evidence threshold: %d of %d %s. %s',
                $requirement->current, $requirement->threshold, $requirement->evidence,
                $requirement->readinessLabel() === 'never at this rate'
                    ? 'No arrivals — waiting will not fix this.'
                    : 'Projected '.$requirement->readinessLabel().'.',
            ), null, null, $requirement, $persist, 'not-yet');
        }

        // 2. Both backtests, same rule, different definition of "was it right?".
        $proxy    = $this->backtest->run(ComebackBacktest::OUTCOME_RECURRENCE);
        $measured = $this->backtest->run(ComebackBacktest::OUTCOME_VERDICT);

        // 3. A comparison on too few cases is noise wearing the costume of evidence.
        if (! $measured['sufficient']) {
            return $this->record($capabilityId, false, sprintf(
                'Measured backtest too thin to compare: %d cards fired over %d opportunities, %d positives.',
                $measured['fired'], $measured['opportunities'], $measured['positives'],
            ), $proxy, $measured, $requirement, $persist);
        }

        // 4. THE TEST. Lift is the comparable quantity — precision alone is not, because the two
        //    outcomes have different base rates and a higher precision against an easier base is not
        //    a better model.
        $delta = $measured['lift'] - $proxy['lift'];
        $ok = $delta >= 0 || abs($delta) * 100 <= self::TOLERANCE_POINTS;

        return $this->record($capabilityId, $ok, sprintf(
            '%s: measured lift %.2f× vs proxy %.2f× (%+.2f). Precision %.1f%% vs %.1f%%, on %d measured opportunities.',
            $ok ? 'Promoted' : 'Refused',
            $measured['lift'], $proxy['lift'], $delta,
            $measured['precision'], $proxy['precision'], $measured['opportunities'],
        ), $proxy, $measured, $requirement, $persist);
    }

    /**
     * The rule applied, recorded in words alongside the decision.
     *
     * Stored rather than inferred: if TOLERANCE_POINTS or the sufficiency floor changes next year, a
     * decision made under the old rule must still explain itself on its own terms.
     */
    private function decisionRule(): string
    {
        return sprintf(
            'promote iff measured_lift >= proxy_lift - %.2f AND fired >= 30 AND positives >= 15; '.
            'lift compared (not precision) because the two outcomes have different base rates',
            self::TOLERANCE_POINTS / 100,
        );
    }

    /**
     * The full provenance of an evaluation: which two models, on which dataset, by which method.
     *
     * @return array<string, string>
     */
    public function provenance(string $capabilityId = 'comeback-warning'): array
    {
        return [
            'proxy_model_version'    => $this->backtest->modelVersion(ComebackBacktest::OUTCOME_RECURRENCE),
            'measured_model_version' => $this->backtest->modelVersion(ComebackBacktest::OUTCOME_VERDICT),
            'dataset_version'        => $this->backtest->datasetVersion(),
            'backtest_version'       => ComebackBacktest::VERSION,
            'capability_version'     => $this->capabilityVersion($capabilityId),
            'query_layer_version'    => ProjectionRepairHistoryQuery::VERSION,
        ];
    }

    /**
     * The capability's own declared version, asked of the capability.
     *
     * This was a hardcoded 'v2' string. That is a quiet way to destroy the entire point of recording
     * provenance: bump ComebackCapability to v3 and every decision from then on claims to have
     * evaluated v2, so a future reader comparing two decisions sees no methodology change where there
     * was one — and the promotion history becomes confidently wrong rather than merely incomplete.
     *
     * Resolved through the container at CALL time rather than injected, on purpose. The engine holds
     * the capabilities and the capabilities hold this gate, so constructor injection would be a cycle.
     * Reading it late is the cost of keeping one source of truth for a version.
     */
    private function capabilityVersion(string $capabilityId): string
    {
        return (app(DecisionEngine::class)->capabilities()[$capabilityId] ?? null)?->version() ?? 'unregistered';
    }

    /**
     * Write the decision, always with the full provenance bundle.
     *
     * Provenance is merged FIRST and the decision fields second, so no caller can accidentally
     * override the versions with its own idea of them — and the model's creating() guard rejects the
     * row outright if any part of the bundle is missing.
     */
    private function record(
        string $capabilityId,
        bool $promoted,
        string $reason,
        ?array $proxy,
        ?array $measured,
        EvidenceRequirement $requirement,
        bool $persist,
        ?string $decision = null,
    ): array {
        $provenance = $this->provenance($capabilityId);

        if ($persist) {
            CapabilityPromotion::create($provenance + [
                'capability_id'      => $capabilityId,
                'from_basis'         => CapabilityPromotion::BASIS_PROXY,
                'to_basis'           => CapabilityPromotion::BASIS_MEASURED,
                'promoted'           => $promoted,
                'reason'             => mb_substr($reason, 0, 500),
                'decision_rule'      => mb_substr($this->decisionRule(), 0, 255),
                'proxy_metrics'      => $proxy,
                'measured_metrics'   => $measured,
                'operating_point'    => $this->backtest->operatingPoint(),
                'evidence_count'     => $requirement->current,
                'evidence_threshold' => $requirement->threshold,
                'decided_at'         => now(),
            ]);

            Cache::forget("intel:promotion:{$capabilityId}");
        }

        return [
            'decision'   => $decision ?? ($promoted ? 'promoted' : 'refused'),
            'promoted'   => $promoted,
            'reason'     => $reason,
            'proxy'      => $proxy,
            'measured'   => $measured,
            'provenance' => $provenance,
        ];
    }
}
