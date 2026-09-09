<?php

namespace Tests\Foundation;

use App\Models\FaultCatalog;
use App\Models\FindingKeyword;
use App\Models\MaintenanceTask;
use App\Services\SelectableFindings;
use App\Support\TextNormalizer;

/**
 * A fault added by the office can be TAPPED — from either door.
 *
 * A fault type is two rows: `finding_keywords` grades it and teaches the matcher its name,
 * `fault_catalog` makes it selectable and decides what a task typed from it IS. Each admin page used
 * to write only its own, so "Add Keyword" produced a fault the engine recognised in a sentence and
 * nobody could pick: the library counted 111, the picker offered 109, and searching the new word in
 * the findings picker returned "0 matching issues" beside a suggestion card explaining that the garage
 * records this one during the repair — which was not true, it was half-added.
 *
 * These assert the WORD, not the row count: after each write, is it in the menu SelectableFindings
 * hands the picker? That is the only question the inspector's screen actually asks.
 */
class AddedFaultIsSelectableTest extends FoundationTestCase
{
    /** Is this word in the menu the findings picker renders? */
    private function isSelectable(string $word): bool
    {
        return isset(app(SelectableFindings::class)->keywords()[TextNormalizer::key($word)]);
    }

    private function menuCategoryOf(string $word): ?string
    {
        return app(SelectableFindings::class)->categoryOf(TextNormalizer::key($word));
    }

    public function test_a_keyword_added_in_the_risk_library_is_tappable_immediately(): void
    {
        $word = 'Intermittent operation '.uniqid();

        $this->assertFalse($this->isSelectable($word));

        $this->postJson('/api/finding-keywords', [
            'category_key' => 'electrical',
            'keyword'      => $word,
            'keyword_ar'   => 'تشغيل متقطع',
            'risk'         => 'routine',
        ])->assertStatus(201);

        $this->assertTrue($this->isSelectable($word), 'The word the office added is still not in the picker.');
        $this->assertSame('electrical', $this->menuCategoryOf($word), 'It is offered under the wrong heading.');

        // The fault type is the row that also decides what a task typed from this word IS — kind=fault,
        // with a severity to prefill. A chip with no row behind it types a task by the legacy default.
        $fault = FaultCatalog::where('name', $word)->first();
        $this->assertNotNull($fault);
        $this->assertSame('routine', $fault->default_severity, 'The risk grade did not reach the fault type.');
        $this->assertSame('تشغيل متقطع', $fault->name_ar);
        $this->assertTrue($fault->edited_in_app, 'Without this the seeder would assert the config back over it.');
    }

    public function test_a_fault_type_added_on_the_fault_types_page_gets_its_risk_grade(): void
    {
        $word = 'Boot latch fault '.uniqid();

        $this->postJson('/api/fault-catalog', [
            'name'             => $word,
            'name_ar'          => 'خلل في قفل الشنطة',
            'category_key'     => 'electrical',
            'default_severity' => 'critical',
        ])->assertStatus(201);

        // The mirror hole: tappable but ungraded, so the page that decides how serious a fault is has
        // never heard of it and the matcher cannot recognise the word in a written note.
        $keyword = FindingKeyword::where('keyword', $word)->first();
        $this->assertNotNull($keyword, 'The fault type has no row in the Keyword Risk Library.');
        $this->assertSame('critical', $keyword->risk);
        $this->assertTrue(
            $keyword->terms()->where('is_active', true)->exists(),
            'The matcher has no canonical term, so the word matches nothing at all.',
        );
    }

    public function test_a_service_wording_is_refused_rather_than_half_added(): void
    {
        // Planned work is not a fault. A fault row would count every oil change in Top Faults,
        // recurrence and the reliability score — and refusing beats the old behaviour of accepting it
        // and leaving a word nobody could ever select.
        // Asserted on the exact wording, with no unique-ing suffix: the classifier reads the WORDS, so
        // "Coolant service 68bf01" is not the phrase the workshop uses and would not be classified at
        // all. The uniqueness this test needs comes from the transaction, not from the string.
        $this->postJson('/api/finding-keywords', [
            'category_key' => 'routine',
            'keyword'      => 'Coolant service',
            'risk'         => 'routine',
        ])->assertStatus(422);
    }

