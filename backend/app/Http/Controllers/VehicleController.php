<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\Contract;
use App\Models\Maintenance;
use App\Models\Vehicle;
use App\Http\Requests\StoreVehicleRequest;
use App\Http\Requests\UpdateVehicleRequest;
use App\Http\Resources\VehicleResource;
use App\Services\FleetUtilizationService;
use App\Services\MaintenanceAnalyticsService;
use App\Services\MileageBaselineService;
use App\Services\RealProfitService;
use App\Services\VehicleService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class VehicleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    private $vehicleService;
    public function __construct(VehicleService $vehicleService)
    {
        $this->vehicleService = $vehicleService;
    }
    public function index()
    {
        try {
            $vehicle = $this->vehicleService->index();
            $result = VehicleResource::collection($vehicle);
            return ResponseHelper::SuccessResponse($result, "Vehicle retrieved successfully", 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    /**
     * Fleet Utilization: per-car split of calendar time into rented / in-maintenance / idle over a
     * window, with days owned as the denominator. `period` picks a preset window (or pass explicit
     * from/to); `status=all` includes sold/disposed cars for historical analysis.
     */
    public function utilization(Request $request, FleetUtilizationService $utilization)
    {
        try {
            [$from, $to] = $this->resolveWindow(
                $request->query('period', 'last_12m'),
                $request->query('from'),
                $request->query('to'),
            );
            $statuses = $this->resolveStatuses($request->query('statuses'), $request->query('status'));

            return ResponseHelper::SuccessResponse(
                $utilization->report($from, $to, $statuses),
                "Fleet utilization retrieved successfully",
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    /**
     * Data Reconciliation: cars whose STORED odometer disagrees with the Mileage-Baseline scanner's
     * validated reading by more than ?min_diff km (default 100). The review queue behind the
     * Mileage Reconciliation page, where each gap can be manually adopted via applyBaseline().
     */
    public function mileageReconciliation(Request $request, MileageBaselineService $mileage)
    {
        try {
            $minDiff    = max(0, (int) $request->query('min_diff', MileageBaselineService::ROLLBACK_FLOOR_KM));
            $includeAll = filter_var($request->query('all', false), FILTER_VALIDATE_BOOLEAN);

            return ResponseHelper::SuccessResponse(
                $mileage->reconciliation($minDiff, $includeAll),
                "Mileage reconciliation retrieved successfully",
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    /**
     * "Apply Baseline" — force this car's odometer to the scanner's validated value (manual,
     * per-car approval before making the scanner the sole authority). Writes the baseline anchor
     * too. Gated by vehicles.manage.
     */
    public function applyBaseline(Vehicle $vehicle, MileageBaselineService $mileage)
    {
        try {
            return ResponseHelper::SuccessResponse(
                $mileage->applyOne($vehicle),
                "Odometer updated to the scanner value",
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    /**
     * Turn a period preset (or explicit dates) into a [from, to] window. `all` / lifetime → [null, null].
     *
     * @return array{0:?string,1:?string}
     */
    private function resolveWindow(string $period, ?string $from, ?string $to): array
    {
        if ($from || $to) {
            return [$from ?: null, $to ?: null];
        }
        $today = Carbon::today();

        return match ($period) {
            'this_month' => [$today->copy()->startOfMonth()->toDateString(), null],
            'last_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(), $today->copy()->subMonthNoOverflow()->endOfMonth()->toDateString()],
            'last_3m'    => [$today->copy()->subMonthsNoOverflow(3)->toDateString(), null],
            'last_6m'    => [$today->copy()->subMonthsNoOverflow(6)->toDateString(), null],
            'last_12m'   => [$today->copy()->subMonthsNoOverflow(12)->toDateString(), null],
            'all'        => [null, null],
            default      => [null, null],
        };
    }

    /** The operational fleet: cars that can actually be rented or sent for maintenance. */
    private const OPERATIONAL_STATUSES = ['rented', 'ready', 'out_of_order', 'returned'];

    /**
     * Resolve which vehicle statuses to include. An explicit `statuses` CSV wins; otherwise default
     * to the operational fleet (so sold / suspended / disposed / office-use are hidden unless asked).
     * Legacy `status=all` (or an explicit "all" in the CSV) means every status → null.
     *
     * @return array<int,string>|null  null = no status filter (all)
     */
    private function resolveStatuses(?string $statuses, ?string $legacy): ?array
    {
        if ($statuses !== null && trim($statuses) !== '') {
            $list = array_values(array_unique(array_filter(array_map('trim', explode(',', $statuses)))));
            if (in_array('all', $list, true)) {
                return null;
            }

            return $list ?: self::OPERATIONAL_STATUSES;
        }
        if ($legacy === 'all') {
            return null;
        }

        return self::OPERATIONAL_STATUSES;
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreVehicleRequest $request)
    {
        try {
            $vehicle = $this->vehicleService->store($request->validated());
            $result = VehicleResource::make($vehicle);
            return ResponseHelper::SuccessResponse($result, "Vehicle created successfully", 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Vehicle $vehicle)
    {
        try {
            $result = VehicleResource::make($vehicle);
            return ResponseHelper::SuccessResponse($result, "Vehicle retrieved successfully", 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    /**
     * Full car profile: specs + registration/insurance + fines + contract history.
     */
    public function profile(Vehicle $vehicle, MaintenanceAnalyticsService $analytics, RealProfitService $profit)
    {
        try {
            $vehicle->load('registration.insuranceCompany');
            $contracts = $vehicle->contracts()->with('customer')->latest('id')->get();
            $reg = $vehicle->registration;

            // Lifetime Profit Bridge for this one car (gross revenue − operating − maintenance = net),
            // straight from the shared RealProfitService engine so it matches the fleet table & yield.
            $bridge = $profit->vehicleBridge([$vehicle->id])[$vehicle->id] ?? [
                'rent_billed' => 0.0, 'discount' => 0.0, 'realized_usage' => 0.0,
                'gross_revenue' => 0.0, 'operating_cost' => 0.0, 'maintenance' => 0.0,
                'net_profit' => 0.0, 'contracts' => 0,
            ];

            // Per-rental-contract contributions — the full working behind the bridge, so clicking
            // "Lifetime Net Profit" shows EVERY contract that summed into it (newest first).
            $profitContracts = $contracts->where('contract_type', 'C')
                ->map(function ($c) use ($profit) {
                    $p = $profit->contractProfit($c);

                    return [
                        'id'             => $c->id,
                        'contract_no'    => $c->contract_no,
                        'out_date'       => optional($c->out_date)->toDateString(),
                        'in_date'        => optional($c->in_date)->toDateString(),
                        'customer'       => $c->customer?->name_en,
                        'rent_billed'    => round((float) $c->rents_debit, 2),
                        'discount'       => round((float) $c->contract_discount, 2),
                        'realized_usage' => $p['realized_usage'],
                        'operating_cost' => $p['operating_cost'],
                        'net'            => round((float) $p['real_net_profit'], 2),
                    ];
                })
                ->sortByDesc('out_date')
                ->values();

            // How many workshop repairs actually carried a cost into the maintenance line.
            $maintCostedVisits = Maintenance::whereIn('origin', Maintenance::WORKSHOP_LOG_ORIGINS)
                ->where('vehicle_id', $vehicle->id)
                ->where('cost', '>', 0)
                ->count();

            // Current availability = the car's single open contract (if any).
            $open = $vehicle->openContract()->with(['customer', 'maintenance.vendor'])->first();
            $availability = $this->availabilityFor($open, $vehicle);

            // Sheet maintenance log for this car (origin='sheet'): one row per workshop event
            // (OUT/IN/Follow up/…), the authoritative source of garage / issues / cost / reason.
            // Loaded once — used both for the timeline below AND to enrich each maintenance
            // contract visit, which on its own (from OfficeManager) carries no detail.
            $sheetEvents = Maintenance::where('vehicle_id', $vehicle->id)
                ->where('origin', 'sheet')
                ->with(['vendor:id,name', 'reason:id,reason_en,level'])
                ->orderByRaw('COALESCE(out_date, follow_date, actual_in_date) DESC')
                ->orderByDesc('id')
                ->get();

            $levelRank = ['critical' => 0, 'special' => 1, 'minor' => 2, 'routine' => 3];

            // Full maintenance "story": every type-U visit, ENRICHED with the workshop events
            // from the sheet log (garage / issues / priority / cost / stage), newest first.
            $maintenanceContracts = $vehicle->contracts()
                ->where('contract_type', 'U')
                ->with(['maintenance.vendor', 'maintenance.reason', 'items'])
                ->orderByDesc('out_date')
                ->orderByDesc('id')
                ->get();

            // Link each visit to its FULL workshop sequence using the contract-lifespan
            // window (same Hard-Lock rule as the board), not an exact out_date match — a
            // repair often logs a Test on day 1 then OUT/IN on later dates, all one visit.
            $linkedEvents = $analytics->linkedSheetEvents($maintenanceContracts);

            $maintenance = $maintenanceContracts->map(function ($c) use ($analytics, $linkedEvents, $levelRank) {
                $visitDate = optional($c->out_date)->toDateString();
                // Newest-first so the existing ->first() calls below mean "latest event".
                $events = ($linkedEvents[$c->id] ?? collect())->reverse()->values();

                // Issues: hand-entered header tags win; else the union of the events' MAIN+SUP.
                $tags = ! empty($c->maintenance?->maintenance_tags)
                    ? $c->maintenance->maintenance_tags
                    : $events->flatMap(fn ($e) => $analytics->sheetIssueTags($e))->unique()->values()->all();

                // Garage: header vendor; else the first garage seen across the visit's events.
                $garage = $c->maintenance?->vendor?->name
                    ?: $events->map(fn ($e) => $e->vendor?->name ?: $e->garage)->filter()->first();

                // Priority: header reason; else the most severe reason across the events;
                // else fall back to classifying the issue tags.
                $level = $c->maintenance?->reason?->level
                    ?: $events->map(fn ($e) => $e->reason?->level)->filter()
                        ->sortBy(fn ($l) => $levelRank[$l] ?? 9)->first();
                $priority = $level ?: $analytics->classifyPriority($tags)['level'];

                // Cost: contract line items if any; else the sum of the events' costs.
                $cost = round((float) $c->items->sum('cost'), 2);
                if ($cost <= 0) {
                    $cost = round((float) $events->sum('cost'), 2);
                }

                return [
                    'id'           => $c->id,
                    'contract_no'  => $c->contract_no,
                    'date'         => $visitDate,
                    'in_date'      => optional($c->in_date)->toDateString()
                                       ?: $events->map(fn ($e) => optional($e->actual_in_date)->toDateString())->filter()->max(),
                    'state'        => $c->state,
                    'garage'       => $garage,
                    'responsible'  => $c->maintenance?->responsible ?: $events->map(fn ($e) => $e->responsible)->filter()->first(),
                    'approved_by'  => $c->maintenance?->approved_by,
                    'priority'     => $priority,
                    'reason'       => $c->maintenance?->reason?->reason_en,
                    'tags'         => $tags,
                    'notes'        => $c->maintenance?->maintenance_notes,
                    // latest workshop stage for this visit (OUT/IN/Follow up/…)
                    'stage'        => optional($events->first())->event_status,
                    'event_count'  => $events->count(),
                    'total'        => $cost,
                    // the workshop events behind this visit, for the expandable detail
                    'events'       => $events->map(fn ($e) => [
                        'id'     => $e->id,
                        'event'  => $e->event_status,
                        'date'   => optional($e->out_date)->toDateString()
                                     ?? optional($e->follow_date)->toDateString()
                                     ?? optional($e->actual_in_date)->toDateString(),
                        // actual return date — shown on the event when it differs from the out date
                        'actual_in' => optional($e->actual_in_date)->toDateString(),
                        'garage' => $e->vendor?->name ?: $e->garage,
                        'type'   => $e->maintenance_type,
                        'issues' => $analytics->sheetIssueTags($e),
                        'notes'  => $e->maintenance_notes,
                        'cost'   => $e->cost,
                    ])->values(),
                    'items'        => $c->items->map(fn ($i) => [
                        'id'           => $i->id,
                        'service_name' => $i->service_name,
                        'cost'         => $i->cost,
                        'notes'        => $i->notes,
                    ])->values(),
                ];
            })->values();

            // Timeline of every workshop event for this car (built from the same loaded rows).
            $maintenanceLog = $sheetEvents
                ->map(fn ($m) => [
                    'id'        => $m->id,
                    'event'     => $m->event_status,
                    'date'      => optional($m->out_date)->toDateString()
                                   ?? optional($m->follow_date)->toDateString()
                                   ?? optional($m->actual_in_date)->toDateString(),
                    // actual return date — shown on the event when it differs from the out date
                    'actual_in' => optional($m->actual_in_date)->toDateString(),
                    'garage'    => $m->vendor?->name ?: $m->garage,
                    'type'      => $m->maintenance_type,
                    // the real fault: MAIN area(s) + SUP detail(s) recorded for the visit
                    'main'      => $m->service_main,
                    'sup'       => $m->service_sup,
                    'issues'    => $analytics->sheetIssueTags($m),
                    'severity'  => $m->severity,
                    'damage'    => $m->damage_location,
                    'driver'    => $m->driver,
                    'notes'     => $m->maintenance_notes,
                    'cost'      => $m->cost,
                ])->values();

            $data = [
                'vehicle'      => VehicleResource::make($vehicle),
                'registration' => $reg ? [
                    'chasis_no'              => $reg->chasis_no,
                    'expiry_date'            => optional($reg->expiry_date)->toDateString(),
                    'registration_days_left' => $reg->registration_days_left,
                    'insurance_expiry'       => optional($reg->insurance_expiry)->toDateString(),
                    'insurance_days_left'    => $reg->insurance_days_left,
                    'insurer'                => $reg->insuranceCompany?->name,
                    'status'                 => $reg->status,
                    'mortgaged_by'           => $reg->mortgaged_by,
                    'fines_count'            => $reg->fines_count,
                    'fines_amount'           => $reg->fines_amount,
                ] : null,
                'contracts' => $contracts->map(fn ($c) => [
                    'id'            => $c->id,
                    'contract_no'   => $c->contract_no,
                    'contract_type' => $c->contract_type,
                    'state'         => $c->state,
                    'customer'      => $c->customer?->name_en,
                    'customer_id'   => $c->customer_id,
                    'out_date'      => optional($c->out_date)->toDateString(),
                    'in_date'       => optional($c->in_date)->toDateString(),
                    'debit'         => $c->contract_debit,
                    'credit'        => $c->contract_credit,
                    'balance'       => $c->contract_balance,
                ])->values(),
                'availability' => $availability,
                'maintenance' => $maintenance,
                'maintenance_log' => $maintenanceLog,
                'maintenance_analytics' => $analytics->vehicleServiceTrends($vehicle->id),
                'stats' => [
                    'contracts_count'   => $contracts->count(),
                    'open_count'        => $contracts->where('state', 'open')->whereNull('in_date')->count(),
                    // Lifetime Net Profit + the gross→net Profit Bridge — what the car generated on
                    // paper vs. what was actually pocketed (RealProfitService, the single source).
                    'lifetime_net_profit' => $bridge['net_profit'],
                    // A NEW car (in fleet, never rented) shows "New — not yet rented" instead of AED 0,
                    // so a brand-new addition is never mistaken for a non-performer.
                    'is_new'              => $contracts->where('contract_type', 'C')->isEmpty()
                                              && ! in_array($vehicle->status, ['sold', 'disposed'], true),
                    // Full gross→net working, incl. the sub-sums (rent − discount + usage = gross) and
                    // counts, so the "Lifetime Net Profit" drill-down can show exactly how it was built.
                    'profit_bridge'       => [
                        'rent_billed'        => $bridge['rent_billed'],
                        'discount'           => $bridge['discount'],
                        'realized_usage'     => $bridge['realized_usage'],
                        'gross_revenue'      => $bridge['gross_revenue'],
                        'operating_cost'     => $bridge['operating_cost'],
                        'maintenance'        => $bridge['maintenance'],
                        'net_profit'         => $bridge['net_profit'],
                        'rentals'            => $bridge['contracts'],
                        'maintenance_visits' => $maintCostedVisits,
                    ],
                    'profit_contracts'    => $profitContracts,
                    'maintenance_count' => $maintenance->count(),
                    'maintenance_total' => round($maintenance->sum('total'), 2),
                    'maintenance_events' => $maintenanceLog->count(),
                ],
            ];

            return ResponseHelper::SuccessResponse($data, "Vehicle profile retrieved successfully", 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    /** Derive a car's current availability from its single open contract. */
    private function availabilityFor(?Contract $open, ?Vehicle $vehicle = null): array
    {
        // A car that has left the fleet (sold / disposed / returned) is never live-rented or
        // in maintenance, even if an old contract was never closed in OfficeManager — its
        // lifecycle status governs. (See OperationsService::LEFT_FLEET_STATUSES.)
        if ($vehicle && in_array($vehicle->status, \App\Services\OperationsService::LEFT_FLEET_STATUSES, true)) {
            return [
                'state'            => 'out_of_fleet',
                'label'            => Vehicle::STATUS_LABELS[$vehicle->status] ?? ucfirst($vehicle->status),
                'open_contract_id' => null,
            ];
        }

        if (! $open) {
            return ['state' => 'available', 'label' => 'Available', 'open_contract_id' => null];
        }

        if ($open->contract_type === 'U') {
            return [
                'state'            => 'maintenance',
                'label'            => 'In maintenance',
                'open_contract_id' => $open->id,
                'since'            => optional($open->out_date)->toDateString(),
                'due'              => optional($open->maintenance?->expected_return_date)->toDateString(),
                'garage'           => $open->maintenance?->vendor?->name,
            ];
        }

        if ($open->contract_type === 'C') {
            // No return date yet — estimate it from the planned rental days.
            $due = ($open->out_date && $open->days > 0)
                ? $open->out_date->copy()->addDays((int) $open->days)->toDateString()
                : null;

            return [
                'state'            => 'rented',
                'label'            => 'Rented',
                'open_contract_id' => $open->id,
                'since'            => optional($open->out_date)->toDateString(),
                'days'             => $open->days ? (int) $open->days : null,
                'due'              => $due,                                   // estimated return
                'overdue'          => $due ? now()->startOfDay()->gt(\Carbon\Carbon::parse($due)) : false,
                'customer'         => $open->customer?->name_en ?: ($open->customer?->customer_no ? '#' . $open->customer->customer_no : null),
            ];
        }

        return ['state' => 'busy', 'label' => 'Out', 'open_contract_id' => $open->id, 'since' => optional($open->out_date)->toDateString()];
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateVehicleRequest $request, Vehicle $vehicle)
    {
        try {
            $vehicle = $this->vehicleService->update($request->validated(), $vehicle);
            $result = VehicleResource::make($vehicle);
            return ResponseHelper::SuccessResponse($result, "Vehicle updated successfully", 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Vehicle $vehicle)
    {
        try {
            $this->vehicleService->destroy($vehicle);
            return ResponseHelper::SuccessResponse(null, "Vehicle deleted successfully", 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }
}
