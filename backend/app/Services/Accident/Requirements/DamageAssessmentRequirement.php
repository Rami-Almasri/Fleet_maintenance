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
/** THE DAMAGE HAS BEEN LOOKED AT and written down. Replaces the completeAssessment refusal. */
class DamageAssessmentRequirement implements StageRequirement
{
    public function key(): string { return 'damage_assessment'; }
    public function label(): string { return 'Damage assessment completed'; }
    public function description(): string
    {
        return 'Somebody has looked at the car and recorded what is broken. An assessment with no '
             . 'damage items is a button press, not an assessment.';
    }
    public function configSchema(): array { return []; }

    public function isSatisfied(AccidentCase $case, AccidentWorkflowStage $stage): bool
    {
        return $case->assessed_at !== null && $case->damageItems()->exists();
    }

    public function missing(AccidentCase $case, AccidentWorkflowStage $stage): string
    {
        return $case->damageItems()->exists()
            ? 'The damage is recorded but the assessment has not been completed.'
            : 'No damage has been recorded yet — even “no visible damage” is a finding worth writing down.';
    }
}
