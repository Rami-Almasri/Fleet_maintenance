<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A part was delivered to the workshop (delivered_at set). Emitter: PartWorkflowService/ProcurementService (Step 6). */
class PartDelivered implements OperationalEvent
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
