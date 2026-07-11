<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\InspectionSchedule;
use App\Models\Maintenance;
use App\Models\Vehicle;
use App\Models\VehicleRegistration;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Read-only KPIs for the dashboard.
 *
 * The homepage fires several of these at once (summary + overdue lists + trends + fleet-pulse), and
 * each is a fan-out of COUNT/SUM/GROUP BY queries over the whole fleet. They change slowly (the data
 * is refreshed by the periodic OfficeManager sync, not by live user edits), so every public read here
 * is wrapped in a short-TTL cache via remember(). A sync (or any large data change) can call
 * DashboardService::flushCache() to bump the cache version and surface fresh figures immediately
 * instead of waiting out the TTL. Fast-moving boards (the /maintenance pipeline) are deliberately NOT
 * cached here — they have their own live endpoints.
 */
class DashboardService
{
    /** TTL (seconds) for the fast-moving KPI aggregates. Bounds dashboard staleness to ~1 minute. */
    private const CACHE_TTL = 60;

    /** TTL (seconds) for the month-by-month trend series — historical, changes very slowly. */
    private const CACHE_TTL_TRENDS = 600;

    /** Versioned cache namespace — bumping the version (flushCache) orphans every prior entry. */
    private const CACHE_VERSION_KEY = 'dashboard:cache_version';

    /** How many days ahead a rental counts as "expiring soon" for the Proactive Flags panel. */
    public const EXPIRY_WINDOW_DAYS = 7;

    /** Only chase unpaid balances on rentals returned within this trailing window (older = write-off). */
    private const INVOICE_OVERDUE_MONTHS = 12;

    /**
     * Ignore trivial residual balances below this (AED) when flagging "payment overdue" — OM
     * contract_balance carries a long tail of tiny rounding/legacy remainders that would otherwise
     * bury the real debts. Tune via FLEET_INVOICE_OVERDUE_MIN (env); 0 = flag any positive balance.
     */
    private function invoiceOverdueFloor(): float
    {
        return (float) config('fleet.invoice_overdue_min_balance', env('FLEET_INVOICE_OVERDUE_MIN', 100));
    }

    public function __construct(
        private RealProfitService $realProfit,
        private OperationsService $operations,
    ) {
    }

    /**
     * Cache a dashboard read under the current cache version. The version prefix means a single
     * flushCache() invalidates EVERY parameter variant (every expiring-days / months value) at once —
     * the tag-free invalidation pattern the `database`/`file` cache stores need (they have no tags).
     */
    private function remember(string $key, int $ttl, \Closure $callback): mixed
    {
        $version = (int) Cache::get(self::CACHE_VERSION_KEY, 1);

        return Cache::remember("dashboard:v{$version}:{$key}", $ttl, $callback);
    }

    /**
     * Invalidate every cached dashboard aggregate at once (call after a sync / large data change).
     * Bumps the cache version so all prior keys become unreachable and TTL-expire on their own.
     */
    public static function flushCache(): void
    {
        $version = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        Cache::forever(self::CACHE_VERSION_KEY, $version + 1);
    }

    /**
     * @return array{
     *   total_outstanding_balance: float,
     *   active_contracts: int,
     *   expiring_registrations: int,
     *   vehicles_for_sale: int
     * }
     */
    public function summary(int $expiringDays = 7): array
    {
        return $this->remember("summary:{$expiringDays}", self::CACHE_TTL, fn () => [
            'total_outstanding_balance' => $this->totalOutstandingBalance(),
            'cars_in_maintenance'       => $this->carsInMaintenance(),
            'overdue_rentals'           => $this->overdueRentals(),
            'overdue_maintenance'       => $this->overdueMaintenance(),
            'active_contracts'          => $this->activeContracts(),
            'expiring_registrations'    => $this->expiringRegistrations($expiringDays),
            'vehicles_for_sale'         => $this->vehiclesForSale(),
            'negative_yield'            => $this->negativeYield(),
            'uncosted_repairs'          => $this->uncostedRepairs(),
            'pending_approvals'         => $this->pendingApprovals(),
            'fleet_status'              => $this->fleetStatus(),
        ]);
    }

