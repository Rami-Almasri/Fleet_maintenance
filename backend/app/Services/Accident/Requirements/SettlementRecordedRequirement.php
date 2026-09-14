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
 * THE MONEY HAS LANDED. Satisfied when nothing is outstanding — either because it was all paid, or
 * because the case never cost anything.
 */
class SettlementRecordedRequirement implements StageRequirement
{
    public function key(): string { return 'settlement_recorded'; }
    public function label(): string { return 'Nothing outstanding'; }
    public function description(): string
    {
        return 'Everything the repair actually cost has been paid or accounted for. Use sparingly — '
             . 'an accident whose settlement never arrives is a real ending, and this gate will hold '
             . 'the case open forever.';
    }
    public function configSchema(): array { return []; }

    public function isSatisfied(AccidentCase $case, AccidentWorkflowStage $stage): bool
    {
        $m = $case->financialBreakdown();

        return round($m['actual'] - $m['paid'], 2) <= 0;
    }

    public function missing(AccidentCase $case, AccidentWorkflowStage $stage): string
    {
        $m = $case->financialBreakdown();

        return sprintf('%s %s of what the repair cost is still unpaid.',
            $m['currency'], number_format($m['actual'] - $m['paid'], 2));
    }
}
