<?php

namespace App\Services\Accident\Conditions;

use App\Models\AccidentCase;

/**
 * One answer to "does this stage apply to this case at all" — the fork, expressed as a condition on
 * the rung rather than a routing graph. @see \App\Services\Accident\ConditionRegistry.
 */
/** THE CAR WAS ON HIRE. For steps that only exist when a renter had it. */
class OnRentalCondition implements StageCondition
{
    public function key(): string { return 'on_rental'; }
    public function label(): string { return 'Only if the car was on hire'; }
    public function description(): string
    {
        return 'Skipped for accidents on a car that was not out with a customer.';
    }

    public function applies(AccidentCase $case): bool
    {
        return $case->wasWithCustomer();
    }
}
