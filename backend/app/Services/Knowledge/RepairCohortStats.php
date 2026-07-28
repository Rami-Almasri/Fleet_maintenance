<?php

namespace App\Services\Knowledge;

/**
 * PURE aggregation over a cohort of matched past repairs (plain arrays — no DB, no models), so every
 * number the recommendation shows (expected cost band, expected duration, most-likely cause, parts,
 * per-garage success) is locked by DB-free unit tests. RepairRecommendationService feeds it the rows
 * RepairHistoryQueryService produced.
 *
 * A "repair row" is an associative array:
 *   [ 'garage'=>?string, 'garage_vendor_id'=>?int, 'total_cost'=>?float, 'cost_known'=>bool,
 *     'duration_days'=>?int, 'root_cause'=>?string, 'parts'=>[['part_number'=>?,'name'=>?], …],
 *     'outcome'=>'verified_fixed'|'fixed'|'failed'|'unknown', 'recurred'=>bool ]
 */
class RepairCohortStats
{
    /**
     * The p25 / median / p75 band of a numeric field over the rows that HAVE it (nulls excluded).
     *
     * @param  array<int,array<string,mixed>>  $rows
     * @return array{p25:?float, median:?float, p75:?float, n:int}
     */
    public function band(array $rows, string $field): array
    {
        $vals = [];
        foreach ($rows as $r) {
            $v = $r[$field] ?? null;
            if ($v !== null && is_numeric($v)) {
                $vals[] = (float) $v;
            }
        }
        sort($vals);

        return [
            'p25'    => $this->percentile($vals, 0.25),
            'median' => $this->percentile($vals, 0.50),
            'p75'    => $this->percentile($vals, 0.75),
            'n'      => count($vals),
        ];
    }

    /**
     * The most common non-empty value of a field + its share of the rows that have the field.
     *
     * @param  array<int,array<string,mixed>>  $rows
     * @return array{value:?string, share:float, count:int}
     */
    public function modal(array $rows, string $field): array
    {
        $tally = [];
        $known = 0;
        foreach ($rows as $r) {
            $v = $r[$field] ?? null;
            if (is_string($v)) {
                $v = trim($v);
            }
            if ($v === null || $v === '') {
                continue;
            }
            $known++;
            $tally[$v] = ($tally[$v] ?? 0) + 1;
        }
        if (empty($tally)) {
            return ['value' => null, 'share' => 0.0, 'count' => 0];
        }
        arsort($tally);
        $top = array_key_first($tally);

        return ['value' => (string) $top, 'share' => round($tally[$top] / max($known, 1), 2), 'count' => $tally[$top]];
    }

    /**
     * Most frequently replaced parts across the cohort, keyed by part_number when present else lowercased
     * name (so "Timing chain" and "timing chain" collapse). Returns the display name + frequency.
     *
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<int,array{part_number:?string, name:?string, freq:int}>
     */
    public function topParts(array $rows, int $limit = 5): array
    {
        $tally = [];   // key => ['part_number'=>, 'name'=>, 'freq'=>]
        foreach ($rows as $r) {
            foreach ((array) ($r['parts'] ?? []) as $p) {
                $num  = $p['part_number'] ?? null;
                $name = $p['name'] ?? null;
                $key  = $num !== null && $num !== '' ? 'n:' . $num : 'd:' . mb_strtolower(trim((string) $name));
                if ($key === 'd:') {
                    continue;
                }
                if (! isset($tally[$key])) {
                    $tally[$key] = ['part_number' => $num ?: null, 'name' => $name ?: null, 'freq' => 0];
                }
                $tally[$key]['freq']++;
            }
        }
        usort($tally, fn ($a, $b) => $b['freq'] <=> $a['freq']);

        return array_slice(array_values($tally), 0, $limit);
    }

    /**
     * Share of the cohort that recurred after repair (0..1).
     *
     * @param  array<int,array<string,mixed>>  $rows
     */
    public function recurrenceRate(array $rows): float
    {
        if (empty($rows)) {
            return 0.0;
        }
        $recurred = 0;
        foreach ($rows as $r) {
            if (! empty($r['recurred'])) {
                $recurred++;
            }
        }
        return round($recurred / count($rows), 4);
    }

    /**
     * Mean of a numeric field over the rows that have it (nulls excluded). Null when none do.
     *
     * @param  array<int,array<string,mixed>>  $rows
     */
    public function mean(array $rows, string $field): ?float
    {
        $sum = 0.0;
        $n = 0;
        foreach ($rows as $r) {
            $v = $r[$field] ?? null;
            if ($v !== null && is_numeric($v)) {
                $sum += (float) $v;
                $n++;
            }
        }
        return $n > 0 ? round($sum / $n, 2) : null;
    }

