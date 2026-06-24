<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Maintenance;
use App\Models\Vehicle;
use App\Models\VehicleRegistration;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Read-only KPIs for the dashboard. Each figure is a single COUNT/SUM query —
 * cheap to call and safe to cache later if needed.
 */
class DashboardService
{
    public function __construct(
        private RealProfitService $realProfit,
        private OperationsService $operations,
    ) {
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
        return [
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
        ];
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
        $by = Vehicle::selectRaw('status, count(*) c')
            ->groupBy('status')->pluck('c', 'status');

        // OfficeManager rarely sets the lifecycle status to `under_maintenance` — a car
        // in for repair keeps whatever it was (almost always still `rented`). So the raw
        // status buckets would report Maintenance = 0 while cars sit in the garage. We
        // treat "in the garage" operationally: any vehicle with an OPEN type-U maintenance
        // contract (or an explicit under_maintenance status). Those cars are reclassified
        // into the maintenance slice and removed from their original bucket, so the donut
        // stays mutually exclusive, still sums to total, and matches the "Cars in
        // Maintenance" KPI and the /maintenance board.
        $maintIds = Vehicle::where('status', 'under_maintenance')->pluck('id')
            ->merge(
                Contract::where('contract_type', 'U')->currentlyOpen()
                    ->whereNotNull('vehicle_id')->pluck('vehicle_id')
            )
            // Cars in the garage on a hand-entered workshop event with no open contract — same
            // set the KPI and the /maintenance board count, so the donut never disagrees.
            ->merge($this->operations->manualOnlyGarageVehicleIds())
            ->unique();
        $maintByStatus = Vehicle::whereIn('id', $maintIds)
            ->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');

        // Each named bucket = its raw count minus the cars we moved into maintenance.
        $bucket = fn (string $key) => max(0, (int) ($by[$key] ?? 0) - (int) ($maintByStatus[$key] ?? 0));

        $available   = $bucket('ready');
        $rented      = $bucket('rented');
        $maintenance = $maintIds->count();   // every car actually in the garage right now
        $officeUse   = $bucket('office_use');
        $outOfOrder  = $bucket('out_of_order');
        $suspended   = $bucket('suspended');
        $returned    = $bucket('returned');
        $sold        = $bucket('sold');
        $disposed    = $bucket('disposed');
        $total       = (int) $by->sum();

        $named = $available + $rented + $maintenance + $officeUse
            + $outOfOrder + $suspended + $returned + $sold + $disposed;

        return [
            'available'    => $available,
            'rented'       => $rented,
            'maintenance'  => $maintenance,
            'office_use'   => $officeUse,
            'out_of_order' => $outOfOrder,
            'suspended'    => $suspended,
            'returned'     => $returned,
            'sold'         => $sold,
            'disposed'     => $disposed,
            'booked'       => $this->bookedCars(),
            'other'        => max(0, $total - $named), // any uncategorised / null status
            'total'        => $total,
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
     * Cars currently in the garage: open maintenance contracts PLUS cars in on a hand-entered
     * workshop event with no contract. One definition, shared with the donut and the board.
     */
    public function carsInMaintenance(): int
    {
        $contractCars = Contract::where('contract_type', 'U')->currentlyOpen()
            ->whereNotNull('vehicle_id')->distinct()->count('vehicle_id');

        return $contractCars + count($this->operations->manualOnlyGarageVehicleIds());
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
}