    /**
     * Vehicles flagged "Negative Yield" — Real Net Profit over the trailing window is below their
     * maintenance/repair spend, i.e. they cost more to keep than they earn (sell candidates).
     * Returns the count, the window, and the worst offenders for the homepage.
     *
     * @return array{count:int, window_months:int, worst:array<int,array<string,mixed>>}
     */
    public function negativeYield(int $limit = 5): array
    {
        $rows = $this->realProfit->negativeYieldVehicles();

        return [
            'count'         => count($rows),
            'window_months' => RealProfitService::YIELD_MONTHS,
            'worst'         => array_slice($rows, 0, $limit),
        ];
    }

    /**
     * Recent repair VISITS (vehicle|out_date) within the yield window that have NO cost on any
     * row — the size of the "understated spend" gap, and the count behind Quick Cost Input.
     */
    public function uncostedRepairs(): int
    {
        $cutoff = Carbon::today()->subMonths(RealProfitService::YIELD_MONTHS)->toDateString();

        $visits = DB::table('maintenances')
            ->whereIn('origin', Maintenance::WORKSHOP_LOG_ORIGINS)
            ->whereNotNull('vehicle_id')
            ->whereNotNull('out_date')
            ->whereDate('out_date', '>=', $cutoff)
            ->groupBy('vehicle_id', 'out_date')
            ->havingRaw('COALESCE(SUM(cost),0) = 0')
            ->select('vehicle_id')
            ->get();

        return $visits->count();
    }

    /** Maintenance jobs over the threshold awaiting manual approval. */
    public function pendingApprovals(): int
    {
        return Contract::where('contract_type', 'U')
            ->whereHas('maintenance', fn ($q) => $q->where('approval_status', 'pending'))
            ->count();
    }

    /**
     * Fleet breakdown by the OfficeManager lifecycle `status` (AssetStatusNo) — the API
     * is the source of truth, NOT the contract-derived operational_status. "Available" is
     * exactly the cars whose status is `ready`; rented = `rented`; maintenance =
     * `under_maintenance`; everything else (office_use / out_of_order / suspended /
     * disposed / sold / returned) rolls into `other`.
     *
     * `booked` is an OVERLAY, not a slice of the total: a car reserved for a future
     * window is usually still `ready` today, so available + rented + maintenance +
     * other = total, while booked is counted separately on top.
     *
     * Returns the FULL OfficeManager lifecycle breakdown (one bucket per status), which is
     * mutually exclusive and sums to `total` — so the dashboard donut can show a named slice
     * for every status (Sold, Disposed, …) instead of a vague "Other". `booked` is an
     * operational overlay (a car can be ready AND booked), so it's reported separately and is
     * NOT part of the donut sum.
     *
     * @return array{available:int, rented:int, maintenance:int, office_use:int, out_of_order:int, suspended:int, returned:int, sold:int, disposed:int, booked:int, other:int, total:int}
     */
    public function fleetStatus(): array
    {
        // SINGLE SOURCE OF TRUTH for live state: classify every car from CONTRACTS + the canonical
        // maintenance set — NOT the OfficeManager lifecycle `status`, which drifts (a car out on
        // rent usually stays `ready`/`rented` in OM). Priority per car: in-maintenance ⇒ rented
        // (open type-C) ⇒ available (an active car free to earn) ⇒ its lifecycle bucket. The result
        // is mutually exclusive, sums to total, and matches the Vehicles list, Fleet Ops, the
        // /maintenance board, the fleet-pulse grid and the per-car operational_status.
        // The two override sets (same canonical sources as the board / per-car operational_status).
        // Maintenance wins over rented, so drop any rented car that is also in the garage.
        $maintSet  = array_flip($this->operations->vehiclesInMaintenance());
        $rentedSet = [];
        foreach (
            Contract::where('contract_type', 'C')->currentlyOpen()
                ->whereNotNull('vehicle_id')->distinct()->pluck('vehicle_id')->all() as $vid
        ) {
            if (! isset($maintSet[$vid])) {
                $rentedSet[$vid] = true;
            }
        }

        $counts = [
            'available' => 0, 'rented' => count($rentedSet), 'maintenance' => count($maintSet),
            'office_use' => 0, 'out_of_order' => 0, 'suspended' => 0, 'returned' => 0,
            'sold' => 0, 'disposed' => 0, 'other' => 0,
        ];

        // Per-status fleet totals in ONE grouped query (no model hydration, no full-table load).
        $byStatus = DB::table('vehicles')
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        // The override cars must NOT also be counted by their lifecycle status — fetch just that
        // subset's statuses and subtract them from the totals before folding the rest into buckets.
        $overrideIds = array_keys($maintSet + $rentedSet);
        $overrideByStatus = $overrideIds
            ? DB::table('vehicles')->whereIn('id', $overrideIds)
                ->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status')->all()
            : [];

        foreach ($byStatus as $status => $total) {
            $remaining = (int) $total - (int) ($overrideByStatus[$status] ?? 0);
            if ($remaining <= 0) {
                continue;
            }
            $key = (string) $status;
            if (in_array($key, ['ready', 'rented'], true)) {
                // Active car (ready, or OM-says-rented but with NO open rental) = free to earn.
                $counts['available'] += $remaining;
            } elseif (array_key_exists($key, $counts)) {
                $counts[$key] += $remaining;   // office_use / out_of_order / suspended / returned / sold / disposed
            } else {
                $counts['other'] += $remaining;   // uncategorised / null status
            }
        }

        return $counts + [
            'booked' => $this->bookedCars(),       // operational overlay, NOT part of the donut sum
            'total'  => array_sum($counts),         // every car counted in exactly one bucket
        ];
    }

