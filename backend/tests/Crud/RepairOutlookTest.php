<?php

namespace Tests\Crud;

use App\Models\ActionCatalog;
use App\Models\FindingKeyword;
use App\Models\KeywordProfile;
use App\Models\Maintenance;
use App\Models\MaintenanceTask;
use App\Services\Garage\RepairOutlook;
use App\Services\GarageRecommendationService;
use Illuminate\Support\Facades\DB;

/**
 * "What the garage will do" — the block a supervisor reads before authorising the work.
 *
 * Needs a database because the whole point of the service is reading the fault vocabulary, which is why
 * it lives here rather than in the DB-free Unit suite that pins garage ranking.
 *
 * Builds its own concepts rather than leaning on the seeded ontology: these are assertions about the
 * SERVICE, and a test that silently depends on 105 seeded concepts fails for the wrong reason the day
 * someone renames one.
 *
 * The rule worth defending is the NEGATIVE one. It is trivially easy to make this fall back to the
 * fuzzy matcher for a finding it does not recognise, and that would put invented repair steps in front
 * of the person signing off the job — where they read as fact, not as a suggestion. A blank is the
 * correct answer, and `test_a_hand_written_finding_is_never_guessed_at` is what keeps it.
 */
class RepairOutlookTest extends CrudTestCase
{
    /**
     * A fault concept with its causes and its repairs, as the ontology seeder would leave it.
     *
     * @param  array<int,string>  $causes
     * @param  array<int,array{0:string,1:string}>  $repairs  [label, relevance]
     */
    private function concept(string $name, array $causes, array $repairs, string $risk = 'moderate'): FindingKeyword
    {
        $keyword = FindingKeyword::create([
            'category_key' => 'engine', 'category_label' => 'Engine',
            'keyword' => $name, 'risk' => $risk, 'is_active' => true,
        ]);

        KeywordProfile::create([
            'finding_keyword_id' => $keyword->id,
            'likely_causes'      => $causes,
        ]);

        foreach ($repairs as $order => [$label, $relevance]) {
            $action = ActionCatalog::create([
                'slug'  => 'act-'.uniqid(), 'verb' => 'replace', 'target' => 'part',
                'label' => $label, 'category_key' => 'engine', 'is_active' => true,
            ]);

            DB::table('fault_concept_actions')->insert([
                'finding_keyword_id' => $keyword->id,
                'action_catalog_id'  => $action->id,
                'relevance'          => $relevance,
                'sort_order'         => ($order + 1) * 10,
                'source'             => 'seed',
                'created_at'         => now(), 'updated_at' => now(),
            ]);
        }

        return $keyword;
    }

    /** @param array<int,string> $symptoms */
    private function outlook(array $symptoms): array
    {
        return app(RepairOutlook::class)->for(array_map(
            fn (string $s) => ['symptom' => $s, 'category_key' => 'engine', 'label' => 'Engine'],
            $symptoms,
        ));
    }

