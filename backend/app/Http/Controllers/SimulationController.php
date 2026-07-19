<?php

namespace App\Http\Controllers;

use App\Models\Maintenance;
use App\Models\ServiceReminder;
use App\Models\SimulationEvent;
use App\Models\Vehicle;
use App\Services\MaintenanceWorkflowService;
use App\Services\NotificationScanner;
use App\Services\OperationsService;
use App\Services\PlateResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Demo / Simulation Panel — the admin-only "watch the system react" console.
 *
 * Two buttons force a REAL live condition on a REAL vehicle so the team can watch the system's
 * own pipeline handle it end-to-end, rather than looking at a faked screenshot:
 *
 *   • oilAlert       → forces the strict km "Service Due" condition on a car, then runs the real
 *                      NotificationScanner so the genuine oil-change alert lands in the bell with
 *                      its deep-link into the Log-Oil-Change flow.
 *   • faultDiscovery → files a real Complaint ticket (openComplaint) so the car appears in the
 *                      Inspector's Pad / Maintenance-Workflow board exactly as a live report would.
 *
 * SAFETY — nothing here is permanent:
 *   1. Every mutating action hard-refuses unless config('features.demo_mode') is on (403).
 *   2. Every action journals what it changed in `simulation_events` with an exact snapshot.
 *   3. reset() replays that journal in reverse to restore the fleet byte-for-byte.
 *
 * Routes are additionally gated to `permission:users.manage` (admins only) in routes/api.php.
 */
class SimulationController extends Controller
{
    /** Fallback service interval (km) when the car has none on file, so the demo always has numbers. */
    private const DEFAULT_INTERVAL_KM = 10000;
    /** How far past the interval the forced condition sits — 2,000 km reads as a clear "overdue". */
    private const OVERDUE_MARGIN_KM = 2000;
    /** Synthetic odometer for a car that has no reading yet, so serviceStatus() can compute. */
    private const FALLBACK_ODOMETER = 60000;

    public function __construct(
        private MaintenanceWorkflowService $workflow,
        private NotificationScanner $scanner,
        private OperationsService $operations,
    ) {}

    /**
     * Panel bootstrap — safe to call whether or not Demo Mode is on (it never mutates).
     * Returns the current arm-state, the live simulation journal, and a suggested target car.
     */
    public function status(Request $request)
    {
        $active = SimulationEvent::with('vehicle:id,code,make,model,plate_no')
            ->latest('id')
            ->get()
            ->map(fn (SimulationEvent $e) => $this->presentEvent($e));

        $suggested = $this->pickActiveVehicle(requireOdometer: true)
            ?? $this->pickActiveVehicle(requireOdometer: false);

        return response()->json([
            'success' => true,
            'message' => 'Simulation status',
            'data'    => [
                'demo_mode'         => (bool) config('features.demo_mode'),
                'active'            => $active,
                'active_count'      => $active->count(),
                'suggested_vehicle' => $suggested ? $this->presentVehicle($suggested) : null,
            ],
        ]);
    }

    /**
     * TRIGGER OIL ALERT — force the "Service Due" oil condition on one car, then let the real
     * scanner raise the genuine alert. Snapshots the exact prior values so Reset is lossless.
     */
    public function oilAlert(Request $request)
    {
        $this->assertDemoMode();

        $data = $request->validate([
            'vehicle' => ['nullable', 'string'], // id or plate_no; omitted → auto-pick an active car
        ]);

        $vehicle = $this->resolveVehicle($data['vehicle'] ?? null, requireOdometer: true);

        return DB::transaction(function () use ($vehicle, $request) {
            // 1) Snapshot every column we are about to overwrite — this IS the undo record.
            $snapshot = [
                'odometer'              => $vehicle->odometer,
                'last_service_odometer' => $vehicle->last_service_odometer,
                'service_interval_km'   => $vehicle->service_interval_km,
                'service_synced_at'     => optional($vehicle->service_synced_at)?->toDateTimeString(),
            ];

            // 2) Force the condition: distance (odometer − last) = interval + margin → clearly overdue.
            $interval = $vehicle->service_interval_km ?: self::DEFAULT_INTERVAL_KM;
            $current  = $vehicle->odometer ?: self::FALLBACK_ODOMETER;
            $baseline = max(0, $current - $interval - self::OVERDUE_MARGIN_KM);

            $vehicle->odometer              = $current;
            $vehicle->service_interval_km   = $interval;
            $vehicle->last_service_odometer = $baseline;
            $vehicle->service_synced_at     = now();
            $vehicle->save();

            // 3) Keep the recurring oil_change reminder row in step (if the car has one), so the
            //    /reminders + Fleet Health surfaces agree with the vehicle-level status. Snapshot it too.
            $reminder = $vehicle->serviceReminders()->where('service_type', 'oil_change')->first();
            if ($reminder) {
                $snapshot['reminder'] = [
                    'id'                    => $reminder->id,
                    'last_service_odometer' => $reminder->last_service_odometer,
                    'interval_km'           => $reminder->interval_km,
                    'next_due_odometer'     => $reminder->next_due_odometer,
                    'last_service_at'       => optional($reminder->last_service_at)?->toDateString(),
                    'source'                => $reminder->source,
                ];
                $reminder->last_service_odometer = $baseline;
                $reminder->interval_km           = $interval;
                $reminder->recomputeNextDue();
                $reminder->save();
            }

            $vehicle->refresh();
            $service = $vehicle->serviceStatus();

            // 4) Journal the change so Reset can undo it exactly.
            $event = SimulationEvent::create([
                'scenario'       => SimulationEvent::SCENARIO_OIL,
                'vehicle_id'     => $vehicle->id,
                'snapshot'       => $snapshot,
                'label'          => 'Forced Service Due · ' . number_format($service['overdue_km'] ?? 0) . ' km over on ' . $this->label($vehicle),
                'created_by'     => $request->user()?->id,
            ]);

            // 5) Run the REAL scanner so the genuine service_due alert reaches the bell now.
            $scan = $this->scanner->scan();

            return response()->json([
                'success' => true,
                'message' => 'Service Due forced — the scanner has raised the oil-change alert. Open the bell 🔔',
                'data'    => [
                    'event'          => $this->presentEvent($event->load('vehicle:id,code,make,model,plate_no')),
                    'vehicle'        => $this->presentVehicle($vehicle),
                    'service_status' => $service,
                    'deep_link'      => '/vehicles/' . $vehicle->id . '?logOil=1',
                    'scan'           => $scan,
                ],
            ], 201);
        });
    }

