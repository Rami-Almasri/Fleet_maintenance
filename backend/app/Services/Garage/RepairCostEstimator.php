<?php

namespace App\Services\Garage;

use App\Contracts\VehicleExpenseProvider;
use App\Services\GarageRecommendationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Rebuilds repair cost from the vehicle expense ledger, because `maintenances.cost` cannot carry it.
 *
 * The measured position: 314 priced repairs fleet-wide out of 26,839, and 294 of those sit at a single
 * garage — so the old cost forecast gave three garages a figure of their own and handed everyone else the
 * fleet median wearing their name. The expense ledger holds the same money elsewhere: 28,327 lines,
 * AED 26.9M, with the garage and the work written into free-text `remarks`.
 *
 * Method, in one line: drop everything that is not a repair, then attribute what is left to the ticket
 * whose vehicle and dates surround it.
 *
 *   1. read the ledger through {@see VehicleExpenseProvider} — the ONLY sanctioned door to expense data
 *   2. drop non-repair lines by the reviewable `cost.exclude` config (fuel, insurance, RTA, fines, …)
 *   3. for each ticket, sum the surviving lines inside [out_date − 2d, out_date + 7d]
 *   4. bucket by garage / garage+fault / garage+fault+model and take medians
 *
 * ⚠️ Attribution is not itemisation. The window sums everything spent on that car in those nine days; two
 * overlapping repairs merge. The window is tight on purpose — widening it 0 → 7 → 14 → 30 days moves the
 * median 400 → 800 → 1,137 → 1,899, which is unrelated spending leaking in, not better pricing.
 *
 * ⚠️ Only SINGLE-fault tickets feed the per-fault buckets. Splitting a multi-fault ticket's spend across
 * its faults would require knowing the split, which is the very thing being estimated; apportioning it
 * evenly would manufacture evidence. 2,730 tickets qualify, which is enough for 83 garage+fault buckets.
 *
 * Validated against the 313 tickets whose real cost IS recorded: 39% exact, 50% within 10%, median error
 * 11.4% — on the 54 that were comparable. Published with `sample` and `basis` for that reason.
 *
 * See [[garage-recommendation-engine]], [[vehicle-expense-provider]], [[historical-knowledge-mining]].
 *
 * RETIRED TICKETS ARE INCLUDED, DELIBERATELY. `maintenances` is soft-deleted; the raw queries below do
 * not inherit the model's scope and are not meant to. This class measures WHAT HAPPENED, and a retired
 * ticket is still a repair that occurred — excluding it would let history change whenever somebody
 * tidied the board, and would move a denominator without its numerator. Live operational surfaces take
 * the opposite rule and filter `deleted_at` explicitly. See docs/Maintenance-Deletion-Model.md.
 */
class RepairCostEstimator
{
    private const CACHE_KEY = 'intelligence:repair_cost:v1';

    public function __construct(
        private VehicleExpenseProvider $expenses,
        private GarageRecommendationService $faults,
    ) {
    }

    /**
     * Is the ledger these estimates rest on still being maintained?
     *
     * Published WITH the estimates rather than hidden in an admin screen, because a frozen source fails
     * silently: the medians keep computing, keep looking precise, and quietly describe a period that
     * has ended. Anyone acting on a cost figure is entitled to know the spending behind it stopped.
     *
     * @return array<string, mixed>
     */
    public function freshness(): array
    {
        try {
            return $this->expenses->freshness();
        } catch (\Throwable $e) {
            Log::warning('expense freshness check failed: ' . $e->getMessage());
            return ['status' => 'unknown', 'message' => 'Could not determine whether the expense source is current.'];
        }
    }

    /** The cost index, cached — rebuilding it walks the whole ledger plus every ticket. */
    public function index(): RepairCostIndex
    {
        $cfg = (array) config('garage_recommendation.cost', []);
        $min = (array) ($cfg['min_sample'] ?? []);
        $minSample = [
            'garage_fault_model' => (int) ($min['garage_fault_model'] ?? 5),
            'garage_fault'       => (int) ($min['garage_fault'] ?? 5),
            'garage'             => (int) ($min['garage'] ?? 5),
        ];

        if (! ($cfg['enabled'] ?? true)) {
            return new RepairCostIndex([], $minSample);
        }

        try {
            $buckets = Cache::remember(self::CACHE_KEY, (int) ($cfg['cache_ttl'] ?? 3600), fn () => $this->build($cfg));
        } catch (\Throwable $e) {
            // A cache failure must not take the recommendation down — rebuild uncached and carry on.
            Log::warning('repair cost index cache failed: ' . $e->getMessage());
            $buckets = $this->build($cfg);
        }

        return new RepairCostIndex($buckets, $minSample);
    }

