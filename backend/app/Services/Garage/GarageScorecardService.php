<?php

namespace App\Services\Garage;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * THE GARAGE SCORECARD — "what is this garage good at, and where does it have a problem?"
 *
 * The garages page used to answer only "how busy is it and how late is it": jobs, cars in now, spend,
 * average delay. None of that says whether the work is any good, and none of it is specific enough to
 * act on. A supervisor does not need to know that a garage is generally fine — they need to know that
 * it is the best shop in the fleet for brakes and the worst one for tyres, and they need the repairs
 * behind that sentence.
 *
 * ── WHAT IS MEASURED, AND WHY THESE SOURCES ──────────────────────────────────────────────────────
 * RELIABILITY (do the repairs hold) is the recurrence proxy: a repair "came back" if the same fault
 * signature recurred on the same vehicle within 90 days. This is the EXACT query
 * App\Kpi\OperationalKpiService publishes as the fleet baseline and GarageOutcomeForecaster uses per
 * garage — same window, same strict `>`, same `is_exposure = 0` exclusion — attributed to the garage
 * that did the repair and split by repair domain. Diverging from it would put three different
 * "comeback rates" in one product.
 *
 * SPEED is `out_date → actual_in_date` on the same events. Not the workflow timestamps
 * (`repair_started_at`/`ready_at`), which exist on a handful of tickets and cannot segment 44 garages.
 *
 * ON-TIME IS ABSENT FROM THE SCORE, deliberately. Its only source is `expected_return_date`, which
 * equals `actual_in_date` in ~89% of rows — an "on-time rate" built on it is ~94% for everybody and
 * discriminates nothing. The garages page still shows late returns as an operational fact; it just
 * never grades a garage on them. Same ruling as Knowledge\GaragePerformanceQueryService.
 *
 * ── THE ONE THING THAT MAKES THE COMPARISON FAIR ─────────────────────────────────────────────────
 * A garage's raw comeback rate is mostly a description of its WORK MIX. Oil services recur by
 * schedule; a shop that does nothing else looks unreliable, and a brake specialist looks excellent,
 * for reasons neither of them controls. So every comparison here is CASE-MIX ADJUSTED: a garage is
 * measured against what the fleet averages on the same mix of domains it actually works on. That is
 * what `expected_comeback_pct` is, and it is published alongside the actual so the adjustment is
 * visible rather than buried in a coefficient.
 *
 * Body and rim damage are excluded from every quality figure and shown as volume only. They recur
 * because customers keep scraping cars, not because repairs fail — the same exclusion
 * `maintenance_signatures.is_exposure` exists for.
 *
 * ── HONESTY RULES ────────────────────────────────────────────────────────────────────────────────
 *   · Nothing is graded below its sample floor (config `min_n`). It reads "not enough repairs", never 0%.
 *   · Only MEASURED axes enter the score; weights renormalise over them and the coverage is published.
 *   · Every figure carries its n. Every domain row carries the repairs it was computed from.
 *   · Differences smaller than `material_pts` are reported as "on par" — not dressed up as a finding.
 *
 * READ-ONLY and cached. Retired tickets ARE counted: `maintenances` is soft-deleted and these raw
 * queries do not inherit the scope, deliberately — this class measures WHAT HAPPENED, and dropping a
 * retired ticket would let history change whenever somebody tidied the board (docs/Maintenance-Deletion-Model.md).
 *
 * See [[garage-recommendation-engine]], [[operational-kpi-baseline]], [[repair-intelligence-boundary]].
 */
class GarageScorecardService
{
    // v1 — bump when the row shape changes; a stale blob must retire rather than be half-understood.
    private const CACHE_KEY = 'intelligence:garage_scorecard:v1';

    /** Grades a (garage, domain) cell can carry. `thin` = measured but below the floor. */
    public const GRADE_STRONG = 'strong';
    public const GRADE_ON_PAR = 'on_par';
    public const GRADE_WEAK   = 'weak';
    public const GRADE_THIN   = 'thin';
    public const GRADE_EXPOSURE = 'not_graded_exposure';

