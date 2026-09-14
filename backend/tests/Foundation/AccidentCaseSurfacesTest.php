<?php

namespace Tests\Foundation;

use App\Models\AccidentCase;
use App\Models\AccidentFinancialEntry;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\DamageCatalog;
use App\Models\VehicleDocument;
use App\Models\VehicleLocation;
use App\Models\VehicleLogEvent;
use App\Services\ActivityFeedService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The surfaces the workflow suite does not reach — the reads, the dossier, and the two rules that
 * only show up once time has passed.
 *
 * AccidentCaseWorkflowTest covers the state machine and its refusals. This covers everything a
 * PERSON touches around it: the board and its queues, the dashboard tiles, the intake preview, the
 * document trail, and the feed the vehicle timeline is built from. Split into a second file because
 * they fail for different reasons — a red test here means a screen is wrong, not that the workflow is.
 */
class AccidentCaseSurfacesTest extends FoundationTestCase
{

    /**
     * Any live damage type. Damage is named from the catalog rather than typed, so a test that
     * hard-coded "Front bumper" would be asserting the old free-text contract.
     */
    protected function aDamageType(): int
    {
        return (int) \App\Models\DamageCatalog::where('is_active', true)->value('id');
    }
    private function rented(?string $outDate = null, ?string $inDate = null): array
    {
        $vehicle = $this->makeVehicle();
        $customer = Customer::create([
            'customer_no' => 'C' . random_int(100000, 999999),
            'name_en'     => 'Surfaces Customer ' . uniqid(),
            'mobile1'     => '0501112222',
        ]);
        $contract = Contract::create([
            'contract_no'   => 'RC' . random_int(100000, 999999),
            'contract_type' => 'C',
            'state'         => 'open',
            'vehicle_id'    => $vehicle->id,
            'customer_id'   => $customer->id,
            'out_date'      => $outDate ?: now()->subDays(5)->toDateString(),
            'in_date'       => $inDate,
        ]);

        return [$vehicle, $customer, $contract];
    }

    private function report($vehicle, array $extra = []): int
    {
        return $this->idOf($this->postJson('/api/accidents', array_merge([
            'vehicle_id'  => $vehicle->id,
            'occurred_at' => now()->subHours(2)->toIso8601String(),
            'description' => 'Test crash.',
        ], $extra))->assertCreated());
    }

    // ── the intake preview ────────────────────────────────────────────────────────────────────

    /**
     * The read-only preview the intake form shows WHILE somebody is typing. It must answer with the
     * same vocabulary the case will use, and it must change nothing.
     */
    public function test_intake_options_ships_the_vocabularies_and_a_read_only_context_preview(): void
    {
        [$vehicle, $customer, $contract] = $this->rented();

        $res = $this->getJson('/api/accidents/options?vehicle_id=' . $vehicle->id)->assertOk();

        $this->assertSame(AccidentCase::ACCIDENT_TYPES, $res->json('data.accident_types'));
        $this->assertSame(AccidentCase::LIABILITY_STATUSES, $res->json('data.liability'));
        $this->assertSame(AccidentFinancialEntry::PHASES, $res->json('data.phases'));
        $this->assertNotEmpty($res->json('data.damage_groups'), 'the damage vocabulary is reused, not reinvented');
        $this->assertNotEmpty($res->json('data.damage_groups.0.items'), 'grouped, because a flat list of 27 is a scroll not a choice');
        $this->assertNotEmpty($res->json('data.location_groups'), 'and the WHERE axis alongside it');
        $this->assertArrayHasKey('police_report', $res->json('data.document_kinds'));

        $preview = $res->json('data.context_preview');
        $this->assertSame(AccidentCase::PARTY_RENTAL_CUSTOMER, $preview['responsible_party_type']);
        $this->assertSame($customer->name_en, $preview['customer_name']);
        $this->assertSame($contract->contract_no, $preview['contract_no']);

        // Read-only: asking the question must not have opened anything.
        $this->assertSame(0, AccidentCase::forVehicle($vehicle->id)->count());
    }