    public function test_a_word_withheld_on_purpose_is_not_reported_as_a_dead_end(): void
    {
        // "Sensor failure" is declared understanding-only: the engine reads it, the picker does not
        // offer it, and that is correct. A count meant to be zero would otherwise sit permanently at
        // four and the one row that IS broken would hide inside it.
        FindingKeyword::create([
            'category_key'   => 'electrical',
            'category_label' => 'Electrical',
            'keyword'        => 'Sensor failure',
            'risk'           => 'moderate',
            'is_active'      => true,
        ]);

        $body = $this->getJson('/api/finding-keywords')->assertStatus(200)->json();

        $this->assertSame(0, data_get($body, 'data.counts.not_selectable'));

        $row = collect(data_get($body, 'data.keywords'))->firstWhere('keyword', 'Sensor failure');
        $this->assertFalse($row['selectable']);
        $this->assertTrue($row['withheld'], 'The page cannot tell a deliberate omission from a broken one.');
    }

    public function test_a_rename_reaches_both_halves(): void
    {
        $word = 'Dash light flicker '.uniqid();

        $id = $this->idOf($this->postJson('/api/finding-keywords', [
            'category_key' => 'electrical',
            'keyword'      => $word,
            'risk'         => 'moderate',
        ]));

        $renamed = 'Dashboard light flicker '.uniqid();

        $this->postJson("/api/finding-keywords/{$id}", [
            'category_key' => 'electrical',
            'keyword'      => $renamed,
            'risk'         => 'critical',
        ])->assertStatus(200);

        $this->assertTrue($this->isSelectable($renamed));
        $this->assertFalse($this->isSelectable($word), 'The picker still offers the old wording.');

        $fault = FaultCatalog::where('name', $renamed)->first();
        $this->assertNotNull($fault);
        $this->assertSame('critical', $fault->default_severity, 'The re-grade stopped at the library.');
        $this->assertSame(0, FaultCatalog::where('name', $word)->count(), 'The rename forked one fault into two rows.');
    }

    public function test_deleting_a_keyword_takes_its_chip_out_of_the_picker(): void
    {
        $word = 'Aerial mount loose '.uniqid();

        $id = $this->idOf($this->postJson('/api/finding-keywords', [
            'category_key' => 'bodywork',
            'keyword'      => $word,
            'risk'         => 'routine',
        ]));

        $this->assertTrue($this->isSelectable($word));

        $this->deleteJson("/api/finding-keywords/{$id}")->assertStatus(200);

        $this->assertFalse(
            $this->isSelectable($word),
            'The word is gone from the library but still tappable — a chip with no grade behind it.',
        );
    }

    public function test_a_fault_type_tasks_were_typed_from_is_retired_not_deleted(): void
    {
        $word = 'Wiring chafe '.uniqid();

        $id = $this->idOf($this->postJson('/api/finding-keywords', [
            'category_key' => 'electrical',
            'keyword'      => $word,
            'risk'         => 'moderate',
        ]));

        $fault = FaultCatalog::where('name', $word)->firstOrFail();

        // A task typed from this row: the row is HOW that ticket said what was wrong, and deleting it
        // would rewrite the history rather than curate the menu.
        $vehicle = $this->makeVehicle();
        $ticket  = \App\Models\Maintenance::create([
            'vehicle_id' => $vehicle->id,
            'status'     => 'pending',
        ]);

        MaintenanceTask::create([
            'maintenance_id'   => $ticket->id,
            'vehicle_id'       => $vehicle->id,
            'symptom'          => $word,
            'kind'             => MaintenanceTask::KIND_FAULT,
            'fault_catalog_id' => $fault->id,
        ]);

        $this->deleteJson("/api/finding-keywords/{$id}")->assertStatus(200);

        $fault->refresh();
        $this->assertFalse($fault->is_active, 'It should have been retired.');
        $this->assertFalse($this->isSelectable($word), 'A retired fault type must leave the picker.');
    }
}
