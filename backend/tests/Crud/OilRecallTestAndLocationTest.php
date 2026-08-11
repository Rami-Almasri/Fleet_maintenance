<?php

namespace Tests\Crud;

use App\Models\Contract;
use App\Models\ContractOilDecision;
use App\Models\LogisticsTask;
use App\Models\Maintenance;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\OilChangeProjectionService;
use Illuminate\Support\Facades\Hash;

/**
 * THE TWO CHOICES LEEN MAKES WHEN SHE RECALLS A CAR — and what each one routes.
 *
 * A recall used to answer only "bring it back". Two questions were always being asked out loud in
 * the office and answered nowhere in the system:
 *
 *   1. IS THIS CAR ALREADY DUE A TEST? Very often the scheduler has already flagged it ("routine
 *      check overdue, 15 days") and the card is sitting in /inspection-review. Filing a second
 *      request for the same car on the same day is how a review queue stops being believed — so
 *      when Leen says "yes, test it too", the oil change is ADDED TO THAT REQUEST. When she says no,
 *      no request is filed at all and the system's own card is left exactly as it was: the oil
 *      change is a driver job, not a test.
 *
 *   2. WHERE IS THE OIL CHANGED? In our parking the Inspector (Abu Maroof) does it; at a garage the
 *      Supervisors arrange it. The driver is told either way — he is fetching the car. So the
 *      location is not decoration: it decides who gets the alert and where the car is driven.
 *
 * Fixture arithmetic matches the sibling oil suites: limit 7,000 · allowed max 7,500.
 */
class OilRecallTestAndLocationTest extends CrudTestCase
{
    private const INTERVAL = 7000;

    private function service(): OilChangeProjectionService
    {
        return app(OilChangeProjectionService::class);
    }

    private function user(string $name, array $permissions = []): User
    {
        $user = User::create([
            'name'     => $name,
            'email'    => 'u.' . uniqid() . '@fleet.test',
            'password' => Hash::make('password'),
            'status'   => 'active',
        ]);
        foreach ($permissions as $p) {
            $user->givePermissionTo($p);
        }

        return $user;
    }

    /** Waleed & Abdullah (garage lane) and Abu Maroof (parking lane), as the app resolves each. */
    private function people(): array
    {
        $waleed   = $this->user('Waleed', ['maintenance.delegate']);
        $abdullah = $this->user('Abdullah', ['maintenance.delegate']);
        config(['maintenance.checkpoint.default_user_ids' => [$waleed->id, $abdullah->id]]);

        // Abu Maroof is reached through the INSPECTOR role, not the bare permission — Lin, Marwa and
        // the QA accounts hold `maintenance.initiate` too, and must not be alerted for his job.
        $abuMaroof = $this->user('Abu Maroof', ['maintenance.initiate']);
        $abuMaroof->assignRole('inspector');

        return [$waleed, $abdullah, $abuMaroof];
    }

    private function rentalWithReading(int $remainingDays, int $reading): Contract
    {
        $vehicleId = $this->makeVehicle();
        Vehicle::whereKey($vehicleId)->update([
            'status'                => 'rented',
            'operational_status'    => 'rented',
            'odometer'              => 5000,
            'last_service_odometer' => 0,
            'service_interval_km'   => self::INTERVAL,
        ]);

        $contract = Contract::create([
            'contract_no'   => 'C-' . strtoupper(uniqid()),
            'contract_type' => 'C',
            'state'         => 'open',
            'vehicle_id'    => $vehicleId,
            'customer_id'   => $this->makeCustomer(),
            'out_date'      => now()->subDay()->toDateString(),
            'out_milage'    => 5000,
            'days'          => 1 + $remainingDays,
        ]);

        $this->postJson('/api/Contract/' . $contract->id . '/mileage-reading', [
            'odometer'    => $reading,
            'reported_by' => 'Customer (phone)',
        ])->assertSuccessful();

        return $contract->fresh();
    }

    /** The system's own request, as the scheduler leaves it: waiting in the review queue. */
    private function systemTestRequest(int $vehicleId): Maintenance
    {
        $ticket = app(\App\Services\MaintenanceWorkflowService::class)->requestInspectionByController([
            'vehicle_id'         => $vehicleId,
            'trigger_reason'     => 'test_drive',
            'customer_complaint' => 'Routine check overdue — 15 days since last maintenance completion.',
            'test_kind'          => Maintenance::TEST_ROUTINE_CHECK,
        ], $this->admin);

        $ticket->workflow_status = Maintenance::WF_PENDING_REVIEW;
        $ticket->trigger_detail  = ['source' => 'post_downtime_check', 'idle_days' => 15];
        $ticket->save();

        return $ticket->fresh();
    }

