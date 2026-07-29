<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Requests\StoreServiceReminderRequest;
use App\Http\Requests\UpdateServiceReminderRequest;
use App\Http\Resources\ServiceReminderResource;
use App\Models\Maintenance;
use App\Models\ServiceReminder;
use App\Services\MaintenanceWorkflowService;
use App\Services\NotificationScanner;
use Illuminate\Http\Request;

/**
 * Technical maintenance Service Reminders (oil, filters, brakes, …). Auto reminders are
 * seeded by the service:sync-reminders command from the Oil Change sheet data; any edit
 * here flips the row to source='manual' so the seeder stops overwriting it. Live status
 * (overdue | due_soon | ok) reuses Vehicle::serviceStatus() maths via the model.
 */
class ServiceReminderController extends Controller
{
    /**
     * Past-due distance beyond which the reading is a data error, not a service need (a real
     * interval is 5–15k km). Used by dueByVehicle() to keep broken odometer anchors from
     * ranking above cars that genuinely need a wrench.
     */
    private const IMPLAUSIBLE_KM = 200000;


    /** List reminders. Filter by ?vehicle_id, ?source (auto|manual), ?status, ?active. */
    public function index(Request $request)
    {
        try {
            $reminders = ServiceReminder::query()
                ->with(['vehicle', 'notifier'])
                ->when($request->filled('vehicle_id'), fn ($q) => $q->where('vehicle_id', $request->integer('vehicle_id')))
                ->when($request->filled('source'), fn ($q) => $q->where('source', $request->string('source')))
                ->when($request->has('active'), fn ($q) => $q->where('active', $request->boolean('active')))
                ->orderByRaw('next_due_at IS NULL')
                ->orderBy('next_due_at')
                ->get();

            if ($request->filled('status')) {
                $status = (string) $request->string('status');
                $reminders = $reminders->filter(fn ($r) => $r->statusInfo()['status'] === $status)->values();
            }

            return ResponseHelper::SuccessResponse(
                ServiceReminderResource::collection($reminders),
                'Service reminders retrieved successfully',
                200
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Dashboard feed: "this car needs a check — oil, battery". One row PER CAR (not per reminder),
     * carrying only the services that are actually overdue or due soon, worst car first. Same
     * statusInfo() maths as the reminders board, just folded by vehicle so the dashboard can say
     * "plate X → Oil Change, Battery" in a single line.
     *
     * ?limit=N (default 8, max 50) caps the cars returned; `total_cars` reports the true count.
     */
    public function dueByVehicle(Request $request)
    {
        try {
            $limit = min(max($request->integer('limit', 8), 1), 50);

            $cars = ServiceReminder::query()
                ->with('vehicle')
                ->where('active', true)
                ->where('is_muted', false)
                ->get()
                // Only the two states that call for a wrench, and only reminders that still have a car.
                ->filter(function ($r) {
                    $status = $r->statusInfo()['status'];

                    return $r->vehicle && in_array($status, ['overdue', 'due_soon'], true);
                })
                ->groupBy('vehicle_id')
                ->map(function ($group) {
                    /** @var \App\Models\ServiceReminder $first */
                    $first    = $group->first();
                    $vehicle  = $first->vehicle;

                    $services = $group->map(function ($r) {
                        $status = $r->statusInfo();
                        $km     = $status['km_remaining'];

                        return [
                            'id'             => $r->id,
                            'service_type'   => $r->service_type,
                            'name'           => $r->displayName(),
                            'status'         => $status['status'],
                            'km_remaining'   => $km,
                            'days_remaining' => $status['days_remaining'],
                            // A car cannot genuinely be this far past a service interval — the odometer or
                            // the last-service anchor is wrong. Flagged so the UI shows "check odometer"
                            // instead of a fake number, and excluded from the ranking below.
                            'data_suspect'   => $km !== null && abs($km) > self::IMPLAUSIBLE_KM,
                        ];
                    })
                        // Overdue services lead the chip list, then the closest to due. Suspect
                        // readings sink to the end so real work is never buried under bad data.
                        ->sortBy(fn ($s) => [
                            $s['data_suspect'] ? 1 : 0,
                            $s['status'] === 'overdue' ? 0 : 1,
                            $s['km_remaining'] ?? PHP_INT_MAX,
                        ])
                        ->values();

                    $overdue = $services->where('status', 'overdue')->where('data_suspect', false);

                    return [
                        'vehicle_id'    => $vehicle->id,
                        'plate'         => $vehicle->plate_no ?: $vehicle->code,
                        'car'           => trim(($vehicle->make ?? '') . ' ' . ($vehicle->model ?? '')) ?: null,
                        'odometer'      => $vehicle->odometer,
                        'overdue_count' => $overdue->count(),
                        'due_count'     => $services->count(),
                        'suspect_count' => $services->where('data_suspect', true)->count(),
                        // How far past due the worst service on this car is — the ranking metric.
                        'worst_km_over' => $overdue->isNotEmpty()
                            ? (int) abs((int) $overdue->min('km_remaining'))
                            : 0,
                        'services'      => $services->all(),
                    ];
                })
                ->sortByDesc(fn ($c) => [$c['overdue_count'], $c['worst_km_over'], $c['due_count']])
                ->values();

            // Three DISJOINT buckets so the dashboard tally sums to total_cars: a car is counted
            // once, by its worst state. "suspect" is reserved for cars whose only due services are
            // un-trustable readings — they need a data fix, not a wrench.
            $overdueCars = $cars->where('overdue_count', '>', 0);
            $rest        = $cars->where('overdue_count', 0);
            $suspectCars = $rest->filter(fn ($c) => $c['due_count'] === $c['suspect_count']);

            return ResponseHelper::SuccessResponse([
                'items'         => $cars->take($limit)->all(),
                'total_cars'    => $cars->count(),
                'overdue_cars'  => $overdueCars->count(),
                'due_soon_cars' => $rest->count() - $suspectCars->count(),
                'suspect_cars'  => $suspectCars->count(),
            ], 'Cars needing service retrieved successfully', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    public function store(StoreServiceReminderRequest $request)
    {
        try {
            $data = $request->validated();

            // One reminder per car per service type (matches the unique index).
            $exists = ServiceReminder::where('vehicle_id', $data['vehicle_id'])
                ->where('service_type', $data['service_type'])
                ->exists();
            if ($exists) {
                return ResponseHelper::FailureResponse(
                    null,
                    'This vehicle already has a reminder for that service type — edit it instead.',
                    422
                );
            }

            $reminder = new ServiceReminder($data);
            $reminder->source = 'manual';   // hand-created → manual, protected from the auto-seed
            $reminder->active = $request->boolean('active', true);
            $reminder->recomputeNextDue();
            $reminder->save();

            return ResponseHelper::SuccessResponse(
                ServiceReminderResource::make($reminder->load('vehicle')),
                'Service reminder created successfully',
                201
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    public function show(ServiceReminder $serviceReminder)
    {
        try {
            return ResponseHelper::SuccessResponse(
                ServiceReminderResource::make($serviceReminder->load('vehicle')),
                'Service reminder retrieved successfully',
                200
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    public function update(UpdateServiceReminderRequest $request, ServiceReminder $serviceReminder)
    {
        try {
            $serviceReminder->fill($request->validated());
            $serviceReminder->source = 'manual';   // a human touched it → manual wins over the seeder
            $serviceReminder->recomputeNextDue();
            $serviceReminder->save();

            return ResponseHelper::SuccessResponse(
                ServiceReminderResource::make($serviceReminder->load('vehicle')),
                'Service reminder updated successfully',
                200
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * "Perform this service" — the ticket is now the single source of truth for all maintenance, so this
     * NO LONGER touches the vehicle or rolls the reminder directly. Instead it opens (or reuses) a
     * Maintenance Ticket seeded with this service, and the crew performs + closes it there. The reminder's
     * next-due point and the car's service record are advanced only when that ticket is CLOSED
     * (MaintenanceWorkflowService::confirmRoutineServices). No maintenance ever updates the vehicle
     * outside a completed ticket workflow.
     *
     *   Service Reminder → Maintenance Ticket → technician performs → Pending Confirmation → ticket closed → vehicle updated
     */
    public function complete(Request $request, ServiceReminder $serviceReminder, MaintenanceWorkflowService $workflow)
    {
        try {
            $request->validate(['odometer' => 'nullable|integer|min:0']);

            $serviceReminder->loadMissing('vehicle');
            $vehicle = $serviceReminder->vehicle;
            if (! $vehicle) {
                return ResponseHelper::FailureResponse(null, 'This reminder is not linked to a vehicle.', 422);
            }

            $label    = Maintenance::serviceLabelForType($serviceReminder->service_type) ?: $serviceReminder->displayName();
            $odometer = $request->filled('odometer') ? $request->integer('odometer') : $vehicle->odometer;

            $ticket = $workflow->openServiceTicket($vehicle, $label, $odometer, $request->user());

            return ResponseHelper::SuccessResponse([
                'reminder' => ServiceReminderResource::make($serviceReminder->fresh()->load('vehicle')),
                'ticket'   => [
                    'id'              => $ticket->id,
                    'workflow_status' => $ticket->workflow_status,
                    'url'             => '/maintenance-workflow/' . $ticket->id,
                ],
            ], 'Maintenance ticket opened for this service — the vehicle record updates once the ticket is closed.', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * "Active communication": fire an in-app alert to the fleet team (field drivers + technicians)
     * that this car needs a service, and stamp when/who so the UI can show a "Notified" chip and
     * disable the button. This replaces "Mark done" here — closing the loop happens in the ticket.
     */
    public function notify(Request $request, ServiceReminder $serviceReminder, NotificationScanner $scanner)
    {
        try {
            $serviceReminder->loadMissing('vehicle');
            $vehicle = $serviceReminder->vehicle;
            $status  = $serviceReminder->statusInfo();
            $service = $serviceReminder->displayName();
            $plate   = $vehicle?->plate_no ?: ('#' . $serviceReminder->vehicle_id);

            $payload = [
                'type'     => 'service_reminder_due',
                'category' => 'maintenance',
                'severity' => $status['status'] === 'overdue' ? 'critical' : 'warning',
                'title'    => "Service needed: {$service}",
                'body'     => "{$plate} — {$service} is " . strtolower($status['label']) . '. Please arrange the service.',
                'url'      => $serviceReminder->vehicle_id ? "/vehicles/{$serviceReminder->vehicle_id}" : '/inspections/schedules?tab=service',
                // Manual-channel key, distinct from the scanner's `service_reminder:{id}` so the two never collide.
                'key'      => 'service_reminder_manual:' . $serviceReminder->id,
                'icon'     => 'wrench',
                'meta'     => [
                    'plate'        => $vehicle?->plate_no,
                    'service_type' => $serviceReminder->service_type,
                    'reminder_id'  => $serviceReminder->id,
                ],
            ];

            // Field drivers (logistics.view) + technicians/inspectors (maintenance.initiate), deduped.
            $delivered = $scanner->notifyByAnyPermission(
                ['logistics.view', 'maintenance.initiate'],
                $payload,
                $request->user()?->id
            );

            $serviceReminder->last_notified_at = now();
            $serviceReminder->notified_by      = $request->user()?->id;
            $serviceReminder->notified_count   = (int) $serviceReminder->notified_count + 1;
            $serviceReminder->save();

            return ResponseHelper::SuccessResponse(
                ServiceReminderResource::make($serviceReminder->load(['vehicle', 'notifier'])),
                $delivered > 0
                    ? "Alert sent to {$delivered} team member" . ($delivered === 1 ? '' : 's')
                    : 'Reminder marked as notified (no driver/technician recipients are configured yet)',
                200
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    public function destroy(ServiceReminder $serviceReminder)
    {
        try {
            $serviceReminder->delete();

            return ResponseHelper::SuccessResponse(null, 'Service reminder deleted successfully', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
