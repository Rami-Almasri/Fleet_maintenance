<?php

namespace App\Services\Knowledge;

use App\Models\MaintenanceTask;
use App\Models\Vehicle;

/**
 * The immutable input to the Knowledge Engine's retrieval. Deliberately decoupled from any Eloquent row
 * so it works BOTH for an existing fault (built from a MaintenanceTask) AND at registration time, BEFORE
 * a task row exists (built from a raw {vehicle, symptom} the inspector is typing) — see
 * RepairIntelligenceController::preview.
 *
 * It carries the vehicle identity (id + make + model — the tier anchors) and the fault identity in
 * precedence order (fault_catalog_id → category_key → symptom), plus the rows to exclude so a fault never
 * matches itself or its own ticket.
 */
final class SimilarRepairQuery
{
    /** @param array<int,string> $nothing reserved */
    public function __construct(
        public readonly ?int $vehicleId,
        public readonly ?string $make,
        public readonly ?string $model,
        public readonly ?string $categoryKey = null,
        public readonly ?int $faultCatalogId = null,
        public readonly ?string $symptom = null,
        public readonly ?int $excludeTaskId = null,
        public readonly ?int $excludeMaintenanceId = null,
    ) {
    }

    /** Build from an existing fault (the /{task}/repair-intelligence endpoint). */
    public static function fromTask(MaintenanceTask $task): self
    {
        $vehicle = $task->relationLoaded('vehicle') ? $task->vehicle : $task->vehicle()->first();

        return new self(
            vehicleId: $task->vehicle_id,
            make: $vehicle?->make,
            model: $vehicle?->model,
            categoryKey: $task->category_key,
            faultCatalogId: $task->fault_catalog_id,
            symptom: $task->symptom,
            excludeTaskId: $task->id,
            excludeMaintenanceId: $task->maintenance_id,
        );
    }

    /** Build from a raw vehicle + typed symptom (the /preview endpoint, before a task exists). */
    public static function fromVehicleAndSymptom(
        Vehicle $vehicle,
        ?string $symptom = null,
        ?string $categoryKey = null,
        ?int $faultCatalogId = null,
    ): self {
        return new self(
            vehicleId: $vehicle->id,
            make: $vehicle->make,
            model: $vehicle->model,
            categoryKey: $categoryKey,
            faultCatalogId: $faultCatalogId,
            symptom: $symptom,
        );
    }

    /** Does the query name a fault at all? (No fault identity ⇒ nothing to match on.) */
    public function hasFaultIdentity(): bool
    {
        return $this->faultCatalogId !== null
            || ($this->categoryKey !== null && $this->categoryKey !== '')
            || ($this->symptom !== null && trim($this->symptom) !== '');
    }
}
