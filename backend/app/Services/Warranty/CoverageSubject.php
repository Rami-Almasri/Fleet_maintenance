<?php

namespace App\Services\Warranty;

use App\Models\MaintenanceTask;
use App\Models\PartRequest;
use App\Models\VehicleComponent;

/**
 * WHAT is being asked about — the thing a coverage question is a question ABOUT.
 *
 * A value object rather than four loose arguments, because the engine is called from four very
 * different doors (a purchase request, a fault on a ticket, a failed component, a spare-key need)
 * and each knows a different subset of the same four facts. Passing an array would mean every caller
 * inventing its own key names and the engine defensively checking for all of them; passing four
 * nullable parameters would mean four call sites full of `null, null, $id, null`.
 *
 * THE PART TYPE IS THE ONE THAT MATTERS. `catalogId` is a component_catalog id — the parts
 * vocabulary the request form, the purchase, the fitted component and the warranty's covered/excluded
 * lists ALL already speak. Coverage is decided by set membership against that vocabulary and by
 * nothing else: no text matching, no keyword similarity, no scoring. A subject with no catalog id
 * cannot be decided at all, and the engine says so rather than guessing from the wording — which is
 * why `partName` exists only to be shown to the human who then has to answer.
 *
 * @see \App\Services\PartIdentityService — the single answer to "are these the same part?", which is
 *      what stamps catalogId onto a request in the first place.
 */
final class CoverageSubject
{
    public function __construct(
        /** component_catalog id — the ONLY thing coverage can be decided from. Null ⇒ undecidable. */
        public readonly ?int $catalogId = null,
        /** How the person described it. For display and for the review card; never matched against. */
        public readonly ?string $partName = null,
        /** The fitted asset that failed, when there is one — unlocks its own component warranty. */
        public readonly ?int $componentId = null,
        /** The fault, when the question came off a ticket — unlocks the garage's repair warranty. */
        public readonly ?int $taskId = null,
        /** The ticket, kept so a case opened from here stays joined to the visit it belongs to. */
        public readonly ?int $maintenanceId = null,
    ) {}

    /** From a purchase request that is about to be created — the guard's door. */
    public static function fromRequestData(array $data): self
    {
        return new self(
            catalogId: isset($data['component_catalog_id']) ? (int) $data['component_catalog_id'] : null,
            partName: $data['part_name'] ?? null,
            componentId: isset($data['vehicle_component_id']) ? (int) $data['vehicle_component_id'] : null,
            taskId: isset($data['maintenance_task_id']) ? (int) $data['maintenance_task_id'] : null,
            maintenanceId: isset($data['maintenance_id']) ? (int) $data['maintenance_id'] : null,
        );
    }

    /** From a request that already exists. */
    public static function fromRequest(PartRequest $request): self
    {
        return new self(
            catalogId: $request->component_catalog_id ? (int) $request->component_catalog_id : null,
            partName: $request->part_name,
            taskId: $request->maintenance_task_id ? (int) $request->maintenance_task_id : null,
            maintenanceId: $request->maintenance_id ? (int) $request->maintenance_id : null,
        );
    }

    /** From a physical part that has failed. */
    public static function fromComponent(VehicleComponent $component): self
    {
        return new self(
            catalogId: $component->component_catalog_id ? (int) $component->component_catalog_id : null,
            partName: $component->label ?: $component->catalog?->name,
            componentId: (int) $component->id,
        );
    }

    /** From a fault on a ticket. */
    public static function fromTask(MaintenanceTask $task): self
    {
        return new self(
            partName: $task->symptom,
            taskId: (int) $task->id,
            maintenanceId: $task->maintenance_id ? (int) $task->maintenance_id : null,
        );
    }

    /**
     * Can this subject be decided from data at all?
     *
     * False means the answer can only ever be UNKNOWN — not because the engine is unsure, but
     * because nobody said which part this is. Worth asking separately so the review card can say
     * "pick the part type" rather than "review coverage", which is a different job.
     */
    public function isIdentified(): bool
    {
        return $this->catalogId !== null;
    }

    /** The words a human reads on the review card. Never used to decide anything. */
    public function describe(): string
    {
        return $this->partName ?: 'Unspecified item';
    }
}
