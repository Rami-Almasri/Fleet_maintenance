<?php

namespace App\Services\Knowledge;

/**
 * Garage Performance Score (docs/Repair-Intelligence-Architecture.md §7.1).
 *
 * Answers "who consistently repairs this fault best?" — not "who has done it before?" — via a multi-KPI
 * score per (garage, fault category). It is a STANDALONE COMPOSER, not an extension of
 * RepairDurationQueryService, because the KPIs span multiple substrates that don't share a garage key:
 *   - SHEET   (maintenances.vendor_id)                     → duration, relative speed        [Phase 1]
 *   - TASK    (repair_inspections / recurring_fault_reviews / maintenance_tasks)
 *             → QC pass, repeat-fault, reopen                                                [Phase 2]
 * The composer keys everything on the shared vendor entity and calls each single-purpose service, so
 * RepairDurationQueryService stays pure and single-substrate.
 *
 * PHASE 1 (this class): Duration + relative-to-fleet speed. On-time is deliberately scaffolded as
 * UNAVAILABLE — its only current source (sheet expected_return_date) failed a provenance check (it equals
 * actual_in_date in 88.7% of rows, so "on-time" would be a meaningless ~94% by construction). It waits for
 * the workflow substrate's forward-looking expected_completion_date (Phase 2).
 *
 * Honest-by-construction: every KPI carries its own n + confidence + vs-fleet delta, and the composite
 * score blends ONLY the KPIs that clear a sample threshold, renormalizing weights over what's available —
 * so the score strengthens as task data accrues, with no architecture change.
 *
 * The pure scoring helpers (relativeToFleet / speedScore / confidenceBand / composite) are DB-free and
 * unit-tested; profile() is the thin composition over the DB services.
 */
class GaragePerformanceQueryService
{
    /** Days added to numerator & denominator so same-day (0-day) categories don't divide by zero or explode. */
    private const SMOOTH = 1.0;

    /** Default KPI weights for the composite. Only AVAILABLE kpis are used; weights renormalize over them. */
    private const WEIGHTS = [
        'duration'     => 0.30,   // speed vs fleet          (SHEET, Phase 1)
        'on_time'      => 0.20,   // ETA compliance          (Phase 2)
        'qc_pass'      => 0.25,   // re-inspection pass rate  (Phase 2)
        'repeat_fault' => 0.15,   // recurrence (inverse)     (Phase 2)
        'reopen'       => 0.10,   // reopen rate (inverse)    (Phase 2)
    ];

    public function __construct(
        private readonly RepairDurationQueryService $duration = new RepairDurationQueryService(),
    ) {}

    // ── Composition (DB) ────────────────────────────────────────────────────────────────────────────

    /**
     * The performance card for one garage, optionally scoped to a fault category (recommended — it's the
     * fair, mix-adjusted comparison). Pass $reasonId=null for the garage's all-category profile.
     *
     * @return array<string,mixed>
     */
    public function profile(int $vendorId, ?int $reasonId = null): array
    {
        $cfg = (array) config('repair_intelligence');

        $garage = $this->duration->cohort($reasonId, $vendorId);
        $fleet  = $this->duration->cohort($reasonId, null);

        $durationKpi = $this->durationKpi($garage, $fleet['median'], $cfg);

        $kpis = [
            'duration' => $durationKpi,
            'on_time'  => [
                'available' => false,
                'reason'    => 'Deferred (Phase 2): the sheet estimate is not a trustworthy forward promise '
                    . '(equals the actual return date in ~89% of rows). Awaits the workflow expected_completion_date.',
            ],
            // Phase-2 KPIs (qc_pass / repeat_fault / reopen) are added by the composer once their
            // task-substrate services are wired; today they are simply absent from `coverage`.
        ];

        return [
            'vendor_id'         => $vendorId,
            'reason_id'         => $reasonId,
            'scope'             => $reasonId === null ? 'all_categories' : 'category',
            'kpis'              => $kpis,
            'performance_score' => $this->composite([
                'duration' => ['score' => $durationKpi['speed_score'], 'available' => $durationKpi['in_score']],
            ]),
        ];
    }

