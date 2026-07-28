<?php

namespace App\Events;

/**
 * Marker for the Phase-1 point-in-time domain events (blueprint B2). Every operational event carries at
 * least a `vehicleId` and a nullable `maintenanceId` so the projection listeners can fan out generically.
 *
 * Phase 1 covers granular, point-in-time facts only. The state-CROSSING events
 * (RepairBlockedOnParts / RepairResumed / VehicleOperationalStateChanged) are deliberately absent —
 * they need a persisted before-state and are deferred to the DEBT-1/DEBT-5 cutover.
 */
interface OperationalEvent
{
}