    private function decision(Contract $contract): ContractOilDecision
    {
        return ContractOilDecision::where('contract_id', $contract->id)->orderByDesc('id')->firstOrFail();
    }

    private function alertCount(User $user, string $key): int
    {
        return $user->notifications()->where('data->key', $key)->count();
    }

    // ═══════════════════════════════════════════════════════════════════════════════════════════
    //  A — THE BOARD SAYS SO BEFORE ANYONE DECIDES
    // ═══════════════════════════════════════════════════════════════════════════════════════════

    public function test_a_the_board_reports_the_test_request_this_car_already_has(): void
    {
        $contract = $this->rentalWithReading(remainingDays: 10, reading: 7600);
        $existing = $this->systemTestRequest($contract->vehicle_id);

        $row = collect($this->getJson('/api/OilProjection')->assertSuccessful()->json('data.contracts'))
            ->firstWhere('contract_id', $contract->id);

        $this->assertNotNull($row['pending_test_request'], 'the card must warn that a request already exists');
        $this->assertSame($existing->id, $row['pending_test_request']['id']);
        $this->assertStringContainsString('Routine check overdue', $row['pending_test_request']['reason']);
    }

    // ═══════════════════════════════════════════════════════════════════════════════════════════
    //  B — "TEST IT TOO" ADOPTS THE REQUEST INSTEAD OF FILING A SECOND ONE
    // ═══════════════════════════════════════════════════════════════════════════════════════════

    public function test_b_the_oil_change_is_added_to_the_request_the_system_already_raised(): void
    {
        $this->people();
        $contract = $this->rentalWithReading(remainingDays: 10, reading: 7600);
        $existing = $this->systemTestRequest($contract->vehicle_id);

        $this->postJson('/api/Contract/' . $contract->id . '/oil-decision', [
            'decision'         => 'recall',
            'test_required'    => true,
            'service_location' => 'garage',
        ])->assertSuccessful();

        $decision = $this->decision($contract);

        // ONE card for this car, and it is the system's own.
        $this->assertSame($existing->id, $decision->inspection_ticket_id);
        $this->assertTrue($decision->hasAdoptedRequest());
        $this->assertSame(1, Maintenance::where('vehicle_id', $contract->vehicle_id)
            ->where('workflow_status', Maintenance::WF_PENDING_REVIEW)->count(),
            'a second request for the same car is exactly what adoption exists to prevent');

        // Its own reason survives — the system's explanation is evidence, not scaffolding.
        $fresh = $existing->fresh();
        $this->assertSame('post_downtime_check', $fresh->trigger_detail['source']);
        $this->assertSame(15, $fresh->trigger_detail['idle_days']);
        $this->assertStringContainsString('Routine check overdue', $fresh->customer_complaint);
        // …with the oil layer added alongside it.
        $this->assertSame($decision->id, $fresh->trigger_detail['oil_projection']['contract_oil_decision_id']);
        $this->assertStringContainsString('Oil follow-up', $fresh->customer_complaint);

        // And the review card renders both stories.
        $ctx = \App\Http\Resources\MaintenanceWorkflowResource::make($fresh)->resolve()['oil_context'];
        $this->assertNotNull($ctx);
        $this->assertTrue($ctx['recall']['request_adopted']);
    }

    /** Finishing the oil must NEVER close an adopted request — the test has not been done. */
    public function test_b2_the_completed_oil_change_leaves_the_adopted_test_open(): void
    {
        $this->people();
        $contract = $this->rentalWithReading(remainingDays: 10, reading: 7600);
        $existing = $this->systemTestRequest($contract->vehicle_id);

        $this->postJson('/api/Contract/' . $contract->id . '/oil-decision', [
            'decision' => 'recall', 'test_required' => true, 'service_location' => 'parking',
        ])->assertSuccessful();
        $this->postJson('/api/Contract/' . $contract->id . '/oil-recall/sales-confirm', [])->assertSuccessful();
        $this->postJson('/api/Contract/' . $contract->id . '/oil-change-done', ['odometer' => 20000])
            ->assertSuccessful();

        $fresh = $existing->fresh();
        $this->assertContains($fresh->workflow_status, Maintenance::WF_PRE_TICKET,
            'the system asked for a test; changing the oil did not perform it');
        $this->assertStringContainsString('20,000 km', (string) $fresh->review_notes);
    }

