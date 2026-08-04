<?php

namespace App\Intelligence\Recurrence;

use App\Intelligence\Coverage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * THE ONLY CODE THAT READS THE CANONICAL RECURRENCE DATASET.
 *
 * ── WHY THIS CLASS EXISTS ────────────────────────────────────────────────────────────────────────
 * The platform previously carried SIX implementations of "did the same fault come back?" — in
 * OperationalKpiService, GarageScorecardService, GarageOutcomeForecaster, ForecastCalibration,
 * ProjectionRepairHistoryQuery and the rebuild itself. Each was individually defensible. Measured
 * together on the live corpus they returned 40.62%, 40.96% and 46.51% for the same question, over
 * sample sizes from 10,595 to 33,026 — and one of those surfaces routes cars to garages.
 *
 * Six implementations is not six bugs. It is one missing boundary. This class is that boundary.
 *
 * ── WHAT IT DOES AND DOES NOT DO ─────────────────────────────────────────────────────────────────
 * It answers "WHAT HAPPENED?". It never answers "what does it mean?".
 *
 * So there is no scoring here, no grading, no case-mix expectation, no verdict. Those are judgements
 * about garages and they live in the domain service that owns them (GarageScorecardService). Putting
 * case-mix in the measurement layer would make every future consumer inherit it whether or not it
 * applied to them — Fault Intelligence and Vehicle Intelligence both read recurrence and neither
 * wants a garage's work-mix expectation folded in.
 *
 * ── HARD RULES ───────────────────────────────────────────────────────────────────────────────────
 *   · This is the only class that may name `fault_recurrence_pairs` in a query.
 *   · No class anywhere may compute recurrence from raw `maintenance_signatures`.
 *     Both are enforced by a static CI guard, not by convention.
 *   · Every method takes a RecurrenceWindow, so a caller cannot silently use a different definition.
 *   · An empty group reports null, never 0.0 — "0% comeback" over no repairs is the most flattering
 *     possible lie about a garage.
 *
 * @see config/metrics/recurrence.php            the governed definition
 * @see docs/Metric-Specification-Recurrence.md   the human specification
 */
class RecurrenceRepository
{
    private const TABLE = 'fault_recurrence_pairs';

    /** Cached per request — the corpus edge is fixed between rebuilds. */
    private ?string $corpusMax = null;

    /** Cached per request — signature → repair domain, from the shared vocabulary. */
    private ?array $domainMap = null;

    // ── Fleet ───────────────────────────────────────────────────────────────────────────────────

    /** The platform baseline. Consumed by OperationalKpiService and the Executive dashboard. */
    public function fleet(RecurrenceWindow $window): RecurrenceStats
    {
        $row = $this->base($window)
            ->selectRaw($this->aggregateSelect($window))
            ->first();

        return $this->hydrate($row, $window, $this->coverage($window));
    }

    // ── Garage ──────────────────────────────────────────────────────────────────────────────────

    /**
     * Per garage.
     *
     * Events with no vendor (1,586 of 12,608) are excluded here and counted fleet-wide: a repair we
     * cannot attribute is still a repair, but it cannot appear on anybody's scorecard.
     *
     * @param  int[]  $vendorIds  empty = every garage
     * @return array<int, RecurrenceStats>  keyed by vendor_id
     */
    public function byGarage(RecurrenceWindow $window, array $vendorIds = []): array
    {
        $q = $this->base($window)
            ->whereNotNull('first_vendor_id')
            ->selectRaw('first_vendor_id, ' . $this->aggregateSelect($window))
            ->groupBy('first_vendor_id');

        if ($vendorIds !== []) {
            $q->whereIn('first_vendor_id', $vendorIds);
        }

        $out = [];
        foreach ($q->get() as $row) {
            $out[(int) $row->first_vendor_id] = $this->hydrate($row, $window, $this->coverageFor($row));
        }

        return $out;
    }

