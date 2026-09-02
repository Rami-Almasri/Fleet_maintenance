<?php

namespace Tests\Foundation;

use App\Exceptions\WarrantyGateException;
use App\Models\ComponentCatalog;
use App\Models\PartRequest;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCheckRequirement;
use App\Models\VehicleLogEvent;
use App\Models\Warranty;
use App\Models\WarrantyClaim;
use App\Notifications\FleetAlert;
use App\Services\PartWorkflowService;
use App\Services\Warranty\CoverageSubject;
use App\Services\Warranty\WarrantyCaseService;
use App\Services\Warranty\WarrantyCoverageEngine;
use App\Services\Warranty\WarrantyExpiryInspectionService;
use App\Support\WarrantyCoverage;
use App\Support\WarrantyResponsibility;
use Illuminate\Support\Facades\Hash;

/**
 * Warranty-aware operations, end to end, through the real services against a real schema.
 *
 * WHY A FOUNDATION TEST AND NOT A UNIT ONE. The rules themselves are pinned in
 * {@see \Tests\Unit\WarrantyCoverageEngineTest} without a database, and that is where they belong.
 * What CANNOT be proved in memory is the thing this feature actually promises: that a purchase
 * request on a car with live cover does not get created. That claim spans the guard, the case
 * service, the audit log, the notification fan-out and PartWorkflowService itself — four layers below
 * the door an operator uses — and a unit test on any one of them would pass while the door stayed
 * open.
 *
 * The single most important assertion in this file is
 * {@see test_the_existing_out_of_warranty_workflow_is_unchanged}: a fleet is almost entirely
 * out-of-warranty cars, and if this feature changed anything at all for them it would be a
 * regression dressed as a feature.
 *
 * DatabaseTransactions (from the base case), so nothing here survives the test.
 */
class WarrantyAwareOperationsTest extends FoundationTestCase
{
    private PartWorkflowService $parts;
    private WarrantyCaseService $cases;
    private WarrantyCoverageEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parts  = app(PartWorkflowService::class);
        $this->cases  = app(WarrantyCaseService::class);
        $this->engine = app(WarrantyCoverageEngine::class);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────────────────────

    private function catalog(string $slug = 'transmission-test'): ComponentCatalog
    {
        return ComponentCatalog::firstOrCreate(
            ['slug' => $slug . '-' . substr(md5($slug), 0, 6)],
            ['name' => ucfirst($slug), 'category_key' => 'transmission', 'tracking_mode' => 'serialized', 'is_active' => true],
        );
    }

    /** A live manufacturer warranty with nothing itemised — the ordinary state of a real booklet. */
    private function coverFor(Vehicle $vehicle, array $overrides = []): Warranty
    {
        return Warranty::create(array_merge([
            'kind'            => Warranty::KIND_VEHICLE,
            'vehicle_id'      => $vehicle->id,
            'subject'         => 'Manufacturer warranty',
            'provider_kind'   => Warranty::PROVIDER_DEALER,
            'provider_name'   => 'Authorized Dealer',
            'contact_phone'   => '+971 4 000 0000',
            'reference_no'    => 'WTY-TEST-1',
            'starts_on'       => now()->subMonths(6)->toDateString(),
            'start_odometer'  => 0,
            'duration_months' => 60,
        ], $overrides));
    }

    private function requestData(Vehicle $vehicle, ComponentCatalog $catalog, array $extra = []): array
    {
        return array_merge([
            'source'               => PartRequest::SOURCE_GARAGE,
            'vehicle_id'           => $vehicle->id,
            'part_name'            => $catalog->name,
            'component_catalog_id' => $catalog->id,
            'quantity'             => 1,
            'reason'               => 'Reported fault',
        ], $extra);
    }

    /** A user holding exactly the given permissions, and nothing else. */
    private function userWith(array $permissions): User
    {
        $user = User::create([
            'name' => 'Warranty Tester', 'email' => 'wty.'.uniqid().'@fleet.test',
            'password' => Hash::make('password'), 'status' => 'active',
        ]);
        $user->givePermissionTo($permissions);

        return $user->fresh();
    }

    // ── 23 · The one that matters most ───────────────────────────────────────────────────────────

