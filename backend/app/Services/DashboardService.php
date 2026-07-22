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
            $inShop      = $this->inMaintenanceList();

            return [
                'window_days'     => $days,
                'contract_expiry' => ['count' => count($rentals),      'items' => array_slice($rentals, 0, 5)],
                // Cars physically in the workshop right now, tagged with the lifecycle stage they sit at.
                // The panel is the primary Proactive-Flags column, so it shows the FULL in-shop list
                // (inMaintenanceList already caps at 25), not a top-5 teaser.
                'in_maintenance'  => ['count' => count($inShop),        'items' => $inShop],
                // count/total are the TRUE totals of the whole set; items is just the top-5 shown.
                'invoice_overdue' => ['count' => $invSummary['count'], 'total' => $invSummary['total'], 'items' => $invItems],
                'inspection_due'  => ['count' => count($inspections),  'items' => array_slice($inspections, 0, 5)],
            ];
        });
    }

    /**
     * Cars physically in the workshop right now — sourced from the REAL maintenance data we hold
     * today: OPEN type-U maintenance CONTRACTS (OM / sheet-synced), one row per in-shop car. The
     * app-side workflow tickets are still being adopted, so the contract is the source of truth for
     * "is this car in the garage" for now. Each row carries its garage and a repair-ETA gauge
     * computed from the contract's in-shop start (out_date) vs the promised ready-by date
     * (maintenance.expected_return_date), falling back to the fleet-default target when none is set.
     * Longest-overdue first so the cars blowing their window sit at the top.
     *
     * @return array<int,array{id:?int, plate:?string, car:?string, stage:string, garage:?string, eta:array}>
     */
    public function inMaintenanceList(int $limit = 25): array
    {
        return Contract::query()
            ->where('contract_type', 'U')
            ->currentlyOpen()
            ->whereNotNull('vehicle_id')
            ->with(['vehicle:id,plate_no,make,model', 'maintenance:id,contract_id,garage,vendor_id,expected_return_date', 'maintenance.vendor:id,name'])
            ->limit($limit)
            ->get(['id', 'vehicle_id', 'out_date'])
            ->map(function ($c) {
                $m = $c->maintenance;
                // In-shop clock anchored to when the car went in (contract out_date); target is the
                // garage's promised ready-by date when set, else the fleet-default window.
                $eta = Maintenance::etaFromDates($c->out_date, $m?->expected_return_date);

                return [
                    'id'     => (int) $c->vehicle_id,
                    'plate'  => $c->vehicle?->plate_no,
                    'car'    => $c->vehicle ? (trim(($c->vehicle->make ?? '') . ' ' . ($c->vehicle->model ?? '')) ?: null) : null,
                    'stage'  => 'In workshop',
                    'garage' => $m?->vendor?->name ?: ($m?->garage ?: null),
                    'eta'    => $eta,
                ];
            })
            // Worst-overdue first, then closest-to-due; keeps the urgent cars at the top of the panel.
            ->sortByDesc(fn ($r) => ($r['eta']['days_over'] ?? 0) * 1000 - ($r['eta']['days_left'] ?? 0))
            ->values()
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
    public function maintenanceHistory(int $days = 90): array
    {
        return $this->remember("maint_history:{$days}", self::CACHE_TTL, function () use ($days) {
            // days=0 → all-time (no trailing window); the whereDate cutoff below is skipped.
            $allTime = $days <= 0;
            $cutoff  = Carbon::today()->subDays($days)->toDateString();

            // SINGLE SOURCE OF TRUTH for BOTH the visit count and the day count — the same Rental-is-King
            // calculation Fleet Utilization uses, over the same window (null = all-time). Keyed by car.
            $canonical = $this->canonicalMaintenanceDays($allTime ? null : $cutoff);

            // First/last workshop DATES (sheet + manual log) — timeline metadata only, attached per car.
            $dates = DB::table('maintenances as m')
                ->whereIn('m.origin', Maintenance::WORKSHOP_LOG_ORIGINS)
                ->whereNotNull('m.out_date')
                ->when(! $allTime, fn ($q) => $q->whereDate('m.out_date', '>=', $cutoff))
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

            return ['count' => count($items), 'window_days' => $days, 'items' => $items];
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
    public function maintenanceHistoryVisits(int $vehicleId, int $days = 90): array
    {
        $from = $days > 0 ? Carbon::today()->subDays($days)->toDateString() : null;
        $out  = $this->fleetUtilization->maintenanceVisits($vehicleId, $from);

        return ['vehicle_id' => $vehicleId, 'window_days' => $days, 'count' => $out['count'], 'items' => $out['items']];
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