    /**
     * THE RULE THAT ONLY SHOWS UP ONCE TIME HAS PASSED. A crash reported today about last month
     * belongs to last month's renter — the resolver is anchored on `occurred_at`, not on the clock.
     * Get this wrong and the case names whoever happens to have the car when somebody gets round to
     * filing it.
     */
    public function test_the_context_is_anchored_on_when_it_happened_not_on_now(): void
    {
        $vehicle = $this->makeVehicle();

        $past = Customer::create(['customer_no' => 'C' . random_int(100000, 999999), 'name_en' => 'Last Month Renter']);
        Contract::create([
            'contract_no' => 'RC' . random_int(100000, 999999), 'contract_type' => 'C', 'state' => 'closed',
            'vehicle_id' => $vehicle->id, 'customer_id' => $past->id,
            'out_date' => now()->subDays(40)->toDateString(),
            'in_date'  => now()->subDays(20)->toDateString(),
        ]);

        $today = Customer::create(['customer_no' => 'C' . random_int(100000, 999999), 'name_en' => 'Todays Renter']);
        Contract::create([
            'contract_no' => 'RC' . random_int(100000, 999999), 'contract_type' => 'C', 'state' => 'open',
            'vehicle_id' => $vehicle->id, 'customer_id' => $today->id,
            'out_date' => now()->subDays(2)->toDateString(),
        ]);

        $id = $this->report($vehicle, ['occurred_at' => now()->subDays(30)->toIso8601String()]);

        $ctx = $this->getJson("/api/accidents/$id")->assertOk()->json('data.context');
        $this->assertSame('Last Month Renter', $ctx['customer']['name'], 'the renter at the TIME, not the one holding it now');
        $this->assertSame('closed', $ctx['contract']['state_at_accident']);
    }

    // ── the board and its queues ──────────────────────────────────────────────────────────────

    /**
     * A tile and the list it links to must never disagree — they are the same scope, so the queue
     * filter is tested against the dashboard's own count.
     */
    public function test_the_board_queues_agree_with_the_dashboard_tiles(): void
    {
        [$onHire] = $this->rented();
        $parked   = $this->makeVehicle();

        $withCustomer = $this->report($onHire);
        $yard = $this->report($parked, ['responsible_party_type' => AccidentCase::PARTY_PARKED]);

        // Settle one of them completely so the queues have something to EXCLUDE.
        $this->postJson("/api/accidents/$yard/police/bypass", ['reason' => 'Damaged in our own yard.'])->assertOk();
        $this->postJson("/api/accidents/$yard/liability", [
            'liability_status' => AccidentCase::LIABILITY_COMPANY, 'liability_source' => 'internal',
        ])->assertOk();

        $dash = $this->getJson('/api/accidents/dashboard')->assertOk()->json('data');

        // per_page raised past the default 25: the tile counts every matching case while the list
        // paginates, so on a schema holding more than a page of them the two would "disagree" purely
        // because the second page was never fetched — a false red that says nothing about the code.
        $police = collect($this->getJson('/api/accidents?queue=police&per_page=200')->assertOk()->json('data.cases'));
        $this->assertTrue($police->contains('id', $withCustomer));
        $this->assertFalse($police->contains('id', $yard), 'a waived report is not still awaiting one');
        $this->assertSame($dash['awaiting_police'], $police->count());

        $liability = collect($this->getJson('/api/accidents?queue=liability&per_page=200')->assertOk()->json('data.cases'));
        $this->assertTrue($liability->contains('id', $withCustomer));
        $this->assertFalse($liability->contains('id', $yard), 'that one has been ruled on');

        $rental = collect($this->getJson('/api/accidents?queue=rental&per_page=200')->assertOk()->json('data.cases'));
        $this->assertTrue($rental->contains('id', $withCustomer));
        $this->assertFalse($rental->contains('id', $yard));

        $this->assertGreaterThanOrEqual(1, $dash['police_waived'], 'the guardrail counts its own bypasses');
        // The board draws its columns from the PUBLISHED workflow, in its configured order — so a
        // reorder on the settings screen reaches the board without a deploy, and the board can never
        // offer a column the service would refuse to move a case into.
        $published = \App\Models\AccidentWorkflowStage::whereHas('workflow', fn ($w) => $w->where('status', 'active'))
            ->orderBy('position')->pluck('key')->all();
        $this->assertSame($published, array_column($this->getJson('/api/accidents')->json('data.stages'), 'key'));
    }

