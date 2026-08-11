<?php

namespace Tests\Crud;

use App\Models\Contract;
use App\Models\ContractOilDecision;
use App\Models\LogisticsTask;
use App\Models\Maintenance;
use App\Models\OilRecallTask;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleLogEvent;
use App\Services\OilChangeProjectionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;

/**
 * THE OIL WAS CHANGED — the one write that ends an oil follow-up.
 *
 * Everything the recall relay does is arrangement: a projection predicts, a Controller decides,
 * Sales agree, a driver collects. None of it changes a kilometre of the car's oil life. This does.
 * The workshop reads the dash after the change, enters that number, and from it:
 *
 *   • the car's schedule moves      — next change = this reading + the car's interval;
 *   • the board re-anchors          — the projection recomputes from a fact, not from 200 km/day;
 *   • the follow-up ends            — recall call, driver collection and the announcing inspection
 *                                     request all stand down, and the review card flips to
 *                                     "oil service completed".
 *
 * Fixture arithmetic, deliberately identical to the sibling oil suites so the numbers read the same:
 *   last service 0 km · 7,000 km interval · 500 km grace ⇒ limit 7,000, allowed max 7,500
 * After a change recorded at 20,000 km:            ⇒ limit 27,000, allowed max 27,500
 */
class OilChangeRecordedTest extends CrudTestCase
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

    private function supervisors(): void
    {
        $waleed   = $this->user('Waleed', ['maintenance.delegate']);
        $abdullah = $this->user('Abdullah', ['maintenance.delegate']);
        config(['maintenance.checkpoint.default_user_ids' => [$waleed->id, $abdullah->id]]);
    }

    /** A car whose oil limit is 7,000 km, out on rental with a fresh customer reading. */
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

    /** Recall → Sales OK → a driver actually collects it. The car is ours from here on. */
    private function collectedRecall(int $reading = 7600): array
    {
        $this->supervisors();
        $driver = $this->user('Collecting Driver', ['logistics.claim', 'logistics.view']);

        $contract = $this->rentalWithReading(remainingDays: 10, reading: $reading);
        $this->postJson('/api/Contract/' . $contract->id . '/oil-decision', ['decision' => 'recall'])
            ->assertSuccessful();
        $this->postJson('/api/Contract/' . $contract->id . '/oil-recall/sales-confirm', [])->assertSuccessful();

        $task = LogisticsTask::where('vehicle_id', $contract->vehicle_id)->firstOrFail();
        $this->actingAs($driver);
        $this->postJson('/api/logistics/' . $task->id . '/claim')->assertSuccessful();
        $this->post('/api/logistics/' . $task->id . '/pickup', [
            'odometer'       => $reading + 300,
            'odometer_photo' => UploadedFile::fake()->image('odometer.jpg'),
        ])->assertSuccessful();

        // Back to the office actor — recording the change is not the driver's write.
        $this->actingAs($this->admin);

        $decision = ContractOilDecision::where('contract_id', $contract->id)->orderByDesc('id')->firstOrFail();
        $this->assertTrue($decision->inOurCustody(), 'precondition: the car must be with us');

        return [$contract->fresh(), $decision];
    }

    private function oilContext(ContractOilDecision $decision): array
    {
        return \App\Http\Resources\MaintenanceWorkflowResource::make(
            Maintenance::find($decision->inspection_ticket_id)
        )->resolve()['oil_context'];
    }

    // ═══════════════════════════════════════════════════════════════════════════════════════════
    //  A — ONE NUMBER MOVES THE CAR'S OWN SCHEDULE
    // ═══════════════════════════════════════════════════════════════════════════════════════════

    public function test_a_recording_the_change_moves_the_cars_next_service_and_keeps_the_grace(): void
    {
        [$contract, $decision] = $this->collectedRecall();

        $res = $this->postJson('/api/Contract/' . $contract->id . '/oil-change-done', [
            'odometer' => 20000,
            'note'     => 'Changed at Al Quoz.',
        ])->assertSuccessful();

        // 1. The car's profile — the whole point. Next change = the reading + this car's interval.
        $vehicle = Vehicle::findOrFail($contract->vehicle_id);
        $this->assertSame(20000, (int) $vehicle->last_service_odometer);
        $this->assertSame(27000, $this->service()->oilLimit($vehicle), 'next service = 20,000 + 7,000');

        // 2. The 500 km grace belongs to the BOARD, not to the service record — it is added again
        //    on top of the new limit, never folded into it.
        $this->assertSame(27500, $this->service()->threshold($vehicle));
        $this->assertSame(27000, (int) $res->json('data.vehicle.next_service_odometer'));
        $this->assertSame(27500, (int) $res->json('data.vehicle.allowed_max'));

        // 3. The recurring oil reminder moved with it — one anchor, no second schedule.
        $reminder = $vehicle->serviceReminders()->where('service_type', 'oil_change')->first();
        $this->assertNotNull($reminder, 'the oil ServiceReminder must be rolled forward, not left behind');
        $this->assertSame(20000, (int) $reminder->last_service_odometer);

        // 4. Stamped as a fact, with a real actor.
        $decision->refresh();
        $this->assertTrue($decision->isOilChanged());
        $this->assertSame(20000, $decision->oil_changed_odometer);
        $this->assertSame($this->admin->name, $decision->oil_changed_by_name);
        $this->assertSame('Changed at Al Quoz.', $decision->oil_change_note);
        $this->assertNotNull($decision->settled_at, 'a completed change settles the follow-up');
        $this->assertNull($decision->settled_ticket_id, 'the change is DONE — it does not owe a ticket');
    }

    // ═══════════════════════════════════════════════════════════════════════════════════════════
    //  B — THE BOARD STOPS ASKING, BY ARITHMETIC
    // ═══════════════════════════════════════════════════════════════════════════════════════════

    public function test_b_the_follow_up_board_goes_quiet_on_the_new_number(): void
    {
        [$contract] = $this->collectedRecall();

        $before = $this->service()->project($contract);
        $this->assertSame(OilChangeProjectionService::OIL_RECALL_REQUIRED, $before['oil_status']);

        $this->postJson('/api/Contract/' . $contract->id . '/oil-change-done', ['odometer' => 20000])
            ->assertSuccessful();

        $after = $this->service()->project($contract->fresh());

        // The reading is now the anchor — a fact, not a projection — and the car sits inside a
        // brand-new interval, so it drops out of the queue because the numbers say so.
        $this->assertSame(20000, $after['anchor_odometer']);
        $this->assertSame(OilChangeProjectionService::ANCHOR_READING, $after['anchor_source']);
        $this->assertSame(27000, $after['oil_limit']);
        $this->assertSame(27500, $after['allowed_max']);
        $this->assertSame(OilChangeProjectionService::OIL_WITHIN_TOLERANCE, $after['oil_status']);
        $this->assertSame(OilChangeProjectionService::LANE_SAFE, $after['lane']);
    }

    /**
     * …and it does NOT come straight back as a fresh decision.
     *
     * Found by walking the flow end to end on a 60-day rental: the oil was recorded, the new limit
     * was set — and the board immediately asked "recall it now, or do it on return?" about the same
     * car, because a 60-day rental at 200 km/day cannot fit inside a 7,000 km interval either. True,
     * and useless: nothing anyone decides today gets acted on for a month. The fact stays published;
     * the card goes quiet until the car is actually near the oil point.
     */
    public function test_b2_a_just_serviced_car_is_not_asked_about_again_the_same_day(): void
    {
        // A long rental — long enough that even a fresh interval cannot cover it.
        $contract = $this->rentalWithReading(remainingDays: 60, reading: 7600);
        $this->postJson('/api/Contract/' . $contract->id . '/oil-decision', ['decision' => 'recall'])
            ->assertSuccessful();

        $this->postJson('/api/Contract/' . $contract->id . '/oil-change-done', ['odometer' => 20000])
            ->assertSuccessful();

        $p = $this->service()->project($contract->fresh());

        // The arithmetic is still reported honestly…
        $this->assertSame(27000, $p['oil_limit']);
        $this->assertSame(OilChangeProjectionService::OIL_DECISION_REQUIRED, $p['oil_status'],
            'it genuinely will need another change before this rental ends — that fact is not hidden');

        // …but nobody is asked about it today.
        $this->assertFalse($p['decision_due_soon'], 'the car is a whole interval away from its oil point');
        $this->assertFalse($p['decision_ready']);
        $this->assertSame(OilChangeProjectionService::LANE_SAFE, $p['lane'],
            'a car serviced this morning must not be back in Action required this afternoon');
    }

    // ═══════════════════════════════════════════════════════════════════════════════════════════
    //  C — EVERYTHING THAT WAS ONLY ASKING FOR THIS CHANGE STANDS DOWN
    // ═══════════════════════════════════════════════════════════════════════════════════════════

    public function test_c_the_recall_the_collection_and_the_inspection_request_all_close(): void
    {
        [$contract, $decision] = $this->collectedRecall();

        $this->postJson('/api/Contract/' . $contract->id . '/oil-change-done', ['odometer' => 20000])
            ->assertSuccessful();

        // The call to the customer is finished — the thing it was arranging has happened.
        $this->assertSame(0, OilRecallTask::open()->where('contract_id', $contract->id)->count());

        // The announcing inspection request no longer waits in the review queue.
        $request = Maintenance::find($decision->inspection_ticket_id);
        $this->assertSame(Maintenance::WF_CLOSED, $request->workflow_status);
        $this->assertStringContainsString('20,000 km', (string) $request->review_notes);

        // The relay has reached its last step — the work is done, the car is not back yet. (Handing
        // it over is what reads "completed"; see test_c3.)
        $this->assertSame(ContractOilDecision::STAGE_RETURN_TO_CUSTOMER, $decision->fresh()->recallStage());
        $ctx = $this->oilContext($decision);
        $this->assertSame('oil_service_completed', $ctx['current_action']);
        $this->assertSame(20000, $ctx['oil_changed']['odometer']);
        $this->assertSame(27000, $ctx['oil_changed']['next_due']);
        $this->assertTrue($ctx['recall']['required_actions']['oil_change']['done']);
        $this->assertTrue($ctx['recall']['required_actions']['oil_change']['required'],
            'the requirement is MET, never deleted — it is why the customer was interrupted');

        // And it is in the car's audit trail as workshop work, with what it did to the schedule.
        $event = VehicleLogEvent::where('maintenance_id', $decision->inspection_ticket_id)
            ->where('event_type', VehicleLogEvent::EVENT_OIL_CHANGE_RECORDED)
            ->firstOrFail();
        $this->assertSame(20000, $event->meta['odometer']);
        $this->assertSame(27000, $event->meta['next_due_odometer']);
    }

    /**
     * …unless the recall also asked for a TEST. The oil being done does not do the test, so the
     * request keeps its place in the review queue instead of being closed out from under it.
     */
    public function test_c2_a_recall_that_also_wants_a_test_keeps_its_request_open(): void
    {
        [$contract, $decision] = $this->collectedRecall();

        $this->postJson('/api/Contract/' . $contract->id . '/oil-recall/instructions', ['test_required' => true])
            ->assertSuccessful();
        $this->postJson('/api/Contract/' . $contract->id . '/oil-change-done', ['odometer' => 20000])
            ->assertSuccessful();

        $request = Maintenance::find($decision->inspection_ticket_id);
        $this->assertContains($request->workflow_status, Maintenance::WF_PRE_TICKET,
            'the test is still owed — the request must stay in the queue');
        $this->assertStringContainsString('test is still owed', (string) $request->review_notes);

        // …and it now reads as an oil change that has been DONE, not one still required.
        $ctx = $this->oilContext($decision);
        $this->assertSame('oil_service_completed', $ctx['current_action']);
        $this->assertTrue($ctx['recall']['required_actions']['test']['required']);
    }

    // ═══════════════════════════════════════════════════════════════════════════════════════════
    //  C3 — THE RECALL ENDS WHEN THE CUSTOMER HAS THE CAR BACK, NOT WHEN THE OIL IS CHANGED
    // ═══════════════════════════════════════════════════════════════════════════════════════════

    /**
     * The blind spot this closes: the moment the oil is recorded, every signal says "finished" —
     * ticket closed, workshop moved on, board green — while the car stands in our yard on a rental
     * the customer is still paying for. Nothing else in the system notices.
     */
    public function test_c3_a_car_still_in_our_yard_after_the_change_is_chased_until_it_goes_back(): void
    {
        [$contract, $decision] = $this->collectedRecall();

        $this->postJson('/api/Contract/' . $contract->id . '/oil-change-done', ['odometer' => 20000])
            ->assertSuccessful();

        // The rental is still open, so the recall is NOT over.
        $decision->refresh();
        $this->assertTrue($decision->owesReturnToCustomer());
        $this->assertSame(ContractOilDecision::STAGE_RETURN_TO_CUSTOMER, $decision->recallStage());
        $this->assertTrue($this->service()->recallState($decision)['owes_return']);

        // The chase rings the people who can act, and does not ring them twice inside its window.
        $first = $this->service()->chaseCustomerReturns();
        $this->assertSame(1, $first['chased']);
        $this->assertGreaterThan(0, $first['notified'], 'somebody must actually be told');

        $again = $this->service()->chaseCustomerReturns();
        $this->assertSame(0, $again['chased'], 'a second sweep inside the window must stay silent');

        // …and it keeps ringing once the window has passed.
        $decision->forceFill(['return_reminder_at' => now()->subMinutes(OilChangeProjectionService::RETURN_CHASE_MINUTES + 1)])->save();
        $this->assertSame(1, $this->service()->chaseCustomerReturns()['chased']);

        // Handing the car back is what ends it — and stops the chase.
        $this->postJson('/api/Contract/' . $contract->id . '/oil-returned', [])->assertSuccessful();

        $decision->refresh();
        $this->assertNotNull($decision->returned_to_customer_at);
        $this->assertSame($this->admin->name, $decision->returned_to_customer_by_name);
        $this->assertFalse($decision->owesReturnToCustomer());
        $this->assertSame(ContractOilDecision::STAGE_COMPLETED, $decision->recallStage());
        $this->assertSame(0, $this->service()->chaseCustomerReturns()['chased']);
    }

    // ═══════════════════════════════════════════════════════════════════════════════════════════
    //  D — ONE OIL CHANGE, ONE ANCHOR
    // ═══════════════════════════════════════════════════════════════════════════════════════════

    public function test_d_recording_it_twice_changes_nothing_the_second_time(): void
    {
        [$contract, $decision] = $this->collectedRecall();

        $this->postJson('/api/Contract/' . $contract->id . '/oil-change-done', ['odometer' => 20000])
            ->assertSuccessful();
        $firstStamp = $decision->fresh()->oil_changed_at;

        // A second tap with a DIFFERENT number must not hand the car a fresh interval it never had.
        $this->postJson('/api/Contract/' . $contract->id . '/oil-change-done', ['odometer' => 25000])
            ->assertSuccessful();

        $decision->refresh();
        $this->assertSame(20000, $decision->oil_changed_odometer);
        $this->assertEquals($firstStamp, $decision->oil_changed_at);
        $this->assertSame(20000, (int) Vehicle::findOrFail($contract->vehicle_id)->last_service_odometer);
    }

    // ═══════════════════════════════════════════════════════════════════════════════════════════
    //  E — A NUMBER THAT CANNOT BE TRUE IS REFUSED
    // ═══════════════════════════════════════════════════════════════════════════════════════════

    public function test_e_a_reading_below_what_we_already_know_is_rejected(): void
    {
        [$contract] = $this->collectedRecall(reading: 7600);

        // Below the last known odometer: a typo here would become the car's service anchor and
        // silently park its next interval.
        $this->postJson('/api/Contract/' . $contract->id . '/oil-change-done', ['odometer' => 700])
            ->assertStatus(422);

        $this->assertSame(0, (int) Vehicle::findOrFail($contract->vehicle_id)->last_service_odometer,
            'a refused reading must leave the car\'s service anchor exactly where it was');
    }

    // ═══════════════════════════════════════════════════════════════════════════════════════════
    //  F — NOTHING TO RECORD AGAINST
    // ═══════════════════════════════════════════════════════════════════════════════════════════

    public function test_f_a_rental_with_no_oil_decision_is_refused(): void
    {
        $contract = $this->rentalWithReading(remainingDays: 10, reading: 6000);

        $this->postJson('/api/Contract/' . $contract->id . '/oil-change-done', ['odometer' => 20000])
            ->assertStatus(422);

        $this->assertSame(0, (int) Vehicle::findOrFail($contract->vehicle_id)->last_service_odometer);
    }
}
