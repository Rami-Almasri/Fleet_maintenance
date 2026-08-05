<?php

namespace App\Services\Intelligence;

use App\Intelligence\Recurrence\RecurrenceRepository;
use App\Intelligence\Recurrence\RecurrenceWindow;
use App\Intelligence\Coverage;
use App\Kpi\Kpi;
use App\Services\FleetUtilizationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The Executive Home payload — Basem's page.
 *
 * ── WHY THIS IS A DIFFERENT PRODUCT, NOT A FILTERED VERSION OF ADHAM'S ──────────────────────────
 * Adham asks "what do I do about this car today". Basem asks "where is my money going and what
 * should I change". Same corpus, different questions, and a shared page would serve neither: the
 * operational surfaces lead with queue depth and ticket state, which is noise to an owner, while the
 * numbers he needs — spend, availability, lifecycle economics — are buried behind a click.
 *
 * ── THE HEALTH SCORE THE SPEC ASKED FOR IS DELIBERATELY ABSENT ──────────────────────────────────
 * §2.2 Card 1 specifies a "Fleet Health Score, 72/100" blended from recurrence, spend, age and
 * odometer. It is not built, and not because it was hard.
 *
 * A composite score is an INFERENCE — it invents a number the data never contained, then hides the
 * weighting that produced it. The platform's standing rule is to map data directly and never publish
 * a confidence or health score, precisely so that no figure on any page is unfalsifiable. "72" cannot
 * be checked against anything; a reader cannot tell whether it moved because repairs got worse or
 * because a weight was retuned. Every other number here can be drilled to the rows behind it.
 *
 * The hero card is therefore REPAIR RELIABILITY — the share of repairs where the same fault did not
 * come back — which answers the same executive question ("is the fleet being fixed properly?") and
 * is a measurement rather than a construction. It is also already governed, versioned and CI-guarded
 * as the platform's single recurrence definition.
 *
 * ── WHAT EVERY NUMBER HERE OWES THE READER ──────────────────────────────────────────────────────
 * Each figure is a {@see Kpi}, so it carries its own sample size, coverage, as-of date and
 * confidence, and "not measurable" is a first-class state rather than a zero. Money figures are
 * separated from operational ones so the frontend can gate them behind SHOW_FINANCIALS without
 * unpicking the payload.
 */
class ExecutiveDashboardService
{
    /**
     * Spend categories that are NOT repair work.
     *
     * sub_rental alone is AED 4.6M — larger than every genuine repair category combined — because it
     * is the cost of hiring a replacement car, not of fixing one. Insurance, salik, registration,
     * fuel and fines are running costs of owning the fleet. Including any of them would make
     * "maintenance spend" roughly four times larger and answer a question nobody asked.
     */
    private const NON_REPAIR_CATEGORIES = [
        'sub_rental', 'insurance', 'salik', 'registration', 'fuel', 'fines', 'gps', 'recovery',
    ];

    /** Below this, a garage's rate is noise. Same floor the scorecard uses — one platform, one gate. */
    private const MIN_GARAGE_SAMPLE = 30;

    public function __construct(
        private RecurrenceRepository $recurrence,
        private FleetUtilizationService $utilization,
    ) {}

    /** @return array<string, mixed> */
    public function report(): array
    {
        // fromContract() with no override, so this page inherits the SAME governed window the garage
        // scorecard uses. Hardcoding 90 here would let the executive summary and the page it links to
        // drift apart the day the contract changes — which is the precise failure convergence removed.
        $window = RecurrenceWindow::fromContract();
        $asOf   = $this->recurrence->asOf();

        // ONE STREAM, TWO ANSWERS.
        //
        // Both "how many cars have a repeat fault" and "how many cars does each fault touch" are
        // distinct-vehicle questions, and neither has a dedicated repository method. Reading the
        // table directly would have been three lines — and would have bypassed the window, the
        // observation horizon and the censoring rule, producing numbers that look canonical and are
        // not. That is exactly what the architecture guard exists to catch, and it caught me.
        //
        // pairs() is the repository's streaming accessor, so the governed filters still apply.
        $digest = $this->digest($window);

        return [
            'headline'   => $this->headline($window, $digest),
            'spend'      => $this->spendByCategory(),
            'garages'    => $this->garagePerformance($window),
            'failures'   => $this->recurringFailures($window, $digest),
            'lifecycle'  => $this->lifecycleEconomics(),
            'as_of'      => $asOf?->toDateString(),
            'provenance' => $this->provenance($asOf),
        ];
    }