    /**
     * Walk the ledger and the tickets, and reduce them to medians.
     *
     * @return array<string, array{median:float, p90:float, n:int}>
     */
    public function build(array $cfg): array
    {
        $before = (int) ($cfg['window_before_days'] ?? 2) * 86400;
        $after = (int) ($cfg['window_after_days'] ?? 7) * 86400;

        // 1–2. The ledger, minus everything that is not a repair.
        ['lines' => $byVehicle, 'kept' => $kept, 'dropped' => $dropped] = $this->repairLines($cfg);

        // 3. Attribute to tickets.
        $models = DB::table('vehicles')->pluck('model', 'id');
        $samples = [];   // bucket key => list of AED
        DB::table('maintenances')
            ->whereNotNull('vehicle_id')->whereNotNull('vendor_id')->whereNotNull('out_date')
            ->orderBy('id')
            ->select(['vehicle_id', 'vendor_id', 'out_date', 'service_main', 'service_sup'])
            ->chunk(5000, function ($tickets) use (&$samples, $byVehicle, $models, $before, $after) {
                foreach ($tickets as $t) {
                    $lines = $byVehicle[(int) $t->vehicle_id] ?? null;
                    if (! $lines) {
                        continue;
                    }
                    $out = strtotime((string) $t->out_date);
                    $spend = 0.0;
                    foreach ($lines as [$when, $amount]) {
                        if ($when >= $out - $before && $when <= $out + $after) {
                            $spend += $amount;
                        }
                    }
                    if ($spend <= 0) {
                        continue;
                    }

                    $vid = (int) $t->vendor_id;
                    $samples[RepairCostIndex::key('fleet')][] = $spend;
                    $samples[RepairCostIndex::key('garage', $vid)][] = $spend;

                    // Per-fault grains take SINGLE-fault tickets only — see the class note.
                    $cats = $this->faults->extractCategories($t->service_main, $t->service_sup, null);
                    if (count($cats) !== 1) {
                        continue;
                    }
                    $cat = $cats[0];
                    $samples[RepairCostIndex::key('garage_fault', $vid, $cat)][] = $spend;

                    $model = (string) ($models[$t->vehicle_id] ?? '');
                    if (trim($model) !== '') {
                        $samples[RepairCostIndex::key('garage_fault_model', $vid, $cat, $model)][] = $spend;
                    }
                }
            });

        // 4. Reduce. Medians, not averages — one disputed AED 40,000 rebuild must not move a garage's
        //    typical bill, and the ledger has plenty of those.
        $out = [];
        foreach ($samples as $key => $values) {
            $out[$key] = [
                'median' => $this->percentile($values, 0.5),
                'p90'    => $this->percentile($values, 0.9),
                'n'      => count($values),
            ];
        }

        $out['__meta'] = ['lines_kept' => $kept, 'lines_dropped' => $dropped, 'buckets' => count($out)];

        return $out;
    }

    /**
     * The ledger reduced to lines that are plausibly repair spend.
     *
     * Separated from {@see build()} because this is where the RULE "never mix unrelated expenses into
     * repair cost" is actually enforced, and a rule worth stating is worth testing on its own — without
     * a database, so the test is about the filter and nothing else.
     *
     * @return array{lines: array<int, array<int, array{0:int, 1:float}>>, kept:int, dropped:int}
     */
    public function repairLines(array $cfg): array
    {
        $exclude = $this->excludePattern((array) ($cfg['exclude'] ?? []));
        $cap = (float) ($cfg['line_outlier_aed'] ?? 50000);

        $byVehicle = [];
        $kept = 0;
        $dropped = 0;
        foreach ($this->expenses->linesByVehicle() as $vid => $lines) {
            foreach ($lines as $l) {
                $amount = (float) $l['amount'];
                // A credit, a write-off-sized settlement or an undated line cannot price a repair.
                if ($amount <= 0 || $amount > $cap || $l['date'] === null) {
                    $dropped++;
                    continue;
                }
                if ($exclude !== null && preg_match($exclude, (string) $l['remarks'])) {
                    $dropped++;
                    continue;
                }
                $byVehicle[(int) $vid][] = [strtotime($l['date']), $amount];
                $kept++;
            }
        }

        return ['lines' => $byVehicle, 'kept' => $kept, 'dropped' => $dropped];
    }

    /**
     * One regex from the reviewable exclusion config. Returns null when nothing is configured — which
     * would mean fuel and insurance counted as repair cost, so it is logged loudly rather than silently
     * accepted.
     */
    private function excludePattern(array $exclude): ?string
    {
        $parts = array_filter(array_map('trim', array_values($exclude)));
        if (empty($parts)) {
            Log::warning('repair cost: no expense exclusions configured — fuel and insurance will count as repair spend');
            return null;
        }
        return '/' . implode('|', $parts) . '/i';
    }

    /** @param  array<int, float>  $values */
    private function percentile(array $values, float $p): float
    {
        sort($values);
        $n = count($values);
        if ($n === 0) {
            return 0.0;
        }
        if ($n === 1) {
            return round((float) $values[0], 0);
        }
        $i = ($n - 1) * $p;
        $lo = (int) floor($i);
        $hi = (int) ceil($i);
        return round($lo === $hi ? (float) $values[$lo] : $values[$lo] + ($values[$hi] - $values[$lo]) * ($i - $lo), 0);
    }
}
