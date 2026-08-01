<?php

namespace App\Services\Garage;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * "If we send the car here, what is likely to happen?" — the forecast layer under the garage
 * recommendation. Turns the maintenance corpus into four forward-looking numbers per garage:
 *
 *   turnaround  how many days the car will be off the road
 *   comeback    how likely the fault is to return (and so the expected first-time-fix rate)
 *   cost        what the repair is likely to bill
 *   queue       how much work is in front of it, and therefore when it can start
 *
 * EVERY forecast carries its BASIS and its SAMPLE. That is the whole discipline of this class: a number
 * derived from this garage's own record on this fault, a number borrowed from the garage's overall
 * record, and a number that is really just the fleet average are three very different claims, and the
 * operator has to be able to tell them apart. When nothing grounds a forecast we return `unavailable`
 * and the UI says so — we never dress a fleet median up as a garage's estimate.
 *
 * Basis ladder, strongest first: `garage_fault` → `garage` → `fleet` → `unavailable`.
 *
 * ── Why these sources and not the obvious ones ────────────────────────────────────────────────────
 * Turnaround comes from `maintenances.out_date → actual_in_date` (6,600 timed repairs), NOT from the
 * workflow timestamps (`repair_started_at`/`ready_at`), which cover 7 tickets, nor from
 * `maintenance_tasks` (39 rows). Comeback reuses the EXACT recurrence proxy that
 * App\Kpi\OperationalKpiService publishes as the fleet baseline — same 90-day window, same
 * `is_exposure = 0` exclusion, same strict `>` comparison — so a garage's rate and the fleet's rate are
 * the same measurement and can honestly be compared. Diverging from it would produce two "comeback
 * rates" in one product that disagree.
 *
 * Cost NO LONGER comes from `maintenances.cost` (314 priced repairs, 294 of them at a single garage — so
 * three garages had a figure of their own and everyone else wore the fleet median). It is rebuilt from
 * the vehicle expense ledger by {@see RepairCostEstimator}, which raises that to 79 garages and adds a
 * per-fault grain. Distance/transport is absent entirely — `vendors` stores no address or coordinates —
 * and is reported as unavailable rather than invented.
 *
 * READ-ONLY and cached. See [[garage-recommendation-engine]] and [[operational-kpi-baseline]].
 *
 * RETIRED TICKETS ARE INCLUDED, DELIBERATELY. `maintenances` is soft-deleted; the raw queries below do
 * not inherit the model's scope and are not meant to. This class measures WHAT HAPPENED, and a retired
 * ticket is still a repair that occurred — excluding it would let history change whenever somebody
 * tidied the board, and would move a denominator without its numerator. Live operational surfaces take
 * the opposite rule and filter `deleted_at` explicitly. See docs/Maintenance-Deletion-Model.md.
 */
class GarageOutcomeForecaster
{
    private const CACHE_KEY = 'intelligence:garage_outcomes:v1';

    /**
     * Forecasts for every garage, keyed by vendor id, plus the fleet fallbacks they degrade to.
     *
     * @param  array<int, string>  $faults  the ticket's fault categories, for the garage+fault grain
     * @return array{vendors: array<int, array<string, mixed>>, fleet: array<string, mixed>}
     */
    public function forecast(array $faults = [], ?string $model = null): array
    {
        $cfg = (array) config('garage_recommendation.outcomes', []);
        $base = $this->corpus($cfg);
        // Cost comes from the expense ledger, not from the corpus — a separate source with its own
        // cache, its own basis ladder and its own failure modes.
        $estimator = app(RepairCostEstimator::class);
        $costs = $estimator->index();

        // The live queue is NOT cached with the corpus — it is current state, and a stale queue would
        // misreport when a garage can start.
        $queue = $this->queueByVendor();

        $fleet = $base['fleet'];
        $vendors = [];
        foreach ($base['vendors'] as $vid => $v) {
            $vendors[$vid] = $this->assemble($vid, $v, $fleet, $faults, $queue[$vid] ?? 0, $cfg, $costs, $model);
        }
        // A garage with no corpus history at all still needs a forecast row (it may hold a live queue).
        foreach ($queue as $vid => $open) {
            $vendors[$vid] ??= $this->assemble($vid, ['duration' => [], 'comeback' => null, 'cost' => []], $fleet, $faults, $open, $cfg, $costs, $model);
        }

        // Cost freshness rides WITH the estimates. A frozen ledger keeps producing precise-looking
        // medians for a period that has ended, and the operator acting on one deserves to know.
        return ['vendors' => $vendors, 'fleet' => $fleet, 'cost_meta' => $costs->meta(), 'cost_freshness' => $estimator->freshness()];
    }

