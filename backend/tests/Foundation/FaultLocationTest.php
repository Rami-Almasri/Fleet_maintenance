<?php

namespace Tests\Foundation;

use App\Models\Maintenance;
use App\Models\MaintenanceTask;
use App\Models\MaintenanceTaskLocation;
use App\Models\Vehicle;
use App\Models\VehicleLocation;
use App\Services\FaultLocationService;
use App\Services\MaintenanceTaskService;

/**
 * The WHERE axis against a real schema: persistence, the child-table invariants, and the API shape.
 *
 * The policy and the sentence are locked DB-free elsewhere (FaultLocationPolicyTest, FaultPhraseTest).
 * What can only be proved here is that a fault's places actually survive a write, that the schema
 * refuses the two things it must (duplicate places, a deleted vocabulary row that history points at),
 * and that a fault recorded before any of this existed still reads correctly.
 *
 * DatabaseTransactions via FoundationTestCase — no DDL, nothing persisted. See phpunit.foundation.xml.
 */
class FaultLocationTest extends FoundationTestCase
{
    private function svc(): FaultLocationService
    {
        return app(FaultLocationService::class);
    }

    /** A ticket + one fault on it, the way MaintenanceTaskService promotes findings. */
    private function fault(array $attributes = []): MaintenanceTask
    {
        $vehicle = Vehicle::query()->first() ?? Vehicle::factory()->create();

        $ticket = Maintenance::create([
            'vehicle_id'      => $vehicle->id,
            'workflow_status' => Maintenance::WF_INSPECTION_PENDING,
        ]);

        return MaintenanceTask::create(array_merge([
            'maintenance_id' => $ticket->id,
            'vehicle_id'     => $vehicle->id,
            'symptom'        => 'Scratch',
            'category_key'   => 'bodywork',
            'status'         => MaintenanceTask::STATUS_PENDING,
        ], $attributes));
    }

    // ── The vocabulary is seeded and shared ───────────────────────────────────────────────────────

    public function test_the_vocabulary_is_seeded(): void
    {
        $this->assertGreaterThan(20, VehicleLocation::query()->active()->count());
        $this->assertNotNull(VehicleLocation::query()->where('slug', 'rims')->first());
        $this->assertNotNull(VehicleLocation::query()->where('slug', 'front_bumper')->first());
    }

    /**
     * The join back to the inspection hotspot diagram. If this breaks, the location vocabulary has
     * quietly become the second location system it exists to avoid.
     */
    public function test_panels_carry_their_inspection_diagram_zone(): void
    {
        $this->assertSame('front_bumper', VehicleLocation::query()->where('slug', 'front_bumper')->value('inspection_zone'));
        $this->assertSame('door_rear_left', VehicleLocation::query()->where('slug', 'door_rear_left')->value('inspection_zone'));
    }

    public function test_the_seeder_stamped_the_policy_onto_the_type_catalogs(): void
    {
        $this->assertSame('required', \App\Models\FaultCatalog::query()->where('slug', 'body_scratch')->value('location_mode'));
        // The wiper case — a `required` category, overridden to `none` by its own row.
        $this->assertSame('none', \App\Models\FaultCatalog::query()->where('slug', 'light_wiper_washer')->value('location_mode'));
    }

    // ── Persistence ───────────────────────────────────────────────────────────────────────────────

    public function test_a_fault_keeps_its_places_in_the_order_they_were_picked(): void
    {
        $task = $this->fault();
        $this->svc()->sync($task, ['rims', 'body']);

        $this->assertSame(['rims', 'body'], $this->svc()->locationRefs($task->fresh())->pluck('key')->all());
    }

    public function test_re_syncing_replaces_rather_than_accumulates(): void
    {
        $task = $this->fault();
        $this->svc()->sync($task, ['rims', 'body']);
        $this->svc()->sync($task, ['hood']);

        $this->assertSame(['hood'], $this->svc()->locationRefs($task->fresh())->pluck('key')->all());
        $this->assertSame(1, MaintenanceTaskLocation::query()->where('maintenance_task_id', $task->id)->count());
    }

    /** An explicit empty list is a person REMOVING a location, and it must stick. */
    public function test_syncing_an_empty_list_clears_the_places(): void
    {
        $task = $this->fault();
        $this->svc()->sync($task, ['rims']);
        $this->svc()->sync($task, []);

        $this->assertSame([], $this->svc()->locationRefs($task->fresh())->all());
    }

