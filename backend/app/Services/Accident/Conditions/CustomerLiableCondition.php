<?php

namespace App\Services\Accident\Conditions;

use App\Models\AccidentCase;

/**
 * One answer to "does this stage apply to this case at all" — the fork, expressed as a condition on
 * the rung rather than a routing graph. @see \App\Services\Accident\ConditionRegistry.
 */
/**
 * THE VERDICT PUT THE MONEY ON THE RENTER. This is what makes "Charge the Customer" vanish from a
 * case somebody else caused, rather than sitting there permanently un-completable.
 *
 * `pending` deliberately counts as APPLICABLE: while nobody has ruled, the stage stays on the ladder
 * so the case can reach it. It disappears only once a verdict has actively cleared the customer.
 */
class CustomerLiableCondition implements StageCondition
{
    public function key(): string { return 'customer_liable'; }
    public function label(): string { return 'Only if the customer is liable'; }
    public function description(): string
    {
        return 'Skipped once liability has been decided against somebody else. Still shown while '
             . 'liability is undecided, so the case can reach the stage.';
    }

    public function applies(AccidentCase $case): bool
    {
        return in_array($case->liability_status, [
            AccidentCase::LIABILITY_PENDING,
            AccidentCase::LIABILITY_CUSTOMER,
            AccidentCase::LIABILITY_SHARED,
        ], true);
    }
}