    /**
     * A CAR WITH NO WARRANTY BEHAVES EXACTLY AS IT DID BEFORE THIS FEATURE EXISTED.
     *
     * Almost the whole fleet is in this state. If the gate changed anything here — a refusal, an
     * extra case, a notification — it would be a regression wearing a feature's clothes.
     */
    public function test_the_existing_out_of_warranty_workflow_is_unchanged(): void
    {
        $vehicle = $this->makeVehicle();
        $catalog = $this->catalog();

        $request = $this->parts->createRequest($this->requestData($vehicle, $catalog), $this->admin);

        $this->assertSame(PartRequest::STATUS_REQUESTED, $request->status);
        $this->assertSame(WarrantyCoverage::NOT_COVERED, $request->warranty_verdict);
        $this->assertSame(WarrantyCoverage::R_NO_LIVE_COVER, $request->warranty_reason_code);
        $this->assertNull($request->warranty_case_id, 'no cover means no case to open');
        $this->assertNull($request->warranty_override_at);
        $this->assertSame(0, WarrantyClaim::forVehicle($vehicle->id)->count());
    }

    // ── 10 & 13 · The gate ───────────────────────────────────────────────────────────────────────

    /**
     * #10/#13 A purchase on a car with live, un-itemised cover is REFUSED — and the refusal leaves a
     * coverage review behind rather than a dead end.
     *
     * The ordering is the point: a refusal with no work item attached is one the user routes around,
     * and the whole value of stopping here is that somebody is now holding the question.
     */
    public function test_unknown_coverage_blocks_the_purchase_and_opens_a_review(): void
    {
        $vehicle = $this->makeVehicle();
        $catalog = $this->catalog();
        $this->coverFor($vehicle);

        try {
            $this->parts->createRequest($this->requestData($vehicle, $catalog), $this->admin);
            $this->fail('the gate should have refused a purchase on a car with live cover');
        } catch (WarrantyGateException $e) {
            $this->assertSame(WarrantyCoverage::UNKNOWN, $e->context['verdict']);
            $this->assertTrue($e->context['blocks_procurement']);
            $this->assertNotNull($e->context['case_id'], 'the refusal must hand somebody the question');
            // The card needs the phone number, not just a red message.
            $this->assertSame('Authorized Dealer', $e->context['candidates'][0]['provider_name']);
        }

        $this->assertSame(0, PartRequest::where('vehicle_id', $vehicle->id)->count(), 'nothing was bought');

        $case = WarrantyClaim::forVehicle($vehicle->id)->firstOrFail();
        $this->assertSame(WarrantyClaim::STAGE_COVERAGE_REVIEW, $case->stage);
        $this->assertSame(WarrantyClaim::ORIGIN_PROCUREMENT, $case->origin);
    }

    /** A second request for the same part does NOT open a second review. */
    public function test_a_second_request_reuses_the_open_review(): void
    {
        $vehicle = $this->makeVehicle();
        $catalog = $this->catalog();
        $this->coverFor($vehicle);

        foreach ([1, 2] as $_) {
            try {
                $this->parts->createRequest($this->requestData($vehicle, $catalog), $this->admin);
            } catch (WarrantyGateException) {
                // expected
            }
        }

        $this->assertSame(1, WarrantyClaim::forVehicle($vehicle->id)->count(),
            'two buyers must not each open a review and each ring the same dealer');
    }

    // ── 11 & 12 · The two endings ────────────────────────────────────────────────────────────────

