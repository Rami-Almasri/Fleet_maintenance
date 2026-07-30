<?php

namespace Tests\Unit;

use App\Services\Intelligence\Capabilities\ComebackCapability;
use App\Services\Intelligence\CapabilityContext;
use App\Services\Intelligence\DecisionCard;
use App\Services\Intelligence\Evidence;
use App\Services\RepairIntelligence\Query\HistoricalAnswer;
use App\Services\RepairIntelligence\Query\RepairHistoryQuery;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * The capability, driven entirely by a fake query layer.
 *
 * THIS FILE IS THE ARGUMENT FOR THE QUERY LAYER. There is no database here, no projection, no
 * factory, no migration and no fixture — the capability's whole world is an interface returning
 * HistoricalAnswers, so its business logic can be exercised at any history we care to invent,
 * including histories the real corpus does not contain.
 */
class ComebackCapabilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('features.intelligence.comeback_detection', true);
    }

    /** @param array<string, int> $episodesBySignature signature => how many prior cases */
    private function query(array $episodesBySignature, float $fleetRate = 0.467, int $fleetN = 1200): RepairHistoryQuery
    {
        return new class($episodesBySignature, $fleetRate, $fleetN) implements RepairHistoryQuery
        {
            public function __construct(private array $episodes, private float $rate, private int $n) {}

            public function version(): string
            {
                return 'fake';
            }

            public function findPreviousEpisodes(int $vehicleId, array $signatures, ?int $excludeTicketId = null, ?string $asOf = null, ?int $windowDays = null): HistoricalAnswer
            {
                $grouped = [];
                $id = 100;

                foreach ($this->episodes as $signature => $count) {
                    $rows = [];
                    for ($i = 0; $i < $count; $i++) {
                        $rows[] = (object) [
                            'maintenance_id' => $id++,
                            'signature'      => $signature,
                            'occurred_at'    => now()->subDays(10 + ($i * 15))->toDateString(),
                            'source'         => 'derived',
                        ];
                    }
                    $grouped[$signature] = new Collection($rows);
                }

                if ($grouped === []) {
                    return HistoricalAnswer::empty();
                }

                $all = collect($grouped)->flatten();

                return new HistoricalAnswer(
                    value: $grouped,
                    sampleSize: $all->count(),
                    sourceIds: $all->pluck('maintenance_id')->all(),
                    labelSource: Evidence::LABEL_DERIVED,
                    reconstructionTier: HistoricalAnswer::TIER_DIRECT,
                );
            }

            public function signatureReturnRate(string $signature, ?int $windowDays = null): HistoricalAnswer
            {
                return new HistoricalAnswer(
                    value: ['n' => $this->n, 'returned' => (int) round($this->n * $this->rate), 'rate' => $this->rate],
                    sampleSize: $this->n,
                    labelSource: Evidence::LABEL_MIXED,
                    isProxy: true,
                    proxyNote: 'return rate, not verified repair success',
                );
            }

            public function vehicleHistory(int $vehicleId, ?string $asOf = null, int $limit = 100): HistoricalAnswer
            {
                return HistoricalAnswer::empty();
            }
        };
    }

    private function context(array $signatures = ['COOLING']): CapabilityContext
    {
        return new CapabilityContext(vehicleId: 7, signatures: $signatures, workflowState: 'inspection_pending');
    }

    /** Silence is the normal case and must cost nothing. */
    public function test_no_prior_history_yields_no_card(): void
    {
        $capability = new ComebackCapability($this->query([]));

        $this->assertNull($capability->evaluate($this->context()));
    }

    public function test_a_first_comeback_recommends_a_diagnostic_reset(): void
    {
        $card = (new ComebackCapability($this->query(['COOLING' => 1])))->evaluate($this->context());

        $this->assertInstanceOf(DecisionCard::class, $card);
        $this->assertSame(DecisionCard::TIER_REWORK, $card->tier);
        $this->assertStringContainsString('occurrence 2 of COOLING', $card->observation);
        $this->assertStringContainsString('diagnostic reset', $card->recommendation);
        $this->assertStringNotContainsString('Escalate', $card->recommendation);
    }

    /**
     * THE ESCALATION CASE. A repair that has already come back once returns again 56.9% of the time,
     * so the second comeback must not read like the first.
     */
    public function test_a_repeat_comeback_escalates_and_outranks_a_first(): void
    {
        $escalated = (new ComebackCapability($this->query(['COOLING' => 2])))->evaluate($this->context());
        $first     = (new ComebackCapability($this->query(['COOLING' => 1])))->evaluate($this->context());

        $this->assertStringContainsString('Escalate to supervisor', $escalated->recommendation);
        $this->assertStringContainsString('56.9%', $escalated->reasoning);
        $this->assertTrue($escalated->evidence->facts['is_escalation']);
        $this->assertGreaterThan($first->score(0), $escalated->score(0));
    }

    /** The card interrupts for the worst offender, not whichever signature sorted first. */
    public function test_the_most_recurrent_signature_is_the_one_reported(): void
    {
        $card = (new ComebackCapability($this->query(['BRAKES' => 1, 'COOLING' => 3])))
            ->evaluate($this->context(['BRAKES', 'COOLING']));

        $this->assertSame('COOLING', $card->evidence->facts['signature']);
        $this->assertSame(4, $card->evidence->facts['occurrence']);
    }

    /**
     * The proxy caveat survives the whole journey from query to card. Return rate is not verified
     * repair success, and a card that forgot to say so would be an accusation rather than a signal.
     */
    public function test_the_proxy_caveat_reaches_the_card_and_caps_its_confidence(): void
    {
        $card = (new ComebackCapability($this->query(['COOLING' => 1])))->evaluate($this->context());

        $this->assertTrue($card->evidence->isProxy);
        $this->assertSame('return rate, not verified repair success', $card->evidence->proxyNote);
        $this->assertNotSame('must', $card->strength(), 'A proxy may never speak with full authority.');
    }

    /** Drill-through: the card names the exact prior cases behind it. */
    public function test_the_card_carries_its_prior_cases_for_audit(): void
    {
        $card = (new ComebackCapability($this->query(['COOLING' => 2])))->evaluate($this->context());

        $this->assertCount(2, $card->evidence->sourceIds);
        $this->assertSame($card->evidence->facts['prior_case_ids'], $card->evidence->sourceIds);
    }

    /**
     * appliesTo() asks ONLY "do I have the inputs to answer?". The feature flag, the eligible
     * workflow states and the audience are orchestration, and live in the registered policy — a
     * capability that gated on them would be deciding things it has no view of.
     */
    public function test_applies_to_checks_inputs_only_not_orchestration(): void
    {
        $capability = new ComebackCapability($this->query(['COOLING' => 2]));

        // The flag is the policy's business now, so flipping it changes nothing here.
        config()->set('features.intelligence.comeback_detection', false);
        $this->assertTrue($capability->appliesTo($this->context()));

        // What it DOES check: can this question even be asked?
        $this->assertFalse($capability->appliesTo(new CapabilityContext(vehicleId: 7, signatures: [])));
        $this->assertFalse($capability->appliesTo(new CapabilityContext(vehicleId: null, signatures: ['COOLING'])));
    }

    public function test_the_capability_declares_a_version_for_the_audit_trail(): void
    {
        $this->assertSame('v1', (new ComebackCapability($this->query([])))->version());
    }
}