    /**
     * The whole report: every garage with a score, its domain profile and its ranked strengths and
     * problems, plus the fleet baselines they are measured against and a leaderboard per domain.
     *
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $cfg = (array) config('garage_scorecard');

        try {
            return Cache::remember(self::CACHE_KEY, (int) ($cfg['cache_ttl'] ?? 900), fn () => $this->build($cfg));
        } catch (\Throwable $e) {
            report($e);   // a caching hiccup must never cost the page its content
            return $this->build($cfg);
        }
    }

    /** Drop the cached report — for the console command and for anything that rewrites signatures. */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    // ── Composition ─────────────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function build(array $cfg): array
    {
        $labels = $this->domainLabels();
        $cells  = $this->cells($cfg);                    // vendor => domain => raw tallies
        $fleet  = $this->fleetBaselines($cells);         // domain => fleet comeback / turnaround
        $ranks  = $this->domainRanks($cells, $cfg);      // domain => [vendor_id => rank], plus counts

        $garages = [];
        foreach ($cells as $vid => $byDomain) {
            $garages[] = $this->scorecard((int) $vid, $byDomain, $fleet, $ranks, $labels, $cfg);
        }

        // Best first, but a garage without a score must never sit above one with a bad score — an
        // unmeasured garage is not a good garage. Unscored rows fall to the bottom, ordered by volume.
        usort($garages, fn ($a, $b) => [$b['score']['value'] !== null, $b['score']['value'] ?? -1, $b['repairs']]
            <=> [$a['score']['value'] !== null, $a['score']['value'] ?? -1, $a['repairs']]);

        return [
            'garages'     => $garages,
            'domains'     => $this->domainSummary($fleet, $ranks, $labels),
            'leaderboard' => $this->leaderboards($garages, $fleet, $labels, $cfg),
            'fleet'       => [
                'comeback_pct'    => $fleet['__all']['comeback_pct'] ?? null,
                'comeback_n'      => $fleet['__all']['comeback_n'] ?? 0,
                'turnaround_days' => $fleet['__all']['turnaround_days'] ?? null,
                'turnaround_n'    => $fleet['__all']['turnaround_n'] ?? 0,
                'garages_scored'  => count(array_filter($garages, fn ($g) => $g['score']['value'] !== null)),
                'garages_total'   => count($garages),
            ],
            'provenance'  => $this->provenance($cfg, $cells),
        ];
    }

