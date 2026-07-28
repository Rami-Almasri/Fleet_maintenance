<?php

namespace App\Services\Knowledge;

/**
 * The INTERFACE BOUNDARY. Maps the engine's rich internal output (RepairRecommendationService) to the
 * FROZEN public contract the frontend consumes — so the UI never depends on an internal shape and money
 * is redacted on the SERVER (never sent to a user without billing.view), not merely hidden in the UI.
 *
 * PURE + static: `present($internal, $showFinancials)` — no DB, no request — so the contract shape and the
 * redaction are locked by a unit test.
 *
 * The contract (stable):
 *   {
 *     state, message,
 *     recommendation: { action, summary, confidence{score,band,reasons},
 *                       likely_cause, suggested_garage, expected_parts[],
 *                       expected_cost|null, expected_duration, recurrence_risk },
 *     statistics: { sample_size, success_rate, average_duration, average_cost|null, recurrence_rate },
 *     similar_repairs: [ { tier, vehicle, plate, fault, garage, duration_days, cost|null, parts[],
 *                          outcome, repaired_at } ],
 *     explanation: { why[], evidence[] },
 *     financials_visible: bool
 *   }
 */
class RepairIntelligencePresenter
{
    public const STATE_NO_HISTORY     = 'no_history';
    public const STATE_LOW_CONFIDENCE = 'low_confidence';
    public const STATE_READY          = 'ready';

    /** @param array<string,mixed> $d the RepairRecommendationService::forFault output */
    public static function present(array $d, bool $showFinancials): array
    {
        $confidence = $d['confidence'] ?? ['score' => 0, 'band' => 'low', 'reasons' => []];
        $sample     = (int) ($d['sample_size'] ?? 0);
        $hasHistory = (bool) ($d['has_history'] ?? false);

        [$state, $message] = self::state($hasHistory, $sample, (string) ($confidence['band'] ?? 'low'));

        return [
            'state'              => $state,
            'message'            => $message,
            'recommendation'     => self::recommendation($d, $confidence, $state, $showFinancials),
            'statistics'         => self::statistics($d['statistics'] ?? null, $sample, $showFinancials),
            'similar_repairs'    => self::similarRepairs($d['similar_repairs']['tiers'] ?? [], $showFinancials),
            'explanation'        => [
                'why'      => array_values((array) ($d['why'] ?? [])),
                'evidence' => self::evidence((array) ($d['evidence'] ?? []), $showFinancials),
            ],
            'financials_visible' => $showFinancials,
        ];
    }

    /** @return array{0:string,1:string} */
    private static function state(bool $hasHistory, int $sample, string $band): array
    {
        if (! $hasHistory || $sample === 0) {
            return [self::STATE_NO_HISTORY, 'No comparable repairs in fleet history yet.'];
        }
        if ($band === 'low') {
            $s = $sample === 1 ? '1 similar repair' : "{$sample} similar repairs";
            return [self::STATE_LOW_CONFIDENCE, "Found {$s}, but history is limited — treat as indicative."];
        }
        $s = $sample === 1 ? '1 similar repair' : "{$sample} similar repairs";
        return [self::STATE_READY, "Based on {$s}."];
    }

    /** @return array<string,mixed>|null */
    private static function recommendation(array $d, array $confidence, string $state, bool $showFinancials): array
    {
        $rec = $d['recommendation'] ?? null;

        // No-history: still return the confidence + a null headline so the UI shape is invariant.
        if ($rec === null) {
            return [
                'action'            => null,
                'summary'           => null,
                'confidence'        => $confidence,
                'likely_cause'      => null,
                'suggested_garage'  => null,
                'expected_parts'    => [],
                'expected_cost'     => null,
                'expected_duration' => null,
                'recurrence_risk'   => null,
            ];
        }

        $garage = $rec['suggested_garage'] ?? null;
        if ($garage !== null && ! $showFinancials) {
            unset($garage['avg_cost']);   // redact cost from the garage headline
        }

        return [
            'action'            => self::action($rec, $showFinancials),
            'summary'           => self::summary($rec),
            'confidence'        => $confidence,
            'likely_cause'      => $rec['likely_cause'] ?? null,
            'suggested_garage'  => $garage,
            'expected_parts'    => array_values((array) ($rec['expected_parts'] ?? [])),
            'expected_cost'     => $showFinancials ? ($rec['expected_cost'] ?? null) : null,
            'expected_duration' => $rec['expected_duration'] ?? null,
            'recurrence_risk'   => $rec['recurrence_risk'] ?? null,
        ];
    }