    /**
     * Headline success rate: share of CONCLUDED repairs (verified_fixed / fixed / failed) that did not
     * fail. Null when none of the cohort was ever concluded with a verdict.
     *
     * @param  array<int,array<string,mixed>>  $rows
     */
    public function successRate(array $rows): ?float
    {
        $c = $this->outcomeCounts($rows);
        $concluded = $c['verified_fixed'] + $c['fixed'] + $c['failed'];
        if ($concluded === 0) {
            return null;
        }
        return round(($c['verified_fixed'] + $c['fixed']) / $concluded, 2);
    }

    /**
     * Outcome tally for the confidence scorer.
     *
     * @param  array<int,array<string,mixed>>  $rows
     * @return array{verified_fixed:int, fixed:int, failed:int, unknown:int}
     */
    public function outcomeCounts(array $rows): array
    {
        $c = ['verified_fixed' => 0, 'fixed' => 0, 'failed' => 0, 'unknown' => 0];
        foreach ($rows as $r) {
            $o = $r['outcome'] ?? 'unknown';
            $c[$o] = ($c[$o] ?? 0) + 1;
        }
        return $c;
    }

    /**
     * Share of the cohort with BOTH cost and duration known (0..1) — the completeness signal.
     *
     * @param  array<int,array<string,mixed>>  $rows
     */
    public function completeness(array $rows): float
    {
        if (empty($rows)) {
            return 0.0;
        }
        $full = 0;
        foreach ($rows as $r) {
            if (! empty($r['cost_known']) && ($r['duration_days'] ?? null) !== null) {
                $full++;
            }
        }
        return round($full / count($rows), 4);
    }

    /**
     * Rank the garages that handled these repairs — for each: jobs, success rate, avg cost, avg duration,
     * recurrences. Ranked by a credibility-damped success rate so a 1-job shop can't top a proven one.
     *
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<int,array{vendor_id:int, garage:?string, jobs:int, success_rate:?float,
     *                         avg_cost:?float, avg_duration_days:?float, recurrences:int}>
     */
    public function garageBreakdown(array $rows): array
    {
        $g = [];
        foreach ($rows as $r) {
            $vid = $r['garage_vendor_id'] ?? null;
            if ($vid === null) {
                continue;
            }
            if (! isset($g[$vid])) {
                $g[$vid] = ['vendor_id' => (int) $vid, 'garage' => $r['garage'] ?? null,
                    'jobs' => 0, 'concluded' => 0, 'success_w' => 0.0,
                    'cost_sum' => 0.0, 'cost_n' => 0, 'dur_sum' => 0, 'dur_n' => 0, 'recurrences' => 0];
            }
            $b = &$g[$vid];
            $b['jobs']++;
            $o = $r['outcome'] ?? 'unknown';
            if (in_array($o, ['verified_fixed', 'fixed', 'failed'], true)) {
                $b['concluded']++;
                $b['success_w'] += $o === 'verified_fixed' ? 1.0 : ($o === 'fixed' ? 0.9 : 0.0);
            }
            if (! empty($r['cost_known']) && ($r['total_cost'] ?? null) !== null) {
                $b['cost_sum'] += (float) $r['total_cost'];
                $b['cost_n']++;
            }
            if (($r['duration_days'] ?? null) !== null) {
                $b['dur_sum'] += (int) $r['duration_days'];
                $b['dur_n']++;
            }
            if (! empty($r['recurred'])) {
                $b['recurrences']++;
            }
            unset($b);
        }

        $out = [];
        foreach ($g as $b) {
            $rate = $b['concluded'] > 0 ? $b['success_w'] / $b['concluded'] : null;
            $out[] = [
                'vendor_id'         => $b['vendor_id'],
                'garage'            => $b['garage'],
                'jobs'              => $b['jobs'],
                'success_rate'      => $rate !== null ? round($rate, 2) : null,
                'avg_cost'          => $b['cost_n'] > 0 ? round($b['cost_sum'] / $b['cost_n'], 2) : null,
                'avg_duration_days' => $b['dur_n'] > 0 ? round($b['dur_sum'] / $b['dur_n'], 1) : null,
                'recurrences'       => $b['recurrences'],
                // ranking key: credibility-damped success (√jobs), null-success sinks to the bottom
                '_rank'             => ($rate ?? 0) * min(1.0, sqrt($b['jobs']) / sqrt(6)),
            ];
        }
        usort($out, fn ($a, $b) => $b['_rank'] <=> $a['_rank']);

        return array_map(function ($r) {
            unset($r['_rank']);
            return $r;
        }, $out);
    }

    /**
     * Linear-interpolated percentile of a PRE-SORTED ascending list. Null for an empty list.
     *
     * @param  array<int,float>  $sorted
     */
    public function percentile(array $sorted, float $q): ?float
    {
        $n = count($sorted);
        if ($n === 0) {
            return null;
        }
        if ($n === 1) {
            return round($sorted[0], 2);
        }
        $pos  = $q * ($n - 1);
        $lo   = (int) floor($pos);
        $hi   = (int) ceil($pos);
        $frac = $pos - $lo;

        return round($sorted[$lo] + ($sorted[$hi] - $sorted[$lo]) * $frac, 2);
    }
}