    /** Search reaches the frozen customer and contract, not just the case's own columns. */
    public function test_the_board_searches_the_frozen_context(): void
    {
        [$vehicle, $customer, $contract] = $this->rented();
        $id = $this->report($vehicle);

        foreach ([$customer->name_en, $contract->contract_no] as $needle) {
            $hits = collect($this->getJson('/api/accidents?q=' . urlencode($needle))->assertOk()->json('data.cases'));
            $this->assertTrue($hits->contains('id', $id), "searching for “{$needle}” must find the case");
        }
    }

    /**
     * The dashboard's money keeps the phases apart, exactly as a case does.
     *
     * MEASURED AS A DELTA, not as an absolute. The dashboard is a FLEET-WIDE aggregate, so asserting
     * "estimated == 5,700" silently assumes this schema holds no other accidents — which is true on
     * a clean run and false the moment anything else has ever been recorded, including data written
     * outside the test transaction. A fleet-wide figure has to be tested by what THIS case adds to
     * it; anything else is a test that passes for the wrong reason and then fails for one too.
     */
    public function test_the_dashboard_money_never_sums_across_phases(): void
    {
        $before = $this->getJson('/api/accidents/dashboard')->assertOk()->json('data.money');

        $vehicle = $this->makeVehicle();
        $id = $this->report($vehicle);

        foreach ([
            ['estimate', 'insurance', 5000],
            ['approved', 'insurance', 4000],
            ['actual',   'company',   4200],
            ['paid',     'insurance', 3800],
            ['estimate', 'unresolved', 700],
        ] as [$phase, $party, $amount]) {
            $this->postJson("/api/accidents/$id/financials", compact('phase', 'party') + ['amount' => $amount])->assertCreated();
        }

        $after = $this->getJson('/api/accidents/dashboard')->assertOk()->json('data.money');
        $added = fn (string $key) => round((float) $after[$key] - (float) $before[$key], 2);

        $this->assertEquals(5700, $added('estimated'), 'estimate rows only — 5,000 insurance + 700 unresolved');
        $this->assertEquals(4000, $added('approved'), 'an estimate is not an approval');
        $this->assertEquals(4200, $added('actual'));
        $this->assertEquals(3800, $added('paid'), 'an approval is not a payment');
        $this->assertEquals(400, $added('outstanding'), 'actual 4,200 − paid 3,800');
        $this->assertEquals(700, $added('unresolved'), 'carried as its own figure, never folded away');
    }

    // ── the dossier ───────────────────────────────────────────────────────────────────────────

    /**
     * Uploading a police report is EVIDENCE ONE EXISTS, not verification. It nudges a case that had
     * nothing to `recorded` and stops there — the reading is a separate, separately-permissioned act.
     */
    public function test_uploading_a_police_report_records_it_but_never_verifies_it(): void
    {
        Storage::fake('public');
        $vehicle = $this->makeVehicle();
        $id = $this->report($vehicle);

        $res = $this->post("/api/accidents/$id/documents", [
            'file' => UploadedFile::fake()->create('report.pdf', 40, 'application/pdf'),
            'kind' => VehicleDocument::KIND_POLICE_REPORT,
            'note' => 'Scan from the station.',
        ])->assertCreated();

        $this->assertSame(AccidentCase::POLICE_RECORDED, $res->json('data.police_status'));
        $this->assertFalse(AccidentCase::find($id)->policeSatisfied(), 'a scan is not a reading');

        $doc = $res->json('data.documents.0');
        $this->assertSame(VehicleDocument::KIND_POLICE_REPORT, $doc['kind']);
        $this->assertSame('Police report', $doc['kind_label']);
        $this->assertArrayHasKey('police_report', $res->json('data.by_kind'));

        // The timeline row carries the door to the file itself.
        $events = $this->getJson("/api/accidents/$id/timeline")->assertOk()->json('data.events');
        $added = collect($events)->firstWhere('event_type', VehicleLogEvent::EVENT_ACCIDENT_DOCUMENT_ADDED);
        $this->assertNotNull($added);
        $this->assertNotEmpty($added['link'], 'the event opens the document');
        $this->assertSame('Open Police report', $added['link_label']);
    }

