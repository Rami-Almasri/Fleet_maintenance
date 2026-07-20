<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\Contract;
use App\Models\Maintenance;
use App\Models\MaintenanceMedia;
use App\Models\Vehicle;
use App\Models\VehicleLogEvent;
use App\Http\Requests\StoreVehicleRequest;
use App\Http\Requests\UpdateVehicleRequest;
use App\Http\Resources\VehicleResource;
use App\Services\FleetUtilizationService;
use App\Services\MaintenanceAnalyticsService;
use App\Services\MileageBaselineService;
use App\Services\OperationsService;
use App\Services\RealProfitService;
use App\Services\VehicleService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
            return ResponseHelper::fromException($e);
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
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Fleet Operations: cars on rent right now that also have a workshop record open today (their
     * maintenance clock is paused until they return) — an open 'U' contract, or an untracked sheet shop
     * event with no 'U' contract. Rental is King; this snapshot is informational, not a warning.
     */
    public function activeShopStays(Request $request, FleetUtilizationService $utilization)
    {
        try {
            $lookback = (int) $request->query('sheet_lookback', 60);

            return ResponseHelper::SuccessResponse(
                $utilization->activeShopStays($lookback > 0 ? $lookback : 60),
                'Active rental shop stays retrieved successfully',
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Fleet Operations — maintenance↔rental overlap history: every type-'U' maintenance contract whose
     * dates overlapped a 'C' rental (both may be closed). Rental is King, so the overlap is rental time;
     * this powers the per-ticket true off-road shop-days breakdown. ?months=N (default 12).
     */
    public function maintenanceOverlaps(Request $request, FleetUtilizationService $utilization)
    {
        try {
            $months = (int) $request->query('months', 12);

            return ResponseHelper::SuccessResponse(
                $utilization->maintenanceOverlaps($months > 0 ? $months : 12),
                'Maintenance overlaps retrieved successfully',
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * The car's Technical Service Log — every part/service recorded on its invoices (money-free),
     * newest first. Powers the per-vehicle Service History view AND the ticket "last done" hint:
     *   - ?q=  free-text search across the work-item description (e.g. "engine oil").
     *   - `last_by_category` pre-buckets the LATEST occurrence per Findings category, so a ticket can
     *     instantly show "last done: <date> at <garage>" the moment the inspector picks a category.
     */
    public function serviceHistory(Request $request, Vehicle $vehicle)
    {
        try {
            $q = trim((string) $request->query('q', ''));

            $items = \App\Models\InvoiceItem::query()
                ->whereHas('invoice', fn ($iq) => $iq->where('vehicle_id', $vehicle->id))
                ->when($q !== '', fn ($iq) => $iq->where('description', 'like', '%' . $q . '%'))
                ->with(['invoice:id,invoice_ref,invoice_date,vendor_id', 'invoice.vendor:id,name'])
                ->get()
                ->map(fn ($it) => [
                    'id'           => $it->id,
                    'description'  => $it->description,
                    'category_key' => $it->category_key,
                    'date'         => optional($it->invoice?->invoice_date)->toDateString(),
                    'garage'       => $it->invoice?->vendor?->name,
                    'invoice_ref'  => $it->invoice?->invoice_ref,
                ])
                ->sortByDesc(fn ($r) => $r['date'] ?? '0000-00-00') // newest first; null dates last
                ->values();

            // Latest occurrence per category (items are newest-first, so first seen = latest).
            $lastByCategory = [];
            foreach ($items as $r) {
                $key = $r['category_key'];
                if (! $key || isset($lastByCategory[$key])) {
                    continue;
                }
                $lastByCategory[$key] = ['date' => $r['date'], 'garage' => $r['garage'], 'description' => $r['description']];
            }

            return ResponseHelper::SuccessResponse([
                'items'            => $items,
                'last_by_category' => $lastByCategory,
                'total'            => $items->count(),
            ], 'Service history retrieved', 200);
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Open a maintenance ticket for a scheduled/routine service on this car — the target of the
     * Service-Due alert (?serviceTicket=<type>). This ORIGINATES a ticket only; it does NOT log service
     * against the vehicle. The vehicle's service record is updated exclusively when that ticket is later
     * closed (MaintenanceWorkflowService::confirmRoutineServices). Ticket = single source of truth.
     */
    public function openServiceTicket(Request $request, Vehicle $vehicle, \App\Services\MaintenanceWorkflowService $workflow)
    {
        try {
            $data = $request->validate([
                'service_type'  => 'nullable|string|max:64',
                'service_label' => 'nullable|string|max:120',
                'odometer'      => 'nullable|integer|min:0',
            ]);

            $label = $data['service_label']
                ?: (Maintenance::serviceLabelForType($data['service_type'] ?? null) ?: 'Oil Change');

            $odometer = $data['odometer'] ?? $vehicle->odometer;

            $ticket = $workflow->openServiceTicket($vehicle, $label, $odometer, $request->user());

            return ResponseHelper::SuccessResponse([
                'ticket' => [
                    'id'              => $ticket->id,
                    'workflow_status' => $ticket->workflow_status,
                    'url'             => '/maintenance-workflow/' . $ticket->id,
                ],
            ], 'Maintenance ticket opened — perform the service on the ticket; the vehicle updates when it is closed.', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * The car's Tire Details — every tyre line recorded on its maintenance tickets (brand, DOT code,
     * tread depth, install date/odometer, warranty), newest install first. A tyre line is any
     * maintenance line item categorised 'tyres' OR carrying a tyre-specific field. Money (line cost)
     * is included but the frontend hides it behind SHOW_FINANCIALS like every other cost surface.
     */
    public function tireHistory(Vehicle $vehicle)
    {
        try {
            $items = \App\Models\MaintenanceLineItem::query()
                ->where('vehicle_id', $vehicle->id)
                ->where(function ($w) {
                    $w->where('category_key', 'tyres')
                        ->orWhereNotNull('tire_brand')
                        ->orWhereNotNull('tire_dot')
                        ->orWhereNotNull('tire_tread_mm');
                })
                ->with(['maintenance:id,contract_no,vehicle_id,vendor_id', 'maintenance.vendor:id,name'])
                ->get()
                ->map(fn ($l) => [
                    'id'                 => $l->id,
                    'brand'              => $l->tire_brand,
                    'dot'                => $l->tire_dot,
                    'tread_mm'           => $l->tire_tread_mm !== null ? (float) $l->tire_tread_mm : null,
                    'description'        => $l->description,
                    'part_number'        => $l->part_number,
                    'quantity'           => (float) $l->quantity,
                    'cost'               => (float) $l->line_total,
                    'installed_on'       => optional($l->installed_on)->toDateString(),
                    'installed_odometer' => $l->installed_odometer,
                    'warranty_until'     => optional($l->warranty_until)->toDateString(),
                    'garage'             => $l->maintenance?->vendor?->name,
                    'ticket_id'          => $l->maintenance_id,
                    'ticket_no'          => $l->maintenance?->contract_no,
                ])
                ->sortByDesc(fn ($r) => $r['installed_on'] ?? '0000-00-00') // newest install first; null dates last
                ->values();

            return ResponseHelper::SuccessResponse([
                'items' => $items,
                'total' => $items->count(),
            ], 'Tire details retrieved', 200);
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Plate History — every vehicle that has ever carried this car's plate (plates get reused
     * after a sale in the UAE). Returns the current holder first, then previous holders, each
     * tagged with its status and how much history lives on it. Read-only over the
     * plate_assignments timeline: NOTHING is ever merged or moved between vehicles — each car
     * keeps its own maintenance / inspection / repair history forever. The only link is the
     * shared plate. The frontend uses this to render the "Plate History" section + Sold /
     * Current / Previous badges and to navigate between the vehicles.
     */
    public function plateHistory(Vehicle $vehicle, \App\Services\PlateHistoryService $plateHistory)
    {
        try {
            return ResponseHelper::SuccessResponse(
                $plateHistory->forVehicle($vehicle),
                'Plate history retrieved',
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * "Time machine": what one car was doing — either on a single calendar day (?date=YYYY-MM-DD, or
     * ?from= alone; rented to whom / in the workshop for what / idle / onboarding / not yet owned), OR
     * over a date range (?from=&to=) returning how many days it was rented / in the workshop / available.
     */
    public function statusOn(Request $request, Vehicle $vehicle, FleetUtilizationService $utilization)
    {
        try {
            $from = $request->query('from') ?: $request->query('date') ?: Carbon::today()->toDateString();
            $to   = $request->query('to');

            if (! Carbon::hasFormat($from, 'Y-m-d')) {
                return ResponseHelper::FailureResponse(null, 'A valid date (YYYY-MM-DD) is required.', 422);
            }

            // Range mode — caller passed both ends and they differ.
            if ($to) {
                if (! Carbon::hasFormat($to, 'Y-m-d')) {
                    return ResponseHelper::FailureResponse(null, 'A valid end date (YYYY-MM-DD) is required.', 422);
                }
                if ($to !== $from) {
                    return ResponseHelper::SuccessResponse(
                        $utilization->usageBreakdown($vehicle->id, $from, $to),
                        "Vehicle usage retrieved successfully",
                        200
                    );
                }
            }

            return ResponseHelper::SuccessResponse(
                $utilization->statusOn($vehicle->id, $from),
                "Vehicle status retrieved successfully",
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
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
            return ResponseHelper::fromException($e);
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
            return ResponseHelper::fromException($e);
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
            return ResponseHelper::fromException($e);
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
            return ResponseHelper::fromException($e);
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
            // `kind` + `ts` let these legacy sheet rows interleave with the workflow audit trail
            // below into one chronological timeline.
            $maintenanceLog = $sheetEvents
                ->map(fn ($m) => [
                    'kind'      => 'workshop',
                    'id'        => $m->id,
                    'ts'        => optional($m->out_date ?? $m->follow_date ?? $m->actual_in_date)->toIso8601String(),
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

            // Maintenance-Workflow audit trail (VehicleLogEvent) — the lifecycle transitions the team
            // now enters by hand (request → test drive → report → dispatch → repair → ready → close /
            // reopen). Tagged inspector vs garage. These are history only; the visit row owns cost.
            $logEvents = $vehicle->logEvents()
                ->with(['actor:id,name', 'linkedContract:id,contract_no', 'maintenance:id,maintenance_notes,garage_feedback'])
                ->get();

            // Video Evidence (the garage's repair clips / photos) lives on the ticket, keyed by
            // maintenance_id. Load it once and group so each lifecycle event can surface the media that
            // was captured for it — no N+1. Only the events where a clip/photo is actually taken carry it:
            // the garage-finished "Video Review" (ready), and per-fault "fixed" evidence (task_resolved).
            $mediaByTicket = MaintenanceMedia::query()
                ->whereIn('maintenance_id', $logEvents->pluck('maintenance_id')->filter()->unique()->all())
                ->get()
                ->groupBy('maintenance_id');
            $mediaEvents = [VehicleLogEvent::EVENT_READY, VehicleLogEvent::EVENT_TASK_RESOLVED];

            $workflowLog = $logEvents
                ->map(function ($e) use ($mediaByTicket, $mediaEvents) {
                    $meta = (array) ($e->meta ?? []);

                    // A task-scoped event shows only its own fault's clips; a ticket-wide event (mark-ready)
                    // shows the ticket-wide clips (task_id null). Signed viewUrl() so nothing is publicly listable.
                    $media = [];
                    if (in_array($e->event_type, $mediaEvents, true) && isset($mediaByTicket[$e->maintenance_id])) {
                        $media = $mediaByTicket[$e->maintenance_id]
                            ->filter(fn ($m) => $e->maintenance_task_id
                                ? (int) $m->maintenance_task_id === (int) $e->maintenance_task_id
                                : $m->maintenance_task_id === null)
                            ->map(fn ($m) => [
                                'id'   => $m->id,
                                'kind' => $m->kind,
                                'name' => $m->original_name,
                                'url'  => $m->viewUrl(),
                            ])
                            ->values()
                            ->all();
                    }

                    return [
                        'kind'        => 'workflow',
                        'id'          => $e->id,
                        'ticket_id'   => $e->maintenance_id,
                        'ts'          => optional($e->occurred_at)->toIso8601String(),
                        'date'        => optional($e->occurred_at)->toDateString(),
                        'event_type'  => $e->event_type,
                        'workflow_status' => $e->workflow_status,   // the exact lifecycle stage at the time — lets the timeline label the badge by stage
                        'source'      => $e->source_tag,            // inspector | garage
                        'description' => $e->description,
                        'meta'        => $meta ?: null,
                        'garage'      => $meta['garage'] ?? null,
                        'odometer'    => $meta['receive_odometer'] ?? $meta['dispatch_odometer'] ?? $meta['return_odometer'] ?? null,
                        'cost'        => $meta['cost'] ?? null,
                        // The event card's expandable "Show Faults (N)" list — kept as a list, never
                        // flattened into `description`, so a multi-fault ticket doesn't read as a paragraph.
                        'faults'      => $meta['faults'] ?? null,
                        // Workshop notes for the ticket itself — free text the garage/team logged (only
                        // set once the ticket is closed via composeClosingSummary, or live garage feedback).
                        'notes'       => $e->maintenance?->maintenance_notes ?: $e->maintenance?->garage_feedback,
                        'actor'       => $e->actor?->name,
                        'contract_id' => $e->linked_contract_id,
                        'contract_no' => $e->linkedContract?->contract_no,
                        'media'       => $media ?: null,
                    ];
                });

            // Driver follow-up notes — logged on the ticket itself (a JSON trail), not as log events.
            // The user wants these in the timeline too, so flatten each ticket's follow_ups into events.
            $followUps = Maintenance::workflowTickets()
                ->where('vehicle_id', $vehicle->id)
                ->whereNotNull('follow_ups')
                ->get(['id', 'follow_ups'])
                ->flatMap(fn ($t) => collect($t->follow_ups ?? [])->map(fn ($f) => [
                    'kind'        => 'workflow',
                    'id'          => 'fu-' . $t->id . '-' . ($f['at'] ?? ''),
                    'ts'          => $f['at'] ?? null,
                    'date'        => ! empty($f['at']) ? Carbon::parse($f['at'])->toDateString() : null,
                    'event_type'  => 'follow_up',
                    'source'      => 'garage',
                    'description' => $f['text'] ?? null,
                    'actor'       => $f['by'] ?? null,
                    'meta'        => null,
                ]));

            // One unified, chronological (newest-first) timeline: legacy sheet history + the workflow
            // audit trail + follow-ups, so the profile shows everything in a single place.
            $timeline = $maintenanceLog
                ->concat($workflowLog)
                ->concat($followUps)
                ->sortByDesc(fn ($i) => $i['ts'] ?? $i['date'] ?? '')
                ->values();

            // Workflow Journeys — the SAME VehicleLogEvent trail, but reshaped from a flat feed into
            // one bar-meter PER TICKET showing every workflow STAGE the car passed through and HOW LONG
            // it sat in each. The log stamps workflow_status on each transition; walking a ticket's
            // events in order and collapsing consecutive rows that share a workflow_status yields the
            // distinct stage segments, each timed to the next transition (the last open stage runs to now).
            // Sub-events with a null workflow_status (status pings, per-fault rows) don't open a stage —
            // they happen inside the current one. Terminal stages carry no running clock.
            $now = Carbon::now();
            $terminal = ['closed', 'diagnostic_cleared', 'complaint_resolved'];
            $journeys = $logEvents
                ->filter(fn ($e) => $e->maintenance_id && $e->occurred_at)
                ->groupBy('maintenance_id')
                ->map(function ($events, $ticketId) use ($now, $terminal) {
                    $ordered = $events->sortBy('occurred_at')->values();

                    // Collapse the ordered events into stage segments keyed by workflow_status.
                    $stages = [];
                    foreach ($ordered as $e) {
                        if ($e->workflow_status === null) {
                            continue; // a sub-event inside the current stage — doesn't open a new one
                        }
                        $last = empty($stages) ? null : $stages[count($stages) - 1];
                        if ($last && $last['workflow_status'] === $e->workflow_status) {
                            continue; // still the same stage — no new segment
                        }
                        $meta = (array) ($e->meta ?? []);
                        $stages[] = [
                            'workflow_status' => $e->workflow_status,
                            'event_type'      => $e->event_type,
                            'entered_at'      => $e->occurred_at->toIso8601String(),
                            '_entered'        => $e->occurred_at,          // kept for duration maths, stripped below
                            'actor'           => $e->actor?->name,
                            'source'          => $e->source_tag,
                            'garage'          => $meta['garage'] ?? null,
                            'description'     => $e->description,
                        ];
                    }

                    if (empty($stages)) {
                        return null; // a ticket whose events never stamped a workflow_status — nothing to chart
                    }

                    // Time each segment: from its entry to the NEXT stage's entry. The final stage runs to
                    // now while the ticket is live, or stops (no clock) once it reached a terminal status.
                    $count = count($stages);
                    $lastStatus = $stages[$count - 1]['workflow_status'];
                    $isOpen = ! in_array($lastStatus, $terminal, true);
                    foreach ($stages as $i => &$stage) {
                        $start = $stage['_entered'];
                        if ($i + 1 < $count) {
                            $end = $stages[$i + 1]['_entered'];
                        } else {
                            $end = $isOpen ? $now : null; // terminal stage → no running clock
                        }
                        $stage['seconds'] = $end ? max(0, $start->diffInSeconds($end)) : null;
                        unset($stage['_entered']);
                    }
                    unset($stage);

                    $openedAt = $stages[0]['entered_at'];
                    $totalSeconds = collect($stages)->sum(fn ($s) => $s['seconds'] ?? 0);

                    return [
                        'ticket_id'     => (int) $ticketId,
                        'opened_at'     => $openedAt,
                        'closed_at'     => $isOpen ? null : $stages[$count - 1]['entered_at'],
                        'is_open'       => $isOpen,
                        'current_stage' => $lastStatus,
                        'stage_count'   => $count,
                        'total_seconds' => $totalSeconds,
                        'stages'        => $stages,
                    ];
                })
                ->filter()
                ->sortByDesc('opened_at')
                ->values();

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
                // Unified history: sheet workshop events + manual workflow audit trail + follow-ups.
                'timeline' => $timeline,
                // Per-ticket stage meter: every workflow stage the car passed through + time in each.
                'workflow_journeys' => $journeys,
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
            return ResponseHelper::fromException($e);
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

        // A car designated Office / Personal use (from the fleet Status sheet overlay) is out of
        // the rental pool — its status governs even if OfficeManager left a rental contract open,
        // exactly like the left-fleet short-circuit above.
        if ($vehicle && $vehicle->status === 'office_use') {
            return [
                'state'            => 'office_use',
                'label'            => Vehicle::STATUS_LABELS['office_use'], // "Office Use"
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
            $data = $request->validated();

            // Odometer Modification Approval: a SIGNIFICANT edit (> OdometerChangeRequest::
            // SIGNIFICANT_DELTA_KM in either direction) is not applied here — it is filed for
            // admin review instead, carrying the mandatory reason note the frontend collects.
            // A small edit (typo-fix range) still applies immediately, same as always.
            if (array_key_exists('odometer', $data) && $data['odometer'] !== null) {
                $requested = (int) $data['odometer'];
                $previous  = $vehicle->odometer;

                if (\App\Models\OdometerChangeRequest::isSignificant($previous, $requested)) {
                    $note = trim((string) $request->input('odometer_change_note', ''));
                    if ($note === '') {
                        return ResponseHelper::FailureResponse(
                            null,
                            'This is a significant odometer change (more than ' . \App\Models\OdometerChangeRequest::SIGNIFICANT_DELTA_KM . ' km). Please enter a note explaining the change — it will be sent for admin approval.',
                            422
                        );
                    }

                    \App\Models\OdometerChangeRequest::create([
                        'vehicle_id'         => $vehicle->id,
                        'previous_odometer'  => $previous,
                        'requested_odometer' => $requested,
                        'delta'              => $requested - ($previous ?? 0),
                        'note'               => $note,
                        'workflow_stage'     => $vehicle->operational_status,
                        'status'             => \App\Models\OdometerChangeRequest::STATUS_PENDING,
                        'requested_by_id'    => optional($request->user())->id,
                        'requested_by'       => optional($request->user())->name,
                    ]);

                    // The rest of the edit (make/model/status/etc.) still applies now; only the
                    // odometer itself is held back pending approval.
                    unset($data['odometer']);
                    $vehicle = $this->vehicleService->update($data, $vehicle);

                    return ResponseHelper::SuccessResponse(
                        VehicleResource::make($vehicle),
                        'Vehicle updated. The odometer change was significant and has been sent for admin approval.',
                        200
                    );
                }
            }

            $vehicle = $this->vehicleService->update($data, $vehicle);
            $result = VehicleResource::make($vehicle);
            return ResponseHelper::SuccessResponse($result, "Vehicle updated successfully", 200);
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Set the car's Visual Condition Grade (Abu Marouf): green / orange / red, plus an
     * optional "Cosmetic Note". Green clears the note. Stamps who graded it and when so
     * the grade carries provenance. Gated by vehicles.manage.
     */
    public function updateCondition(Request $request, Vehicle $vehicle)
    {
        try {
            $data = $request->validate([
                'condition_grade' => ['required', Rule::in(Vehicle::CONDITION_GRADES)],
                'condition_note'  => ['nullable', 'string', 'max:1000'],
            ]);

            $previous = $vehicle->condition_grade;

            $vehicle->update([
                'condition_grade'     => $data['condition_grade'],
                // A Perfect car carries no cosmetic note; orange/red keep whatever was entered.
                'condition_note'      => $data['condition_grade'] === 'green' ? null : ($data['condition_note'] ?? null),
                'condition_graded_at' => now(),
                'condition_graded_by' => optional($request->user())->name,
            ]);

            // Audit the grade change on the vehicle's event trail (who / when / from → to). The
            // condition grade is the Damage Assessment pillar's verdict, so it belongs in the
            // readiness audit story. Best-effort — never blocks the grade update.
            if ($previous !== $data['condition_grade']) {
                app(\App\Services\VehicleLogService::class)->recordVehicle(
                    $vehicle,
                    \App\Models\VehicleLogEvent::EVENT_CONDITION_GRADED,
                    $request->user(),
                    [
                        'description' => 'Condition graded ' . ($previous ?: 'none') . ' → ' . $data['condition_grade'],
                        'meta'        => ['pillar' => 'Damage Assessment', 'from' => $previous, 'to' => $data['condition_grade'], 'note' => $data['condition_note'] ?? null],
                    ],
                );
            }

            return ResponseHelper::SuccessResponse(VehicleResource::make($vehicle->refresh()), "Condition grade updated", 200);
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Deferred Maintenance — manually raise the "owes maintenance" flag on a car that was pulled
     * out of the workshop for a customer. It is normally set automatically when such a car is rented
     * out; this endpoint is the manual override for the odd case the system didn't catch.
     */
    public function deferMaintenance(Request $request, Vehicle $vehicle, OperationsService $operations)
    {
        try {
            $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
            $operations->flagDeferredMaintenance(
                $vehicle,
                $data['note'] ?? $operations->deferredMaintenanceNote($vehicle),
                optional($request->user())->name,
            );

            return ResponseHelper::SuccessResponse(VehicleResource::make($vehicle->refresh()), "Flagged for deferred maintenance", 200);
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Deferred Maintenance — the supervisor's Resolve / Dismiss: clear the "owes maintenance" flag
     * (e.g. the car turned out not to need the shop after all, or it's already been sent back).
     */
    public function resolveDeferMaintenance(Request $request, Vehicle $vehicle, OperationsService $operations)
    {
        try {
            $operations->resolveDeferredMaintenance($vehicle);

            return ResponseHelper::SuccessResponse(VehicleResource::make($vehicle->refresh()), "Deferred maintenance cleared", 200);
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
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
            return ResponseHelper::fromException($e);
        }
    }
}
