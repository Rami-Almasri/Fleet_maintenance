<?php

namespace App\Services\Garage;

/**
 * What the operation is telling us by the garages it actually picks.
 *
 * The recommendation engine has always been able to explain itself. What it could not do is find out
 * whether anyone agrees with it. This reads the recorded dispatch decisions and answers three questions:
 * how often is the recommendation followed, what do supervisors trade it away for, and is any of that a
 * pattern worth changing the weights over.
 *
 * ── The governing rule ────────────────────────────────────────────────────────────────────────────
 * AN OVERRIDE IS NOT AN ERROR. A supervisor sending the car elsewhere because the customer asked for
 * that workshop is not a model failure — it is information the model never had and mostly should not
 * have. So overrides are separated by AXIS before anything is concluded:
 *
 *   cost / speed / availability   we already measure it. A consistent pattern here is a WEIGHTS
 *                                 problem: the operation values something more than our config does.
 *   relationship / external       we do not measure it and should not. These are correct decisions and
 *                                 are excluded from any accuracy figure — counting them as misses would
 *                                 make the engine look wrong for respecting a customer's request.
 *   evidence                      the supervisor knows something the history has not caught up with.
 *                                 A pattern here is a DATA problem, not a weights problem.
 *
 * Reporting one blended "acceptance rate" would hide all of that, and the number would be used to
 * argue the engine is failing when half its overrides are it working exactly as intended.
 *
 * Suggestions are PROPOSALS, never auto-applied — same discipline as {@see ForecastCalibration}. A
 * model that retunes itself on its operators' behaviour drifts toward whatever is habitual rather than
 * whatever is right, and nobody can tell afterwards which happened.
 *
 * PURE: no DB, no config() calls. The command loads decisions and thresholds and injects them.
 *
 * See [[garage-recommendation-engine]] and [[explainability-platform]].
 */
class DecisionLearning
{
    /** Axes where a persistent override means OUR configuration is out of step with the operation. */
    private const TUNABLE_AXES = ['cost', 'speed', 'availability'];

    /** Axes the engine deliberately does not model — never counted against it. */
    private const OUT_OF_SCOPE_AXES = ['relationship', 'external'];

    /**
     * @param  array<string, array{label:string, axis:string}>  $taxonomy
     * @param  array{min_decisions_for_signal?:int, min_overrides_per_reason?:int, near_tie_gap?:int}  $thresholds
     */
    public function __construct(
        private array $taxonomy = [],
        private array $thresholds = [],
    ) {
    }