    /** #11 A covered issue follows the warranty path, and the case stays anchored to the car. */
    public function test_a_covered_issue_follows_the_warranty_path(): void
    {
        $vehicle = $this->makeVehicle();
        $catalog = $this->catalog();
        $warranty = $this->coverFor($vehicle, ['covered_catalog_ids' => [$this->catalog()->id]]);

        $assessment = $this->engine->assess($vehicle, new CoverageSubject(catalogId: $catalog->id, partName: $catalog->name));
        $this->assertSame(WarrantyCoverage::COVERED, $assessment->verdict);

        $case = $this->cases->openCoveredCase(
            $vehicle, $assessment,
            new CoverageSubject(catalogId: $catalog->id, partName: $catalog->name),
            $this->admin, WarrantyClaim::ORIGIN_MAINTENANCE,
        );

        // #18/#19 the anchors that make a case findable from the thing that caused it
        $this->assertSame($vehicle->id, $case->vehicle_id);
        $this->assertSame($warranty->id, $case->warranty_id);
        $this->assertSame($catalog->id, (int) $case->component_catalog_id);
        $this->assertSame(WarrantyClaim::STAGE_COVERED, $case->stage);

        // The provider ladder, forward only.
        $case = $this->cases->advance($case, WarrantyClaim::STAGE_AUTHORIZATION_REQUESTED, $this->admin);
        $this->assertNotNull($case->provider_response_due_on, 'a case sent to a provider must be chaseable');

        $case = $this->cases->advance($case, WarrantyClaim::STAGE_AUTHORIZED, $this->admin, ['authorization_ref' => 'AUTH-99']);
        $this->assertSame('AUTH-99', $case->authorization_ref);

        $case = $this->cases->recordRecovery($case, ['recovered_amount' => 0, 'avoided_amount' => 9000, 'remedy' => 'replacement'], $this->admin);
        $this->assertEqualsWithDelta(9000.0, $case->totalBenefit(), 0.01,
            'a free gearbox recovers nothing and avoids a great deal — both must count');

        $case = $this->cases->close($case, $this->admin, 'Done');
        $this->assertSame(WarrantyClaim::STAGE_CLOSED, $case->stage);
        $this->assertFalse($case->isOpen());
    }

    /** An authorisation with no reference is refused — it is a recollection, not an authorisation. */
    public function test_authorization_requires_a_reference(): void
    {
        $vehicle = $this->makeVehicle();
        $catalog = $this->catalog();
        $this->coverFor($vehicle, ['covered_catalog_ids' => [$catalog->id]]);

        $subject = new CoverageSubject(catalogId: $catalog->id, partName: $catalog->name);
        $case = $this->cases->openCoveredCase($vehicle, $this->engine->assess($vehicle, $subject), $subject, $this->admin);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->cases->advance($case, WarrantyClaim::STAGE_AUTHORIZED, $this->admin);
    }

    /** A case moves forward only — un-recording a dealer's answer would erase a fact, not correct it. */
    public function test_a_case_cannot_be_walked_backwards(): void
    {
        $vehicle = $this->makeVehicle();
        $catalog = $this->catalog();
        $this->coverFor($vehicle, ['covered_catalog_ids' => [$catalog->id]]);

        $subject = new CoverageSubject(catalogId: $catalog->id, partName: $catalog->name);
        $case = $this->cases->openCoveredCase($vehicle, $this->engine->assess($vehicle, $subject), $subject, $this->admin);
        $case = $this->cases->advance($case, WarrantyClaim::STAGE_SENT_TO_PROVIDER, $this->admin);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->cases->advance($case, WarrantyClaim::STAGE_AUTHORIZED, $this->admin, ['authorization_ref' => 'X']);
    }

    /**
     * #12 A review that ends "ours to pay" RELEASES procurement — and the engine remembers, so the
     * next buyer is never asked the same question again.
     */
    public function test_a_not_covered_decision_releases_procurement_permanently(): void
    {
        $vehicle = $this->makeVehicle();
        $catalog = $this->catalog();
        $this->coverFor($vehicle);

        try {
            $this->parts->createRequest($this->requestData($vehicle, $catalog), $this->admin);
        } catch (WarrantyGateException) {
            // expected — the review now exists
        }

        $case = WarrantyClaim::forVehicle($vehicle->id)->firstOrFail();
        $this->cases->decideCoverage(
            $case, WarrantyCoverage::NOT_COVERED, WarrantyCoverage::R_EXPLICITLY_EXCLUDED,
            $this->admin, 'Wear item, excluded after 40,000 km.',
        );

        // The very same call now succeeds, with the decision frozen onto the request.
        $request = $this->parts->createRequest($this->requestData($vehicle, $catalog), $this->admin);

        $this->assertSame(PartRequest::STATUS_REQUESTED, $request->status);
        $this->assertSame(WarrantyCoverage::NOT_COVERED, $request->warranty_verdict);
        $this->assertSame(WarrantyCoverage::R_DECIDED_BY_REVIEW, $request->warranty_reason_code);
    }

