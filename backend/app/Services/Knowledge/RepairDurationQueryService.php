<?php

namespace App\Services\Knowledge;

use Illuminate\Support\Facades\DB;

/**
 * Repair Intelligence — the duration substrate (docs/Repair-Intelligence-Architecture.md §3, §8).
 *
 * The SINGLE place the "Time-To-Fix" definition lives, and the cohort-statistics engine the ETA
 * cascade (RepairEtaPredictor, L4) reads from. It answers one question for any grouping:
 *
 *     "For repairs matching (reason?, garage?), how long did they historically take?"
 *
 * Data substrate — the CLEAN training set (§2 assumptions, LOCKED):
 *   - origin='sheet' only  (Customer Cases excluded — no OUT date, so no duration)
 *   - a linked vehicle, and BOTH out_date and actual_in_date present
 *   - duration = DATEDIFF(actual_in_date, out_date) = CALENDAR days (D2), incl. weekends/holidays
 *   - 0 ≤ duration ≤ MAX_DAYS  (A5 outlier guard — a 300-day row is an unclosed artifact, not a repair)
 *
 * Reporting shape (D1, A4): the data is heavily right-skewed, so the headline is the MEDIAN and the
 * range is p25→p90 — never a bare mean. `mean` is returned for diagnostics only.
 *
 * The percentile math is reused from RepairCohortStats (the recommendation engine's aggregator) so there
 * is ONE percentile implementation across the Knowledge layer. `summarize()` is pure and DB-free.
 */
class RepairDurationQueryService
{
    /** Durations beyond this (calendar days) are treated as data artifacts and dropped (A5). */
    public const MAX_DAYS = 120;

    public function __construct(
        private readonly RepairCohortStats $stats = new RepairCohortStats(),
    ) {}

    /**
     * PURE cohort statistics over a list of day-durations. DB-free — the unit-tested core.
     *
     * @param  array<int,int|float>  $days
     * @return array{n:int, median:?float, p25:?float, p90:?float, mean:?float, min:?int, max:?int}
     */
    public function summarize(array $days): array
    {
        $vals = [];
        foreach ($days as $d) {
            if (is_numeric($d)) {
                $vals[] = (float) $d;
            }
        }
        $n = count($vals);
        if ($n === 0) {
            return ['n' => 0, 'median' => null, 'p25' => null, 'p90' => null, 'mean' => null, 'min' => null, 'max' => null];
        }
        sort($vals);

        return [
            'n'      => $n,
            'median' => $this->stats->percentile($vals, 0.50),
            'p25'    => $this->stats->percentile($vals, 0.25),
            'p90'    => $this->stats->percentile($vals, 0.90),
            'mean'   => round(array_sum($vals) / $n, 1),
            'min'    => (int) $vals[0],
            'max'    => (int) $vals[$n - 1],
        ];
    }

    /**
     * Cohort statistics for a grouping. Pass either/both filters null to widen:
     *   cohort($reason, $vendor) → Level-1 substrate   (reason × garage)
     *   cohort($reason, null)    → reason-only          (Level-2)
     *   cohort(null, $vendor)    → garage-only          (Level-2)
     *   cohort(null, null)       → fleet baseline       (Level-3)
     *
     * @return array{n:int, median:?float, p25:?float, p90:?float, mean:?float, min:?int, max:?int,
     *               reason_id:?int, vendor_id:?int}
     */
    public function cohort(?int $reasonId, ?int $vendorId): array
    {
        $summary = $this->summarize($this->durations($reasonId, $vendorId));
        $summary['reason_id'] = $reasonId;
        $summary['vendor_id'] = $vendorId;

        return $summary;
    }

    /** Fleet-wide baseline (Level-3): every clean repair cycle, no filter. */
    public function fleetBaseline(): array
    {
        return $this->cohort(null, null);
    }

    /**
     * The raw calendar-day durations of the clean training set, optionally filtered by reason and/or
     * garage. This is where the Time-To-Fix definition (§3) is applied — nowhere else.
     *
     * @return array<int,int>
     */
    public function durations(?int $reasonId, ?int $vendorId): array
    {
        $q = DB::table('maintenances')
            ->where('origin', 'sheet')
            ->whereNotNull('vehicle_id')
            ->whereNotNull('out_date')
            ->whereNotNull('actual_in_date')
            // calendar days (D2); the guard also drops the ~0.4% negative (in-before-out) rows
            ->whereRaw('DATEDIFF(actual_in_date, out_date) BETWEEN 0 AND ?', [self::MAX_DAYS]);

        if ($reasonId !== null) {
            $q->where('maintenance_reason_id', $reasonId);
        }
        if ($vendorId !== null) {
            $q->where('vendor_id', $vendorId);
        }

        return $q->selectRaw('DATEDIFF(actual_in_date, out_date) AS d')
            ->pluck('d')
            ->map(fn ($d) => (int) $d)
            ->all();
    }
}