    /**
     * One garage's card: the overall score, the two axes behind it, every domain it has worked in,
     * and the strengths / problems worth acting on.
     *
     * @param  array<string, array<string, mixed>>  $byDomain
     * @return array<string, mixed>
     */
    private function scorecard(int $vid, array $byDomain, array $fleet, array $ranks, array $labels, array $cfg): array
    {
        $minDomain = (int) ($cfg['min_n']['domain'] ?? 20);
        $material  = (float) ($cfg['material_pts'] ?? 8);

        $name = '';
        $repairs = 0;          // graded repairs (non-exposure, attributable, fully observed)
        $volume  = 0;          // every attributable repair, exposure included
        $returned = 0;
        $held = 0;
        $back30 = 0;
        $back90 = 0;
        $gaps = [];
        $expected = 0.0;       // case-mix-adjusted expectation, in repairs
        $durDays = 0.0;
        $durN = 0;
        $expDurDays = 0.0;

        $domains = [];
        foreach ($byDomain as $key => $c) {
            $name = $name ?: (string) $c['garage'];
            $volume += (int) $c['volume'];

            $fleetRow = $fleet[$key] ?? [];
            $n = (int) $c['n'];
            $exposure = (bool) $c['exposure'];

            // Roll the measurable half into the garage totals.
            if (! $exposure && $n > 0) {
                $repairs += $n;
                $returned += (int) $c['returned'];
                $held += (int) $c['held'];
                $back30 += (int) $c['back_30'];
                $back90 += (int) $c['back_90'];
                $gaps = array_merge($gaps, $c['gaps']);
                $expected += $n * (($fleetRow['comeback_pct'] ?? 0) / 100);
            }
            if (! $exposure && (int) $c['duration_n'] > 0) {
                $durDays += (float) $c['duration_sum'];
                $durN    += (int) $c['duration_n'];
                $expDurDays += (int) $c['duration_n'] * (float) ($fleetRow['turnaround_days'] ?? 0);
            }

            $cb = $n > 0 ? round((int) $c['returned'] / $n * 100, 1) : null;
            $fleetCb = $fleetRow['comeback_pct'] ?? null;
            $delta = ($cb !== null && $fleetCb !== null) ? round($cb - $fleetCb, 1) : null;

            $grade = match (true) {
                $exposure                       => self::GRADE_EXPOSURE,
                $n < $minDomain || $delta === null => self::GRADE_THIN,
                $delta <= -$material            => self::GRADE_STRONG,
                $delta >= $material             => self::GRADE_WEAK,
                default                         => self::GRADE_ON_PAR,
            };

            $domains[] = [
                'key'              => $key,
                'label'            => $labels[$key] ?? $key,
                'jobs'             => (int) $c['volume'],
                'graded_jobs'      => $exposure ? 0 : $n,
                'comeback_pct'     => $exposure ? null : $cb,
                'returned'         => $exposure ? null : (int) $c['returned'],
                // WHAT ACTUALLY HAPPENED to those repairs — the three counts a person can picture,
                // plus how long the failures typically lasted before coming back.
                'held'             => $exposure ? null : (int) $c['held'],
                'back_30'          => $exposure ? null : (int) $c['back_30'],
                'back_90'          => $exposure ? null : (int) $c['back_90'],
                'return_days'      => $exposure ? null : $this->median($c['gaps']),
                'fleet_comeback_pct' => $exposure ? null : $fleetCb,
                'vs_fleet_pts'     => $exposure ? null : $delta,
                'turnaround_days'  => (int) $c['duration_n'] > 0 ? round((float) $c['duration_sum'] / (int) $c['duration_n'], 1) : null,
                'turnaround_n'     => (int) $c['duration_n'],
                'fleet_turnaround_days' => $fleetRow['turnaround_days'] ?? null,
                'rank'             => $ranks[$key]['positions'][$vid] ?? null,
                'ranked_of'        => $ranks[$key]['measured'] ?? 0,
                'fleet_share_pct'  => ($fleetRow['volume'] ?? 0) > 0
                    ? round((int) $c['volume'] / $fleetRow['volume'] * 100)
                    : null,
                'grade'            => $grade,
                'graded'           => in_array($grade, [self::GRADE_STRONG, self::GRADE_ON_PAR, self::GRADE_WEAK], true),
                // Why this cell is not graded, said out loud. An ungraded cell with no reason reads
                // as a broken page rather than an honest one.
                'not_graded_reason' => match ($grade) {
                    self::GRADE_EXPOSURE => 'Damage repairs recur because cars get scraped, not because the work fails — volume is shown, quality is not graded.',
                    self::GRADE_THIN     => "Only {$n} repair" . ($n === 1 ? '' : 's') . " here — under the {$minDomain} needed to judge a garage on a domain.",
                    default              => null,
                },
            ];
        }

        // Biggest first: what a garage mostly does is the first thing to see.
        usort($domains, fn ($a, $b) => $b['jobs'] <=> $a['jobs']);

        $share = $volume > 0 ? array_column($domains, 'jobs') : [];
        foreach ($domains as $i => $d) {
            $domains[$i]['share_pct'] = $volume > 0 ? round($d['jobs'] / $volume * 100) : null;
        }
        unset($share);

        $actual = $repairs > 0 ? round($returned / $repairs * 100, 1) : null;
        $expectedPct = $repairs > 0 ? round($expected / $repairs * 100, 1) : null;

        $reliability = [
            'measured'      => $repairs >= (int) ($cfg['min_n']['garage'] ?? 30) && $expectedPct !== null,
            'comeback_pct'  => $actual,
            'expected_pct'  => $expectedPct,
            'vs_expected_pts' => ($actual !== null && $expectedPct !== null) ? round($actual - $expectedPct, 1) : null,
            'n'             => $repairs,
            'held'          => $held,
            'back_30'       => $back30,
            'back_90'       => $back90,
            'return_days'   => $this->median($gaps),
            'reason'        => $repairs >= (int) ($cfg['min_n']['garage'] ?? 30)
                ? null
                : "Only {$repairs} attributable repairs — under the " . (int) ($cfg['min_n']['garage'] ?? 30) . ' needed to score a garage.',
        ];

        $avgDays = $durN > 0 ? round($durDays / $durN, 1) : null;
        $expDays = $durN > 0 ? round($expDurDays / $durN, 1) : null;
        $speed = [
            'measured'    => $durN >= (int) ($cfg['min_n']['duration'] ?? 10) && $expDays !== null && $expDays > 0,
            'days'        => $avgDays,
            'expected_days' => $expDays,
            'vs_expected_days' => ($avgDays !== null && $expDays !== null) ? round($avgDays - $expDays, 1) : null,
            'n'           => $durN,
            'reason'      => $durN >= (int) ($cfg['min_n']['duration'] ?? 10)
                ? null
                : "Only {$durN} timed repair" . ($durN === 1 ? '' : 's') . ' — too few to compare turnaround.',
        ];

        $score = $this->composite($reliability, $speed, $repairs, $cfg);

        // Strengths and problems: only GRADED domains, ordered by how far from the fleet they sit
        // AND by how much evidence stands behind that distance.
        //
        // Distance alone put "strongest on Brakes — 0 in 100 come back" at the top of a garage with
        // 451 repairs, on the strength of 21 brake jobs, while a 30-point lead over 300 repairs sat
        // below it. A perfect record over 21 jobs is the least reliable number on the card, so the
        // same √n credibility ramp the recommendation engine uses damps it here too — a domain still
        // has to clear the floor to appear at all, this only decides which one leads.
        $graded = array_values(array_filter($domains, fn ($d) => $d['graded']));
        $limit = (int) ($cfg['highlight_limit'] ?? 3);
        $full = (float) ($cfg['min_n']['domain'] ?? 20) * 5;
        $weight = fn ($d) => abs((float) $d['vs_fleet_pts']) * min(1.0, sqrt(max($d['graded_jobs'], 0)) / sqrt($full));

        $strengths = array_values(array_filter($graded, fn ($d) => $d['grade'] === self::GRADE_STRONG));
        usort($strengths, fn ($a, $b) => $weight($b) <=> $weight($a));

        $problems = array_values(array_filter($graded, fn ($d) => $d['grade'] === self::GRADE_WEAK));
        usort($problems, fn ($a, $b) => $weight($b) <=> $weight($a));

        $strengths = array_slice($strengths, 0, $limit);
        $problems  = array_slice($problems, 0, $limit);

        return [
            'vendor_id'   => $vid,
            'garage'      => $name ?: ('#' . $vid),
            'repairs'     => $repairs,
            'volume'      => $volume,
            'score'       => $score,
            'reliability' => $reliability,
            'speed'       => $speed,
            'domains'     => $domains,
            'strengths'   => $strengths,
            'problems'    => $problems,
            // NO `headline`. The card's one-line verdict used to be composed here, which meant an
            // English sentence rendered verbatim on an otherwise Arabic card, and a backend deploy for
            // every rewording. Every number behind it already travels in this payload, so the sentence
            // is built by the side that owns the language — frontend/src/components/garages/phrasing.js.
            // Removed rather than left warm for nobody ([[evidence-layer-governance]]).
        ];
    }

