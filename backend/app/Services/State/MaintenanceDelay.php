<?php

namespace App\Services\State;

/**
 * Immutable value object — the DERIVED "why is this ticket delayed?" answer.
 *
 * Produced only by {@see MaintenanceDelayResolver}; describes itself and nothing more. The delay
 * reason is INFERRED (blueprint Invariant 1) — never a stored/typed value. `delaySource` records how
 * it was inferred so surfaces can label provenance without re-deriving.
 *
 * Owns no action: it does not notify, checkpoint, write, touch the timeline, or change workflow —
 * those belong to Projectors/Policies/Listeners in later steps.
 */
final class MaintenanceDelay
{
    /** The one derived reason Step 3 owns. Manual reasons pass through verbatim from a checkpoint. */
    public const REASON_WAITING_FOR_PARTS = 'waiting_for_parts';

    /** Where the reason came from. */
    public const SOURCE_DERIVED_PARTS     = 'derived_parts';
    public const SOURCE_CHECKPOINT_MANUAL = 'checkpoint_manual';

    public function __construct(
        public readonly bool $isDelayed,
        public readonly ?string $delayReason = null,
        public readonly ?string $delaySource = null,
        public readonly ?int $daysWaiting = null,
        public readonly ?string $expectedResolutionDate = null, // Y-m-d
        public readonly ?string $supplierName = null,
        public readonly ?string $checkpointStatus = null,
        public readonly ?string $headline = null,
    ) {}

    /** Not delayed by anything this resolver owns (parts / manual checkpoint). */
    public static function none(?string $checkpointStatus = null): self
    {
        return new self(isDelayed: false, checkpointStatus: $checkpointStatus);
    }
}