    // ── 14 · The override ────────────────────────────────────────────────────────────────────────

    /**
     * #14 An authorised override lets the purchase through — and puts a name, a reason and a
     * timestamp on the car's own timeline, which is the only mechanism that has ever made people
     * ring the dealer first.
     */
    public function test_an_authorized_override_proceeds_and_is_audited(): void
    {
        $vehicle = $this->makeVehicle();
        $catalog = $this->catalog();
        $this->coverFor($vehicle);

        $request = $this->parts->createRequest(
            $this->requestData($vehicle, $catalog, [
                'warranty_override_reason' => 'Car needed for a booking tomorrow; dealer unreachable until Sunday.',
            ]),
            $this->admin,
        );

        $this->assertSame(PartRequest::STATUS_REQUESTED, $request->status);
        $this->assertTrue($request->boughtOverWarranty());
        $this->assertSame($this->admin->id, (int) $request->warranty_override_by);
        $this->assertSame(WarrantyCoverage::UNKNOWN, $request->warranty_verdict,
            'the verdict that was set aside is frozen, so the decision can be judged later');

        $audit = VehicleLogEvent::where('vehicle_id', $vehicle->id)
            ->where('event_type', VehicleLogEvent::EVENT_WARRANTY_PROCUREMENT_OVERRIDE)
            ->firstOrFail();

        $this->assertStringContainsString('dealer unreachable', strtolower((string) $audit->description));
        $this->assertSame(WarrantyCoverage::UNKNOWN, $audit->meta['verdict']);
    }

    /** A user without the authority is told THAT — not asked for a longer reason. */
    public function test_an_override_without_the_permission_is_refused(): void
    {
        $vehicle = $this->makeVehicle();
        $catalog = $this->catalog();
        $this->coverFor($vehicle);

        // Can raise a purchase request; cannot overrule a warranty.
        $buyer = $this->userWith(['parts.request', 'parts.purchase']);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->parts->createRequest(
            $this->requestData($vehicle, $catalog, ['warranty_override_reason' => 'Needed urgently for a booking tomorrow.']),
            $buyer,
        );
    }

    /** "asap" is not a reason. The bar is low, but it is not zero. */
    public function test_an_override_needs_a_real_sentence(): void
    {
        $vehicle = $this->makeVehicle();
        $catalog = $this->catalog();
        $this->coverFor($vehicle);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->parts->createRequest(
            $this->requestData($vehicle, $catalog, ['warranty_override_reason' => 'asap']),
            $this->admin,
        );
    }

    // ── 15 · Who gets told ───────────────────────────────────────────────────────────────────────

    /**
     * #15 The coverage review reaches the WARRANTY DESK — defined as whoever holds `warranty.review`
     * — and nobody else. There is not a user id anywhere in the feature; who receives this changes
     * by granting a permission, never by a deploy.
     */
    public function test_a_coverage_review_notifies_the_warranty_desk_and_not_the_yard(): void
    {
        $desk   = $this->userWith([WarrantyResponsibility::REVIEW, WarrantyResponsibility::VIEW]);
        $driver = $this->userWith(['logistics.view']);

        $vehicle = $this->makeVehicle();
        $catalog = $this->catalog();
        $this->coverFor($vehicle);

        try {
            $this->parts->createRequest($this->requestData($vehicle, $catalog), $this->admin);
        } catch (WarrantyGateException) {
            // expected
        }

        $deskAlerts = $desk->notifications()->where('type', FleetAlert::class)->get();

        $this->assertTrue(
            $deskAlerts->contains(fn ($n) => ($n->data['type'] ?? null) === 'warranty_coverage_review'),
            'the person who decides coverage must be told there is a decision waiting',
        );
        $this->assertSame(0, $driver->notifications()->where('type', FleetAlert::class)->count(),
            'a driver has no business receiving warranty adjudication');

        // The card must LAND somewhere, not merely say something.
        $card = $deskAlerts->first(fn ($n) => ($n->data['type'] ?? null) === 'warranty_coverage_review');
        $this->assertStringStartsWith('/warranty/cases/', $card->data['url']);
        $this->assertSame($vehicle->plate_no, $card->data['meta']['plate']);
    }

