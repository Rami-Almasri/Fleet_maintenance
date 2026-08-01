<?php

namespace App\Services;

use App\Models\Maintenance;
use App\Models\MaintenanceTaskAssignment;
use App\Models\Vendor;
use App\Services\Garage\FaultCriticality;
use App\Services\Garage\GarageOutcomeForecaster;
use App\Services\Garage\MetricDictionary;
use App\Services\Garage\PerFaultRecommender;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The Garage Recommendation engine — learns from maintenance HISTORY which garage best fits a given
 * {vehicle model, brand, fault category}, and explains WHY.
 *
 * This is the DATA-DRIVEN counterpart to GarageRoutingService (which scores hand-curated policy rules).
 * Nobody maintains a table here: the signal emerges from what garages have actually done. For a query it
 * ranks garages on four learned signals, mirroring the validated BI prototype:
 *
 *   1. Experience     — how many matching repairs the garage has done (proven volume).
 *   2. Concentration  — what share of the garage's OWN work is this model / brand / fault (a focused
 *                       specialist vs. a busy generalist).
 *   3. Lift           — how far that concentration exceeds the fleet average for the fault.
 *   4. Combo evidence — jobs matching ALL selected criteria at once (the strongest "proven on this").
 *
 * Every term is damped by a credibility ramp (√jobs) so a 2-job shop at "100%" never outranks the real
 * specialist. Results split into PRIMARY recommendations (garages with proven experience on this exact
 * problem) and ALSO-CONSIDER (single-factor specialists) — the operational distinction between "has done
 * this exact vehicle problem" and "is generally a specialist in this fault area".
 *
 * STATELESS and READ-ONLY: it never mutates a ticket. Persisting the chosen suggestion + reason belongs
 * to the workflow controller (assign-dispatch writes it into the garage_assigned audit event). All tuning
 * lives in config/garage_recommendation.php; the history is read live from `maintenances`.
 *
 * See [[garage-recommendation-engine]] and its sibling GarageRoutingService (policy rules).
 */
class GarageRecommendationService
{
    // v2 — the history row shape gained `class`/`class_l` (vehicle category) for the Vehicle Similarity
    // component of the explainable score. Bumping the key retires the v1 blobs rather than unpacking a
    // row shape the scorer no longer understands.
    private const CACHE_KEY = 'intelligence:garage_recommendation:v2';

    private FaultCriticality $criticality;
    private PerFaultRecommender $perFault;

    public function __construct(
        private ?GarageAssignmentStrategy $strategy = null,
        private ?GarageOutcomeForecaster $forecaster = null,
    ) {
        $this->strategy = $strategy ?? new GarageAssignmentStrategy();
        $this->criticality = new FaultCriticality();
        $this->perFault = new PerFaultRecommender();
    }

    /**
     * Rank garages for a free-form query.
     *
     * @param  array{model?:?string, brand?:?string, fault?:?string, faults?:array<int,string>}  $criteria
     * @return array<string, mixed>
     */
    public function recommend(array $criteria): array
    {
        // Version stamps ride in with the scoring config so the pure core can record provenance without
        // reaching for the container. The `__` prefix marks them as metadata, not tuning knobs.
        $scoring = (array) config('garage_recommendation.scoring');
        $scoring['__engine_version'] = config('garage_recommendation.engine_version', 'unversioned');
        $scoring['__policy_version'] = config('garage_recommendation.policy_version', 'unversioned');

        // Forecasts are resolved lazily — a pure-core caller (tests) passes none and the engine simply
        // reports every outcome as unavailable rather than inventing one.
        $outcomes = ($this->forecaster ?? new GarageOutcomeForecaster())
            ->forecast($criteria['faults'] ?? [], $criteria['model'] ?? null);

        return $this->scoreRows(
            $this->history(),
            $criteria,
            $scoring,
            config('garage_recommendation.quality_penalty'),
            $this->categoryLabels(),
            fn (array $vendorIds, array $faults) => $this->qualitySignal($vendorIds, $faults),
            config('garage_recommendation.explain', []),
            config('garage_recommendation.strategy', []),
            [
                'criticality' => config('garage_recommendation.criticality', []),
                'business'    => config('garage_recommendation.business', []),
                'outcomes'    => $outcomes,
                'metrics'     => (new MetricDictionary(
                    (int) config('garage_recommendation.outcomes.comeback_window_days', 90),
                    (float) config('garage_recommendation.explain.specialization.full_share', 0.40),
                    // The REAL line count, so the explanation can never claim a corpus that is not there.
                    number_format((int) ($outcomes['cost_meta']['lines_kept'] ?? 0)),
                ))->all(),
                'faults_detail' => $criteria['faults_detail'] ?? [],
                // Whether the expense ledger behind every cost figure is still being maintained.
                'cost_freshness' => $outcomes['cost_freshness'] ?? null,
            ],
        );
    }

