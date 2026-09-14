<?php

namespace App\Services\Accident\Conditions;

use App\Models\AccidentCase;

/**
 * One answer to "does this stage apply to this case at all" — the fork, expressed as a condition on
 * the rung rather than a routing graph. @see \App\Services\Accident\ConditionRegistry.
 */
/** Every case climbs this rung. The default. */
class AlwaysCondition implements StageCondition
{
    public function key(): string { return 'always'; }
    public function label(): string { return 'Always'; }
    public function description(): string { return 'This stage is part of every accident.'; }
    public function applies(AccidentCase $case): bool { return true; }
}
