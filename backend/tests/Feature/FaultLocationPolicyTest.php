<?php

namespace Tests\Feature;

use App\Services\FaultLocationService;
use Tests\TestCase;

/**
 * The WHERE axis, at the level that needs no database: the vocabulary, the per-type policy, and the
 * input normalisation every writer goes through.
 *
 * Deliberately schema-free. `FaultLocationService` falls back to config/vehicle_locations.php when
 * the table is absent, and the catalog lookups are wrapped so a missing table degrades to the
 * category answer — which is exactly the behaviour a half-migrated environment must have, so it is
 * worth asserting rather than working around. The DB-backed half (sync, the API shape) lives in the
 * Foundation suite, which runs against a real schema.
 */
class FaultLocationPolicyTest extends TestCase
{
    private function svc(): FaultLocationService
    {
        return new FaultLocationService();
    }

    // ── The vocabulary is real, shared, and not a second system ───────────────────────────────────

    public function test_the_vocabulary_loads_from_config_when_the_table_is_absent(): void
    {
        $catalog = $this->svc()->catalog();

        $this->assertNotEmpty($catalog);
        foreach (['front_bumper', 'hood', 'rims', 'body', 'windshield_front', 'wheel_front_left'] as $slug) {
            $this->assertArrayHasKey($slug, $catalog, "the vocabulary is missing '{$slug}'");
        }
    }

    /**
     * The location vocabulary must stay JOINABLE to the inspection hotspot diagram, or this becomes
     * the second location system it was written to avoid. Every `inspection_zone` a location claims
     * has to be a real VehicleDiagram zone id.
     */
    public function test_every_inspection_zone_reference_is_a_real_diagram_zone(): void
    {
        // The ids in frontend/src/components/inspection/VehicleDiagram.js (VEHICLE_ZONES).
        $zones = [
            'front_bumper', 'hood', 'windshield_front', 'door_front_left', 'door_rear_left',
            'roof', 'door_front_right', 'door_rear_right', 'windshield_rear', 'trunk', 'rear_bumper',
        ];

        foreach ($this->svc()->catalog() as $slug => $location) {
            if ($location->inspection_zone === null) {
                continue;
            }
            $this->assertContains(
                $location->inspection_zone,
                $zones,
                "'{$slug}' points at diagram zone '{$location->inspection_zone}', which does not exist",
            );
        }
    }

    public function test_the_four_wheel_corners_reuse_the_component_position_scheme(): void
    {
        // component_catalog's axle_corner vocabulary — the locations must not invent a rival one.
        foreach (\App\Models\ComponentCatalog::POSITIONS_BY_SCHEME[\App\Models\ComponentCatalog::SCHEME_AXLE_CORNER] as $corner) {
            $this->assertArrayHasKey("wheel_{$corner}", $this->svc()->catalog());
        }
    }

    public function test_the_grouped_catalog_is_shaped_for_a_picker(): void
    {
        $groups = $this->svc()->groupedCatalog();

        $this->assertNotEmpty($groups);
        $keys = array_column($groups, 'key');
        $this->assertContains('exterior', $keys);
        $this->assertContains('wheels', $keys);

        $exterior = $groups[array_search('exterior', $keys, true)];
        $this->assertNotEmpty($exterior['locations']);
        $this->assertArrayHasKey('key', $exterior['locations'][0]);
        $this->assertArrayHasKey('label', $exterior['locations'][0]);
    }

    // ── Policy: does this TYPE have a where? ──────────────────────────────────────────────────────

    public function test_body_and_wheel_and_light_faults_require_a_location(): void
    {
        $this->assertSame('required', $this->svc()->policyFor(null, 'bodywork'));
        $this->assertSame('required', $this->svc()->policyFor(null, 'tyres'));
        $this->assertSame('required', $this->svc()->policyFor(null, 'lights'));
    }

    public function test_faults_with_nowhere_to_point_ask_for_nothing(): void
    {
        $this->assertSame('none', $this->svc()->policyFor(null, 'engine'));
        $this->assertSame('none', $this->svc()->policyFor(null, 'transmission'));
        $this->assertSame('none', $this->svc()->policyFor(null, 'ac'));
        $this->assertSame('none', $this->svc()->policyFor(null, 'routine'));
    }

    /**
     * The wiper case: it sits in a `required` category, but the part IS the answer, so its own row
     * overrides the category. This is the per-type override working — one config line, no code.
     */
    public function test_a_per_type_override_beats_its_category(): void
    {
        $this->assertSame('required', $this->svc()->policyFor('light_headlight_out', 'lights'));
        $this->assertSame('none', $this->svc()->policyFor('light_wiper_washer', 'lights'));
    }

    public function test_a_curators_stored_answer_beats_the_config(): void
    {
        // fault_catalog.location_mode — the in-app override. Nothing else may outrank it.
        $this->assertSame('optional', $this->svc()->policyFor('body_scratch', 'bodywork', 'optional'));
        // …but only when it is a real mode; junk falls through rather than disabling the rule.
        $this->assertSame('required', $this->svc()->policyFor('body_scratch', 'bodywork', 'banana'));
    }

    public function test_an_unknown_type_offers_the_picker_rather_than_demanding_one(): void
    {
        // A custom issue an inspector typed. Unknown must not mean "required".
        $this->assertSame('optional', $this->svc()->policyFor(null, null));
        $this->assertSame('optional', $this->svc()->policyFor('nothing_we_know', 'category_we_dropped'));
    }