    /**
     * The PURE scoring core — no DB, no config() calls, no facades. Everything it needs is injected, so it
     * can be unit-tested with a hand-built rows array (see GarageRecommendationServiceTest). `recommend()`
     * is the thin wrapper that feeds it live history + config; `$signalResolver(vendorIds, faults)` returns
     * the re-inspection quality signal (a no-op in tests).
     *
     * `$xCfg` (config `explain`) defines the 0–100 breakdown shown to the operator and `$sCfg`
     * (config `strategy`) the single-vs-split decision; both fall back to documented defaults so an
     * older caller still gets a coherent score.
     *
     * @param  array<int, array{vendor_id:int, garage:string, brand:?string, brand_l:string, model:?string, model_l:string, class_l?:string, categories:array<int,string>}>  $rows
     * @param  array{model?:?string, brand?:?string, class?:?string, fault?:?string, faults?:array<int,string>}  $criteria
     * @return array<string, mixed>
     */
    public function scoreRows(array $rows, array $criteria, array $cfg, array $qCfg, array $catLabels, ?callable $signalResolver = null, array $xCfg = [], array $sCfg = [], array $ctx = []): array
    {
        $model  = $this->norm($criteria['model'] ?? null);
        $brand  = $this->norm($criteria['brand'] ?? null);
        $class  = $this->norm($criteria['class'] ?? null);
        $critCfg = (array) ($ctx['criticality'] ?? []);
        $bizCfg  = (array) ($ctx['business'] ?? []);
        $outcomes = (array) ($ctx['outcomes'] ?? ['vendors' => [], 'fleet' => []]);
        $faults = array_values(array_filter(array_map(
            fn ($f) => (string) $f,
            $criteria['faults'] ?? (isset($criteria['fault']) ? [$criteria['fault']] : [])
        )));

        $totalHist = count($rows);
        $credFull  = (float) ($cfg['credibility_jobs'] ?? 8);

        // How much each fault gets to influence the decision (safety ≫ cosmetic), escalated by the
        // inspector's severity where one was recorded. Drives the weighted Fault Matching average AND
        // the split decision, so a scratch can never outvote a brake fault.
        $crit = $this->criticality->resolveAll($faults, (array) ($criteria['fault_severities'] ?? []), $critCfg);

        $base = [
            'criteria' => [
                'model'         => $model,
                'brand'         => $brand,
                'class'         => $class,
                'faults'        => $faults,
                'fault_labels'  => array_map(fn ($f) => $catLabels[$f] ?? $f, $faults),
                // Published so the UI can show WHY one fault outweighed another.
                'fault_criticality' => array_map(fn ($c, $k) => [
                    'category_key' => $k,
                    'label'        => $catLabels[$k] ?? $k,
                    'tier'         => $c['tier'],
                    'tier_label'   => $c['label'],
                    'weight'       => $c['weight'],
                    'escalated'    => $c['escalated'],
                ], $crit, array_keys($crit)),
            ],
            'strategy'      => null,
            'per_fault'     => [],
            'fleet_outcomes' => $outcomes['fleet'] ?? [],
            // Sent ONCE, not repeated per garage: what every figure on this screen means, how it was
            // calculated and which records produced it. Nothing rendered should be a magic number.
            'metrics'       => (array) ($ctx['metrics'] ?? []),
            // Is the expense ledger every cost figure rests on still being written to? Shipped with the
            // recommendation, not buried in an admin page: a frozen source keeps producing precise
            // medians for a period that has ended, and that failure is invisible in the numbers.
            'cost_freshness' => $ctx['cost_freshness'] ?? null,
            'total_history' => $totalHist,
            'has_history'   => $totalHist > 0,
            // WHO decided, under WHICH rules, against WHICH data. Stamped on every recommendation so a
            // decision stays explainable after the engine, the policy or the corpus have all moved on.
            'provenance'    => $this->provenance($totalHist, $cfg, $xCfg, $sCfg, $critCfg, $bizCfg),
            'primary'       => [],
            'also_consider' => [],
            'generated_at'  => Carbon::now()->toIso8601String(),
        ];

        if ($totalHist === 0 || ($model === null && $brand === null && empty($faults))) {
            return $base; // nothing to learn from, or nothing asked
        }

        $modelL = $model !== null ? mb_strtolower($model) : null;
        $classL = $class !== null ? mb_strtolower($class) : null;
        // Brand is either explicitly chosen, or inferred from the model (for reasoning only, not scoring).
        $brandExplicit = $brand !== null;
        $bName = $brand ?? ($model !== null ? $this->inferBrand($rows, $modelL) : null);
        $bNameL = $bName !== null ? mb_strtolower($bName) : null;

        $matchModel = fn (array $r) => $modelL === null || $r['model_l'] === $modelL;
        $matchBrand = fn (array $r) => $bNameL === null || $r['brand_l'] === $bNameL;
        $matchFault = fn (array $r) => empty($faults) || array_intersect($r['categories'], $faults);

        // Fleet baseline for lift. Fault reasoning runs over the CATEGORISED subset only — the rows where
        // we actually know the fault — since most historical rows have no extractable category and would
        // otherwise dilute every concentration to near-zero. (Model/brand are known for every row, so they
        // keep the full denominator below.)
        $fleetFaultShare = 0.0;
        if (! empty($faults)) {
            $hits = 0;
            $catHist = 0;
            foreach ($rows as $r) {
                if (! empty($r['categories'])) {
                    $catHist++;
                    if (array_intersect($r['categories'], $faults)) {
                        $hits++;
                    }
                }
            }
            $fleetFaultShare = $hits / max($catHist, 1);
        }

        // Per-garage tallies over the whole history. `catTotal` = rows with any extracted category (the
        // denominator for fault concentration); `total` = all rows (denominator for model/brand).
        $g = [];
        foreach ($rows as $r) {
            $vid = $r['vendor_id'];
            if (! isset($g[$vid])) {
                // catAt/catModel = per-fault-category coverage: how many of this garage's repairs touched
                // each queried category (at_garage), and how many were also THIS model (same_model).
                $g[$vid] = ['vendor_id' => $vid, 'garage' => $r['garage'], 'total' => 0, 'catTotal' => 0, 'mE' => 0, 'bE' => 0, 'cE' => 0, 'fE' => 0, 'combo' => 0, 'catAt' => [], 'catModel' => []];
            }
            $b = &$g[$vid];
            $b['total']++;
            if (! empty($r['categories'])) $b['catTotal']++;
            $mm = $matchModel($r);
            $bm = $matchBrand($r);
            $fm = (bool) $matchFault($r);
            if ($modelL !== null && $r['model_l'] === $modelL) $b['mE']++;
            if ($bNameL !== null && $r['brand_l'] === $bNameL) $b['bE']++;
            if ($classL !== null && ($r['class_l'] ?? '') === $classL) $b['cE']++;
            if (! empty($faults) && $fm) $b['fE']++;
            if ($mm && $bm && $fm) $b['combo']++;
            // Per-category coverage tally (only for the queried faults).
            $modelRow = ($modelL !== null && $r['model_l'] === $modelL);
            foreach ($faults as $cat) {
                if (in_array($cat, $r['categories'], true)) {
                    $b['catAt'][$cat] = ($b['catAt'][$cat] ?? 0) + 1;
                    if ($modelRow) $b['catModel'][$cat] = ($b['catModel'][$cat] ?? 0) + 1;
                }
            }
            unset($b);
        }

        $nSel = ($model !== null ? 1 : 0) + ($brandExplicit ? 1 : 0) + (! empty($faults) ? 1 : 0);
        $mEmax = max(1, ...array_map(fn ($x) => $x['mE'], $g) ?: [1]);
        $bEmax = max(1, ...array_map(fn ($x) => $x['bE'], $g) ?: [1]);
        $fEmax = max(1, ...array_map(fn ($x) => $x['fE'], $g) ?: [1]);
        $cmax  = max(1, ...array_map(fn ($x) => $x['combo'], $g) ?: [1]);
        $liftCap = (float) ($cfg['lift_cap'] ?? 3);

        // The re-inspection / turnaround signal: {vendors: {vendorId: {cat: {attempts, failures, days_sum,
        // days_jobs}}}, fleet: {median_days}}. A resolver that predates the fleet key (or a test's null
        // resolver) degrades to "not measured" rather than to a penalty.
        $raw = (! empty($faults) && $signalResolver) ? (array) $signalResolver(array_keys($g), $faults) : [];
        $signal = $raw['vendors'] ?? $raw;
        $fleetSignal = (array) ($raw['fleet'] ?? []);

        foreach ($g as &$b) {
            $t   = max($b['total'], 1);
            $mC  = $b['mE'] / $t;
            $bC  = $b['bE'] / $t;
            $fC  = $b['catTotal'] > 0 ? $b['fE'] / $b['catTotal'] : 0;   // over categorised work
            $lift = $fleetFaultShare > 0 ? $fC / $fleetFaultShare : 0;
            $s = 0.0;
            if ($model !== null)   $s += (float) $cfg['w_model'] * (0.5 * ($b['mE'] / $mEmax) + 0.5 * ($mC * $this->cr($b['mE'], $credFull)));
            if ($brandExplicit)    $s += (float) $cfg['w_brand'] * (0.4 * ($b['bE'] / $bEmax) + 0.6 * ($bC * $this->cr($b['bE'], $credFull)));
            if (! empty($faults))  $s += (float) $cfg['w_fault'] * (0.4 * ($b['fE'] / $fEmax) + 0.6 * (min($lift, $liftCap) / $liftCap * $this->cr($b['fE'], $credFull)));
            if ($nSel >= 2) {
                $cc = $b['combo'] / $t;
                $s += (float) $cfg['w_combo'] * (0.5 * ($b['combo'] / $cmax) + 0.5 * ($cc * $this->cr($b['combo'], $credFull)));
            }

            // Soft quality penalty (reliability-gated).
            [$s, $warn] = $this->applyQualityPenalty($s, $signal[$b['vendor_id']] ?? [], $qCfg);

            $b['mC'] = $mC; $b['bC'] = $bC; $b['fC'] = $fC; $b['lift'] = $lift;
            $b['score'] = round($s, 4);
            $b['warn'] = $warn;
            $b['bName'] = $bName;

            // The EXPLAINABLE 0–100 score — a traceable additive budget, computed here so it is available
            // to ranking, to the strategy layer, and to the card alike (one number, one derivation).
            $b['breakdown'] = $this->breakdown($b, $model, $brandExplicit, $classL, $faults, $xCfg, (array) ($signal[$b['vendor_id']] ?? []), $fleetSignal, $catLabels, $crit);
            $b['match_score'] = $b['breakdown']['total'];
            unset($b);
        }

        // PRIMARY — garages with proven experience on this exact problem.
        $minPrimary = (int) ($cfg['min_primary_combo'] ?? 3);
        $eligible = array_values(array_filter($g, fn ($x) => $x['combo'] >= $minPrimary));
        if (count($eligible) < 2) {
            $eligible = array_values(array_filter($g, fn ($x) => $x['combo'] >= 1));
        }
        // Rank by the number the operator SEES. A list ordered by a hidden weighted score while showing a
        // different 0–100 headline is exactly the opacity this engine is meant to remove; `score` stays in
        // the payload as the tie-breaker (and as the audit trail of the internal weighting).
        usort($eligible, fn ($a, $b) => [$b['match_score'], $b['score']] <=> [$a['match_score'], $a['score']]);
        $primary = array_slice($eligible, 0, (int) ($cfg['primary_limit'] ?? 4));

        $primaryOut = array_map(function ($b, $i) use ($model, $brandExplicit, $faults, $bName, $mEmax, $fEmax, $cmax, $credFull, $catLabels, $cfg) {
            // Specialization % — fault concentration (over its identified faults) when a fault is queried,
            // else model / brand concentration. The single number the "89% specialization" line shows.
            $conc = ! empty($faults)
                ? (int) round(($b['fC'] ?? 0) * 100)
                : ($model !== null ? (int) round(($b['mC'] ?? 0) * 100) : ($brandExplicit ? (int) round(($b['bC'] ?? 0) * 100) : 0));

            // The headline 0–100 — computed once in breakdown() so the components on the card literally
            // add up to it (see the `explain` block in config/garage_recommendation.php).
            $match = $b['breakdown']['total'];

            // Per-fault coverage — the evidence tied to THIS repair: for each queried fault category, how
            // many repairs this garage has done in it (at_garage), how many were also this exact model,
            // and which evidence TIER that lands in (exact > domain > general > none), which is what the
            // Fault Matching component actually scored.
            $faultCoverage = [];
            $covered = 0;
            $missing = [];
            foreach ($faults as $cat) {
                $at = $b['catAt'][$cat] ?? 0;
                $sm = $b['catModel'][$cat] ?? 0;
                if ($at > 0) {
                    $covered++;
                } else {
                    $missing[] = $catLabels[$cat] ?? $cat;
                }
                $ev = $b['breakdown']['fault_points'][$cat] ?? ['tier' => 'none', 'points' => 0.0];
                $faultCoverage[] = [
                    'category_key' => $cat,
                    'label'        => $catLabels[$cat] ?? $cat,
                    'at_garage'    => $at,
                    'same_model'   => $sm,
                    'tier'         => $ev['tier'],
                    'pct'          => (int) round($ev['points'] * 100),
                ];
            }
            $coverage = ['covered' => $covered, 'total' => count($faults), 'missing' => $missing];

            $reasons = $this->reasons($b, $model, $bName, $faults, $faults ? true : false, ['mEmax' => $mEmax, 'fEmax' => $fEmax], $catLabels, $cfg, $conc);
            // Surface "Current fault coverage: X/N" high in the explanation (right after the exact-history line).
            if (! empty($faults)) {
                array_splice($reasons, min(1, count($reasons)), 0, [['t' => "Current fault coverage: {$covered}/" . count($faults), 's' => null]]);
                $reasons = array_slice($reasons, 0, 4);
            }

            return [
                'vendor_id'      => $b['vendor_id'],
                'garage'         => $b['garage'],
                'rank'           => $i + 1,
                'is_top'         => $i === 0,
                'score'          => $b['score'],
                'match_score'    => $match,
                'matched'        => $b['combo'],
                'total'          => $b['total'],
                'model_jobs'     => $b['mE'],
                'brand_jobs'     => $b['bE'],
                'fault_jobs'     => $b['fE'],
                'concentration'  => $conc,
                'confidence'     => $this->confidence($b['combo'], $cfg),
                'warn'           => $b['warn'],
                'fault_coverage' => $faultCoverage,
                'coverage'       => $coverage,
                'reasons'        => $reasons,
                // The full "how was 95/100 calculated" audit — components, each with awarded/max and the
                // facts behind it. Never a black box.
                'breakdown'      => $this->presentBreakdown($b['breakdown']),
            ];
        }, $primary, array_keys($primary));

        // EXPECTED OUTCOMES + BUSINESS AXIS. Kept deliberately OUT of the technical 100: folding money
        // and calendars into it would destroy the traceability the breakdown exists for. The operator
        // gets two clean axes — "can they do the job" and "what will it cost me" — and the trade-off
        // between them is stated rather than silently resolved.
        $vendorOutcomes = (array) ($outcomes['vendors'] ?? []);
        foreach ($primaryOut as &$row) {
            $row['outcomes'] = $vendorOutcomes[$row['vendor_id']] ?? null;
        }
        unset($row);
        $primaryOut = $this->scoreBusiness($primaryOut, $bizCfg);

        // "Why not the other one?" — for every runner-up, exactly where it lost AND what it is still
        // better at. A ranking without this is just an assertion.
        foreach ($primaryOut as $i => &$row) {
            $row['why_not'] = $i === 0
                ? null
                : $this->whyNot($primary[0], $primary[$i], $primaryOut[0], $row, $catLabels);
        }
        unset($row);

        // ALSO CONSIDER — single-factor specialists not already in the primary list.
        $inPrimary = array_flip(array_map(fn ($x) => $x['vendor_id'], $primary));
        $also = [];
        $alsoMin = (int) ($cfg['also_consider_min_jobs'] ?? 5);
        if (! empty($faults)) {
            $best = $this->topSpecialist($g, 'fE', 'fC', $inPrimary, $alsoMin, true, $liftCap);
            if ($best) $also[] = $this->alsoRow('fault', $this->joinLabels($faults, $catLabels) . ' specialist', $best);
        }
        if ($model !== null) {
            $best = $this->topSpecialist($g, 'mE', 'mC', $inPrimary, $alsoMin, false, $liftCap);
            if ($best) $also[] = $this->alsoRow('model', $model . ' specialist', $best);
        }
        if ($brandExplicit) {
            $best = $this->topSpecialist($g, 'bE', 'bC', $inPrimary, $alsoMin, false, $liftCap);
            if ($best) $also[] = $this->alsoRow('brand', $bName . ' specialist', $best);
        }

        $base['primary'] = $primaryOut;
        $base['also_consider'] = $also;

        // MULTI-FAULT DECISION — one garage, or split the job between specialists? Legs may come from any
        // scored garage (a bodywork specialist with no record on this model is still the right shop for
        // the dent), so the strategy sees the whole field, not just the primary list.
        $base['strategy'] = $this->strategy->decide(
            array_map(fn ($b) => [
                'vendor_id'      => $b['vendor_id'],
                'garage'         => $b['garage'],
                'match_score'    => $b['match_score'],
                'outcomes'       => $vendorOutcomes[$b['vendor_id']] ?? null,
                'fault_points'   => array_map(fn ($p) => $p['points'], $b['breakdown']['fault_points']),
                'fault_evidence' => array_map(fn ($p) => $p['jobs'], $b['breakdown']['fault_points']),
            ], array_values($g)),
            $primaryOut,
            $faults,
            $catLabels,
            $sCfg,
            ['criticality' => $crit, 'criticality_cfg' => $critCfg, 'business_cfg' => $bizCfg],
        );

        // FAULT-FIRST view. A supervisor reads a multi-fault car fault by fault, not as one average, so
        // each fault gets its own winner, its own best alternative and its own stated trade-off. Drawn
        // from every scored garage rather than the primary shortlist, because the right shop for one
        // fault is often not the best all-rounder.
        $base['per_fault'] = $this->perFault->recommend(
            array_values($g),
            $faults,
            $crit,
            $catLabels,
            $vendorOutcomes,
            (array) ($ctx['faults_detail'] ?? []),
            (string) ($criteria['model'] ?? ''),
        );

        return $base;
    }