    /**
     * Build the duration KPI block from the garage cohort vs the fleet median for the same scope.
     *
     * @param  array<string,mixed>  $garage  cohort() output for (reason, vendor)
     */
    private function durationKpi(array $garage, ?float $fleetMedian, array $cfg): array
    {
        $n = (int) ($garage['n'] ?? 0);
        $median = $garage['median'];
        $rel = $this->relativeToFleet($median, $fleetMedian);
        // A KPI only enters the composite once it clears the Level-1 sample floor (default 8).
        $floor = (int) ($cfg['min_n']['level1'] ?? 8);

        return [
            'available'      => $n > 0,
            'in_score'       => $n >= $floor,
            'n'              => $n,
            'median'         => $median,
            'p25'            => $garage['p25'] ?? null,
            'p90'            => $garage['p90'] ?? null,
            'fastest'        => $garage['min'] ?? null,
            'slowest'        => $garage['max'] ?? null,
            'fleet_median'   => $fleetMedian,
            'vs_fleet_pct'   => $rel['pct'],
            'vs_fleet_label' => $rel['label'],
            'speed_score'    => $this->speedScore($median, $fleetMedian),
            'confidence'     => $this->confidenceBand($n, $cfg),
        ];
    }

    // ── Pure scoring core (DB-free, unit-tested) ────────────────────────────────────────────────────

    /**
     * How the garage's turnaround compares to the fleet for the same scope. Positive pct = FASTER.
     *
     * @return array{pct:?int, label:string}
     */
    public function relativeToFleet(?float $garageMedian, ?float $fleetMedian): array
    {
        if ($garageMedian === null || $fleetMedian === null) {
            return ['pct' => null, 'label' => 'no comparison'];
        }
        $denom = max($fleetMedian, 1.0);                 // guard the 0-day (same-day) categories
        $pct = (int) round((($fleetMedian - $garageMedian) / $denom) * 100);
        $label = $pct >= 5 ? "{$pct}% faster than fleet"
            : ($pct <= -5 ? abs($pct) . '% slower than fleet' : 'on par with fleet');

        return ['pct' => $pct, 'label' => $label];
    }

    /**
     * 0–100 speed score from the garage median vs the fleet median. 50 = fleet-average; faster → higher.
     * Smoothed so same-day categories are stable. Null when either side is unknown.
     */
    public function speedScore(?float $garageMedian, ?float $fleetMedian): ?int
    {
        if ($garageMedian === null || $fleetMedian === null) {
            return null;
        }
        $ratio = ($garageMedian + self::SMOOTH) / ($fleetMedian + self::SMOOTH);

        return (int) round(max(0.0, min(100.0, 50.0 * (2.0 - $ratio))));
    }

    /** Sample-size confidence band, using the same thresholds as the ETA cascade config. */
    public function confidenceBand(int $n, array $cfg): string
    {
        $high = (int) ($cfg['confidence']['high_min_n'] ?? 20);
        $med  = (int) ($cfg['min_n']['level1'] ?? 8);
        if ($n >= $high) {
            return 'high';
        }
        if ($n >= $med) {
            return 'medium';
        }
        return 'low';
    }

    /**
     * Blend the AVAILABLE KPIs into a 0–100 composite, renormalizing the default weights over only those
     * present. Returns the score, which KPIs it covers, and whether it should be trusted yet.
     *
     * @param  array<string,array{score:?int, available:bool}>  $kpis
     * @return array{value:?int, coverage:array<int,string>, kpi_count:int}
     */
    public function composite(array $kpis): array
    {
        $num = 0.0;
        $wsum = 0.0;
        $coverage = [];
        foreach ($kpis as $key => $kpi) {
            if (empty($kpi['available']) || ($kpi['score'] ?? null) === null) {
                continue;
            }
            $w = self::WEIGHTS[$key] ?? 0.0;
            if ($w <= 0.0) {
                continue;
            }
            $num += ((float) $kpi['score']) * $w;
            $wsum += $w;
            $coverage[] = $key;
        }

        return [
            'value'     => $wsum > 0.0 ? (int) round($num / $wsum) : null,
            'coverage'  => $coverage,
            'kpi_count' => count($coverage),
        ];
    }
}
