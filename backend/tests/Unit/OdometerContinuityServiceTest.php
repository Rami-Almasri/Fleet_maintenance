<?php

namespace Tests\Unit;

use App\Services\OdometerContinuityService;
use PHPUnit\Framework\TestCase;

/**
 * Odometer Continuity Rules — pure classification logic, so a plain PHPUnit TestCase (no DB/app boot).
 * These lock the thresholds the UI mirrors in odometerContinuity.js; keep the two in step.
 */
class OdometerContinuityServiceTest extends TestCase
{
    private OdometerContinuityService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new OdometerContinuityService();
    }

    public function test_no_previous_reading_anchors_as_verified(): void
    {
        $flag = $this->svc->evaluate(84210, null, OdometerContinuityService::STAGE_TEST);
        $this->assertSame(OdometerContinuityService::STATUS_VERIFIED, $flag['status']);
        $this->assertNull($flag['delta']);
    }

    public function test_within_tolerance_either_way_is_verified(): void
    {
        // exactly the same
        $this->assertSame(OdometerContinuityService::STATUS_VERIFIED, $this->svc->evaluate(100, 100, OdometerContinuityService::STAGE_PICKUP)['status']);
        // +5 (edge of the buffer)
        $this->assertSame(OdometerContinuityService::STATUS_VERIFIED, $this->svc->evaluate(105, 100, OdometerContinuityService::STAGE_PICKUP)['status']);
        // -5 (edge of the buffer, the other way)
        $this->assertSame(OdometerContinuityService::STATUS_VERIFIED, $this->svc->evaluate(95, 100, OdometerContinuityService::STAGE_PICKUP)['status']);
    }

    public function test_backward_beyond_tolerance_is_a_discrepancy_at_the_non_strict_stages(): void
    {
        // The strict-match park spot-checks (TEST / PARK_PICKUP) treat a backward reading as a HARD block
        // (exact_required), covered separately below — here we assert the softer "discrepancy" classification
        // for every other stage.
        foreach ([
            OdometerContinuityService::STAGE_PICKUP,
            OdometerContinuityService::STAGE_GARAGE_IN,
            OdometerContinuityService::STAGE_GARAGE_OUT,
            OdometerContinuityService::STAGE_RETURN,
        ] as $stage) {
            $flag = $this->svc->evaluate(80, 100, $stage);
            $this->assertSame(OdometerContinuityService::STATUS_DISCREPANCY, $flag['status'], "stage {$stage}");
            $this->assertSame(-20, $flag['delta']);
        }
    }

    public function test_strict_match_stages_enforce_exact_with_a_small_noted_tolerance(): void
    {
        foreach ([
            OdometerContinuityService::STAGE_TEST,
            OdometerContinuityService::STAGE_PARK_PICKUP,
        ] as $stage) {
            // exact match → verified, no note needed
            $this->assertSame(OdometerContinuityService::STATUS_VERIFIED, $this->svc->evaluate(100, 100, $stage)['status'], "exact {$stage}");
            // +1 and +5 (the buffer edge) → an authorized deviation that must carry a note
            $this->assertSame(OdometerContinuityService::STATUS_AUTHORIZED, $this->svc->evaluate(101, 100, $stage)['status'], "+1 {$stage}");
            $this->assertSame(OdometerContinuityService::STATUS_AUTHORIZED, $this->svc->evaluate(105, 100, $stage)['status'], "+5 {$stage}");
            // +6 (beyond the buffer) → a hard block, NOT an ack-able nudge
            $this->assertSame(OdometerContinuityService::STATUS_EXACT, $this->svc->evaluate(106, 100, $stage)['status'], "+6 {$stage}");
            // any backward reading → a hard block, never a soft discrepancy
            $this->assertSame(OdometerContinuityService::STATUS_EXACT, $this->svc->evaluate(80, 100, $stage)['status'], "backward {$stage}");
        }

        $this->assertTrue($this->svc->stageRequiresExactMatch(OdometerContinuityService::STAGE_TEST));
        $this->assertTrue($this->svc->stageRequiresExactMatch(OdometerContinuityService::STAGE_PARK_PICKUP));
        $this->assertFalse($this->svc->stageRequiresExactMatch(OdometerContinuityService::STAGE_PICKUP));
    }

    public function test_pickup_forward_movement_is_verified_until_the_warn_threshold(): void
    {
        // normal driving since we last saw the car — fine
        $this->assertSame(OdometerContinuityService::STATUS_VERIFIED, $this->svc->evaluate(150, 100, OdometerContinuityService::STAGE_PICKUP)['status']);
        // +50 is the edge — still verified
        $this->assertSame(OdometerContinuityService::STATUS_VERIFIED, $this->svc->evaluate(150, 100, OdometerContinuityService::STAGE_PICKUP)['status']);
        // beyond +50 → a "double-check" nudge
        $this->assertSame(OdometerContinuityService::STATUS_CHECK, $this->svc->evaluate(151, 100, OdometerContinuityService::STAGE_PICKUP)['status']);
    }

    public function test_garage_out_beyond_tolerance_flags_a_test_drive(): void
    {
        $flag = $this->svc->evaluate(140, 100, OdometerContinuityService::STAGE_GARAGE_OUT);
        $this->assertSame(OdometerContinuityService::STATUS_TEST_DRIVE, $flag['status']);
        $this->assertSame(40, $flag['delta']);
    }

    public function test_garage_in_and_return_treat_forward_travel_as_verified(): void
    {
        // drive to the garage moved the meter a lot — expected, not a test drive
        $this->assertSame(OdometerContinuityService::STATUS_VERIFIED, $this->svc->evaluate(200, 100, OdometerContinuityService::STAGE_GARAGE_IN)['status']);
        $this->assertSame(OdometerContinuityService::STATUS_VERIFIED, $this->svc->evaluate(200, 100, OdometerContinuityService::STAGE_RETURN)['status']);
    }

    public function test_garage_transfer_stages_waive_tolerance_but_internal_checks_keep_it(): void
    {
        // The site↔garage / garage↔garage moves: a mileage increase is an expected road trip (the car is
        // physically driven), so the forward-tolerance nag is waived — including a garage-to-garage TRANSFER.
        $this->assertTrue($this->svc->stageIgnoresTolerance(OdometerContinuityService::STAGE_GARAGE_IN));
        $this->assertTrue($this->svc->stageIgnoresTolerance(OdometerContinuityService::STAGE_GARAGE_OUT));
        $this->assertTrue($this->svc->stageIgnoresTolerance(OdometerContinuityService::STAGE_TRANSFER));

        // The internal spot-checks at the park keep the ±10 km rule.
        $this->assertFalse($this->svc->stageIgnoresTolerance(OdometerContinuityService::STAGE_TEST));
        $this->assertFalse($this->svc->stageIgnoresTolerance(OdometerContinuityService::STAGE_PICKUP));
        $this->assertFalse($this->svc->stageIgnoresTolerance(OdometerContinuityService::STAGE_RETURN));
    }
}
