<?php

namespace App\Services\Accident;

use App\Services\Accident\Conditions\AlwaysCondition;
use App\Services\Accident\Conditions\CustomerLiableCondition;
use App\Services\Accident\Conditions\InsuredCondition;
use App\Services\Accident\Conditions\OnRentalCondition;
use App\Services\Accident\Conditions\OtherPartyInvolvedCondition;
use App\Services\Accident\Conditions\StageCondition;
use App\Services\Accident\Conditions\VehicleDrivableCondition;
use App\Services\Accident\Conditions\VehicleNotDrivableCondition;

/**
 * THE LIST OF "WHEN DOES THIS STAGE APPLY" ANSWERS an admin may choose from.
 *
 * Same contract as the requirement registry, including the forgiving `get()`: an unknown condition
 * key degrades to `always`, so a stale key makes a stage MORE visible rather than silently removing
 * it from somebody's ladder. When in doubt, show the work.
 */
class ConditionRegistry
{
    /** @var array<string, StageCondition>|null */
    private ?array $map = null;

    /** @return array<string, StageCondition> */
    public function all(): array
    {
        return $this->map ??= collect([
            new AlwaysCondition(),
            new VehicleDrivableCondition(),
            new VehicleNotDrivableCondition(),
            new CustomerLiableCondition(),
            new InsuredCondition(),
            new OtherPartyInvolvedCondition(),
            new OnRentalCondition(),
        ])->keyBy(fn (StageCondition $c) => $c->key())->all();
    }

    public function get(string $key): StageCondition
    {
        return $this->all()[$key] ?? new AlwaysCondition();
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    public function keys(): array
    {
        return array_keys($this->all());
    }

    public function options(): array
    {
        return array_values(array_map(fn (StageCondition $c) => [
            'key'         => $c->key(),
            'label'       => $c->label(),
            'description' => $c->description(),
        ], $this->all()));
    }
}