    /**
     * Recommendations for a maintenance ticket — derives the query from the ticket's vehicle + faults.
     * Brand is intentionally left implicit (a model already implies its make), so scoring keys on
     * model + fault, exactly like the validated prototype.
     */
    public function forTicket(Maintenance $ticket): array
    {
        $vehicle = $ticket->relationLoaded('vehicle') ? $ticket->vehicle : $ticket->vehicle()->first();
        $faults  = $this->ticketCategories($ticket);

        $result = $this->recommend([
            'model'  => $vehicle?->model,
            'class'  => $vehicle?->category,
            'faults' => $faults,
            // The inspector's severity per fault, so a fault they flagged as severe can climb a
            // criticality tier and pull more weight in both the score and the split decision.
            'fault_severities' => $this->ticketFaultSeverities($ticket),
            // The inspector wrote "Engine noise", not "Engine" — the per-fault cards should use their
            // words, since that is what the supervisor is looking at on the ticket.
            'faults_detail'    => $this->ticketFaultsDetail($ticket),
        ]);

        $result['ticket'] = [
            'id'               => $ticket->id,
            'vehicle_id'       => $ticket->vehicle_id,
            'vehicle_label'    => trim(((string) $vehicle?->make) . ' ' . ((string) $vehicle?->model)) ?: null,
            'model_label'      => $vehicle?->model,
            'plate'            => $vehicle?->plate_no ?? $vehicle?->plate ?? null,
            'fault_categories' => $faults,
            // Each DETECTED fault (symptom + its category) so the UI can show per-fault coverage ("Engine
            // noise → N Engine repairs here, M {model} Engine repairs").
            'faults_detail'    => $this->ticketFaultsDetail($ticket),
            'fault_severity'   => $ticket->fault_severity,
        ];
        return $result;
    }

    /**
     * The ticket's detected faults as {symptom, category_key, label} — from the promoted tasks (symptom +
     * category_key) or, failing that, the findings JSON resolved through the catalog.
     *
     * @return array<int, array{symptom:string, category_key:string, label:string}>
     */
    private function ticketFaultsDetail(Maintenance $ticket): array
    {
        $labels = $this->categoryLabels();
        $out = [];

        $tasks = $ticket->relationLoaded('tasks') ? $ticket->tasks : $ticket->tasks()->get();
        foreach ($tasks as $task) {
            $cat = $task->category_key;
            if (! $cat) {
                continue;
            }
            $out[] = ['symptom' => $task->symptom ?: ($labels[$cat] ?? $cat), 'category_key' => $cat, 'label' => $labels[$cat] ?? $cat];
        }

        if (empty($out)) {
            foreach ((array) $ticket->findings as $f) {
                $text = is_array($f) ? ($f['text'] ?? null) : (is_string($f) ? $f : null);
                $cat = Maintenance::categoryForKeyword($text);
                if (! $cat) {
                    continue;
                }
                $out[] = ['symptom' => $text, 'category_key' => $cat, 'label' => $labels[$cat] ?? $cat];
            }
        }

        return $out;
    }

    // ── History dataset (cached) ──────────────────────────────────────────────────────────────────────

    /**
     * The derived history dataset, cached. The full fleet is ~25k rows, which serialises to several MB —
     * too big for a single row in the DB cache store (MySQL max_allowed_packet). So we cache a gzip+base64
     * PACKED blob (crushes the repetitive garage/model strings to well under the packet limit) and rehydrate
     * on read. Any cache failure is swallowed and we recompute uncached — the recommendation must never 500
     * because of a caching hiccup.
     *
     * @return array<int, array{vendor_id:int, garage:string, brand:?string, brand_l:string, model:?string, model_l:string, categories:array<int,string>}>
     */
    private function history(): array
    {
        try {
            $packed = Cache::remember(
                self::CACHE_KEY,
                (int) config('garage_recommendation.cache_ttl', 600),
                fn () => base64_encode(gzcompress(serialize($this->buildHistory()), 6)),
            );
            $rows = @unserialize(gzuncompress(base64_decode($packed)));
            if (is_array($rows)) {
                return $rows;
            }
        } catch (\Throwable $e) {
            report($e); // packet limit, no zlib, corrupt entry — fall through to an uncached rebuild
        }
        return $this->buildHistory();
    }

