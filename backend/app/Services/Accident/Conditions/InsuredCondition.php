<?php

namespace App\Services\Accident\Conditions;

use App\Models\AccidentCase;

/**
 * One answer to "does this stage apply to this case at all" — the fork, expressed as a condition on
 * the rung rather than a routing graph. @see \App\Services\Accident\ConditionRegistry.
 */
/** THERE IS AN INSURER IN THIS AT ALL. Skips the insurance rungs on an uninsured or self-borne case. */
class InsuredCondition implements StageCondition
{
    public function key(): string { return 'insured'; }
    public function label(): string { return 'Only if an insurer is involved'; }
    public function description(): string
    {
        return 'Skipped when no insurer or policy has been recorded on the case.';
    }

    public function applies(AccidentCase $case): bool
    {
        return $case->insurer_vendor_id !== null
            || ! empty($case->insurer_name)
            || ! empty($case->policy_no);
    }
}
