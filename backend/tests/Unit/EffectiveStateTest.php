<?php

namespace Tests\Unit;

use App\Services\State\EffectiveState;
use App\Services\State\RepairState;
use PHPUnit\Framework\TestCase;

/**
 * Phase 1, Step 4 validation (blueprint A3/B1; upholds Invariant 2 — one interpretation). The two-axis
 * headline reduction is a PURE function; these lock its precedence (incl. Rental-is-King with the
 * never-overwrite service badge). No DB, no app boot.
 */
class EffectiveStateTest extends TestCase
{
    private function repair(string $state): RepairState
    {
        return new RepairState($state);
    }

    public function test_blocked_and_not_on_rent_headlines_waiting_for_parts(): void
    {
        $es = EffectiveState::reduce('available', $this->repair(RepairState::BLOCKED_WAITING_PARTS));
        $this->assertSame('Waiting for Parts', $es->headline);
        $this->assertSame(RepairState::BLOCKED_WAITING_PARTS, $es->service);
        $this->assertSame('available', $es->availability);
    }

    public function test_rental_is_king_but_keeps_service_badge(): void
    {
        $this->assertSame(
            'On Rent · waiting parts',
            EffectiveState::reduce('rented', $this->repair(RepairState::BLOCKED_WAITING_PARTS))->headline
        );
        $this->assertSame(
            'On Rent · repair open',
            EffectiveState::reduce('rented', $this->repair(RepairState::ACTIVE_REPAIR))->headline
        );
        $this->assertSame(
            'On Rent',
            EffectiveState::reduce('rented', $this->repair(RepairState::NOT_IN_REPAIR))->headline
        );
    }

    public function test_in_repair_labels_when_not_on_rent(): void
    {
        $this->assertSame('Under Repair', EffectiveState::reduce('maintenance', $this->repair(RepairState::ACTIVE_REPAIR))->headline);
        $this->assertSame('Under Repair · partial hold', EffectiveState::reduce('maintenance', $this->repair(RepairState::PARTIALLY_BLOCKED))->headline);
    }

    public function test_availability_axis_speaks_when_not_in_repair(): void
    {
        $this->assertSame('Available', EffectiveState::reduce('available', $this->repair(RepairState::NOT_IN_REPAIR))->headline);
        $this->assertSame('In Transit', EffectiveState::reduce('in_transit', $this->repair(RepairState::NOT_IN_REPAIR))->headline);
        $this->assertSame('Unknown', EffectiveState::reduce(null, $this->repair(RepairState::NOT_IN_REPAIR))->headline);
    }
}