    /**
     * Adding a fault type must not need code. A slug the policy has never heard of inherits its
     * category's answer — the property the whole design rests on.
     */
    public function test_a_brand_new_fault_type_inherits_its_category_with_no_code_change(): void
    {
        $this->assertSame('required', $this->svc()->policyFor('body_stone_chip_2027', 'bodywork'));
        $this->assertSame('none', $this->svc()->policyFor('engine_new_symptom_2027', 'engine'));
    }

    /**
     * The picker's contract: one map, every keyword answered before the user taps anything, so the
     * location box can appear instantly instead of after a round trip per selection.
     */
    public function test_the_keyword_policy_map_answers_the_whole_findings_catalog(): void
    {
        $categories = (array) config('maintenance_findings.categories', []);
        $map = $this->svc()->policyByKeyword($categories);

        $keywords = collect($categories)->flatMap(fn ($c) => $c['keywords'] ?? [])->all();
        $this->assertNotEmpty($keywords);
        foreach ($keywords as $keyword) {
            $this->assertArrayHasKey($keyword, $map, "no location policy for '{$keyword}'");
            $this->assertContains($map[$keyword], FaultLocationService::MODES);
        }

        // Spot-checks across three different kinds of answer, none of them scratch-specific.
        $this->assertSame('required', $map['Dent'] ?? null);
        $this->assertSame('required', $map['Scratch'] ?? null);
        $this->assertSame('none', $map['Overheating'] ?? null);
        $this->assertSame('none', $map['Wiper / washer fault'] ?? null);
    }

    // ── Normalisation: what every writer goes through ─────────────────────────────────────────────

    public function test_unknown_slugs_are_dropped_and_known_ones_keep_their_order(): void
    {
        $this->assertSame(
            ['rims', 'body'],
            $this->svc()->normalizeSlugs(['rims', 'not_a_place', 'body']),
        );
    }

    public function test_duplicate_locations_collapse(): void
    {
        $this->assertSame(['rims'], $this->svc()->normalizeSlugs(['rims', 'rims', ' rims ']));
    }

    public function test_the_picker_may_send_objects_or_bare_slugs(): void
    {
        $this->assertSame(
            ['hood', 'front_bumper'],
            $this->svc()->normalizeSlugs([['key' => 'hood'], ['slug' => 'front_bumper']]),
        );
    }

    public function test_blank_and_malformed_entries_never_survive(): void
    {
        $this->assertSame([], $this->svc()->normalizeSlugs(['', '   ', [], ['key' => '']]));
    }

    public function test_quantity_is_clamped_to_a_sane_range(): void
    {
        $max = (int) config('vehicle_locations.max_quantity');

        $this->assertSame(1, $this->svc()->normalizeQuantity(null));
        $this->assertSame(1, $this->svc()->normalizeQuantity(0));
        $this->assertSame(1, $this->svc()->normalizeQuantity(-9));
        $this->assertSame(1, $this->svc()->normalizeQuantity('not a number'));
        $this->assertSame(3, $this->svc()->normalizeQuantity('3'));
        $this->assertSame($max, $this->svc()->normalizeQuantity($max + 500));
    }

    // ── The intake gate ───────────────────────────────────────────────────────────────────────────

    public function test_a_required_type_with_no_location_is_named_as_missing(): void
    {
        $missing = $this->svc()->findingsMissingRequiredLocation([
            ['text' => 'Scratch', 'category_key' => 'bodywork', 'locations' => []],
            ['text' => 'Dent',    'category_key' => 'bodywork', 'locations' => ['hood']],
            ['text' => 'Overheating', 'category_key' => 'engine', 'locations' => []],
        ]);

        $this->assertSame(['Scratch'], $missing);
    }

    public function test_every_offender_is_reported_at_once_not_one_per_resubmission(): void
    {
        $missing = $this->svc()->findingsMissingRequiredLocation([
            ['text' => 'Scratch', 'category_key' => 'bodywork', 'locations' => []],
            ['text' => 'Worn / bald tyre', 'category_key' => 'tyres', 'locations' => []],
            ['text' => 'Headlight out', 'category_key' => 'lights', 'locations' => []],
        ]);

        $this->assertSame(['Scratch', 'Worn / bald tyre', 'Headlight out'], $missing);
    }

    public function test_an_unknown_location_does_not_satisfy_a_required_type(): void
    {
        $missing = $this->svc()->findingsMissingRequiredLocation([
            ['text' => 'Scratch', 'category_key' => 'bodywork', 'locations' => ['somewhere_made_up']],
        ]);

        $this->assertSame(['Scratch'], $missing);
    }

    public function test_the_gate_is_silent_about_types_that_have_no_where(): void
    {
        $this->assertSame([], $this->svc()->findingsMissingRequiredLocation([
            ['text' => 'Overheating',        'category_key' => 'engine'],
            ['text' => 'Wiper / washer fault', 'category_key' => 'lights'],
            ['text' => 'Oil Change',          'category_key' => 'routine'],
        ]));
    }

    /**
     * BACKWARD COMPATIBILITY. A legacy findings payload — plain strings, no locations key at all —
     * must pass through the gate untouched. Historical reports are not retroactively invalid.
     */
    public function test_legacy_findings_with_no_location_key_are_not_rejected_as_a_class(): void
    {
        $this->assertSame([], $this->svc()->findingsMissingRequiredLocation([
            ['text' => 'Overheating'],
            ['text' => 'Gear slipping'],
        ]));
    }
}
