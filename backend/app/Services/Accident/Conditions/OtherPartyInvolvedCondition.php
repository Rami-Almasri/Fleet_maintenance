<?php

namespace App\Services\Accident\Conditions;

use App\Models\AccidentCase;

/**
 * One answer to "does this stage apply to this case at all" — the fork, expressed as a condition on
 * the rung rather than a routing graph. @see \App\Services\Accident\ConditionRegistry.
 */
/** OTHER PARTY INVOLVED — for recovery of costs from a third party, chasing their insurer, etc. */
class OtherPartyInvolvedCondition implements StageCondition
{
    public function key(): string { return 'other_party_involved'; }
    public function label(): string { return 'Only if another party was involved'; }
    public function description(): string
    {
        return 'For steps that only make sense when somebody else was in the accident.';
    }

    public function applies(AccidentCase $case): bool
    {
        return (bool) $case->other_party_involved;
    }
}
