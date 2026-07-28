<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A maintenance ticket moved to a new workflow_status (lane). Emitter: MaintenanceWorkflowService (Step 6). */
class MaintenanceLaneChanged implements OperationalEvent
{
    use Dispatchable;

    public function __construct(
        public int $maintenanceId,
        public int $vehicleId,
        public ?string $fromStatus = null,
        public ?string $toStatus = null,
        public ?int $actorId = null,
    ) {}
}