    /**
     * TRIGGER FAULT DISCOVERY — file a real Complaint ticket so the car flows into the Inspector's Pad
     * / Maintenance-Workflow board exactly as a live customer report would. The ticket id is journaled
     * so Reset deletes precisely this ticket (and only this one).
     */
    public function faultDiscovery(Request $request)
    {
        $this->assertDemoMode();

        $data = $request->validate([
            'vehicle'  => ['nullable', 'string'], // id or plate_no; omitted → auto-pick an active car
            'severity' => ['nullable', 'in:critical,moderate,routine'],
        ]);

        $vehicle  = $this->resolveVehicle($data['vehicle'] ?? null, requireOdometer: false);
        $severity = $data['severity'] ?? 'moderate';

        return DB::transaction(function () use ($vehicle, $severity, $request) {
            $ticket = $this->workflow->openComplaint([
                'vehicle_id'        => $vehicle->id,
                'fault_description' => '[DEMO] Customer reports a rattling noise and a dashboard warning light — logged from the Simulation Panel.',
                'fault_severity'    => $severity,
            ], $request->user());

            $event = SimulationEvent::create([
                'scenario'       => SimulationEvent::SCENARIO_FAULT,
                'vehicle_id'     => $vehicle->id,
                'maintenance_id' => $ticket->id,
                'label'          => 'Filed fault ticket #' . $ticket->id . ' (' . $severity . ') on ' . $this->label($vehicle),
                'created_by'     => $request->user()?->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Fault discovered — a maintenance ticket is now in the Supervisors\' dispatch queue.',
                'data'    => [
                    'event'           => $this->presentEvent($event->load('vehicle:id,code,make,model,plate_no')),
                    'vehicle'         => $this->presentVehicle($vehicle),
                    'ticket_id'       => $ticket->id,
                    'workflow_status' => $ticket->workflow_status,
                    'deep_links'      => [
                        'ticket'        => '/maintenance-workflow/' . $ticket->id,
                        'board'         => '/maintenance-workflow',
                        'inspector_pad' => '/inspector-pad',
                    ],
                ],
            ], 201);
        });
    }