    public function test_duplicates_collapse_to_one_row(): void
    {
        $task = $this->fault();
        $this->svc()->sync($task, ['rims', 'rims', 'rims']);

        $this->assertSame(1, MaintenanceTaskLocation::query()->where('maintenance_task_id', $task->id)->count());
    }

    public function test_an_unknown_place_is_dropped_and_never_written(): void
    {
        $task = $this->fault();
        $stored = $this->svc()->sync($task, ['rims', 'a_place_that_does_not_exist']);

        $this->assertSame(['rims'], $stored);
        $this->assertSame(1, MaintenanceTaskLocation::query()->where('maintenance_task_id', $task->id)->count());
    }

    /**
     * A type with nowhere to point at never accumulates places, even if a stale client sends some.
     * Ignored rather than refused: losing a real report over a field the operator never saw is worse.
     */
    public function test_a_type_with_no_where_stores_nothing(): void
    {
        $task = $this->fault(['symptom' => 'Overheating', 'category_key' => 'engine']);
        $stored = $this->svc()->sync($task, ['hood']);

        $this->assertSame([], $stored);
        $this->assertSame(0, MaintenanceTaskLocation::query()->where('maintenance_task_id', $task->id)->count());
    }

    public function test_deleting_a_fault_takes_its_places_with_it(): void
    {
        $task = $this->fault();
        $this->svc()->sync($task, ['rims']);
        $id = $task->id;
        $task->delete();

        $this->assertSame(0, MaintenanceTaskLocation::query()->where('maintenance_task_id', $id)->count());
    }

