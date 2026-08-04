<?php

namespace Tests\Foundation;

use App\Models\ComponentCatalog;
use App\Models\Maintenance;
use App\Models\MaintenanceRequiredPart;
use App\Services\MaintenanceRequiredPartService;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;

/**
 * Required parts must name a catalog part, not a string someone typed.
 *
 * This is the rule the whole catalog exists to make possible. Two halves have to hold together: the
 * picker makes compliance possible, and enforcement makes it certain — without the second, free text
 * returns through any client that keeps posting a name.
 */
class RequiredPartCatalogLinkTest extends FoundationTestCase
{
    private MaintenanceRequiredPartService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(MaintenanceRequiredPartService::class);
    }

    private function ticket(): Maintenance
    {
        return Maintenance::create([
            'vehicle_id'      => $this->makeVehicle()->id,
            'maintenance_type' => 'Breakdown',
            'status'          => 'pending',
            'date'            => now()->toDateString(),
        ]);
    }

    private function record(Maintenance $ticket, array $line): int
    {
        return $this->service->record($ticket, [$line], $this->admin);
    }

    // ── enforcement ON (the shipped default) ─────────────────────────────────────────────────────

    public function test_enforcement_is_on_by_default(): void
    {
        $this->assertTrue(config('parts.require_catalog_link'), 'the picker has shipped; free text must be refused');
    }

    /** A line naming an unidentifiable part is refused at the door. */
    public function test_free_text_that_matches_no_part_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->record($this->ticket(), ['part_name' => 'some thing nobody stocks']);
    }

    /** The refusal names the offending text so the user knows which row to fix. */
    public function test_the_rejection_names_the_text_it_could_not_identify(): void
    {
        try {
            $this->record($this->ticket(), ['part_name' => 'zzz unidentifiable']);
            $this->fail('expected the line to be refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('zzz unidentifiable', json_encode($e->errors()));
        }
    }

    /** An explicit catalog id is what the picker sends, and it always wins. */
    public function test_a_line_with_a_catalog_id_is_accepted_and_recorded_as_manual(): void
    {
        $part = ComponentCatalog::where('slug', 'alternator')->first();
        $ticket = $this->ticket();

        $this->record($ticket, ['component_catalog_id' => $part->id, 'part_name' => 'Alternator']);

        $line = MaintenanceRequiredPart::where('maintenance_id', $ticket->id)->first();
        $this->assertSame($part->id, $line->component_catalog_id);
        $this->assertSame('manual', $line->catalog_matched_by, 'a person chose it; that is stronger than any match');
    }

    /**
     * Text that DOES identify a part is still accepted with enforcement on — the rule is "must name
     * a known part", not "must arrive with an id". An API client posting "Alternator" is unambiguous.
     */
    public function test_text_that_resolves_exactly_is_still_accepted(): void
    {
        $ticket = $this->ticket();

        $this->record($ticket, ['part_name' => 'Alternator']);

        $line = MaintenanceRequiredPart::where('maintenance_id', $ticket->id)->first();
        $this->assertNotNull($line->component_catalog_id);
        $this->assertSame('exact', $line->catalog_matched_by);
    }

    /** A symptom is not a part. It must be refused even though the picker can search by it. */
    public function test_a_symptom_is_refused_because_it_names_no_part(): void
    {
        $this->expectException(ValidationException::class);

        $this->record($this->ticket(), ['part_name' => 'brake noise']);
    }

    /** The inspector's own wording is kept beside the reference — it is evidence, not a lookup key. */
    public function test_the_typed_wording_is_preserved_alongside_the_reference(): void
    {
        $part = ComponentCatalog::where('slug', 'brake-pads')->first();
        $ticket = $this->ticket();

        $this->record($ticket, [
            'component_catalog_id' => $part->id,
            'part_name'            => 'Front brake pads (worn to the metal)',
        ]);

        $line = MaintenanceRequiredPart::where('maintenance_id', $ticket->id)->first();
        $this->assertSame('Front brake pads (worn to the metal)', $line->part_name);
        $this->assertSame($part->id, $line->component_catalog_id);
    }

    // ── enforcement OFF (the rollback path) ──────────────────────────────────────────────────────

    /**
     * Turning the flag off must genuinely restore the old behaviour, because it is the rollback if a
     * client is found still posting free text. A flag that does not actually roll back is not a flag.
     */
    public function test_with_enforcement_off_an_unmatched_line_is_saved_unlinked(): void
    {
        Config::set('parts.require_catalog_link', false);
        $ticket = $this->ticket();

        $this->record($ticket, ['part_name' => 'some thing nobody stocks']);

        $line = MaintenanceRequiredPart::where('maintenance_id', $ticket->id)->first();
        $this->assertNotNull($line, 'the line is kept');
        $this->assertNull($line->component_catalog_id);
        $this->assertNull($line->catalog_matched_by, 'nothing was matched, so nothing is claimed');
    }

    /** Existing unlinked history stays readable — enforcement is never retroactive. */
    public function test_enforcement_does_not_invalidate_rows_already_stored_unlinked(): void
    {
        Config::set('parts.require_catalog_link', false);
        $ticket = $this->ticket();
        $this->record($ticket, ['part_name' => 'legacy wording']);

        Config::set('parts.require_catalog_link', true);

        $line = MaintenanceRequiredPart::where('maintenance_id', $ticket->id)->first();
        $this->assertSame('legacy wording', $line->fresh()->part_name);
        $this->assertNull($line->component_catalog_id);
    }
}