    /** Deciding "not covered" tells the BUYERS — silence is what teaches people to override. */
    public function test_a_not_covered_decision_tells_procurement(): void
    {
        $buyer = $this->userWith(['parts.purchase']);

        $vehicle = $this->makeVehicle();
        $catalog = $this->catalog();
        $this->coverFor($vehicle);

        try {
            $this->parts->createRequest($this->requestData($vehicle, $catalog), $this->admin);
        } catch (WarrantyGateException) {
            // expected
        }

        $case = WarrantyClaim::forVehicle($vehicle->id)->firstOrFail();
        $this->cases->decideCoverage($case, WarrantyCoverage::NOT_COVERED, WarrantyCoverage::R_EXPLICITLY_EXCLUDED, $this->admin, 'Wear item.');

        $this->assertTrue(
            $buyer->notifications()->where('type', FleetAlert::class)->get()
                ->contains(fn ($n) => ($n->data['type'] ?? null) === 'warranty_not_covered'),
        );
    }

    // ── 20 & 21 · The spare key, both ways ───────────────────────────────────────────────────────

    /**
     * #20 THE SAME NEED, TWO ACQUISITION PATHS.
     *
     * Exercised through PartWorkflowService::createRequest, which is the exact method
     * SpareKeyService::createPurchaseRequest delegates to — so this proves the spare-key flow is
     * warranty-aware WITHOUT this test (or the warranty layer) knowing anything about spare keys.
     * That is the design: the gate sits on the one door every purchase comes through, so a feature
     * built beside it inherits the behaviour rather than having to opt in.
     *
     * The physical result is identical either way — the key becomes a component on the car. Only who
     * pays for it differs.
     */
    public function test_a_spare_key_takes_the_normal_path_out_of_warranty_and_the_warranty_path_in_it(): void
    {
        $key = ComponentCatalog::firstOrCreate(
            ['slug' => 'spare-key'],
            ['name' => 'Spare Key', 'name_ar' => 'مفتاح احتياطي', 'category_key' => 'electrical',
             'tracking_mode' => 'serialized', 'is_active' => true],
        );

        // (a) Out of warranty — the existing procurement workflow, untouched.
        $plain = $this->makeVehicle();
        $request = $this->parts->createRequest($this->requestData($plain, $key, ['reason' => 'Car has only one key']), $this->admin);
        $this->assertSame(PartRequest::STATUS_REQUESTED, $request->status);
        $this->assertSame(WarrantyCoverage::NOT_COVERED, $request->warranty_verdict);

        // (b) In warranty — held, with a review opened for the desk.
        $covered = $this->makeVehicle();
        $this->coverFor($covered);

        try {
            $this->parts->createRequest($this->requestData($covered, $key, ['reason' => 'Car has only one key']), $this->admin);
            $this->fail('a spare key on a car under warranty must not be bought unreviewed');
        } catch (WarrantyGateException $e) {
            $this->assertSame(WarrantyCoverage::UNKNOWN, $e->context['verdict']);
        }

        $case = WarrantyClaim::forVehicle($covered->id)->firstOrFail();
        $this->assertSame($key->id, (int) $case->component_catalog_id);

        // (c) The desk says the dealer does not cover keys → the ordinary buy proceeds, exactly as
        //     it did for the car in (a). Same ending, different route to it.
        $this->cases->decideCoverage($case, WarrantyCoverage::NOT_COVERED, WarrantyCoverage::R_EXPLICITLY_EXCLUDED, $this->admin, 'Keys are not covered.');

        $second = $this->parts->createRequest($this->requestData($covered, $key, ['reason' => 'Car has only one key']), $this->admin);
        $this->assertSame(PartRequest::STATUS_REQUESTED, $second->status);
    }

    // ── 17 · The pre-expiry inspection ───────────────────────────────────────────────────────────

