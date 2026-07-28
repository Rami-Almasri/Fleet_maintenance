<?php

namespace App\Listeners;

use App\Events\OperationalEvent;
use App\Models\Maintenance;
use App\Models\Vehicle;
use App\Services\VehicleLogService;

/**
 * Projects part-lifecycle domain events onto the vehicle timeline by composing the EXISTING
 * {@see VehicleLogService} (the canonical append-only writer). No new writer, no new derivation — it
 * maps each event to an EXISTING `VehicleLogEvent::EVENT_*` type and forwards.
 *
 * Phase 1, Step 5 (blueprint B2): DORMANT until Step 6 wires the emitters. When Step 6 emits these
 * events it also removes the equivalent direct `VehicleLogService` calls in `PartWorkflowService`, so
 * the write RELOCATES here rather than duplicating. Events without an existing timeline constant
 * (PartDelivered / PartDeliveryDelayed / MaintenanceLaneChanged) are intentionally not projected here —
 * their timeline vocabulary is decided alongside their emitters in Step 6/7.
 */
class TimelineProjector
{
    /**
     * Domain event → VehicleLogEvent type. EMPTY in Phase 1 (Step 6, Option B / DEBT-6): the rich
     * existing writer `PartWorkflowService::logVehicle()` keeps ownership of the parts timeline, so this
     * projector must NOT also write (that would double-write). It stays a structural placeholder; the
     * wholesale relocation happens at the DEBT-1 cutover.
     */
    private const EVENT_LOG = [];

    public function __construct(private readonly VehicleLogService $log) {}

    public function handle(OperationalEvent $event): void
    {
        $type = self::EVENT_LOG[$event::class] ?? null;
        if ($type === null) {
            return; // unmapped events get their timeline vocabulary in Step 6/7
        }

        // Ticket-scoped when we know the ticket; otherwise vehicle-scoped. Actor threading is added
        // with the emitters in Step 6 (null actor reads as "System" for now).
        if ($event->maintenanceId !== null && ($ticket = Maintenance::find($event->maintenanceId)) !== null) {
            $this->log->record($ticket, $type, null, ['source_tag' => 'parts']);

            return;
        }

        if (($vehicle = Vehicle::find($event->vehicleId)) !== null) {
            $this->log->recordVehicle($vehicle, $type, null, ['source_tag' => 'parts']);
        }
    }
}