    // ═══════════════════════════════════════════════════════════════════════════════════════════
    //  C — "NO TEST" FILES NOTHING AND TOUCHES NOTHING
    // ═══════════════════════════════════════════════════════════════════════════════════════════

    public function test_c_declining_the_test_raises_no_request_and_leaves_the_systems_alone(): void
    {
        $this->people();
        $contract = $this->rentalWithReading(remainingDays: 10, reading: 7600);
        $existing = $this->systemTestRequest($contract->vehicle_id);

        $this->postJson('/api/Contract/' . $contract->id . '/oil-decision', [
            'decision'         => 'recall',
            'test_required'    => false,
            'service_location' => 'parking',
        ])->assertSuccessful();

        $decision = $this->decision($contract);
        $this->assertNull($decision->inspection_ticket_id, 'no test wanted ⇒ no request filed');
        $this->assertFalse((bool) $decision->test_required);

        // The system's own card is untouched — not adopted, not rewritten, not closed.
        $fresh = $existing->fresh();
        $this->assertSame(Maintenance::WF_PENDING_REVIEW, $fresh->workflow_status);
        $this->assertArrayNotHasKey('oil_projection', (array) $fresh->trigger_detail);
        $this->assertStringNotContainsString('Oil follow-up', (string) $fresh->customer_complaint);

        // The oil change still happens — it is a driver job now, and the relay still runs.
        $this->postJson('/api/Contract/' . $contract->id . '/oil-recall/sales-confirm', [])->assertSuccessful();
        $this->assertNotNull($decision->fresh()->collection_task_id);
    }

    // ═══════════════════════════════════════════════════════════════════════════════════════════
    //  D — WHERE DECIDES WHO
    // ═══════════════════════════════════════════════════════════════════════════════════════════

    public function test_d_parking_tells_abu_maroof_and_sends_the_driver_to_the_parking(): void
    {
        [$waleed, $abdullah, $abuMaroof] = $this->people();

        $contract = $this->rentalWithReading(remainingDays: 10, reading: 7600);
        $this->postJson('/api/Contract/' . $contract->id . '/oil-decision', [
            'decision' => 'recall', 'test_required' => false, 'service_location' => 'parking',
        ])->assertSuccessful();
        $this->postJson('/api/Contract/' . $contract->id . '/oil-recall/sales-confirm', [])->assertSuccessful();

        $decision = $this->decision($contract);
        $key = 'oil_recall_collection:' . $decision->id;

        $this->assertSame(1, $this->alertCount($abuMaroof, $key), 'the parking lane is Abu Maroof’s');
        $this->assertSame(0, $this->alertCount($waleed, $key));
        $this->assertSame(0, $this->alertCount($abdullah, $key));
        // …and NOT everyone who merely holds the permission. A bare-permission broadcast is the
        // failure mode this fleet has already learned to guard against.
        $bystander = $this->user('Holds maintenance.initiate but is not the Inspector', ['maintenance.initiate']);
        $this->assertSame(0, $this->alertCount($bystander, $key));

        $body = $abuMaroof->notifications()->where('data->key', $key)->first()->data['body'];
        $this->assertStringContainsString('in our parking', $body);

        // The driver is told where he is actually driving.
        $task = LogisticsTask::where('vehicle_id', $contract->vehicle_id)->firstOrFail();
        $this->assertSame('Parking', $task->destination);
        $this->assertStringContainsString('bring it to Parking', $task->notes);
    }

