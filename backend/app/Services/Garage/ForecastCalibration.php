<?php

namespace App\Services\Garage;

use App\Models\GarageRecommendationDecision;
use Illuminate\Support\Facades\DB;

/**
 * Was the forecast any good?
 *
 * GarageOutcomeForecaster makes claims — 4.2 days, 96% success, AED 420. A claim nobody checks is just
 * a confident-sounding number, so this class closes the loop: it compares what we PREDICTED against what
 * actually HAPPENED, and reports both accuracy and BIAS (the direction we are wrong in), which is the
 * part you can actually act on. "We overestimate this garage's turnaround by 1.2 days" is a fixable
 * finding; "duration accuracy 91%" alone is not.
 *
 * Two modes, because they answer the same question at different times:
 *
 *   SCORE     — the real feedback loop. Walks recorded dispatch decisions that carry a forecast, finds
 *               what the repair actually did, and stores the comparison on the decision row. Truthful
 *               but slow to accumulate: it can only score decisions made after forecasting shipped.
 *   BACKTEST  — the same question asked of history. For each completed repair, predict it using the
 *               garage's OTHER repairs (leave-one-out, so a repair never predicts itself) and score that
 *               prediction. Gives a calibration reading today from ~6,600 completed repairs instead of
 *               waiting months for live decisions to pile up.
 *
 * Backtest measures the METHOD; score measures the SYSTEM AS DEPLOYED. They will disagree, and that
 * disagreement is informative — it is the gap between the forecaster and how people actually used it.
 *
 * ── On the accuracy metric ────────────────────────────────────────────────────────────────────────
 * Continuous figures use symmetric relative error, `1 - |a-p| / (a+p)`, not MAPE. Fleet turnaround has a
 * median of 1 day, so MAPE explodes on the many 0-day repairs and would report nonsense. The symmetric
 * form is bounded, handles zeros, and treats "predicted 2, got 1" the same as "predicted 1, got 2".
 * Comeback is a probability against a 0/1 outcome, so it is scored with Brier, and its calibration is
 * reported separately as predicted-mean vs observed-rate — a model can be accurate and badly calibrated.
 *
 * READ-MOSTLY: `score()` writes only to garage_recommendation_decisions. See
 * [[garage-recommendation-engine]] and [[operational-kpi-baseline]].
 */
class ForecastCalibration
{
    /**
     * How close the forecast was, in 0..1. Symmetric, bounded, and SMOOTHED.
     *
     * `$floor` is the "who cares" tolerance — an error smaller than this is not a meaningful miss. It is
     * not a fudge factor; without it the metric is degenerate on this data and reports nonsense.
     *
     * Fleet turnarounds are small discrete integers (median 1 day, a third of them 0). Unsmoothed, the
     * denominator |a|+|p| collapses toward zero and one day of error scores a flat 0. Worse, on a model
     * whose repairs are [0,0,1,1,4,…] the leave-one-out median flips across the 0/1 boundary, so EVERY
     * observation scores exactly 0 — six YUKON-class models reported "0% accuracy" for that reason alone,
     * with a mean error of a quarter of a day. That is a broken ruler, not a broken forecast.
     *
     * With a one-day floor, "predicted 0, got 1" scores 0.5 (a real but minor miss) while "predicted 1,
     * got 4" scores 0.2. MAPE was rejected for the same underlying reason: it divides by zero here.
     */
    public static function accuracy(float $actual, float $predicted, float $floor = 1.0): float
    {
        $denom = abs($actual) + abs($predicted) + max($floor, 0.0);
        return $denom <= 0.0 ? 1.0 : 1 - min(1.0, abs($actual - $predicted) / $denom);
    }

    // =============================================================================================
    // BACKTEST — calibrate the method against completed history
    // =============================================================================================

