<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\Contract;
use App\Models\Maintenance;
use App\Models\Vehicle;
use App\Http\Requests\StoreVehicleRequest;
use App\Http\Requests\UpdateVehicleRequest;
use App\Http\Resources\VehicleResource;
use App\Services\MaintenanceAnalyticsService;
use App\Services\VehicleService;

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
    public function profile(Vehicle $vehicle, MaintenanceAnalyticsService $analytics)
    {
        try {
            $vehicle->load('registration.insuranceCompany');
            $contracts = $vehicle->contracts()->with('customer')->latest('id')->get();
            $reg = $vehicle->registration;

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
                    'id'       => $m->id,
                    'event'    => $m->event_status,
                    'date'     => optional($m->out_date)->toDateString()
                                   ?? optional($m->follow_date)->toDateString()
                                   ?? optional($m->actual_in_date)->toDateString(),
                    'garage'   => $m->vendor?->name ?: $m->garage,
                    'type'     => $m->maintenance_type,
                    'severity' => $m->severity,
                    'damage'   => $m->damage_location,
                    'driver'   => $m->driver,
                    'notes'    => $m->maintenance_notes,
                    'cost'     => $m->cost,
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
                    'lifetime_income'   => round($contracts->sum(fn ($c) => (float) $c->contract_income), 2),
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