    /** Flatten `maintenances` into one experience row per repair visit: (garage, brand, model, categories). */
    private function buildHistory(): array
    {
        $vendorNames = Vendor::query()->pluck('name', 'id');

        // Per-ticket fault categories from the promoted tasks (cleanest signal), grouped in one query.
        $taskCats = DB::table('maintenance_tasks')
            ->whereNotNull('category_key')
            ->whereNotNull('maintenance_id')
            ->get(['maintenance_id', 'category_key'])
            ->groupBy('maintenance_id')
            ->map(fn ($rows) => $rows->pluck('category_key')->unique()->values()->all());

        $out = [];
        Maintenance::query()
            ->whereNotNull('vendor_id')
            ->whereNotNull('vehicle_id')
            ->with(['vehicle:id,make,model,category'])
            ->select(['id', 'vendor_id', 'vehicle_id', 'findings', 'garage', 'service_main', 'service_sup'])
            ->chunk(500, function ($chunk) use (&$out, $vendorNames, $taskCats) {
                foreach ($chunk as $m) {
                    $veh = $m->vehicle;
                    if (! $veh) {
                        continue;
                    }
                    // Prefer the structured tasks grain; otherwise EXTRACT categories from the free-text
                    // service_main/service_sup (+ findings) so the historical backlog carries fault signal.
                    $cats = $taskCats[$m->id] ?? $this->extractCategories($m->service_main, $m->service_sup, $m->findings);
                    $out[] = [
                        'vendor_id'  => (int) $m->vendor_id,
                        'garage'     => $vendorNames[$m->vendor_id] ?? ($m->garage ?: 'Unknown garage'),
                        'brand'      => $veh->make,
                        'brand_l'    => mb_strtolower((string) $veh->make),
                        'model'      => $veh->model,
                        'model_l'    => mb_strtolower((string) $veh->model),
                        // Vehicle class/segment (vehicles.category) — the third rung of Vehicle Similarity:
                        // a garage that has never seen a Yukon but works SUVs all day is still a closer
                        // match than one that only does sedans.
                        'class'      => $veh->category,
                        'class_l'    => mb_strtolower((string) $veh->category),
                        'categories' => array_values(array_unique($cats)),
                        // Raw source kept for audit/debugging (why a category was assigned). Capped so the
                        // cached dataset stays lean; gzip-packed on the way into the cache regardless.
                        'raw'        => mb_substr(trim(trim((string) $m->service_main) . ' | ' . trim((string) $m->service_sup), ' |'), 0, 120),
                    ];
                }
            });

        return $out;
    }

    /**
     * CONSERVATIVE free-text → fault-category extractor (config/fault_extraction.php). Turns the historical
     * `service_main` / `service_sup` free text into canonical catalog category keys, unioned with any exact
     * findings-keyword matches. Precision over recall: unrecognised text yields NO category. Public so the
     * audit command and tests can exercise it directly.
     *
     * @return array<int, string>
     */
    public function extractCategories(?string $main, ?string $sup, $findings = null): array
    {
        $cats = $this->categoriesFromFindings($findings); // exact catalog-keyword hits (precise)
        $map  = config('fault_extraction.map', []);

        foreach ([$main, $sup] as $text) {
            if (! is_string($text) || trim($text) === '') {
                continue;
            }
            // Each column is a comma-separated list of phrases; match per phrase, union the results.
            foreach (explode(',', mb_strtolower($text)) as $phrase) {
                $padded = ' ' . trim($phrase) . ' ';
                if ($padded === '  ') {
                    continue;
                }
                foreach ($map as $key => $needles) {
                    if (in_array($key, $cats, true)) {
                        continue; // already assigned
                    }
                    foreach ($needles as $needle) {
                        if (str_contains($padded, $needle)) {
                            $cats[] = $key;
                            break;
                        }
                    }
                }
            }
        }

        $valid = $this->validCategoryKeys();
        return array_values(array_unique(array_filter($cats, fn ($c) => in_array($c, $valid, true))));
    }

    /**
     * Audit/debug helper: the most recent free-text maintenances with the categories the extractor assigns.
     * Lets the team eyeball extraction quality (see `maintenance:fault-extraction-audit`). Uncached, live.
     *
     * @return array<int, array{id:int, service_main:?string, service_sup:?string, categories:array<int,string>}>
     */
    public function previewExtraction(int $limit = 40): array
    {
        return Maintenance::query()
            ->whereNotNull('vehicle_id')
            ->where(fn ($q) => $q->whereNotNull('service_main')->orWhereNotNull('service_sup'))
            ->latest('id')
            ->limit($limit)
            ->get(['id', 'service_main', 'service_sup', 'findings'])
            ->map(fn ($m) => [
                'id'           => $m->id,
                'service_main' => $m->service_main,
                'service_sup'  => $m->service_sup,
                'categories'   => $this->extractCategories($m->service_main, $m->service_sup, $m->findings),
            ])->all();
    }

    /** @return array<int, string> valid catalog category keys */
    private function validCategoryKeys(): array
    {
        static $keys = null;
        if ($keys === null) {
            $keys = array_values(array_filter(array_map(
                fn ($c) => $c['key'] ?? null,
                config('maintenance_findings.categories', [])
            )));
        }
        return $keys;
    }

    /**
     * The worst severity the inspector recorded per fault category. Worst-wins: if a ticket carries two
     * electrical faults and one is severe, the category is treated as severe — the safe default.
     *
     * @return array<string, string>  category_key => severity
     */
    private function ticketFaultSeverities(Maintenance $ticket): array
    {
        $rank = array_flip(['routine', 'moderate', 'high']);   // higher index = worse
        $out = [];
        $tasks = $ticket->relationLoaded('tasks') ? $ticket->tasks : $ticket->tasks()->get();
        foreach ($tasks as $task) {
            if (! $task->category_key || ! $task->severity) {
                continue;
            }
            $cur = $out[$task->category_key] ?? null;
            if ($cur === null || ($rank[$task->severity] ?? 0) > ($rank[$cur] ?? 0)) {
                $out[$task->category_key] = $task->severity;
            }
        }
        return $out;
    }

    /** Fault categories for a ticket — promoted tasks first, else resolve each finding keyword. */
    private function ticketCategories(Maintenance $ticket): array
    {
        $fromTasks = $ticket->relationLoaded('tasks')
            ? $ticket->tasks->pluck('category_key')->filter()->all()
            : $ticket->tasks()->pluck('category_key')->filter()->all();

        if (! empty($fromTasks)) {
            return array_values(array_unique($fromTasks));
        }
        return $this->categoriesFromFindings($ticket->findings);
    }

    /** Resolve a `findings` JSON array (each {text:…}) to catalog category keys. */
    private function categoriesFromFindings($findings): array
    {
        $out = [];
        foreach ((array) $findings as $f) {
            $text = is_array($f) ? ($f['text'] ?? null) : (is_string($f) ? $f : null);
            $cat = Maintenance::categoryForKeyword($text);
            if ($cat) {
                $out[] = $cat;
            }
        }
        return array_values(array_unique($out));
    }

    // ── Scoring helpers ─────────────────────────────────────────────────────────────────────────────

    /** Credibility ramp — concentration earns full credit only once a garage has `$full` matching jobs. */
    private function cr(int $jobs, float $full): float
    {
        return min(1.0, sqrt(max($jobs, 0)) / sqrt(max($full, 1)));
    }

    /**
     * The decision's provenance — engine build, policy version, a fingerprint of the ACTUAL tuning used,
     * and the size/date of the corpus it read.
     *
     * The fingerprint is computed from the config arrays rather than declared, because a declared version
     * only tells you what someone remembered to bump. Two decisions with the same `engine_version` but
     * different fingerprints were made under different weights, and that is exactly the discrepancy an
     * audit six months later needs to see.
     *
     * @return array<string, mixed>
     */
    private function provenance(int $totalHist, array $cfg, array $xCfg, array $sCfg, array $critCfg, array $bizCfg): array
    {
        return [
            // Injected by recommend(); the pure core never reaches for config() itself.
            'engine_version' => (string) ($cfg['__engine_version'] ?? 'unversioned'),
            'policy_version' => (string) ($cfg['__policy_version'] ?? 'unversioned'),
            // Short, stable hash of every knob that can change a result. Order-insensitive by ksort so
            // reordering a config file is not mistaken for a policy change.
            'config_fingerprint' => substr(hash('sha256', json_encode([
                'scoring' => $this->normaliseForHash($cfg),
                'explain' => $this->normaliseForHash($xCfg),
                'strategy' => $this->normaliseForHash($sCfg),
                'criticality' => $this->normaliseForHash($critCfg),
                'business' => $this->normaliseForHash($bizCfg),
            ])), 0, 12),
            'data_snapshot'  => Carbon::now()->toDateString(),
            'history_rows'   => $totalHist,
        ];
    }