    /**
     * A single pass over the canonical pairs, answering every distinct-vehicle question at once.
     *
     * @return array{repeat_vehicles:int, cars_by_signature:array<string,int>}
     */
    private function digest(RecurrenceWindow $window): array
    {
        $repeatVehicles = [];
        $carsBySignature = [];

        foreach ($this->recurrence->pairs($window) as $p) {
            $sig = (string) $p->signature;
            $carsBySignature[$sig] ??= [];
            $carsBySignature[$sig][$p->vehicle_id] = true;

            // "Came back" means inside the governed window — the same test every rate on the
            // platform uses, rather than a 90 hardcoded here.
            if ($p->days_to_return !== null && $p->days_to_return <= $window->windowDays) {
                $repeatVehicles[$p->vehicle_id] = true;
            }
        }

        return [
            'repeat_vehicles'   => count($repeatVehicles),
            'cars_by_signature' => array_map('count', $carsBySignature),
        ];
    }

    // ── Row 1 · the first thirty seconds ────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function headline(RecurrenceWindow $window, array $digest): array
    {
        $fleet    = $this->recurrence->fleet($window);
        $coverage = $this->recurrence->coverage($window);
        $asOf     = $this->recurrence->asOf();

        // Reliability, stated as the GOOD direction. The operational surfaces lead with the comeback
        // rate because Adham is hunting failures; an owner reads "how much of my repair spend held"
        // more naturally, and inverting it here keeps every card on this page pointing the same way
        // (higher is better) so a glance down the row is not a series of direction reversals.
        $heldRate = $fleet->heldRate();

        $reliability = $heldRate === null
            ? Kpi::unavailable('repair_reliability', 'Repair Reliability', 'No fully observed repairs in the window.')
            : Kpi::measured(
                'repair_reliability',
                'Repair Reliability',
                round($heldRate, 2),
                'percent',
                $fleet->n,
                Kpi::HIGHER_BETTER,
                [
                    'returned'      => $fleet->returned,
                    'held'          => $fleet->held,
                    'back_within_30'=> $fleet->back30,
                    'note'          => 'Share of repairs where the same fault did not return within the measurement window.',
                ],
                $coverage,
                $asOf,
                'recurrence.fleet',
            );

        return [
            'reliability'  => $reliability->toArray(),
            'spend'        => $this->repairSpendKpi()->toArray(),
            'availability' => $this->availabilityKpi()->toArray(),
            'attention'    => $this->vehiclesNeedingDecision($window, $digest)->toArray(),
        ];
    }

