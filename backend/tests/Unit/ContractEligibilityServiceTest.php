<?php

namespace Tests\Unit;

use App\Services\ContractEligibilityService;
use PHPUnit\Framework\TestCase;

/**
 * Contract eligibility checklist — the pure classifier that decides whether a vehicle may go onto a
 * rental / booking contract. DB-free (like OdometerContinuityServiceTest): we feed it a fact struct
 * and assert the verdict. These lock the SHOWSTOPPER rule that a vehicle in maintenance / sold /
 * out-of-service can NEVER be rented, plus the softer condition/cleaning/document rules.
 */
class ContractEligibilityServiceTest extends TestCase
{
    private ContractEligibilityService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new ContractEligibilityService();
    }

    /** A fully rentable car: Ready, green, clean, no open work/damage, valid docs. */
    private function readyFacts(array $overrides = []): array
    {
        return array_merge([
            'status'                 => 'ready',
            'operational_status'     => 'available',
            'condition_grade'        => 'green',
            'cleaning_status'        => 'clean',
            'open_maintenance'       => false,
            'open_damage'            => 0,
            'insurance_days_left'    => 120,
            'registration_days_left' => 120,
        ], $overrides);
    }

    private function keys(array $result, string $bucket): array
    {
        return array_column($result[$bucket], 'key');
    }

    public function test_ready_clean_vehicle_is_eligible(): void
    {
        $r = $this->svc->assess($this->readyFacts());
        $this->assertTrue($r['eligible']);
        $this->assertFalse($r['requires_manager']);
        $this->assertEmpty($r['blocks']);
    }

    /** THE showstopper regression — a vehicle whose lifecycle status is 'under_maintenance' is blocked. */
    public function test_vehicle_in_maintenance_status_is_blocked(): void
    {
        $r = $this->svc->assess($this->readyFacts(['status' => 'under_maintenance', 'operational_status' => 'under_maintenance']));
        $this->assertFalse($r['eligible'], 'A vehicle under maintenance must never be rentable');
        $this->assertContains('status', $this->keys($r, 'blocks'));
    }

    /** An OPEN WORK ORDER blocks even when the lifecycle status looks fine. */
    public function test_open_work_order_is_blocked(): void
    {
        $r = $this->svc->assess($this->readyFacts(['open_maintenance' => true]));
        $this->assertFalse($r['eligible']);
        $this->assertContains('maintenance', $this->keys($r, 'blocks'));
    }

    public function test_sold_vehicle_is_blocked(): void
    {
        $r = $this->svc->assess($this->readyFacts(['status' => 'sold']));
        $this->assertFalse($r['eligible']);
        $this->assertContains('status', $this->keys($r, 'blocks'));
    }

    public function test_already_rented_vehicle_is_blocked(): void
    {
        $r = $this->svc->assess($this->readyFacts(['status' => 'rented', 'operational_status' => 'rented']));
        $this->assertFalse($r['eligible']);
        $this->assertContains('status', $this->keys($r, 'blocks'));
    }

    public function test_red_condition_is_blocked(): void
    {
        $r = $this->svc->assess($this->readyFacts(['condition_grade' => 'red']));
        $this->assertFalse($r['eligible']);
        $this->assertContains('condition', $this->keys($r, 'blocks'));
    }

    public function test_yellow_condition_requires_manager_override(): void
    {
        $r = $this->svc->assess($this->readyFacts(['condition_grade' => 'yellow']));
        $this->assertTrue($r['requires_manager']);      // not a hard block, but needs an override
        $this->assertContains('condition', $this->keys($r, 'overridable'));
    }

    public function test_open_damage_is_blocked(): void
    {
        $r = $this->svc->assess($this->readyFacts(['open_damage' => 2]));
        $this->assertFalse($r['eligible']);
        $this->assertContains('inspection', $this->keys($r, 'blocks'));
    }

    public function test_dirty_vehicle_is_blocked(): void
    {
        $r = $this->svc->assess($this->readyFacts(['cleaning_status' => 'dirty']));
        $this->assertFalse($r['eligible']);
        $this->assertContains('cleaning', $this->keys($r, 'blocks'));
    }

    /** Unknown cleaning is a warning (flag for cleaning), not a block — else null-cleaning grounds all. */
    public function test_unassessed_cleaning_warns_but_does_not_block(): void
    {
        $r = $this->svc->assess($this->readyFacts(['cleaning_status' => null]));
        $this->assertTrue($r['eligible']);
        $this->assertContains('cleaning', $this->keys($r, 'warnings'));
    }

    /**
     * Insurance expiry is a WARNING, not a block, because the F-Insurance/F-RTA sync data is broadly
     * stale — hard-blocking would false-ground most of the fleet. Locks that deliberate decision
     * (flip ContractEligibilityService::DOCUMENTS_BLOCK to promote it once the sync is trusted).
     */
    public function test_expired_insurance_warns_but_does_not_block(): void
    {
        $r = $this->svc->assess($this->readyFacts(['insurance_days_left' => -30]));
        $this->assertTrue($r['eligible']);
        $this->assertContains('insurance', $this->keys($r, 'warnings'));
    }
}