    /**
     * Cars with an active/upcoming booking (type-R reservation whose window has not
     * ended — same definition OperationsService uses to guard maintenance clashes).
     * Distinct vehicles, so two bookings on one car count once.
     */
    public function bookedCars(): int
    {
        return Contract::upcomingReservation()->whereNotNull('vehicle_id')->distinct()->count('vehicle_id');
    }

    /**
     * Cars currently in the garage — the canonical maintenance set (open U-contract, manual garage
     * event, OR open workflow ticket). One definition, shared with the donut, the fleet-pulse grid
     * and the per-car operational_status, so the KPI can never disagree with them again.
     */
    public function carsInMaintenance(): int
    {
        return count($this->operations->vehiclesInMaintenance());
    }

    /**
     * Base query for overdue rentals: an open rental (type C) whose estimated return
     * date (out_date + planned days) is already in the past. `days` only fills in once
     * a contract has been detail-enriched, so contracts without it are simply not counted.
     */
    private function overdueRentalsQuery()
    {
        return Contract::query()
            ->where('contract_type', 'C')
            ->currentlyOpen()
            ->whereNotNull('out_date')
            ->where('days', '>', 0)
            ->whereRaw('DATE_ADD(out_date, INTERVAL days DAY) < CURDATE()');
    }

    /** How many rentals are past their estimated return date. */
    public function overdueRentals(): int
    {
        return $this->overdueRentalsQuery()->count();
    }

    /**
     * The actionable list behind the KPI: each overdue rental with its car, customer,
     * estimated return date, how many days late, and the outstanding balance — sorted
     * most-overdue first so the worst offenders surface at the top.
     */
    public function overdueRentalsList(): array
    {
        return $this->remember('overdue_rentals_list', self::CACHE_TTL, function () {
            $today = now()->startOfDay();

            return $this->overdueRentalsQuery()
                ->with(['vehicle', 'customer'])
                ->get()
                ->map(function ($c) use ($today) {
                    $due = $c->out_date->copy()->addDays((int) $c->days);

                    return [
                        'id'           => $c->id,
                        'contract_no'  => $c->contract_no,
                        'vehicle_id'   => $c->vehicle_id,
                        'plate'        => $c->vehicle?->plate_no,
                        'car'          => $c->vehicle ? trim($c->vehicle->make . ' ' . $c->vehicle->model) : null,
                        'customer'     => $c->customer?->name_en ?: ($c->customer?->customer_no ? '#' . $c->customer->customer_no : null),
                        'customer_id'  => $c->customer_id,
                        'out_date'     => $c->out_date->toDateString(),
                        'days'         => (int) $c->days,
                        'due'          => $due->toDateString(),
                        'days_overdue' => (int) abs($due->diffInDays($today)),
                        'balance'      => round((float) $c->contract_balance, 2),
                    ];
                })
                ->sortByDesc('days_overdue')
                ->values()
                ->all();
        });
    }