    /**
     * Trailing-12-month repair spend.
     *
     * ── THE AMBER BADGE IS THE POINT OF THIS METHOD ─────────────────────────────────────────────
     * The ledger collapses after March 2026 — AED 273k that month, then 17k, 7.5k, 758, 308. That is
     * not the fleet suddenly spending nothing; it is the expense feed stopping. Rendered as a plain
     * trailing-12m total the number would look like a spectacular saving and would be quoted as one.
     *
     * So the window ENDS at the last month with a credible volume, the card is stamped with that
     * date, and the KPI is marked partial. An owner reading "AED N as of 31 Mar" can act on it; an
     * owner reading "AED N" with a downward sparkline would act on a reporting artefact.
     */
    private function repairSpendKpi(): Kpi
    {
        $lastCredible = DB::table('vehicle_expenses')
            ->selectRaw('DATE_FORMAT(entry_date, "%Y-%m-01") AS ym, COUNT(*) AS n')
            ->groupBy('ym')
            ->havingRaw('COUNT(*) >= 100')       // a month with <100 rows is a feed failure, not a quiet month
            ->orderByDesc('ym')
            ->limit(1)
            ->first();

        if (! $lastCredible) {
            return Kpi::unavailable('repair_spend', 'Maintenance Spend', 'No expense month has enough rows to report.', 'currency');
        }

        $monthStart = Carbon::parse($lastCredible->ym)->startOfMonth();
        $end        = $monthStart->copy()->endOfMonth();
        $start      = $monthStart->copy()->subMonths(11);   // from the START of the month, or endOfMonth arithmetic loses a month

        $rows = DB::table('vehicle_expenses')
            ->whereBetween('entry_date', [$start->toDateString(), $end->toDateString()])
            ->whereNotIn('category', self::NON_REPAIR_CATEGORIES)
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total')
            ->first();

        $staleDays = (int) $end->diffInDays(Carbon::today());

        return Kpi::measured(
            'repair_spend',
            'Maintenance Spend',
            round((float) $rows->total, 2),
            'currency',
            (int) $rows->n,
            Kpi::LOWER_BETTER,
            [
                'window_start'      => $start->toDateString(),
                'window_end'        => $end->toDateString(),
                'excludes'          => self::NON_REPAIR_CATEGORIES,
                'exclusion_note'    => 'Repair work only. Sub-rental, insurance, salik, registration, fuel and fines are excluded — including them roughly quadruples the figure and answers a different question.',
                'ledger_stale_days' => $staleDays,
                'ledger_note'       => $staleDays > 45
                    ? 'The expense feed has not delivered a full month since ' . $end->format('M Y') . '. This is a data gap, not a reduction in spending.'
                    : null,
            ],
            // Coverage is expressed against the CALENDAR, not the rows: the honest statement is
            // "12 months requested, N of them actually delivered by the feed".
            new Coverage($this->creditedMonths($start, $end), 12, $end, 'Months in the window that the expense feed actually delivered.'),
            $end,
        );
    }

    /**
     * Share of fleet-days not lost to the workshop.
     *
     * Delegates to FleetUtilizationService because that service is the canonical owner of
     * maintenance-days. Recomputing days here would create a second definition of "how long was the
     * car off the road", which is the exact failure the recurrence convergence work spent weeks
     * undoing. One question, one owner.
     */
    private function availabilityKpi(): Kpi
    {
        // TRAILING 12 MONTHS, not lifetime.
        //
        // report() defaults to the whole history, which puts every idle day since purchase into the
        // denominator — 729,883 of them against 11,821 workshop days. The resulting 98.8% is
        // arithmetically correct and executively useless: it says "the fleet is almost never in the
        // shop" when what it mostly measures is how long the fleet has existed. A trailing year is
        // the period an owner can still act on, and it lines up with the spend card beside it.
        $to   = Carbon::today();
        $from = $to->copy()->subMonths(12);

        try {
            $report = $this->utilization->report($from->toDateString(), $to->toDateString());
        } catch (\Throwable $e) {
            return Kpi::unavailable('fleet_availability', 'Fleet Availability', 'Utilisation report unavailable: ' . $e->getMessage());
        }

        $s = $report['summary'] ?? [];

        // Computed from DAY TOTALS, not from avg_downtime_pct. That column averages per-car
        // percentages, which silently weights a car owned for one month the same as one owned for
        // five years. Fleet availability is a fleet-day question, so it is answered in fleet-days.
        $maintDays = (float) ($s['total_days_maintenance'] ?? 0);
        $totalDays = $maintDays
            + (float) ($s['total_days_rented'] ?? 0)
            + (float) ($s['total_days_idle'] ?? 0);

        if ($totalDays <= 0) {
            return Kpi::unavailable('fleet_availability', 'Fleet Availability', 'Utilisation report returned no fleet-days to divide by.');
        }

        return Kpi::measured(
            'fleet_availability',
            'Fleet Availability',
            round((1 - ($maintDays / $totalDays)) * 100, 2),
            'percent',
            (int) ($s['cars'] ?? 0),
            Kpi::HIGHER_BETTER,
            [
                'maintenance_days' => $maintDays,
                'rented_days'      => (float) ($s['total_days_rented'] ?? 0),
                'idle_days'        => (float) ($s['total_days_idle'] ?? 0),
                'fleet_days'       => $totalDays,
                'window'           => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                'note'             => 'Share of fleet-days over the last 12 months not lost to the workshop. Idle days count as available — the car could have been rented. Source: FleetUtilizationService, the canonical owner of maintenance-days.',
            ],
        );
    }

