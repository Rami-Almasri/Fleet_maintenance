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
 * THE POLICE REPORT — verified, or consciously waived with a reason.
 *
 * The waiver counts, and that is the point: a car scraped in our own yard has no report and never
 * will. What is forbidden is the SILENT version — advancing with the field simply left empty.
 * Replaces the hard-coded `awaiting_police` gate.
 */
class PoliceReportRequirement implements StageRequirement
{
    public function key(): string { return 'police_report'; }
    public function label(): string { return 'Police report verified or waived'; }
    public function description(): string
    {
        return 'The report must be recorded AND read against the file — or waived by somebody with '
             . 'the authority, giving a reason. Both count; leaving it blank does not.';
    }
    public function configSchema(): array { return []; }

    public function isSatisfied(AccidentCase $case, AccidentWorkflowStage $stage): bool
    {
        return $case->policeSatisfied();
    }

    public function missing(AccidentCase $case, AccidentWorkflowStage $stage): string
    {
        return $case->police_status === AccidentCase::POLICE_MISSING
            ? 'The police report is still outstanding. Record it, or waive it with a reason.'
            : 'The police report has been recorded but nobody has verified it against the file yet.';
    }
}