    /**
     * Cars delayed in the garage: open maintenance contracts whose expected return
     * date has already passed. (Open maintenance with no expected-return set isn't
     * counted as "delayed" — there's no date to be late against.)
     */
    private function overdueMaintenanceQuery()
    {
        return Contract::query()
            ->where('contract_type', 'U')
            ->currentlyOpen()
            ->whereHas('maintenance', fn ($q) => $q
                ->whereNotNull('expected_return_date')
                ->whereDate('expected_return_date', '<', now()->toDateString()));
    }

    /** How many cars are past their expected return-from-maintenance date. */
    public function overdueMaintenance(): int
    {
        return $this->overdueMaintenanceQuery()->count();
    }

    /** The actionable list of cars stuck in the garage past their due date. */
    public function overdueMaintenanceList(): array
    {
        return $this->remember('overdue_maintenance_list', self::CACHE_TTL, function () {
            $today = now()->startOfDay();

            return $this->overdueMaintenanceQuery()
                ->with(['vehicle', 'maintenance.vendor'])
                ->get()
                ->map(function ($c) use ($today) {
                    $due = \Carbon\Carbon::parse($c->maintenance->expected_return_date)->startOfDay();

                    return [
                        'id'           => $c->id,
                        'contract_no'  => $c->contract_no,
                        'vehicle_id'   => $c->vehicle_id,
                        'plate'        => $c->vehicle?->plate_no,
                        'car'          => $c->vehicle ? trim($c->vehicle->make . ' ' . $c->vehicle->model) : null,
                        'garage'       => $c->maintenance?->vendor?->name,
                        'since'        => optional($c->out_date)->toDateString(),
                        'due'          => $due->toDateString(),
                        'days_overdue' => (int) abs($today->diffInDays($due)),
                    ];
                })
                ->sortByDesc('days_overdue')
                ->values()
                ->all();
        });
    }

    // ── Proactive Flags (forward-looking conditions for the homepage + the bell) ──────────────
    // Each list below is the SINGLE source both the dashboard panel and the NotificationScanner
    // read, so a flag on the homepage and its matching bell alert can never drift apart. Every row
    // carries the ids needed to deep-link straight to its source record (traceability).

    /**
     * Open rentals whose estimated return date falls within the next N days (default 7) and is
     * NOT yet overdue — the "coming due" heads-up. Same rental (type C, currently out) and
     * due-date definition as overdueRentalsList() (out_date + planned `days`), just the forward
     * window, so the two lists are two halves of one timeline and can never disagree.
     *
     * @return array<int,array<string,mixed>>
     */
    public function expiringRentalsList(int $days = self::EXPIRY_WINDOW_DAYS): array
    {
        $days = max(1, $days);

        return $this->remember("expiring_rentals_list:{$days}", self::CACHE_TTL, function () use ($days) {
            $today = now()->startOfDay();

            return Contract::query()
                ->where('contract_type', 'C')
                ->currentlyOpen()
                ->whereNotNull('out_date')
                ->where('days', '>', 0)
                ->whereRaw('DATE_ADD(out_date, INTERVAL days DAY) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)', [$days])
                ->with(['vehicle', 'customer'])
                ->get()
                ->map(function ($c) use ($today) {
                    $due = $c->out_date->copy()->addDays((int) $c->days);

                    return [
                        'id'          => $c->id,
                        'contract_no' => $c->contract_no,
                        'vehicle_id'  => $c->vehicle_id,
                        'plate'       => $c->vehicle?->plate_no,
                        'car'         => $c->vehicle ? trim($c->vehicle->make . ' ' . $c->vehicle->model) : null,
                        'customer'    => $c->customer?->name_en ?: ($c->customer?->customer_no ? '#' . $c->customer->customer_no : null),
                        'customer_id' => $c->customer_id,
                        'due'         => $due->toDateString(),
                        'days_left'   => (int) $today->diffInDays($due, false), // 0 = due today
                        'balance'     => round((float) $c->contract_balance, 2),
                    ];
                })
                ->sortBy('days_left')
                ->values()
                ->all();
        });
    }

