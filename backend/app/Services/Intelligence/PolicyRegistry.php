<?php

namespace App\Services\Intelligence;

use Illuminate\Support\Facades\Log;

/**
 * Every capability's orchestration rules, in one place.
 *
 * Kept separate from the DecisionEngine's capability list on purpose: the engine's job is to run
 * capabilities and rank cards, and it should stay ignorant of workflow states, roles and cooldowns.
 * Operational Intelligence consults this registry; nothing else does.
 */
class PolicyRegistry
{
    /** @var array<string, CapabilityPolicy> */
    private array $policies = [];

    /** @param CapabilityPolicy[] $policies */
    public function __construct(array $policies = [])
    {
        foreach ($policies as $policy) {
            $this->register($policy);
        }
    }

    public function register(CapabilityPolicy $policy): self
    {
        $this->policies[$policy->capabilityId] = $policy;

        return $this;
    }

    /**
     * A missing policy is a registration oversight, not a reason to go silent — so it is logged and
     * the permissive default applies. Failing open is right here: the alternative is a capability
     * that was built, registered and tested, and then never appears for a reason nobody can see.
     */
    public function for(string $capabilityId): CapabilityPolicy
    {
        if (! isset($this->policies[$capabilityId])) {
            Log::warning('Intelligence capability has no registered policy; using the permissive default', [
                'capability' => $capabilityId,
            ]);

            return CapabilityPolicy::permissive($capabilityId);
        }

        return $this->policies[$capabilityId];
    }

    public function has(string $capabilityId): bool
    {
        return isset($this->policies[$capabilityId]);
    }

    /** @return array<string, CapabilityPolicy> */
    public function all(): array
    {
        return $this->policies;
    }
}
