<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A fault (maintenance_task) was identified on a vehicle. Public platform event — emitter: MaintenanceTaskService (Step 6). */
class FaultIdentified implements OperationalEvent
{
    use Dispatchable;

    public function __construct(
        public int $maintenanceTaskId,
        public ?int $maintenanceId,
        public int $vehicleId,
        public ?int $actorId = null,
    ) {}
}
