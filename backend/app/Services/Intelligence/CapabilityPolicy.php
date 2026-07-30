<?php

namespace App\Services\Intelligence;

use Illuminate\Support\Carbon;

/**
 * WHEN, WHERE and HOW OFTEN a capability's card may appear — declared as data, outside the
 * capability.
 *
 * A capability answers exactly one question: "given this maintenance case, do I have useful
 * historical evidence?" It has no business deciding which workflow states it fires at, which roles
 * may see it, when it expires or when it is allowed back. Those are orchestration rules, they
 * change for operational reasons that have nothing to do with evidence, and they are the same shape
 * for every capability — so they live here and are enforced once.
 *
 * The practical effect is that adding a capability is three lines: implement evaluate(), register
 * the capability, register the policy. No orchestration code is written, ever.
 *
 * WHY A VALUE OBJECT RATHER THAN A CLASS PER CAPABILITY: policies are configuration. Expressing
 * them as data means the whole platform's delivery behaviour can be read in one screen of
 * AppServiceProvider, instead of being distributed across a dozen classes that each need opening to
 * find out whether they gate on state.
 */
final readonly class CapabilityPolicy
{
    /** Freeze one recommendation per decision moment — the default, and almost always right. */
    public const REFRESH_PER_STATE  = 'per_state';
    /** One per ticket for its whole life. For advice that does not change as the ticket moves. */
    public const REFRESH_PER_TICKET = 'per_ticket';
    /** A new recommendation every time it is served. Measures reloads; use only for testing. */
    public const REFRESH_ALWAYS     = 'always';

    /**
     * @param string   $capabilityId    the capability this governs
     * @param bool     $enabled         the feature flag, resolved at registration
     * @param string[] $workflowStates  WF_* states this may fire at; [] = any state
     * @param string[] $roles           permissions that may SEE it; [] = anyone who can see the ticket
     * @param string   $refresh         REFRESH_* — what counts as a new decision moment
     * @param int|null $expiresAfterMinutes advice goes stale; null = never expires
     * @param int|null $cooldownMinutes after a response, how long before it may return; null = never returns
     * @param int|null $maxPresentations lifetime cap per subject; null = uncapped
     */
    public function __construct(
        public string $capabilityId,
        public bool $enabled = true,
        public array $workflowStates = [],
        public array $roles = [],
        public string $refresh = self::REFRESH_PER_STATE,
        public ?int $expiresAfterMinutes = null,
        public ?int $cooldownMinutes = null,
        public ?int $maxPresentations = null,
    ) {}

    /**
     * The fallback for a capability registered without a policy.
     *
     * Deliberately PERMISSIVE rather than restrictive: a missing policy is a registration oversight,
     * and silently showing nothing is the hardest possible failure to notice. It is logged at
     * registration instead, where someone can act on it.
     */
    public static function permissive(string $capabilityId): self
    {
        return new self(capabilityId: $capabilityId);
    }

    /** Does this capability run at all, at this state? The gate applied BEFORE evaluate(). */
    public function allowsState(?string $workflowState): bool
    {
        if (! $this->enabled) {
            return false;
        }

        return $this->workflowStates === []
            || ($workflowState !== null && in_array($workflowState, $this->workflowStates, true));
    }

    /**
     * May this actor see it? [] means "anyone already permitted to view the ticket", which is the
     * right default — a second permission layer over an already-authorised screen mostly produces
     * confusing invisible cards.
     *
     * @param string[] $actorPermissions
     */
    public function allowsActor(array $actorPermissions): bool
    {
        return $this->roles === []
            || $actorPermissions === []            // system/background context — not a user to filter
            || array_intersect($this->roles, $actorPermissions) !== [];
    }

    /** The key that identifies one decision moment, per the refresh strategy. */
    public function momentKey(?string $workflowState): ?string
    {
        return match ($this->refresh) {
            self::REFRESH_PER_TICKET => '*',
            self::REFRESH_ALWAYS     => null,
            default                  => $workflowState ?? '*',
        };
    }

    public function expiresAt(?Carbon $from = null): ?Carbon
    {
        return $this->expiresAfterMinutes === null
            ? null
            : ($from ?? now())->copy()->addMinutes($this->expiresAfterMinutes);
    }

    /**
     * After a user has answered, the card stays away for the cooldown — and forever if none is set.
     *
     * A null cooldown is the honest default for advice: once someone has considered a warning and
     * decided, showing it again is nagging rather than informing, and the anti-fatigue decay exists
     * precisely because that erodes trust in every other card too.
     */
    public function mayReturnAfter(Carbon $respondedAt): ?Carbon
    {
        return $this->cooldownMinutes === null
            ? null
            : $respondedAt->copy()->addMinutes($this->cooldownMinutes);
    }
}
