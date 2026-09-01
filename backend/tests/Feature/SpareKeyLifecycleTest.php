<?php

namespace Tests\Feature;

use App\Models\ComponentCatalog;
use App\Models\PartPurchase;
use App\Models\PartRequest;
use App\Models\SpareKeyRequirement;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleComponent;
use App\Models\VehicleLogEvent;
use App\Services\PartWorkflowService;
use App\Services\SpareKeyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * END-TO-END guard for the spare-key lifecycle: need → purchase request → approval → purchase →
 * receipt → a physical key belonging to the car, through the REAL services at every step.
 *
 * WHY A FEATURE TEST. Almost every claim this feature makes is a claim about how three existing
 * subsystems compose — the part-request state machine, the asset ledger's slot invariant, and the
 * projection that keeps the requirement in step with both. A unit test on any one of them would
 * prove nothing about the two things that can actually go wrong here: a receipt that stamps
 * "delivered" without producing a key, and a second receipt that produces one key too many.
 *
 * REQUIRES the `fleet_e2e_scratch` MySQL database — run with `-c phpunit.e2e.xml`. There is no
 * RefreshDatabase on purpose: the full migration set cannot be replayed from empty (a raw MySQL-only
 * ALTER in 2026_06_11_140001 breaks it), so the schema is CLONED from live and the tables this test
 * touches are truncated instead. @see ComponentLimitSnapshotTest, which established this pattern.
 */
class SpareKeyLifecycleTest extends TestCase
{
    /** Only the tables this test writes to — truncated per test so each starts clean. */
    private const TOUCHED = [
        'spare_key_requirements', 'part_requests', 'part_purchases',
        'vehicle_components', 'component_events', 'maintenance_line_items',
        'vehicle_log_events', 'vehicles', 'users', 'notifications',
    ];