    /**
     * Recursively key-sort an array so a fingerprint tracks VALUES, not file ordering, and drop `__`
     * metadata keys — the fingerprint answers "was this tuned differently?", so bumping a version label
     * must not make identical tuning look changed.
     */
    private function normaliseForHash(array $a): array
    {
        $a = array_filter($a, fn ($k) => ! str_starts_with((string) $k, '__'), ARRAY_FILTER_USE_KEY);
        ksort($a);
        foreach ($a as $k => $v) {
            if (is_array($v)) {
                $a[$k] = $this->normaliseForHash($v);
            }
        }
        return $a;
    }

    // ── The explainable 100-point breakdown ─────────────────────────────────────────────────────────

    /**
     * Derive the 0–100 `match_score` as a TRACEABLE additive budget: five components, each with the
     * facts that earned it. Nothing here is a fitted coefficient — every point maps to a countable
     * event in the maintenance history, which is the whole point of the component.
     *
     * A component that cannot be measured for this query (no fault selected → nothing to match; no
     * vehicle → no similarity) is marked not-applicable and its budget is REDISTRIBUTED pro-rata over
     * the rest, so 100 always means 100 and a garage is never quietly deflated by data we never asked
     * for. `presentBreakdown()` turns this into the card's payload.
     *
     * @param  array<string, mixed>  $b  the garage's tallies
     * @param  array<string, mixed>  $x  config('garage_recommendation.explain')
     * @param  array<string, array{attempts:int, failures:int, days_sum?:float, days_jobs?:int}>  $signalRow
     * @return array{total:int, components:array<string,array<string,mixed>>, fault_points:array<string,array<string,mixed>>, redistributed:bool}
     */
    private function breakdown(array $b, ?string $model, bool $brandExplicit, ?string $classL, array $faults, array $x, array $signalRow, array $fleetSignal, array $catLabels, array $crit = []): array
    {
        $w = (array) ($x['weights'] ?? []);
        $W = fn (string $k, float $d) => (float) ($w[$k] ?? $d);

        // ── 1. Fault matching (40) — the evidence ladder, per fault, CRITICALITY-weighted ──────────
        [$faultCredit, $faultPoints, $faultDetail] = $this->faultMatchingCredit($b, $faults, (array) ($x['fault'] ?? []), $catLabels, $crit);

        // ── 2. Vehicle similarity (20) — model > brand > class, each credibility-ramped ────────────
        [$vehCredit, $vehDetail, $vehApplicable] = $this->vehicleSimilarityCredit($b, $model, $brandExplicit, $classL, (array) ($x['vehicle'] ?? []));

        // ── 3. Historical success (20) — proven volume + re-inspection record + turnaround ─────────
        [$sucCredit, $sucDetail, $sucFacts] = $this->historicalSuccessCredit($b, $faults, (array) ($x['success'] ?? []), $signalRow, $fleetSignal);

        // ── 4. Specialization (10) — share of this garage's own work in these domains ──────────────
        $sp = (array) ($x['specialization'] ?? []);
        $spShare = (float) ($sp['full_share'] ?? 0.40);
        $spFloor = (float) ($sp['damp_floor'] ?? 0.50);
        $spDamp  = $spFloor + (1 - $spFloor) * $this->cr((int) $b['fE'], (float) ($sp['damp_full'] ?? 8));
        $spCredit = $spShare > 0 ? min(1.0, ((float) $b['fC']) / $spShare) * $spDamp : 0.0;
        $spDetail = (int) round(((float) $b['fC']) * 100) . '% of this garage\'s identified work is in '
            . ($faults ? $this->joinLabels($faults, $catLabels) : 'this area') . " ({$b['fE']} of {$b['catTotal']} jobs)";

        // ── 5. Confidence (10) — sample size behind everything above ───────────────────────────────
        $n = (! empty($faults) && ($model !== null || $brandExplicit))
            ? (int) $b['combo']
            : (! empty($faults) ? (int) $b['fE'] : (int) $b['mE']);
        [$confCredit, $confBand] = $this->confidenceCredit($n, (array) ($x['confidence'] ?? []));

        $components = [
            'fault_matching' => [
                'label' => 'Fault matching', 'weight' => $W('fault_matching', 40), 'credit' => $faultCredit,
                'applicable' => ! empty($faults), 'detail' => $faultDetail,
                'question' => 'How many of THIS ticket\'s faults has this garage repaired before?',
            ],
            'vehicle_similarity' => [
                'label' => 'Vehicle similarity', 'weight' => $W('vehicle_similarity', 20), 'credit' => $vehCredit,
                'applicable' => $vehApplicable, 'detail' => $vehDetail,
                'question' => 'Has it worked on this model, make and class of vehicle?',
            ],
            'historical_success' => [
                'label' => 'Historical success', 'weight' => $W('historical_success', 20), 'credit' => $sucCredit,
                'applicable' => true, 'detail' => $sucDetail, 'facts' => $sucFacts,
                'question' => 'Do its repairs hold, and how fast does it turn cars around?',
            ],
            'specialization' => [
                'label' => 'Specialization', 'weight' => $W('specialization', 10), 'credit' => $spCredit,
                'applicable' => ! empty($faults), 'detail' => $spDetail,
                'question' => 'How much of this garage\'s own work is in this repair domain?',
            ],
            'confidence' => [
                'label' => 'Confidence', 'weight' => $W('confidence', 10), 'credit' => $confCredit,
                'applicable' => true, 'detail' => "{$n} matching jobs — {$confBand} confidence",
                'question' => 'How much evidence is all of this resting on?',
            ],
        ];

        // Redistribute the budget of anything we could not measure, then apportion to whole points so the
        // maxima on the card sum to exactly 100.
        $applicable = array_filter($components, fn ($c) => $c['applicable']);
        $sumW = array_sum(array_column($applicable, 'weight')) ?: 1;
        $maxima = $this->apportion(array_map(fn ($c) => $c['weight'], $applicable), 100);

        $total = 0.0;
        foreach ($components as $k => &$c) {
            $c['max'] = $c['applicable'] ? $maxima[$k] : 0;
            $c['awarded'] = $c['applicable'] ? min($c['max'], round($c['credit'] * $c['max'], 1)) : 0.0;
            $total += $c['awarded'];
            unset($c['credit'], $c['weight']);
        }
        unset($c);

        return [
            // Rounded from the ROUNDED components, so the numbers on the card always add up to the headline.
            'total'          => (int) max(0, min(100, round($total))),
            'components'     => $components,
            'fault_points'   => $faultPoints,
            'redistributed'  => count($applicable) < count($components),
            'budget_note'    => count($applicable) < count($components)
                ? 'Some factors could not be measured for this ticket; their points were redistributed over the rest.'
                : null,
        ];
    }

    /**
     * Fault Matching — for each fault on the ticket, grade the garage's evidence on a ladder and average.
     * EXACT (this fault on this model) beats DOMAIN (this fault, other models) beats GENERAL (a repair
     * history, but nothing in this domain). Averaging is per-fault on purpose: a garage that nails three
     * faults and has never touched the fourth should visibly lose points for the fourth.
     *
     * @return array{0: float, 1: array<string, array<string, mixed>>, 2: string}
     */
    private function faultMatchingCredit(array $b, array $faults, array $f, array $catLabels, array $crit = []): array
    {
        if (empty($faults)) {
            return [0.0, [], 'No fault selected'];
        }
        $points = [];
        $exactN = 0;
        $domainN = 0;
        $critical = null;
        foreach ($faults as $cat) {
            $sm = (int) ($b['catModel'][$cat] ?? 0);
            $at = (int) ($b['catAt'][$cat] ?? 0);
            if ($sm > 0) {
                $tier = 'exact';
                $jobs = $sm;
                $p = (float) ($f['exact_base'] ?? 0.35) + (float) ($f['exact_ramp'] ?? 0.65) * $this->ramp($sm, (float) ($f['exact_full'] ?? 8));
                $exactN++;
            } elseif ($at > 0) {
                $tier = 'domain';
                $jobs = $at;
                $p = (float) ($f['domain_base'] ?? 0.20) + (float) ($f['domain_ramp'] ?? 0.35) * $this->ramp($at, (float) ($f['domain_full'] ?? 20));
                $domainN++;
            } elseif ((int) $b['total'] > 0) {
                $tier = 'general';
                $jobs = 0;
                $p = (float) ($f['general_base'] ?? 0.0) + (float) ($f['general_ramp'] ?? 0.10) * $this->ramp((int) $b['total'], (float) ($f['general_full'] ?? 40));
            } else {
                $tier = 'none';
                $jobs = 0;
                $p = 0.0;
            }
            $c = $crit[$cat] ?? ['weight' => 1.0, 'tier' => null, 'label' => null];
            $points[$cat] = [
                'tier' => $tier, 'points' => round(min(1.0, $p), 4), 'jobs' => $jobs,
                'at_garage' => $at, 'same_model' => $sm, 'label' => $catLabels[$cat] ?? $cat,
                'criticality'       => $c['tier'],
                'criticality_label' => $c['label'],
                'weight'            => (float) $c['weight'],
            ];
            // Remember the heaviest fault so the explanation can name what actually drove the number.
            if ($critical === null || (float) $c['weight'] > $critical['w']) {
                $critical = ['w' => (float) $c['weight'], 'label' => $catLabels[$cat] ?? $cat, 'tier' => $c['label']];
            }
        }

        // WEIGHTED average, not a plain mean: a cosmetic fault (×0.6) must not dilute a safety-critical
        // one (×1.5). This is what stops paint damage steering the car away from the brake specialist.
        $wSum = 0.0;
        $pSum = 0.0;
        foreach ($points as $p) {
            $wSum += $p['weight'];
            $pSum += $p['weight'] * $p['points'];
        }
        $credit = $wSum > 0 ? $pSum / $wSum : 0.0;

        $n = count($faults);
        $detail = "{$exactN} of {$n} faults with same-model history, {$domainN} with same-domain history";
        if ($n > 1 && $critical && $critical['tier']) {
            $detail .= " · weighted toward {$critical['label']} ({$critical['tier']})";
        }

        return [$credit, $points, $detail];
    }

