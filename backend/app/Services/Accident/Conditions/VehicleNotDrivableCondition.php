<?php

namespace App\Services\Accident\Conditions;

use App\Models\AccidentCase;

/**
 * One answer to "does this stage apply to this case at all" — the fork, expressed as a condition on
 * the rung rather than a routing graph. @see \App\Services\Accident\ConditionRegistry.
 */
/** THE CAR DOES NOT MOVE — it needs a truck. Again `=== false`, never "not yes". */
class VehicleNotDrivableCondition implements StageCondition
{
    public function key(): string { return 'vehicle_not_drivable'; }
    public function label(): string { return 'Only if the car cannot be driven'; }
    public function description(): string
    {
        return 'For recovery/towing steps. Skipped when the car can still be driven, or when nobody '
             . 'has assessed it yet.';
    }
    public function applies(AccidentCase $case): bool { return $case->drivable === false; }
}
