<?php

namespace Tests\Unit;

use App\Services\Garage\PerFaultRecommender;
use PHPUnit\Framework\TestCase;

/**
 * DB-free tests for the per-fault layer, and specifically for the one thing it must never do again:
 * name a different garage from the ticket-level call without saying so.
 *
 * THE BUG THESE LOCK. This layer ranks garages by fault coverage; the ticket-level call ranks them by
 * overall fit, which weighs same-model history and reliability alongside raw repair volume. On a
 * single-fault ticket both answer the same question, so a disagreement put two contradictory
 * recommendations on one screen — the header saying "send this fault to FUTURE TYRES" while the fault
 * card put a green tick beside a garage with 48 repairs of that fault and none on the car in question.
 *
 * Coverage points are comparable ACROSS evidence tiers, which is what made the contradiction possible:
 * volume on other models can out-point same-model history. So the ticket's call stands unless a garage
 * is materially ahead AND standing on evidence at least as specific.
 */
class PerFaultRecommenderTest extends TestCase
{
    private PerFaultRecommender $rec;

    protected function setUp(): void
    {
        $this->rec = new PerFaultRecommender();
    }

    /** One scored-garage bucket, in the shape GarageRecommendationService hands over. */
    private function garage(int $id, string $name, float $points, string $tier, int $sameModel, int $atGarage, int $matchScore): array
    {
        return [
            'vendor_id'   => $id,
            'garage'      => $name,
            'match_score' => $matchScore,
            'breakdown'   => ['fault_points' => ['tyres' => [
                'points'     => $points,
                'tier'       => $tier,
                'same_model' => $sameModel,
                'at_garage'  => $atGarage,
            ]]],
        ];
    }

    /** A forecast row — deliberately identical for every garage so only evidence decides the ranking. */
    private function outcome(): array
    {
        return [
            'duration_days' => ['value' => 1.0, 'basis' => 'garage', 'sample' => 30],
            'success_pct'   => ['value' => 70.0, 'basis' => 'garage', 'sample' => 30],
            'comeback_pct'  => ['value' => 30.0, 'basis' => 'garage', 'sample' => 30],
            'cost_aed'      => ['value' => 400.0, 'basis' => 'garage', 'sample' => 30],
            'cost_by_fault' => [],
            'queue_open'    => ['value' => 0],
            'start_in_days' => 0,
        ];
    }

    private function perFault(array $garages, ?int $ticketPick): array
    {
        $outcomes = [];
        foreach ($garages as $g) {
            $outcomes[$g['vendor_id']] = $this->outcome();
        }

        return $this->rec->recommend(
            $garages, ['tyres'], [], ['tyres' => 'Tyres'], $outcomes,
            [['category_key' => 'tyres', 'symptom' => 'Worn tyre']], 'CHARGER', $ticketPick,
        );
    }

    public function test_a_narrow_coverage_lead_does_not_overrule_the_ticket_call(): void
    {
        // 0.72 vs 0.66 — six points. Real enough to sort a list by, nowhere near enough to justify the
        // panel contradicting its own header.
        $out = $this->perFault([
            $this->garage(1, 'Deals On Wheels', 0.72, 'exact', 4, 48, 61),
            $this->garage(2, 'FUTURE TYRES', 0.66, 'exact', 9, 31, 66),
        ], 2);

        $this->assertSame('agrees', $out[0]['standing']);
        $this->assertSame('FUTURE TYRES', $out[0]['winner']['garage']);
        $this->assertSame('Deals On Wheels', $out[0]['alternative']['garage']);
    }

    public function test_a_big_lead_bought_with_thinner_evidence_does_not_overrule_the_ticket_call(): void
    {
        // THE REPORTED CASE. 48 repairs of this fault, none of them on a CHARGER (domain tier), against
        // a garage with genuine same-model history (exact tier). The bigger number must not win.
        $out = $this->perFault([
            $this->garage(1, 'Deals On Wheels', 0.90, 'domain', 0, 48, 61),
            $this->garage(2, 'FUTURE TYRES', 0.55, 'exact', 9, 31, 66),
        ], 2);

        $this->assertSame('agrees', $out[0]['standing']);
        $this->assertSame('FUTURE TYRES', $out[0]['winner']['garage']);
    }

