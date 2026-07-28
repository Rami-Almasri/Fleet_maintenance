<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A part was installed on the vehicle (installed_at set; cost bridged). Emitter: PartWorkflowService (Step 6). */
class PartInstalled implements OperationalEvent
{
    use Dispatchable;

    public function __construct(
        public int $partPurchaseId,
        public int $partRequestId,
        public int $vehicleId,
        public ?int $maintenanceId = null,
        public ?int $actorId = null,
    ) {}
}
