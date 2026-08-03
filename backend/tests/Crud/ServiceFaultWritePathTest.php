<?php

namespace Tests\Crud;

use App\Models\Maintenance;
use App\Models\MaintenanceTask;
use App\Models\RecurringFaultReview;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Services\MaintenanceTaskService;
use App\Services\RecurringFaultService;

/**
 * The Service/Fault separation where it can CORRUPT DATA — the write paths.
 *
 * The three Critical findings of the pre-release audit were all writes, which is why they needed tests
 * against a real database rather than the config-only unit suite: each one persisted a wrong fact
 * (a wrong `kind`, a recurrence record, a vehicle service anchor) that no read-side flag could suppress.
 *
 * @see docs/Service-Fault-Separation-Audit.md — C2, C3, H5
 */
class ServiceFaultWritePathTest extends CrudTestCase
{
    /**
     * The isolated test schema is a clone of production STRUCTURE, not of its reference data, so the
     * catalogs may be empty here. These tests are about the catalog being authoritative, so they seed the
     * two rows they need rather than skipping — an assertion that only runs on a seeded machine is an
     * assertion that stops running.
     */
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            ['slug' => 'oil_change', 'name' => 'Oil Change',           'service_reminder_type' => 'oil_change'],
            ['slug' => 'brake_pads', 'name' => 'Brake Pads (service)', 'service_reminder_type' => 'brake_pads'],
        ] as $row) {
            ServiceCatalog::firstOrCreate(['slug' => $row['slug']], $row + ['category_key' => 'routine']);
        }

        // One row in EVERY catalog, so the kind↔catalog mismatch matrix below is genuinely exhaustive
        // rather than quietly covering only the halves that happen to be seeded.
        \App\Models\FaultCatalog::firstOrCreate(
            ['slug' => 'test_brake_noise'],
            ['name' => 'Brake noise (test)', 'category_key' => 'brakes']
        );
        \App\Models\InspectionType::firstOrCreate(
            ['slug' => 'test_pre_rental'],
            ['name' => 'Pre-rental (test)']
        );
        \App\Models\DamageCatalog::firstOrCreate(
            ['slug' => 'rim_scratch'],
            ['name' => 'Rim Scratch', 'category_key' => 'tyres', 'damage_type' => 'scratch']
        );
    }

    private function ticket(array $overrides = []): Maintenance
    {
        return Maintenance::create(array_merge([
            'vehicle_id' => $this->makeVehicle(),
            'origin'     => 'workflow',
        ], $overrides));
    }

    private function task(Maintenance $ticket, string $symptom, array $overrides = []): MaintenanceTask
    {
        return MaintenanceTask::create(array_merge([
            'maintenance_id' => $ticket->id,
            'vehicle_id'     => $ticket->vehicle_id,
            'symptom'        => $symptom,
            'status'         => MaintenanceTask::STATUS_PENDING,
        ], $overrides));
    }

    // ── C3 · recurrence is a fault concept ────────────────────────────────────────────────────────

    /**
     * REGRESSION — audit C3. `flagPossibleRecurrence` ran for EVERY promoted finding, and its subject was
     * never type-checked (only the query for the PRIOR occurrence was, and only when the rollout flag was
     * enforced). A car receiving its second oil change was therefore marked as a returning fault.
     *
     * Recurring is NORMAL for a service — it is the defining difference between the two types.
     */
    public function test_a_repeated_service_is_never_flagged_as_a_recurring_fault(): void
    {
        $vehicleId = $this->makeVehicle();

        // A completed oil change three weeks ago…
        $past = Maintenance::create(['vehicle_id' => $vehicleId, 'origin' => 'workflow']);
        $this->task($past, 'Oil Change', [
            'kind'        => MaintenanceTask::KIND_SERVICE,
            'status'      => MaintenanceTask::STATUS_COMPLETED,
            'resolved_at' => now()->subWeeks(3),
        ]);

        // …and the next one today, on the same car, well inside the 90-day recurrence window.
        $today   = Maintenance::create(['vehicle_id' => $vehicleId, 'origin' => 'workflow']);
        $service = $this->task($today, 'Oil Change', ['kind' => MaintenanceTask::KIND_SERVICE]);

        $recurring = app(RecurringFaultService::class);

        $this->assertNull($recurring->detectPriorFix($service),
            'a service must not be measured for recurrence at all');

        $recurring->flagPossibleRecurrence($service);
        $service->refresh();

        $this->assertFalse((bool) $service->recurrence_flagged, 'nothing may be written on a service');
        $this->assertNull($service->recurrence_previous_task_id);

        $actor = User::query()->first() ?? User::factory()->create();
        $this->assertNull($recurring->onFaultConfirmed($service, $actor), 'and no review case may open');
        $this->assertSame(0, RecurringFaultReview::where('maintenance_task_id', $service->id)->count());
    }

    /** The engine must still work for genuine faults — the guard narrows it, it does not disable it. */
    public function test_a_repeated_fault_is_still_detected(): void
    {
        $vehicleId = $this->makeVehicle();

        $past = Maintenance::create(['vehicle_id' => $vehicleId, 'origin' => 'workflow']);
        $this->task($past, 'A/C not cooling', [
            'kind'         => MaintenanceTask::KIND_FAULT,
            'category_key' => 'ac',
            'status'       => MaintenanceTask::STATUS_COMPLETED,
            'resolved_at'  => now()->subWeeks(3),
        ]);

        $today = Maintenance::create(['vehicle_id' => $vehicleId, 'origin' => 'workflow']);
        $fault = $this->task($today, 'A/C not cooling', [
            'kind' => MaintenanceTask::KIND_FAULT, 'category_key' => 'ac',
        ]);

        $this->assertNotNull(app(RecurringFaultService::class)->detectPriorFix($fault),
            'a real repeat fault must still be caught');
    }

    /**
     * REGRESSION — audit C3, the other half. A prior SERVICE must never be the evidence that "confirms" a
     * fault is recurring. This is unconditional: it is not gated on EVENT_KIND_MODE, because a rollout
     * flag must not decide whether a stored record is correct.
     */
    public function test_a_prior_service_never_confirms_a_recurring_fault(): void
    {
        $vehicleId = $this->makeVehicle();

        $past = Maintenance::create(['vehicle_id' => $vehicleId, 'origin' => 'workflow']);
        $this->task($past, 'Battery Replacement', [
            'kind'         => MaintenanceTask::KIND_SERVICE,
            'category_key' => 'electrical',
            'status'       => MaintenanceTask::STATUS_COMPLETED,
            'resolved_at'  => now()->subWeeks(2),
        ]);

        $today = Maintenance::create(['vehicle_id' => $vehicleId, 'origin' => 'workflow']);
        $fault = $this->task($today, 'Battery / won\'t start', [
            'kind' => MaintenanceTask::KIND_FAULT, 'category_key' => 'electrical',
        ]);

        $this->assertNull(app(RecurringFaultService::class)->detectPriorFix($fault),
            'the battery being REPLACED on schedule is not a prior failure of the battery');
    }

    // ── C2 · the vehicle service anchor ───────────────────────────────────────────────────────────

    /**
     * REGRESSION — audit C2. The reminder loop read the SYMPTOM TEXT, so a fault whose wording matched a
     * service name stamped the car as serviced; and a correctly-typed, catalog-linked service whose
     * wording differed from the reminder's label silently rolled nothing.
     */
    public function test_the_reminder_loop_reads_the_type_not_the_wording(): void
    {
        $ticket = $this->ticket();

        // `classification_source` is set so the model's creating hook respects the caller's type instead
        // of re-running the shield — this is the documented contract for an explicit pick, and it is how
        // an admin override or a type-first intake reaches the database.
        $fault = $this->task($ticket, 'Oil Change', [
            'kind'                  => MaintenanceTask::KIND_FAULT,
            'classification_source' => MaintenanceTask::CLS_MANUAL,
        ]);
        $this->assertSame(MaintenanceTask::KIND_FAULT, $fault->fresh()->kind, 'the explicit type must stick');
        $this->assertNull($fault->serviceReminderType(),
            'a FAULT named like a service must not stamp the vehicle service record');

        $service = $this->task($ticket, 'Oil Change', [
            'kind'                  => MaintenanceTask::KIND_SERVICE,
            'classification_source' => MaintenanceTask::CLS_MANUAL,
        ]);
        $this->assertSame('oil_change', $service->serviceReminderType());

        // Catalog wins over wording: "Brake Pads (service)" is not a ServiceReminder label, so the legacy
        // text resolver returns nothing for it — but the catalog row it points at knows exactly which
        // reminder it closes.
        $brakePads = ServiceCatalog::where('slug', 'brake_pads')->firstOrFail();
        $this->assertNull(Maintenance::serviceTypeForSymptom('Brake Pads (service)'),
            'precondition: the wording alone resolves to nothing');

        $catalogService = $this->task($ticket, 'Brake Pads (service)', [
            'kind'                  => MaintenanceTask::KIND_SERVICE,
            'service_catalog_id'    => $brakePads->id,
            'classification_source' => MaintenanceTask::CLS_CATALOG,
        ]);

        $this->assertSame('brake_pads', $catalogService->serviceReminderType(),
            'the catalog reference is the authority, not the wording');
    }

    // ── H5 · the catalog is the source of type ────────────────────────────────────────────────────

    /**
     * REGRESSION — audit H5. Every task in the database was `classification_source = resolver`, i.e. a
     * guess, including the ones where the inspector had picked an unambiguous catalog name. Findings that
     * name a catalog row are now born authoritatively typed.
     */
    public function test_findings_are_classified_from_the_catalog(): void
    {
        $ticket = $this->ticket(['maintenance_type' => Maintenance::TYPE_ROUTINE]);
        $ticket->findings = [
            ['text' => 'Oil Change', 'source' => Maintenance::FINDING_INSPECTOR],
            ['text' => 'wording no catalog has ever seen', 'source' => Maintenance::FINDING_INSPECTOR],
        ];
        $ticket->save();

        app(MaintenanceTaskService::class)->syncFromFindings($ticket);

        $byText = $ticket->tasks()->get()->keyBy(fn ($t) => $t->symptom);

        $service = $byText['Oil Change'];
        $this->assertSame(MaintenanceTask::KIND_SERVICE, $service->kind);
        $this->assertSame(MaintenanceTask::CLS_CATALOG, $service->classification_source,
            'a catalog name is a selection, not a guess');
        $this->assertNotNull($service->service_catalog_id);
        $this->assertFalse((bool) $service->needs_review);

        // Unknown wording still falls through to the shield — and says so.
        $unknown = $byText['wording no catalog has ever seen'];
        $this->assertSame(MaintenanceTask::CLS_RESOLVER, $unknown->classification_source);
        $this->assertTrue((bool) $unknown->needs_review,
            'the shield must never assert confidence it has not earned');
    }

    // ── DAMAGE · the third kind must never write reliability data ─────────────────────────────────

    /**
     * The domain rule that justifies the whole kind: a car whose rims are kerbed twice is a statement
     * about its DRIVERS, not a returning fault. Flagging it would open a management review case on the
     * wrong table entirely.
     */
    public function test_repeated_damage_is_never_flagged_as_a_recurring_fault(): void
    {
        $vehicleId = $this->makeVehicle();
        $catalog   = \App\Models\DamageCatalog::firstOrCreate(
            ['slug' => 'rim_scratch'],
            ['name' => 'Rim Scratch', 'category_key' => 'tyres', 'damage_type' => 'scratch']
        );

        $past = Maintenance::create(['vehicle_id' => $vehicleId, 'origin' => 'workflow']);
        $this->task($past, 'Rim Scratch', [
            'kind'                  => MaintenanceTask::KIND_DAMAGE,
            'damage_catalog_id'     => $catalog->id,
            'classification_source' => MaintenanceTask::CLS_CATALOG,
            'category_key'          => 'tyres',
            'status'                => MaintenanceTask::STATUS_COMPLETED,
            'resolved_at'           => now()->subWeeks(2),
        ]);

        $today  = Maintenance::create(['vehicle_id' => $vehicleId, 'origin' => 'workflow']);
        $damage = $this->task($today, 'Rim Scratch', [
            'kind'                  => MaintenanceTask::KIND_DAMAGE,
            'damage_catalog_id'     => $catalog->id,
            'classification_source' => MaintenanceTask::CLS_CATALOG,
            'category_key'          => 'tyres',
        ]);

        $recurring = app(RecurringFaultService::class);

        $this->assertNull($recurring->detectPriorFix($damage), 'damage is not measured for recurrence');

        $recurring->flagPossibleRecurrence($damage);
        $damage->refresh();

        $this->assertFalse((bool) $damage->recurrence_flagged);
        $this->assertNull($damage->recurrence_previous_task_id);

        $actor = User::query()->first() ?? User::factory()->create();
        $this->assertNull($recurring->onFaultConfirmed($damage, $actor));
        $this->assertSame(0, RecurringFaultReview::where('maintenance_task_id', $damage->id)->count());
    }

    /** A prior DAMAGE event must never be the evidence that "confirms" a fault is recurring. */
    public function test_prior_damage_never_confirms_a_recurring_fault(): void
    {
        $vehicleId = $this->makeVehicle();
        $catalog   = \App\Models\DamageCatalog::firstOrCreate(
            ['slug' => 'glass_crack'],
            ['name' => 'Glass Chip / Crack', 'category_key' => 'bodywork', 'damage_type' => 'crack']
        );

        $past = Maintenance::create(['vehicle_id' => $vehicleId, 'origin' => 'workflow']);
        $this->task($past, 'Glass Chip / Crack', [
            'kind'                  => MaintenanceTask::KIND_DAMAGE,
            'damage_catalog_id'     => $catalog->id,
            'classification_source' => MaintenanceTask::CLS_CATALOG,
            'category_key'          => 'bodywork',
            'status'                => MaintenanceTask::STATUS_COMPLETED,
            'resolved_at'           => now()->subWeeks(1),
        ]);

        $today = Maintenance::create(['vehicle_id' => $vehicleId, 'origin' => 'workflow']);
        $fault = $this->task($today, 'Windscreen washer not working', [
            'kind'         => MaintenanceTask::KIND_FAULT,
            'category_key' => 'bodywork',
        ]);

        $this->assertNull(app(RecurringFaultService::class)->detectPriorFix($fault),
            'a cracked screen is not a prior failure of the washer system');
    }

    /** Damage is typed from the catalog on the normal findings path, like every other kind. */
    public function test_damage_findings_are_classified_from_the_damage_catalog(): void
    {
        \App\Models\DamageCatalog::firstOrCreate(
            ['slug' => 'rim_scratch'],
            ['name' => 'Rim Scratch', 'category_key' => 'tyres', 'damage_type' => 'scratch']
        );

        $ticket = $this->ticket();
        $ticket->findings = [
            ['text' => 'Rim Scratch', 'source' => Maintenance::FINDING_INSPECTOR],
        ];
        $ticket->save();

        app(MaintenanceTaskService::class)->syncFromFindings($ticket);

        $task = $ticket->tasks()->firstWhere('symptom', 'Rim Scratch');

        $this->assertSame(MaintenanceTask::KIND_DAMAGE, $task->kind);
        $this->assertSame(MaintenanceTask::CLS_CATALOG, $task->classification_source);
        $this->assertNotNull($task->damage_catalog_id);
        $this->assertNull($task->fault_catalog_id, 'a damage row must not also reference the fault catalog');
        $this->assertFalse($task->affectsReliability());
    }

    /**
     * REGRESSION — audit L1. `kind` was effectively free text for any row with no catalog reference,
     * which is the overwhelming majority: the saving guard returned early before checking it and the DB
     * CHECK permits any string when the FKs are null.
     */
    public function test_an_unknown_kind_is_rejected(): void
    {
        $ticket = $this->ticket();

        $this->expectException(\DomainException::class);

        $this->task($ticket, 'Brake noise', ['kind' => 'banana']);
    }

    /**
     * REGRESSION — the pre-merge consistency sweep found the model guard itself hand-listing three
     * catalog columns. A row with `kind = fault` carrying a `damage_catalog_id` produced an EMPTY set,
     * took the "unclassified, permitted" early return, and was ACCEPTED — leaving the DB CHECK (which
     * production's MySQL 8 cannot install) as the only defence against a combination the application
     * considers impossible.
     *
     * The guard now derives its columns from KIND_CATALOG_FK. This test walks every kind against every
     * OTHER kind's catalog column, so a fifth kind cannot reopen the hole.
     */
    public function test_the_guard_rejects_every_kind_catalog_mismatch(): void
    {
        $ticket = $this->ticket();

        // One real id per catalog column, so the guard is tested against genuine references.
        $ids = [
            'fault_catalog_id'   => \App\Models\FaultCatalog::query()->value('id'),
            'service_catalog_id' => ServiceCatalog::where('slug', 'oil_change')->value('id'),
            'inspection_type_id' => \App\Models\InspectionType::query()->value('id'),
            'damage_catalog_id'  => \App\Models\DamageCatalog::firstOrCreate(
                ['slug' => 'rim_scratch'],
                ['name' => 'Rim Scratch', 'category_key' => 'tyres', 'damage_type' => 'scratch']
            )->id,
        ];

        $checked = 0;

        foreach (MaintenanceTask::KIND_CATALOG_FK as $kind => $ownColumn) {
            foreach (MaintenanceTask::KIND_CATALOG_FK as $otherKind => $otherColumn) {
                if ($kind === $otherKind || empty($ids[$otherColumn])) {
                    continue;
                }

                $rejected = false;
                try {
                    $this->task($ticket, "mismatch {$kind}/{$otherKind}", [
                        'kind'                  => $kind,
                        $otherColumn            => $ids[$otherColumn],
                        'classification_source' => MaintenanceTask::CLS_MANUAL,
                    ]);
                } catch (\DomainException $e) {
                    $rejected = true;
                }

                $this->assertTrue($rejected, "kind={$kind} carrying {$otherColumn} must be rejected");
                $checked++;
            }
        }

        // 4 kinds × the 3 catalogs that are not theirs. Asserted exactly, so a future kind that arrives
        // without a seeded catalog cannot make this test silently shrink.
        $expected = count(MaintenanceTask::KIND_CATALOG_FK) * (count(MaintenanceTask::KIND_CATALOG_FK) - 1);
        $this->assertSame($expected, $checked, 'every kind must be tested against every other catalog');
    }

    /** And the exactly-one-catalog rule still holds at the model, whatever the database allows. */
    public function test_a_kind_may_not_reference_another_types_catalog(): void
    {
        $ticket  = $this->ticket();
        $service = ServiceCatalog::where('slug', 'oil_change')->firstOrFail();

        $this->expectException(\DomainException::class);

        $this->task($ticket, 'Brake noise', [
            'kind'               => MaintenanceTask::KIND_FAULT,
            'service_catalog_id' => $service->id,
        ]);
    }
}