    /**
     * Customers who still owe money on a CONCLUDED rental: an outstanding contract_balance on a
     * contract whose car is already back (in_date set), bounded to the trailing
     * INVOICE_OVERDUE_MONTHS so the list stays chase-able. Distinct from overdueRentalsList()
     * (which nags cars still OUT past their due date) — this is "the rental ended, the bill didn't
     * get settled". Source of truth is contracts.contract_balance (OM-synced), the same field the
     * Notification Test Console samples for its invoice_overdue payload.
     *
     * @return array<int,array<string,mixed>>
     */
    /** Base query for the invoice-overdue set — returned rentals (last 12mo) owing ≥ the floor. */
    private function overdueInvoicesQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return Contract::query()
            ->where('contract_balance', '>=', max(0.01, $this->invoiceOverdueFloor()))
            ->whereNotNull('in_date')
            ->whereDate('in_date', '<=', now()->toDateString())
            ->whereDate('in_date', '>=', now()->subMonths(self::INVOICE_OVERDUE_MONTHS)->toDateString());
    }

    /** The top $limit overdue-invoice contracts by balance (heaviest debts first). */
    public function overdueInvoicesList(int $limit = 100): array
    {
        return $this->remember("overdue_invoices_list:{$limit}", self::CACHE_TTL, function () use ($limit) {
            return $this->overdueInvoicesQuery()
                ->with(['vehicle', 'customer'])
                ->orderByDesc('contract_balance')
                ->limit($limit)
                ->get()
                ->map(fn ($c) => [
                    'id'          => $c->id,
                    'contract_no' => $c->contract_no,
                    'vehicle_id'  => $c->vehicle_id,
                    'plate'       => $c->vehicle?->plate_no,
                    'car'         => $c->vehicle ? trim($c->vehicle->make . ' ' . $c->vehicle->model) : null,
                    'customer'    => $c->customer?->name_en ?: ($c->customer?->customer_no ? '#' . $c->customer->customer_no : null),
                    'customer_id' => $c->customer_id,
                    'returned_on' => optional($c->in_date)->toDateString(),
                    'balance'     => round((float) $c->contract_balance, 2),
                ])
                ->all();
        });
    }

    /** True count + total AED of the overdue-invoice set (NOT the display cap) — for the headline. */
    public function overdueInvoicesSummary(): array
    {
        return $this->remember('overdue_invoices_summary', self::CACHE_TTL, function () {
            $q = $this->overdueInvoicesQuery();

            return [
                'count' => (clone $q)->count(),
                'total' => round((float) (clone $q)->sum('contract_balance'), 2),
            ];
        });
    }

    /**
     * Active inspection schedules that are overdue or due soon, via the same
     * InspectionSchedule::statusInfo() the Inspections page uses (whichever of time/meter is worse
     * wins). Small table — iterated in PHP rather than SQL. Overdue sorts ahead of due-soon.
     *
     * @return array<int,array<string,mixed>>
     */
    public function inspectionsDueList(): array
    {
        return $this->remember('inspections_due_list', self::CACHE_TTL, function () {
            return InspectionSchedule::with('vehicle')
                ->where('active', true)
                ->get()
                ->map(function (InspectionSchedule $s) {
                    $info = $s->statusInfo();

                    return [
                        'id'             => $s->id,
                        'vehicle_id'     => $s->vehicle_id,
                        'plate'          => $s->vehicle?->plate_no,
                        'car'            => $s->vehicle ? trim($s->vehicle->make . ' ' . $s->vehicle->model) : null,
                        'name'           => $s->name,
                        'pillar'         => $s->pillar,
                        'status'         => $info['status'],
                        'label'          => $info['label'],
                        'days_remaining' => $info['days_remaining'],
                        'km_remaining'   => $info['km_remaining'],
                    ];
                })
                ->filter(fn ($r) => in_array($r['status'], ['overdue', 'due_soon'], true))
                ->sortBy(fn ($r) => [$r['status'] === 'overdue' ? 0 : 1, $r['days_remaining'] ?? 9999])
                ->values()
                ->all();
        });
    }

    /**
     * The homepage "Proactive Flags" panel — three forward-looking conditions to act on BEFORE
     * they become problems: rentals expiring within N days, concluded rentals with an unpaid
     * balance, and inspections due/overdue. Each returns a count + the top few items, every item
     * deep-linkable to its source record. Reads the exact lists the NotificationScanner raises its
     * rental_expiring / invoice_overdue / inspection_due alerts from, so panel and bell agree.
     *
     * @return array<string,mixed>
     */
    public function proactiveFlags(int $days = self::EXPIRY_WINDOW_DAYS): array
    {
        return $this->remember("proactive_flags:{$days}", self::CACHE_TTL, function () use ($days) {
            $rentals     = $this->expiringRentalsList($days);
            $invSummary  = $this->overdueInvoicesSummary();
            $invItems    = $this->overdueInvoicesList(5);
            $inspections = $this->inspectionsDueList();

            return [
                'window_days'     => $days,
                'contract_expiry' => ['count' => count($rentals),      'items' => array_slice($rentals, 0, 5)],
                // count/total are the TRUE totals of the whole set; items is just the top-5 shown.
                'invoice_overdue' => ['count' => $invSummary['count'], 'total' => $invSummary['total'], 'items' => $invItems],
                'inspection_due'  => ['count' => count($inspections),  'items' => array_slice($inspections, 0, 5)],
            ];
        });
    }

    /** Sum of what customers still owe (positive balances only). */
    public function totalOutstandingBalance(): float
    {
        return round((float) Customer::where('balance', '>', 0)->sum('balance'), 2);
    }

    /** Contracts currently open (car is out: rented / in maintenance / etc.). */
    public function activeContracts(): int
    {
        return Contract::currentlyOpen()->count();
    }

    /** Vehicles whose registration or insurance is expired or expires within N days. */
    public function expiringRegistrations(int $days = 7): int
    {
        $cutoff = now()->startOfDay()->addDays($days)->toDateString();

        return VehicleRegistration::where(function ($q) use ($cutoff) {
            $q->whereNotNull('expiry_date')->where('expiry_date', '<=', $cutoff)
                ->orWhere(function ($q2) use ($cutoff) {
                    $q2->whereNotNull('insurance_expiry')->where('insurance_expiry', '<=', $cutoff);
                });
        })->count();
    }

    /** Vehicles currently flagged for sale (the API's ForSale flag). */
    public function vehiclesForSale(): int
    {
        return Vehicle::where('for_sale', true)->count();
    }

    /**
     * Month-by-month maintenance trends for the dashboard charts — two aligned series
     * over the trailing N months (empty months included so the chart never has gaps):
     *
     *   • cost     — total workshop spend per month (the bar chart) + the visit count
     *   • downtime — average days a car spent in the shop per visit that month (the
     *                trend line; lower = improving). Also carries total days + visits.
     *
     * A "visit" is one (vehicle, out_date) group of the live workshop log (sheet +
     * manual), matching how uncostedRepairs() and the board count visits — so multiple
     * event rows (OUT / IN / Follow up) for the same trip count once. Cost still sums
     * every row's amount. Single grouped query, cheap to call.
     *
     * @return array{months:int, cost:array<int,array<string,mixed>>, downtime:array<int,array<string,mixed>>}
     */
    public function maintenanceTrends(int $months = 12): array
    {
        $months = max(1, min(36, $months));

        return $this->remember("trends:{$months}", self::CACHE_TTL_TRENDS, fn () => $this->computeMaintenanceTrends($months));
    }

    /** The two-query trend computation behind maintenanceTrends() (cached by its caller). */
    private function computeMaintenanceTrends(int $months): array
    {
        $start  = Carbon::today()->startOfMonth()->subMonths($months - 1);

        // Pre-seed every month so gaps render as zero, not as a missing point.
        $skeleton = [];
        for ($i = 0; $i < $months; $i++) {
            $m = $start->copy()->addMonths($i);
            $skeleton[$m->format('Y-m')] = [
                'month'       => $m->format('Y-m'),
                'label'       => $m->format('M'),
                'cost'        => 0.0,
                'cost_visits' => 0,
                'down_days'   => 0,
                'down_visits' => 0,
            ];
        }

        // COST (the spend bars): summed from the workshop log — the canonical maintenance-cost
        // source the rest of the app (RealProfitService / Profitability / the board) also uses.
        // A "visit" is one (vehicle, out_date) group, so multiple event rows count once.
        $costRows = DB::select(
            "SELECT ym, ROUND(SUM(cost), 2) AS cost, COUNT(*) AS visits
             FROM (
                 SELECT DATE_FORMAT(out_date, '%Y-%m') AS ym, vehicle_id, out_date,
                        COALESCE(SUM(cost), 0) AS cost
                 FROM maintenances
                 WHERE origin IN ('sheet', 'manual')
                   AND out_date IS NOT NULL
                   AND out_date >= ?
                 GROUP BY vehicle_id, out_date
             ) v
             GROUP BY ym",
            [$start->toDateString()]
        );
        foreach ($costRows as $r) {
            if (isset($skeleton[$r->ym])) {
                $skeleton[$r->ym]['cost']        = round((float) $r->cost, 2);
                $skeleton[$r->ym]['cost_visits'] = (int) $r->visits;
            }
        }

        // DOWNTIME (the trend line): days in the shop per visit, sourced ENTIRELY from type-U
        // maintenance CONTRACTS (out_date → in_date; an open visit spans to today) — the SAME
        // canonical basis as Fleet Utilization. The retired sheet/manual log is deliberately NOT
        // used here: its stale, never-closed OUT rows invented huge fake downtime.
        $downRows = DB::select(
            "SELECT DATE_FORMAT(out_date, '%Y-%m') AS ym,
                    COUNT(*) AS visits,
                    SUM(GREATEST(DATEDIFF(COALESCE(in_date, CURDATE()), out_date), 0)) AS down_days
             FROM contracts
             WHERE contract_type = 'U'
               AND out_date IS NOT NULL
               AND out_date >= ?
             GROUP BY ym",
            [$start->toDateString()]
        );
        foreach ($downRows as $r) {
            if (isset($skeleton[$r->ym])) {
                $skeleton[$r->ym]['down_days']   = (int) $r->down_days;
                $skeleton[$r->ym]['down_visits'] = (int) $r->visits;
            }
        }

        $series = array_values($skeleton);

        return [
            'months' => $months,
            'cost' => array_map(fn ($s) => [
                'label'  => $s['label'],
                'month'  => $s['month'],
                'value'  => $s['cost'],
                'visits' => $s['cost_visits'],
            ], $series),
            'downtime' => array_map(fn ($s) => [
                'label'      => $s['label'],
                'month'      => $s['month'],
                'value'      => $s['down_visits'] ? round($s['down_days'] / $s['down_visits'], 1) : 0,
                'total_days' => $s['down_days'],
                'visits'     => $s['down_visits'],
            ], $series),
        ];
    }

    /**
     * The "Fleet Pulse" grid: one card per car in the active fleet with a colour-coded
     * live state and, for cars in the shop, a maintenance-completion percentage derived
     * from the workflow stage. States: incident (red) > garage (amber) > rented (green)
     * > available (emerald) > idle/other (slate). One pass, a few keyed lookups — no N+1.
     *
     * @return array<int,array<string,mixed>>
     */
    public function fleetPulse(): array
    {
        return $this->remember('fleet_pulse', self::CACHE_TTL, fn () => $this->computeFleetPulse());
    }

    /** The per-car grid computation behind fleetPulse() (cached by its caller). */
    private function computeFleetPulse(): array
    {
        // The cars you actually operate — drop the ones that have left the fleet.
        $vehicles = Vehicle::whereNotIn('status', ['sold', 'disposed', 'returned'])
            ->orderBy('plate_no')
            ->get();

        // THE canonical "in the garage right now" set — identical to the KPI, the donut and the
        // per-car operational_status, so the grid can never disagree with the headline count.
        $maintSet = array_flip($this->operations->vehiclesInMaintenance());

        // Cars in the garage on an open type-U contract, with when they went in (for "days in shop").
        $openMaint = Contract::where('contract_type', 'U')->currentlyOpen()
            ->whereNotNull('vehicle_id')
            ->get(['vehicle_id', 'out_date'])
            ->keyBy('vehicle_id');

        // Live workflow tickets → stage drives the completion % and incident flag.
        $tickets = Maintenance::openWorkflow()->whereNotNull('vehicle_id')
            ->get(['vehicle_id', 'workflow_status', 'maintenance_type', 'out_date'])
            ->keyBy('vehicle_id');

        // Workflow stage → rough completion of the repair journey.
        $progress = [
            Maintenance::WF_INSPECTION_PENDING  => 15,
            Maintenance::WF_AWAITING_DISPATCH   => 25,
            Maintenance::WF_IN_TRANSIT          => 40,
            Maintenance::WF_UNDER_REPAIR        => 60,
            Maintenance::WF_REPAIR_REVIEW       => 75,
            Maintenance::WF_READY_REINSPECTION  => 85,
        ];
        $incidentTypes = [Maintenance::TYPE_INS_INCIDENT, Maintenance::TYPE_NON_INS_INCIDENT];
        $today = Carbon::today();

        return $vehicles->map(function ($v) use ($openMaint, $maintSet, $tickets, $progress, $incidentTypes, $today) {
            $ticket = $tickets->get($v->id);
            $inGarage = isset($maintSet[$v->id]);

            $isIncident = $ticket && in_array($ticket->maintenance_type, $incidentTypes, true);

            // How long the car has been in the shop, from the earliest signal we have.
            $since = $openMaint->get($v->id)?->out_date ?? $ticket?->out_date;
            $daysInShop = $since ? (int) abs($today->diffInDays(Carbon::parse($since))) : null;

            if ($inGarage && $isIncident) {
                $state = 'incident'; $tone = 'red'; $label = 'Incident';
            } elseif ($inGarage) {
                $state = 'garage'; $tone = 'amber'; $label = 'In garage';
            } elseif ($v->status === 'rented') {
                $state = 'rented'; $tone = 'emerald'; $label = 'On rent';
            } elseif ($v->status === 'ready') {
                $state = 'available'; $tone = 'blue'; $label = 'Available';
            } else {
                $state = 'idle'; $tone = 'slate'; $label = ucfirst(str_replace('_', ' ', (string) $v->status));
            }

            // Completion only means something while the car is in the shop.
            $completion = null;
            if ($inGarage) {
                $completion = $ticket ? ($progress[$ticket->workflow_status] ?? 50) : 50;
            }

            return [
                'id'          => $v->id,
                'plate'       => $v->plate_no,
                'car'         => trim($v->make . ' ' . $v->model) ?: $v->code,
                'state'       => $state,
                'tone'        => $tone,
                'status'      => $label,
                'completion'  => $completion,
                'days_in_shop'=> $inGarage ? $daysInShop : null,
                'odometer'    => $v->odometer,
                'workflow'    => $ticket?->workflow_status,
                // Visual Condition Grade — drives the small orange (cosmetic) / red flag on the card.
                'condition_grade' => $v->condition_grade,
                'condition_note'  => $v->condition_note,
                // Deferred Maintenance — the standing 🛠️↩️ "owes the workshop" flag on the card.
                'is_deferred_maintenance' => (bool) $v->is_deferred_maintenance,
                'deferred_maintenance_reason'    => $v->deferred_maintenance_reason,
            ];
        })->all();
    }
}