    /**
     * A place that history points at may not be deleted out from under it — retire it with
     * is_active=false instead. The restrictOnDelete FK is what enforces that.
     */
    public function test_a_place_in_use_cannot_be_deleted(): void
    {
        $task = $this->fault();
        $this->svc()->sync($task, ['rims']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        VehicleLocation::query()->where('slug', 'rims')->delete();
    }

    // ── Quantity ──────────────────────────────────────────────────────────────────────────────────

    public function test_quantity_defaults_to_one_occurrence(): void
    {
        $this->assertSame(1, (int) $this->fault()->fresh()->quantity);
    }

    public function test_quantity_is_stored_beside_the_fault_not_as_more_faults(): void
    {
        $task = $this->fault(['quantity' => 2]);

        $this->assertSame(2, (int) $task->fresh()->quantity);
        // The invariant the whole design rests on: 2 scratches is ONE routable record.
        $this->assertSame(1, MaintenanceTask::query()->where('maintenance_id', $task->maintenance_id)->count());
    }

    // ── The sentence, from stored data ────────────────────────────────────────────────────────────

    public function test_a_fault_describes_itself_from_its_stored_parts(): void
    {
        $task = $this->fault(['quantity' => 2]);
        $this->svc()->sync($task, ['rims', 'body']);

        $this->assertSame('2 scratches — Rims and Body (general)', $task->fresh()->describe());
    }

    public function test_arabic_reads_from_the_same_stored_parts(): void
    {
        $task = $this->fault(['quantity' => 2]);
        $this->svc()->sync($task, ['rims', 'body']);

        $this->assertSame('2 Scratch — الجنوط والهيكل (بشكل عام)', $task->fresh()->describe('ar'));
    }

    /** BACKWARD COMPATIBILITY: a fault with neither reads exactly as it always did. */
    public function test_a_legacy_fault_with_no_count_and_no_place_is_unchanged(): void
    {
        $task = $this->fault(['symptom' => 'Overheating', 'category_key' => 'engine']);

        $this->assertSame('Overheating', $task->fresh()->describe());
        $this->assertSame([], $this->svc()->locationRefs($task->fresh())->all());
    }

    // ── Promotion from a finding ──────────────────────────────────────────────────────────────────

    public function test_a_findings_payload_carries_the_count_and_places_onto_the_fault(): void
    {
        $vehicle = Vehicle::query()->first() ?? Vehicle::factory()->create();
        $ticket  = Maintenance::create([
            'vehicle_id'      => $vehicle->id,
            'workflow_status' => Maintenance::WF_INSPECTION_PENDING,
            'findings'        => [[
                'text'         => 'Scratch',
                'category_key' => 'bodywork',
                'source'       => Maintenance::FINDING_INSPECTOR,
                'quantity'     => 2,
                'locations'    => ['rims', 'body'],
            ]],
        ]);

        app(MaintenanceTaskService::class)->syncFromFindings($ticket, $this->admin);

        $task = $ticket->tasks()->first();
        $this->assertNotNull($task);
        $this->assertSame(2, (int) $task->quantity);
        $this->assertSame(['rims', 'body'], $this->svc()->locationRefs($task)->pluck('key')->all());
    }

    /** A legacy-shaped finding — no quantity, no locations — promotes exactly as it always did. */
    public function test_a_legacy_findings_payload_still_promotes(): void
    {
        $vehicle = Vehicle::query()->first() ?? Vehicle::factory()->create();
        $ticket  = Maintenance::create([
            'vehicle_id'      => $vehicle->id,
            'workflow_status' => Maintenance::WF_INSPECTION_PENDING,
            'findings'        => [['text' => 'Overheating', 'source' => Maintenance::FINDING_INSPECTOR]],
        ]);

        app(MaintenanceTaskService::class)->syncFromFindings($ticket, $this->admin);

        $task = $ticket->tasks()->first();
        $this->assertNotNull($task);
        $this->assertSame(1, (int) $task->quantity);
        $this->assertSame([], $this->svc()->locationRefs($task)->all());
    }

    /**
     * Re-filing a corrected report must CORRECT the fault, not silently keep the first answer — the
     * finding is the inspector's live statement, the task is its promotion.
     */
    public function test_re_promoting_updates_an_already_created_fault(): void
    {
        $vehicle = Vehicle::query()->first() ?? Vehicle::factory()->create();
        $ticket  = Maintenance::create([
            'vehicle_id'      => $vehicle->id,
            'workflow_status' => Maintenance::WF_INSPECTION_PENDING,
            'findings'        => [['text' => 'Scratch', 'category_key' => 'bodywork', 'quantity' => 1, 'locations' => ['rims']]],
        ]);
        $tasks = app(MaintenanceTaskService::class);
        $tasks->syncFromFindings($ticket, $this->admin);

        $ticket->findings = [['text' => 'Scratch', 'category_key' => 'bodywork', 'quantity' => 3, 'locations' => ['hood', 'front_bumper']]];
        $ticket->save();
        $tasks->syncFromFindings($ticket->fresh(), $this->admin);

        $this->assertSame(1, $ticket->tasks()->count(), 'the correction must not create a second fault');
        $task = $ticket->tasks()->first();
        $this->assertSame(3, (int) $task->quantity);
        $this->assertSame(['hood', 'front_bumper'], $this->svc()->locationRefs($task)->pluck('key')->all());
    }

    // ── The API ───────────────────────────────────────────────────────────────────────────────────

    public function test_the_findings_catalog_ships_the_vocabulary_and_the_policy(): void
    {
        $res = $this->getJson('/api/maintenance-tickets/findings-catalog')->assertOk();

        $groups = $res->json('data.locations');
        $this->assertNotEmpty($groups);
        $this->assertContains('exterior', array_column($groups, 'key'));

        $policy = $res->json('data.location_policy');
        $this->assertSame('required', $policy['Scratch'] ?? null);
        $this->assertSame('none', $policy['Overheating'] ?? null);
        $this->assertSame('none', $policy['Wiper / washer fault'] ?? null);

        $this->assertIsInt($res->json('data.max_quantity'));
    }

    public function test_the_api_exposes_the_parts_not_just_the_sentence(): void
    {
        $task = $this->fault(['quantity' => 2]);
        $this->svc()->sync($task, ['rims', 'body']);

        $payload = (new \App\Http\Resources\MaintenanceTaskResource(
            MaintenanceTask::with(['locations.location', 'faultCatalog'])->find($task->id)
        ))->toArray(request());

        // A consumer must be able to answer what/how many/where WITHOUT parsing prose.
        $this->assertSame('Scratch', $payload['symptom']);
        $this->assertSame(2, $payload['quantity']);
        $this->assertSame(['rims', 'body'], collect($payload['locations'])->pluck('key')->all());
        $this->assertSame(['Rims', 'Body (general)'], collect($payload['locations'])->pluck('label')->all());
        $this->assertSame('required', $payload['location_mode']);
        // …and the rendered sentence is there too, as a convenience, never as the only route.
        $this->assertSame('2 scratches — Rims and Body (general)', $payload['display']);
    }

    public function test_the_api_shape_is_stable_for_a_fault_with_no_places(): void
    {
        $task = $this->fault(['symptom' => 'Overheating', 'category_key' => 'engine']);

        $payload = (new \App\Http\Resources\MaintenanceTaskResource(
            MaintenanceTask::with(['locations.location'])->find($task->id)
        ))->toArray(request());

        $this->assertSame(1, $payload['quantity']);
        $this->assertSame([], $payload['locations']->all());
        $this->assertSame('none', $payload['location_mode']);
        $this->assertSame('Overheating', $payload['display']);
    }

    // ── Every word the inspector can tap is curatable ─────────────────────────────────────────────

    /**
     * A keyword-library word that no fault or damage TYPE owns — the case this section is about.
     *
     * Created rather than looked up: the assertion has to hold for a word added tomorrow, and the
     * Foundation schema's library is not always seeded. The name is deliberately one no catalog
     * carries, so the index resolves it through the library and nothing else.
     */
    private function libraryWord(string $keyword, string $category): \App\Models\FindingKeyword
    {
        $word = \App\Models\FindingKeyword::create([
            'category_key'   => $category,
            'category_label' => ucfirst($category),
            'keyword'        => $keyword,
            'risk'           => \App\Models\FindingKeyword::RISK_MODERATE,
            'is_active'      => true,
        ]);
        $this->svc()->flush(); // the index is memoised per request; this row post-dates it

        return $word;
    }

    /**
     * THE CONTROL ROOM COVERS THE WHOLE PICKER, NOT MOST OF IT.
     *
     * The regression this locks: the type index knew only `fault_catalog` and `damage_catalog`, so 22
     * words in the keyword library — "Sensor failure", "Water pump failure", "Refrigerant leak",
     * "Broken spring", "Key / immobiliser fault" — resolved to nothing. They still got a policy (their
     * category's), but with no row behind it, so Control Desk → Where on the car → Fault types listed
     * 108 of 130 words and a curator could neither see nor re-grade the rest. The tab claimed to be
     * the control room for the picker while hiding a fifth of it.
     *
     * Asserted as coverage, not as a count: a word added to the library tomorrow must resolve too,
     * which is the whole point of indexing the library rather than naming these 22. The fixture word
     * IS that tomorrow — it makes the assertion bite on a schema whose library has not been seeded,
     * while the loop still sweeps the real library wherever there is one.
     */
    public function test_every_word_in_the_keyword_library_resolves_to_a_curatable_row(): void
    {
        $this->libraryWord('Turbo actuator sticking', 'engine');

        $svc      = $this->svc();
        $keywords = \App\Models\FindingKeyword::query()->pluck('keyword')->all();

        foreach ($keywords as $keyword) {
            $row = $svc->catalogRowForText($keyword);

            $this->assertNotNull($row, "'{$keyword}' is offered by the picker but has no policy row");
            // Either a fault/damage TYPE owns the word (it carries that type's slug) or the library is
            // the only thing that knows it — and then the row says so, which is how the admin page
            // tells a type apart from a keyword-only word without a second lookup.
            $this->assertTrue(
                ($row['slug'] ?? null) !== null || ($row['kind'] ?? null) === 'keyword',
                "'{$keyword}' resolved to a row that is neither a catalog type nor a library word"
            );
            $this->assertContains(
                $svc->policyForText($keyword, $row['category_key'] ?? null),
                FaultLocationService::MODES
            );
        }
    }

    /**
     * A curator's answer on a keyword-only word actually reaches the report gate.
     *
     * Before the `location_mode` column existed on `finding_keywords` these words had nowhere to hold
     * an override, so the Fault types switch would have been a control that moves nothing. This is the
     * assertion that it moves something.
     */
    public function test_a_stored_answer_on_a_library_word_beats_its_category(): void
    {
        $word = $this->libraryWord('Turbo actuator sticking', 'engine');
        $svc  = $this->svc();

        $category = $svc->policyForText($word->keyword, $word->category_key);
        $target   = $category === FaultLocationService::MODE_REQUIRED
            ? FaultLocationService::MODE_NONE
            : FaultLocationService::MODE_REQUIRED;

        $word->update(['location_mode' => $target]);
        $svc->flush();

        $this->assertSame($target, $svc->policyForText($word->keyword, $word->category_key));

        // …and clearing it returns the word to the answer its category implies, with nothing remembered.
        $word->update(['location_mode' => null]);
        $svc->flush();
        $this->assertSame($category, $svc->policyForText($word->keyword, $word->category_key));
    }
}
