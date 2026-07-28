<?php

namespace App\Services\State;

/**
 * The canonical operational state of a vehicle for one open ticket: the two axes plus the deterministic
 * B1 headline reduction (blueprint A3/B1). {@see reduce()} is a PURE function — the single definition of
 * how the axes collapse to one label — so no orchestration/UI layer re-implements the precedence.
 *
 * Axis inputs (Phase 1, per P1-D1 / DP4):
 *  - service:      derived by {@see WorkflowStateResolver} (a {@see RepairState}).
 *  - availability: the existing `vehicles.operational_status` read AS-IS (no new derivation, no writer;
 *                  full availability derivation is DEBT-1).
 * "Never overwrite": when Rental is King wins the headline, the service axis is still surfaced as a
 * suffix badge rather than hidden.
 */
final class EffectiveState
{
    public function __construct(
        public readonly string $headline,
        public readonly string $service,        // one of RepairState::* — the service axis
        public readonly ?string $availability,  // operational_status, read as-is
    ) {}

    public static function reduce(?string $availability, RepairState $repair): self
    {
        return new self(
            headline: self::headline($availability, $repair),
            service: $repair->state,
            availability: $availability,
        );
    }

    private static function headline(?string $availability, RepairState $repair): string
    {
        $onRent = $availability === 'rented';

        // 1) Blocked on parts and not on rent → the block is the headline.
        if ($repair->isBlocked() && ! $onRent) {
            return 'Waiting for Parts';
        }

        // 2) Rental is King — but never hide the service axis; surface it as a badge.
        if ($onRent) {
            if ($repair->isWaitingForParts()) {
                return 'On Rent · waiting parts';
            }
            if ($repair->inRepair()) {
                return 'On Rent · repair open';
            }
            return 'On Rent';
        }

        // 3) In a repair lane → the service-axis label.
        return match ($repair->state) {
            RepairState::BLOCKED_WAITING_PARTS => 'Waiting for Parts',
            RepairState::PARTIALLY_BLOCKED     => 'Under Repair · partial hold',
            RepairState::ACTIVE_REPAIR         => 'Under Repair',
            // 4) Otherwise the availability axis speaks.
            default => self::availabilityLabel($availability),
        };
    }

    private static function availabilityLabel(?string $availability): string
    {
        return match ($availability) {
            'available'   => 'Available',
            'rented'      => 'On Rent',
            'in_transit'  => 'In Transit',
            'maintenance' => 'In Maintenance',
            default       => 'Unknown',
        };
    }
}