    private static function action(array $rec, bool $showFinancials): ?string
    {
        $g = $rec['suggested_garage'] ?? null;
        if (! $g || empty($g['name'])) {
            return null;
        }
        $parts = "Recommend {$g['name']}";
        if (($g['success_rate'] ?? null) !== null) {
            $parts .= ' — ' . round($g['success_rate'] * 100) . '% success on ' . ($g['jobs'] ?? 0) . ' similar repair' . (($g['jobs'] ?? 0) === 1 ? '' : 's');
        }
        $tail = [];
        if ($showFinancials && ($rec['expected_cost']['median'] ?? null) !== null) {
            $tail[] = '~AED ' . (int) round($rec['expected_cost']['median']);
        }
        if (($rec['expected_duration']['median'] ?? null) !== null) {
            $tail[] = '~' . rtrim(rtrim((string) $rec['expected_duration']['median'], '0'), '.') . 'd';
        }
        return $parts . (empty($tail) ? '' : ' · ' . implode(' · ', $tail));
    }

    private static function summary(array $rec): ?string
    {
        $bits = [];
        if (($rec['likely_cause']['value'] ?? null)) {
            $bits[] = 'Likely ' . $rec['likely_cause']['value'];
        }
        $parts = $rec['expected_parts'] ?? [];
        if (! empty($parts)) {
            $name = $parts[0]['name'] ?? $parts[0]['part_number'] ?? null;
            if ($name) {
                $bits[] = 'usually ' . mb_strtolower($name) . ' replaced';
            }
        }
        return empty($bits) ? null : ucfirst(implode('; ', $bits)) . '.';
    }

    /** @return array<string,mixed> */
    private static function statistics(?array $s, int $sample, bool $showFinancials): array
    {
        $s ??= ['sample_size' => $sample, 'success_rate' => null, 'average_duration' => null, 'average_cost' => null, 'recurrence_rate' => 0.0];

        return [
            'sample_size'      => (int) ($s['sample_size'] ?? $sample),
            'success_rate'     => $s['success_rate'] ?? null,
            'average_duration' => $s['average_duration'] ?? null,
            'average_cost'     => $showFinancials ? ($s['average_cost'] ?? null) : null,
            'recurrence_rate'  => (float) ($s['recurrence_rate'] ?? 0.0),
        ];
    }

    /**
     * Flatten the four internal tier buckets into ONE recency-tiered array (vehicle → model → make →
     * fleet), each row reshaped to the flat contract + cost redacted.
     *
     * @param  array<string,array<int,array<string,mixed>>>  $tiers
     * @return array<int,array<string,mixed>>
     */
    private static function similarRepairs(array $tiers, bool $showFinancials): array
    {
        $out = [];
        foreach (['vehicle', 'model', 'make', 'fleet'] as $tier) {
            foreach ((array) ($tiers[$tier] ?? []) as $r) {
                $out[] = [
                    'tier'          => $tier,
                    'vehicle'       => trim((string) ($r['make'] ?? '') . ' ' . (string) ($r['model'] ?? '')) ?: null,
                    'plate'         => $r['plate'] ?? null,
                    'fault'         => $r['symptom'] ?? null,
                    'garage'        => $r['garage'] ?? null,
                    'duration_days' => $r['duration_days'] ?? null,
                    'cost'          => $showFinancials ? ($r['total_cost'] ?? null) : null,
                    'parts'         => array_values((array) ($r['parts'] ?? [])),
                    'outcome'       => $r['outcome'] ?? 'fixed',
                    'repaired_at'   => $r['resolved_at'] ?? null,
                ];
            }
        }
        return $out;
    }

    /**
     * @param  array<int,array<string,mixed>>  $evidence
     * @return array<int,array<string,mixed>>
     */
    private static function evidence(array $evidence, bool $showFinancials): array
    {
        return array_map(function ($e) use ($showFinancials) {
            if (! $showFinancials) {
                $e['total_cost'] = null;
            }
            return [
                'maintenance_task_id' => $e['maintenance_task_id'] ?? null,
                'vehicle'             => $e['vehicle'] ?? null,
                'plate'               => $e['plate'] ?? null,
                'garage'              => $e['garage'] ?? null,
                'cost'                => $showFinancials ? ($e['total_cost'] ?? null) : null,
                'duration_days'       => $e['duration_days'] ?? null,
                'outcome'             => $e['outcome'] ?? null,
                'recurred'            => (bool) ($e['recurred'] ?? false),
                'repaired_at'         => $e['resolved_at'] ?? null,
                'tier'                => $e['tier'] ?? null,
            ];
        }, $evidence);
    }
}