    /** @return array{0: float, 1: string, 2: bool} Vehicle Similarity credit, its explanation, applicability. */
    private function vehicleSimilarityCredit(array $b, ?string $model, bool $brandExplicit, ?string $classL, array $v): array
    {
        $terms = [];   // [share, credit, sentence]
        if ($model !== null) {
            $terms[] = [(float) ($v['model_share'] ?? 0.65), $this->cr((int) $b['mE'], (float) ($v['model_full'] ?? 10)), "{$b['mE']} repairs on this exact model"];
        }
        if ($b['bName'] ?? null) {
            $terms[] = [(float) ($v['brand_share'] ?? 0.20), $this->cr((int) $b['bE'], (float) ($v['brand_full'] ?? 15)), "{$b['bE']} on {$b['bName']}"];
        }
        if ($classL !== null) {
            $terms[] = [(float) ($v['class_share'] ?? 0.15), $this->cr((int) $b['cE'], (float) ($v['class_full'] ?? 20)), "{$b['cE']} on the same vehicle class"];
        }
        if (empty($terms)) {
            return [0.0, 'No vehicle selected', false];
        }
        // Renormalise over the rungs we can actually measure — an unknown vehicle class must not silently
        // cost the garage 15% of this component.
        $sum = array_sum(array_column($terms, 0)) ?: 1;
        $credit = array_sum(array_map(fn ($t) => $t[0] * $t[1], $terms)) / $sum;

        return [$credit, implode(' · ', array_column($terms, 2)), true];
    }

    /**
     * Historical Success — proven volume, then the two quality signals we may or may not have. When a
     * signal is unmeasurable it gets NEUTRAL credit and says so; guessing in either direction would be
     * the same black box this rewrite is removing.
     *
     * @return array{0: float, 1: string, 2: array<string, mixed>}
     */
    private function historicalSuccessCredit(array $b, array $faults, array $s, array $signalRow, array $fleetSignal): array
    {
        $unknown = (float) ($s['unknown'] ?? 0.75);
        $relevant = (int) ($b['combo'] > 0 ? $b['combo'] : ($faults ? $b['fE'] : $b['mE']));
        $volume = $this->cr($relevant, (float) ($s['volume_full'] ?? 10));
        $parts = ["{$relevant} relevant repairs completed"];

        // Re-inspection record → the comeback rate. Only trusted past the reliability gate.
        $attempts = (int) array_sum(array_column($signalRow, 'attempts'));
        $failures = (int) array_sum(array_column($signalRow, 'failures'));
        $minAtt = (int) ($s['quality_min_attempts'] ?? 3);
        if ($attempts >= $minAtt) {
            $comeback = $failures / max($attempts, 1);
            $quality = 1 - $comeback;
            $parts[] = round((1 - $comeback) * 100) . "% re-inspection pass rate ({$failures} of {$attempts} came back)";
        } else {
            $quality = $unknown;
            $parts[] = 'comeback rate not measured (' . $attempts . ' concluded re-inspections)';
        }

        // Turnaround, relative to the fleet median. Faster than the fleet = full credit.
        $days = (float) array_sum(array_column($signalRow, 'days_sum'));
        $timed = (int) array_sum(array_column($signalRow, 'days_jobs'));
        $median = (float) ($fleetSignal['median_days'] ?? 0);
        $slow = max(1.01, (float) ($s['duration_slow_multiple'] ?? 2.0));
        if ($timed >= (int) ($s['duration_min_jobs'] ?? 3) && $median > 0) {
            $avg = $days / max($timed, 1);
            $duration = max(0.0, min(1.0, 1 - (($avg / $median) - 1) / ($slow - 1)));
            $parts[] = round($avg, 1) . ' days average repair (fleet ' . round($median, 1) . ')';
        } else {
            $duration = $unknown;
            $parts[] = 'repair duration not measured';
        }

        $credit = (float) ($s['volume_share'] ?? 0.50) * $volume
            + (float) ($s['quality_share'] ?? 0.35) * $quality
            + (float) ($s['duration_share'] ?? 0.15) * $duration;

        return [$credit, implode(' · ', $parts), [
            'relevant_repairs' => $relevant,
            'reinspections'    => $attempts,
            'comebacks'        => $failures,
            'comeback_measured'=> $attempts >= $minAtt,
            'timed_repairs'    => $timed,
            'avg_days'         => $timed > 0 ? round($days / $timed, 1) : null,
            'fleet_median_days'=> $median > 0 ? round($median, 1) : null,
        ]];
    }

    /**
     * Confidence credit from sample size, on the brief's bands: 1–2 jobs low, 3–9 medium (ramped so the
     * 9th job is worth more than the 3rd), 10+ high.
     *
     * @return array{0: float, 1: string}
     */
    private function confidenceCredit(int $n, array $c): array
    {
        $hiMin = (int) ($c['high_min'] ?? 10);
        $mdMin = (int) ($c['med_min'] ?? 3);
        $loMin = (int) ($c['low_min'] ?? 1);
        $hiP = (float) ($c['high_points'] ?? 10);
        $mdP = (float) ($c['med_points'] ?? 5);
        $loP = (float) ($c['low_points'] ?? 3);

        if ($n >= $hiMin)  return [1.0, 'high'];
        if ($n >= $mdMin)  return [($mdP + ($hiP - $mdP) * (($n - $mdMin) / max($hiMin - $mdMin, 1))) / max($hiP, 1), 'medium'];
        if ($n >= $loMin)  return [$loP / max($hiP, 1), 'low'];
        return [0.0, 'none'];
    }

    /**
     * Split a whole `$total` across weighted buckets with the largest-remainder method, so the component
     * maxima on the card always sum to exactly 100 — no "out of 99" after redistribution.
     *
     * @param  array<string, float>  $weights
     * @return array<string, int>
     */
    private function apportion(array $weights, int $total): array
    {
        $sum = array_sum($weights) ?: 1;
        $exact = array_map(fn ($w) => $w / $sum * $total, $weights);
        $out = array_map('intval', $exact);
        $rem = $total - array_sum($out);
        if ($rem > 0) {
            $frac = [];
            foreach ($exact as $k => $v) {
                $frac[$k] = $v - (int) $v;
            }
            arsort($frac);
            foreach (array_slice(array_keys($frac), 0, $rem) as $k) {
                $out[$k]++;
            }
        }
        return $out;
    }

    /**
     * Flatten the breakdown for the API: an ordered list the UI can render straight down, each row
     * carrying the question it answers, the points and the facts behind them.
     *
     * @return array<string, mixed>
     */
    private function presentBreakdown(array $bd): array
    {
        $rows = [];
        foreach ($bd['components'] as $key => $c) {
            $rows[] = [
                'key'        => $key,
                'label'      => $c['label'],
                'question'   => $c['question'],
                'awarded'    => $c['awarded'],
                'max'        => $c['max'],
                'applicable' => $c['applicable'],
                'detail'     => $c['detail'],
                'facts'      => $c['facts'] ?? null,
            ];
        }
        return [
            'total'         => $bd['total'],
            'components'    => $rows,
            'redistributed' => $bd['redistributed'],
            'note'          => $bd['budget_note'],
        ];
    }