    /** Build one garage's forecast from its aggregates, degrading down the basis ladder. */
    private function assemble(int $vid, array $v, array $fleet, array $faults, int $open, array $cfg, ?RepairCostIndex $costs = null, ?string $model = null): array
    {
        $min = (array) ($cfg['min_sample'] ?? []);

        // ── Turnaround ────────────────────────────────────────────────────────────────────────────
        // Prefer this garage's record on THESE faults; fall back to its overall record, then the fleet.
        $faultDur = $this->pickFaultGrain($v['duration'] ?? [], $faults);
        $allDur   = $v['duration']['__all'] ?? null;
        $durSupport = [
            'garage_fault' => (int) ($faultDur['n'] ?? 0),
            'garage'       => (int) ($allDur['n'] ?? 0),
            'fleet'        => (int) $fleet['duration_n'],
        ];
        if ($faultDur && $faultDur['n'] >= (int) ($min['duration_garage_fault'] ?? 5)) {
            $duration = $this->stat($faultDur['median'], 'garage_fault', $faultDur['n'], $durSupport);
            $duration['p90'] = $faultDur['p90'];
        } elseif ($allDur && $allDur['n'] >= (int) ($min['duration_garage'] ?? 5)) {
            $duration = $this->stat($allDur['median'], 'garage', $allDur['n'], $durSupport);
            $duration['p90'] = $allDur['p90'];
        } else {
            $duration = $this->stat($fleet['duration_days'], 'fleet', $fleet['duration_n'], $durSupport,
                'No timed repairs recorded for this garage.');
            $duration['p90'] = $fleet['duration_p90'];
        }

        // ── Comeback risk → expected first-time-fix ───────────────────────────────────────────────
        $cb = $v['comeback'] ?? null;
        $cbSupport = ['garage' => (int) ($cb['n'] ?? 0), 'fleet' => (int) $fleet['comeback_n']];
        if ($cb && $cb['n'] >= (int) ($min['comeback_garage'] ?? 30)) {
            $comeback = $this->stat(round($cb['returned'] / max($cb['n'], 1) * 100, 1), 'garage', $cb['n'], $cbSupport);
        } else {
            $comeback = $this->stat($fleet['comeback_pct'], 'fleet', $fleet['comeback_n'], $cbSupport,
                'Fewer than ' . (int) ($min['comeback_garage'] ?? 30) . ' attributable repairs at this garage.');
        }
        $success = $comeback['value'] === null
            ? $this->stat(null, 'unavailable', 0, $cbSupport, 'Comeback rate could not be measured, so success cannot be derived.')
            : $this->stat(round(100 - $comeback['value'], 1), $comeback['basis'], $comeback['sample'], $cbSupport);

        // ── Cost, from the expense ledger ─────────────────────────────────────────────────────────
        // The ladder is garage+fault+model → garage+fault → garage → fleet, and the rung it landed on
        // travels with the figure. `cost_by_fault` carries the per-category prices that ARE earned;
        // a category with too little history simply does not appear, rather than borrowing a number.
        if ($costs !== null) {
            $c = $costs->estimate($vid, $faults, $model);
            $cost = $this->stat($c['value'], $c['basis'], $c['sample'], $c['support'], $c['reason']);
            $costByFault = $costs->breakdown($vid, $faults, $model);
        } else {
            // Pure-core / test path: no ledger injected means no cost claim, not a fabricated one.
            $cost = $this->stat(null, 'unavailable', 0, [], 'Repair cost estimation is not available in this context.');
            $costByFault = [];
        }

        // ── Queue → when can they start, when would they finish ───────────────────────────────────
        $perTicket = (float) ($cfg['queue_days_per_open_ticket'] ?? 0.5);
        $startInDays = round($open * $perTicket, 1);
        $completeInDays = $duration['value'] === null ? null : round($startInDays + $duration['value'], 1);

        // The risk case is published as its OWN figure, not folded into the expected value. Calibration
        // shows the median forecast running consistently low, and hiding that inside a wider single
        // number would obscure the weakness instead of stating it: the operator needs the normal case
        // to plan with and the risk case to protect against, and they are different questions.
        $risk = $duration['value'] === null || $duration['p90'] === null
            ? $this->stat(null, 'unavailable', 0, $durSupport, 'No turnaround distribution available for this garage.')
            : $this->stat($duration['p90'], $duration['basis'], $duration['sample'], $durSupport, $duration['reason']);

        return [
            'duration_days'    => $duration,
            'duration_p90'     => $risk,
            'comeback_pct'     => $comeback,
            'success_pct'      => $success,
            'cost_aed'         => $cost,
            'cost_by_fault'    => $costByFault,
            'queue_open'       => ['value' => $open, 'basis' => 'live', 'sample' => $open],
            'busy'             => $open >= (int) ($cfg['queue_busy_threshold'] ?? 4),
            'start_in_days'    => $startInDays,
            'complete_in_days' => $completeInDays,
            // No address or coordinates exist on `vendors`, so this is honestly unknown rather than 0.
            // The reason names the FIX, not just the gap: this one is a configuration problem, not a
            // data-volume problem, and the two need different responses from whoever reads it.
            'transport'        => $this->stat(null, 'unavailable', 0, [],
                'Vendor location has not been configured, so transport impact cannot be calculated.'),
        ];
    }