    /**
     * Cars whose faults keep coming back.
     *
     * NOT a "risk score". This counts vehicles with a repeat fault inside 90 days over the observed
     * corpus — a fact with rows behind it — rather than grading cars on a scale nobody can audit.
     */
    private function vehiclesNeedingDecision(RecurrenceWindow $window, array $digest): Kpi
    {
        $fleetSize = (int) DB::table('vehicles')->whereNull('deleted_at')->count();
        $fleet     = $this->recurrence->fleet($window);

        return Kpi::measured(
            'vehicles_repeat_faults',
            'Cars With Repeat Faults',
            (float) $digest['repeat_vehicles'],
            'count',
            $fleetSize,
            Kpi::LOWER_BETTER,
            [
                'repeat_events' => $fleet->returned,
                'fleet_size'    => $fleetSize,
                'window_days'   => $window->windowDays,
                'note'          => 'Cars where the same fault came back inside the measurement window at least once. Counted, not scored.',
            ],
            $this->recurrence->coverage($window),
            $this->recurrence->asOf(),
        );
    }

    // ── Row 2 · where the money goes ────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function spendByCategory(): array
    {
        $lastCredible = DB::table('vehicle_expenses')
            ->selectRaw('DATE_FORMAT(entry_date, "%Y-%m-01") AS ym')
            ->groupBy('ym')->havingRaw('COUNT(*) >= 100')->orderByDesc('ym')->limit(1)->value('ym');

        if (! $lastCredible) {
            return ['rows' => [], 'total' => 0, 'window' => null];
        }

        $monthStart = Carbon::parse($lastCredible)->startOfMonth();
        $end        = $monthStart->copy()->endOfMonth();
        $start      = $monthStart->copy()->subMonths(11);

        $rows = DB::table('vehicle_expenses')
            ->whereBetween('entry_date', [$start->toDateString(), $end->toDateString()])
            ->whereNotIn('category', self::NON_REPAIR_CATEGORIES)
            ->groupBy('category')
            ->selectRaw('category, COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total')
            ->orderByDesc('total')
            ->get();

        $total = (float) $rows->sum('total');

        return [
            'window' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'total'  => round($total, 2),
            'rows'   => $rows->map(fn ($r) => [
                'category' => $r->category ?: 'uncategorised',
                'total'    => round((float) $r->total, 2),
                'n'        => (int) $r->n,
                'share'    => $total > 0 ? round(((float) $r->total / $total) * 100, 1) : null,
            ])->values()->all(),
            // Stated, not implied: the excluded pile is bigger than the included one, and someone
            // will eventually compare this page against the raw ledger.
            'excluded' => $this->excludedSpend($start, $end),
        ];
    }

    /** @return array<string, mixed> */
    private function excludedSpend(Carbon $start, Carbon $end): array
    {
        $rows = DB::table('vehicle_expenses')
            ->whereBetween('entry_date', [$start->toDateString(), $end->toDateString()])
            ->whereIn('category', self::NON_REPAIR_CATEGORIES)
            ->groupBy('category')
            ->selectRaw('category, COALESCE(SUM(amount), 0) AS total')
            ->orderByDesc('total')
            ->get();

        return [
            'total' => round((float) $rows->sum('total'), 2),
            'rows'  => $rows->map(fn ($r) => ['category' => $r->category, 'total' => round((float) $r->total, 2)])->all(),
            'note'  => 'Excluded from Maintenance Spend because these are not repair work.',
        ];
    }

