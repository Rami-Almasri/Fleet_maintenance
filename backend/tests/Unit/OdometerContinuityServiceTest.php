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

    public function test_strict_match_stages_expect_a_match_and_allow_a_noted_forward_drift(): void
    {
        // Behaviour shared by EVERY strict-match stage: the car should not have moved since the last
        // reading, so an exact match is clean, a forward drift is allowed but must carry a note,
        // and a backward reading is always a hard block (an odometer cannot go down — it is a typo).
        foreach (OdometerContinuityService::STRICT_MATCH_STAGES as $stage) {
            $this->assertSame(OdometerContinuityService::STATUS_VERIFIED, $this->svc->evaluate(100, 100, $stage)['status'], "exact {$stage}");
            $this->assertSame(OdometerContinuityService::STATUS_AUTHORIZED, $this->svc->evaluate(101, 100, $stage)['status'], "+1 {$stage}");
            $this->assertSame(OdometerContinuityService::STATUS_AUTHORIZED, $this->svc->evaluate(105, 100, $stage)['status'], "+5 {$stage}");
            $this->assertSame(OdometerContinuityService::STATUS_EXACT, $this->svc->evaluate(80, 100, $stage)['status'], "backward {$stage}");
        }

        $this->assertTrue($this->svc->stageRequiresExactMatch(OdometerContinuityService::STAGE_TEST));
        $this->assertTrue($this->svc->stageRequiresExactMatch(OdometerContinuityService::STAGE_PARK_PICKUP));
        $this->assertTrue($this->svc->stageRequiresExactMatch(OdometerContinuityService::STAGE_REINSPECT));
        $this->assertFalse($this->svc->stageRequiresExactMatch(OdometerContinuityService::STAGE_PICKUP));
    }

    public function test_a_large_forward_drift_is_accepted_on_every_strict_stage_and_sent_for_review(): void
    {
        // No strict stage hard-blocks a FORWARD reading any more. A car genuinely does move between two
        // of our checkpoints — a yard shuffle, a fuel run, someone else taking it — and re-reading the
        // dial cannot make a true reading go away. The old wall only taught drivers to re-type the
        // previous number, which is the one outcome that actually corrupts the mileage chain. So the
        // reading is taken as an audited "authorized deviation" with a mandatory note.
        foreach (OdometerContinuityService::STRICT_MATCH_STAGES as $stage) {
            $this->assertSame(OdometerContinuityService::STATUS_AUTHORIZED, $this->svc->evaluate(106, 100, $stage)['status'], "+6 {$stage}");
            $this->assertSame(OdometerContinuityService::STATUS_AUTHORIZED, $this->svc->evaluate(400, 100, $stage)['status'], "+300 {$stage}");
        }

        // …but a drift PAST the buffer is escalated: it goes to the odometer approval board, because the
        // car moved when our records say it was standing still. A drift within the buffer is dial-reading
        // noise and stays a note-only event.
        $this->assertTrue($this->svc->needsSupervisorReview($this->svc->evaluate(112, 100, OdometerContinuityService::STAGE_TEST)));
        $this->assertFalse($this->svc->needsSupervisorReview($this->svc->evaluate(103, 100, OdometerContinuityService::STAGE_TEST)));
        $this->assertFalse($this->svc->needsSupervisorReview($this->svc->evaluate(100, 100, OdometerContinuityService::STAGE_TEST)));

        // Backward still CLASSIFIES as exact-required everywhere — that direction is physically impossible.
        // Whether it blocks or is merely reviewed is a per-stage decision (see the review-not-block test).
        $this->assertSame(
            OdometerContinuityService::STATUS_EXACT,
            $this->svc->evaluate(99, 100, OdometerContinuityService::STAGE_PARK_PICKUP)['status'],
            'strict stages are lenient FORWARD only',
        );
    }

    /**
     * "Needs Test Drive" is the first time anyone physically reads the dial, so it REVIEWS instead of
     * BLOCKING: a backward reading is accepted and routed to the odometer approval board rather than
     * refused. Every other strict-match stage keeps the hard block, because by then this stage has
     * already anchored the car's mileage.
     */
    public function test_the_test_drive_stage_reviews_a_backward_reading_instead_of_blocking_it(): void
    {
        $this->assertTrue($this->svc->stageReviewsInsteadOfBlocking(OdometerContinuityService::STAGE_TEST));
        $this->assertFalse($this->svc->stageReviewsInsteadOfBlocking(OdometerContinuityService::STAGE_PARK_PICKUP));
        $this->assertFalse($this->svc->stageReviewsInsteadOfBlocking(OdometerContinuityService::STAGE_REINSPECT));

        $backward = $this->svc->evaluate(90, 100, OdometerContinuityService::STAGE_TEST);
        $this->assertSame(OdometerContinuityService::STATUS_EXACT, $backward['status']);

        // WITH the stage: the backward reading is the supervisor's to settle, so it must be filed.
        $this->assertTrue($this->svc->needsSupervisorReview($backward, OdometerContinuityService::STAGE_TEST));
        // WITHOUT the stage: the original forward-only rule is untouched, so existing callers can't drift.
        $this->assertFalse($this->svc->needsSupervisorReview($backward));
        // A backward reading at a stage that still blocks is never filed — it never gets that far.
        $this->assertFalse($this->svc->needsSupervisorReview(
            $this->svc->evaluate(90, 100, OdometerContinuityService::STAGE_PARK_PICKUP),
            OdometerContinuityService::STAGE_PARK_PICKUP,
        ));

        // The forward buffer is unchanged by the new leniency: noise stays noise, a real drift still goes up.
        $this->assertFalse($this->svc->needsSupervisorReview(
            $this->svc->evaluate(103, 100, OdometerContinuityService::STAGE_TEST),
            OdometerContinuityService::STAGE_TEST,
        ));
        $this->assertTrue($this->svc->needsSupervisorReview(
            $this->svc->evaluate(112, 100, OdometerContinuityService::STAGE_TEST),
            OdometerContinuityService::STAGE_TEST,
        ));

        // The typo guard is NOT waived — a slipped digit is not "a different mileage".
        $this->assertSame(
            OdometerContinuityService::STATUS_IMPLAUSIBLE,
            $this->svc->evaluate(6_276_888, 62_769, OdometerContinuityService::STAGE_TEST)['status'],
        );
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

    // ── The forward guard (MAX_JUMP_KM) ─────────────────────────────────────────────────────────────

    /**
     * THE REGRESSION TEST. The exact reading that corrupted three real vehicles: a garage-out capture of
     * 6,276,888 km against an intake of 62,769. Every rule in this class bounded how far a reading could
     * run BACKWARDS; nothing bounded forwards, so this was classified `test_drive` — "legitimate, confirm
     * but never block" — and written straight through to vehicles.odometer.
     */
    public function test_the_six_million_km_typo_is_rejected(): void
    {
        $flag = $this->svc->evaluate(6_276_888, 62_769, OdometerContinuityService::STAGE_GARAGE_OUT);

        $this->assertSame(OdometerContinuityService::STATUS_IMPLAUSIBLE, $flag['status']);
        $this->assertSame(6_214_119, $flag['delta']);
    }

    public function test_an_implausible_jump_is_caught_on_every_stage(): void
    {
        // Including the garage-transfer stages that deliberately waive the tolerance nag — "the car was
        // driven" explains 500 km, never 6 million.
        foreach ([
            OdometerContinuityService::STAGE_TEST,
            OdometerContinuityService::STAGE_PICKUP,
            OdometerContinuityService::STAGE_PARK_PICKUP,
            OdometerContinuityService::STAGE_GARAGE_IN,
            OdometerContinuityService::STAGE_GARAGE_OUT,
            OdometerContinuityService::STAGE_RETURN,
            OdometerContinuityService::STAGE_TRANSFER,
            OdometerContinuityService::STAGE_TEST_END,
            OdometerContinuityService::STAGE_REINSPECT,
        ] as $stage) {
            $this->assertSame(
                OdometerContinuityService::STATUS_IMPLAUSIBLE,
                $this->svc->evaluate(50_000 + OdometerContinuityService::MAX_JUMP_KM + 1, 50_000, $stage)['status'],
                "stage {$stage} must reject a jump beyond MAX_JUMP_KM",
            );
        }
    }

    public function test_a_long_but_real_journey_still_passes(): void
    {
        // A car out on rental between two readings can legitimately cover thousands of km. The guard is a
        // TYPO catcher, not a mileage policy — it must not start blocking honest long hops.
        $flag = $this->svc->evaluate(50_000 + OdometerContinuityService::MAX_JUMP_KM, 50_000, OdometerContinuityService::STAGE_PICKUP);

        $this->assertNotSame(OdometerContinuityService::STATUS_IMPLAUSIBLE, $flag['status']);
        $this->assertSame(OdometerContinuityService::STATUS_CHECK, $flag['status'], 'a big-but-possible pickup jump stays a soft "re-read the dial" nudge');
    }

    public function test_the_guard_does_not_fire_without_a_previous_reading(): void
    {
        // An anchor reading has nothing to jump FROM — a high first reading is just a high-mileage car.
        $this->assertSame(
            OdometerContinuityService::STATUS_VERIFIED,
            $this->svc->evaluate(900_000, null, OdometerContinuityService::STAGE_GARAGE_OUT)['status'],
        );
    }
}