    public function test_d2_garage_tells_the_supervisors_and_sends_the_driver_to_the_workshop(): void
    {
        [$waleed, $abdullah, $abuMaroof] = $this->people();

        $contract = $this->rentalWithReading(remainingDays: 10, reading: 7600);
        $this->postJson('/api/Contract/' . $contract->id . '/oil-decision', [
            'decision' => 'recall', 'test_required' => false, 'service_location' => 'garage',
        ])->assertSuccessful();
        $this->postJson('/api/Contract/' . $contract->id . '/oil-recall/sales-confirm', [])->assertSuccessful();

        $decision = $this->decision($contract);
        $key = 'oil_recall_collection:' . $decision->id;

        $this->assertSame(1, $this->alertCount($waleed, $key));
        $this->assertSame(1, $this->alertCount($abdullah, $key));
        $this->assertSame(0, $this->alertCount($abuMaroof, $key), 'a garage job is not Abu Maroof’s to do');

        $task = LogisticsTask::where('vehicle_id', $contract->vehicle_id)->firstOrFail();
        $this->assertSame('Workshop', $task->destination);

        // And the board publishes the routing rather than leaving it to be re-derived.
        $recall = $this->service()->project($contract->fresh())['decision']['recall'];
        $this->assertSame('garage', $recall['service_location']);
        $this->assertSame('supervisor', $recall['owner_role']);
    }

    // ═══════════════════════════════════════════════════════════════════════════════════════════
    //  D3 — THE DRIVER'S OWN QUEUE, AND THE READING HE CANNOT SKIP
    // ═══════════════════════════════════════════════════════════════════════════════════════════

    public function test_d3_the_collection_is_labelled_for_the_drivers_queue_and_demands_the_reading(): void
    {
        $this->people();
        $driver = $this->user('Pool Driver', ['logistics.claim', 'logistics.view']);

        $contract = $this->rentalWithReading(remainingDays: 10, reading: 7600);
        // No test ⇒ no maintenance ticket is linked, which used to make the odometer optional at
        // pick-up. On a collection it is the whole point of the trip, so it is required regardless.
        $this->postJson('/api/Contract/' . $contract->id . '/oil-decision', [
            'decision' => 'recall', 'test_required' => false, 'service_location' => 'parking',
        ])->assertSuccessful();
        $this->postJson('/api/Contract/' . $contract->id . '/oil-recall/sales-confirm', [])->assertSuccessful();

        $task = LogisticsTask::where('vehicle_id', $contract->vehicle_id)->firstOrFail();
        $this->assertNull($task->maintenance_id, 'precondition: no test ⇒ nothing to link to');
        $this->assertTrue($task->isCustomerCollection());
        $this->assertSame(LogisticsTask::PURPOSE_CUSTOMER_COLLECTION, $task->purpose);

        // It reaches the driver's own queue as a collection, under its own flag.
        $this->actingAs($driver);
        $pool = collect($this->getJson('/api/logistics/pool')->assertSuccessful()->json('data.tasks'))
            ->firstWhere('id', $task->id);
        $this->assertNotNull($pool, 'an unclaimed collection must be visible to claim');
        $this->assertTrue($pool['customer_collection']);
        $this->assertSame('Parking', $pool['destination']);

        $this->postJson('/api/logistics/' . $task->id . '/claim')->assertSuccessful();
        $mine = collect($this->getJson('/api/logistics/my-queue')->assertSuccessful()->json('data.tasks'))
            ->firstWhere('id', $task->id);
        $this->assertTrue($mine['customer_collection']);

        // …and he cannot say "I have the car" without the number he was sent to fetch.
        $this->postJson('/api/logistics/' . $task->id . '/pickup', [])->assertStatus(422);
        $this->assertNotSame(LogisticsTask::STATUS_PICKED_UP, $task->fresh()->status);
    }

