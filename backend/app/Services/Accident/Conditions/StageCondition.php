<?php

namespace App\Services\Accident\Conditions;

use App\Models\AccidentCase;

/**
 * One answer to "does this stage apply to this case at all" — the fork, expressed as a condition on
 * the rung rather than a routing graph. @see \App\Services\Accident\ConditionRegistry.
 */
/**
 * WHETHER A STAGE APPLIES TO THIS CASE AT ALL — the fork, without a graph engine.
 *
 * The real process branches on FACTS about the car, not on arbitrary routing:
 *
 *   "Then Abu Maroof does a test drive IF IT CAN MOVE, then maintenance.
 *    IF IT CANNOT MOVE — recovery, then maintenance."
 *
 * Both arms rejoin at repair. Modelling that as a routing graph would buy nothing and cost a great
 * deal: an admin would have to draw edges, and every stage would need a "where do I go next" answer
 * for every outcome. Expressed as a CONDITION on the rung instead, the ladder stays a list — Recovery
 * simply is not part of a drivable car's ladder, and Test Drive is not part of a wrecked one's.
 *
 * "Not applicable" is drawn differently from "not done yet", because they are different states and
 * colouring them the same is how somebody loses confidence in the board.
 *
 * Same discipline as the requirements: a fixed vocabulary, never an expression.
 */
interface StageCondition
{
    public function key(): string;
    public function label(): string;
    public function description(): string;
    public function applies(AccidentCase $case): bool;
}