    /**
     * Leave-one-out backtest of the duration forecast, overall and per garage.
     *
     * @return array<string, mixed>
     */
    public function backtestDuration(int $minSample = 5, int $outlierDays = 60): array
    {
        $rows = DB::table('maintenances')
            ->whereNotNull('vendor_id')->whereNotNull('out_date')->whereNotNull('actual_in_date')
            ->whereColumn('actual_in_date', '>=', 'out_date')
            ->whereRaw('DATEDIFF(actual_in_date, out_date) <= ?', [$outlierDays])
            ->selectRaw('vendor_id, DATEDIFF(actual_in_date, out_date) as days')
            ->get();

        $byVendor = [];
        foreach ($rows as $r) {
            $byVendor[(int) $r->vendor_id][] = (int) $r->days;
        }

        $all = [];
        $perGarage = [];
        foreach ($byVendor as $vid => $days) {
            if (count($days) < $minSample) {
                continue;   // too thin to forecast from, so the forecaster would have used the fleet median
            }
            $acc = [];
            $err = [];
            foreach ($days as $i => $actual) {
                // Leave-one-out: a repair must never help predict itself, or the score is self-congratulation.
                $others = $days;
                unset($others[$i]);
                $predicted = $this->median(array_values($others));
                if ($predicted === null) {
                    continue;
                }
                $acc[] = self::accuracy((float) $actual, $predicted);
                $err[] = $predicted - $actual;   // positive = we OVERestimated
            }
            if (empty($acc)) {
                continue;
            }
            $perGarage[$vid] = [
                'vendor_id' => $vid,
                'n'         => count($acc),
                'accuracy'  => round(array_sum($acc) / count($acc) * 100, 1),
                'bias_days' => round(array_sum($err) / count($err), 2),
            ];
            $all = array_merge($all, $acc);
        }

        return [
            'metric'    => 'duration',
            'unit'      => 'days',
            'n'         => count($all),
            'accuracy'  => empty($all) ? null : round(array_sum($all) / count($all) * 100, 1),
            'per_garage' => $perGarage,
        ];
    }