    /**
     * @param  array<int, array<string, mixed>>  $decisions  rows from garage_recommendation_decisions
     * @return array<string, mixed>
     */
    public function report(array $decisions): array
    {
        $minTotal = (int) ($this->thresholds['min_decisions_for_signal'] ?? 20);
        $minPerReason = (int) ($this->thresholds['min_overrides_per_reason'] ?? 5);
        $nearTie = (int) ($this->thresholds['near_tie_gap'] ?? 10);

        // Only decisions where a recommendation actually existed can say anything about acceptance.
        $scored = array_values(array_filter($decisions, fn ($d) => ($d['recommended_vendor_id'] ?? null) !== null));
        $total = count($scored);
        // ...but the ones WITHOUT a recommendation are the most important number on the page when it is
        // high. A 95% acceptance rate over four decisions, while sixty cars were dispatched without the
        // panel ever being opened, describes a system nobody is using — and every figure below would
        // read as healthy. Coverage is what stops that misreading.
        $unadvised = count($decisions) - $total;
        $followed = count(array_filter($scored, fn ($d) => (bool) ($d['followed'] ?? false)));
        $overrides = array_values(array_filter($scored, fn ($d) => ! ($d['followed'] ?? false)));

        $byReason = $this->byReason($overrides, $nearTie);
        $byAxis = $this->byAxis($byReason);

        // The headline rate counts only decisions the engine could in principle have got right. An
        // override for a customer request is not a miss, and folding it in makes the engine look worse
        // the better the operation is at serving its customers.
        $inScope = array_values(array_filter($overrides, fn ($d) => ! in_array($this->axisOf($d['override_reason'] ?? null), self::OUT_OF_SCOPE_AXES, true)));
        $inScopeTotal = $followed + count($inScope);

        return [
            'total'            => $total,
            // Engine COVERAGE — how much of the real dispatch flow the recommendation is even part of.
            'assignments'      => count($decisions),
            'unadvised'        => $unadvised,
            'coverage_pct'     => count($decisions) > 0 ? round($total / count($decisions) * 100, 1) : null,
            'followed'         => $followed,
            'overridden'       => count($overrides),
            'acceptance_pct'   => $total > 0 ? round($followed / $total * 100, 1) : null,
            // The figure to actually judge the engine by.
            'in_scope_total'   => $inScopeTotal,
            'adjusted_pct'     => $inScopeTotal > 0 ? round($followed / $inScopeTotal * 100, 1) : null,
            'out_of_scope'     => count($overrides) - count($inScope),
            'unexplained'      => count(array_filter($overrides, fn ($d) => empty($d['override_reason']))),
            'by_reason'        => $byReason,
            'by_axis'          => $byAxis,
            // Where the SAME substitution keeps happening. One override is a judgement call; the same
            // swap eight times is a standing preference the engine has not been told about.
            'repeat_pairs'     => $this->repeatPairs($overrides, max(3, (int) floor($minPerReason * 0.6))),
            'suggestions'      => $total >= $minTotal
                ? $this->suggestions($byAxis, $byReason, $minPerReason, $inScopeTotal)
                : [],
            'sufficient'       => $total >= $minTotal,
            // What is still needed before any of this may be acted on. Published as structured data
            // rather than a sentence so the UI can show progress instead of a bare refusal — "12 of 20"
            // tells an operator the system is working and collecting; "not enough data" reads as broken.
            'readiness'        => $this->readiness($total, $minTotal, $minPerReason, $byReason),
            'note'             => $total >= $minTotal
                ? null
                : "Only {$total} recorded decisions — below the {$minTotal} needed before an acceptance rate means anything.",
        ];
    }

    /**
     * How much evidence exists, and what each conclusion is still waiting for.
     *
     * Gated separately per conclusion because they need different amounts: an acceptance RATE is
     * readable long before a per-reason pattern is, and showing one blanket "not ready" hides the fact
     * that the headline number is already usable.
     *
     * @return array<string, mixed>
     */
    private function readiness(int $total, int $minTotal, int $minPerReason, array $byReason): array
    {
        $strongestReason = 0;
        foreach ($byReason as $r) {
            if ($r['in_scope'] && $r['reason'] !== 'not_stated') {
                $strongestReason = max($strongestReason, $r['count']);
            }
        }

        $gates = [
            [
                'key'   => 'acceptance_rate',
                'label' => 'Acceptance rate',
                'have'  => $total,
                'need'  => $minTotal,
                'met'   => $total >= $minTotal,
            ],
            [
                'key'   => 'pattern_analysis',
                'label' => 'Override pattern analysis',
                'have'  => $strongestReason,
                'need'  => $minPerReason,
                'met'   => $total >= $minTotal && $strongestReason >= $minPerReason,
            ],
        ];

        $met = count(array_filter($gates, fn ($g) => $g['met']));
        $level = $met === count($gates) ? 'ready' : ($met > 0 ? 'emerging' : 'not_ready');

        return [
            'level'   => $level,
            'have'    => $total,
            'need'    => $minTotal,
            'pct'     => $minTotal > 0 ? min(100, (int) round($total / $minTotal * 100)) : 100,
            'gates'   => $gates,
            'message' => match ($level) {
                'ready'    => 'Enough decisions recorded — the patterns below can be acted on.',
                'emerging' => "{$total} decisions recorded. The acceptance rate is readable; per-reason patterns need {$minPerReason} overrides sharing one reason before they mean anything.",
                default    => "{$total} decisions recorded, {$minTotal}+ needed before pattern analysis. Nothing here should change any weight yet.",
            },
        ];
    }