    /**
     * A dossier, not a slot: eleven photographs of one wing coexist and none supersedes another.
     * Deleting one is the escape hatch for a wrong upload and leaves the rest alone.
     */
    public function test_the_accident_dossier_never_supersedes_itself(): void
    {
        Storage::fake('public');
        $vehicle = $this->makeVehicle();
        $id = $this->report($vehicle);

        $ids = [];
        foreach (['a', 'b', 'c'] as $n) {
            $res = $this->post("/api/accidents/$id/documents", [
                'file' => UploadedFile::fake()->image("wing-$n.jpg"),
                'kind' => VehicleDocument::KIND_DAMAGE_PHOTO,
            ])->assertCreated();
            $ids = collect($res->json('data.documents'))->pluck('id')->all();
        }

        $this->assertCount(3, $ids);
        $this->assertSame(0, VehicleDocument::whereIn('id', $ids)->whereNotNull('superseded_at')->count(),
            'nothing in an accident file supersedes anything else in it');

        $after = $this->deleteJson("/api/accidents/$id/documents/{$ids[0]}")->assertOk();
        $this->assertCount(2, $after->json('data.documents'));
    }

    /** A document belonging to another case is refused, not silently detached. */
    public function test_a_document_from_another_case_cannot_be_deleted_through_this_one(): void
    {
        Storage::fake('public');
        $mine  = $this->report($this->makeVehicle());
        $other = $this->report($this->makeVehicle());

        $doc = $this->post("/api/accidents/$other/documents", [
            'file' => UploadedFile::fake()->image('x.jpg'),
            'kind' => VehicleDocument::KIND_DAMAGE_PHOTO,
        ])->assertCreated()->json('data.documents.0.id');

        $this->deleteJson("/api/accidents/$mine/documents/$doc")->assertNotFound();
        $this->assertDatabaseHas('vehicle_documents', ['id' => $doc, 'accident_case_id' => $other]);
    }

    // ── the vehicle surface ───────────────────────────────────────────────────────────────────

    /** The car's own accident list, and the straight answer the counter needs: may it be let? */
    public function test_the_vehicle_endpoint_reports_the_rental_hold(): void
    {
        $vehicle = $this->makeVehicle();
        $id = $this->report($vehicle);

        $res = $this->getJson("/api/accidents/vehicle/{$vehicle->id}")->assertOk();
        $this->assertTrue($res->json('data.restricted'));
        $this->assertSame($id, $res->json('data.cases.0.id'));
        $this->assertSame(0, $res->json('data.cases.0.damage_items_count'));

        $this->postJson("/api/accidents/$id/damage", ['damage_catalog_id' => $this->aDamageType()])->assertCreated();
        $this->assertSame(1, $this->getJson("/api/accidents/vehicle/{$vehicle->id}")->json('data.cases.0.damage_items_count'));

        $this->postJson("/api/accidents/$id/police/bypass", ['reason' => 'No report available.'])->assertOk();
        $this->postJson("/api/accidents/$id/liability", [
            'liability_status' => AccidentCase::LIABILITY_UNKNOWN, 'liability_source' => 'internal',
        ])->assertOk();
        $this->postJson("/api/accidents/$id/close")->assertOk();

        $this->assertFalse($this->getJson("/api/accidents/vehicle/{$vehicle->id}")->json('data.restricted'),
            'a closed case releases the car');
    }

    // ── the feed the vehicle timeline is built from ───────────────────────────────────────────

    /**
     * The accident events must be their OWN category in the activity feed. Filed under 'maintenance'
     * they would be invisible to "show me this car's accidents" and would pad the maintenance count.
     */
    public function test_accident_events_are_their_own_category_in_the_feed(): void
    {
        $vehicle = $this->makeVehicle();
        $id = $this->report($vehicle);

        $feed = collect(app(ActivityFeedService::class)->forVehicle($vehicle->id));
        $accident = $feed->where('category', 'accident');

        $this->assertTrue($accident->isNotEmpty());
        $this->assertSame('rose', $accident->first()['tone'], 'a crash must not read as a complaint');
        $this->assertSame($id, $accident->first()['accident_case_id']);
        $this->assertSame('/accidents/' . $id, $accident->first()['link']);
        $this->assertSame('Accident reported', $feed->firstWhere('event_type', VehicleLogEvent::EVENT_ACCIDENT_REPORTED)['action']);

        // …and they must NOT be swept into the maintenance bucket.
        $this->assertTrue($accident->every(fn ($e) => $e['category'] !== 'maintenance'));
    }

    // ── the rules that need a second pass to show ─────────────────────────────────────────────