    /**
     * Leave-one-out backtest of the comeback forecast. Uses the SAME recurrence proxy as the KPI
     * baseline, and only counts repairs whose 90-day window has fully elapsed — a repair from last week
     * has not had the chance to come back, and scoring it as a success would flatter the model.
     *
     * @return array<string, mixed>
     */
    public function backtestComeback(int $minSample = 30, int $window = 90): array
    {
        $rows = DB::select('
            SELECT m.vendor_id,
                   EXISTS (
                        SELECT 1 FROM maintenance_signatures b
                        WHERE b.vehicle_id  = a.vehicle_id
                          AND b.signature   = a.signature
                          AND b.occurred_at > a.occurred_at
                          AND b.occurred_at <= DATE_ADD(a.occurred_at, INTERVAL ? DAY)
                   ) AS returned
            FROM maintenance_signatures a
            JOIN maintenances m ON m.id = a.maintenance_id
            WHERE a.vehicle_id IS NOT NULL AND a.occurred_at IS NOT NULL AND a.is_exposure = 0
              AND m.vendor_id IS NOT NULL
              AND a.occurred_at <= DATE_SUB(CURDATE(), INTERVAL ? DAY)', [$window, $window]);

        $byVendor = [];
        foreach ($rows as $r) {
            $byVendor[(int) $r->vendor_id][] = (int) $r->returned;
        }

        $briers = [];
        $predSum = 0.0;
        $obsSum = 0;
        $n = 0;
        $perGarage = [];
        foreach ($byVendor as $vid => $outcomes) {
            if (count($outcomes) < $minSample) {
                continue;
            }
            $total = array_sum($outcomes);
            $count = count($outcomes);
            $g = [];
            foreach ($outcomes as $actual) {
                // Leave-one-out rate, so this repair does not contribute to its own prediction.
                $p = ($total - $actual) / max($count - 1, 1);
                $briers[] = ($p - $actual) ** 2;
                $g[] = ($p - $actual) ** 2;
                $predSum += $p;
                $obsSum += $actual;
                $n++;
            }
            $perGarage[$vid] = [
                'vendor_id'      => $vid,
                'n'              => $count,
                'accuracy'       => round((1 - array_sum($g) / count($g)) * 100, 1),
                'predicted_rate' => round($total / $count * 100, 1),
            ];
        }

        return [
            'metric'   => 'comeback',
            'unit'     => 'percent',
            'n'        => $n,
            // 1 - Brier: a perfectly confident, perfectly right model scores 100.
            'accuracy' => $n === 0 ? null : round((1 - array_sum($briers) / $n) * 100, 1),
            'calibration' => $n === 0 ? null : [
                'predicted_mean' => round($predSum / $n * 100, 1),
                'observed_rate'  => round($obsSum / $n * 100, 1),
            ],
            'per_garage' => $perGarage,
        ];
    }

    /**
     * Cost backtest. Almost certainly returns `insufficient` — only a few hundred repairs carry a cost
     * and barely any garage clears the sample bar. Reporting that plainly is the point.
     *
     * @return array<string, mixed>
     */
    public function backtestCost(int $minSample = 5): array
    {
        $rows = DB::table('maintenances')->whereNotNull('vendor_id')->where('cost', '>', 0)->get(['vendor_id', 'cost']);
        $byVendor = [];
        foreach ($rows as $r) {
            $byVendor[(int) $r->vendor_id][] = (float) $r->cost;
        }

        $all = [];
        $perGarage = [];
        foreach ($byVendor as $vid => $costs) {
            if (count($costs) < $minSample) {
                continue;
            }
            $acc = [];
            $err = [];
            foreach ($costs as $i => $actual) {
                $others = $costs;
                unset($others[$i]);
                $predicted = $this->median(array_values($others));
                if ($predicted === null) {
                    continue;
                }
                $acc[] = self::accuracy($actual, $predicted);
                $err[] = $predicted - $actual;
            }
            if (empty($acc)) {
                continue;
            }
            $perGarage[$vid] = [
                'vendor_id' => $vid,
                'n'         => count($acc),
                'accuracy'  => round(array_sum($acc) / count($acc) * 100, 1),
                'bias_aed'  => round(array_sum($err) / count($err), 2),
            ];
            $all = array_merge($all, $acc);
        }

        return [
            'metric'   => 'cost',
            'unit'     => 'AED',
            'n'        => count($all),
            'accuracy' => empty($all) ? null : round(array_sum($all) / count($all) * 100, 1),
            'insufficient' => count($all) === 0 ? 'Too few priced repairs to calibrate cost at any garage.' : null,
            'per_garage' => $perGarage,
        ];
    }

    /**
     * Duration calibration BY SEGMENT — a fleet-wide 51% hides that engine work may be well predicted
     * while bodywork is guesswork. Segments: fault category, vehicle model and criticality tier.
     *
     * The fault label comes from `maintenance_signatures` (~31k rows) rather than
     * `maintenance_tasks.category_key` (a few dozen rows), then is translated into the product's
     * 12-category vocabulary through config `criticality.signature_categories`. A signature with no
     * mapping is reported as `unmapped` rather than folded into a category it does not belong to.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function backtestBySegment(int $minSample = 20, int $outlierDays = 60): array
    {
        $sigMap = (array) config('garage_recommendation.criticality.signature_categories', []);
        $critCfg = (array) config('garage_recommendation.criticality', []);
        $labels = [];
        foreach ((array) config('maintenance_findings.categories', []) as $c) {
            if (! empty($c['key'])) {
                $labels[$c['key']] = $c['label'] ?? $c['key'];
            }
        }
        $criticality = new FaultCriticality();

        $rows = DB::table('maintenances as m')
            ->join('vehicles as v', 'v.id', '=', 'm.vehicle_id')
            ->leftJoin('maintenance_signatures as s', function ($j) {
                $j->on('s.maintenance_id', '=', 'm.id')->where('s.is_exposure', '=', 0);
            })
            ->whereNotNull('m.vendor_id')->whereNotNull('m.out_date')->whereNotNull('m.actual_in_date')
            ->whereColumn('m.actual_in_date', '>=', 'm.out_date')
            ->whereRaw('DATEDIFF(m.actual_in_date, m.out_date) <= ?', [$outlierDays])
            ->selectRaw('m.id, m.vendor_id, v.model, s.signature, DATEDIFF(m.actual_in_date, m.out_date) as days')
            ->get();

        // Bucket the observations. A ticket with two signatures contributes to both fault segments —
        // correct, since the forecast would have been asked about both — but only once per model.
        $buckets = ['fault' => [], 'model' => [], 'criticality' => []];
        $seenModel = [];
        foreach ($rows as $r) {
            $days = (int) $r->days;
            if ($r->signature) {
                $cat = $sigMap[$r->signature] ?? null;
                $key = $cat ? ($labels[$cat] ?? $cat) : 'unmapped';
                $buckets['fault'][$key][] = $days;
                if ($cat) {
                    $tier = $criticality->resolve($cat, null, $critCfg);
                    $buckets['criticality'][$tier['label']][] = $days;
                }
            }
            if ($r->model && ! isset($seenModel[$r->id])) {
                $seenModel[$r->id] = true;
                $buckets['model'][$r->model][] = $days;
            }
        }

        $out = [];
        foreach ($buckets as $dimension => $groups) {
            $seg = [];
            foreach ($groups as $name => $days) {
                if (count($days) < $minSample) {
                    continue;
                }
                $acc = [];
                $err = [];
                foreach ($days as $i => $actual) {
                    $others = $days;
                    unset($others[$i]);
                    $p = $this->median(array_values($others));
                    if ($p === null) {
                        continue;
                    }
                    $acc[] = self::accuracy((float) $actual, $p);
                    $err[] = $p - $actual;
                }
                if (empty($acc)) {
                    continue;
                }
                $seg[] = [
                    'segment'   => $name,
                    'n'         => count($acc),
                    'accuracy'  => round(array_sum($acc) / count($acc) * 100, 1),
                    'bias_days' => round(array_sum($err) / count($err), 2),
                ];
            }
            usort($seg, fn ($a, $b) => $a['accuracy'] <=> $b['accuracy']);   // worst first — that is the news
            $out[$dimension] = $seg;
        }

        return $out;
    }

    // =============================================================================================
    // ACTIONS — turn calibration into proposed changes
    // =============================================================================================

    /**
     * What should we DO about it? Measuring the engine is only half the loop; this turns each material
     * bias into a concrete, reviewable proposal.
     *
     * Proposals are NEVER auto-applied. Every one names the evidence, the change and the size of the
     * change, and is left for a human to accept — an engine that silently retunes itself from its own
     * output is one bad segment away from a feedback spiral, and nobody could explain a decision made
     * under a policy no person chose.
     *
     * @return array<int, array<string, mixed>>
     */
    public function suggestions(float $minBiasDays = 0.75, int $minSample = 20): array
    {
        $out = [];
        $names = DB::table('vendors')->pluck('name', 'id');

        // 1. Per-garage turnaround bias → a duration multiplier for that garage.
        foreach ($this->backtestDuration()['per_garage'] as $g) {
            if ($g['n'] < $minSample || abs($g['bias_days']) < $minBiasDays) {
                continue;
            }
            $observed = $this->observedMedian($g['vendor_id']);
            if ($observed === null) {
                continue;
            }
            $correction = -round($g['bias_days'], 2);   // bias is (predicted - actual); undo it

            // Corrections are stated in DAYS, not as a multiplier. A multiplicative form is unusable at
            // this scale: with a 1-day median, a 0.77-day error implies a 4.35× factor, which reads as a
            // catastrophe and would be catastrophic if anyone applied it. A ratio is only offered where
            // the median is big enough for one to mean anything.
            // A ratio needs BOTH terms to be substantial. Requiring only a positive denominator still
            // produced "×4.12" from a 4-day median against a 0.97-day prediction — arithmetically true,
            // operationally absurd. The result must also land in a plausible band or it is not offered.
            $corrected = $observed + $g['bias_days'];
            $multiplier = null;
            if ($observed >= 2.0 && $corrected >= 1.0) {
                $ratio = round($observed / $corrected, 2);
                $multiplier = ($ratio >= 0.5 && $ratio <= 3.0) ? $ratio : null;
            }

            $out[] = [
                'kind'      => 'duration_offset',
                'scope'     => 'garage',
                'target'    => $names[$g['vendor_id']] ?? ('#' . $g['vendor_id']),
                'target_id' => $g['vendor_id'],
                'evidence'  => "Turnaround predictions are {$g['bias_days']} days off across {$g['n']} repairs ("
                    . ($g['bias_days'] > 0 ? 'over' : 'under') . 'estimated); observed median '
                    . $observed . ' days.',
                'action'    => ($correction > 0 ? 'Add ' : 'Subtract ') . abs($correction)
                    . ' days to this garage\'s duration estimate'
                    . ($multiplier !== null ? " (equivalently ×{$multiplier})." : '.'),
                'value'      => $correction,
                'multiplier' => $multiplier,
                'confidence' => $g['n'] >= 50 ? 'high' : 'medium',
            ];
        }

        // 2. Per-segment turnaround bias → a multiplier for that fault category / model.
        foreach ($this->backtestBySegment($minSample) as $dimension => $segments) {
            foreach ($segments as $s) {
                if (abs($s['bias_days']) < $minBiasDays) {
                    continue;
                }
                $correction = -round($s['bias_days'], 2);
                $out[] = [
                    'kind'      => 'duration_offset',
                    'scope'     => $dimension,
                    'target'    => $s['segment'],
                    'target_id' => null,
                    'evidence'  => "{$s['segment']} duration accuracy is {$s['accuracy']}% across {$s['n']} repairs, "
                        . 'biased ' . ($s['bias_days'] > 0 ? 'high' : 'low') . " by {$s['bias_days']} days.",
                    'action'    => ($correction > 0 ? 'Add ' : 'Subtract ') . abs($correction)
                        . " days to the duration estimate for {$s['segment']} ({$dimension}).",
                    'value'      => $correction,
                    'multiplier' => null,
                    'confidence' => $s['n'] >= 50 ? 'high' : 'medium',
                ];
            }
        }

        // 3. Comeback mis-calibration → trust the forecast less, rather than silently shifting the number.
        $cb = $this->backtestComeback();
        if (($cb['calibration'] ?? null) && $cb['n'] > 0) {
            $gap = $cb['calibration']['observed_rate'] - $cb['calibration']['predicted_mean'];
            if (abs($gap) >= 5) {
                $out[] = [
                    'kind'      => 'confidence_adjustment',
                    'scope'     => 'fleet',
                    'target'    => 'comeback forecast',
                    'target_id' => null,
                    'evidence'  => "Comeback risk is predicted at {$cb['calibration']['predicted_mean']}% but observed at "
                        . "{$cb['calibration']['observed_rate']}% across " . number_format($cb['n']) . ' repairs.',
                    'action'    => $gap > 0
                        ? 'Comeback risk is UNDERestimated — lower the confidence attached to success forecasts.'
                        : 'Comeback risk is OVERestimated — the forecast is pessimistic; review before it suppresses good garages.',
                    'value'     => round($gap, 1),
                    'confidence' => 'high',
                ];
            }
        }

        usort($out, fn ($a, $b) => abs((float) $b['value']) <=> abs((float) $a['value']));
        return $out;
    }

    /** The garage's observed median turnaround — the target a corrected forecast should land on. */
    private function observedMedian(int $vendorId): ?float
    {
        $days = DB::table('maintenances')
            ->where('vendor_id', $vendorId)
            ->whereNotNull('out_date')->whereNotNull('actual_in_date')
            ->whereColumn('actual_in_date', '>=', 'out_date')
            ->selectRaw('DATEDIFF(actual_in_date, out_date) as d')
            ->pluck('d')->map(fn ($d) => (int) $d)->all();

        return $this->median($days);
    }

    // =============================================================================================
    // SCORE — the live loop over recorded decisions
    // =============================================================================================

    /**
     * Compare each recorded dispatch decision's forecast against what the repair actually did.
     *
     * Only scores what can honestly be scored: a ticket that has not come back yet has no actual
     * duration, and comeback is left unscored until the full recurrence window has elapsed since the
     * repair — absence of a return is only evidence once there has been time to return.
     *
     * @return array{scored:int, skipped:int, pending_window:int}
     */
    public function score(int $window = 90, bool $dryRun = false): array
    {
        $scored = 0;
        $skipped = 0;
        $pendingWindow = 0;

        GarageRecommendationDecision::query()
            ->whereNotNull('expected_outcomes')
            ->whereNull('scored_at')
            ->with('maintenance:id,vendor_id,vehicle_id,out_date,actual_in_date,cost,wf_closed_at,repair_started_at,ready_at')
            ->chunkById(200, function ($decisions) use (&$scored, &$skipped, &$pendingWindow, $window, $dryRun) {
                foreach ($decisions as $d) {
                    $m = $d->maintenance;
                    $actual = $m ? $this->actualsFor($m, $window) : null;
                    if (! $actual || empty($actual['measured'])) {
                        $skipped++;
                        continue;
                    }
                    if (! empty($actual['comeback_pending'])) {
                        $pendingWindow++;
                    }

                    $accuracy = $this->compare((array) $d->expected_outcomes, $actual);
                    if ($dryRun) {
                        $scored++;
                        continue;
                    }
                    $d->forceFill([
                        'actual_outcomes'   => $actual,
                        'forecast_accuracy' => $accuracy,
                        'scored_at'         => now(),
                    ])->save();
                    $scored++;
                }
            });

        return ['scored' => $scored, 'skipped' => $skipped, 'pending_window' => $pendingWindow];
    }

    /** What the repair actually did. Absent measures are simply omitted — never defaulted to zero. */
    private function actualsFor($m, int $window): array
    {
        $out = ['measured' => []];

        // Prefer the workflow clock when it exists; fall back to the legacy out/in dates.
        $start = $m->repair_started_at ?? $m->out_date;
        $end   = $m->ready_at ?? $m->actual_in_date;
        if ($start && $end && strtotime((string) $end) >= strtotime((string) $start)) {
            $out['duration_days'] = round((strtotime((string) $end) - strtotime((string) $start)) / 86400, 1);
            $out['measured'][] = 'duration_days';
        }
        if ($m->cost > 0) {
            $out['cost_aed'] = round((float) $m->cost, 2);
            $out['measured'][] = 'cost_aed';
        }

        // Comeback: only decidable once the window has fully elapsed since the repair.
        $ref = $end ?? $m->wf_closed_at;
        if ($ref) {
            $elapsed = (time() - strtotime((string) $ref)) / 86400;
            if ($elapsed >= $window) {
                $returned = DB::table('maintenance_signatures as a')
                    ->join('maintenance_signatures as b', function ($j) use ($window) {
                        $j->on('b.vehicle_id', '=', 'a.vehicle_id')
                          ->on('b.signature', '=', 'a.signature')
                          ->whereColumn('b.occurred_at', '>', 'a.occurred_at')
                          ->whereRaw('b.occurred_at <= DATE_ADD(a.occurred_at, INTERVAL ? DAY)', [$window]);
                    })
                    ->where('a.maintenance_id', $m->id)
                    ->where('a.is_exposure', 0)
                    ->exists();
                $out['comeback_pct'] = $returned ? 100.0 : 0.0;
                $out['success_pct'] = $returned ? 0.0 : 100.0;
                $out['measured'][] = 'comeback_pct';
            } else {
                $out['comeback_pending'] = true;
                $out['comeback_window_days_remaining'] = (int) ceil($window - $elapsed);
            }
        }

        return $out;
    }

    /**
     * Predicted vs actual, per measure, with the direction of the error.
     *
     * @return array<string, mixed>
     */
    private function compare(array $expected, array $actual): array
    {
        $out = [];
        foreach (['duration_days', 'cost_aed', 'comeback_pct'] as $key) {
            if (! in_array($key, $actual['measured'] ?? [], true)) {
                continue;
            }
            $p = $expected[$key]['value'] ?? null;
            if ($p === null) {
                continue;
            }
            $a = (float) $actual[$key];
            $out[$key] = [
                'predicted'  => (float) $p,
                'actual'     => $a,
                'error'      => round((float) $p - $a, 2),   // positive = overestimated
                'accuracy'   => round(self::accuracy($a, (float) $p) * 100, 1),
                // The basis is kept so a garage-grain prediction is never averaged with a fleet fallback.
                'basis'      => $expected[$key]['basis'] ?? null,
            ];
        }
        return $out;
    }

    // =============================================================================================
    // Reporting
    // =============================================================================================

    /**
     * The headline calibration report — accuracy per metric, plus the garages we are most biased about,
     * which is the actionable half ("we overestimate this garage's turnaround by 1.2 days").
     *
     * @return array<string, mixed>
     */
    public function report(int $topBias = 5): array
    {
        $duration = $this->backtestDuration();
        $comeback = $this->backtestComeback();
        $cost = $this->backtestCost();

        $names = DB::table('vendors')->pluck('name', 'id');
        $bias = array_values($duration['per_garage']);
        usort($bias, fn ($a, $b) => abs($b['bias_days']) <=> abs($a['bias_days']));

        return [
            'generated_at' => now()->toIso8601String(),
            'metrics'      => ['duration' => $duration, 'comeback' => $comeback, 'cost' => $cost],
            'duration_bias' => array_map(fn ($g) => [
                'garage'    => $names[$g['vendor_id']] ?? ('#' . $g['vendor_id']),
                'vendor_id' => $g['vendor_id'],
                'n'         => $g['n'],
                'bias_days' => $g['bias_days'],
                'note'      => $g['bias_days'] > 0
                    ? 'We historically OVERestimate this garage\'s turnaround by ' . abs($g['bias_days']) . ' days.'
                    : 'We historically UNDERestimate this garage\'s turnaround by ' . abs($g['bias_days']) . ' days.',
            ], array_slice($bias, 0, $topBias)),
            'live' => $this->liveSummary(),
        ];
    }

    /** How much of the REAL loop (recorded decisions) has anything in it yet. */
    private function liveSummary(): array
    {
        $total = GarageRecommendationDecision::query()->whereNotNull('expected_outcomes')->count();
        $scored = GarageRecommendationDecision::query()->whereNotNull('scored_at')->count();

        return [
            'decisions_with_forecast' => $total,
            'scored'                  => $scored,
            'note' => $scored === 0
                ? 'No dispatch decisions have been scored yet — the live loop only covers decisions made after forecasting shipped. The metrics above are a leave-one-out backtest of the same method against completed history.'
                : null,
        ];
    }

    /** @param array<int, float|int> $values */
    private function median(array $values): ?float
    {
        if (empty($values)) {
            return null;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);
        return $n % 2 ? (float) $values[$mid] : ((float) $values[$mid - 1] + (float) $values[$mid]) / 2;
    }
}
