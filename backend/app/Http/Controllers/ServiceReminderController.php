<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Requests\StoreServiceReminderRequest;
use App\Http\Requests\UpdateServiceReminderRequest;
use App\Http\Resources\ServiceReminderResource;
use App\Models\ServiceReminder;
use App\Services\NotificationScanner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
     * Mark this service as just performed: reset the anchors (date = today, odometer =
     * given or the car's current) and roll the next-due point forward.
     */
    public function complete(Request $request, ServiceReminder $serviceReminder)
    {
        try {
            $request->validate(['odometer' => 'nullable|integer|min:0']);

            $odometer = $request->filled('odometer')
                ? $request->integer('odometer')
                : $serviceReminder->vehicle?->odometer;

            $reminder = DB::transaction(function () use ($serviceReminder, $odometer) {
                // Oil change is the ONE service the vehicle-level serviceStatus() (and its
                // serviceDue / serviceDueSoon alerts) also tracks. Funnel it through the single
                // writer so the car's anchor moves too — otherwise "done" never clears the alert.
                if ($serviceReminder->service_type === 'oil_change'
                    && $serviceReminder->vehicle
                    && $odometer !== null) {
                    return $serviceReminder->vehicle->recordOilService($odometer);
                }

                // Any other service type: just roll this reminder's own due-point forward.
                $serviceReminder->last_service_at       = now()->toDateString();
                $serviceReminder->last_service_odometer = $odometer;
                $serviceReminder->source                = 'manual';
                $serviceReminder->recomputeNextDue();
                $serviceReminder->save();

                return $serviceReminder;
            });

            return ResponseHelper::SuccessResponse(
                ServiceReminderResource::make($reminder->load('vehicle')),
                'Service logged; next due point advanced',
                200
            );
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