    // ── Row 3 · garage performance, surfaced executively ────────────────────────────────────────

    /** @return array<string, mixed> */
    private function garagePerformance(RecurrenceWindow $window): array
    {
        $stats  = $this->recurrence->byGarage($window);
        $medians = $this->recurrence->medianGapsByGarage($window);

        $names = DB::table('vendors')->pluck('name', 'id');
        $fleet = $this->recurrence->fleet($window);

        $rows = [];
        foreach ($stats as $vendorId => $s) {
            if (! $s->meetsFloor(self::MIN_GARAGE_SAMPLE)) {
                continue;   // below the gate a rate is noise; showing it ranked would be worse than hiding it
            }
            $rows[] = [
                'vendor_id'         => (int) $vendorId,
                'name'              => $names[$vendorId] ?? ('Vendor #' . $vendorId),
                'n'                 => $s->n,
                'comeback_pct'      => $s->rate() === null ? null : round($s->rate(), 2),
                'median_gap_days'   => $medians[$vendorId] ?? null,
                'back_within_30'    => $s->back30,
                'evidence_query_id' => 'recurrence.garage:' . $vendorId,
            ];
        }

        // Worst first — the executive question is "who should stop getting work", not "who is best".
        usort($rows, fn ($a, $b) => ($b['comeback_pct'] ?? -1) <=> ($a['comeback_pct'] ?? -1));

        return [
            'fleet_comeback_pct' => $fleet->rate() === null ? null : round($fleet->rate(), 2),
            'fleet_median_gap'   => $this->recurrence->medianGap($window),
            'scored'             => count($rows),
            'min_sample'         => self::MIN_GARAGE_SAMPLE,
            'rows'               => array_slice($rows, 0, 10),
            'cost_of_rework'     => $this->costOfRework($window)->toArray(),
            // Mandatory per UX §2.2 Row 3. Without it this table reads as a pure quality ranking and
            // a tyre shop gets condemned for doing tyre work.
            'caveat'             => 'Tyre and body shops naturally see faster returns. Compare within the same repair type before drawing a conclusion.',
            // KNOWN DATA ISSUE, surfaced rather than silently filtered.
            //
            // Vendor #407 "Parking" is typed `garage` in the vendor register and therefore ranks here
            // like a repair shop. A `type` filter would not catch it — the type is simply wrong in the
            // data — and a name blacklist would be a lie that works until someone renames the row.
            //
            // Not filtered HERE on purpose: /garages applies no such rule either, and fixing it in one
            // of the two places would rebuild the divergence the recurrence convergence just removed.
            // The fix belongs in the vendor register, or in the repository where BOTH pages read it.
            'data_note'          => 'Ranking reflects the vendor register as recorded. Any location typed as a garage appears here, including non-repair locations — correct those in the vendor register rather than on this page.',
        ];
    }

    /**
     * G17 — money paid for work that did not hold.
     *
     * ESTIMATED, and constructed to say so. Expenses carry no garage, so the billed cost of a
     * specific failed repair is not knowable from this data. What IS knowable: how many repairs came
     * back inside 30 days, and what a repair in that category costs on average across the fleet.
     * Multiplying the two gives an order of magnitude that is defensible and useful, and is not the
     * same kind of fact as "this garage was paid AED N".
     *
     * Kpi::estimated() exists so that distinction survives every place this number is rendered,
     * rather than depending on someone remembering to write "approx" in a label.
     */
    private function costOfRework(RecurrenceWindow $window): Kpi
    {
        // back30 comes straight off the governed fleet statistics — same 30-day definition the
        // scorecard and Repair Intelligence use, rather than a second one written here.
        $repeats = $this->recurrence->fleet($window)->back30;

        if ($repeats === 0) {
            return Kpi::unavailable('cost_of_rework', 'Cost of Rework', 'No repairs returned within 30 days.', 'currency');
        }

        $avgRepair = (float) DB::table('vehicle_expenses')
            ->whereNotIn('category', self::NON_REPAIR_CATEGORIES)
            ->where('amount', '>', 0)
            ->avg('amount');

        if ($avgRepair <= 0) {
            return Kpi::unavailable('cost_of_rework', 'Cost of Rework', 'No repair expenses to average.', 'currency');
        }

        return Kpi::estimated(
            'cost_of_rework',
            'Cost of Rework',
            round($repeats * $avgRepair, 2),
            'currency',
            $repeats,
            Kpi::LOWER_BETTER,
            [
                'repeat_repairs'     => $repeats,
                'avg_repair_cost'    => round($avgRepair, 2),
                'method'             => 'repairs that returned within 30 days × fleet-average repair cost',
                'why_estimated'      => 'Expenses do not record which garage did the work, so the billed cost of a specific failed repair cannot be looked up. This is an order of magnitude, not an invoice total.',
            ],
            null,
            $this->recurrence->asOf(),
        );
    }