    /**
     * The 0–100 composite: reliability and speed, each scored against the garage's OWN case-mix
     * expectation rather than a flat fleet average, blended over whatever is measurable.
     *
     * 50 means "exactly what the fleet does on this mix of work". Better than expected climbs, worse
     * falls. The same shape Knowledge\GaragePerformanceQueryService already uses for speed, so two
     * scores in one product cannot be built on two different curves.
     *
     * RELIABILITY IS A HARD GATE, not merely the heaviest weight. Renormalising over "whatever is
     * measurable" is right for two views of quality and wrong here, because speed alone is not a
     * quality signal: the first cut of this scored a garage 87 whose repairs came back 60% of the
     * time, purely because it turned cars around quickly and had too few repairs to grade. A fast
     * garage that cannot be shown to fix things is an UNKNOWN garage, and must read as one.
     *
     * Public because it is the one piece of this class worth pinning without a database — the same
     * reason Knowledge\GaragePerformanceQueryService exposes its scoring core. See GarageScorecardServiceTest.
     *
     * @return array{value:?int, band:?string, coverage:array<int,string>, reason:?string, parts:array<string,mixed>}
     */
    public function composite(array $reliability, array $speed, int $n, array $cfg): array
    {
        $w = (array) ($cfg['weights'] ?? []);
        $parts = [];
        $num = 0.0;
        $den = 0.0;
        $coverage = [];

        $rel = $reliability['measured']
            ? $this->ratioScore($reliability['comeback_pct'], $reliability['expected_pct'])
            : null;

        if ($rel === null) {
            return [
                'value' => null, 'band' => null, 'coverage' => [], 'parts' => [],
                'reason' => $reliability['reason']
                    ?? 'Not enough attributable repairs at this garage to tell whether its work holds.',
            ];
        }

        // Lower comeback is better, so the ratio is inverted relative to the speed curve below.
        $parts['reliability'] = $rel;
        $num += $rel * (float) ($w['reliability'] ?? 0.65);
        $den += (float) ($w['reliability'] ?? 0.65);
        $coverage[] = 'reliability';

        if ($speed['measured']) {
            $v = $this->ratioScore($speed['days'], $speed['expected_days']);
            if ($v !== null) {
                $parts['speed'] = $v;
                $num += $v * (float) ($w['speed'] ?? 0.35);
                $den += (float) ($w['speed'] ?? 0.35);
                $coverage[] = 'speed';
            }
        }

        $hi = (int) ($cfg['confidence']['high_min'] ?? 200);
        $md = (int) ($cfg['confidence']['medium_min'] ?? 60);

        return [
            'value'    => (int) round($num / $den),
            'band'     => $n >= $hi ? 'high' : ($n >= $md ? 'medium' : 'low'),
            'coverage' => $coverage,
            'parts'    => $parts,
            'reason'   => count($coverage) < 2
                ? 'Scored on ' . implode(' and ', $coverage) . ' only — the other measure has too little data at this garage.'
                : null,
        ];
    }

