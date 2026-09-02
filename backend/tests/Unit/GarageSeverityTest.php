<?php

namespace Tests\Unit;

use App\Support\GarageSeverity;
use PHPUnit\Framework\TestCase;

/**
 * The Garage Intelligence attention ladder and — the part that actually matters — the rule that
 * decides whether a grade is worth interrupting an admin for.
 *
 * DB-free on purpose. The anti-spam behaviour of this feature is a comparison between two strings;
 * proving it should not require a database, a notification driver or a fleet of fixtures, and
 * locking it here means the integration tests can be about wiring rather than about arithmetic.
 *
 * Thresholds are passed in rather than read from config, so these tests describe the RULE and stay
 * green when the fleet's numbers are retuned.
 */
class GarageSeverityTest extends TestCase
{
    private const VISITS = ['warning' => 3, 'high' => 5, 'critical' => 7];
    private const DAYS   = ['warning' => 3, 'high' => 7, 'critical' => 14];

    // ── Grading ────────────────────────────────────────────────────────────────────────────────

    public function test_below_the_first_threshold_is_normal(): void
    {
        $this->assertSame(GarageSeverity::NORMAL, GarageSeverity::grade(0, self::VISITS));
        $this->assertSame(GarageSeverity::NORMAL, GarageSeverity::grade(2, self::VISITS));
    }

    /** "3 visits = Warning" means three visits IS the warning, not one short of it. */
    public function test_each_threshold_is_inclusive_at_its_boundary(): void
    {
        $this->assertSame(GarageSeverity::WARNING,  GarageSeverity::grade(3, self::VISITS));
        $this->assertSame(GarageSeverity::HIGH,     GarageSeverity::grade(5, self::VISITS));
        $this->assertSame(GarageSeverity::CRITICAL, GarageSeverity::grade(7, self::VISITS));
    }

    public function test_a_value_between_two_bands_keeps_the_lower_one(): void
    {
        $this->assertSame(GarageSeverity::WARNING, GarageSeverity::grade(4, self::VISITS));
        $this->assertSame(GarageSeverity::HIGH,    GarageSeverity::grade(6, self::VISITS));
    }

    public function test_a_value_past_the_top_band_stays_critical(): void
    {
        $this->assertSame(GarageSeverity::CRITICAL, GarageSeverity::grade(40, self::VISITS));
    }

    /** Downtime is graded on fractional days — 2.9 days is not yet three. */
    public function test_downtime_grades_on_fractions_not_whole_days(): void
    {
        $this->assertSame(GarageSeverity::NORMAL,  GarageSeverity::grade(2.99, self::DAYS));
        $this->assertSame(GarageSeverity::WARNING, GarageSeverity::grade(3.0, self::DAYS));
        $this->assertSame(GarageSeverity::HIGH,    GarageSeverity::grade(8.4, self::DAYS));
        $this->assertSame(GarageSeverity::CRITICAL, GarageSeverity::grade(30.26, self::DAYS));
    }

    /**
     * A band removed from the config must become unreachable, never an implicit promotion to the
     * next one up. Blanking `high` must not make eight days read as critical.
     */
    public function test_an_unconfigured_band_is_unreachable_and_never_promotes(): void
    {
        $partial = ['warning' => 3, 'high' => 0, 'critical' => 14];

        $this->assertSame(GarageSeverity::WARNING, GarageSeverity::grade(8.4, $partial));
        $this->assertSame(GarageSeverity::CRITICAL, GarageSeverity::grade(14, $partial));
    }

    /** A mis-ordered config still grades honestly — the highest SATISFIED band wins, not the first. */
    public function test_a_misordered_config_still_reports_the_highest_band_reached(): void
    {
        $wrong = ['warning' => 10, 'high' => 5, 'critical' => 20];

        $this->assertSame(GarageSeverity::HIGH, GarageSeverity::grade(6, $wrong));
        $this->assertSame(GarageSeverity::CRITICAL, GarageSeverity::grade(25, $wrong));
    }

    // ── The escalation rule (this is the anti-spam design) ─────────────────────────────────────

    public function test_a_climb_notifies_at_every_step(): void
    {
        $this->assertTrue(GarageSeverity::shouldNotify(GarageSeverity::WARNING, GarageSeverity::NORMAL));
        $this->assertTrue(GarageSeverity::shouldNotify(GarageSeverity::HIGH, GarageSeverity::WARNING));
        $this->assertTrue(GarageSeverity::shouldNotify(GarageSeverity::CRITICAL, GarageSeverity::HIGH));
    }