    /**
     * #17 Cover about to run out raises an inspection obligation — once, however many times the
     * sweep runs.
     *
     * Idempotency is asserted explicitly because the sweep runs every morning for the whole lead
     * window: a version of this that raised a new obligation each day would bury the inspector in
     * thirty copies of the same question, and they would stop reading all of them.
     */
    public function test_cover_about_to_expire_raises_one_inspection_however_often_the_sweep_runs(): void
    {
        $vehicle = $this->makeVehicle();
        // Ends in twelve days: inside any sane lead window.
        $this->coverFor($vehicle, [
            'starts_on' => now()->subMonths(36)->addDays(12)->toDateString(),
            'duration_months' => 36,
        ]);

        $sweep = app(WarrantyExpiryInspectionService::class);
        $sweep->sweep(30);
        $sweep->sweep(30);   // the next morning, and the one after

        $raised = VehicleCheckRequirement::where('vehicle_id', $vehicle->id)
            ->where('check_type', 'warranty_expiry')
            ->get();

        $this->assertCount(1, $raised, 'one warranty, one obligation — the cycle key is the warranty');
        $this->assertSame('check.warranty_expiring', $raised->first()->reason_code,
            'a CODE, never an English sentence — the UI composes the wording in either language');
    }

    /** A warranty with plenty of room left is not asked about. */
    public function test_healthy_cover_raises_nothing(): void
    {
        $vehicle = $this->makeVehicle();
        $this->coverFor($vehicle, ['duration_months' => 60]);

        app(WarrantyExpiryInspectionService::class)->sweep(30);

        $this->assertSame(0, VehicleCheckRequirement::where('vehicle_id', $vehicle->id)
            ->where('check_type', 'warranty_expiry')->count());
    }

    // ── 1, 2, 8 · Recording cover ────────────────────────────────────────────────────────────────

    /**
     * #1/#2/#8 A car can carry several coverages, and the badge reads them as one state.
     */
    public function test_a_car_can_hold_several_coverages_and_reads_as_one_state(): void
    {
        $vehicle = $this->makeVehicle(['odometer' => 40000]);

        $this->coverFor($vehicle, ['subject' => 'Bumper to bumper', 'duration_months' => 36]);
        $this->coverFor($vehicle, ['subject' => 'Powertrain', 'duration_months' => 60]);

        $this->assertSame(2, $vehicle->vehicleWarranties()->count());

        $state = app(\App\Services\Warranty\WarrantyStatusService::class)->vehicleState($vehicle->fresh());

        $this->assertSame(WarrantyCoverage::STATE_UNDER_WARRANTY, $state['state']);
        $this->assertSame(2, $state['live_count']);
        $this->assertSame('Powertrain', $state['headline']['subject'], 'the badge quotes the longest-lasting promise');
    }

    /** Recording a warranty lands on the car's own timeline, where "why did we pay?" is answered. */
    public function test_recording_a_warranty_is_audited_on_the_vehicle(): void
    {
        $vehicle = $this->makeVehicle();

        app(\App\Services\WarrantyService::class)->create([
            'kind'            => Warranty::KIND_VEHICLE,
            'vehicle_id'      => $vehicle->id,
            'subject'         => 'Manufacturer warranty',
            'provider_name'   => 'Authorized Dealer',
            'starts_on'       => now()->toDateString(),
            'duration_months' => 36,
        ], $this->admin);

        $this->assertSame(1, VehicleLogEvent::where('vehicle_id', $vehicle->id)
            ->where('event_type', VehicleLogEvent::EVENT_WARRANTY_RECORDED)->count());
    }

    /** A whole-car promise needs no anchor — that is what makes it recordable before anything breaks. */
    public function test_a_vehicle_warranty_needs_no_anchor_beyond_the_car(): void
    {
        $vehicle = $this->makeVehicle();

        $warranty = app(\App\Services\WarrantyService::class)->create([
            'kind' => Warranty::KIND_VEHICLE, 'vehicle_id' => $vehicle->id,
            'subject' => 'Dealer warranty', 'starts_on' => now()->toDateString(), 'duration_months' => 36,
        ], $this->admin);

        $this->assertSame(Warranty::KIND_VEHICLE, $warranty->kind);
        $this->assertNull($warranty->part_purchase_id);
        $this->assertNull($warranty->maintenance_task_id);
    }
}
