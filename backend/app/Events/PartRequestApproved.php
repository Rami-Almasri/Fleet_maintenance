<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A part request was approved to procure. Emitter: PartWorkflowService (Step 6). */
class PartRequestApproved implements OperationalEvent
{
    use Dispatchable;

    public function __construct(
        public int $partRequestId,
        public int $vehicleId,
        public ?int $maintenanceId = null,
        public ?int $actorId = null,
    ) {}
}
