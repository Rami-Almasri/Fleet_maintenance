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
