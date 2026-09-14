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
/**
 * THE WHOLE VOCABULARY OF GATES, in one file.
 *
 * One small class per rule, deliberately kept together: the set is the contract, and a reader needs
 * to see all of it at once to judge whether the workflow is safe. Scattering ten four-line classes
 * across ten files would hide the only thing that matters here — what the office is allowed to
 * choose from.
 *
 * Every one of these replaces an `if` that used to be bound to a stage NAME inside
 * AccidentCaseService. The behaviour is unchanged; what changed is that the rule now travels with
 * the stage instead of with its position.
 */

/** No gate. The stage is informational — leave it whenever you like. */
class NoRequirement implements StageRequirement
{
    public function key(): string { return 'none'; }
    public function label(): string { return 'No requirement'; }
    public function description(): string { return 'The case can move on from this stage at any time.'; }
    public function configSchema(): array { return []; }
    public function isSatisfied(AccidentCase $case, AccidentWorkflowStage $stage): bool { return true; }
    public function missing(AccidentCase $case, AccidentWorkflowStage $stage): string { return ''; }
}
