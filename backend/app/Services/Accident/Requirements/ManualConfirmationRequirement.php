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
 * SOMEBODY CONFIRMED IT. The generic gate, and the one that makes a brand-new stage work with no
 * code: Management Approval, Recovery Arranged, Test Drive Done are all really "a person did this
 * and says so". Satisfied by a row in accident_stage_completions, which carries their name.
 */
class ManualConfirmationRequirement implements StageRequirement
{
    public function key(): string { return 'manual_confirmation'; }
    public function label(): string { return 'Someone must confirm this stage'; }
    public function description(): string
    {
        return 'A person with permission marks the stage done and may leave a note. Use this for any '
             . 'step the system cannot observe for itself — a recovery truck booked, a test drive '
             . 'completed, a manager’s approval.';
    }
    public function configSchema(): array { return []; }

    public function isSatisfied(AccidentCase $case, AccidentWorkflowStage $stage): bool
    {
        return AccidentStageCompletion::where('accident_case_id', $case->id)
            ->where('stage_key', $stage->key)->exists();
    }

    public function missing(AccidentCase $case, AccidentWorkflowStage $stage): string
    {
        return 'Nobody has confirmed “' . $stage->label . '” yet.';
    }
}