    /**
     * Re-recording a police report on a VERIFIED case drops it back to `recorded`. The numbers
     * changed, so whatever was verified is no longer what the case says — silently keeping the
     * verification would leave a signed-off case describing a different document.
     */
    public function test_changing_a_verified_police_report_withdraws_the_verification(): void
    {
        $vehicle = $this->makeVehicle();
        $id = $this->report($vehicle);

        $this->postJson("/api/accidents/$id/police", ['police_report_no' => 'DXB-1'])->assertOk();
        $this->postJson("/api/accidents/$id/police/verify")->assertOk();

        $res = $this->postJson("/api/accidents/$id/police", ['police_report_no' => 'DXB-2'])->assertOk();

        $this->assertSame(AccidentCase::POLICE_RECORDED, $res->json('data.police.status'));
        $this->assertNull($res->json('data.police.verified_by_name'), 'the old verification does not carry over');
        $this->assertFalse($res->json('data.police.satisfied'));
    }

    /**
     * `claim_submitted_at` is stamped ONCE. It is the date the insurer's clock started, and
     * re-stamping it every time the status moves would erase the only fact that dates the claim.
     */
    public function test_the_claim_submission_date_is_stamped_once(): void
    {
        $vehicle = $this->makeVehicle();
        $id = $this->report($vehicle);

        $first = $this->postJson("/api/accidents/$id/insurance", [
            'insurer_name' => 'Oman Insurance', 'claim_status' => AccidentCase::CLAIM_SUBMITTED,
        ])->assertOk()->json('data.insurance.submitted_at');
        $this->assertNotNull($first);

        $this->postJson("/api/accidents/$id/insurance", ['claim_status' => AccidentCase::CLAIM_UNDER_REVIEW])->assertOk();
        $again = $this->postJson("/api/accidents/$id/insurance", ['claim_status' => AccidentCase::CLAIM_SUBMITTED])->assertOk();

        $this->assertSame($first, $again->json('data.insurance.submitted_at'), 'the clock started once');
        // Notes accumulate rather than overwrite — the correspondence IS the history.
        $this->postJson("/api/accidents/$id/insurance", ['insurance_note' => 'They asked for photos.'])->assertOk();
        $final = $this->postJson("/api/accidents/$id/insurance", ['insurance_note' => 'Photos sent.'])->assertOk();
        $this->assertStringContainsString('They asked for photos.', $final->json('data.insurance.note'));
        $this->assertStringContainsString('Photos sent.', $final->json('data.insurance.note'));
    }

    /** A closed case is frozen against the money and the dossier too, not just the narrative. */
    public function test_a_closed_case_refuses_money_and_documents(): void
    {
        Storage::fake('public');
        $vehicle = $this->makeVehicle();
        $id = $this->report($vehicle);
        $this->postJson("/api/accidents/$id/police/bypass", ['reason' => 'None obtainable.'])->assertOk();
        $this->postJson("/api/accidents/$id/liability", [
            'liability_status' => AccidentCase::LIABILITY_COMPANY, 'liability_source' => 'internal',
        ])->assertOk();
        $this->postJson("/api/accidents/$id/close")->assertOk();

        $this->postJson("/api/accidents/$id/financials", [
            'phase' => 'paid', 'party' => 'insurance', 'amount' => 100,
        ])->assertStatus(422)->assertJsonValidationErrors('stage');

        $this->postJson("/api/accidents/$id/damage", ['damage_catalog_id' => $this->aDamageType()])
            ->assertStatus(422)->assertJsonValidationErrors('stage');

        // Closing states the final position on the timeline rather than leaving it to be re-derived.
        $closed = collect($this->getJson("/api/accidents/$id/timeline")->json('data.events'))
            ->firstWhere('event_type', VehicleLogEvent::EVENT_ACCIDENT_CLOSED);
        $this->assertArrayHasKey('financials', $closed['meta']);
        $this->assertArrayHasKey('outstanding', $closed['meta']);
    }

    /** References are sequential within the year — humans read these out over the phone. */
    public function test_references_are_sequential_and_unique(): void
    {
        $a = $this->getJson('/api/accidents/' . $this->report($this->makeVehicle()))->json('data.reference');
        $b = $this->getJson('/api/accidents/' . $this->report($this->makeVehicle()))->json('data.reference');

        $this->assertMatchesRegularExpression('/^ACC-\d{4}-\d{4}$/', $a);
        $this->assertNotSame($a, $b);
        $this->assertSame(1, (int) substr($b, -4) - (int) substr($a, -4));
    }

