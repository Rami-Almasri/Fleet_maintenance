<?php

namespace Tests\Foundation;

use App\Models\ComponentCatalog;
use App\Models\Warranty;

/**
 * The Parts Catalog write path: what may be changed, what may not, and what happens when a part
 * something else depends on is deleted.
 */
class PartsCatalogCrudTest extends FoundationTestCase
{
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name'          => 'Test Widget '.uniqid(),
            'name_ar'       => 'قطعة تجريبية',
            'category_key'  => 'engine',
            'tracking_mode' => 'batch',
        ], $overrides);
    }

    public function test_it_creates_a_part_with_arabic_aliases_and_both_warranty_legs(): void
    {
        $res = $this->postJson('/api/parts-catalog', $this->payload([
            'aliases'                 => ['widget', 'صوت غريب'],
            'default_warranty_months' => 6,
            'default_warranty_km'     => 15000,
        ]));

        $res->assertStatus(201);
        $this->assertSame('قطعة تجريبية', $res->json('data.name_ar'));
        $this->assertSame(['widget', 'صوت غريب'], $res->json('data.aliases'));
        $this->assertSame(6, $res->json('data.default_warranty_months'));
        $this->assertSame(15000, $res->json('data.default_warranty_km'));
    }

    /** Any write claims the row so the seeder stops overwriting it. Without this, deploys undo edits. */
    public function test_every_write_stamps_edited_in_app_with_the_author(): void
    {
        $id = $this->idOf($this->postJson('/api/parts-catalog', $this->payload()));

        $part = ComponentCatalog::find($id);
        $this->assertTrue($part->edited_in_app);
        $this->assertSame($this->admin->name, $part->edited_by_name);
        $this->assertNotNull($part->edited_at);
    }

    /** Aliases are a search index: blanks and case-repeats would surface the same row twice. */
    public function test_aliases_are_trimmed_and_deduplicated_case_insensitively(): void
    {
        $res = $this->postJson('/api/parts-catalog', $this->payload([
            'aliases' => ['widget', '  widget  ', 'WIDGET', '', 'other'],
        ]));

        $this->assertSame(['widget', 'other'], $res->json('data.aliases'));
    }

    public function test_slug_is_generated_and_stays_unique(): void
    {
        $a = $this->postJson('/api/parts-catalog', $this->payload(['name' => 'Duplicate Name Part']));
        $b = $this->postJson('/api/parts-catalog', $this->payload(['name' => 'Duplicate Name Part']));

        $this->assertSame('duplicate-name-part', $a->json('data.slug'));
        $this->assertNotSame($a->json('data.slug'), $b->json('data.slug'));
    }

    public function test_it_rejects_an_unknown_category(): void
    {
        $this->postJson('/api/parts-catalog', $this->payload(['category_key' => 'not_a_category']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('category_key');
    }

    /** A consumable is work performed, not a part fitted to a corner — the pair is contradictory. */
    public function test_it_rejects_a_consumable_with_a_position_scheme(): void
    {
        $this->postJson('/api/parts-catalog', $this->payload([
            'tracking_mode'   => 'consumable',
            'position_scheme' => 'axle_corner',
        ]))->assertStatus(422);
    }

    /**
     * Re-classifying a type rewrites the rules for instances already recorded under the old ones.
     *
     * Provisions its own fitted component with a raw insert rather than through ComponentService:
     * the point is the catalog guard, and going through the asset-layer write path would make this
     * test fail for reasons that have nothing to do with what it is checking.
     */
    public function test_it_refuses_to_change_tracking_mode_once_components_exist(): void
    {
        $id = $this->idOf($this->postJson('/api/parts-catalog', $this->payload(['tracking_mode' => 'batch'])));
        $vehicle = $this->makeVehicle();

        \DB::table('vehicle_components')->insert([
            'component_catalog_id' => $id,
            'vehicle_id'           => $vehicle->id,
            'status'               => 'installed',
            'location'             => 'vehicle',
            'source'               => 'manual',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $this->postJson("/api/parts-catalog/{$id}", [
            'name'          => 'Test Widget',
            'category_key'  => 'engine',
            'tracking_mode' => 'consumable',
        ])->assertStatus(422);

        // And the same reference blocks a delete, naming the component rather than erroring in SQL.
        $res = $this->deleteJson("/api/parts-catalog/{$id}");
        $res->assertStatus(422);
        $this->assertSame(1, $res->json('data.references.fitted_components'));
    }

    public function test_an_unreferenced_part_can_be_deleted(): void
    {
        $id = $this->idOf($this->postJson('/api/parts-catalog', $this->payload()));

        $this->deleteJson("/api/parts-catalog/{$id}")->assertSuccessful();
        $this->assertNull(ComponentCatalog::find($id));
    }

    /**
     * THE REGRESSION THIS REPLACED. Deleting a referenced part used to reach the database and come
     * back as an integrity-constraint error — a 500-shaped answer to a business question.
     */
    public function test_deleting_a_referenced_part_returns_422_naming_the_references(): void
    {
        $id = $this->idOf($this->postJson('/api/parts-catalog', $this->payload()));
        $vehicle = $this->makeVehicle();

        // A warranty is enough to block it; no fitted component needed.
        Warranty::create([
            'kind' => Warranty::KIND_PART, 'vehicle_id' => $vehicle->id,
            'vehicle_component_id' => null, 'part_purchase_id' => null,
            'component_catalog_id' => $id, 'subject' => 'Test cover',
            'starts_on' => now()->toDateString(), 'duration_months' => 12,
        ]);

        $res = $this->deleteJson("/api/parts-catalog/{$id}");

        $res->assertStatus(422);
        $this->assertSame(1, $res->json('data.references.warranties'));
        $this->assertTrue($res->json('data.can_retire'));
        $this->assertStringContainsString('1 warranty', $res->json('message'));
        $this->assertNotNull(ComponentCatalog::find($id), 'the part must survive a refused delete');
    }

    public function test_retire_hides_the_part_and_restore_brings_it_back(): void
    {
        $id = $this->idOf($this->postJson('/api/parts-catalog', $this->payload()));

        $this->postJson("/api/parts-catalog/{$id}/retire")->assertSuccessful();
        $this->assertFalse(ComponentCatalog::find($id)->is_active);

        $this->postJson("/api/parts-catalog/{$id}/restore")->assertSuccessful();
        $this->assertTrue(ComponentCatalog::find($id)->is_active);
    }

    /** Retiring twice is not an error — the end state is what was asked for. */
    public function test_retire_is_idempotent(): void
    {
        $id = $this->idOf($this->postJson('/api/parts-catalog', $this->payload()));

        $this->postJson("/api/parts-catalog/{$id}/retire")->assertSuccessful();
        $this->postJson("/api/parts-catalog/{$id}/retire")->assertSuccessful();
        $this->assertFalse(ComponentCatalog::find($id)->is_active);
    }

    /** One box, any language, part name or the words used instead of it. */
    public function test_index_search_matches_english_arabic_and_symptom_aliases(): void
    {
        foreach (['دينمو', 'dynamo', 'battery not charging'] as $term) {
            $names = collect($this->getJson('/api/parts-catalog?q='.urlencode($term))->json('data.parts'))
                ->pluck('name');

            $this->assertContains('Alternator', $names, "search for '{$term}' should find the Alternator");
        }
    }

    public function test_index_reports_the_counts_the_page_filters_on(): void
    {
        $counts = $this->getJson('/api/parts-catalog')->json('data.counts');

        $this->assertGreaterThan(100, $counts['total']);
        $this->assertSame(0, $counts['missing_ar'], 'every seeded part should carry an Arabic name');
    }
}
