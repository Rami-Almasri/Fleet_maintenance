<?php

namespace Tests\Unit;

use App\Models\MaintenanceTask;
use App\Services\EventClassificationService;
use App\Support\FaultVocabulary;
use Tests\TestCase;

/**
 * The Service/Fault separation invariants, as regression tests — one per defect found by the
 * pre-release audit (docs/Service-Fault-Separation-Audit.md).
 *
 * These are deliberately FILE-AND-CONFIG ONLY where they can be. The suite runs without migrations or a
 * seeded knowledge platform, so anything asserted here holds in CI on a bare checkout — which is the
 * point: the rules this locks down were each violated silently, and a test that needs a populated
 * database is a test that gets skipped.
 */
class ServiceFaultSeparationTest extends TestCase
{
    /**
     * The classifier consults the two catalogs for an exact-name match, so the tables must exist on the
     * :memory: test database. They are created EMPTY on purpose: every assertion below must hold on the
     * config alone, which is what makes this suite meaningful on a bare checkout with nothing seeded.
     */
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['service_catalog', 'fault_catalog'] as $table) {
            if (! \Illuminate\Support\Facades\Schema::hasTable($table)) {
                \Illuminate\Support\Facades\Schema::create($table, function ($t) {
                    $t->id();
                    $t->string('slug')->nullable();
                    $t->string('name')->nullable();
                });
            }
        }
    }

    private function classifier(): EventClassificationService
    {
        return app(EventClassificationService::class);
    }

    // ── H7 · one vocabulary ───────────────────────────────────────────────────────────────────────

    /**
     * REGRESSION — audit H7. FaultVocabulary used to keep its own `routine` entry in
     * NON_FAILURE_CATEGORIES, so it could disagree with the sheet-label map and the service catalog about
     * the same word. The Service/Fault boundary now has exactly one owner and this proves the delegation
     * is live rather than duplicated.
     */
    public function test_fault_vocabulary_delegates_the_service_boundary(): void
    {
        $this->assertNotContains('routine', FaultVocabulary::COSMETIC_CATEGORIES,
            'planned work is not a cosmetic rule — it belongs to EventClassificationService');

        $this->assertTrue($this->classifier()->isServiceCategory('routine'));
        $this->assertFalse(FaultVocabulary::isFailureCategory('routine'),
            'a planned-work category is not a failure');

        $this->assertTrue(FaultVocabulary::isFailureCategory('brakes'));
        $this->assertTrue(FaultVocabulary::isFailureCategory('engine'));

        // AND the category-level cosmetic blanket is GONE. It used to answer false here, which is how
        // 132 rows of genuine interior faults (Dashboard fault, Seat adjustment fault, Water leakage
        // into cabin…) were being dropped from recurrence along with the scratches. Damage is now
        // excluded by TYPE, per concept, so these categories are ordinary failure categories again.
        $this->assertTrue(FaultVocabulary::isFailureCategory('bodywork'),
            'bodywork holds Rust / corrosion — the category itself is not a damage rule');
        $this->assertTrue(FaultVocabulary::isFailureCategory('interior'),
            'interior holds Dashboard fault, Door lock fault, Seat adjustment fault …');
    }

    /**
     * REGRESSION — a two-letter alias matched inside unrelated words, so "Accessories" (186 rows),
     * "Glass Chip / Crack" and "LED / Light Accessory Issue" all resolved to the A/C category and joined
     * A/C fault chains. Found while consolidating the vocabulary; not in the original audit.
     */
    public function test_category_aliases_match_whole_words_only(): void
    {
        foreach (['Accessories', 'Accessories & Mods', 'Glass Chip / Crack', 'Mirror Glass Crack'] as $label) {
            $resolved = FaultVocabulary::categoryOf($label)['key'] ?? null;
            $this->assertNotSame('ac', $resolved, "'{$label}' must not be filed under A/C");
        }

        // The head noun wins over a later alias, so an A/C fault is an A/C fault…
        $this->assertSame('ac', FaultVocabulary::categoryOf('AC Not Cooling')['key'] ?? null);
        // …while wording that genuinely leads with cooling still resolves to fluids.
        $this->assertSame('fluids', FaultVocabulary::categoryOf('Cooling System Issues')['key'] ?? null);
    }

    // ── M5/H7 · the label map agrees with the catalogs ────────────────────────────────────────────

    /**
     * Every label declared `service` in the sheet map must type as a service, and every `context` label
     * must type as context. Guards the config against a typo silently demoting an entry to the
     * fault default.
     */
    public function test_declared_service_and_context_labels_type_correctly(): void
    {
        foreach ((array) config('sheet_label_kinds.service', []) as $label) {
            $this->assertSame(MaintenanceTask::KIND_SERVICE, $this->classifier()->labelKind($label), $label);
        }
        foreach ((array) config('sheet_label_kinds.context', []) as $label) {
            $this->assertSame(EventClassificationService::LABEL_CONTEXT, $this->classifier()->labelKind($label), $label);
        }
    }

    /**
     * The routine-service keywords that close a ServiceReminder loop must never type as faults — they are
     * the definition of planned work.
     */
    public function test_routine_service_keywords_are_services(): void
    {
        foreach (array_keys((array) config('maintenance_findings.routine_service_types', [])) as $keyword) {
            $this->assertSame(MaintenanceTask::KIND_SERVICE, $this->classifier()->labelKind($keyword), $keyword);
        }
    }

    /**
     * Exact matching is load-bearing. A fault wording that CONTAINS a service name must stay a fault —
     * "Wheel Alignment" is planned work, "Wheel Alignment Issue" is a car pulling to one side.
     */
    public function test_service_labels_never_substring_match_fault_wordings(): void
    {
        $this->assertSame(MaintenanceTask::KIND_SERVICE, $this->classifier()->labelKind('Wheel Alignment'));

        foreach (['Wheel Alignment Issue', 'Tire Issues', 'Uneven Tire Wear', 'Engine Oil leak'] as $fault) {
            $this->assertSame(MaintenanceTask::KIND_FAULT, $this->classifier()->labelKind($fault), $fault);
        }
    }

    /** splitLabels() must be total — every input lands in exactly one bucket and nothing is invented. */
    public function test_split_labels_partitions_its_input(): void
    {
        $input = ['Oil & Fillter Change', 'Rim Scratch', 'Customer', 'Brake Failure', 'Rim Scratch'];
        $out   = $this->classifier()->splitLabels($input);

        // Merge EVERY bucket the classifier returns, so this test keeps proving totality as the domain
        // grows instead of silently ignoring a kind it has not been taught about.
        $all = array_merge(...array_values($out));
        sort($all);
        $expected = array_values(array_unique($input));
        sort($expected);

        $this->assertSame($expected, $all, 'the buckets must re-assemble into the de-duplicated input');
        $this->assertContains('Oil & Fillter Change', $out['service']);
        $this->assertContains('Customer', $out[EventClassificationService::LABEL_CONTEXT]);
        $this->assertContains('Brake Failure', $out['fault']);
        $this->assertContains('Rim Scratch', $out[MaintenanceTask::KIND_DAMAGE]);
    }

    // ── H6 · concept typing: the catalog outranks the ontology's filing system ────────────────────

    /**
     * REGRESSION — audit H6. The ontology files concepts by SYSTEM, so "Tyre Rotation" and "Wheel
     * Balancing" live in `tyres` next to punctures. Typing them by category alone made planned work
     * eligible as a diagnosis; the service catalog has to win.
     */
    public function test_concept_kind_prefers_the_catalog_over_the_category(): void
    {
        $c = $this->classifier();

        $this->assertSame(MaintenanceTask::KIND_SERVICE, $c->conceptKind('Tyre Rotation', 'tyres'));
        $this->assertSame(MaintenanceTask::KIND_SERVICE, $c->conceptKind('Wheel Balancing', 'tyres'));
        $this->assertSame(MaintenanceTask::KIND_SERVICE, $c->conceptKind('Oil Change', 'routine'));

        // A real tyre FAULT in the same category stays a fault.
        $this->assertSame(MaintenanceTask::KIND_FAULT, $c->conceptKind('Puncture / slow leak', 'tyres'));
        $this->assertSame(MaintenanceTask::KIND_FAULT, $c->conceptKind('Worn / bald tyre', 'tyres'));
    }

    // ── C2 · the reminder loop reads the type, not the wording ────────────────────────────────────

    /**
     * REGRESSION — audit C2. confirmRoutineServices() writes to the vehicle master record (service
     * odometer anchor + reminder roll-forward) and used to decide "is this a service?" from the symptom
     * TEXT. A fault named like a service therefore stamped the car as serviced.
     */
    public function test_a_fault_named_like_a_service_closes_no_reminder(): void
    {
        $fault = new MaintenanceTask(['symptom' => 'Oil Change', 'kind' => MaintenanceTask::KIND_FAULT]);

        $this->assertNull($fault->serviceReminderType(),
            'the stored type must beat the wording — this write reaches the vehicle record');
    }

    /** The other direction: a legacy service with no catalog id still resolves through the text shim. */
    public function test_a_legacy_service_without_a_catalog_id_still_closes_its_reminder(): void
    {
        $service = new MaintenanceTask(['symptom' => 'Oil Change', 'kind' => MaintenanceTask::KIND_SERVICE]);

        $this->assertSame('oil_change', $service->serviceReminderType());
    }

    /** An inspection is neither, and must never roll a service forward. */
    public function test_an_inspection_closes_no_reminder(): void
    {
        $inspection = new MaintenanceTask(['symptom' => 'Oil Change', 'kind' => MaintenanceTask::KIND_INSPECTION]);

        $this->assertNull($inspection->serviceReminderType());
    }

    // ── L1 · the discriminator is validated ───────────────────────────────────────────────────────

    /** A new task starts at the column default rather than momentarily untyped (saving fires first). */
    public function test_a_new_task_is_born_with_a_valid_kind(): void
    {
        $this->assertContains((new MaintenanceTask())->kind, MaintenanceTask::KINDS);
    }

    /** Every kind has exactly one catalog FK and one relation behind it — no silent gaps. */
    public function test_every_kind_has_a_catalog_binding(): void
    {
        foreach (MaintenanceTask::KINDS as $kind) {
            $this->assertArrayHasKey($kind, MaintenanceTask::KIND_CATALOG_FK, $kind);
            $this->assertArrayHasKey($kind, MaintenanceTask::KIND_CATALOG_RELATIONS, $kind);
            $this->assertArrayHasKey($kind, MaintenanceTask::KIND_META, $kind);
        }

        $this->assertCount(count(MaintenanceTask::KINDS), array_unique(MaintenanceTask::KIND_CATALOG_FK),
            'two kinds must never share a catalog column');
    }

    // ── DAMAGE — the third operational kind ───────────────────────────────────────────────────────

    /**
     * The question that started the whole domain change: "Rim Scratch — this is fault??"
     *
     * It is not. It is damage: unplanned, but caused externally, and therefore not evidence that the
     * vehicle is unreliable.
     */
    public function test_externally_caused_damage_is_typed_damage(): void
    {
        foreach ([
            'Rim Scratch', 'Rims scratch', 'Deep Dent', 'Door Dent', 'Bumper Damage',
            'Minor Surface Scratch', 'Upholstery Damage', 'Glass Chip / Crack', 'Broken / Loose Mirror',
            'Windscreen Chip', 'Accident Damage', 'Vandalism', 'Body Damage', 'Panel Misalignment',
        ] as $wording) {
            $this->assertSame(MaintenanceTask::KIND_DAMAGE, $this->classifier()->labelKind($wording), $wording);
        }
    }

    /**
     * REGRESSION — the trap that makes a category rule unusable. `interior` and `bodywork` are SYSTEMS,
     * not types: they hold real failures next to the scratches. A blanket "bodywork and interior are
     * damage" rule would delete all of these from reliability, which is the mistake the previous
     * COSMETIC_CATEGORIES constant was actually making.
     */
    public function test_faults_inside_damage_prone_categories_stay_faults(): void
    {
        foreach ([
            'Dashboard fault', 'Door lock fault', 'Interior light fault', 'Seat adjustment fault',
            'Infotainment / screen issue', 'Water leakage into cabin', 'Rust / corrosion',
            'Dashboard Warning Lights', 'Seat Adjustment Fault',
        ] as $wording) {
            $this->assertSame(MaintenanceTask::KIND_FAULT, $this->classifier()->labelKind($wording), $wording);
        }
    }

    /** Damage is never evidence about the vehicle — the single predicate every analytics reader asks. */
    public function test_only_faults_affect_reliability(): void
    {
        $this->assertSame([MaintenanceTask::KIND_FAULT], MaintenanceTask::RELIABILITY_KINDS);

        $this->assertTrue((new MaintenanceTask(['kind' => MaintenanceTask::KIND_FAULT]))->affectsReliability());
        $this->assertFalse((new MaintenanceTask(['kind' => MaintenanceTask::KIND_DAMAGE]))->affectsReliability());
        $this->assertFalse((new MaintenanceTask(['kind' => MaintenanceTask::KIND_SERVICE]))->affectsReliability());
        $this->assertFalse((new MaintenanceTask(['kind' => MaintenanceTask::KIND_INSPECTION]))->affectsReliability());

        $this->assertTrue($this->classifier()->kindAffectsReliability(MaintenanceTask::KIND_FAULT));
        $this->assertFalse($this->classifier()->kindAffectsReliability(MaintenanceTask::KIND_DAMAGE));
    }

    /**
     * Damage is excluded from reliability in EVERY mode, unlike services.
     *
     * Services are staged behind EVENT_KIND_MODE because they have historically been counted and the
     * change must be comparable. Damage has never been counted — it is a new kind — so there is no
     * "before" to preserve and no mode in which a kerbed rim should read as a reliability signal.
     */
    public function test_damage_is_excluded_from_reliability_in_every_mode(): void
    {
        foreach (['off', 'shadow', 'enforced'] as $mode) {
            config(['features.event_kind' => $mode]);

            $this->assertNotContains(
                MaintenanceTask::KIND_DAMAGE,
                MaintenanceTask::reliabilityKindsForMode(),
                "damage must never count as reliability evidence (mode: {$mode})"
            );
            $this->assertContains(MaintenanceTask::KIND_FAULT, MaintenanceTask::reliabilityKindsForMode(), $mode);
        }

        // …while the SERVICE exclusion remains the thing the flag actually stages.
        config(['features.event_kind' => 'enforced']);
        $this->assertNotContains(MaintenanceTask::KIND_SERVICE, MaintenanceTask::reliabilityKindsForMode());

        config(['features.event_kind' => 'shadow']);
        $this->assertContains(MaintenanceTask::KIND_SERVICE, MaintenanceTask::reliabilityKindsForMode());
    }

    /** splitLabels() must partition into FOUR buckets now, still losing nothing. */
    public function test_split_labels_partitions_across_four_kinds(): void
    {
        $input = ['Oil & Fillter Change', 'Rim Scratch', 'Customer', 'Brake Failure'];
        $out   = $this->classifier()->splitLabels($input);

        $this->assertSame(['Brake Failure'], $out[MaintenanceTask::KIND_FAULT]);
        $this->assertSame(['Oil & Fillter Change'], $out[MaintenanceTask::KIND_SERVICE]);
        $this->assertSame(['Rim Scratch'], $out[MaintenanceTask::KIND_DAMAGE]);
        $this->assertSame(['Customer'], $out[EventClassificationService::LABEL_CONTEXT]);

        $all = array_merge(...array_values($out));
        sort($all);
        $expected = $input;
        sort($expected);
        $this->assertSame($expected, $all, 'the four buckets must re-assemble into the input');
    }

    /** faultLabels() is the shorthand reliability readers use — damage and service fall away together. */
    public function test_fault_labels_keeps_only_reliability_evidence(): void
    {
        $this->assertSame(
            ['Brake Failure', 'Dashboard fault'],
            $this->classifier()->faultLabels(
                ['Rim Scratch', 'Brake Failure', 'Oil Change', 'Dashboard fault', 'Customer']
            )
        );
    }

    /**
     * Damage is typed per CONCEPT, never per category — asserted directly so nobody reintroduces the
     * shortcut. `isServiceCategory` exists; there is deliberately no `isDamageCategory`.
     */
    public function test_damage_has_no_category_level_shortcut(): void
    {
        $this->assertFalse(method_exists($this->classifier(), 'isDamageCategory'),
            'damage must be typed per concept — a category rule mislabels the faults living in bodywork/interior');

        // A damage concept and a fault concept share the `interior` category and must still differ.
        $this->assertSame(MaintenanceTask::KIND_DAMAGE, $this->classifier()->conceptKind('Upholstery Damage', 'interior'));
        $this->assertSame(MaintenanceTask::KIND_FAULT, $this->classifier()->conceptKind('Dashboard fault', 'interior'));
    }
}
