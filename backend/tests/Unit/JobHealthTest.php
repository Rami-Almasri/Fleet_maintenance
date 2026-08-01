<?php

namespace Tests\Unit;

use App\Services\Intelligence\Readiness\IntelligenceSnapshot;
use Tests\TestCase;

/**
 * Background-job health, judged from the trace a job leaves rather than from a run log.
 *
 * These four states are not decoration. The Intelligence Center's first run against the real fleet
 * found `intelligence:rebuild-signatures` — the corpus EVERY capability reads from — producing fresh
 * output and absent from the scheduler entirely. Somebody had been running it by hand. On a count of
 * rows, or on a freshness check alone, that job looked perfectly healthy.
 */
class JobHealthTest extends TestCase
{
    /**
     * THE LOAD-BEARING CASE. Fresh output plus no schedule is the failure that hides: the data looks
     * current right up until the person running it by hand stops.
     */
    public function test_fresh_output_does_not_excuse_an_unscheduled_job(): void
    {
        $this->assertSame('unscheduled', IntelligenceSnapshot::judgeJob(scheduled: false, ageDays: 0, toleranceDays: 30));
    }

    public function test_a_scheduled_job_within_tolerance_is_ok(): void
    {
        $this->assertSame('ok', IntelligenceSnapshot::judgeJob(true, 1, 7));
        $this->assertSame('ok', IntelligenceSnapshot::judgeJob(true, 7, 7), 'The boundary is inclusive.');
    }

    public function test_output_older_than_tolerance_is_stale(): void
    {
        $this->assertSame('stale', IntelligenceSnapshot::judgeJob(true, 8, 7));
    }

    /**
     * Distinct from `stale` on purpose. A job with nothing to do yet — outcomes are judged ninety days
     * after the fact, so this fleet has produced none — is not a job that broke. Collapsing the two
     * would mean either alerting on a healthy new pipeline or missing one that died.
     */
    public function test_never_having_produced_output_is_its_own_state(): void
    {
        $this->assertSame('never produced output', IntelligenceSnapshot::judgeJob(true, null, 7));
    }

    public function test_unscheduled_outranks_every_other_signal(): void
    {
        foreach ([null, 0, 999] as $age) {
            $this->assertSame('unscheduled', IntelligenceSnapshot::judgeJob(false, $age, 7));
        }
    }
}
