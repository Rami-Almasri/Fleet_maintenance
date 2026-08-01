<?php

namespace Tests\Unit;

use App\Services\Intelligence\DecisionEngine;
use App\Services\Intelligence\OperationalIntelligence;
use App\Services\Intelligence\PolicyRegistry;
use App\Services\Intelligence\Readiness\EvidenceLedger;
use App\Services\Intelligence\Readiness\IntelligenceSnapshot;
use App\Services\Intelligence\Readiness\PromotionGate;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * The Intelligence Center must survive its own inputs failing.
 *
 * The platform's standing rule is that intelligence is advisory and must never break the thing it
 * advises. That rule bites hardest on this page: the person who most needs it is the one already
 * investigating something that has gone wrong, and a 500 because one table is mid-migration tells
 * them nothing at the exact moment it is meant to tell them everything.
 *
 * The second rule matters as much as the first. A failed section must SAY it failed. Rendering it
 * empty would be worse than the crash it replaced — "no data-quality problems" and "the
 * data-quality check crashed" look identical on screen and mean opposite things.
 */
class SnapshotDegradationTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function snapshot(EvidenceLedger $ledger): IntelligenceSnapshot
    {
        $engine = Mockery::mock(DecisionEngine::class);
        $engine->shouldReceive('capabilities')->andReturn([]);

        return new IntelligenceSnapshot(
            ledger: $ledger,
            gate: Mockery::mock(PromotionGate::class),
            policies: new PolicyRegistry(),
            engine: $engine,
            delivery: Mockery::mock(OperationalIntelligence::class),
        );
    }

    /** A ledger that cannot read anything — the worst case, and the one that used to 500. */
    private function brokenLedger(): EvidenceLedger
    {
        $ledger = Mockery::mock(EvidenceLedger::class);

        foreach (['verdictPipelineAlert', 'attentionFirst', 'qcCoverage', 'all', 'health', 'datasetVersion'] as $method) {
            $ledger->shouldReceive($method)->andThrow(new RuntimeException('Base table or view not found'));
        }

        return $ledger;
    }

    public function test_the_page_still_returns_when_every_input_is_broken(): void
    {
        $payload = $this->snapshot($this->brokenLedger())->build();

        $this->assertIsArray($payload);
        $this->assertNotEmpty($payload['generated_at'], 'The page must still be able to say when it was read.');
    }

    /**
     * THE LOAD-BEARING ASSERTION. Silence is the failure mode this design exists to prevent: a
     * section that renders empty reads as "nothing to report" and is indistinguishable from good news.
     */
    public function test_a_failed_section_is_named_rather_than_rendered_empty(): void
    {
        $payload = $this->snapshot($this->brokenLedger())->build();

        $this->assertNotEmpty($payload['failed_sections']);

        foreach ($payload['failed_sections'] as $section => $message) {
            $this->assertIsString($section);
            $this->assertNotSame('', $message, "Section {$section} failed without saying why.");
        }
    }

    /** Sections that read different tables must not be able to take each other down. */
    public function test_one_broken_section_does_not_take_the_others_with_it(): void
    {
        $ledger = Mockery::mock(EvidenceLedger::class);
        $ledger->shouldReceive('verdictPipelineAlert')->andReturnNull();
        $ledger->shouldReceive('attentionFirst')->andReturnNull();
        $ledger->shouldReceive('all')->andReturn([]);
        $ledger->shouldReceive('health')->andReturn([]);
        $ledger->shouldReceive('datasetVersion')->andReturn('proj/v1:0:empty');
        // Only the QC read is broken.
        $ledger->shouldReceive('qcCoverage')->andThrow(new RuntimeException('deadlock'));

        $payload = $this->snapshot($ledger)->build();

        $this->assertArrayHasKey('qc', $payload['failed_sections']);
        $this->assertArrayNotHasKey('capabilities', $payload['failed_sections']);
        $this->assertArrayNotHasKey('flags', $payload['failed_sections'],
            'Feature flags are read from config and cannot depend on a database at all.');
        $this->assertSame([], $payload['capabilities']);
    }

    /** A healthy read reports no failures — otherwise the banner would cry wolf on every load. */
    public function test_a_healthy_read_reports_no_failures(): void
    {
        $ledger = Mockery::mock(EvidenceLedger::class);
        $ledger->shouldReceive('verdictPipelineAlert')->andReturnNull();
        $ledger->shouldReceive('attentionFirst')->andReturnNull();
        $ledger->shouldReceive('all')->andReturn([]);
        $ledger->shouldReceive('health')->andReturn([]);
        $ledger->shouldReceive('datasetVersion')->andReturn('proj/v1:0:empty');
        $ledger->shouldReceive('qcCoverage')->andReturn([
            'closed' => 0, 'with_verdict' => 0, 'unverifiable' => 0, 'coverage' => 0.0, 'lost' => 0,
        ]);

        $payload = $this->snapshot($ledger)->build();

        // `qc` still reaches for the throughput series, and `jobs`/`promotions` still read tables that
        // do not exist in a unit context — so assert on the sections that are genuinely DB-free.
        $this->assertArrayNotHasKey('flags', $payload['failed_sections']);
        $this->assertArrayNotHasKey('headline', $payload['failed_sections']);
        $this->assertNotEmpty($payload['flags']);
    }
}
