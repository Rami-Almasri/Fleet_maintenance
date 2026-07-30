<?php

namespace Tests\Unit;

use App\Models\Maintenance;
use App\Models\MaintenanceTask;
use App\Services\MaintenanceWorkflowService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The QC routing rule: a car a garage repaired must not close without a human verdict.
 *
 * This is an EVIDENCE-CAPTURE rule, not a recommendation, which is why it is the only thing in the
 * feature file that defaults to on. A verdict missed at close cannot be recovered — the car has
 * gone, and nobody reconstructs months later whether the repair held.
 */
class QualityVerdictRoutingTest extends TestCase
{
    private function needsVerdict(Maintenance $ticket): bool
    {
        $m = new ReflectionMethod(MaintenanceWorkflowService::class, 'needsQualityVerdict');
        $m->setAccessible(true);

        return $m->invoke(app(MaintenanceWorkflowService::class), $ticket);
    }

    /** A ticket stub whose tasks() relation answers without touching the database. */
    private function ticket(?int $vendorId, int $openFaults): Maintenance
    {
        // Defaults are required: Eloquent re-instantiates the model internally (event registration,
        // newInstance) with no constructor arguments.
        return new class($vendorId, $openFaults) extends Maintenance
        {
            public function __construct(private ?int $v = null, private int $faults = 0)
            {
                parent::__construct();
                $this->vendor_id = $v;
            }

            public function tasks(): \Illuminate\Database\Eloquent\Relations\HasMany
            {
                $rel = \Mockery::mock(\Illuminate\Database\Eloquent\Relations\HasMany::class);
                $rel->shouldReceive('whereNotIn')->andReturnSelf();
                $rel->shouldReceive('exists')->andReturn($this->faults > 0);

                return $rel;
            }
        };
    }

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('features.maintenance.require_qc_verdict', true);
    }

    /**
     * THE CASE THAT WAS LEAKING. Every one of the 12 uncovered tickets looked like this: graded
     * routine, but with a garage and a real fault. Severity describes how urgent the fault was, not
     * whether a workshop took the car apart.
     */
    public function test_a_routine_ticket_with_garage_work_still_needs_a_verdict(): void
    {
        $this->assertTrue($this->needsVerdict($this->ticket(vendorId: 3, openFaults: 1)));
    }

    /** No garage means no workshop repair to judge. */
    public function test_a_ticket_that_never_reached_a_garage_does_not(): void
    {
        $this->assertFalse($this->needsVerdict($this->ticket(vendorId: null, openFaults: 2)));
    }

    /** No faults means no outcome to record. */
    public function test_a_ticket_with_nothing_to_fix_does_not(): void
    {
        $this->assertFalse($this->needsVerdict($this->ticket(vendorId: 3, openFaults: 0)));
    }

    /**
     * The escape hatch has to work: this rule adds a real inspector step to jobs that used to close
     * themselves, and if that queue becomes the bottleneck it must be switchable without a deploy.
     */
    public function test_the_rule_can_be_switched_off(): void
    {
        config()->set('features.maintenance.require_qc_verdict', false);

        $this->assertFalse($this->needsVerdict($this->ticket(vendorId: 3, openFaults: 1)));
    }

    /** It defaults ON — the evidence it protects is unrecoverable. */
    public function test_it_is_on_by_default(): void
    {
        $this->assertTrue(config('features.maintenance.require_qc_verdict'));
    }
}
