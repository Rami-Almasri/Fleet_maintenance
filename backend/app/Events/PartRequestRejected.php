<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A part request was rejected. Emitter: PartWorkflowService (Step 6). */
class PartRequestRejected implements OperationalEvent
{
    use Dispatchable;

    public function __construct(
        public int $partRequestId,
        public int $vehicleId,
        public ?int $maintenanceId = null,
        public ?int $actorId = null,
        public ?string $reason = null,
    ) {}
}