    /** The best fault-grain duration row for the queried faults (most-sampled wins). */
    private function pickFaultGrain(array $byFault, array $faults): ?array
    {
        $best = null;
        foreach ($faults as $f) {
            $row = $byFault[$f] ?? null;
            if ($row && ($best === null || $row['n'] > $best['n'])) {
                $best = $row;
            }
        }
        return $best;
    }

    /**
     * One forecast figure, carrying everything needed to judge how much to trust it.
     *
     * `confidence` is derived from the basis AND the sample, because those are different failures: a
     * garage-specific median off 4 repairs is thin, and a fleet median off 6,000 is precise but not about
     * this garage at all. Both deserve to be marked down, for different reasons, and `support` publishes
     * the counts behind each rung so the UI can show its working.
     *
     * `reason` is mandatory whenever a value is absent — "unavailable" with no explanation is the kind of
     * silent gap that makes users assume the system is broken rather than honest.
     *
     * @return array{value: float|int|null, basis: string, sample: int, confidence: ?string, reason: ?string, support: array<string,int>}
     */
    private function stat($value, string $basis, ?int $sample, array $support = [], ?string $reason = null): array
    {
        if ($value === null) {
            return [
                'value' => null, 'basis' => 'unavailable', 'sample' => 0, 'confidence' => null,
                'reason' => $reason ?? 'No historical data for this measure yet.',
                'support' => $support,
            ];
        }
        $n = (int) $sample;
        $confidence = match ($basis) {
            // The finest grain: this garage, this fault, this model. It earns High on fewer samples
            // precisely because each one answers the exact question being asked.
            'garage_fault_model' => $n >= 10 ? 'high' : ($n >= 5 ? 'medium' : 'low'),
            'garage_fault' => $n >= 10 ? 'high' : ($n >= 5 ? 'medium' : 'low'),
            'garage'       => $n >= 30 ? 'high' : ($n >= 10 ? 'medium' : 'low'),
            // A fleet median is a precise answer to a question nobody asked about THIS garage.
            'fleet'        => 'low',
            default        => 'low',
        };

        return [
            'value' => $value, 'basis' => $basis, 'sample' => $n,
            'confidence' => $confidence,
            // A caller-supplied reason is the specific one and always wins; the generic sentence is
            // only a floor so a demoted figure can never appear without SOME explanation.
            'reason' => $reason ?? ($basis === 'fleet' ? 'Not enough repairs at this garage — showing the fleet average instead.' : null),
            'support' => $support,
        ];
    }

