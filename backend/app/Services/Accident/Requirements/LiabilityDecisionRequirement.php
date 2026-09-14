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
/** WHOSE FAULT IT WAS. `unknown` counts — it is a decided answer. `pending` is not. */
class LiabilityDecisionRequirement implements StageRequirement
{
    public function key(): string { return 'liability_decision'; }
    public function label(): string { return 'Liability decided'; }
    public function description(): string
    {
        return 'A named person has ruled on who was at fault. “Unknown” is an acceptable answer; '
             . 'leaving it undecided is not.';
    }
    public function configSchema(): array { return []; }

    public function isSatisfied(AccidentCase $case, AccidentWorkflowStage $stage): bool
    {
        return $case->liabilityDecided();
    }

    public function missing(AccidentCase $case, AccidentWorkflowStage $stage): string
    {
        return 'Nobody has decided who was at fault yet.';
    }
}