    /**
     * Recommended X, chose Y — repeatedly.
     *
     * The most actionable pattern in the whole loop, and invisible in the per-reason totals: eight
     * separate "faster availability" overrides look like a general preference for speed, but if all
     * eight swapped the same pair of garages it is one specific fact about one specific garage that we
     * are not seeing. Those need very different responses.
     *
     * @return array<int, array<string, mixed>>
     */
    private function repeatPairs(array $overrides, int $min): array
    {
        $pairs = [];
        foreach ($overrides as $d) {
            $from = $d['recommended_vendor_id'] ?? null;
            $to = $d['chosen_vendor_id'] ?? null;
            if ($from === null || $to === null) {
                continue;
            }
            $key = "{$from}=>{$to}";
            $pairs[$key] ??= ['recommended_vendor_id' => (int) $from, 'chosen_vendor_id' => (int) $to, 'count' => 0, 'reasons' => [], 'gaps' => []];
            $pairs[$key]['count']++;
            $reason = $d['override_reason'] ?: 'not_stated';
            $pairs[$key]['reasons'][$reason] = ($pairs[$key]['reasons'][$reason] ?? 0) + 1;
            if (($d['score_gap'] ?? null) !== null) {
                $pairs[$key]['gaps'][] = $d['score_gap'];
            }
        }

        $out = [];
        foreach ($pairs as $p) {
            if ($p['count'] < $min) {
                continue;
            }
            arsort($p['reasons']);
            $out[] = [
                'recommended_vendor_id' => $p['recommended_vendor_id'],
                'chosen_vendor_id'      => $p['chosen_vendor_id'],
                'count'                 => $p['count'],
                'top_reason'            => array_key_first($p['reasons']),
                'reasons'               => $p['reasons'],
                'median_gap'            => $p['gaps'] ? $this->median($p['gaps']) : null,
                // Whether this standing preference is one the engine could in principle learn.
                'in_scope'              => ! in_array($this->axisOf(array_key_first($p['reasons'])), self::OUT_OF_SCOPE_AXES, true),
            ];
        }

        usort($out, fn ($a, $b) => $b['count'] <=> $a['count']);

        return $out;
    }

