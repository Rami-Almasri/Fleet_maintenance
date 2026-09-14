<?php

namespace App\Services\Accident\Conditions;

use App\Models\AccidentCase;

/**
 * One answer to "does this stage apply to this case at all" — the fork, expressed as a condition on
 * the rung rather than a routing graph. @see \App\Services\Accident\ConditionRegistry.
 */
/**
 * THE CAR STILL MOVES. Note `=== true`: `drivable` is nullable, and "not assessed yet" is a third
 * answer that must not be mistaken for "yes". A case nobody has judged yet does not get a test drive
 * scheduled on the strength of a null.
 */
class VehicleDrivableCondition implements StageCondition
{
    public function key(): string { return 'vehicle_drivable'; }
    public function label(): string { return 'Only if the car can be driven'; }
    public function description(): string
    {
        return 'Skipped when the car cannot move, or when nobody has assessed it yet.';
    }
    public function applies(AccidentCase $case): bool { return $case->drivable === true; }
}
