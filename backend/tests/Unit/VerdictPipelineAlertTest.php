<?php

namespace Tests\Unit;

use App\Services\Intelligence\Readiness\EvidenceLedger;
use Tests\TestCase;

/**
 * The one alert the platform raises unprompted.
 *
 * The failure it catches is silent: the intelligence layer does not break when verdict capture stops.
 * It keeps producing cards from proxy evidence, looking exactly as healthy as before, while the
 * dataset that would let it improve quietly stops growing. Nothing else in this platform reaches a
 * person without being asked for.
 *
 * The decision is pure so it can be exercised at pipeline states this fleet has not reached yet —
 * gathering the numbers and judging them are separate jobs.
 */
class VerdictPipelineAlertTest extends TestCase
{
    private function stats(array $overrides = []): array
    {
        return array_merge([
            'recent'                 => 10,   // trailing 14 days
            'prior_rate'             => 5.0,  // per week, the 4 weeks before that
            'recent_rate'            => 5.0,  // per week, trailing fortnight
            'closed_without_verdict' => 0,
            'gate_enabled'           => true,
        ], $overrides);
    }

    /** Steady capture raises nothing — an alert that fires on a quiet week is one nobody reads. */
    public function test_steady_capture_raises_no_alert(): void
    {
        $this->assertNull(EvidenceLedger::judgePipeline($this->stats()));
    }

    /**
     * THE SMOKING GUN, and it must be checked before anything else. A disabled gate explains a
     * falling rate completely; reporting it as "capture has slowed" would send someone to talk to
     * inspectors who are doing nothing wrong.
     */
    public function test_a_disabled_gate_is_reported_as_the_cause_not_as_a_slowdown(): void
    {
        // Deliberately a HEALTHY rate: a config bypass must not be masked by good recent numbers.
        $alert = EvidenceLedger::judgePipeline($this->stats(['gate_enabled' => false]));

        $this->assertNotNull($alert);
        $this->assertSame('critical', $alert['severity']);
        $this->assertStringContainsString('DISABLED', $alert['headline']);
        $this->assertStringContainsString('MAINT_REQUIRE_QC_VERDICT', $alert['detail']);
    }

    /** Gate on, nothing arriving, and repaired cars closing anyway — the queue is being passed over. */
    public function test_silence_with_the_gate_on_is_critical_and_names_the_difference(): void
    {
        $alert = EvidenceLedger::judgePipeline($this->stats([
            'recent' => 0, 'recent_rate' => 0.0, 'closed_without_verdict' => 8,
        ]));

        $this->assertSame('critical', $alert['severity']);
        $this->assertStringContainsString('No QC verdicts recorded', $alert['headline']);
        $this->assertStringContainsString('passed over rather than bypassed in config', $alert['detail']);
    }

    /** Nothing arriving but nothing closing either is a quiet fleet, not a broken pipeline. */
    public function test_silence_with_nothing_closing_is_not_an_alert(): void
    {
        $this->assertNull(EvidenceLedger::judgePipeline($this->stats([
            'recent' => 0, 'recent_rate' => 0.0, 'closed_without_verdict' => 0,
        ])));
    }

    /** A halving is the threshold — smaller swings are ordinary repair-volume noise. */
    public function test_a_halved_rate_warns(): void
    {
        $alert = EvidenceLedger::judgePipeline($this->stats([
            'prior_rate' => 10.0, 'recent_rate' => 2.0, 'recent' => 4, 'closed_without_verdict' => 3,
        ]));

        $this->assertSame('warning', $alert['severity']);
        $this->assertStringContainsString('slowing', $alert['headline']);
        $this->assertStringContainsString('2.0/week from 10.0/week', $alert['detail']);
    }

    public function test_a_mild_dip_does_not_warn(): void
    {
        $this->assertNull(EvidenceLedger::judgePipeline($this->stats([
            'prior_rate' => 5.0, 'recent_rate' => 4.0,
        ])), 'Normal variation must not page anyone.');
    }

    /** A pipeline that has never produced anything has no baseline to fall from. */
    public function test_a_pipeline_with_no_history_does_not_warn_on_rate(): void
    {
        $this->assertNull(EvidenceLedger::judgePipeline($this->stats([
            'prior_rate' => 0.0, 'recent_rate' => 0.0, 'recent' => 0, 'closed_without_verdict' => 0,
        ])));
    }

    /** Severity drives who gets woken up, so the two critical causes must stay distinguishable. */
    public function test_the_two_critical_causes_carry_different_headlines(): void
    {
        $disabled = EvidenceLedger::judgePipeline($this->stats(['gate_enabled' => false]));
        $skipped  = EvidenceLedger::judgePipeline($this->stats([
            'recent' => 0, 'recent_rate' => 0.0, 'closed_without_verdict' => 5,
        ]));

        $this->assertNotSame($disabled['headline'], $skipped['headline']);
        $this->assertFalse($disabled['gate_enabled']);
        $this->assertTrue($skipped['gate_enabled']);
    }
}