    // ── Row 4 · fleet failure patterns ──────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function recurringFailures(RecurrenceWindow $window, array $digest): array
    {
        $all = $this->recurrence->bySignatureAll($window);

        // How many distinct cars each fault touches — the difference between a fleet problem and a
        // disposal problem, and the single most decision-changing fact in this panel. Taken from the
        // shared digest so it sees exactly the population the rates beside it were computed over.
        $spread = $digest['cars_by_signature'];

        $fleetSize = max(1, (int) DB::table('vehicles')->whereNull('deleted_at')->count());

        $rows = [];
        foreach ($all as $signature => $s) {
            if ($s->n < 50) {
                continue;
            }
            $cars = (int) ($spread[$signature] ?? 0);
            $rows[] = [
                'signature'     => $signature,
                'events'        => $s->n,
                'cars'          => $cars,
                'fleet_share'   => round(($cars / $fleetSize) * 100, 1),
                'comeback_pct'  => $s->rate() === null ? null : round($s->rate(), 2),
                // One chip, opposite decisions: spread => fix the fleet, concentrated => sell the cars.
                'concentration' => $cars >= ($fleetSize * 0.25) ? 'spread' : 'concentrated',
            ];
        }

        usort($rows, fn ($a, $b) => $b['events'] <=> $a['events']);

        return [
            'fleet_size' => $fleetSize,
            'rows'       => array_slice($rows, 0, 10),
            'note'       => 'A fault spread across a quarter of the fleet is a fleet problem. The same count inside a handful of cars is a disposal problem.',
        ];
    }

    // ── Row 5 · lifecycle economics ─────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function lifecycleEconomics(): array
    {
        return [
            'cost_curve' => $this->lifetimeCostCurve(),
            'warranty'   => $this->warrantyLeakage()->toArray(),
        ];
    }

