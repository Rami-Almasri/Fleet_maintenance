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

    /**
     * Re-deciding a car must not quietly re-label somebody else's card as ours.
     *
     * decide() writes a NEW row every time (the history is the point), so "was this request adopted"
     * has to be carried forward from the row that adopted it. Found by walking the flow twice on the
     * same car: the second "Recall now" pointed at the system's request with `request_adopted` back
     * to false — the card would have re-titled itself as an oil follow-up, and the guard that stops
     * the oil lifecycle closing a safety check nobody cancelled would have been resting on nothing.
     */
    public function test_b4_re_deciding_keeps_the_request_marked_as_adopted(): void
    {
        $this->people();
        $contract = $this->rentalWithReading(remainingDays: 10, reading: 7600);
        $existing = $this->systemTestRequest($contract->vehicle_id);

        $this->postJson('/api/Contract/' . $contract->id . '/oil-decision', [
            'decision' => 'recall', 'test_required' => true, 'service_location' => 'garage',
        ])->assertSuccessful();
        $this->assertTrue($this->decision($contract)->hasAdoptedRequest());

        // A second call on the same car — a revision, or simply someone tapping it again.
        $this->postJson('/api/Contract/' . $contract->id . '/oil-decision', [
            'decision' => 'recall', 'test_required' => true, 'service_location' => 'parking',
        ])->assertSuccessful();

        $latest = $this->decision($contract);
        $this->assertSame($existing->id, $latest->inspection_ticket_id);
        $this->assertTrue($latest->hasAdoptedRequest(), 'the card still belongs to the system, not to us');

        // The damage this actually caused on the live fleet: the second decision took the "we raised
        // this" branch and OVERWROTE the system's own reason and trigger_detail with the oil line.
        // The card lost "Why the system flagged this" entirely.
        $fresh = $existing->fresh();
        $this->assertStringContainsString('Routine check overdue', $fresh->customer_complaint,
            'the system\'s own reason must survive a re-decide');
        $this->assertSame('post_downtime_check', $fresh->trigger_detail['source'],
            'and so must the detail that explains why the system flagged the car');
        $this->assertSame(15, $fresh->trigger_detail['idle_days']);
        $this->assertSame($latest->id, $fresh->trigger_detail['oil_projection']['contract_oil_decision_id'],
            'the oil layer sits alongside it, never on top');

        $ctx = \App\Http\Resources\MaintenanceWorkflowResource::make($existing->fresh())->resolve()['oil_context'];
        $this->assertTrue($ctx['recall']['request_adopted'], 'and the card says so');
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

    /**
     * LEEN MAY APPROVE THE TEST WHILE THE CAR IS STILL OUT — and approving it must not pretend the
     * car has arrived.
     *
     * A recalled car's return is being ORGANISED, so the request is not hostage to whether a
     * customer happens to bring it back: the reviewer sends it to Abu Maroof now and the ordinary
     * inspection workflow runs as it always does, with the oil change riding along. The trap that
     * creates: the relay used to read the request first, so an early approval announced "Being
     * inspected" for a car nobody had collected — and hid the Sales OK button that was the actual
     * next action. Paperwork does not move cars.
     */
    public function test_b3_approving_early_sends_it_on_without_faking_the_cars_position(): void
    {
        $this->people();
        $contract = $this->rentalWithReading(remainingDays: 10, reading: 7600);
        $existing = $this->systemTestRequest($contract->vehicle_id);

        $this->postJson('/api/Contract/' . $contract->id . '/oil-decision', [
            'decision' => 'recall', 'test_required' => true, 'service_location' => 'garage',
        ])->assertSuccessful();

        $decision = $this->decision($contract);
        $this->assertSame(ContractOilDecision::STAGE_WAITING_SALES, $decision->recallStage());

        // Approved BEFORE Sales have agreed and before any driver has moved.
        $this->postJson('/api/maintenance-tickets/' . $existing->id . '/review/approve', [])
            ->assertSuccessful();
        $this->assertSame(Maintenance::WF_INSPECTION_REQUESTED, $existing->fresh()->workflow_status,
            'the ordinary workflow runs — the request is now in the Inspector\'s queue');

        // …and the recall still says what is TRUE of the car.
        $decision->refresh();
        $this->assertSame(ContractOilDecision::STAGE_WAITING_SALES, $decision->recallStage(),
            'an office decision must not move a car');
        $this->assertFalse($decision->inOurCustody());
        $this->assertTrue($decision->isAwaitingSalesConfirmation(), 'the Sales OK button must survive');

        // The gate still works from there, and the chain carries on normally.
        $this->postJson('/api/Contract/' . $contract->id . '/oil-recall/sales-confirm', [])->assertSuccessful();
        $this->assertSame(ContractOilDecision::STAGE_READY_FOR_DRIVER, $decision->fresh()->recallStage());
    }

    /**
     * THE INSPECTOR CANNOT FILE THE REPORT WITHOUT THE OIL CHANGE.
     *
     * "Oil Change" sits in the same Routine Maintenance list as Battery, Oil Filter, Air Filter and
     * Coolant — five tick-boxes an inspector runs past in a second. On a recalled car it is not one
     * of them: a customer's rental was interrupted for it. The picker renders it locked-on; this is
     * the same rule where no crafted request, stale tab or future screen can get around it.
     */
    public function test_b5_the_inspector_cannot_file_a_report_that_drops_the_oil_change(): void
    {
        $this->people();
        $contract = $this->rentalWithReading(remainingDays: 10, reading: 7600);
        $existing = $this->systemTestRequest($contract->vehicle_id);

        $this->postJson('/api/Contract/' . $contract->id . '/oil-decision', [
            'decision' => 'recall', 'test_required' => true, 'service_location' => 'garage',
        ])->assertSuccessful();

        // Walk the request to the point the Inspector actually files a report from.
        $wf = app(\App\Services\MaintenanceWorkflowService::class);
        $ticket = $wf->approveInspectionReview(Maintenance::find($existing->id), [], $this->admin);
        $ticket = $wf->startDiagnostic($ticket, ['test_odometer' => 5000], $this->admin);

        // A report that mentions everything EXCEPT the reason the car was recalled.
        $wf->submitReport($ticket, [
            'symptoms'         => ['Battery Replacement', 'Air Filter'],
            'severity'         => 'moderate',
            'fault_severity'   => 'routine',
            'maintenance_type' => Maintenance::TYPE_ROUTINE,
        ], true, $this->admin);

        $findings = collect($ticket->fresh()->findings)->pluck('text')->map(fn ($t) => mb_strtolower($t));
        $this->assertTrue($findings->contains('oil change'), 'the oil change must survive the report');
        $this->assertTrue($findings->contains('battery replacement'), 'and so must what the inspector actually found');

        // Once it HAS been done the requirement is MET, not re-asserted — both the picker and the
        // server-side append read the same flag, so neither puts the same job on the ticket twice.
        $this->postJson('/api/Contract/' . $contract->id . '/oil-recall/sales-confirm', [])->assertSuccessful();
        $this->postJson('/api/Contract/' . $contract->id . '/oil-change-done', ['odometer' => 20000])
            ->assertSuccessful();

        $actions = $this->decision($contract)->requiredActions();
        $this->assertTrue($actions['oil_change']['required'], 'it stays the reason the customer was interrupted');
        $this->assertTrue($actions['oil_change']['done'], 'but it is no longer outstanding');
        $this->assertSame(20000, $actions['oil_change']['odometer']);
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

        $this->assertSame(20000, (int) Vehicle::findOrFail($contract->vehicle_id)->last_service_odometer);

        // 6. The card STAYS — the customer is still paying for a car sitting in our parking, and
        //    handing it back is the one job left on it.
        $card = $mine();
        $this->assertNotNull($card, 'the oil is done, but the car is still ours');
        $this->assertTrue($card['oil_followup']['owes_return']);

        // 7. …and only once the keys are back with the customer is it off his screen.
        $this->postJson('/api/Contract/' . $contract->id . '/oil-returned', [])->assertSuccessful();
        $this->assertNull($mine(), 'a finished collection must not linger in the queue');
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
