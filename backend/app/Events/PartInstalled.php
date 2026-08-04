<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A part was installed on the vehicle (installed_at set; cost bridged). Emitter: PartWorkflowService (Step 6). */
class PartInstalled implements OperationalEvent
{
    use Dispatchable;

    public function __construct(
        public int $partPurchaseId,
        // NULLABLE, because not every install comes from a request. Consumables (oil, coolant, a pack of
        // clips) are bought and fitted in one motion with nothing to approve, and a garage-supplied part
        // billed on the ticket never passes through procurement at all. Requiring an id here made those
        // installs throw a TypeError at the dispatch, AFTER the part had been billed and the timeline
        // written — the work was done and recorded, and only the event failed.
        public ?int $partRequestId,
        public int $vehicleId,
        public ?int $maintenanceId = null,
        public ?int $actorId = null,
    ) {}
}