    /** Two steps at once (a car that jumps straight to critical) is still exactly one alert. */
    public function test_skipping_a_step_still_notifies_once(): void
    {
        $this->assertTrue(GarageSeverity::shouldNotify(GarageSeverity::CRITICAL, GarageSeverity::NORMAL));
    }

    /** The whole point: five maintenance updates on a `high` car must not be five alerts. */
    public function test_the_same_level_never_notifies_again(): void
    {
        foreach (GarageSeverity::LADDER as $level) {
            $this->assertFalse(
                GarageSeverity::shouldNotify($level, $level),
                "{$level} → {$level} must be silent"
            );
        }
    }

    public function test_a_fall_never_notifies(): void
    {
        $this->assertFalse(GarageSeverity::shouldNotify(GarageSeverity::WARNING, GarageSeverity::HIGH));
        $this->assertFalse(GarageSeverity::shouldNotify(GarageSeverity::NORMAL, GarageSeverity::CRITICAL));
    }

    /** `normal` is the absence of a condition. Reaching it is recovery, not news. */
    public function test_normal_is_never_announced(): void
    {
        $this->assertFalse(GarageSeverity::shouldNotify(GarageSeverity::NORMAL, GarageSeverity::NORMAL));
        $this->assertFalse(GarageSeverity::shouldNotify(GarageSeverity::NORMAL, null));
    }

    /**
     * De-escalation is remembered at the NEW level, not the old peak — which is what allows a car
     * that recovers and then deteriorates again to be alerted a second time. Remembering the peak
     * would silence every recurrence forever.
     */
    public function test_recovery_rearms_a_later_climb_back_to_the_same_level(): void
    {
        $remembered = GarageSeverity::settleTo(GarageSeverity::HIGH, GarageSeverity::NORMAL);
        $this->assertSame(GarageSeverity::HIGH, $remembered);

        // Old visits age out of the window; the car settles back to warning. Silent.
        $this->assertFalse(GarageSeverity::shouldNotify(GarageSeverity::WARNING, $remembered));
        $remembered = GarageSeverity::settleTo(GarageSeverity::WARNING, $remembered);
        $this->assertSame(GarageSeverity::WARNING, $remembered, 'the record must drop, not hold the peak');

        // It goes back into the shop and climbs to high again — that IS news.
        $this->assertTrue(GarageSeverity::shouldNotify(GarageSeverity::HIGH, $remembered));
    }

    // ── Plumbing ───────────────────────────────────────────────────────────────────────────────

    public function test_the_headline_is_the_worse_of_the_two_signals(): void
    {
        $this->assertSame(GarageSeverity::HIGH, GarageSeverity::max(GarageSeverity::WARNING, GarageSeverity::HIGH));
        $this->assertSame(GarageSeverity::HIGH, GarageSeverity::max(GarageSeverity::HIGH, GarageSeverity::WARNING));
        $this->assertSame(GarageSeverity::NORMAL, GarageSeverity::max(GarageSeverity::NORMAL, GarageSeverity::NORMAL));
    }

    public function test_an_unknown_level_is_treated_as_normal_rather_than_trusted(): void
    {
        $this->assertSame(0, GarageSeverity::rank('catastrophic'));
        $this->assertSame(GarageSeverity::NORMAL, GarageSeverity::normalise('catastrophic'));
        $this->assertFalse(GarageSeverity::shouldNotify('catastrophic', GarageSeverity::NORMAL));
    }

    /**
     * The bell only understands critical|warning|info|success. `high` must not be flattened into a
     * warning — a car with five garage visits this month is not the same news as one with three.
     */
    public function test_high_and_critical_both_present_as_critical_on_the_bell(): void
    {
        $this->assertSame('critical', GarageSeverity::alertSeverity(GarageSeverity::CRITICAL));
        $this->assertSame('critical', GarageSeverity::alertSeverity(GarageSeverity::HIGH));
        $this->assertSame('warning',  GarageSeverity::alertSeverity(GarageSeverity::WARNING));
        $this->assertSame('info',     GarageSeverity::alertSeverity(GarageSeverity::NORMAL));
    }

    /**
     * Both alert types must be claimed by a real inbox tab. A type nobody claims falls through to the
     * catch-all `other` bucket — the alert is still delivered, but it lands in the drawer nobody
     * opens, which for a standing operational signal is the same as not sending it.
     */
    public function test_both_signals_are_filed_under_a_real_inbox_tab(): void
    {
        foreach (['garage_visit_frequency', 'garage_downtime'] as $type) {
            $this->assertSame(
                'progress',
                \App\Support\NotificationCategories::categoryOf($type),
                "{$type} must not fall through to the catch-all tab"
            );
        }
    }
}
