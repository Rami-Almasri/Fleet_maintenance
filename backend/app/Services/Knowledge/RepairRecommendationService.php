<?php

namespace App\Services\Knowledge;

use Illuminate\Support\Carbon;

/**
 * Layer 4 — the composer. Turns the tiered cohort (RepairHistoryQueryService) into the single actionable
 * answer the UI shows at fault registration: likely cause · best garage · expected parts / cost / duration
 * · recurrence risk · a HONEST confidence · and the evidence + "why" behind it.
 *
 * P0 is self-contained: the suggested garage, cost and duration are computed from the SAME matched
 * repairs we already surface (RepairCohortStats), so the recommendation is fully explainable to the rows
 * in the panel. (GarageRecommendationService can be layered in as a second opinion in a later phase.)
 * Money is always returned; the frontend hides it behind SHOW_FINANCIALS like every other cost surface.
 */
class RepairRecommendationService
{
    public function __construct(
        private RepairHistoryQueryService $history,
        private RepairCohortStats $stats,
        private ConfidenceScorer $scorer,
    ) {
    }

    /**
     * @return array<string,mixed> the full RepairIntelligence envelope
     */
    public function forFault(SimilarRepairQuery $q): array
    {
        $result = $this->history->similarRepairs($q);
        $cohort = $result->flat;

        $base = [
            'query' => [
                'vehicle_id'   => $q->vehicleId,
                'make'         => $q->make,
                'model'        => $q->model,
                'category_key' => $q->categoryKey,
                'symptom'      => $q->symptom,
                'matched_on'   => $result->matchedOn,
            ],
            'has_history' => $result->hasHistory(),
            'sample_size' => $result->sampleSize(),
        ];

        if (! $result->hasHistory()) {
            return $base + [
                'confidence'      => (new ConfidenceScore(0, ConfidenceScore::LOW, ['No comparable repairs yet']))->toArray(),
                'recommendation'  => null,
                'similar_repairs' => ['tiers' => $result->tiers],
                'evidence'        => [],
                'why'             => ['No comparable repairs in fleet history yet'],
            ];
        }

        // ── Aggregates over the full matched cohort ─────────────────────────────────────────────────
        $cause     = $this->stats->modal($cohort, 'root_cause');
        $garages   = $this->stats->garageBreakdown($cohort);
        $parts     = $this->stats->topParts($cohort, (int) config('knowledge.retrieval.expected_parts_limit', 5));
        $costBand  = $this->stats->band($cohort, 'total_cost');
        $durBand   = $this->stats->band($cohort, 'duration_days');
        $recRate   = $this->stats->recurrenceRate($cohort);
        $outcomes  = $this->stats->outcomeCounts($cohort);

        // ── Confidence ──────────────────────────────────────────────────────────────────────────────
        $confidence = $this->scorer->scoreFromConfig(new ConfidenceInputs(
            sampleSize: $result->sampleSize(),
            bestTier: $result->bestTier,
            verifiedFixed: $outcomes['verified_fixed'],
            fixed: $outcomes['fixed'],
            failed: $outcomes['failed'],
            outcomeUnknown: $outcomes['unknown'],
            recurrenceRate: $recRate,
            daysSinceNewest: $this->daysSinceNewest($cohort),
            completeness: $this->stats->completeness($cohort),
        ));

        $topGarage = $garages[0] ?? null;

        return $base + [
            'statistics' => [
                'sample_size'      => $result->sampleSize(),
                'success_rate'     => $this->stats->successRate($cohort),
                'average_duration' => $this->stats->mean($cohort, 'duration_days'),
                'average_cost'     => $this->stats->mean($cohort, 'total_cost'),
                'recurrence_rate'  => $recRate,
            ],
            'confidence'     => $confidence->toArray(),
            'recommendation' => [
                'likely_cause'      => $cause['value'] ? ['value' => $cause['value'], 'share' => $cause['share']] : null,
                'suggested_garage'  => $topGarage ? [
                    'vendor_id'         => $topGarage['vendor_id'],
                    'name'              => $topGarage['garage'],
                    'jobs'              => $topGarage['jobs'],
                    'success_rate'      => $topGarage['success_rate'],
                    'avg_cost'          => $topGarage['avg_cost'],
                    'avg_duration_days' => $topGarage['avg_duration_days'],
                    'recurrences'       => $topGarage['recurrences'],
                ] : null,
                'expected_parts'    => $parts,
                'expected_cost'     => ['p25' => $costBand['p25'], 'median' => $costBand['median'], 'p75' => $costBand['p75'], 'n' => $costBand['n'], 'currency' => 'AED'],
                'expected_duration' => ['p25' => $durBand['p25'], 'median' => $durBand['median'], 'p75' => $durBand['p75'], 'n' => $durBand['n']],
                'recurrence_risk'   => $this->recurrenceRisk($recRate),
                'garage_options'    => $garages,
            ],
            'similar_repairs' => ['tiers' => $result->tiers],
            'evidence'        => $this->evidence($cohort),
            'why'             => $this->why($result, $cause, $topGarage, $parts, $recRate),
        ];
    }

