<?php

namespace App\Services\State;

/**
 * Immutable value object — the DERIVED service-axis (repair) condition of ONE maintenance ticket.
 *
 * Produced only by {@see WorkflowStateResolver}; owns no logic beyond describing itself. The state
 * strings are the blueprint's own vocabulary (Addendum A2/A3/§4 and the B1 service axis), NOT
 * dashboard/UI labels — so presentation can change without touching derivation.
 *
 * Scope note (Phase 1, Step 2): this represents the SERVICE axis only. The availability axis and the
 * full B1 headline reduction are combined later by CarStatusService (Step 4). Nothing here is stored.
 */
final class RepairState
{
    /** In an in-repair lane, work proceeding — no active fault is waiting on a part. */
    public const ACTIVE_REPAIR = 'active_repair';

    /** In an in-repair lane and EVERY active fault is blocked by an un-fulfilled part. */
    public const BLOCKED_WAITING_PARTS = 'repair_blocked_waiting_parts';

    /** In an in-repair lane and SOME (not all) active faults are blocked; the rest can proceed. */
    public const PARTIALLY_BLOCKED = 'partially_blocked';

    /** The ticket is not in an in-repair lane, so the parts-block question does not apply. */
    public const NOT_IN_REPAIR = 'not_in_repair';

    /**
     * @param string $state one of the class constants
     * @param int[]  $activeFaultIds         maintenance_task ids currently active (pending/in_progress)
     * @param int[]  $blockingPartRequestIds part_request ids that are gating the repair right now
     */
    public function __construct(
        public readonly string $state,
        public readonly array $activeFaultIds = [],
        public readonly array $blockingPartRequestIds = [],
    ) {}

    public function inRepair(): bool
    {
        return $this->state !== self::NOT_IN_REPAIR;
    }

    public function isBlocked(): bool
    {
        return $this->state === self::BLOCKED_WAITING_PARTS;
    }

    public function isPartiallyBlocked(): bool
    {
        return $this->state === self::PARTIALLY_BLOCKED;
    }

    /** True when the repair is held up by parts to any degree (fully or partially). */
    public function isWaitingForParts(): bool
    {
        return in_array($this->state, [self::BLOCKED_WAITING_PARTS, self::PARTIALLY_BLOCKED], true);
    }
}
