<?php

namespace App\Services;

use App\Models\Maintenance;
use App\Models\MaintenanceTaskAssignment;
use App\Models\Vendor;
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
    private const CACHE_KEY = 'intelligence:garage_recommendation:v1';

    /**
     * Rank garages for a free-form query.
     *
     * @param  array{model?:?string, brand?:?string, fault?:?string, faults?:array<int,string>}  $criteria
     * @return array<string, mixed>
     */
    public function recommend(array $criteria): array
    {
        return $this->scoreRows(
            $this->history(),
            $criteria,
            config('garage_recommendation.scoring'),
            config('garage_recommendation.quality_penalty'),
            $this->categoryLabels(),
            fn (array $vendorIds, array $faults) => $this->qualitySignal($vendorIds, $faults),
        );
    }

    /**
     * The PURE scoring core — no DB, no config() calls, no facades. Everything it needs is injected, so it
     * can be unit-tested with a hand-built rows array (see GarageRecommendationServiceTest). `recommend()`
     * is the thin wrapper that feeds it live history + config; `$signalResolver(vendorIds, faults)` returns
     * the re-inspection quality signal (a no-op in tests).
     *
     * @param  array<int, array{vendor_id:int, garage:string, brand:?string, brand_l:string, model:?string, model_l:string, categories:array<int,string>}>  $rows
     * @param  array{model?:?string, brand?:?string, fault?:?string, faults?:array<int,string>}  $criteria
     * @return array<string, mixed>
     */
    public function scoreRows(array $rows, array $criteria, array $cfg, array $qCfg, array $catLabels, ?callable $signalResolver = null): array
    {
        $model  = $this->norm($criteria['model'] ?? null);
        $brand  = $this->norm($criteria['brand'] ?? null);
        $faults = array_values(array_filter(array_map(
            fn ($f) => (string) $f,
            $criteria['faults'] ?? (isset($criteria['fault']) ? [$criteria['fault']] : [])
        )));

        $totalHist = count($rows);
        $credFull  = (float) ($cfg['credibility_jobs'] ?? 8);

        $base = [
            'criteria' => [
                'model'         => $model,
                'brand'         => $brand,
                'faults'        => $faults,
                'fault_labels'  => array_map(fn ($f) => $catLabels[$f] ?? $f, $faults),
            ],
            'total_history' => $totalHist,
            'has_history'   => $totalHist > 0,
            'primary'       => [],
            'also_consider' => [],
            'generated_at'  => Carbon::now()->toIso8601String(),
        ];

        if ($totalHist === 0 || ($model === null && $brand === null && empty($faults))) {
            return $base; // nothing to learn from, or nothing asked
        }

        $modelL = $model !== null ? mb_strtolower($model) : null;
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
                $g[$vid] = ['vendor_id' => $vid, 'garage' => $r['garage'], 'total' => 0, 'catTotal' => 0, 'mE' => 0, 'bE' => 0, 'fE' => 0, 'combo' => 0, 'catAt' => [], 'catModel' => []];
            }
            $b = &$g[$vid];
            $b['total']++;
            if (! empty($r['categories'])) $b['catTotal']++;
            $mm = $matchModel($r);
            $bm = $matchBrand($r);
            $fm = (bool) $matchFault($r);
            if ($modelL !== null && $r['model_l'] === $modelL) $b['mE']++;
            if ($bNameL !== null && $r['brand_l'] === $bNameL) $b['bE']++;
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

        $signal = (! empty($faults) && $signalResolver) ? (array) $signalResolver(array_keys($g), $faults) : [];

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
            unset($b);
        }

        // PRIMARY — garages with proven experience on this exact problem.
        $minPrimary = (int) ($cfg['min_primary_combo'] ?? 3);
        $eligible = array_values(array_filter($g, fn ($x) => $x['combo'] >= $minPrimary));
        if (count($eligible) < 2) {
            $eligible = array_values(array_filter($g, fn ($x) => $x['combo'] >= 1));
        }
        usort($eligible, fn ($a, $b) => $b['score'] <=> $a['score']);
        $primary = array_slice($eligible, 0, (int) ($cfg['primary_limit'] ?? 4));

        $primaryOut = array_map(function ($b, $i) use ($model, $brandExplicit, $faults, $bName, $mEmax, $fEmax, $cmax, $credFull, $catLabels, $cfg) {
            // Specialization % — fault concentration (over its identified faults) when a fault is queried,
            // else model / brand concentration. The single number the "89% specialization" line shows.
            $conc = ! empty($faults)
                ? (int) round(($b['fC'] ?? 0) * 100)
                : ($model !== null ? (int) round(($b['mC'] ?? 0) * 100) : ($brandExplicit ? (int) round(($b['bC'] ?? 0) * 100) : 0));

            // One interpretable 0–100 "match score" for the headline card: specialization (½) + evidence
            // credibility (√matches, 0.3) + volume relative to the field (0.2).
            $cred  = min(1.0, sqrt(max($b['combo'], 0)) / sqrt(max($credFull, 1)));
            $rel   = $b['combo'] / max($cmax, 1);
            $match = (int) max(1, min(100, round(100 * (0.5 * ($conc / 100) + 0.3 * $cred + 0.2 * $rel))));

            // Per-fault coverage — the evidence tied to THIS repair: for each queried fault category, how
            // many repairs this garage has done in it (at_garage) and how many were also this exact model.
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
                $faultCoverage[] = [
                    'category_key' => $cat,
                    'label'        => $catLabels[$cat] ?? $cat,
                    'at_garage'    => $at,
                    'same_model'   => $sm,
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
            ];
        }, $primary, array_keys($primary));

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
            'faults' => $faults,
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
            ->with(['vehicle:id,make,model'])
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
     * Per (vendor, category) concluded re-inspection attempts + failures for the queried fault categories.
     *
     * @return array<int, array<string, array{attempts:int, failures:int}>>
     */
    private function qualitySignal(array $vendorIds, array $faults): array
    {
        if (empty($vendorIds) || empty($faults)) {
            return [];
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
            ];
        }
        return $signal;
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
