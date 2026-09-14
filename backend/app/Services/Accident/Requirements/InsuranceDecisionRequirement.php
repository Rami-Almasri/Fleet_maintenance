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
/** THE INSURER ANSWERED — approved, partly approved, rejected or closed. Silence is not an answer. */
class InsuranceDecisionRequirement implements StageRequirement
{
    public function key(): string { return 'insurance_decision'; }
    public function label(): string { return 'Insurer has answered'; }
    public function description(): string
    {
        return 'The claim has reached a decision — approved, partially approved, rejected or closed. '
             . 'A claim still under review has not answered.';
    }
    public function configSchema(): array { return []; }

    public function isSatisfied(AccidentCase $case, AccidentWorkflowStage $stage): bool
    {
        return in_array($case->claim_status, [
            AccidentCase::CLAIM_APPROVED, AccidentCase::CLAIM_PARTIALLY_APPROVED,
            AccidentCase::CLAIM_REJECTED, AccidentCase::CLAIM_CLOSED,
        ], true);
    }

    public function missing(AccidentCase $case, AccidentWorkflowStage $stage): string
    {
        return 'The insurer has not given a decision yet (currently: '
             . str_replace('_', ' ', (string) $case->claim_status) . ').';
    }
}