    /**
     * THE DRIVER'S CARD WALKS: claim → received → arrived → oil changed → gone.
     *
     * The failure this pins down: a one-way move CLOSES the moment it is delivered, so a queue built
     * from "open tasks assigned to me" drops the card one step before its last step — the driver
     * parks the car and the oil change he was sent to make possible vanishes off his screen.
     */
    public function test_d4_the_collection_card_walks_every_step_and_only_then_disappears(): void
    {
        $this->people();
        $driver = $this->user('Pool Driver', ['logistics.claim', 'logistics.view', 'maintenance.logistics']);

        $contract = $this->rentalWithReading(remainingDays: 10, reading: 7600);
        $this->postJson('/api/Contract/' . $contract->id . '/oil-decision', [
            'decision' => 'recall', 'test_required' => false, 'service_location' => 'parking',
        ])->assertSuccessful();
        $this->postJson('/api/Contract/' . $contract->id . '/oil-recall/sales-confirm', [])->assertSuccessful();

        $task = LogisticsTask::where('vehicle_id', $contract->vehicle_id)->firstOrFail();
        $mine = fn () => collect($this->getJson('/api/logistics/my-collections')->assertSuccessful()->json('data.tasks'))
            ->firstWhere('id', $task->id);

        $this->actingAs($driver);

        // 1. Unclaimed — on his card, with the follow-up it exists for.
        $card = $mine();
        $this->assertNotNull($card);
        $this->assertNull($card['assigned_to_id']);
        $this->assertSame($contract->id, $card['oil_followup']['contract_id']);
        $this->assertFalse($card['oil_followup']['oil_changed']);

        // 2. Claimed — still his, car still the customer's.
        $this->postJson('/api/logistics/' . $task->id . '/claim')->assertSuccessful();
        $this->assertSame($driver->id, $mine()['assigned_to_id']);

        // 3. Received, with the reading he was sent for.
        $this->post('/api/logistics/' . $task->id . '/pickup', [
            'odometer'       => 7900,
            'odometer_photo' => \Illuminate\Http\UploadedFile::fake()->image('odo.jpg'),
        ])->assertSuccessful();
        $this->assertSame(LogisticsTask::STATUS_PICKED_UP, $task->fresh()->status);
        $this->assertNotNull($mine(), 'the card must not vanish once he has the car');

        // 4. Arrived — this CLOSES the move, and is exactly where the card used to disappear.
        $this->post('/api/logistics/' . $task->id . '/deliver', [
            'odometer'       => 7950,
            'odometer_photo' => \Illuminate\Http\UploadedFile::fake()->image('odo2.jpg'),
        ])->assertSuccessful();
        $this->assertFalse($task->fresh()->isActive(), 'precondition: a one-way move ends on delivery');

        $card = $mine();
        $this->assertNotNull($card, 'the oil change is still owed — the card stays');
        $this->assertFalse($card['oil_followup']['oil_changed']);

        // 5. The driver records the change himself — a parking job is often his to do.
        $this->postJson('/api/Contract/' . $contract->id . '/oil-change-done', ['odometer' => 20000])
            ->assertSuccessful();

        // 6. …and only NOW is it off his screen, because there is nothing left to do.
        $this->assertNull($mine(), 'a finished collection must not linger in the queue');
        $this->assertSame(20000, (int) Vehicle::findOrFail($contract->vehicle_id)->last_service_odometer);
    }

    // ═══════════════════════════════════════════════════════════════════════════════════════════
    //  E — A CALLER THAT SAYS NOTHING NEVER SILENTLY DROPS THE SYSTEM'S TEST
    // ═══════════════════════════════════════════════════════════════════════════════════════════

    public function test_e_an_unspecified_choice_keeps_the_test_the_system_already_asked_for(): void
    {
        $this->people();
        $contract = $this->rentalWithReading(remainingDays: 10, reading: 7600);
        $existing = $this->systemTestRequest($contract->vehicle_id);

        // No test_required, no service_location — the shape every older caller sends.
        $this->postJson('/api/Contract/' . $contract->id . '/oil-decision', ['decision' => 'recall'])
            ->assertSuccessful();

        $decision = $this->decision($contract);
        // Silence is not "no test": the request is still filed, and because one already existed for
        // this car it is ADOPTED rather than duplicated. `test_required` stays undecided, exactly as
        // it always did — the dispatcher answers that separately.
        $this->assertNotFalse($decision->test_required, 'silence must never read as "no test"');
        $this->assertSame($existing->id, $decision->inspection_ticket_id);
        $this->assertTrue($decision->hasAdoptedRequest());
        $this->assertSame('garage', $decision->serviceLocation(), 'the safe default is the garage lane');
    }

    /** …and with no request open, an unspecified choice files one, exactly as it always did. */
    public function test_e2_with_nothing_pending_the_default_is_the_ordinary_follow_up(): void
    {
        $this->people();
        $contract = $this->rentalWithReading(remainingDays: 10, reading: 7600);

        $this->postJson('/api/Contract/' . $contract->id . '/oil-decision', ['decision' => 'recall'])
            ->assertSuccessful();

        $decision = $this->decision($contract);
        $this->assertNotNull($decision->inspection_ticket_id);
        $this->assertFalse($decision->hasAdoptedRequest());
    }
}