    private User $supervisor;
    private User $otherSupervisor;
    private User $approver;
    private Vehicle $vehicle;
    private ComponentCatalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDatabaseName() !== 'fleet_e2e_scratch') {
            $this->markTestSkipped('needs the fleet_e2e_scratch database — run with -c phpunit.e2e.xml');
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (self::TOUCHED as $table) {
            DB::table($table)->truncate();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        // The asset layer's shadow/enforced flag governs the WORKFLOW-coupled install door. The
        // spare-key receipt is an explicit custody write and must work regardless — pinned to the
        // production default here so the test proves that, rather than proving 'enforced' works.
        config()->set('features.asset_layer', 'shadow');

        $this->seedRbac();

        $this->supervisor      = $this->userWithRole('Waleed Medhat', 'supervisor');
        $this->otherSupervisor = $this->userWithRole('Abdullah Asham', 'supervisor');
        $this->approver        = $this->userWithRole('Workshop Manager', 'maintenance');

        $this->vehicle = Vehicle::create([
            'plate_no' => '32967',
            'make'     => 'CHEVROLET',
            'model'    => 'CORVETTE STINGRAY',
            'year'     => 2025,
            'odometer' => 12_000,
            'status'   => 'available',
        ]);

        $this->catalog = ComponentCatalog::firstOrCreate(
            ['slug' => SpareKeyRequirement::CATALOG_SLUG],
            [
                'name'            => 'Spare Key',
                'name_ar'         => 'مفتاح احتياطي',
                'category_key'    => 'interior',
                'tracking_mode'   => ComponentCatalog::TRACKING_BATCH,
                'position_scheme' => ComponentCatalog::SCHEME_SET,
                'is_active'       => true,
            ]
        );
    }

    // ───────────────────────────── fixtures ─────────────────────────────

    /**
     * The two permissions and two roles this feature's authorisation actually rests on. Created
     * here rather than by running the full RolesAndPermissionsSeeder, because the scratch schema is
     * a clone and re-seeding the whole RBAC tree per test is slow and touches tables this test has
     * no business truncating.
     */
    private function seedRbac(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['parts.view', 'parts.request', 'parts.purchase', 'parts.investigate', 'maintenance.manage'] as $p) {
            Permission::findOrCreate($p, 'web');
        }

        Role::findOrCreate('supervisor', 'web')
            ->syncPermissions(['parts.view', 'parts.request', 'parts.purchase']);
        Role::findOrCreate('maintenance', 'web')
            ->syncPermissions(['parts.view', 'parts.request', 'parts.purchase', 'parts.investigate', 'maintenance.manage']);
        Role::findOrCreate('viewer', 'web')->syncPermissions(['parts.view']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function userWithRole(string $name, string $role): User
    {
        $user = User::create([
            'name'     => $name,
            'email'    => strtolower(str_replace(' ', '.', $name)) . '.' . uniqid() . '@fleet.test',
            'password' => bcrypt('password'),
            'status'   => 'active',
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function keys(): SpareKeyService
    {
        return app(SpareKeyService::class);
    }

    private function parts(): PartWorkflowService
    {
        return app(PartWorkflowService::class);
    }

    /** Drive the chain up to (but not including) receipt, and hand back every row it produced. */
    private function chainToPurchase(int $quantity = 1, float $price = 350.0): array
    {
        $requirement = $this->keys()->raise($this->vehicle, ['quantity' => $quantity], $this->supervisor);
        $request     = $this->keys()->createPurchaseRequest($requirement, [], $this->supervisor);

        $request  = $this->parts()->approve($request, $this->approver);
        $purchase = $this->parts()->purchase($request, [
            'purchase_source' => PartPurchase::SOURCE_SUPPLIER,
            'source_name'     => 'Key Cutting Co',
            'purchase_price'  => $price,
            'quantity'        => $quantity,
        ], $this->supervisor)['purchase'];

        return [$requirement->fresh(), $request->fresh(), $purchase];
    }

    // ───────────────────────────── 1. raising the need ─────────────────────────────

    public function test_a_spare_key_requirement_can_be_raised_against_a_vehicle(): void
    {
        $requirement = $this->keys()->raise($this->vehicle, [
            'reason_code' => SpareKeyRequirement::REASON_MISSING,
            'quantity'    => 1,
            'notes'       => 'Only one key came with the car.',
        ], $this->supervisor);

        $this->assertSame(SpareKeyRequirement::STATUS_REQUIRED, $requirement->status);
        $this->assertSame($this->vehicle->id, $requirement->vehicle_id);
        $this->assertSame('Waleed Medhat', $requirement->requested_by_name);
        $this->assertSame(SpareKeyRequirement::SOURCE_APP, $requirement->source);
        $this->assertTrue($requirement->isOpen());
        // The open lock mirrors the vehicle while the need stands — this is what the unique index
        // enforces the duplicate rule through.
        $this->assertSame($this->vehicle->id, (int) $requirement->open_vehicle_id);
    }

    public function test_raising_a_requirement_writes_an_auditable_vehicle_timeline_entry(): void
    {
        $requirement = $this->keys()->raise($this->vehicle, [], $this->supervisor);

        $event = VehicleLogEvent::where('vehicle_id', $this->vehicle->id)
            ->where('event_type', VehicleLogEvent::EVENT_SPARE_KEY_REQUIRED)
            ->first();

        $this->assertNotNull($event, 'raising a spare-key need must land on the vehicle timeline');
        $this->assertSame($requirement->id, (int) data_get($event->meta, 'spare_key_requirement_id'));
        $this->assertSame($this->supervisor->id, (int) $event->actor_id);
    }

    /** THE DUPLICATE RULE, enforced by the database rather than by a check-then-insert race. */
    public function test_a_second_open_requirement_for_the_same_vehicle_is_refused(): void
    {
        $this->keys()->raise($this->vehicle, [], $this->supervisor);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->expectExceptionMessage('already has an open spare-key requirement');

        $this->keys()->raise($this->vehicle, [], $this->otherSupervisor);
    }

    /** …but a car whose last need was met may legitimately need another key later. */
    public function test_a_new_requirement_is_allowed_once_the_previous_one_completed(): void
    {
        [$requirement] = $this->chainToPurchase();
        $this->keys()->receive($requirement, [], $this->supervisor);

        $this->assertSame(SpareKeyRequirement::STATUS_COMPLETED, $requirement->fresh()->status);

        $second = $this->keys()->raise($this->vehicle, ['reason_code' => SpareKeyRequirement::REASON_LOST], $this->supervisor);

        $this->assertSame(SpareKeyRequirement::STATUS_REQUIRED, $second->status);
        $this->assertSame(2, SpareKeyRequirement::forVehicle($this->vehicle->id)->count());
    }

    // ───────────────────────────── 2. who hears about it ─────────────────────────────

    /**
     * The audience is resolved from ROLES and config, never from user ids in code — so this asserts
     * the resolution, not a hard-coded pair of names.
     */
    public function test_the_supervisors_are_notified_and_nobody_else_is(): void
    {
        Notification::fake();

        $this->keys()->raise($this->vehicle, [], $this->supervisor);

        // The person who raised it is skipped; the other supervisor is told; the approver (a
        // maintenance-role user who holds parts.request too) is NOT — the fallback is narrowed by
        // role precisely so a broad permission does not fan the bell out across the company.
        Notification::assertSentTo($this->otherSupervisor, \App\Notifications\FleetAlert::class);
        Notification::assertNotSentTo($this->supervisor, \App\Notifications\FleetAlert::class);
        Notification::assertNotSentTo($this->approver, \App\Notifications\FleetAlert::class);
    }

    public function test_the_notification_names_the_car_and_links_to_the_requirement(): void
    {
        Notification::fake();

        $requirement = $this->keys()->raise($this->vehicle, [], $this->supervisor);

        Notification::assertSentTo($this->otherSupervisor, \App\Notifications\FleetAlert::class,
            function (\App\Notifications\FleetAlert $alert) use ($requirement) {
                $data = $alert->toArray($this->otherSupervisor);

                return $data['title'] === 'Spare Key Required'
                    && str_contains($data['body'], 'CHEVROLET CORVETTE STINGRAY')
                    && str_contains($data['body'], '32967')
                    && str_contains((string) $data['url'], (string) $requirement->id)
                    && $data['key'] === 'spare_key_req:' . $requirement->id;
            });
    }

    /** An explicit allow-list overrides the role fallback — the production-safe setting. */
    public function test_a_configured_recipient_list_replaces_the_role_fallback(): void
    {
        config()->set('maintenance.spare_keys.recipient_user_ids', [$this->approver->id]);

        $recipients = $this->keys()->recipients()->pluck('id')->all();

        $this->assertSame([$this->approver->id], $recipients);
    }

    // ───────────────────────────── 3. need → purchase request ─────────────────────────────

    public function test_a_purchase_request_is_created_from_the_requirement_and_stays_linked_to_it(): void
    {
        $requirement = $this->keys()->raise($this->vehicle, ['quantity' => 2], $this->supervisor);
        $request     = $this->keys()->createPurchaseRequest($requirement, ['estimated_price' => 400], $this->supervisor);

        $this->assertInstanceOf(PartRequest::class, $request);
        // THE TRACEABILITY CLAIM: "why was this bought?" is answerable by a join, not a guess.
        $this->assertSame($requirement->id, (int) $request->spare_key_requirement_id);
        $this->assertSame($this->vehicle->id, (int) $request->vehicle_id);
        $this->assertSame($this->catalog->id, (int) $request->component_catalog_id);
        $this->assertSame(2.0, (float) $request->quantity);
        $this->assertStringContainsString("requirement #{$requirement->id}", $request->reason);

        // …and the requirement now says a buy is in flight.
        $this->assertSame(SpareKeyRequirement::STATUS_PURCHASE_REQUESTED, $requirement->fresh()->status);
    }

    public function test_a_second_purchase_request_cannot_be_opened_while_one_is_live(): void
    {
        $requirement = $this->keys()->raise($this->vehicle, [], $this->supervisor);
        $this->keys()->createPurchaseRequest($requirement, [], $this->supervisor);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->expectExceptionMessage('is already open for this requirement');

        $this->keys()->createPurchaseRequest($requirement->fresh(), [], $this->supervisor);
    }

    // ───────────────────────────── 4. the existing approval workflow ─────────────────────────────

    public function test_approval_runs_through_the_existing_part_request_workflow(): void
    {
        $requirement = $this->keys()->raise($this->vehicle, [], $this->supervisor);
        $request     = $this->keys()->createPurchaseRequest($requirement, [], $this->supervisor);

        $approved = $this->parts()->approve($request, $this->approver);

        // The approval chain is auditable on the EXISTING columns — no second approval store.
        $this->assertSame(PartRequest::STATUS_APPROVED, $approved->status);
        $this->assertSame($this->approver->id, (int) $approved->approved_by);
        $this->assertSame('Workshop Manager', $approved->approved_by_name);
        $this->assertNotNull($approved->approved_at);

        $this->assertSame(SpareKeyRequirement::STATUS_APPROVED, $requirement->fresh()->status);
    }

    /**
     * THE REJECTION RULE. A refused buy must never read as a met need — the car still has no key,
     * so the requirement stays OPEN and stays on the board.
     */
    public function test_a_rejected_purchase_request_leaves_the_requirement_open_and_re_requestable(): void
    {
        $requirement = $this->keys()->raise($this->vehicle, [], $this->supervisor);
        $request     = $this->keys()->createPurchaseRequest($requirement, [], $this->supervisor);

        $rejected = $this->parts()->reject($request, $this->approver, 'Dealer quote too high — try the locksmith');

        $this->assertSame(PartRequest::STATUS_REJECTED, $rejected->status);
        $this->assertSame('Dealer quote too high — try the locksmith', $rejected->rejection_reason);
        $this->assertSame($this->approver->id, (int) $rejected->rejected_by);

        $requirement = $requirement->fresh();
        $this->assertSame(SpareKeyRequirement::STATUS_REJECTED, $requirement->status);
        $this->assertTrue($requirement->isOpen(), 'a refused buy ends a purchase, never a need');
        $this->assertNotNull($requirement->open_vehicle_id, 'the car must still count as outstanding');

        // And the next attempt is allowed, against the SAME requirement, so the history keeps both.
        $second = $this->keys()->createPurchaseRequest($requirement, [], $this->supervisor);
        $this->assertSame($requirement->id, (int) $second->spare_key_requirement_id);
        $this->assertSame(2, $requirement->purchaseRequests()->count());
    }

    // ───────────────────────────── 5. receipt → a physical key ─────────────────────────────

    public function test_receiving_creates_a_physical_component_belonging_to_the_vehicle(): void
    {
        [$requirement, , $purchase] = $this->chainToPurchase(price: 420.0);

        $result = $this->keys()->receive($requirement, ['serial_no' => 'KEY-8891'], $this->supervisor);

        $this->assertCount(1, $result['components']);

        /** @var VehicleComponent $key */
        $key = $result['components'][0];
        $this->assertSame($this->vehicle->id, (int) $key->vehicle_id);
        $this->assertSame($this->catalog->id, (int) $key->component_catalog_id);
        $this->assertSame(VehicleComponent::STATUS_ACTIVE, $key->status);
        $this->assertSame(VehicleComponent::LOC_ON_VEHICLE, $key->location);
        $this->assertSame('unit_1', $key->position);
        $this->assertSame('KEY-8891', $key->serial_no);
        $this->assertSame(420.0, (float) $key->purchase_cost);
        // Provenance: the key knows which buy produced it, which is the link back to the requirement.
        $this->assertSame($purchase->id, (int) $key->source_part_purchase_id);
        $this->assertSame(VehicleComponent::EV_PURCHASE, $key->evidence_channel);

        $this->assertSame(SpareKeyRequirement::STATUS_COMPLETED, $result['requirement']->status);
        $this->assertNotNull($result['requirement']->completed_at);
    }

    /** Receipt and delivery commit together — never a delivered purchase with no key behind it. */
    public function test_receipt_marks_the_purchase_delivered_in_the_same_breath(): void
    {
        [$requirement, , $purchase] = $this->chainToPurchase();

        $this->assertNull($purchase->delivered_at);

        $this->keys()->receive($requirement, [], $this->supervisor);

        $this->assertNotNull($purchase->fresh()->delivered_at);
    }

    /** THE IDEMPOTENCY CLAIM: one physical key, however many times the button is pressed. */
    public function test_receiving_twice_does_not_create_a_second_key(): void
    {
        [$requirement] = $this->chainToPurchase();

        $first  = $this->keys()->receive($requirement, [], $this->supervisor);
        $second = $this->keys()->receive($requirement->fresh(), [], $this->supervisor);

        $this->assertCount(1, $first['components']);
        $this->assertCount(1, $second['components'], 'a repeated receipt must return the same key, not mint another');
        $this->assertSame($first['components'][0]->id, $second['components'][0]->id);

        $this->assertSame(1, VehicleComponent::where('vehicle_id', $this->vehicle->id)
            ->where('component_catalog_id', $this->catalog->id)->count());
    }

    public function test_a_key_cannot_be_received_before_the_purchase_exists(): void
    {
        $requirement = $this->keys()->raise($this->vehicle, [], $this->supervisor);
        $request     = $this->keys()->createPurchaseRequest($requirement, [], $this->supervisor);
        $this->parts()->approve($request, $this->approver);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->expectExceptionMessage('has no purchase recorded against it yet');

        $this->keys()->receive($requirement->fresh(), [], $this->supervisor);
    }

    // ───────────────────────────── 6. several keys ─────────────────────────────

    public function test_two_keys_are_received_as_two_independently_traceable_components(): void
    {
        [$requirement] = $this->chainToPurchase(quantity: 2, price: 300.0);

        $result = $this->keys()->receive($requirement, ['quantity' => 2], $this->supervisor);

        $this->assertCount(2, $result['components']);
        $this->assertSame(['unit_1', 'unit_2'], collect($result['components'])->pluck('position')->all());
        // Each key is its own asset row with its own id — not one row with quantity 2.
        $this->assertCount(2, collect($result['components'])->pluck('id')->unique());
        $this->assertSame(SpareKeyRequirement::STATUS_COMPLETED, $result['requirement']->status);
    }

    /** A half-delivery is `received`, not `completed` — the car is still owed a key. */
    public function test_receiving_part_of_a_multi_key_requirement_leaves_it_open(): void
    {
        [$requirement] = $this->chainToPurchase(quantity: 2);

        $result = $this->keys()->receive($requirement, ['quantity' => 1], $this->supervisor);

        $this->assertSame(SpareKeyRequirement::STATUS_RECEIVED, $result['requirement']->status);
        $this->assertTrue($result['requirement']->isOpen());
        $this->assertSame(1, $result['requirement']->outstandingQuantity());
    }

    // ───────────────────────────── 7. current vs history ─────────────────────────────

    /**
     * THE HEADLINE DISTINCTION. A car that lost a key has fewer keys than it has had — and the panel
     * must be able to say both numbers without either one contradicting the other.
     */
    public function test_the_vehicle_panel_separates_keys_held_today_from_keys_ever_received(): void
    {
        // Two keys arrive.
        [$first] = $this->chainToPurchase(quantity: 2);
        $received = $this->keys()->receive($first, ['quantity' => 2], $this->supervisor)['components'];

        // One is lost and retired from the ledger.
        app(\App\Services\ComponentService::class)->remove($received[1], [
            'removal_reason' => VehicleComponent::REASON_FAILED,
            'disposition'    => VehicleComponent::DISP_SCRAPPED,
            'removal_note'   => 'Key lost by the driver',
        ], $this->supervisor);

        // A third requirement is raised to replace it.
        $this->keys()->raise($this->vehicle, ['reason_code' => SpareKeyRequirement::REASON_LOST], $this->supervisor);

        $panel = $this->keys()->forVehicle($this->vehicle->fresh());

        $this->assertSame(1, $panel['current']['count'], 'the car HOLDS one key today');
        $this->assertSame(2, $panel['history']['requirements_raised'], 'two requirements have ever been raised');
        $this->assertSame(2, $panel['history']['keys_ever_received'], 'two keys have ever arrived');
        $this->assertSame(1, $panel['history']['keys_retired'], 'and one of them is gone');
        $this->assertSame('unit_1', $panel['current']['keys'][0]['slot']);
    }

    public function test_a_completed_requirement_is_reported_as_completed_with_its_chain_intact(): void
    {
        [$requirement, $request] = $this->chainToPurchase();
        $this->keys()->receive($requirement, [], $this->supervisor);

        $panel = $this->keys()->forVehicle($this->vehicle->fresh());
        $row   = $panel['history']['requirements'][0];

        $this->assertSame(SpareKeyRequirement::STATUS_COMPLETED, $row['status']);
        $this->assertFalse($row['is_open']);
        $this->assertSame($request->id, $row['purchase_request']['id']);
        $this->assertSame('Workshop Manager', $row['purchase_request']['approved_by_name']);
        $this->assertSame('Key Cutting Co', $row['purchase']['supplier']);
        $this->assertNotNull($row['purchase']['delivered_at']);
    }

    // ───────────────────────────── 8. imported history ─────────────────────────────

    /**
     * A sheet row is a requirement and its dates — nothing else. It must NOT arrive carrying an
     * invented key, supplier or price, and the panel must show it as history without inflating the
     * count of keys the car actually holds.
     */
    public function test_imported_sheet_history_is_preserved_without_fabricating_a_physical_key(): void
    {
        $imported = new SpareKeyRequirement();
        $imported->fill([
            'vehicle_id'        => $this->vehicle->id,
            'quantity'          => 1,
            'reason_code'       => SpareKeyRequirement::REASON_MISSING,
            'source'            => SpareKeyRequirement::SOURCE_SHEET_IMPORT,
            'external_ref'      => 'need-spare-key-2:test',
            'started_on'        => '2026-07-01',
            'finished_on'       => '2026-07-06',
            'requested_by_name' => 'Sheet import (NEED SPARE KEY)',
        ]);
        $imported->status          = SpareKeyRequirement::STATUS_COMPLETED;
        $imported->open_vehicle_id = null;
        $imported->save();

        $panel = $this->keys()->forVehicle($this->vehicle->fresh());

        $this->assertSame(1, $panel['history']['requirements_raised']);
        $this->assertSame(0, $panel['history']['keys_ever_received'], 'the sheet is no evidence that a key exists');
        $this->assertSame(0, $panel['current']['count']);

        $row = $panel['history']['requirements'][0];
        $this->assertSame('2026-07-01', $row['started_on']);
        $this->assertSame('2026-07-06', $row['finished_on']);
        $this->assertNull($row['purchase'], 'no purchase may be invented for an imported row');
        $this->assertNull($row['purchase_request']);
    }

    // ───────────────────────────── 9. the board ─────────────────────────────

    public function test_the_operations_board_counts_every_stage(): void
    {
        // One completed, one still required, one refused.
        [$done] = $this->chainToPurchase();
        $this->keys()->receive($done, [], $this->supervisor);

        $second = Vehicle::create(['plate_no' => '12281', 'make' => 'RANGE ROVER', 'model' => 'SPORT', 'status' => 'available']);
        $this->keys()->raise($second, [], $this->supervisor);

        $third   = Vehicle::create(['plate_no' => '91485', 'make' => 'CHEVROLET', 'model' => 'TRAVERSE', 'status' => 'available']);
        $req3    = $this->keys()->raise($third, [], $this->supervisor);
        $pr3     = $this->keys()->createPurchaseRequest($req3, [], $this->supervisor);
        $this->parts()->reject($pr3, $this->approver, 'not now');

        $board = $this->keys()->board();

        $this->assertSame(1, $board['counts'][SpareKeyRequirement::STATUS_COMPLETED]);
        $this->assertSame(1, $board['counts'][SpareKeyRequirement::STATUS_REQUIRED]);
        $this->assertSame(1, $board['counts'][SpareKeyRequirement::STATUS_REJECTED]);
        $this->assertSame(2, $board['open_total'], 'a rejected requirement is still outstanding work');
        $this->assertCount(3, $board['rows']);
    }

    // ───────────────────────────── 10. authorisation ─────────────────────────────

    public function test_a_read_only_user_cannot_raise_a_requirement(): void
    {
        $viewer = $this->userWithRole('Read Only', 'viewer');

        $this->actingAs($viewer, 'sanctum')
            ->postJson('/api/spare-keys', ['vehicle_id' => $this->vehicle->id])
            ->assertForbidden();

        $this->assertSame(0, SpareKeyRequirement::count());
    }

    public function test_a_supervisor_may_raise_a_requirement_through_the_api(): void
    {
        $this->actingAs($this->supervisor, 'sanctum')
            ->postJson('/api/spare-keys', [
                'vehicle_id'  => $this->vehicle->id,
                'reason_code' => SpareKeyRequirement::REASON_MISSING,
                'quantity'    => 1,
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.current.count', 0)
            ->assertJsonPath('data.history.requirements_raised', 1);
    }

    /** The money gate is unchanged: a supervisor raises and buys, but does not approve. */
    public function test_a_supervisor_cannot_approve_the_purchase_request_they_raised(): void
    {
        $requirement = $this->keys()->raise($this->vehicle, [], $this->supervisor);
        $request     = $this->keys()->createPurchaseRequest($requirement, [], $this->supervisor);

        $this->actingAs($this->supervisor, 'sanctum')
            ->postJson("/api/part-requests/{$request->id}/approve")
            ->assertForbidden();

        $this->assertSame(PartRequest::STATUS_REQUESTED, $request->fresh()->status);
    }

    // ───────────────────────────── 11. nothing else moved ─────────────────────────────

    /**
     * REGRESSION GUARD. The projection hook now runs on every approve/reject/purchase in the fleet.
     * A normal part request has no requirement behind it and must be completely unaffected — same
     * statuses, same stamps, no spare-key row created out of thin air.
     */
    public function test_an_ordinary_part_request_is_untouched_by_the_spare_key_hook(): void
    {
        $request = $this->parts()->createRequest([
            'source'     => PartRequest::SOURCE_GARAGE,
            'vehicle_id' => $this->vehicle->id,
            'part_name'  => 'Alternator',
            'quantity'   => 1,
            'reason'     => 'Not charging',
        ], $this->supervisor);

        $this->assertNull($request->spare_key_requirement_id);

        $approved = $this->parts()->approve($request, $this->approver);
        $this->assertSame(PartRequest::STATUS_APPROVED, $approved->status);

        $purchase = $this->parts()->purchase($approved, [
            'purchase_source' => PartPurchase::SOURCE_SUPPLIER,
            'source_name'     => 'Parts Co',
            'purchase_price'  => 900,
        ], $this->supervisor)['purchase'];

        $this->assertSame(PartRequest::STATUS_PURCHASED, $approved->fresh()->status);
        $this->assertSame(900.0, (float) $purchase->purchase_price);
        $this->assertSame(0, SpareKeyRequirement::count(), 'an ordinary part must not conjure a spare-key requirement');
    }
}