    public function test_a_materially_better_garage_on_equal_or_firmer_evidence_does_displace_the_call(): void
    {
        // Same tier, and a 35-point lead. This is a real reason to send the fault elsewhere, and saying
        // so is the whole value of a per-fault view — it just has to be labelled as a disagreement.
        $out = $this->perFault([
            $this->garage(1, 'Deals On Wheels', 0.90, 'exact', 22, 48, 61),
            $this->garage(2, 'FUTURE TYRES', 0.55, 'exact', 3, 31, 66),
        ], 2);

        $this->assertSame('displaced', $out[0]['standing']);
        $this->assertSame('Deals On Wheels', $out[0]['winner']['garage']);
    }

    public function test_a_ticket_garage_with_no_record_of_the_fault_is_reported_as_absent(): void
    {
        // Below MIN_CREDIBLE_COVERAGE the ticket's garage is not an answer for this fault at all.
        // Presenting it here anyway would be the mirror image of the bug.
        $out = $this->perFault([
            $this->garage(1, 'Deals On Wheels', 0.90, 'exact', 22, 48, 61),
            $this->garage(2, 'FUTURE TYRES', 0.02, 'none', 0, 0, 66),
        ], 2);

        $this->assertSame('pick_absent', $out[0]['standing']);
        $this->assertSame('Deals On Wheels', $out[0]['winner']['garage']);
    }

    public function test_the_presented_winner_is_never_offered_as_its_own_alternative(): void
    {
        // The alternative used to be "whatever is at index 1", which was safe only while the winner was
        // always at index 0. Once the ticket call can promote a lower-ranked garage, position is wrong.
        $out = $this->perFault([
            $this->garage(1, 'Deals On Wheels', 0.80, 'exact', 4, 48, 61),
            $this->garage(2, 'FUTURE TYRES', 0.74, 'exact', 9, 31, 66),
            $this->garage(3, 'RMR', 0.70, 'exact', 2, 12, 50),
        ], 2);

        $winner = $out[0]['winner'];
        $this->assertSame('FUTURE TYRES', $winner['garage']);
        $this->assertNotSame($winner['vendor_id'], $out[0]['alternative']['vendor_id']);
        $this->assertSame('Deals On Wheels', $out[0]['alternative']['garage']);
    }

    public function test_the_repair_outlook_is_passed_in_never_queried_by_the_core(): void
    {
        // REGRESSION GUARD, and the reason it belongs in a test rather than a comment: RepairOutlook
        // reads the fault ontology through Eloquent, and scoreRows is contractually DB-free. Calling it
        // from inside the core made every test in GarageRecommendationServiceTest depend on a database
        // connection those tests deliberately do not have — the whole file died on one added line.
        //
        // Asserted by reading the source: a unit test cannot prove the absence of a query, but it can
        // prove the core never reaches for the collaborator that issues one.
        // Bounded to scoreRows() ITSELF, not "everything after it". Slicing to end-of-file made the
        // guard fire on any LATER method that legitimately reaches for the outlook — outlookForTicket()
        // is a DB-backed entry point where doing so is exactly right — so the test was reporting a
        // violation in a method it was never about.
        $core = file_get_contents(dirname(__DIR__, 2).'/app/Services/GarageRecommendationService.php');
        $from = (int) strpos($core, 'public function scoreRows');
        $next = strpos($core, "\n    public function ", $from + 1);
        $scoreRows = $next === false ? substr($core, $from) : substr($core, $from, $next - $from);

        $this->assertStringNotContainsString('repairOutlook->', $scoreRows,
            'scoreRows() must receive the repair outlook through $ctx, never resolve it itself.');
        $this->assertStringContainsString("\$ctx['repair_outlook']", $scoreRows,
            'The core still has to publish the outlook it was handed.');
    }

    public function test_with_no_ticket_call_the_coverage_leader_still_leads(): void
    {
        // The ticket-free finder scores a hypothetical car with no assignment in play.
        $out = $this->perFault([
            $this->garage(1, 'Deals On Wheels', 0.80, 'exact', 4, 48, 61),
            $this->garage(2, 'FUTURE TYRES', 0.74, 'exact', 9, 31, 66),
        ], null);

        $this->assertSame('no_ticket_call', $out[0]['standing']);
        $this->assertSame('Deals On Wheels', $out[0]['winner']['garage']);
    }
}
