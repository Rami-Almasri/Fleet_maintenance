<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A required part was raised against a fault (part_requests row created). Emitter: PartWorkflowService (Step 6). */
class PartRequirementRaised implements OperationalEvent
{
    use Dispatchable;

    public function __construct(
        public int $partRequestId,
        public int $vehicleId,
        public ?int $maintenanceId = null,
        public ?int $maintenanceTaskId = null,
        public ?int $actorId = null,
    ) {}
}