    /** Risk band from the cohort's recurrence rate. */
    public function recurrenceRisk(float $rate): string
    {
        if ($rate < 0.10) {
            return 'low';
        }
        return $rate < 0.34 ? 'medium' : 'high';
    }

    /** Days since the newest repair in the cohort (null if none carry a date). */
    private function daysSinceNewest(array $cohort): ?int
    {
        $newest = null;
        foreach ($cohort as $r) {
            $d = $r['resolved_at'] ?? null;
            if ($d && ($newest === null || $d > $newest)) {
                $newest = $d;
            }
        }
        if ($newest === null) {
            return null;
        }
        return (int) Carbon::parse($newest)->startOfDay()->diffInDays(Carbon::now()->startOfDay());
    }

    /**
     * The top comparable repairs as the "why" evidence (strongest tier first, then most recent).
     *
     * @return array<int,array<string,mixed>>
     */
    private function evidence(array $cohort): array
    {
        $rows = $cohort;
        usort($rows, function ($a, $b) {
            return [$a['tier'], strcmp((string) $b['resolved_at'], (string) $a['resolved_at'])] <=> [$b['tier'], 0];
        });
        $limit = (int) config('knowledge.retrieval.evidence_limit', 6);

        return array_map(fn ($r) => [
            'maintenance_task_id' => $r['maintenance_task_id'],
            'plate'               => $r['plate'],
            'vehicle'             => trim((string) $r['make'] . ' ' . (string) $r['model']) ?: null,
            'garage'              => $r['garage'],
            'total_cost'          => $r['total_cost'],
            'duration_days'       => $r['duration_days'],
            'outcome'             => $r['outcome'],
            'recurred'            => $r['recurred'],
            'resolved_at'         => $r['resolved_at'],
            'tier'                => $r['tier_label'],
        ], array_slice($rows, 0, $limit));
    }

    /**
     * Plain-language "why this was recommended" lines — the P0 seed of the future Explainability DAG.
     *
     * @return array<int,string>
     */
    private function why(SimilarRepairResult $result, array $cause, ?array $topGarage, array $parts, float $recRate): array
    {
        $why = [];
        $n = $result->sampleSize();
        $scope = $result->bestTier === 1 ? 'this vehicle'
            : ($result->bestTier === 2 ? 'this model' : ($result->bestTier === 3 ? 'this make' : 'the fleet'));
        $why[] = "{$n} comparable repair" . ($n === 1 ? '' : 's') . " on {$scope}";

        if ($cause['value']) {
            $why[] = round($cause['share'] * 100) . "% pointed to \"{$cause['value']}\"";
        }
        if ($topGarage && $topGarage['success_rate'] !== null) {
            $rate = round($topGarage['success_rate'] * 100);
            $why[] = "{$topGarage['garage']} handled {$topGarage['jobs']} of them at {$rate}% success";
        }
        if (! empty($parts)) {
            $p = $parts[0];
            $why[] = ($p['name'] ?: $p['part_number']) . " replaced in {$p['freq']} of them";
        }
        if ($recRate > 0) {
            $why[] = round($recRate * 100) . '% recurred after repair';
        } else {
            $why[] = 'No recurrence detected after these repairs';
        }
        return $why;
    }
}