    /**
     * Per garage per repair domain — the cell behind the Garage × Fault matrix and the scorecard.
     *
     * Signatures are mapped into the shared findings vocabulary rather than shown raw, so no surface
     * ever prints a machine label like `OIL_SERVICE` at an operator. A signature the map does not
     * know segments as nothing rather than as a wrong domain.
     *
     * @return array<int, array<string, RecurrenceStats>>  [vendor_id][domain]
     */
    public function byGarageAndDomain(RecurrenceWindow $window, array $vendorIds = []): array
    {
        $q = $this->base($window)
            ->whereNotNull('first_vendor_id')
            ->selectRaw('first_vendor_id, signature, ' . $this->aggregateSelect($window))
            ->groupBy('first_vendor_id', 'signature');

        if ($vendorIds !== []) {
            $q->whereIn('first_vendor_id', $vendorIds);
        }

        $map = $this->domainMap();
        $acc = [];

        foreach ($q->get() as $row) {
            $domain = $map[$row->signature] ?? null;
            if ($domain === null) {
                continue;
            }

            $vid   = (int) $row->first_vendor_id;
            $stats = $this->hydrate($row, $window, $this->coverageFor($row));

            $acc[$vid][$domain] = isset($acc[$vid][$domain])
                ? $acc[$vid][$domain]->merge($stats)
                : $stats;
        }

        return $acc;
    }

    // ── Fault ───────────────────────────────────────────────────────────────────────────────────

    public function bySignature(RecurrenceWindow $window, string $signature): RecurrenceStats
    {
        $row = $this->base($window)
            ->where('signature', $signature)
            ->selectRaw($this->aggregateSelect($window))
            ->first();

        return $this->hydrate($row, $window, $this->coverageFor($row));
    }

    /** @return array<string, RecurrenceStats> keyed by signature */
    public function bySignatureAll(RecurrenceWindow $window): array
    {
        $rows = $this->base($window)
            ->selectRaw('signature, ' . $this->aggregateSelect($window))
            ->groupBy('signature')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[$row->signature] = $this->hydrate($row, $window, $this->coverageFor($row));
        }