    // ── Corpus aggregates (cached) ────────────────────────────────────────────────────────────────

    /**
     * The expensive half: per-garage turnaround, comeback and cost aggregates plus the fleet fallbacks.
     * Cached because the recurrence proxy is a correlated subquery over ~31k signature rows. A cache
     * failure degrades to a live rebuild — a forecast must never 500 the recommendation.
     *
     * @return array{vendors: array<int, array<string, mixed>>, fleet: array<string, mixed>}
     */
    private function corpus(array $cfg): array
    {
        try {
            return Cache::remember(
                self::CACHE_KEY,
                (int) ($cfg['cache_ttl'] ?? 900),
                fn () => $this->buildCorpus($cfg),
            );
        } catch (\Throwable $e) {
            report($e);
            return $this->buildCorpus($cfg);
        }
    }

    private function buildCorpus(array $cfg): array
    {
        $vendors = [];
        $outlier = (int) ($cfg['duration_outlier_days'] ?? 60);

        // ── Turnaround, per garage and per garage+fault ────────────────────────────────────────────
        // Medians, not means: a single 102-day dispute would otherwise drag a garage's forecast up by
        // weeks. Computed in PHP because MySQL 5.7/MariaDB have no percentile function.
        $durRows = DB::table('maintenances as m')
            ->leftJoin('maintenance_tasks as t', 't.maintenance_id', '=', 'm.id')
            ->whereNotNull('m.vendor_id')
            ->whereNotNull('m.out_date')
            ->whereNotNull('m.actual_in_date')
            ->whereColumn('m.actual_in_date', '>=', 'm.out_date')
            ->whereRaw('DATEDIFF(m.actual_in_date, m.out_date) <= ?', [$outlier])
            ->distinct()
            ->get([
                'm.id', 'm.vendor_id', 't.category_key',
                DB::raw('DATEDIFF(m.actual_in_date, m.out_date) as days'),
            ]);

        $durBuckets = [];   // vendor => grain => [days…]
        $fleetDays = [];
        $seen = [];         // a ticket with two tasks must not count twice toward "__all"
        foreach ($durRows as $r) {
            $vid = (int) $r->vendor_id;
            $d = (int) $r->days;
            if (! isset($seen[$r->id])) {
                $seen[$r->id] = true;
                $durBuckets[$vid]['__all'][] = $d;
                $fleetDays[] = $d;
            }
            if ($r->category_key) {
                $durBuckets[$vid][$r->category_key][] = $d;
            }
        }
        foreach ($durBuckets as $vid => $grains) {
            foreach ($grains as $grain => $days) {
                $vendors[$vid]['duration'][$grain] = [
                    'median' => $this->median($days),
                    // Turnaround is heavily right-skewed (fleet median 1 day, p90 6, max 60), so the
                    // median alone systematically UNDERstates how long a car may be gone — the backtest
                    // measured exactly that bias. p90 is carried so the UI can show a realistic range
                    // instead of a falsely precise point estimate.
                    'p90'    => $this->percentile($days, 0.90),
                    'n'      => count($days),
                ];
            }
        }

        // ── Comeback, per garage ───────────────────────────────────────────────────────────────────
        // The SAME proxy App\Kpi\OperationalKpiService publishes fleet-wide, attributed to the garage
        // that did the repair. Keep this query in step with COMEBACK_WINDOW_DAYS or the per-garage rates
        // stop reconciling with the published baseline.
        $window = (int) ($cfg['comeback_window_days'] ?? 90);
        $cbRows = DB::select('
            SELECT m.vendor_id, COUNT(*) AS n,
                   SUM(CASE WHEN EXISTS (
                        SELECT 1 FROM maintenance_signatures b
                        WHERE b.vehicle_id  = a.vehicle_id
                          AND b.signature   = a.signature
                          AND b.occurred_at > a.occurred_at
                          AND b.occurred_at <= DATE_ADD(a.occurred_at, INTERVAL ? DAY)
                   ) THEN 1 ELSE 0 END) AS returned
            FROM maintenance_signatures a
            JOIN maintenances m ON m.id = a.maintenance_id
            WHERE a.vehicle_id IS NOT NULL AND a.occurred_at IS NOT NULL AND a.is_exposure = 0
              AND m.vendor_id IS NOT NULL
            GROUP BY m.vendor_id', [$window]);

        $fleetN = 0;
        $fleetReturned = 0;
        foreach ($cbRows as $r) {
            $vendors[(int) $r->vendor_id]['comeback'] = ['n' => (int) $r->n, 'returned' => (int) $r->returned];
            $fleetN += (int) $r->n;
            $fleetReturned += (int) $r->returned;
        }

        // ── Cost, per garage ───────────────────────────────────────────────────────────────────────
        $costRows = DB::table('maintenances')->whereNotNull('vendor_id')->where('cost', '>', 0)->get(['vendor_id', 'cost']);
        $costBuckets = [];
        $fleetCosts = [];
        foreach ($costRows as $r) {
            $costBuckets[(int) $r->vendor_id][] = (float) $r->cost;
            $fleetCosts[] = (float) $r->cost;
        }
        foreach ($costBuckets as $vid => $costs) {
            $vendors[$vid]['cost'] = ['median' => $this->median($costs), 'n' => count($costs)];
        }

        return [
            'vendors' => $vendors,
            'fleet' => [
                'duration_days' => $this->median($fleetDays),
                'duration_p90'  => $this->percentile($fleetDays, 0.90),
                'duration_n'    => count($fleetDays),
                'comeback_pct'  => $fleetN > 0 ? round($fleetReturned / $fleetN * 100, 1) : null,
                'comeback_n'    => $fleetN,
                'cost_median'   => $this->median($fleetCosts),
                'cost_n'        => count($fleetCosts),
            ],
        ];
    }

    /**
     * Open tickets per garage — the live queue. Anything not yet closed and currently pointed at a
     * vendor counts as work in front of a new car.
     *
     * @return array<int, int>
     */
    private function queueByVendor(): array
    {
        $open = (array) config('garage_recommendation.outcomes.open_statuses', [
            'awaiting_dispatch', 'in_transit', 'at_garage', 'under_repair',
            'ready_for_pickup', 'ready_for_reinspection', 'reinspection_failed', 'awaiting_invoice',
        ]);

        return DB::table('maintenances')
            ->whereNotNull('vendor_id')
            ->whereIn('workflow_status', $open)
            ->groupBy('vendor_id')
            ->selectRaw('vendor_id, COUNT(*) as c')
            ->pluck('c', 'vendor_id')
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    /** @param array<int, float|int> $values */
    private function percentile(array $values, float $q): ?float
    {
        if (empty($values)) {
            return null;
        }
        sort($values);
        return round((float) $values[min(count($values) - 1, (int) floor($q * count($values)))], 1);
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
        return round($n % 2 ? (float) $values[$mid] : ((float) $values[$mid - 1] + (float) $values[$mid]) / 2, 1);
    }
}