    /**
     * RESET — replay the journal in reverse and put the fleet back exactly as it was. Optionally scope
     * to one journal row (?event=ID); otherwise rolls back everything the panel has done.
     */
    public function reset(Request $request)
    {
        $this->assertDemoMode();

        $data = $request->validate([
            'event' => ['nullable', 'integer'], // roll back one journal row; omitted → roll back all
        ]);

        $query = SimulationEvent::query()->latest('id');
        if (! empty($data['event'])) {
            $query->whereKey($data['event']);
        }
        $events = $query->get();

        if ($events->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => 'Nothing to reset — the simulation journal is already clean.',
                'data'    => ['reverted' => 0],
            ]);
        }

        $reverted = ['oil_alert' => 0, 'fault_discovery' => 0];

        DB::transaction(function () use ($events, &$reverted) {
            foreach ($events as $event) {
                if ($event->scenario === SimulationEvent::SCENARIO_OIL) {
                    $this->revertOil($event);
                    $reverted['oil_alert']++;
                } elseif ($event->scenario === SimulationEvent::SCENARIO_FAULT) {
                    $this->revertFault($event);
                    $reverted['fault_discovery']++;
                }
                $event->delete();
            }
        });

        // Let the scanner auto-resolve any now-stale service_due alert it raised for the demo cars.
        $scan = $this->scanner->scan();

        return response()->json([
            'success' => true,
            'message' => 'Reset complete — the fleet is back to its pre-demo state.',
            'data'    => ['reverted' => array_sum($reverted), 'breakdown' => $reverted, 'scan' => $scan],
        ]);
    }

    /* --------------------------------------------------------------------- helpers */

    private function assertDemoMode(): void
    {
        abort_unless(
            (bool) config('features.demo_mode'),
            403,
            'Demo Mode is OFF. Set FEATURE_DEMO_MODE=true in the backend .env to arm the Simulation Panel.'
        );
    }

    /** Restore the vehicle (and its oil reminder) to the exact values captured before the oil scenario. */
    private function revertOil(SimulationEvent $event): void
    {
        $snap = $event->snapshot ?? [];
        if ($vehicle = Vehicle::find($event->vehicle_id)) {
            $vehicle->odometer              = $snap['odometer'] ?? $vehicle->odometer;
            $vehicle->last_service_odometer = $snap['last_service_odometer'] ?? null;
            $vehicle->service_interval_km   = $snap['service_interval_km'] ?? null;
            $vehicle->service_synced_at     = $snap['service_synced_at'] ?? null;
            $vehicle->save();
        }

        if (! empty($snap['reminder']['id']) && ($reminder = ServiceReminder::find($snap['reminder']['id']))) {
            $reminder->last_service_odometer = $snap['reminder']['last_service_odometer'] ?? null;
            $reminder->interval_km           = $snap['reminder']['interval_km'] ?? null;
            $reminder->next_due_odometer     = $snap['reminder']['next_due_odometer'] ?? null;
            $reminder->last_service_at       = $snap['reminder']['last_service_at'] ?? null;
            $reminder->source                = $snap['reminder']['source'] ?? 'auto';
            $reminder->save();
        }
    }

    /**
     * Delete exactly the ticket this scenario created and return its car to normal. Mirrors the ordering
     * in maintenance:reset-workflow (logistics + media have no cascading FK), scoped to the one ticket.
     */
    private function revertFault(SimulationEvent $event): void
    {
        $ticketId = $event->maintenance_id;
        if (! $ticketId) {
            return;
        }

        $logisticsIds = DB::table('logistics_tasks')->where('maintenance_id', $ticketId)->pluck('id')->all();
        if ($logisticsIds) {
            DB::table('logistics_task_events')->whereIn('logistics_task_id', $logisticsIds)->delete();
            DB::table('logistics_tasks')->whereIn('id', $logisticsIds)->delete();
        }
        DB::table('maintenance_media')->where('maintenance_id', $ticketId)->delete();

        // Cascades: maintenance_tasks (+ assignments), maintenance_line_items, maintenance_watchers.
        // Nulls: vehicle_log_events.maintenance_id (the audit trail is preserved).
        Maintenance::whereKey($ticketId)->delete();

        if ($vehicle = Vehicle::find($event->vehicle_id)) {
            $this->operations->reconcileVehicleOperationalStatus($vehicle);
        }
    }

    /** Resolve the target car: an explicit id/plate, or auto-pick a sensible active-fleet vehicle. */
    private function resolveVehicle(?string $ref, bool $requireOdometer): Vehicle
    {
        if ($ref !== null && trim($ref) !== '') {
            $ref = trim($ref);
            // Plate first (via the shared resolver, so a reused plate lands on the current car),
            // then the internal code as a fallback for non-plate refs.
            $vehicle = is_numeric($ref)
                ? Vehicle::find((int) $ref)
                : (PlateResolver::resolve($ref) ?? Vehicle::where('code', $ref)->first());
            abort_unless($vehicle, 404, "No vehicle matches '{$ref}' (try an id, plate, or code).");
            return $vehicle;
        }

        $vehicle = $this->pickActiveVehicle($requireOdometer)
            ?? ($requireOdometer ? $this->pickActiveVehicle(false) : null);

        abort_unless($vehicle, 422, 'No active-fleet vehicle available to run the simulation on.');
        return $vehicle;
    }

    /** An active (ready/rented) car — optionally one that already has an odometer reading. */
    private function pickActiveVehicle(bool $requireOdometer): ?Vehicle
    {
        $q = Vehicle::whereIn('status', Vehicle::ACTIVE_STATUSES)->orderBy('code');
        if ($requireOdometer) {
            $q->whereNotNull('odometer')->where('odometer', '>', 0);
        }
        return $q->first();
    }

    private function label(Vehicle $v): string
    {
        return trim(($v->code ? '#' . $v->code . ' ' : '') . trim($v->make . ' ' . $v->model)
            . ($v->plate_no ? ' (' . $v->plate_no . ')' : '')) ?: ('Vehicle #' . $v->id);
    }

    private function presentVehicle(Vehicle $v): array
    {
        return [
            'id'       => $v->id,
            'code'     => $v->code,
            'plate_no' => $v->plate_no,
            'label'    => $this->label($v),
        ];
    }

    private function presentEvent(SimulationEvent $e): array
    {
        return [
            'id'             => $e->id,
            'scenario'       => $e->scenario,
            'label'          => $e->label,
            'vehicle'        => $e->vehicle ? $this->presentVehicle($e->vehicle) : null,
            'maintenance_id' => $e->maintenance_id,
            'created_at'     => optional($e->created_at)?->toDateTimeString(),
        ];
    }
}