    /**
     * Overrides grouped by stated reason, each with the score gap the supervisor was willing to give up
     * and how often the data agreed with them.
     *
     * @return array<int, array<string, mixed>>
     */
    private function byReason(array $overrides, int $nearTie): array
    {
        $groups = [];
        foreach ($overrides as $d) {
            $key = $d['override_reason'] ?: 'not_stated';
            $groups[$key][] = $d;
        }

        $out = [];
        foreach ($groups as $key => $rows) {
            $gaps = array_values(array_filter(array_map(fn ($d) => $d['score_gap'] ?? null, $rows), fn ($g) => $g !== null));
            $axis = $this->axisOf($key);

            $out[] = [
                'reason'    => $key,
                'label'     => $this->taxonomy[$key]['label'] ?? 'Not stated',
                'axis'      => $axis,
                'count'     => count($rows),
                // How much score was traded away. A cluster of near-ties says the engine nearly agreed;
                // large gaps say it was working from different information entirely.
                'median_gap' => $gaps ? $this->median($gaps) : null,
                'near_ties'  => count(array_filter($gaps, fn ($g) => $g <= $nearTie)),
                // Did the data back the supervisor up? Stated "lower cost" against a garage our own
                // figures show was cheaper is a weights signal. The same claim against a garage that
                // was NOT cheaper is a signal about perception, or about our cost data — very
                // different problems, and only separable because the advantages were measured.
                'corroborated' => $this->corroborated($rows, $axis),
                'in_scope'     => ! in_array($axis, self::OUT_OF_SCOPE_AXES, true),
            ];
        }

        usort($out, fn ($a, $b) => $b['count'] <=> $a['count']);

        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    private function byAxis(array $byReason): array
    {
        $axes = [];
        foreach ($byReason as $r) {
            $a = $r['axis'];
            $axes[$a] ??= ['axis' => $a, 'count' => 0, 'corroborated' => 0, 'tunable' => in_array($a, self::TUNABLE_AXES, true)];
            $axes[$a]['count'] += $r['count'];
            $axes[$a]['corroborated'] += $r['corroborated'];
        }
        $out = array_values($axes);
        usort($out, fn ($a, $b) => $b['count'] <=> $a['count']);

        return $out;
    }

    /**
     * How many of these overrides the measured data actually supports.
     *
     * Only meaningful for axes we measure — nobody can corroborate "the customer asked for it" from a
     * repair history, and pretending otherwise would report those as unsupported and imply the
     * supervisor was making it up.
     */
    private function corroborated(array $rows, string $axis): int
    {
        if (! in_array($axis, self::TUNABLE_AXES, true)) {
            return 0;
        }
        return count(array_filter($rows, function ($d) use ($axis) {
            $adv = $d['chosen_advantages'] ?? [];
            return is_array($adv) && in_array($axis, $adv, true);
        }));
    }

    /**
     * Reviewable proposals. Deliberately worded as questions for a human, not as changes to apply — the
     * engine describes the disagreement, a person decides whether the operation or the config is right.
     *
     * @return array<int, array<string, mixed>>
     */
    private function suggestions(array $byAxis, array $byReason, int $minPerReason, int $inScopeTotal): array
    {
        $out = [];

        foreach ($byAxis as $a) {
            if (! $a['tunable'] || $a['count'] < $minPerReason) {
                continue;
            }
            $share = $inScopeTotal > 0 ? round($a['count'] / $inScopeTotal * 100) : 0;
            // Corroborated means our own data agreed the chosen garage was better on that axis. That is
            // the strong case: we measured the advantage, scored it too lightly, and were overruled.
            if ($a['corroborated'] >= $minPerReason) {
                $out[] = [
                    'kind'   => 'weights',
                    'axis'   => $a['axis'],
                    'detail' => "{$a['corroborated']} of {$a['count']} {$a['axis']} overrides are backed by our own figures — the chosen garage really was better on {$a['axis']}. That is {$share}% of in-scope decisions going against the recommendation on a factor we already measure.",
                    'action' => "Review business.weights.{$a['axis']} — the operation appears to value {$a['axis']} more highly than the current configuration does.",
                ];
            } elseif ($a['count'] >= $minPerReason) {
                $out[] = [
                    'kind'   => 'data',
                    'axis'   => $a['axis'],
                    'detail' => "{$a['count']} overrides cite {$a['axis']}, but our figures do not show the chosen garage was better on it.",
                    'action' => "Check the {$a['axis']} data before touching any weight — either supervisors are working from information we do not hold, or our {$a['axis']} figures are wrong.",
                ];
            }
        }

        foreach ($byReason as $r) {
            if ($r['reason'] === 'not_stated' && $r['count'] >= $minPerReason) {
                $out[] = [
                    'kind'   => 'capture',
                    'axis'   => null,
                    'detail' => "{$r['count']} overrides have no stated reason.",
                    'action' => 'These teach nothing. Check the assign screen is asking for a reason and that it is not being skipped.',
                ];
            }
            if ($r['axis'] === 'evidence' && $r['count'] >= $minPerReason) {
                $out[] = [
                    'kind'   => 'data',
                    'axis'   => 'evidence',
                    'detail' => "{$r['count']} overrides cite expertise the history does not reflect.",
                    'action' => 'A specialism exists that our fault extraction is not capturing. Look at those garages in the fault-extraction audit before adjusting any score.',
                ];
            }
            // A cluster of near-ties is the one case where the engine plausibly just got it wrong.
            if ($r['in_scope'] && $r['count'] >= $minPerReason && $r['near_ties'] >= (int) ceil($r['count'] * 0.6)) {
                $out[] = [
                    'kind'   => 'tie_break',
                    'axis'   => $r['axis'],
                    'detail' => "{$r['near_ties']} of {$r['count']} '{$r['label']}' overrides were near-ties on score.",
                    'action' => "The engine nearly agreed in these cases. Consider whether {$r['axis']} should break a tie when two garages are within a few points.",
                ];
            }
        }

        return $out;
    }

    private function axisOf(?string $reason): string
    {
        if ($reason === null || $reason === '' || $reason === 'not_stated') {
            return 'not_stated';
        }
        return $this->taxonomy[$reason]['axis'] ?? 'other';
    }

    /** @param  array<int, int|float>  $values */
    private function median(array $values): float
    {
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);
        return round($n % 2 ? (float) $values[$mid] : ((float) $values[$mid - 1] + (float) $values[$mid]) / 2, 1);
    }
}