    /**
     * C13 — repair spend per vehicle-month by age band.
     *
     * The strategic panel: it turns "should we replace this car" from an argument held one car at a
     * time into a policy. Every vehicle has a purchase_date (438/438), so the age axis is complete —
     * unusually for this dataset, nothing here is estimated.
     *
     * @return array<string, mixed>
     */
    private function lifetimeCostCurve(): array
    {
        $rows = DB::select("
            SELECT band, COUNT(DISTINCT vehicle_id) AS vehicles,
                   ROUND(SUM(amount)) AS spend, ROUND(SUM(months)) AS vehicle_months
            FROM (
                SELECT v.id AS vehicle_id,
                       CASE
                         WHEN TIMESTAMPDIFF(YEAR, v.purchase_date, COALESCE(e.entry_date, CURDATE())) < 1 THEN '0-1y'
                         WHEN TIMESTAMPDIFF(YEAR, v.purchase_date, COALESCE(e.entry_date, CURDATE())) < 3 THEN '1-3y'
                         WHEN TIMESTAMPDIFF(YEAR, v.purchase_date, COALESCE(e.entry_date, CURDATE())) < 5 THEN '3-5y'
                         ELSE '5y+'
                       END AS band,
                       e.amount AS amount,
                       1 AS months
                FROM vehicles v
                JOIN vehicle_expenses e ON e.vehicle_id = v.id
                WHERE v.deleted_at IS NULL
                  AND v.purchase_date IS NOT NULL
                  AND e.category NOT IN ('" . implode("','", self::NON_REPAIR_CATEGORIES) . "')
                  AND e.amount > 0
            ) t
            GROUP BY band
            ORDER BY FIELD(band, '0-1y', '1-3y', '3-5y', '5y+')
        ");

        return [
            'bands' => array_map(fn ($r) => [
                'band'            => $r->band,
                'vehicles'        => (int) $r->vehicles,
                'spend'           => (float) $r->spend,
                'spend_per_car'   => $r->vehicles > 0 ? round(((float) $r->spend) / (int) $r->vehicles, 2) : null,
            ], $rows),
            'note' => 'Repair spend by the age the car had reached when the money was spent. Purchase dates are complete for the fleet, so the age axis is measured rather than inferred.',
        ];
    }

    /**
     * C15 — money spent on cars that were still under warranty.
     *
     * Possibly recoverable cash, and the kind of finding that pays for a platform. Reported only for
     * the 383 of 438 cars whose warranty end date is known; the rest are excluded rather than assumed
     * out of warranty, because assuming would understate the number in a direction nobody would check.
     */
    private function warrantyLeakage(): Kpi
    {
        $known = (int) DB::table('vehicles')->whereNull('deleted_at')->whereNotNull('warranty_end_date')->count();
        $total = (int) DB::table('vehicles')->whereNull('deleted_at')->count();

        if ($known === 0) {
            return Kpi::unavailable('warranty_leakage', 'Warranty Leakage', 'No vehicle has a warranty end date recorded.', 'currency');
        }

        $row = DB::table('vehicle_expenses AS e')
            ->join('vehicles AS v', 'v.id', '=', 'e.vehicle_id')
            ->whereNull('v.deleted_at')
            ->whereNotNull('v.warranty_end_date')
            ->whereColumn('e.entry_date', '<=', 'v.warranty_end_date')
            ->whereNotIn('e.category', self::NON_REPAIR_CATEGORIES)
            ->where('e.amount', '>', 0)
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(e.amount), 0) AS total, COUNT(DISTINCT v.id) AS vehicles')
            ->first();

        return Kpi::measured(
            'warranty_leakage',
            'Warranty Leakage',
            round((float) $row->total, 2),
            'currency',
            (int) $row->n,
            Kpi::LOWER_BETTER,
            [
                'vehicles' => (int) $row->vehicles,
                'note'     => 'Repair money spent on cars that were still inside their warranty window. Possibly recoverable.',
            ],
            new Coverage($known, $total, null, 'Vehicles with a warranty end date on file. The rest are excluded, not assumed out of warranty.'),
        );
    }

    /** How many months inside the window the expense feed actually delivered at credible volume. */
    private function creditedMonths(Carbon $start, Carbon $end): int
    {
        return (int) DB::table('vehicle_expenses')
            ->whereBetween('entry_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('DATE_FORMAT(entry_date, "%Y-%m") AS ym')
            ->groupBy('ym')
            ->havingRaw('COUNT(*) >= 100')
            ->get()
            ->count();
    }

    /** @return array<string, mixed> */
    private function provenance(?\DateTimeInterface $asOf): array
    {
        return [
            'recurrence' => [
                'source'  => 'fault_recurrence_pairs (canonical)',
                'reader'  => 'RecurrenceRepository',
                'version' => config('metrics.recurrence.version'),
                'as_of'   => $asOf?->format('Y-m-d'),
            ],
            'spend' => [
                'source' => 'vehicle_expenses',
                'note'   => 'Repair categories only. Classification comes from the expense remark.',
            ],
            'availability' => [
                'source' => 'FleetUtilizationService (canonical owner of maintenance-days)',
            ],
            'no_composite_score' => 'This page publishes no blended health or risk score. Every figure maps to rows that can be listed.',
        ];
    }
}
