<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A purchase order was issued for a part (part_purchases row created; ordered_at set). Emitter: PartWorkflowService/ProcurementService (Step 6). */
class PurchaseOrderIssued implements OperationalEvent
{
    use Dispatchable;

    public function __construct(
        public int $partPurchaseId,
        public int $partRequestId,
        public int $vehicleId,
        public ?int $maintenanceId = null,
        public ?string $expectedDeliveryDate = null,
        public ?int $actorId = null,
    ) {}
}