    /** A damage item can carry the shared vocabularies — the catalog and the curated location axis. */
    public function test_a_damage_item_can_be_pinned_to_the_shared_vocabularies(): void
    {
        $vehicle = $this->makeVehicle();
        $id = $this->report($vehicle);

        $catalog  = DamageCatalog::query()->first();
        $location = VehicleLocation::query()->first();
        $this->assertNotNull($catalog, 'the damage catalog must be seeded for this test to mean anything');

        $item = $this->postJson("/api/accidents/$id/damage", [
            'damage_catalog_id'   => $catalog->id,
            'vehicle_location_id' => $location?->id,
            'severity'            => 'moderate',
            'estimated_cost'      => 1450.50,
            'requires_replacement' => true,
        ])->assertCreated();

        $this->assertSame($catalog->id, $item->json('data.damage_catalog_id'));
        // The display label is DERIVED from the two vocabularies, never typed — that is what keeps
        // "how much did bumper damage cost us?" a group-by rather than a spelling lottery.
        $expected = trim($catalog->name . ($location ? ' — ' . $location->name : ''));
        $this->assertSame($expected, $item->json('data.area_label'));
        $this->assertTrue($item->json('data.requires_replacement'));
        $this->assertFalse($item->json('data.repaired'), 'nothing has fixed it yet');
    }

    /**
     * DAMAGE IS NAMED FROM THE CATALOG, NOT TYPED — the regression guard on the whole point of the
     * picker.
     *
     * A free-text area is uncountable the moment two people spell it differently, and nothing
     * anywhere reports that the grouping broke. So the endpoint refuses a bare label and the label it
     * stores is DERIVED. If somebody ever "helpfully" makes `area_label` acceptable again, this goes
     * red rather than the reporting going quietly wrong six months later.
     */
    public function test_damage_cannot_be_recorded_as_free_text(): void
    {
        $vehicle = $this->makeVehicle();
        $id = $this->report($vehicle);

        // The old contract — a typed area and nothing else.
        $this->postJson("/api/accidents/$id/damage", ['area_label' => 'front bumper'])
            ->assertStatus(422)->assertJsonValidationErrors('damage_catalog_id');

        // …and it cannot sneak in at intake either.
        $this->postJson('/api/accidents', [
            'vehicle_id' => $this->makeVehicle()->id,
            'description' => 'Typed damage.',
            'damage_items' => [['area_label' => 'left door']],
        ])->assertStatus(422)->assertJsonValidationErrors('damage_items.0.damage_catalog_id');

        $this->assertSame(0, \App\Models\AccidentDamageItem::where('accident_case_id', $id)->count());
    }

    /**
     * And the payoff: because the id is stored, the dashboard can GROUP BY what actually gets broken.
     * This is the query a free-text field makes impossible.
     */
    public function test_the_dashboard_groups_damage_by_type_and_by_area(): void
    {
        $vehicle = $this->makeVehicle();
        $id = $this->report($vehicle);

        $catalog  = DamageCatalog::where('is_active', true)->firstOrFail();
        $location = VehicleLocation::where('is_active', true)->first();

        foreach ([1200, 800] as $cost) {
            $this->postJson("/api/accidents/$id/damage", [
                'damage_catalog_id'   => $catalog->id,
                'vehicle_location_id' => $location?->id,
                'estimated_cost'      => $cost,
            ])->assertCreated();
        }

        $dash = $this->getJson('/api/accidents/dashboard')->assertOk()->json('data');

        $row = collect($dash['by_damage_type'])->firstWhere('name', $catalog->name);
        $this->assertNotNull($row, 'the damage type is reportable by name');
        $this->assertGreaterThanOrEqual(2, $row['items']);
        $this->assertGreaterThanOrEqual(2000, $row['estimated'], 'and its spend adds up');

        if ($location) {
            $this->assertArrayHasKey($location->name, $dash['by_damage_area']);
        }
    }

    /** An accident in the future is a typo, and the intake says so rather than storing it. */
    public function test_a_future_accident_is_refused(): void
    {
        $this->postJson('/api/accidents', [
            'vehicle_id'  => $this->makeVehicle()->id,
            'occurred_at' => now()->addDay()->toIso8601String(),
            'description' => 'Tomorrow.',
        ])->assertStatus(422)->assertJsonValidationErrors('occurred_at');
    }
}
