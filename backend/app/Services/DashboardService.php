<?php

namespace App\Services;

use App\Contracts\VehicleExpenseProvider;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\InspectionSchedule;
use App\Models\Maintenance;
use App\Models\MaintenanceTask;
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
        private FleetUtilizationService $fleetUtilization,
        private VehicleExpenseProvider $expenses,
        private MaintenanceCheckpointService $checkpoints,
        private MaintenanceAnalyticsService $analytics,
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
            'fixed_this_month'          => $this->fixedThisMonth(),
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

    /**
     * Workflow tickets FIXED this calendar month — re-inspected, signed off and returned to service
     * (workflow_status = closed, wf_closed_at in the current month). The "throughput / good news"
     * counter next to the outstanding-work tiles.
     */
    public function fixedThisMonth(): int
    {
        return Maintenance::where('workflow_status', Maintenance::WF_CLOSED)
            ->whereBetween('wf_closed_at', [
                Carbon::now()->startOfMonth(),
                Carbon::now()->endOfMonth(),
            ])
            ->count();
    }

    /** Maintenance jobs over the threshold awaiting manual approval. */
    public function pendingApprovals(): int
    {
        return Contract::where('contract_type', 'U')
            ->whereHas('maintenance', fn ($q) => $q->where('approval_status', 'pending'))
            ->count();
    }

    /**
     * The fleet split behind the dashboard donut, in two layers.
     *
     * WHICH CARS COUNT is the fleet register's decision — the "Faster" tab of the Database
     * Warehouse workbook, captured per car in `vehicles.sheet_status`. The active fleet is
     * exactly the rows that tab marks "Active", so `available + rented + maintenance` always
     * equals the figure someone reads off the sheet. It is the same number by construction, not
     * by coincidence, and it cannot drift again: no status value and no contract can promote a
     * car into those buckets if the register does not list it as Active.
     *
     * WHAT EACH CAR IS DOING is then decided by CONTRACTS, not by the lifecycle `status` column,
     * which drifts badly (a car out on rent usually still reads `ready` in OfficeManager).
     * Priority: open type-U (in the garage) ⇒ open type-C (out earning) ⇒ available.
     *
     * The register deliberately outranks live contract data here. A car the sheet calls "Office"
     * that still carries an open rental is reported under office_use, not On Rent. That conflict
     * is not hidden — it is surfaced on the Vehicles page as an amber "Register mismatch" chip,
     * which is where it can actually be resolved. Silently counting the car twice, once per
     * source, is what produced the drift this method exists to prevent.
     *
     * Everything the register does not call Active folds into its lifecycle bucket, with the
     * sheet's for-sale cars named rather than dumped in `other`. All buckets are mutually
     * exclusive and sum to `total`.
     *
     * `booked` is an OVERLAY, not a slice: a car reserved for a future window is usually still
     * available today, so it is reported alongside and is NOT part of the sum.
     *
     * NOTE: maintenance here is CONTRACT-based only (open type-U), deliberately narrower than
     * OperationsService::vehiclesInMaintenance(), which also unions workflow tickets and manual
     * garage events and drives the /maintenance board. The donut may legitimately show fewer
     * cars in the shop than that board does.
     *
     * @return array{available:int, rented:int, maintenance:int, office_use:int, out_of_order:int, suspended:int, returned:int, sold:int, disposed:int, for_sale:int, booked:int, other:int, total:int}
     */
    public function fleetStatus(): array
    {
        // MEMBERSHIP is the register's call, not ours. The active fleet is exactly the cars the
        // "Faster" tab marks "Active" — no more, no less — so available + rented + maintenance
        // always equals the number the sheet shows. A car the register does not call Active
        // cannot enter those three buckets no matter what its status column or its contracts say;
        // it folds into its lifecycle bucket below instead.
        //
        // This deliberately overrides live contract data with the register's word. A car the sheet
        // calls "Office" that still carries an open rental will now sit in office_use rather than
        // On Rent — the conflict does not disappear, it moves to where it can be seen and fixed:
        // the Vehicles page flags it amber under "Register mismatch".
        $registerActive = DB::table('vehicles')
            ->whereNull('deleted_at')
            ->whereRaw("LOWER(TRIM(COALESCE(sheet_status, ''))) = 'active'")
            ->pluck('id')->mapWithKeys(fn ($id) => [(int) $id => true])->all();

        // WITHIN the register's fleet, contracts decide what each car is doing right now — the
        // status column drifts (a car out on rent usually still reads `ready` in OM), so it is not
        // trusted for this. Maintenance wins over rented; whatever is left is free to earn.
        $maintSet = [];
        foreach ($this->contractMaintenanceVehicleIds() as $vid) {
            if (isset($registerActive[$vid])) {
                $maintSet[$vid] = true;
            }
        }

        $rentedSet = [];
        foreach (
            Contract::where('contract_type', 'C')->currentlyOpen()
                ->whereNotNull('vehicle_id')->distinct()->pluck('vehicle_id')->all() as $vid
        ) {
            $vid = (int) $vid;
            if (isset($registerActive[$vid]) && ! isset($maintSet[$vid])) {
                $rentedSet[$vid] = true;
            }
        }

        $counts = [
            // Residual, so the three always sum to the register's Active count exactly.
            'available'   => count($registerActive) - count($maintSet) - count($rentedSet),
            'rented'      => count($rentedSet),
            'maintenance' => count($maintSet),
            'office_use' => 0, 'out_of_order' => 0, 'suspended' => 0, 'returned' => 0,
            'sold' => 0, 'disposed' => 0, 'for_sale' => 0, 'other' => 0,
        ];

        // Everything the register does NOT call Active, folded by lifecycle status in ONE grouped
        // query. whereNull('deleted_at') is NOT optional here: this is a DB::table() query, so
        // Eloquent's SoftDeletes global scope does not apply, and a car retired by
        // fleet:retire-unlisted (soft-deleted precisely so it "disappears from the lists, boards
        // and counts") would otherwise keep being counted.
        $rest = DB::table('vehicles')
            ->whereNull('deleted_at')
            ->whereRaw("LOWER(TRIM(COALESCE(sheet_status, ''))) <> 'active'")
            ->selectRaw("status, LOWER(TRIM(COALESCE(sheet_status, ''))) AS reg, COUNT(*) AS c")
            ->groupBy('status', 'reg')
            ->get();

        // Buckets that describe a car OUT of service. 'ready'/'rented' are absent on purpose: for a
        // car the register does not call Active those two say nothing useful (they are exactly the
        // stale values that inflated this count), so such a car lands in `other` rather than being
        // quietly re-admitted to the active fleet through the back door.
        $lifecycle = ['office_use', 'out_of_order', 'suspended', 'returned', 'sold', 'disposed'];

        foreach ($rest as $row) {
            $n = (int) $row->c;
            if ($row->reg === 'for sale') {
                // Give the register's for-sale cars a named home instead of the `other` catch-all —
                // there are a dozen of them and "unaccounted" is the wrong word for a car we know
                // we are selling.
                $counts['for_sale'] += $n;
            } elseif (in_array((string) $row->status, $lifecycle, true)) {
                $counts[(string) $row->status] += $n;
            } else {
                $counts['other'] += $n;
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
     * Cars currently in the garage for the DASHBOARD — CONTRACT-based maintenance only (open
     * type-U contracts), matching the Fleet Split donut. This is intentionally narrower than the
     * canonical OperationsService::vehiclesInMaintenance() (which also counts app workflow tickets
     * and manual garage events and drives the /maintenance board + rental-eligibility guards).
     */
    public function carsInMaintenance(): int
    {
        return count($this->contractMaintenanceVehicleIds());
    }

    /**
     * Vehicle IDs with an OPEN type-U (maintenance) contract — the contract-derived maintenance set
     * powering the dashboard's Fleet Split donut and its cars-in-maintenance KPI. Kept separate from
     * OperationsService::vehiclesInMaintenance() on purpose so the dashboard reflects only cars the
     * OfficeManager/contract layer says are in the shop, without the ticket/manual overlays.
     *
     * Gated on a car the fleet register still lists as Active. We count from the CONTRACT, not the
     * vehicle, so without this an open type-U contract pointing at a retired (soft-deleted) car —
     * or at one the register has since marked Sold or Under process — would be reported as a car
     * sitting in our garage. The gate lives here rather than at each call site so the donut and
     * the cars-in-maintenance KPI cannot drift apart.
     *
     * @return array<int,int>
     */
    private function contractMaintenanceVehicleIds(): array
    {
        return Contract::where('contract_type', 'U')->currentlyOpen()
            ->whereNotNull('vehicle_id')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('vehicles')
                ->whereColumn('vehicles.id', 'contracts.vehicle_id')
                ->whereNull('vehicles.deleted_at')
                ->whereRaw("LOWER(TRIM(COALESCE(vehicles.sheet_status, ''))) = 'active'"))
            ->distinct()
            ->pluck('vehicle_id')
            ->map(fn ($id) => (int) $id)
            ->all();
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
            $inShop      = $this->inMaintenanceList();

            return [
                'window_days'     => $days,
                'contract_expiry' => ['count' => count($rentals),      'items' => array_slice($rentals, 0, 5)],
                // Cars physically in the workshop right now, tagged with the lifecycle stage they sit at.
                // The panel is the primary Proactive-Flags column, so it shows the FULL in-shop list
                // (inMaintenanceList already caps at 25), not a top-5 teaser.
                'in_maintenance'  => [
                    'count' => count($inShop),
                    'items' => $inShop,
                    // How many of those cars we know about from each record, so the panel can state
                    // its provenance split up front (sheet contract vs app workflow ticket vs both).
                    'sources' => [
                        'contract' => count(array_filter($inShop, fn ($r) => $r['source'] === 'contract')),
                        'workshop' => count(array_filter($inShop, fn ($r) => $r['source'] === 'workshop')),
                        'both'     => count(array_filter($inShop, fn ($r) => $r['source'] === 'both')),
                    ],
                ],
                // count/total are the TRUE totals of the whole set; items is just the top-5 shown.
                'invoice_overdue' => ['count' => $invSummary['count'], 'total' => $invSummary['total'], 'items' => $invItems],
                'inspection_due'  => ['count' => count($inspections),  'items' => array_slice($inspections, 0, 5)],
            ];
        });
    }

    /**
     * Cars physically in the workshop right now — the UNION of the two records we hold for a car
     * being in the garage, so no in-shop car can hide behind whichever system it was logged in:
     *
     *   source 'contract' ("from sheet")  — an OPEN type-U maintenance CONTRACT (OM / sheet-synced).
     *                                       In-shop clock = contract out_date; target = the ticket's
     *                                       promised ready-by date, else the fleet-default window.
     *   source 'workshop' ("from system") — an app maintenance-workflow TICKET sitting in one of the
     *                                       in-shop states, with no open contract behind it. Clock =
     *                                       the ticket's own start stamp; target = its effective
     *                                       expected-completion date (same maths the Checkpoints
     *                                       monitor uses), else the default window.
     *   source 'both'                     — the same visit exists in BOTH (a contract whose ticket is
     *                                       live in the workflow). Shown ONCE, badged as both.
     *
     * De-duplicated by vehicle, longest-overdue first so the cars blowing their window sit at the top.
     *
     * @return array<int,array{id:?int, plate:?string, car:?string, stage:string, garage:?string, source:string, eta:array}>
     */
    public function inMaintenanceList(int $limit = 25): array
    {
        $rows = $this->contractInShopRows($limit);

        // Vehicles already accounted for by an open contract — a workflow ticket for the same car is
        // the same visit seen from the other side, so it upgrades that row to 'both' instead of
        // adding a duplicate card.
        $seen = [];
        foreach ($rows as $r) {
            if ($r['id']) {
                $seen[(int) $r['id']] = true;
            }
        }

        foreach ($this->ticketInShopRows($limit, array_keys($seen)) as $r) {
            $rows[] = $r;
        }

        return collect($rows)
            // Worst-overdue first, then closest-to-due; keeps the urgent cars at the top of the panel.
            ->sortByDesc(fn ($r) => ($r['eta']['days_over'] ?? 0) * 1000 - ($r['eta']['days_left'] ?? 0))
            ->values()
            ->take($limit)
            ->all();
    }

    /**
     * Source A — cars in the shop per the OPEN type-U maintenance contracts (OM / sheet-synced).
     *
     * @return array<int,array<string,mixed>>
     */
    private function contractInShopRows(int $limit): array
    {
        $contracts = Contract::query()
            ->where('contract_type', 'U')
            ->currentlyOpen()
            ->whereNotNull('vehicle_id')
            ->with([
                'vehicle:id,plate_no,make,model',
                'maintenance:id,contract_id,garage,vendor_id,workflow_status,expected_return_date,maintenance_reason_id,maintenance_type,customer_complaint,service_main,maintenance_notes,findings',
                'maintenance.vendor:id,name',
                'maintenance.checkpoints',
                // The fault(s)/reason behind the visit — so each card can say WHY the car is in the shop.
                'maintenance.tasks:id,maintenance_id,symptom,status,severity',
                'maintenance.reason:id,reason_en',
            ])
            ->limit($limit)
            ->get(['id', 'vehicle_id', 'out_date', 'in_date']);

        // The contract header carries no fault — pull the current problem from the N-Maintenance sheet
        // event matched to THIS contract's visit window (our contract↔sheet Hard-Lock rule).
        $sheetProblems = $this->sheetProblemsForContracts($contracts);

        // Live app tickets for these same CARS, keyed by vehicle. The contract's own `maintenance`
        // relation is a contract_id join, but ticketInShopRows() excludes duplicates by VEHICLE — so a
        // ticket raised without a contract_id (the common case: the app opened the visit, the sheet
        // contract arrived separately) was dropped as a duplicate on one side and never recognised as
        // "also in the system" on the other, and the whole panel read "From system 0". Badge by the
        // same key the exclusion uses, so a car in both records is counted in both pills.
        $trackedTickets = Maintenance::query()
            ->whereIn('workflow_status', Maintenance::CHECKPOINT_TRACKED_STATES)
            ->whereIn('vehicle_id', $contracts->pluck('vehicle_id')->filter()->all())
            ->pluck('id', 'vehicle_id');

        return $contracts
            ->map(function ($c) use ($sheetProblems, $trackedTickets) {
                $m = $c->maintenance;
                // The live ticket standing behind this visit, found by contract link OR by car.
                $liveTicketId = $trackedTickets[(int) $c->vehicle_id] ?? null;
                // Prefer any fault recorded on the ticket itself; else fall back to the matched sheet fault.
                $problem = $this->ticketProblem($m);
                if (! $problem['label']) {
                    $problem = $sheetProblems[(int) $c->id] ?? $problem;
                }
                // In-shop clock anchored to when the car went in (contract out_date); target is the
                // garage's promised ready-by date when set, else the fleet-default window.
                $eta = Maintenance::etaFromDates($c->out_date, $m?->expected_return_date);

                // Did the supervisors file a checkpoint on this car from /maintenance-progress? The
                // contract's own maintenance ticket is the same record that page tracks — surface its
                // latest checkpoint inline so a card shows whether a value was inserted (and what it said)
                // rather than only the auto-computed ETA.
                $cp = $m?->checkpoints?->first();

                return [
                    'id'         => (int) $c->vehicle_id,
                    'plate'      => $c->vehicle?->plate_no,
                    'car'        => $c->vehicle ? (trim(($c->vehicle->make ?? '') . ' ' . ($c->vehicle->model ?? '')) ?: null) : null,
                    'stage'      => 'In workshop',
                    // Provenance — 'contract' (the sheet/OM record alone) or 'both' when the same visit
                    // is ALSO live as an app workflow ticket, whether that ticket names this contract
                    // or only the car.
                    'source'      => ($m && in_array($m->workflow_status, Maintenance::CHECKPOINT_TRACKED_STATES, true)) || $liveTicketId
                        ? 'both' : 'contract',
                    'contract_id' => (int) $c->id,
                    // Deep-link to the live ticket when there is one, so the card opens the visit that is
                    // actually running rather than a closed contract header.
                    'ticket_id'   => $liveTicketId ? (int) $liveTicketId : ($m?->id ? (int) $m->id : null),
                    'garage'     => $m?->vendor?->name ?: ($m?->garage ?: null),
                    // WHY the car is in the shop — the fault(s)/reason behind the visit.
                    'problem'       => $problem['label'],
                    'problem_items' => $problem['items'],
                    'problem_type'  => $problem['type'],
                    'eta'        => $eta,
                    'checkpoint' => $cp ? [
                        'status'                 => $cp->status,
                        'summary'                => $cp->summary,
                        // Both ends of the promise + the structured reason it moved, so the card can
                        // explain WHY the car is delayed (previous → new ETA, delay reason), not just
                        // print the new date next to the update date.
                        'previous_expected_date' => optional($cp->previous_expected_date)->toDateString(),
                        'next_expected_date'     => optional($cp->next_expected_date)->toDateString(),
                        'delay_reason'           => $cp->delay_reason,
                        'delay_reason_other'     => $cp->delay_reason_other,
                        'at'                     => optional($cp->created_at)->toIso8601String(),
                        'by'                     => $cp->submitted_by_name,
                    ] : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Source B — cars in the shop per the APP's own maintenance-workflow tickets: any ticket sitting
     * in an in-shop state (the same set the Checkpoints monitor tracks). Vehicles already covered by
     * an open contract row are skipped here — that row is badged 'both' instead. The ETA is computed
     * exactly as MaintenanceCheckpointService::monitorState does (start stamp → effective expected
     * completion → fleet-default window), so a ticket card and its Checkpoints row can never disagree.
     *
     * @param  array<int,int>  $excludeVehicleIds  vehicles already listed from the contract side
     * @return array<int,array<string,mixed>>
     */
    private function ticketInShopRows(int $limit, array $excludeVehicleIds): array
    {
        return Maintenance::query()
            ->whereIn('workflow_status', Maintenance::CHECKPOINT_TRACKED_STATES)
            ->whereNotNull('vehicle_id')
            ->when($excludeVehicleIds, fn ($q) => $q->whereNotIn('vehicle_id', $excludeVehicleIds))
            ->with([
                'vehicle:id,plate_no,make,model',
                'vendor:id,name',
                'checkpoints',
                'tasks:id,maintenance_id,symptom,status,severity',
                'reason:id,reason_en',
            ])
            ->limit($limit)
            ->get()
            ->map(function (Maintenance $t) {
                $start = $t->repair_started_at ?? $t->out_date ?? $t->dispatched_at ?? $t->created_at;
                $eta   = Maintenance::etaFromDates($start, $t->effectiveExpectedCompletion());
                $cp    = $t->checkpoints->first();
                $problem = $this->ticketProblem($t);

                return [
                    'id'          => (int) $t->vehicle_id,
                    'plate'       => $t->vehicle?->plate_no,
                    'car'         => $t->vehicle ? (trim(($t->vehicle->make ?? '') . ' ' . ($t->vehicle->model ?? '')) ?: null) : null,
                    'stage'       => 'In workshop',
                    // Provenance — this car is in the shop per the app's own workflow, with no open
                    // maintenance contract behind it.
                    'source'      => 'workshop',
                    'contract_id' => $t->contract_id ? (int) $t->contract_id : null,
                    'ticket_id'   => (int) $t->id,
                    'garage'      => $t->vendor?->name ?: ($t->garage ?: null),
                    'problem'       => $problem['label'],
                    'problem_items' => $problem['items'],
                    'problem_type'  => $problem['type'],
                    'eta'         => $eta,
                    'checkpoint'  => $cp ? [
                        'status'                 => $cp->status,
                        'summary'                => $cp->summary,
                        'previous_expected_date' => optional($cp->previous_expected_date)->toDateString(),
                        'next_expected_date'     => optional($cp->next_expected_date)->toDateString(),
                        'delay_reason'           => $cp->delay_reason,
                        'delay_reason_other'     => $cp->delay_reason_other,
                        'at'                     => optional($cp->created_at)->toIso8601String(),
                        'by'                     => $cp->submitted_by_name,
                    ] : null,
                ];
            })
            // One card per CAR, not per ticket: a car carrying several open tickets shows its
            // longest-running one and counts the rest, so the board stays "one car, one card"
            // without hiding that more work is open on it.
            ->sortByDesc(fn ($r) => $r['eta']['days_elapsed'] ?? 0)
            ->groupBy('id')
            ->map(function ($group) {
                $row = $group->first();
                $row['other_tickets'] = $group->count() - 1;

                return $row;
            })
            ->values()
            ->all();
    }

    /**
     * Maintenance Progress — the operational monitoring centre for every car currently in the workshop.
     * One row per active in-shop ticket carrying its garage, live ETA, the last checkpoint (who/when/
     * note), the responsible follow-up owner(s), and a single mutually-exclusive PROGRESS status the UI
     * colours by. The status is DERIVED (never a manual verdict) — precedence, worst first:
     *   overdue          — today is past the promised completion date (red)
     *   needs_update     — inside the reminder window with no fresh update yet (amber, "Checkpoint due")
     *   on_schedule      — today is on/before the promised date (green)
     *   ready_for_pickup — the car sits at the Ready-for-Pickup workflow stage (essentially done)
     * Sorted worst-first. Cached like every dashboard aggregate; flushed on the next sync.
     *
     * @return array{summary:array<string,int>, items:array<int,array<string,mixed>>}
     */
    public function maintenanceProgress(int $limit = 100): array
    {
        return $this->remember("maint_progress:{$limit}", self::CACHE_TTL, function () use ($limit) {
            $tickets = Maintenance::query()
                ->whereIn('workflow_status', Maintenance::CHECKPOINT_TRACKED_STATES)
                ->with([
                    'vehicle:id,plate_no,make,model', 'vendor:id,name', 'checkpoints', 'responsibles:id,name',
                    // The fault(s) behind the visit — so each row can say WHY the car is in the shop.
                    'tasks:id,maintenance_id,symptom,status,severity', 'reason:id,reason_en',
                ])
                ->limit($limit)
                ->get();

            // Default follow-up owners resolved once (cached recipient query), used for tickets that
            // never assigned explicit responsibles.
            $defaults = $this->checkpoints->defaultRecipients()
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values()->all();

            // Source A — the app workflow tickets (origin = the maintenance workflow engine). Tagged
            // 'workshop' so the UI can badge where a row came from.
            $workshop = $tickets->map(fn ($t) => $this->progressRow(
                ticketId:    (int) $t->id,
                vehicleId:   $t->vehicle_id ? (int) $t->vehicle_id : null,
                vehicle:     $t->vehicle,
                garage:      $t->vendor?->name ?: ($t->garage ?: null),
                workflow:    $t->workflow_status,
                state:       $this->checkpoints->monitorState($t),
                latest:      $t->checkpoints->first(),
                responsible: $t->responsibles->isNotEmpty()
                    ? $t->responsibles->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values()->all()
                    : $defaults,
                source:      'workshop',
                contractId:  $t->contract_id ? (int) $t->contract_id : null,
                problem:     $this->ticketProblem($t),
            ));

            // Source B — the open type-U maintenance CONTRACTS (OM / sheet-synced), the same set the
            // Proactive-Flags "In Maintenance" panel shows. Merged in here (tagged 'contract') so every
            // car that is physically in the shop appears in the queue, whichever record we hold for it.
            $contract = collect($this->contractProgressRows($defaults));

            // Union, then rank worst-first (overdue to the top, then unattended, then on-schedule, with the
            // ready-for-pickup cars — essentially done — at the tail).
            $items = $workshop->concat($contract)
                ->sortByDesc(function ($r) {
                    $rank = ['overdue' => 4, 'needs_update' => 3, 'on_schedule' => 2, 'ready_for_pickup' => 1];
                    return ($rank[$r['status']] ?? 0) * 100000
                        + ($r['days_over'] ?? 0) * 100
                        - ($r['days_left'] ?? 0);
                })
                ->values()->all();

            $count = fn (string $s) => count(array_filter($items, fn ($r) => $r['status'] === $s));
            $bySource = fn (string $s) => count(array_filter($items, fn ($r) => $r['source'] === $s));

            return [
                'summary' => [
                    'total'            => count($items),
                    'on_schedule'      => $count('on_schedule'),
                    'needs_update'     => $count('needs_update'),
                    'overdue'          => $count('overdue'),
                    'ready_for_pickup' => $count('ready_for_pickup'),
                    // How many rows came from each source (contract cars vs app workflow tickets).
                    'contract'         => $bySource('contract'),
                    'workshop'         => $bySource('workshop'),
                ],
                'items' => $items,
            ];
        });
    }

    /**
     * Normalise one Maintenance-Progress row from an already-resolved monitor state, so the workflow-ticket
     * and contract branches emit the IDENTICAL shape (the only difference is `source` + `contract_id`, and
     * a contract car that has no ticket yet carries a null `ticket_id` — the UI lazily creates one when the
     * first checkpoint is filed via /maintenance-tickets/contract/{contract}/ensure-ticket).
     *
     * @param  array<string,mixed>  $state         monitorState() output
     * @param  array<int,array>     $responsible
     * @return array<string,mixed>
     */
    private function progressRow(
        ?int $ticketId,
        ?int $vehicleId,
        $vehicle,
        ?string $garage,
        ?string $workflow,
        array $state,
        $latest,
        array $responsible,
        string $source,
        ?int $contractId,
        array $problem = ['label' => null, 'items' => [], 'type' => null],
    ): array {
        // Single mutually-exclusive status, DERIVED (never a manual verdict): a car parked at the
        // Ready-for-Pickup stage reads as such; otherwise it's Overdue when today is past the promised
        // date, "Checkpoint due" when an update is owed in the current window, else On Schedule.
        $status = $workflow === Maintenance::WF_READY_FOR_PICKUP ? 'ready_for_pickup'
            : ($state['overdue'] ? 'overdue'
            : ($state['needs_update'] ? 'needs_update' : 'on_schedule'));

        return [
            'ticket_id'          => $ticketId,
            'vehicle_id'         => $vehicleId,
            'plate'              => $vehicle?->plate_no,
            'car'                => $vehicle ? (trim(($vehicle->make ?? '') . ' ' . ($vehicle->model ?? '')) ?: null) : null,
            'garage'             => $garage,
            'workflow_status'    => $workflow,
            'status'             => $status,
            'eta_status'         => $state['eta_status'],
            'expected_on'        => $state['expected_on'],
            'is_estimated'       => $state['is_estimated'],
            'days_left'          => $state['days_left'],
            'days_over'          => $state['days_over'],
            // WHY the car is in the shop — the fault(s)/reason behind the visit (headline + full list).
            'problem'            => $problem['label'],
            'problem_items'      => $problem['items'],
            'problem_type'       => $problem['type'],
            'has_checkpoint'     => $state['has_checkpoint'],
            'last_checkpoint_at' => $state['last_checkpoint_at'],
            'last_checkpoint'    => $latest ? [
                'status'                 => $latest->status,
                'delay_reason'           => $latest->delay_reason,
                'delay_reason_other'     => $latest->delay_reason_other,
                'summary'                => $latest->summary,
                'previous_expected_date' => optional($latest->previous_expected_date)->toDateString(),
                'next_expected_date'     => optional($latest->next_expected_date)->toDateString(),
                'at'                     => optional($latest->created_at)->toIso8601String(),
                'by'                     => $latest->submitted_by_name,
            ] : null,
            'needs_update'       => $state['needs_update'],
            'overdue'            => $state['overdue'],
            'responsible'        => $responsible,
            // Provenance so the UI can badge each row: 'contract' (open type-U contract, the OM/sheet
            // source of truth) vs 'workshop' (an app maintenance-workflow ticket).
            'source'             => $source,
            'contract_id'        => $contractId,
        ];
    }

    /**
     * WHY is this car in the shop? Distil the fault(s)/reason behind a maintenance ticket (or contract
     * header) into a short headline + the full list (for a hover tooltip) + the ticket-type label. Reads
     * whichever source we actually hold, most-specific first:
     *   1) the app's structured active faults (maintenance_tasks.symptom, dropping cancelled/not-found);
     *   2) the inspector's findings snapshot (findings JSON);
     *   3) the customer complaint / classified reason / free-text service (sheet + contract headers).
     * A ticket with none of these still shows its type ("Routine Maintenance") so no row reads blank.
     *
     * @return array{label:?string, items:array<int,string>, type:?string}
     */
    private function ticketProblem(?Maintenance $m): array
    {
        $empty = ['label' => null, 'items' => [], 'type' => null];
        if (! $m) {
            return $empty;
        }

        $typeLabel = $m->maintenance_type
            ? (Maintenance::MAINTENANCE_TYPES[$m->maintenance_type] ?? null)
            : null;

        // 1) Structured active faults — the app's fault list (drop cancelled / not-found non-issues).
        $items = [];
        if ($m->relationLoaded('tasks')) {
            $items = $m->tasks
                ->reject(fn ($t) => in_array($t->status, MaintenanceTask::NON_REPAIR_TERMINAL, true))
                ->map(fn ($t) => trim((string) $t->symptom))
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        // 2) Inspector findings snapshot (JSON list of keyword/label entries) — fallback for a ticket with
        //    no structured task rows yet.
        if (! $items && is_array($m->findings)) {
            $items = collect($m->findings)
                ->map(fn ($f) => is_array($f) ? ($f['label'] ?? $f['keyword'] ?? $f['symptom'] ?? null) : $f)
                ->map(fn ($f) => trim((string) $f))
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        // 3) Free-text fallbacks — customer complaint, the classified sheet reason, or the service text.
        if (! $items) {
            $fallback = $m->customer_complaint
                ?: ($m->relationLoaded('reason') ? $m->reason?->reason_en : null)
                ?: $m->service_main
                ?: $m->maintenance_notes;
            if ($fallback) {
                $items = [trim((string) $fallback)];
            }
        }

        // Headline = first fault (+N more), else the ticket-type label so no row is blank.
        $label = $items[0] ?? $typeLabel;
        if ($label !== null && count($items) > 1) {
            $label .= ' +' . (count($items) - 1);
        }

        return ['label' => $label, 'items' => $items, 'type' => $typeLabel];
    }

    /**
     * Fault-from-the-sheet fallback. An OM type-U maintenance CONTRACT carries no fault text of its own,
     * but the N-Maintenance workshop log does: the fault the car actually went in for lives on the
     * sheet/manual `maintenances` rows (reason category + service_main/service_sup issue keywords).
     *
     * This resolves the CURRENT problem for each contract using our EXISTING contract↔sheet matching rule
     * — MaintenanceAnalyticsService::linkedSheetEvents(), the "Hard Lock" windowed by the contract's own
     * lifespan (out_date − buffer … in_date cutoff) — so a card can never borrow a fault from a different
     * visit. Within a contract's linked sequence the LATEST issue-bearing event is the current stage; its
     * issue tags (sheetIssueTags) + classified reason map onto the standard problem shape. Keyed by
     * CONTRACT id. Contracts with no matching sheet event are simply absent (the caller shows "—").
     *
     * @param  \Illuminate\Support\Collection<int,\App\Models\Contract>  $contracts  need id, vehicle_id, out_date, in_date
     * @return array<int,array{label:?string, items:array<int,string>, type:?string}>
     */
    private function sheetProblemsForContracts($contracts): array
    {
        // [contract id => chronological Collection<Maintenance> of the sheet events inside its window].
        $linked = $this->analytics->linkedSheetEvents($contracts);

        $out = [];
        foreach ($linked as $contractId => $sequence) {
            // Current stage is the newest event; walk newest→oldest to the latest one that actually carries
            // an issue, so a bare OUT/IN handover row doesn't blank a car that has a real fault behind it.
            $event = $sequence->reverse()->first(
                fn ($e) => ! empty($this->analytics->sheetIssueTags($e)) || $e->reason?->reason_en
            );
            if (! $event) {
                continue;
            }

            $items = $this->analytics->sheetIssueTags($event);  // service_main + service_sup keywords
            $type  = $event->reason?->reason_en ?: null;         // controlled reason (e.g. "Mechanical Issues")
            $label = $items[0] ?? $type;
            if ($label !== null && count($items) > 1) {
                $label .= ' +' . (count($items) - 1);
            }

            $out[(int) $contractId] = ['label' => $label, 'items' => $items, 'type' => $type];
        }

        return $out;
    }

    /**
     * Maintenance-Progress rows for the open type-U maintenance CONTRACTS — every car physically in the
     * shop per OM/sheet, the same set inMaintenanceList() (Proactive Flags) shows. Each reuses the exact
     * checkpoint monitor logic: when the contract already has a linked maintenance ticket we read it
     * directly (so its filed checkpoints + responsibles surface); otherwise we compute the live ETA from a
     * transient ticket seeded with the contract's in-shop start, and leave `ticket_id` null until the first
     * checkpoint is filed.
     *
     * @param  array<int,array>  $defaults  fallback follow-up owners
     * @return array<int,array<string,mixed>>
     */
    private function contractProgressRows(array $defaults): array
    {
        $contracts = Contract::query()
            ->where('contract_type', 'U')
            ->currentlyOpen()
            ->whereNotNull('vehicle_id')
            ->with([
                'vehicle:id,plate_no,make,model',
                'maintenance:id,contract_id,vehicle_id,garage,vendor_id,workflow_status,expected_return_date,expected_completion_date,expected_duration_days,last_checkpoint_at,out_date,repair_started_at,dispatched_at,maintenance_reason_id,maintenance_type,customer_complaint,service_main,maintenance_notes,findings',
                'maintenance.vendor:id,name',
                'maintenance.checkpoints',
                'maintenance.responsibles:id,name',
                // The fault(s)/reason behind the visit — so a contract row can also show WHY.
                'maintenance.tasks:id,maintenance_id,symptom,status,severity',
                'maintenance.reason:id,reason_en',
            ])
            ->limit(50)
            ->get(['id', 'vehicle_id', 'out_date', 'in_date']);

        // Fault-from-the-sheet fallback, matched to each contract's own visit window (Hard-Lock rule).
        $sheetProblems = $this->sheetProblemsForContracts($contracts);

        return $contracts
            ->map(function ($c) use ($defaults, $sheetProblems) {
                $m = $c->maintenance;

                if ($m) {
                    // A real ticket exists for this contract — read its live state + last checkpoint.
                    $state       = $this->checkpoints->monitorState($m);
                    $latest      = $m->checkpoints->first();
                    $garage      = $m->vendor?->name ?: ($m->garage ?: null);
                    $responsible = $m->responsibles->isNotEmpty()
                        ? $m->responsibles->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values()->all()
                        : $defaults;
                    $workflow    = $m->workflow_status;
                    $ticketId    = (int) $m->id;
                } else {
                    // No ticket yet — compute the ETA/monitor state from a transient ticket anchored to the
                    // contract's in-shop start (out_date). No row is written until a checkpoint is filed.
                    $probe = new Maintenance(['out_date' => $c->out_date]);
                    $probe->setRelation('checkpoints', collect());
                    $state       = $this->checkpoints->monitorState($probe);
                    $latest      = null;
                    $garage      = null;
                    $responsible = $defaults;
                    $workflow    = null;
                    $ticketId    = null;
                }

                // Prefer any fault on the ticket; else fall back to the matched sheet fault.
                $problem = $this->ticketProblem($m);
                if (! $problem['label']) {
                    $problem = $sheetProblems[(int) $c->id] ?? $problem;
                }

                return $this->progressRow(
                    ticketId:    $ticketId,
                    vehicleId:   (int) $c->vehicle_id,
                    vehicle:     $c->vehicle,
                    garage:      $garage,
                    workflow:    $workflow,
                    state:       $state,
                    latest:      $latest,
                    responsible: $responsible,
                    source:      'contract',
                    contractId:  (int) $c->id,
                    problem:     $problem,
                );
            })
            ->all();
    }

    /**
     * The cars that have been in the workshop the MOST over a trailing window — ranked by number of
     * distinct workshop visits (vehicle|out_date, the same visit definition Workshop Visits / trends /
     * uncostedRepairs use). Powers the "Most in Maintenance" proactive-flags column, whose date filter
     * drives $days. Returns the fleet-wide car count for the window plus the top-N repeat offenders.
     *
     * @return array{count:int, window_days:int, items:array<int,array{id:int, plate:?string, car:?string, visits:int, last_visit:?string}>}
     */
    public function mostMaintained(int $days = 90, int $limit = 5): array
    {
        return $this->remember("most_maintained:{$days}:{$limit}", self::CACHE_TTL, function () use ($days, $limit) {
            $cutoff = Carbon::today()->subDays($days)->toDateString();

            // A "visit" is one (vehicle, out_date) group of the live workshop log (sheet + manual) with a
            // non-null out_date — the IDENTICAL definition maintenanceTrends() and uncostedRepairs() use,
            // so a car's count here always reconciles with the Workshop Visits metric. The INNER join to
            // vehicles (+ soft-delete guard + GONE_STATUSES exclusion) means a sold / disposed / returned
            // or soft-deleted car — and any orphaned maintenance row — can never rank.
            $base = fn () => DB::table('maintenances as m')
                ->join('vehicles as v', 'v.id', '=', 'm.vehicle_id')
                ->whereNull('v.deleted_at')
                ->whereNotIn('v.status', PlateResolver::GONE_STATUSES)
                ->whereIn('m.origin', Maintenance::WORKSHOP_LOG_ORIGINS)
                ->whereNotNull('m.out_date')
                ->whereDate('m.out_date', '>=', $cutoff);

            // How many distinct (in-fleet) cars saw the shop in the window (the badge total).
            $carCount = (int) $base()->distinct()->count('m.vehicle_id');

            // The top repeat-visitors: most distinct visit-days first, then most-recently-in as a tiebreak.
            $rows = $base()
                ->select(
                    'v.id AS vehicle_id', 'v.plate_no', 'v.make', 'v.model',
                    DB::raw('COUNT(DISTINCT m.out_date) AS visits'),
                    DB::raw('MAX(m.out_date) AS last_visit')
                )
                ->groupBy('v.id', 'v.plate_no', 'v.make', 'v.model')
                ->orderByDesc('visits')
                ->orderByDesc(DB::raw('MAX(m.out_date)'))
                ->limit($limit)
                ->get();

            $items = $rows->map(fn ($r) => [
                'id'         => (int) $r->vehicle_id,
                'plate'      => $r->plate_no,
                'car'        => trim(($r->make ?? '') . ' ' . ($r->model ?? '')) ?: null,
                'visits'     => (int) $r->visits,
                'last_visit' => $r->last_visit,
            ])->all();

            return ['count' => $carCount, 'window_days' => $days, 'items' => $items];
        });
    }

    /**
     * "Most Maintained Models" — which car TYPES (make + model) go to the workshop most, by ALL-TIME
     * ticket volume. A "ticket" is one maintenance row carrying a workflow lifecycle state (the app-driven
     * Fleet Maintenance Workflow — Maintenance::scopeWorkflowTickets), NOT a raw sheet/manual log row, so
     * this counts real tickets the team opened. Grouped by (make, model) and ranked by ticket count. The
     * INNER join to vehicles (+ soft-delete guard + GONE_STATUSES exclusion) keeps sold / disposed /
     * soft-deleted cars and orphan rows out of the ranking.
     *
     * @return array{count:int, items:array<int,array{model:string, tickets:int, cars:int}>}
     */
    public function mostMaintainedModels(int $limit = 8): array
    {
        return $this->remember("most_maintained_models:{$limit}", self::CACHE_TTL, function () use ($limit) {
            $rows = DB::table('maintenances as m')
                ->join('vehicles as v', 'v.id', '=', 'm.vehicle_id')
                ->whereNull('v.deleted_at')
                ->whereNotIn('v.status', PlateResolver::GONE_STATUSES)
                ->whereNotNull('m.workflow_status')
                ->select(
                    'v.make', 'v.model',
                    DB::raw('COUNT(*) AS tickets'),
                    DB::raw('COUNT(DISTINCT m.vehicle_id) AS cars')
                )
                ->groupBy('v.make', 'v.model')
                ->orderByDesc('tickets')
                ->orderByDesc(DB::raw('COUNT(DISTINCT m.vehicle_id)'))
                ->limit($limit)
                ->get();

            $items = $rows->map(fn ($r) => [
                'model'   => trim(($r->make ?? '') . ' ' . ($r->model ?? '')) ?: 'Unknown',
                'tickets' => (int) $r->tickets,
                'cars'    => (int) $r->cars,
            ])->all();

            return ['count' => count($items), 'items' => $items];
        });
    }

    /**
     * Canonical fault taxonomy for the Fault Leaderboard — the ONE shared vocabulary both fault sources
     * (the workshop SHEET's reason categories and OUR SYSTEM's task symptoms) are folded into, so the two
     * can be counted together fairly instead of sitting side-by-side in two different languages ("Braking
     * Problems" from the sheet + "Brake Failure" from a ticket both land in Brakes). First regex to match
     * the lower-cased fault text wins, so ORDER MATTERS — more specific buckets come before broader ones
     * (Safety before Body so "seatbelt" beats "seat"; Cooling before Oil so "oil and coolant" reads as a
     * cooling fault). Unmatched text falls through to "Other".
     *
     * @var array<int,array{0:string,1:string}>  [label, regex]
     */
    private const FAULT_CATEGORIES = [
        ['Brakes',                '/brak|pedal/'],
        ['Cooling & Overheating', '/overheat|coolant|cooling|radiator/'],
        ['Engine',                '/engine|mechanical|misfire|idle|stall|timing|piston|exhaust smoke/'],
        ['Electrical',            '/electric|ignition|battery|alternator|wiring|starter|check engine/'],
        ['Suspension & Steering', '/suspension|steering|alignment|knock|bump|shock|strut|vibration/'],
        ['Transmission',          '/transmission|gearbox|clutch/'],
        ['Safety',                '/airbag|seatbelt|seat belt/'],
        ['AC & Climate',          '/\bac\b|air.?con|climate/'],
        ['Tires & Wheels',        '/tire|tyre|wheel/'],
        ['Exhaust & Emissions',   '/exhaust|emission/'],
        ['Fuel System',           '/fuel|injector/'],
        ['Body & Interior',       '/body|interior|chair|seat|accessor|paint|dent|scratch|door|glass|window/'],
        ['Oil & Fluids',          '/oil|fluid|leak|filter/'],
    ];

    /** Map any raw fault text to its canonical category label (first-match-wins, else "Other"). */
    private static function faultCategory(?string $text): string
    {
        $t = strtolower(trim((string) $text));
        foreach (self::FAULT_CATEGORIES as [$label, $re]) {
            if ($t !== '' && preg_match($re, $t)) {
                return $label;
            }
        }
        return 'Other';
    }

    /** Unify the sheet's `level` (minor/critical/routine) and a ticket's `severity` onto one 0–4 rank. */
    private static function severityRank(?string $sev): int
    {
        return [
            'critical' => 4,
            'high'     => 3,
            'moderate' => 2,
            'minor'    => 2,   // the sheet's "minor" ≈ moderate on the ticket scale
            'routine'  => 1,
        ][strtolower((string) $sev)] ?? 0;
    }

    /** The canonical severity string for a rank (what the UI colours the bar by). */
    private static function severityForRank(int $rank): ?string
    {
        return [4 => 'critical', 3 => 'high', 2 => 'moderate', 1 => 'routine'][$rank] ?? null;
    }

    /**
     * "Most Frequent Faults" — the Fault Leaderboard KPI, built from BOTH fault sources at once:
     *
     *   • OUR SYSTEM — every fault logged in the app's maintenance workflow (`maintenance_tasks.symptom`,
     *     graded with `severity`). Cancelled / not-found tasks are dropped: a fault the workshop checked
     *     and could not reproduce never really "happened".
     *   • THE SHEET  — the historical N-Maintenance / manual workshop log (`maintenances` rows whose origin
     *     is a workshop-log origin) classified to a reason (`maintenance_reasons.reason_en` + `level`).
     *
     * Both are normalised into ONE shared taxonomy (self::FAULT_CATEGORIES) so "Braking Problems" (sheet)
     * and "Brake Failure" (ticket) count together as Brakes. Each category carries the combined count, the
     * per-source split (so you can see how much came from the sheet vs the system), its WORST severity
     * across both sources (bar colour), and the distinct cars it hit. The INNER join to vehicles (+ soft-
     * delete guard + GONE_STATUSES exclusion) keeps sold / disposed / orphan cars out of both feeds.
     *
     * @return array{count:int, total:int, sheet_total:int, system_total:int,
     *               items:array<int,array{fault:string, count:int, sheet:int, system:int, cars:int, severity:?string}>}
     */
    public function topFaults(int $limit = 6): array
    {
        return $this->remember("top_faults:v2:{$limit}", self::CACHE_TTL, function () use ($limit) {
            // key => running tally for one canonical category.
            $cats = [];
            $bump = function (string $label, int $count, int $sevRank, string $source, array $vehicleIds) use (&$cats) {
                $c = $cats[$label] ?? ['fault' => $label, 'count' => 0, 'sheet' => 0, 'system' => 0, 'sevRank' => 0, 'cars' => []];
                $c['count']  += $count;
                $c[$source]  += $count;
                $c['sevRank'] = max($c['sevRank'], $sevRank);
                foreach ($vehicleIds as $vid) {
                    $c['cars'][$vid] = true;   // set → distinct cars across BOTH sources
                }
                $cats[$label] = $c;
            };

            // ── Source A: OUR SYSTEM (maintenance workflow tasks) ───────────────────────────────────────
            $sysRows = DB::table('maintenance_tasks as t')
                ->join('vehicles as v', 'v.id', '=', 't.vehicle_id')
                ->whereNull('v.deleted_at')
                ->whereNotIn('v.status', PlateResolver::GONE_STATUSES)
                ->whereNotIn('t.status', MaintenanceTask::NON_REPAIR_TERMINAL)
                // Event Type layer: once enforced, planned services stop being counted as faults.
                ->whereIn('t.kind', MaintenanceTask::reliabilityKindsForMode())
                ->whereNotNull('t.symptom')->where('t.symptom', '<>', '')
                ->select('t.symptom', 't.severity', 't.vehicle_id', DB::raw('COUNT(*) AS c'))
                ->groupBy('t.symptom', 't.severity', 't.vehicle_id')
                ->get();
            foreach ($sysRows as $r) {
                $bump(self::faultCategory($r->symptom), (int) $r->c, self::severityRank($r->severity), 'system', [(int) $r->vehicle_id]);
            }

            // ── Source B: THE SHEET + manual workshop log (classified by reason) ────────────────────────
            $sheetRows = DB::table('maintenances as m')
                ->join('vehicles as v', 'v.id', '=', 'm.vehicle_id')
                ->join('maintenance_reasons as r', 'r.id', '=', 'm.maintenance_reason_id')
                ->whereNull('v.deleted_at')
                ->whereNotIn('v.status', PlateResolver::GONE_STATUSES)
                ->whereIn('m.origin', Maintenance::WORKSHOP_LOG_ORIGINS)
                // FAULTS ONLY — the sheet half of this chart counted every reason, so `Periodic
                // Maintenance`, `Oil & Fillter Change`, `Cleaning` and `Testing` were ranked as fleet
                // faults alongside real ones (86 of 2,813 reason-classified rows). Source A above is
                // gated on `kind`; this is the same gate at the sheet's grain (audit H2).
                ->whereIn('m.maintenance_reason_id', app(\App\Services\EventClassificationService::class)->faultReasonIds())
                ->select('r.reason_en', 'r.level', 'm.vehicle_id', DB::raw('COUNT(*) AS c'))
                ->groupBy('r.reason_en', 'r.level', 'm.vehicle_id')
                ->get();
            foreach ($sheetRows as $r) {
                $bump(self::faultCategory($r->reason_en), (int) $r->c, self::severityRank($r->level), 'sheet', [(int) $r->vehicle_id]);
            }

            // Rank categories by combined frequency; break ties by severity then name.
            $ranked = collect($cats)->sort(function ($a, $b) {
                return [$b['count'], $b['sevRank'], $a['fault']] <=> [$a['count'], $a['sevRank'], $b['fault']];
            })->values();

            $sheetTotal  = (int) $ranked->sum('sheet');
            $systemTotal = (int) $ranked->sum('system');

            $items = $ranked->take($limit)->map(fn ($c) => [
                'fault'    => $c['fault'],
                'count'    => (int) $c['count'],
                'sheet'    => (int) $c['sheet'],
                'system'   => (int) $c['system'],
                'cars'     => count($c['cars']),
                'severity' => self::severityForRank((int) $c['sevRank']),
            ])->all();

            return [
                'count'        => count($items),
                'total'        => $sheetTotal + $systemTotal,
                'sheet_total'  => $sheetTotal,
                'system_total' => $systemTotal,
                'items'        => $items,
            ];
        });
    }

    /**
     * Drill-down for ONE fault category on the Fault Leaderboard: "which cars fixed this fault the most".
     *
     * Given a canonical category label (e.g. "Brakes"), returns the vehicles ranked by how many times
     * that fault was recorded against them, combining the SAME two sources topFaults() folds together —
     * our maintenance system (`maintenance_tasks.symptom`) and the historical workshop sheet
     * (`maintenances` → `maintenance_reasons.reason_en`). Each car carries its combined count, the
     * per-source split, its worst severity for this fault, and when it was first/last seen. Sold /
     * disposed / orphan cars are excluded exactly as on the leaderboard, so the drill-down reconciles
     * with the parent bar's `cars` figure.
     *
     * @return array{fault:string, total:int, cars:int, items:array<int,array<string,mixed>>}
     */
    public function faultCars(string $fault, int $limit = 12): array
    {
        // Normalise the requested label so "brakes" / "Brakes" both resolve; unknown labels → empty.
        $target = null;
        foreach (array_merge(array_column(self::FAULT_CATEGORIES, 0), ['Other']) as $label) {
            if (strcasecmp($label, trim($fault)) === 0) {
                $target = $label;
                break;
            }
        }
        if ($target === null) {
            return ['fault' => trim($fault), 'total' => 0, 'cars' => 0, 'items' => []];
        }

        return $this->remember("fault_cars:v1:{$target}:{$limit}", self::CACHE_TTL, function () use ($target, $limit) {
            // vehicle_id => running tally for the requested category only.
            $cars = [];
            $bump = function (int $vid, ?string $plate, ?string $make, ?string $model, int $count, int $sevRank, string $source, ?string $when) use (&$cars) {
                $c = $cars[$vid] ?? [
                    'id' => $vid, 'plate' => $plate, 'make' => $make, 'model' => $model,
                    'count' => 0, 'sheet' => 0, 'system' => 0, 'sevRank' => 0, 'first' => null, 'last' => null,
                ];
                $c['count']   += $count;
                $c[$source]   += $count;
                $c['sevRank']  = max($c['sevRank'], $sevRank);
                if ($when) {
                    $c['first'] = $c['first'] === null ? $when : min($c['first'], $when);
                    $c['last']  = $c['last'] === null ? $when : max($c['last'], $when);
                }
                $cars[$vid] = $c;
            };

            // ── Source A: OUR SYSTEM (maintenance workflow tasks) ───────────────────────────────────
            DB::table('maintenance_tasks as t')
                ->join('vehicles as v', 'v.id', '=', 't.vehicle_id')
                ->whereNull('v.deleted_at')
                ->whereNotIn('v.status', PlateResolver::GONE_STATUSES)
                ->whereNotIn('t.status', MaintenanceTask::NON_REPAIR_TERMINAL)
                ->whereIn('t.kind', MaintenanceTask::reliabilityKindsForMode())
                ->whereNotNull('t.symptom')->where('t.symptom', '<>', '')
                ->select('t.symptom', 't.severity', 't.vehicle_id', 'v.plate_no', 'v.make', 'v.model', DB::raw('MAX(t.created_at) AS last_at'), DB::raw('COUNT(*) AS c'))
                ->groupBy('t.symptom', 't.severity', 't.vehicle_id', 'v.plate_no', 'v.make', 'v.model')
                ->get()
                ->each(function ($r) use ($target, $bump) {
                    if (self::faultCategory($r->symptom) !== $target) {
                        return;
                    }
                    $bump((int) $r->vehicle_id, $r->plate_no, $r->make, $r->model, (int) $r->c, self::severityRank($r->severity), 'system', $r->last_at ? (string) $r->last_at : null);
                });

            // ── Source B: THE SHEET + manual workshop log (classified by reason) ─────────────────────
            DB::table('maintenances as m')
                ->join('vehicles as v', 'v.id', '=', 'm.vehicle_id')
                ->join('maintenance_reasons as r', 'r.id', '=', 'm.maintenance_reason_id')
                ->whereNull('v.deleted_at')
                ->whereNotIn('v.status', PlateResolver::GONE_STATUSES)
                ->whereIn('m.origin', Maintenance::WORKSHOP_LOG_ORIGINS)
                // Same fault-only gate as topFaults' sheet source — the drill-down must list the same
                // visits the chart counted (audit H2).
                ->whereIn('m.maintenance_reason_id', app(\App\Services\EventClassificationService::class)->faultReasonIds())
                ->select('r.reason_en', 'r.level', 'm.vehicle_id', 'v.plate_no', 'v.make', 'v.model', DB::raw('MAX(m.out_date) AS last_at'), DB::raw('COUNT(*) AS c'))
                ->groupBy('r.reason_en', 'r.level', 'm.vehicle_id', 'v.plate_no', 'v.make', 'v.model')
                ->get()
                ->each(function ($r) use ($target, $bump) {
                    if (self::faultCategory($r->reason_en) !== $target) {
                        return;
                    }
                    $bump((int) $r->vehicle_id, $r->plate_no, $r->make, $r->model, (int) $r->c, self::severityRank($r->level), 'sheet', $r->last_at ? (string) $r->last_at : null);
                });

            $ranked = collect($cars)->sort(function ($a, $b) {
                return [$b['count'], $b['sevRank'], $a['id']] <=> [$a['count'], $a['sevRank'], $b['id']];
            })->values();

            $items = $ranked->take($limit)->map(fn ($c) => [
                'id'       => (int) $c['id'],
                'plate'    => $c['plate'],
                'car'      => trim(($c['make'] ?? '') . ' ' . ($c['model'] ?? '')) ?: null,
                'count'    => (int) $c['count'],
                'sheet'    => (int) $c['sheet'],
                'system'   => (int) $c['system'],
                'severity' => self::severityForRank((int) $c['sevRank']),
                'first'    => $c['first'] ? substr((string) $c['first'], 0, 10) : null,
                'last'     => $c['last'] ? substr((string) $c['last'], 0, 10) : null,
            ])->all();

            return [
                'fault' => $target,
                'total' => (int) $ranked->sum('count'),
                'cars'  => $ranked->count(),
                'items' => $items,
            ];
        });
    }

    /**
     * The full "Most in Maintenance" list — EVERY in-fleet car that saw the workshop within the window,
     * with how OFTEN (visits) and how LONG (days in shop). BOTH figures come from the ONE canonical
     * source — FleetUtilizationService via canonicalMaintenanceDays() — so every row is byte-identical
     * to what Fleet Utilization and the Most Maintained leaderboard show for the same window: visits =
     * type-U maintenance visits (Rental is King, in-service), days = true off-road shop days. Unlike
     * mostMaintained this returns the whole list (no top-N cap) to back the "All →" Maintenance History
     * page. first/last-visit DATES are the only thing read from the workshop log — pure timeline
     * metadata, not a count.
     *
     * $days = 0 means all-time (no trailing window) — every day the car has ever spent in the shop.
     *
     * @return array{count:int, window_days:int, items:array<int,array<string,mixed>>}
     */
    public function maintenanceHistory(int $days = 90, ?string $from = null, ?string $to = null): array
    {
        // An explicit from/to date range (either bound) overrides the trailing `days` window.
        $explicit = $from !== null || $to !== null;

        return $this->remember("maint_history:{$days}:" . ($from ?? '') . ':' . ($to ?? ''), self::CACHE_TTL, function () use ($days, $from, $to, $explicit) {
            // No explicit range and days=0 → all-time (no window); otherwise trailing `days` from today.
            $allTime = ! $explicit && $days <= 0;

            // Resolve the effective [winFrom, winTo] window. Explicit bounds win; else trailing window.
            $winFrom = $explicit ? $from : ($allTime ? null : Carbon::today()->subDays($days)->toDateString());
            $winTo   = $explicit ? $to   : null;

            // SINGLE SOURCE OF TRUTH for BOTH the visit count and the day count — the same Rental-is-King
            // calculation Fleet Utilization uses, over the same window (null,null = all-time). Keyed by car.
            $canonical = $this->canonicalMaintenanceDays($winFrom, $winTo);

            // First/last workshop DATES (sheet + manual log) — timeline metadata only, attached per car.
            $dates = DB::table('maintenances as m')
                ->whereIn('m.origin', Maintenance::WORKSHOP_LOG_ORIGINS)
                ->whereNotNull('m.out_date')
                ->when($winFrom !== null, fn ($q) => $q->whereDate('m.out_date', '>=', $winFrom))
                ->when($winTo !== null, fn ($q) => $q->whereDate('m.out_date', '<=', $winTo))
                ->whereIn('m.vehicle_id', array_keys($canonical) ?: [0])
                ->select('m.vehicle_id', DB::raw('MIN(m.out_date) AS first_visit'), DB::raw('MAX(m.out_date) AS last_visit'))
                ->groupBy('m.vehicle_id')
                ->get()
                ->keyBy('vehicle_id');

            $items = collect($canonical)->map(function ($c) use ($dates) {
                $d = $dates[$c['id']] ?? null;
                return [
                    'id'                => $c['id'],
                    'plate'             => $c['plate'],
                    'car'               => $c['car'],
                    'visits'            => $c['periods'],          // type-U maintenance visits — matches Fleet Utilization
                    'first_visit'       => $d->first_visit ?? null,
                    'last_visit'        => $d->last_visit ?? null,
                    'days_in_shop'      => $c['days_in_shop'],      // true off-road shop days — matches Fleet Utilization
                    'currently_in_shop' => $c['currently_in_shop'],
                ];
            })
            ->sortByDesc('visits')
            ->values()
            ->all();

            return ['count' => count($items), 'window_days' => $days, 'from' => $winFrom, 'to' => $winTo, 'items' => $items];
        });
    }

    /**
     * SINGLE SOURCE OF TRUTH for per-vehicle maintenance days — delegates to FleetUtilizationService,
     * the exact same "Rental is King" calculation the Fleet Utilization page uses (overlaps merged,
     * rental-overlap days credited to rental not the shop, onboarding excluded, open stays run to
     * today). Both "Most Maintained Cars" (lifetimeMaintenanceDays) and the "All cars →" Maintenance
     * History list build on this so there is ONE definition of a maintenance day anywhere in FleetView.
     *
     * `$from`/`$to` are passed straight through to the report window (null,null = all-time / lifetime).
     * Returns one row per in-fleet car that actually saw the shop in the window, keyed by vehicle_id:
     *   days_in_shop      — TRUE off-road shop days (days_maintenance from the report)
     *   periods           — in-service workshop visits (maintenance_visits — matches the page's "N visits")
     *   currently_in_shop — an open type-U maintenance contract exists right now
     *
     * Sold / disposed / returned cars are dropped (GONE_STATUSES) to match the leaderboard's fleet scope.
     *
     * @return array<int,array{id:int, plate:?string, car:?string, days_in_shop:int, periods:int, currently_in_shop:bool}>
     */
    private function canonicalMaintenanceDays(?string $from = null, ?string $to = null, ?array $statuses = null): array
    {
        // $statuses limits the fleet at the DB level (e.g. ['ready','rented'] = active cars only). When
        // null we take the whole fleet and just drop GONE (sold/disposed) cars below.
        $report = $this->fleetUtilization->report($from, $to, $statuses);

        $rows = [];
        foreach ($report['cars'] as $c) {
            if ($statuses === null && in_array($c['status'], PlateResolver::GONE_STATUSES, true)) {
                continue;   // no sold / disposed / returned cars
            }
            $days     = (int) ($c['days_maintenance'] ?? 0);
            $periods  = (int) ($c['maintenance_visits'] ?? 0);
            $maintSec = (int) ($c['maintenance_seconds'] ?? 0);
            if ($maintSec <= 0 && $periods <= 0) {
                continue;   // never saw the shop in this window (a sub-day visit rounds days→0 but has real seconds)
            }
            $rows[(int) $c['vehicle_id']] = [
                'id'                  => (int) $c['vehicle_id'],
                'plate'               => $c['plate'],
                'car'                 => $c['car'],
                'days_in_shop'        => $days,
                // Precise elapsed time behind the shop figure — the RANKING key (a rounded day int would
                // tie every sub-day car at 0) and the basis for the frontend's sub-day "N h" display.
                'maintenance_seconds' => $maintSec,
                'periods'             => $periods,
                'currently_in_shop'   => (bool) ($c['currently_in_shop'] ?? false),
                // Full day split (same Rental-is-King numbers Fleet Utilization shows) so the widget can
                // show the whole picture per car — rented, in-service total, idle — not just shop days.
                'days_rented'         => (int) ($c['days_rented'] ?? 0),
                'days_in_service'     => (int) ($c['days_in_service'] ?? 0),
                'days_idle'           => (int) ($c['days_idle'] ?? 0),
                'rented_seconds'      => (int) ($c['rented_seconds'] ?? 0),
                'idle_seconds'        => (int) ($c['idle_seconds'] ?? 0),
                'utilization_pct'     => $c['utilization_pct'] ?? null,
                'downtime_pct'        => $c['downtime_pct'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * "Most Maintained Cars" — the top-N vehicles ranked by TRUE LIFETIME DOWNTIME (or by number of
     * maintenance periods). The maintenance-day figure is NOT computed here: it is the identical
     * "Rental is King" number Fleet Utilization shows over its All-Time window, via
     * canonicalMaintenanceDays(). So a car reading 155 days in Fleet Utilization (All Time) reads
     * exactly 155 here — one definition, no drift.
     *
     * @return array{count:int, items:array<int,array{id:int, plate:?string, car:?string, days_in_shop:int, periods:int, currently_in_shop:bool}>}
     */
    public function lifetimeMaintenanceDays(int $limit = 8, string $sort = 'downtime'): array
    {
        $sort = $sort === 'periods' ? 'periods' : 'downtime';

        return $this->remember("lifetime_downtime_days:{$sort}:{$limit}", self::CACHE_TTL, function () use ($limit, $sort) {
            // Active fleet only — cars that can be rented right now (status ready or rented).
            $items = array_values($this->canonicalMaintenanceDays(null, null, ['ready', 'rented']));

            // Rank biggest-first by the chosen metric (true downtime days, or number of maintenance
            // periods), with the other metric as the tiebreak, and cap to the top N.
            // Rank by PRECISE shop seconds (not the rounded day int), so cars that differ only by hours
            // still order correctly and sub-day downtime is never flattened to a tie at 0.
            usort($items, $sort === 'periods'
                ? fn ($a, $b) => ($b['periods'] <=> $a['periods']) ?: ($b['maintenance_seconds'] <=> $a['maintenance_seconds'])
                : fn ($a, $b) => ($b['maintenance_seconds'] <=> $a['maintenance_seconds']) ?: ($b['periods'] <=> $a['periods']));
            $items = array_slice($items, 0, $limit);

            return ['count' => count($items), 'items' => $items];
        });
    }

    /**
     * The individual maintenance visits behind one car's row on the Maintenance History page — the
     * "see N visits" drill-down. Delegates to FleetUtilizationService::maintenanceVisits() so the list
     * is the SAME type-U visit set the row's visit count comes from (list length == the "N visits"
     * badge), each trip enriched with garage / issue / cost from the workshop log. Newest first.
     */
    public function maintenanceHistoryVisits(int $vehicleId, int $days = 90, ?string $from = null, ?string $to = null): array
    {
        // Explicit from/to (either bound) overrides the trailing `days` window — must match maintenanceHistory().
        $explicit = $from !== null || $to !== null;
        $winFrom  = $explicit ? $from : ($days > 0 ? Carbon::today()->subDays($days)->toDateString() : null);
        $winTo    = $explicit ? $to : null;
        $out      = $this->fleetUtilization->maintenanceVisits($vehicleId, $winFrom, $winTo);

        return ['vehicle_id' => $vehicleId, 'window_days' => $days, 'from' => $winFrom, 'to' => $winTo, 'count' => $out['count'], 'items' => $out['items']];
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

        // COST (the spend bars): read from the VehicleExpenseProvider — the SOLE source of vehicle
        // expense (imported Expenses sheet today, Odoo later). Totalled per month by entry_date, so the
        // bars match the expense figures on Profitability / Cost Intelligence. `cost_visits` carries the
        // number of expense lines behind each month's bar (surfaced in the tooltip). The retired
        // sheet/manual maintenance log is deliberately NOT used here — it holds no real spend.
        $costByMonth = $this->expenses->totalsByMonth($start->toDateString(), null);
        foreach ($costByMonth as $ym => $agg) {
            if (isset($skeleton[$ym])) {
                $skeleton[$ym]['cost']        = round((float) $agg['total'], 2);
                $skeleton[$ym]['cost_visits'] = (int) $agg['lines'];
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
