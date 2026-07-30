<?php

namespace Tests\Unit;

use App\Services\Intelligence\CapabilityPolicy;
use App\Services\Intelligence\PolicyRegistry;
use Tests\TestCase;

/**
 * Orchestration rules as data — the contract that lets a new capability ship without a line of
 * delivery code.
 */
class CapabilityPolicyTest extends TestCase
{
    /**
     * THE STATE GATE. The reason this abstraction exists: a comeback warning at `awaiting_invoice`
     * is a fact nobody can act on, and a pipeline that shows those has become a dashboard.
     */
    public function test_a_policy_gates_on_workflow_state(): void
    {
        $policy = new CapabilityPolicy('c', workflowStates: ['inspection_pending', 'reinspection_failed']);

        $this->assertTrue($policy->allowsState('inspection_pending'));
        $this->assertFalse($policy->allowsState('awaiting_invoice'));
        $this->assertFalse($policy->allowsState(null));
    }

    public function test_no_declared_states_means_any_state(): void
    {
        $policy = new CapabilityPolicy('c');

        $this->assertTrue($policy->allowsState('anything'));
        $this->assertTrue($policy->allowsState(null));
    }

    /** The feature flag is orchestration too — a disabled capability is gated before it ever runs. */
    public function test_a_disabled_policy_blocks_every_state(): void
    {
        $policy = new CapabilityPolicy('c', enabled: false, workflowStates: ['inspection_pending']);

        $this->assertFalse($policy->allowsState('inspection_pending'));
    }

    public function test_roles_gate_the_audience_but_a_system_context_is_not_filtered(): void
    {
        $policy = new CapabilityPolicy('c', roles: ['maintenance.delegate']);

        $this->assertTrue($policy->allowsActor(['maintenance.delegate']));
        $this->assertFalse($policy->allowsActor(['maintenance.logistics']));
        $this->assertTrue($policy->allowsActor([]), 'A background/system run has no user to filter against.');
    }

    /** What counts as "the same decision moment" — the present-once key. */
    public function test_the_refresh_strategy_defines_the_decision_moment(): void
    {
        $perState = new CapabilityPolicy('c');
        $this->assertSame('inspection_pending', $perState->momentKey('inspection_pending'));

        $perTicket = new CapabilityPolicy('c', refresh: CapabilityPolicy::REFRESH_PER_TICKET);
        $this->assertSame('*', $perTicket->momentKey('inspection_pending'), 'One recommendation for the whole ticket.');

        $always = new CapabilityPolicy('c', refresh: CapabilityPolicy::REFRESH_ALWAYS);
        $this->assertNull($always->momentKey('inspection_pending'), 'Never reuse — a new recommendation every serve.');
    }

    /**
     * A null cooldown means the answer stands for good. Re-asking someone who already decided is
     * nagging, and nagging costs credibility across every other card too.
     */
    public function test_a_null_cooldown_means_the_card_never_returns(): void
    {
        $this->assertNull((new CapabilityPolicy('c'))->mayReturnAfter(now()));

        $withCooldown = new CapabilityPolicy('c', cooldownMinutes: 60);
        $this->assertTrue($withCooldown->mayReturnAfter(now())->gt(now()->addMinutes(59)));
    }

    public function test_expiry_is_opt_in(): void
    {
        $this->assertNull((new CapabilityPolicy('c'))->expiresAt());
        $this->assertTrue((new CapabilityPolicy('c', expiresAfterMinutes: 30))->expiresAt()->gt(now()->addMinutes(29)));
    }

    /**
     * A missing policy FAILS OPEN, loudly. Failing closed would mean a capability that was built,
     * registered and tested simply never appears, for a reason nobody can see.
     */
    public function test_an_unregistered_capability_gets_the_permissive_default(): void
    {
        $registry = new PolicyRegistry([new CapabilityPolicy('known')]);

        $this->assertTrue($registry->has('known'));
        $this->assertFalse($registry->has('forgotten'));

        $fallback = $registry->for('forgotten');
        $this->assertTrue($fallback->allowsState('anything'));
        $this->assertTrue($fallback->allowsActor(['any.permission']));
    }

    /** The real registration must gate the comeback card to states where the car is still divertible. */
    public function test_the_shipped_comeback_policy_only_fires_before_the_car_is_committed(): void
    {
        $policy = app(PolicyRegistry::class)->for('comeback-warning');

        config()->set('features.intelligence.comeback_detection', true);
        $enabled = new CapabilityPolicy(
            capabilityId: $policy->capabilityId,
            enabled: true,
            workflowStates: $policy->workflowStates,
            roles: $policy->roles,
        );

        $this->assertTrue($enabled->allowsState(\App\Models\Maintenance::WF_INSPECTION_PENDING));
        $this->assertTrue($enabled->allowsState(\App\Models\Maintenance::WF_REINSPECTION_FAILED));
        $this->assertFalse($enabled->allowsState(\App\Models\Maintenance::WF_AWAITING_INVOICE), 'Nobody can act on it here.');
        $this->assertFalse($enabled->allowsState(\App\Models\Maintenance::WF_CLOSED));
    }
}
