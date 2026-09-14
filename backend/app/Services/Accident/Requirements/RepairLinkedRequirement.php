<?php

namespace App\Services\Accident\Requirements;

use App\Models\AccidentCase;
use App\Models\AccidentFinancialEntry;
use App\Models\AccidentStageCompletion;
use App\Models\AccidentWorkflowStage;
use App\Models\VehicleDocument;

/**
 * One gate a stage can carry. Part of the fixed vocabulary an admin chooses from — never a rule they
 * write. @see StageRequirement and \App\Services\Accident\RequirementRegistry for the whole set
 * and why it is closed.
 */
/** THE CAR IS IN THE WORKSHOP — an ordinary maintenance ticket is parented to this case. */
class RepairLinkedRequirement implements StageRequirement
{
    public function key(): string { return 'repair_linked'; }
    public function label(): string { return 'Repair raised'; }
    public function description(): string
    {
        return 'At least one maintenance ticket has been raised for this accident.';
    }
    public function configSchema(): array { return []; }

    public function isSatisfied(AccidentCase $case, AccidentWorkflowStage $stage): bool
    {
        return $case->repairs()->exists();
    }

    public function missing(AccidentCase $case, AccidentWorkflowStage $stage): string
    {
        return 'No repair has been raised for this accident yet.';
    }
}
