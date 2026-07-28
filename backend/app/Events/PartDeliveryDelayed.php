<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A part's expected delivery date passed while still undelivered. Emitter: parts:scan command (Step 7). */
class PartDeliveryDelayed implements OperationalEvent
{
    use Dispatchable;

    public function __construct(
        public int $partPurchaseId,
        public int $partRequestId,
        public int $vehicleId,
        public ?int $maintenanceId = null,
        public ?string $expectedDeliveryDate = null,
    ) {}
}