    /**
     * The BUSINESS axis — cost, speed and availability, scored RELATIVE to the shortlist rather than
     * against an absolute target, because "cheap" only means anything next to the alternatives on the
     * table. Each garage lands on 0–100 and picks up badges for whatever it genuinely leads on.
     *
     * A factor we cannot measure for a garage scores NEUTRAL (0.5) and is flagged, so a garage is
     * neither rewarded nor punished for missing data — and never wins a badge on a fleet-average number.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function scoreBusiness(array $rows, array $bCfg): array
    {
        if (empty($bCfg['enabled']) || empty($rows)) {
            foreach ($rows as &$r) {
                $r['business'] = null;
            }
            return $rows;
        }
        $w = (array) ($bCfg['weights'] ?? []);

        // Only garage-grain figures compete. A fleet-median cost is the same number for everyone, so
        // ranking on it would invent a difference that does not exist.
        $pull = function (array $r, string $key) {
            $o = $r['outcomes'][$key] ?? null;
            return ($o && $o['value'] !== null && $o['basis'] !== 'fleet' && $o['basis'] !== 'unavailable') ? (float) $o['value'] : null;
        };
        $axes = [
            'cost'         => array_map(fn ($r) => $pull($r, 'cost_aed'), $rows),
            'speed'        => array_map(fn ($r) => $pull($r, 'duration_days'), $rows),
            'availability' => array_map(fn ($r) => isset($r['outcomes']['start_in_days']) ? (float) $r['outcomes']['start_in_days'] : null, $rows),
        ];

        // Lower is better on all three, so 1.0 goes to the minimum.
        $norm = [];
        foreach ($axes as $axis => $vals) {
            $known = array_values(array_filter($vals, fn ($v) => $v !== null));
            $lo = $known ? min($known) : null;
            $hi = $known ? max($known) : null;
            foreach ($vals as $i => $v) {
                $norm[$axis][$i] = ($v === null || $lo === null || $hi === null || $hi <= $lo)
                    ? ($v === null ? null : 1.0)   // everyone equal → nobody gains, but it is not "unknown"
                    : 1 - (($v - $lo) / ($hi - $lo));
            }
        }

        foreach ($rows as $i => &$r) {
            $score = 0.0;
            $wSum = 0.0;
            $factors = [];
            foreach (['cost' => 'cost_aed', 'speed' => 'duration_days', 'availability' => 'start_in_days'] as $axis => $srcKey) {
                $weight = (float) ($w[$axis] ?? 0);
                $credit = $norm[$axis][$i];
                $known = $credit !== null;
                $score += $weight * ($known ? $credit : 0.5);
                $wSum += $weight;
                $factors[$axis] = ['measured' => $known, 'credit' => $known ? round($credit, 3) : null];
            }
            $r['business'] = [
                'score'   => $wSum > 0 ? (int) round($score / $wSum * 100) : null,
                'factors' => $factors,
                'badges'  => $this->businessBadges($r, $axes, $i, (array) ($bCfg['material'] ?? [])),
            ];
        }
        unset($r);

        return $rows;
    }

    /**
     * The claims a garage may honestly make on the shortlist — "cheapest", "fastest", "available now".
     * Only awarded on MEASURED, garage-grain figures and only when the lead is material (config
     * `business.material`), so we never headline a difference of two dirhams or half a day.
     *
     * @return array<int, string>
     */
    private function businessBadges(array $me, array $axes, int $i, array $m): array
    {
        $badges = [];

        $best = function (array $vals, int $self) {
            $others = array_filter(array_diff_key($vals, [$self => null]), fn ($v) => $v !== null);
            return ($vals[$self] === null || empty($others)) ? null : [$vals[$self], min($others)];
        };

        if ($p = $best($axes['cost'], $i)) {
            [$mine, $rival] = $p;
            if ($rival > 0 && ($rival - $mine) / $rival >= (float) ($m['cost_pct'] ?? 0.15)) {
                $badges[] = 'cheapest';
            }
        }
        if ($p = $best($axes['speed'], $i)) {
            [$mine, $rival] = $p;
            if (($rival - $mine) >= (float) ($m['duration_days'] ?? 1.0)) {
                $badges[] = 'fastest';
            }
        }
        if ($p = $best($axes['availability'], $i)) {
            [$mine, $rival] = $p;
            if (($rival - $mine) >= (float) ($m['start_days'] ?? 1.0)) {
                $badges[] = 'available_soonest';
            }
        }
        if (($me['outcomes']['queue_open']['value'] ?? null) === 0) {
            $badges[] = 'no_queue';
        }
        return $badges;
    }

    /**
     * Why this garage and not that one — stated in FACTS, not adjectives. "Less history" is useless;
     * "no previous Yukon repairs, only 2 Engine repairs" is something a supervisor can act on. Also
     * reports where the runner-up genuinely beats the pick, because a comparison that only lists the
     * loser's failings is advocacy, not analysis.
     *
     * @return array<string, mixed>
     */
    private function whyNot(array $top, array $other, array $topOut, array $otherOut, array $catLabels): array
    {
        $losses = [];
        $wins = [];
        foreach ($other['breakdown']['components'] as $key => $c) {
            $t = $top['breakdown']['components'][$key] ?? null;
            if (! $t || ! $c['applicable'] || ! $t['applicable']) {
                continue;
            }
            $delta = round($c['awarded'] - $t['awarded'], 1);
            if ($delta <= -0.5) {
                $losses[] = ['component' => $c['label'], 'points' => $delta, 'detail' => $c['detail']];
            } elseif ($delta >= 0.5) {
                $wins[] = ['component' => $c['label'], 'points' => $delta, 'detail' => $c['detail']];
            }
        }
        usort($losses, fn ($a, $b) => $a['points'] <=> $b['points']);
        usort($wins, fn ($a, $b) => $b['points'] <=> $a['points']);

        // The concrete evidence gaps, per fault — the sentences the operator actually wants to read.
        $facts = [];
        foreach ($other['breakdown']['fault_points'] as $cat => $p) {
            $label = $catLabels[$cat] ?? $cat;
            if ($p['same_model'] === 0 && $p['at_garage'] === 0) {
                $facts[] = "No previous {$label} repairs at all";
            } elseif ($p['same_model'] === 0) {
                $facts[] = "No previous {$label} repairs on this model ({$p['at_garage']} on other models)";
            } elseif ($p['same_model'] < ($top['breakdown']['fault_points'][$cat]['same_model'] ?? 0)) {
                $facts[] = "Only {$p['same_model']} {$label} repairs on this model, vs {$top['breakdown']['fault_points'][$cat]['same_model']} at {$top['garage']}";
            }
        }

        // Business strengths are a real counterweight — a garage can be behind on evidence and still be
        // the better operational call, which is exactly the trade-off the supervisor is here to make.
        $strengths = [];
        foreach ((array) ($otherOut['business']['badges'] ?? []) as $badge) {
            $strengths[] = ['kind' => $badge, 'detail' => $this->badgeDetail($badge, $otherOut)];
        }
        foreach (array_slice($wins, 0, 2) as $win) {
            $strengths[] = ['kind' => 'component', 'detail' => "{$win['component']}: {$win['detail']}"];
        }

        $gap = $top['match_score'] - $other['match_score'];
        $lead = $losses[0] ?? null;

        return [
            'gap'       => $gap,
            'summary'   => $lead
                ? "{$gap} points behind {$top['garage']} — mainly " . abs($lead['points']) . " fewer on {$lead['component']}."
                : "{$gap} points behind {$top['garage']} on the combined evidence.",
            'lost_because' => array_slice($facts, 0, 4),
            'losses'    => $losses,
            'strengths' => $strengths,
            'better_at' => $wins,
        ];
    }

    /** One badge rendered as a fact with its number, so a claim is never made without its evidence. */
    private function badgeDetail(string $badge, array $row): string
    {
        $o = (array) ($row['outcomes'] ?? []);
        return match ($badge) {
            'cheapest'          => 'Lowest estimated cost — AED ' . (int) ($o['cost_aed']['value'] ?? 0),
            'fastest'           => 'Fastest turnaround — ' . ($o['duration_days']['value'] ?? '?') . ' days',
            'available_soonest' => 'Can start soonest — in ' . ($o['start_in_days'] ?? '?') . ' days',
            'no_queue'          => 'No cars queued — can start immediately',
            default             => $badge,
        };
    }

    /**
     * LINEAR saturation ramp — full credit at `$full` jobs, proportional below it. The counterpart to
     * cr(): use cr() when the first job should count for the most (credibility), and this when each job
     * should count the same (coverage depth), so "1 job" never masquerades as "proven".
     */
    private function ramp(int $jobs, float $full): float
    {
        return min(1.0, max(0, $jobs) / max($full, 1));
    }

    /** User-facing confidence from the amount of matching history: high / medium / low. */
    private function confidence(int $matched, array $cfg): string
    {
        $hi = (int) ($cfg['confidence_high_matches'] ?? 12);
        $md = (int) ($cfg['confidence_med_matches'] ?? 5);
        return $matched >= $hi ? 'high' : ($matched >= $md ? 'medium' : 'low');
    }

    /** Most common brand among history rows of a given (lowercased) model — used to infer the make. */
    private function inferBrand(array $rows, ?string $modelL): ?string
    {
        if ($modelL === null) {
            return null;
        }
        $tally = [];
        foreach ($rows as $r) {
            if ($r['model_l'] === $modelL && $r['brand']) {
                $tally[$r['brand']] = ($tally[$r['brand']] ?? 0) + 1;
            }
        }
        if (empty($tally)) {
            return null;
        }
        arsort($tally);
        return array_key_first($tally);
    }

    /**
     * SOFT quality penalty from failed re-inspections, reliability-gated: only docks points once the
     * garage has ≥ min_attempts concluded attempts for the fault. Returns [adjustedScore, warnBool].
     *
     * @param  array<string, array{attempts:int, failures:int}>  $vendorSignal
     * @return array{0: float, 1: bool}
     */
    private function applyQualityPenalty(float $score, array $vendorSignal, array $q): array
    {
        if (empty($q['enabled']) || empty($vendorSignal)) {
            return [$score, false];
        }
        $warn = false;
        foreach ($vendorSignal as $stat) {
            if ($stat['attempts'] < (int) $q['min_attempts'] || $stat['failures'] === 0) {
                continue; // reliability gate — not enough evidence to penalise
            }
            $score -= min((float) $q['penalty_max'], $stat['failures'] * (float) $q['penalty_each']);
            $rate = $stat['attempts'] > 0 ? $stat['failures'] / $stat['attempts'] : 0;
            if ($rate >= (float) $q['warn_rate']) {
                $warn = true;
            }
        }
        return [$score, $warn];
    }