        return $out;
    }

    // ── Vehicle ─────────────────────────────────────────────────────────────────────────────────

    public function byVehicle(RecurrenceWindow $window, int $vehicleId): RecurrenceStats
    {
        $row = $this->base($window)
            ->where('vehicle_id', $vehicleId)
            ->selectRaw($this->aggregateSelect($window))
            ->first();

        return $this->hydrate($row, $window, $this->coverageFor($row));
    }

    // ── Evidence ────────────────────────────────────────────────────────────────────────────────

    /**
     * The paired rows behind a claim — for the Evidence Drawer, and nothing else.
     *
     * Every number this repository publishes must be walkable back to the repairs that produced it;
     * a platform that grades suppliers has to be able to show its working on demand. Lazy, because
     * a fleet-wide evidence query is 10k rows and the drawer paginates.
     */
    public function pairs(RecurrenceWindow $window, array $filters = []): LazyCollection
    {
        $q = $this->base($window);

        foreach (['first_vendor_id', 'vehicle_id', 'signature'] as $col) {
            if (isset($filters[$col])) {
                is_array($filters[$col]) ? $q->whereIn($col, $filters[$col]) : $q->where($col, $filters[$col]);
            }
        }

        if (($filters['returned_only'] ?? false) === true) {
            $q->whereNotNull('next_occurred_at')->where('days_to_return', '<=', $window->windowDays);
        }

        return $q->orderBy('occurred_at')->lazy();
    }

    // ── Coverage & freshness ────────────────────────────────────────────────────────────────────

    /**
     * How much of the corpus the window can actually see.
     *
     * `covered` is the fully-observed subset; `total` is every deduplicated event. The gap is the
     * censored tail — repairs too recent to have failed yet. Published rather than silently applied,
     * because a rate over 84% of the corpus is a different claim from a rate over all of it.
     */
    public function coverage(RecurrenceWindow $window): Coverage
    {
        $row = DB::table(self::TABLE)
            ->selectRaw('COUNT(*) AS total, SUM(CASE WHEN days_observed >= ? THEN 1 ELSE 0 END) AS covered', [$window->windowDays])
            ->first();

        return new Coverage(
            covered: (int) ($row->covered ?? 0),
            total:   (int) ($row->total ?? 0),
            asOf:    $this->asOf(),
        );
    }

    /** The corpus edge — what every downstream `Kpi::asOf` resolves to. */
    public function asOf(): ?CarbonImmutable
    {
        $this->corpusMax ??= DB::table(self::TABLE)->max('occurred_at') ?: '';

        return $this->corpusMax === '' ? null : CarbonImmutable::parse($this->corpusMax);
    }

    // ── Internals ───────────────────────────────────────────────────────────────────────────────

    /**
     * The governed base query. Every public method starts here, which is what makes the definition
     * impossible to bypass from inside this class as well as outside it.
     *
     * Exposure is NOT filtered here: it was already excluded when the table was built, so a filter
     * would be a second place the rule lives. `excludeExposure` is carried on the window so the
     * contract can state the rule, and a future window that wanted exposure in would have to change
     * the rebuild, not sneak past a query.
     */
    private function base(RecurrenceWindow $window): Builder
    {
        $q = DB::table(self::TABLE);

        if ($window->appliesHorizon()) {
            $q->where('days_observed', '>=', $window->windowDays);
        }

        return $q;
    }

    /**
     * One aggregate expression, used by every grain, so a garage's rate and the fleet rate it is
     * compared against are literally the same measurement rather than two similar ones.
     */
    private function aggregateSelect(RecurrenceWindow $window): string
    {
        $w = (int) $window->windowDays;

        return "COUNT(*) AS n,
                SUM(CASE WHEN next_occurred_at IS NOT NULL AND days_to_return <= {$w} THEN 1 ELSE 0 END) AS returned,
                SUM(CASE WHEN next_occurred_at IS NULL OR days_to_return > {$w} THEN 1 ELSE 0 END) AS held,
                SUM(CASE WHEN next_occurred_at IS NOT NULL AND days_to_return <= 30 THEN 1 ELSE 0 END) AS back_30,
                SUM(CASE WHEN next_occurred_at IS NOT NULL AND days_to_return > 30 AND days_to_return <= {$w} THEN 1 ELSE 0 END) AS back_90,
                AVG(CASE WHEN next_occurred_at IS NOT NULL AND days_to_return <= {$w} THEN days_to_return END) AS mean_gap,
                COUNT(*) AS scope_total";
    }

    private function hydrate(?object $row, RecurrenceWindow $window, Coverage $coverage): RecurrenceStats
    {
        if ($row === null || (int) ($row->n ?? 0) === 0) {
            return RecurrenceStats::empty($window, $coverage, $this->asOf());
        }

        return new RecurrenceStats(
            n:             (int) $row->n,
            returned:      (int) $row->returned,
            held:          (int) $row->held,
            back30:        (int) $row->back_30,
            back90:        (int) $row->back_90,
            // The MEAN is what the aggregate can produce in one pass. Time-to-return is
            // right-skewed, so a caller that needs the median asks medianGap() for its own grain
            // rather than being handed a mean labelled as a median.
            medianGapDays: $row->mean_gap === null ? null : round((float) $row->mean_gap, 1),
            coverage:      $coverage,
            asOf:          $this->asOf(),
            window:        $window,
        );
    }

    /**
     * The true median days-to-return for one scope.
     *
     * Separate from the aggregate because a median cannot be computed in the same grouped pass, and
     * because most callers do not need it. Right-skewed data: a handful of repairs limping back on
     * day 89 drags a mean well past what typically happens.
     */
    public function medianGap(RecurrenceWindow $window, array $filters = []): ?float
    {
        $q = $this->base($window)
            ->whereNotNull('next_occurred_at')
            ->where('days_to_return', '<=', $window->windowDays);

        foreach (['first_vendor_id', 'vehicle_id', 'signature'] as $col) {
            if (isset($filters[$col])) {
                $q->where($col, $filters[$col]);
            }
        }

        $values = $q->orderBy('days_to_return')->pluck('days_to_return')->all();
        $count  = count($values);

        if ($count === 0) {
            return null;
        }

        $mid = intdiv($count, 2);

        return round($count % 2
            ? (float) $values[$mid]
            : ((float) $values[$mid - 1] + (float) $values[$mid]) / 2, 1);
    }

    private function coverageFor(?object $row): Coverage
    {
        $n = (int) ($row->scope_total ?? $row->n ?? 0);

        return new Coverage($n, $n, $this->asOf());
    }

    /** Signature → repair domain, from the vocabulary the rest of the product speaks. */
    private function domainMap(): array
    {
        return $this->domainMap ??= (array) config('garage_recommendation.criticality.signature_categories', []);
    }
}