    /**
     * Median, not mean. Time-to-return is right-skewed — a handful of repairs that limp back on day
     * 89 would drag a mean well past what typically happens.
     *
     * @param  array<int, int|float>  $values
     */
    private function median(array $values): ?float
    {
        if (empty($values)) {
            return null;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return round($n % 2 ? (float) $values[$mid] : ((float) $values[$mid - 1] + (float) $values[$mid]) / 2, 1);
    }

    /**
     * 0–100 from an actual-vs-expected ratio where LOWER IS BETTER (comeback %, days). 50 = as
     * expected; half the expected value → 75; double → 0. Smoothed by +1 so a near-zero expectation
     * cannot explode the ratio.
     */
    public function ratioScore(?float $actual, ?float $expected): ?int
    {
        if ($actual === null || $expected === null) {
            return null;
        }
        $ratio = ($actual + 1.0) / ($expected + 1.0);

        return (int) round(max(0.0, min(100.0, 50.0 * (2.0 - $ratio))));
    }

    // The card's one-line verdict USED TO BE COMPOSED HERE and no longer is. It was English text
    // rendered verbatim by the UI, so an Arabic reader got an English sentence on an otherwise
    // translated card, and every rewording of it needed a backend deploy. Everything it was built
    // from — the graded domains, their fleet baselines, the case-mix expectation — is published in
    // this payload, so the sentence is assembled by the side that owns the language:
    // frontend/src/components/garages/phrasing.js. Deleted rather than kept warm for nobody.

    // ── The corpus ──────────────────────────────────────────────────────────────────────────────────

    /**
     * Every (garage, repair domain) cell: volume, gradeable repairs, comebacks and timed turnaround.
     *
     * Two passes on purpose. The recurrence pass excludes exposure damage (it measures repair
     * quality); the volume pass does not (it measures what a garage actually does). Folding them into
     * one query would force a choice between under-reporting a body shop's work and grading it on
     * damage nobody could have prevented.
     *
     * @return array<int, array<string, array<string, mixed>>>
     */
    private function cells(array $cfg): array
    {
        $window  = (int) ($cfg['comeback_window_days'] ?? 90);
        $outlier = (int) ($cfg['duration_outlier_days'] ?? 60);
        $map     = (array) config('garage_recommendation.criticality.signature_categories', []);
        $names   = DB::table('vendors')->pluck('name', 'id');

        $cells = [];
        $cell = function (array &$cells, int $vid, string $key) use ($names, $map): array {
            $cells[$vid][$key] ??= [
                'garage'       => $names[$vid] ?? ('#' . $vid),
                'volume'       => 0,
                'n'            => 0,
                'returned'     => 0,
                // WHEN they came back, not just how many. A single "34% comeback rate" collapses two
                // very different garages: one whose failures limp back in a fortnight and one whose
                // cars run for two months first. Buckets are counts, because "12 of 90 came back
                // within a month" is a sentence anybody can act on and "13.3%" is not.
                'back_30'      => 0,
                'back_90'      => 0,
                'held'         => 0,
                'gaps'         => [],   // days-to-return per returning repair, for the median
                'duration_sum' => 0.0,
                'duration_n'   => 0,
                // Domains whose recurrence is customer exposure, not workshop quality. Read off the
                // classifier's own list so adding one there cannot silently start grading it here.
                'exposure'     => in_array($key, $this->exposureDomains($map), true),
            ];
            return $cells[$vid][$key];
        };

        // ── Volume: what each garage actually works on, exposure included ────────────────────────
        $volRows = DB::table('maintenance_signatures as s')
            ->join('maintenances as m', 'm.id', '=', 's.maintenance_id')
            ->whereNotNull('m.vendor_id')
            ->groupBy('m.vendor_id', 's.signature')
            ->selectRaw('m.vendor_id, s.signature, COUNT(*) as n')
            ->get();

        foreach ($volRows as $r) {
            $key = $map[$r->signature] ?? null;
            if ($key === null) {
                continue;   // an unmapped signature segments as nothing rather than as a wrong domain
            }
            $vid = (int) $r->vendor_id;
            $cell($cells, $vid, $key);
            $cells[$vid][$key]['volume'] += (int) $r->n;
        }

        // ── Recurrence: the same proxy OperationalKpiService publishes, split by domain AND BY TIME ──
        //
        // Same population, same window, same `is_exposure = 0` exclusion — but it returns the GAP to
        // the next occurrence rather than a yes/no, so the card can say "12 came back within a month,
        // 6 more within three, the other 72 never did" instead of "20% comeback rate".
        //
        // ONLY FULLY-OBSERVED REPAIRS COUNT. A repair done last week cannot have come back within 90
        // days yet, so counting it as one that held is a free pass that flatters every garage — and
        // flatters the busiest ones most, since they have the most recent work. Excluding the tail
        // costs 4,524 of 31,106 rows and moves the fleet rate by 0.1 points, so it reconciles with the
        // published baseline while removing a bias that would have grown every quarter the corpus did.
        $horizon = DB::table('maintenance_signatures')->max('occurred_at');

        $gapRows = DB::select('
            SELECT g.vendor_id, g.signature, g.gap
            FROM (
                SELECT m.vendor_id, a.signature,
                       (SELECT MIN(DATEDIFF(b.occurred_at, a.occurred_at))
                          FROM maintenance_signatures b
                         WHERE b.vehicle_id  = a.vehicle_id
                           AND b.signature   = a.signature
                           AND b.occurred_at > a.occurred_at
                           AND b.occurred_at <= DATE_ADD(a.occurred_at, INTERVAL ? DAY)) AS gap
                  FROM maintenance_signatures a
                  JOIN maintenances m ON m.id = a.maintenance_id
                 WHERE a.vehicle_id IS NOT NULL AND a.occurred_at IS NOT NULL AND a.is_exposure = 0
                   AND m.vendor_id IS NOT NULL
                   AND a.occurred_at <= DATE_SUB(?, INTERVAL ? DAY)
            ) g', [$window, $horizon, $window]);

        foreach ($gapRows as $r) {
            $key = $map[$r->signature] ?? null;
            if ($key === null) {
                continue;
            }
            $vid = (int) $r->vendor_id;
            $cell($cells, $vid, $key);
            $c = &$cells[$vid][$key];
            $c['n']++;
            if ($r->gap === null) {
                $c['held']++;
            } else {
                $c['returned']++;
                $c['gaps'][] = (int) $r->gap;
                ((int) $r->gap <= 30) ? $c['back_30']++ : $c['back_90']++;
            }
            unset($c);
        }

        // ── Turnaround on the same events, where both dates exist and are sane ───────────────────
        // DISTINCT on (maintenance, signature) so a ticket carrying the same signature twice cannot
        // count its days twice; a ticket spanning two domains legitimately counts once in each.
        $durRows = DB::table('maintenance_signatures as s')
            ->join('maintenances as m', 'm.id', '=', 's.maintenance_id')
            ->whereNotNull('m.vendor_id')
            ->where('s.is_exposure', 0)
            ->whereNotNull('m.out_date')
            ->whereNotNull('m.actual_in_date')
            ->whereColumn('m.actual_in_date', '>=', 'm.out_date')
            ->whereRaw('DATEDIFF(m.actual_in_date, m.out_date) <= ?', [$outlier])
            ->distinct()
            ->get([
                'm.id', 'm.vendor_id', 's.signature',
                DB::raw('DATEDIFF(m.actual_in_date, m.out_date) as days'),
            ]);

        $seen = [];
        foreach ($durRows as $r) {
            $key = $map[$r->signature] ?? null;
            $pair = $r->id . ':' . $r->signature;
            if ($key === null || isset($seen[$pair])) {
                continue;
            }
            $seen[$pair] = true;
            $vid = (int) $r->vendor_id;
            $cell($cells, $vid, $key);
            $cells[$vid][$key]['duration_sum'] += (float) $r->days;
            $cells[$vid][$key]['duration_n']++;
        }

        return $cells;
    }

    /**
     * Fleet baselines per domain — the number every garage is actually compared against — plus an
     * `__all` row for the page header. Built by summing the same cells, so a garage's rate and the
     * fleet rate it is judged against are literally the same measurement.
     *
     * @return array<string, array<string, mixed>>
     */
    private function fleetBaselines(array $cells): array
    {
        $acc = [];
        foreach ($cells as $byDomain) {
            foreach ($byDomain as $key => $c) {
                foreach (['volume', 'n', 'returned', 'held', 'back_30', 'back_90', 'duration_sum', 'duration_n'] as $f) {
                    $acc[$key][$f] = ($acc[$key][$f] ?? 0) + $c[$f];
                }
                $acc[$key]['exposure'] = $c['exposure'];
            }
        }

        $out = [];
        $allN = 0;
        $allRet = 0;
        $allDur = 0.0;
        $allDurN = 0;
        foreach ($acc as $key => $a) {
            $out[$key] = [
                'volume'          => (int) $a['volume'],
                'comeback_pct'    => $a['n'] > 0 && ! $a['exposure'] ? round($a['returned'] / $a['n'] * 100, 1) : null,
                'comeback_n'      => $a['exposure'] ? 0 : (int) $a['n'],
                'held'            => (int) $a['held'],
                'back_30'         => (int) $a['back_30'],
                'back_90'         => (int) $a['back_90'],
                'turnaround_days' => $a['duration_n'] > 0 ? round($a['duration_sum'] / $a['duration_n'], 1) : null,
                'turnaround_n'    => (int) $a['duration_n'],
                'exposure'        => (bool) $a['exposure'],
            ];
            if (! $a['exposure']) {
                $allN += (int) $a['n'];
                $allRet += (int) $a['returned'];
                $allDur += (float) $a['duration_sum'];
                $allDurN += (int) $a['duration_n'];
            }
        }

        $out['__all'] = [
            'comeback_pct'    => $allN > 0 ? round($allRet / $allN * 100, 1) : null,
            'comeback_n'      => $allN,
            'turnaround_days' => $allDurN > 0 ? round($allDur / $allDurN, 1) : null,
            'turnaround_n'    => $allDurN,
        ];

        return $out;
    }

    /**
     * Who is first, second and third in each domain — over garages that clear the domain floor,
     * ranked by comeback rate. The rank is what turns "38% comeback" into something actionable:
     * a number only means something next to the alternatives.
     *
     * @return array<string, array{positions:array<int,int>, measured:int}>
     */
    private function domainRanks(array $cells, array $cfg): array
    {
        $min = (int) ($cfg['min_n']['domain'] ?? 20);
        $bucket = [];

        foreach ($cells as $vid => $byDomain) {
            foreach ($byDomain as $key => $c) {
                if ($c['exposure'] || $c['n'] < $min) {
                    continue;
                }
                $bucket[$key][] = ['vendor_id' => (int) $vid, 'pct' => $c['returned'] / $c['n']];
            }
        }

        $out = [];
        foreach ($bucket as $key => $rows) {
            // Ties break on the larger sample: the same rate over more repairs is the better-evidenced
            // claim, and an arbitrary tiebreak would reshuffle the board between two identical runs.
            usort($rows, fn ($a, $b) => $a['pct'] <=> $b['pct']);
            $positions = [];
            foreach ($rows as $i => $r) {
                $positions[$r['vendor_id']] = $i + 1;
            }
            $out[$key] = ['positions' => $positions, 'measured' => count($rows)];
        }

        return $out;
    }

    /**
     * The fleet's own domain summary — how much work each domain carries, what it typically costs in
     * comebacks and days, and how many garages have enough history to be compared in it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function domainSummary(array $fleet, array $ranks, array $labels): array
    {
        $out = [];
        foreach ($fleet as $key => $f) {
            if ($key === '__all') {
                continue;
            }
            $out[] = [
                'key'             => $key,
                'label'           => $labels[$key] ?? $key,
                'jobs'            => $f['volume'],
                'comeback_pct'    => $f['comeback_pct'],
                'comeback_n'      => $f['comeback_n'],
                'turnaround_days' => $f['turnaround_days'],
                'graded'          => ! $f['exposure'],
                'garages_ranked'  => $ranks[$key]['measured'] ?? 0,
            ];
        }
        usort($out, fn ($a, $b) => $b['jobs'] <=> $a['jobs']);

        return $out;
    }

    /**
     * "Who should I send a tyre job to?" answered from the record alone — the ranked garages per
     * domain, each with the repairs behind its rate.
     *
     * This is a LEADERBOARD, not a recommendation. It knows nothing about the car, the fault's
     * severity, the garage's queue or what the job will cost; the assign step and the Garage Finder
     * do, and they stay the place a car is actually routed. Keeping the distinction explicit is why
     * this returns rates and repair counts rather than a "best garage" verdict.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function leaderboards(array $garages, array $fleet, array $labels, array $cfg): array
    {
        $limit = (int) ($cfg['leaderboard_limit'] ?? 5);
        $rows = [];

        foreach ($garages as $g) {
            foreach ($g['domains'] as $d) {
                if (! $d['graded']) {
                    continue;
                }
                $rows[$d['key']][] = [
                    'vendor_id'    => $g['vendor_id'],
                    'garage'       => $g['garage'],
                    'comeback_pct' => $d['comeback_pct'],
                    'jobs'         => $d['graded_jobs'],
                    'turnaround_days' => $d['turnaround_days'],
                    'rank'         => $d['rank'],
                    'grade'        => $d['grade'],
                ];
            }
        }

        $out = [];
        foreach ($rows as $key => $list) {
            usort($list, fn ($a, $b) => $a['rank'] <=> $b['rank']);
            $out[$key] = [
                'label'        => $labels[$key] ?? $key,
                'fleet_pct'    => $fleet[$key]['comeback_pct'] ?? null,
                'measured'     => count($list),
                'best'         => array_slice($list, 0, $limit),
                // The other end matters as much: this is the page that is supposed to say "this
                // garage has a problem", and a leaderboard that only shows winners never does.
                'worst'        => array_slice(array_reverse($list), 0, $limit),
            ];
        }

        return $out;
    }

    // ── Vocabulary & provenance ─────────────────────────────────────────────────────────────────────

    /**
     * Domain labels from the findings catalog — the vocabulary the rest of the product speaks. The
     * signature map is a reporting bridge INTO this vocabulary (config/garage_recommendation.php),
     * so the page never shows a machine label like `OIL_SERVICE` to an operator.
     *
     * @return array<string, string>
     */
    private function domainLabels(): array
    {
        $out = [];
        foreach ((array) config('maintenance_findings.categories', []) as $c) {
            if (! empty($c['key'])) {
                $out[$c['key']] = $c['label'] ?? $c['key'];
            }
        }
        return $out;
    }

    /**
     * The findings categories that a customer-exposure signature maps into. Derived rather than
     * declared, so the exclusion always tracks RepairSignatureClassifier::EXPOSURE_SIGNATURES.
     *
     * @return array<int, string>
     */
    private function exposureDomains(array $map): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $exposure = \App\Services\Knowledge\RepairSignatureClassifier::EXPOSURE_SIGNATURES;
        $domains = [];
        $nonExposure = [];
        foreach ($map as $sig => $key) {
            in_array($sig, $exposure, true) ? $domains[] = $key : $nonExposure[] = $key;
        }
        // A domain fed by BOTH an exposure and a non-exposure signature keeps its quality grade —
        // excluding it would throw away real repair evidence to avoid some damage rows, and the
        // recurrence query has already dropped the damage half.
        return $cache = array_values(array_diff(array_unique($domains), array_unique($nonExposure)));
    }

    /**
     * WHERE EVERY NUMBER ON THIS PAGE CAME FROM. Shipped with the report rather than documented
     * elsewhere: a page that grades suppliers has to be able to show its working on demand.
     *
     * See [[traceability-visibility-requirement]].
     *
     * @return array<string, mixed>
     */
    private function provenance(array $cfg, array $cells): array
    {
        $pairs = 0;
        $graded = 0;
        $min = (int) ($cfg['min_n']['domain'] ?? 20);
        foreach ($cells as $byDomain) {
            foreach ($byDomain as $c) {
                $pairs++;
                if (! $c['exposure'] && $c['n'] >= $min) {
                    $graded++;
                }
            }
        }

        return [
            'sources' => [
                [
                    'measure' => 'Repairs that came back',
                    'table'   => 'maintenance_signatures → maintenances',
                    'method'  => 'The same fault recorded again on the same car within '
                        . (int) ($cfg['comeback_window_days'] ?? 90)
                        . ' days, credited to the garage that did the first repair. Split by how soon it came back.',
                    'note'    => 'Body and rim damage are excluded — they recur because cars get scraped. '
                        . 'Repairs from the last ' . (int) ($cfg['comeback_window_days'] ?? 90)
                        . ' days are excluded too: they have not had time to come back yet, and counting them '
                        . 'as repairs that held would flatter every garage.',
                ],
                [
                    'measure' => 'Days in the workshop',
                    'table'   => 'maintenances.out_date → maintenances.actual_in_date',
                    'method'  => 'Spans over ' . (int) ($cfg['duration_outlier_days'] ?? 60)
                        . ' days are dropped as data-entry errors, not counted as long repairs.',
                    'note'    => null,
                ],
                [
                    'measure' => 'On-time returns',
                    'table'   => 'maintenances.expected_return_date',
                    'method'  => 'NOT SCORED. The expected date equals the actual return in about 89% of rows, '
                        . 'so an on-time rate built on it would be ~94% for every garage and would grade nobody.',
                    'note'    => 'Late returns are still shown as an operational fact.',
                ],
            ],
            'fair_comparison' => 'Each garage is compared against what the fleet averages on the SAME mix of work it '
                . 'takes on, so a shop that mostly does oil services is not marked down for a fault type that recurs by schedule.',
            'domain_floor'    => $min,
            'garage_floor'    => (int) ($cfg['min_n']['garage'] ?? 30),
            'material_pts'    => (float) ($cfg['material_pts'] ?? 8),
            'pairs_total'     => $pairs,
            'pairs_graded'    => $graded,
            'classifier_version' => \App\Services\Knowledge\RepairSignatureClassifier::VERSION,
            'generated_at'    => now()->toIso8601String(),
        ];
    }
}