    /**
     * The OUTCOME signal per (vendor, category) for the queried faults — two things the raw history can't
     * tell us: did the repair HOLD (concluded re-inspection attempts vs. failures) and how LONG did it
     * take (started_at → resolved_at). Both feed the Historical Success component; the failures alone
     * also drive the soft quality penalty on the internal ranking score.
     *
     * The fleet median turnaround rides along so a garage's speed is judged against the fleet it works
     * for, not an arbitrary target.
     *
     * @return array{vendors: array<int, array<string, array{attempts:int, failures:int, days_sum:float, days_jobs:int}>>, fleet: array{median_days: ?float}}
     */
    private function qualitySignal(array $vendorIds, array $faults): array
    {
        $empty = ['vendors' => [], 'fleet' => ['median_days' => null]];
        if (empty($vendorIds) || empty($faults)) {
            return $empty;
        }
        $failed = MaintenanceTaskAssignment::OUTCOME_FAILED_REINSPECTION;
        $resolved = MaintenanceTaskAssignment::OUTCOME_RESOLVED;

        $rows = DB::table('maintenance_task_assignments as a')
            ->join('maintenance_tasks as t', 't.id', '=', 'a.maintenance_task_id')
            ->whereIn('a.vendor_id', $vendorIds)
            ->whereIn('t.category_key', $faults)
            ->whereIn('a.outcome', [$resolved, $failed])
            ->groupBy('a.vendor_id', 't.category_key')
            ->selectRaw('a.vendor_id, t.category_key, COUNT(*) as attempts, SUM(a.outcome = ?) as failures', [$failed])
            ->get();

        $signal = [];
        foreach ($rows as $r) {
            $signal[(int) $r->vendor_id][$r->category_key] = [
                'attempts' => (int) $r->attempts,
                'failures' => (int) $r->failures,
                'days_sum' => 0.0,
                'days_jobs' => 0,
            ];
        }

        // Turnaround per (vendor, category) from the tasks the garage actually finished. Only tasks with
        // both timestamps count — an unfinished or never-stamped task is absent, not zero-days-fast.
        $dur = DB::table('maintenance_tasks as t')
            ->whereIn('t.current_vendor_id', $vendorIds)
            ->whereIn('t.category_key', $faults)
            ->whereNotNull('t.started_at')
            ->whereNotNull('t.resolved_at')
            ->whereColumn('t.resolved_at', '>=', 't.started_at')
            ->groupBy('t.current_vendor_id', 't.category_key')
            ->selectRaw('t.current_vendor_id as vendor_id, t.category_key, COUNT(*) as jobs, SUM(TIMESTAMPDIFF(HOUR, t.started_at, t.resolved_at)) / 24 as days')
            ->get();

        $allAvg = [];
        foreach ($dur as $r) {
            $vid = (int) $r->vendor_id;
            $signal[$vid][$r->category_key] ??= ['attempts' => 0, 'failures' => 0, 'days_sum' => 0.0, 'days_jobs' => 0];
            $signal[$vid][$r->category_key]['days_sum'] = (float) $r->days;
            $signal[$vid][$r->category_key]['days_jobs'] = (int) $r->jobs;
            if ((int) $r->jobs > 0) {
                $allAvg[] = (float) $r->days / (int) $r->jobs;
            }
        }

        sort($allAvg);
        $median = empty($allAvg) ? null : $allAvg[intdiv(count($allAvg), 2)];

        return ['vendors' => $signal, 'fleet' => ['median_days' => $median]];
    }

    /** Best single-factor specialist for a dimension (concentration × lift × √volume), excluding primaries. */
    private function topSpecialist(array $garages, string $expKey, string $concKey, array $exclude, int $minJobs, bool $useLift, float $liftCap): ?array
    {
        $best = null;
        foreach ($garages as $b) {
            if (isset($exclude[$b['vendor_id']]) || $b[$expKey] < $minJobs) {
                continue;
            }
            $lift = $useLift ? min($b['lift'] ?? 1, $liftCap) : 1;
            $score = $b[$concKey] * ($useLift ? $lift / $liftCap : 1) * sqrt($b[$expKey]);
            if ($best === null || $score > $best['_s']) {
                $best = ['_s' => $score, 'vendor_id' => $b['vendor_id'], 'garage' => $b['garage'], 'jobs' => $b[$expKey], 'conc' => $b[$concKey]];
            }
        }
        return $best;
    }

    private function alsoRow(string $dim, string $label, array $best): array
    {
        return [
            'dimension'     => $dim,
            'label'         => $label,
            'vendor_id'     => $best['vendor_id'],
            'garage'        => $best['garage'],
            'jobs'          => $best['jobs'],
            'concentration' => round($best['conc'] * 100),
        ];
    }

    /**
     * Human-readable "why" chips for a garage, ordered by importance. Mirrors the prototype's reason rules.
     *
     * @return array<int, array{t:string, s:?string}>
     */
    private function reasons(array $b, ?string $model, ?string $bName, array $faults, bool $hasFault, array $maxima, array $catLabels, array $cfg, int $conc = 0): array
    {
        $R = [];
        // Each reason carries an importance rank `p`; we sort by it so the strongest evidence leads:
        //   1 exact vehicle+fault · 2 historical matches · 3 specialization · 4 fleet volume · 5 other.
        $add = function (string $t, ?string $s, int $p) use (&$R) {
            foreach ($R as $x) {
                if ($x['t'] === $t) return;
            }
            $R[] = ['t' => $t, 's' => $s, 'p' => $p];
        };
        $faultLabel = $this->joinLabels($faults, $catLabels);
        $lift = $b['lift'] ?? 0;

        // 1 — exact vehicle + fault history (the strongest signal).
        if ($model !== null && $hasFault && $b['combo'] >= (int) $cfg['proven_combo_min']) {
            $add("Proven on {$model} + {$faultLabel}", "{$b['combo']} past jobs", 1);
        }
        // 2 — historical matches (how much relevant experience).
        if ($model !== null && $b['mE'] >= (int) $cfg['high_experience_min']) {
            $add("{$b['mE']} previous {$model} repairs", null, 2);
        } elseif ($hasFault && $b['fE'] >= (int) $cfg['high_experience_min']) {
            $add("{$b['fE']} previous {$faultLabel} repairs", null, 2);
        }
        // 3 — specialization (how focused).
        if ($hasFault && $b['fC'] >= (float) $cfg['fault_specialist_min_share'] && $b['fE'] >= (int) $cfg['fault_specialist_min_jobs'] && $lift >= (float) $cfg['fault_specialist_min_lift']) {
            $add("{$faultLabel} specialist", $conc . '% · ' . number_format($lift, 1) . '× fleet', 3);
        } elseif ($model !== null && $b['mC'] >= (float) $cfg['model_focus_min_share'] && $b['mE'] >= (int) $cfg['model_focus_min_jobs']) {
            $add("Strong {$model} focus", $conc . '% of its work', 3);
        } elseif ($conc >= 30) {
            $add("{$conc}% specialization", null, 3);
        }
        // 4 — fleet volume leadership.
        if ($model !== null && $b['mE'] === $maxima['mEmax'] && $b['mE'] > 0) {
            $add("Highest {$model} volume", "{$b['mE']} jobs", 4);
        }
        if ($hasFault && $b['fE'] === $maxima['fEmax'] && $b['fE'] > 0) {
            $add("Highest {$faultLabel} volume", "{$b['fE']} jobs", 4);
        }
        // 5 — other supporting evidence.
        if ($bName !== null && $b['bC'] >= (float) $cfg['brand_conc_min_share'] && $b['bE'] >= (int) $cfg['brand_conc_min_jobs']) {
            $add("Strong {$bName} concentration", round($b['bC'] * 100) . "% {$bName}", 5);
        }
        if ($b['warn'] ?? false) {
            $add('⚠ Mixed re-inspection record', null, 6);
        }
        if (empty($R)) {
            $add('Handles this work', "{$b['combo']} matching jobs", 5);
        }

        usort($R, fn ($x, $y) => $x['p'] <=> $y['p']);
        return array_map(fn ($r) => ['t' => $r['t'], 's' => $r['s']], array_slice($R, 0, 4));
    }

    // ── Labels ────────────────────────────────────────────────────────────────────────────────────────

    /** @return array<string, string> category_key => label (from the findings catalog) */
    private function categoryLabels(): array
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (config('maintenance_findings.categories', []) as $c) {
                if (! empty($c['key'])) {
                    $map[$c['key']] = $c['label'] ?? $c['key'];
                }
            }
        }
        return $map;
    }

    private function joinLabels(array $faults, array $catLabels): string
    {
        $labels = array_map(fn ($f) => $catLabels[$f] ?? $f, $faults);
        if (count($labels) <= 1) {
            return $labels[0] ?? 'this fault';
        }
        $last = array_pop($labels);
        return implode(', ', $labels) . ' + ' . $last;
    }

    private function norm(?string $v): ?string
    {
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }
}