    public function test_a_known_fault_carries_the_work_it_usually_takes(): void
    {
        $this->concept(
            'Rough idle / misfire',
            ['Worn spark plugs / coils', 'Clogged / faulty fuel injector'],
            [['Replace spark plugs', 'typical'], ['Clean throttle body', 'possible']],
        );

        $rows = $this->outlook(['Rough idle / misfire']);

        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['known']);
        $this->assertSame(['Worn spark plugs / coils', 'Clogged / faulty fuel injector'], $rows[0]['causes']);
        $this->assertSame(['Replace spark plugs', 'Clean throttle body'], array_column($rows[0]['fixes'], 'label'));
        $this->assertSame('Moderate', $rows[0]['risk_label']);
    }

    public function test_a_hand_written_finding_is_never_guessed_at(): void
    {
        // Deliberately close to the real concept. A fuzzy matcher would happily land this on it and
        // print a confident repair list for a fault nobody has confirmed.
        $this->concept('Engine noise', ['Worn timing chain'], [['Replace timing chain', 'typical']]);

        $rows = $this->outlook(['weird engine noise i heard near the front']);

        $this->assertFalse($rows[0]['known']);
        $this->assertSame([], $rows[0]['causes']);
        $this->assertSame([], $rows[0]['fixes']);
        $this->assertSame('weird engine noise i heard near the front', $rows[0]['symptom']);
    }

    public function test_two_faults_in_one_category_are_both_described(): void
    {
        // The reason this is keyed per FINDING and not per category: `per_fault` collapses a category to
        // one row and keeps only its first symptom, so a supervisor would have seen the expected work
        // for one of these and never known the other was there.
        $this->concept('Rough idle / misfire', ['Worn spark plugs'], [['Replace spark plugs', 'typical']]);
        $this->concept('Overheating', ['Coolant leak'], [['Replace thermostat', 'typical']], 'critical');

        $rows = $this->outlook(['Rough idle / misfire', 'Overheating']);

        $this->assertCount(2, $rows);
        $this->assertSame(['Rough idle / misfire', 'Overheating'], array_column($rows, 'symptom'));
        $this->assertTrue($rows[0]['known'] && $rows[1]['known']);
        $this->assertSame(['Coolant leak'], $rows[1]['causes']);
    }

    public function test_a_fault_is_matched_however_it_was_capitalised_or_spaced(): void
    {
        $this->concept('Overheating', ['Coolant leak'], [['Replace thermostat', 'typical']]);

        $rows = $this->outlook(['  OVERHEATING  ']);

        $this->assertTrue($rows[0]['known'], 'matching normalises like the rest of the vocabulary');
    }

    public function test_the_same_fault_logged_twice_is_one_row_of_expected_work(): void
    {
        $this->concept('Overheating', ['Coolant leak'], [['Replace thermostat', 'typical']]);

        $this->assertCount(1, $this->outlook(['Overheating', 'overheating']));
    }

    public function test_typical_repairs_are_offered_before_merely_possible_ones(): void
    {
        // Seeded in the WRONG order on purpose — the 'possible' repair sorts first by sort_order.
        $this->concept('Overheating', ['Coolant leak'], [
            ['Replace radiator', 'possible'],
            ['Replace thermostat', 'typical'],
        ]);

        $labels = array_column($this->outlook(['Overheating'])[0]['fixes'], 'label');

        // Not cosmetic: the first repairs listed are the ones a supervisor quotes down the phone, so a
        // long-shot surfacing above the usual fix misrepresents the job.
        $this->assertSame(['Replace thermostat', 'Replace radiator'], $labels);
    }

    public function test_a_ticket_with_no_findings_produces_nothing_rather_than_failing(): void
    {
        $this->assertSame([], app(RepairOutlook::class)->for([]));
        $this->assertSame([], app(RepairOutlook::class)->for([['symptom' => '  ', 'category_key' => null]]));
    }

    // ── On the ticket ────────────────────────────────────────────────────────────────────────────
    //
    // The block moved off the assign step onto the ticket, which is read by everyone rather than by one
    // supervisor once. That move is only honest if the ticket can answer the question WITHOUT scoring a
    // dozen garages first — these pin the standalone path and the route that exposes it.

    /** A ticket with one promoted fault per symptom. */
    private function ticket(array $symptoms): Maintenance
    {
        $vehicleId = $this->makeVehicle();
        $ticket = Maintenance::create([
            'vehicle_id'      => $vehicleId,
            'workflow_status' => Maintenance::WF_UNDER_REPAIR,
            'event_status'    => 'OUT',
        ]);

        foreach ($symptoms as $symptom) {
            MaintenanceTask::create([
                'maintenance_id' => $ticket->id,
                'vehicle_id'     => $vehicleId,
                'symptom'        => $symptom,
                'category_key'   => 'engine',
                'status'         => MaintenanceTask::STATUS_PENDING,
            ]);
        }

        return $ticket;
    }

    public function test_the_expected_work_is_answerable_from_the_ticket_alone(): void
    {
        $this->concept('Overheating', ['Coolant leak'], [['Replace thermostat', 'typical']], 'critical');

        $rows = app(GarageRecommendationService::class)->outlookForTicket($this->ticket(['Overheating']));

        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['known']);
        $this->assertSame(['Coolant leak'], $rows[0]['causes']);
        $this->assertSame(['Replace thermostat'], array_column($rows[0]['fixes'], 'label'));
    }

    public function test_the_ticket_route_returns_the_expected_work_without_ranking_a_garage(): void
    {
        $this->concept('Overheating', ['Coolant leak'], [['Replace thermostat', 'typical']], 'critical');
        $ticket = $this->ticket(['Overheating']);

        $res = $this->getJson("/api/maintenance-tickets/{$ticket->id}/repair-outlook");

        $res->assertSuccessful();
        $this->assertSame('Overheating', $res->json('data.0.symptom'));
        // The payload is the work and nothing else — no garages, no scores. A ticket reader is not
        // being handed a dispatch decision they did not ask for.
        $this->assertArrayNotHasKey('primary', (array) $res->json('data'));
    }

    public function test_a_finding_that_became_a_fault_carries_its_task_so_history_can_hang_off_it(): void
    {
        // The assign step mounts "Previous Similar Repairs" per fault off exactly this id; without it
        // every fault would fall back to a symptom preview and lose the real repair thread.
        $this->concept('Overheating', ['Coolant leak'], [['Replace thermostat', 'typical']]);
        $ticket = $this->ticket(['Overheating']);

        $detail = app(GarageRecommendationService::class)->forTicket($ticket)['ticket']['faults_detail'];

        $this->assertSame($ticket->tasks()->first()->id, $detail[0]['task_id']);
    }
}
