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
 * THE RECALL RELAY — a car brought back mid-rental for oil, end to end.
 *
 * The rule this suite exists to hold: **a paying customer's car is not collected until somebody has
 * agreed it with them.** A recall decision starts a conversation, not a movement. Only when a person
 * records "Sales OK — customer confirmed" does anything reach a driver.
 *
 * After that the relay runs on records that already exist — the LogisticsTask carries the driver and
 * the custody, the follow-up request carries the inspection, the service ticket carries the oil
 * change — so the tests below assert against those, not against a new state machine.
 *
 * The second rule: the oil change is not removable. It is the reason the customer was interrupted,
 * so it is derived from the recall rather than stored, and no request body can switch it off.
 *
 * Fixture arithmetic, kept identical to OilChangeProjectionTest so the numbers read the same:
 *   last service 0 km · 7,000 km interval · 500 km grace ⇒ limit 7,000, allowed max 7,500
 */
class OilRecallSalesGateTest extends CrudTestCase
{
    private const INTERVAL    = 7000;
    private const OIL_LIMIT   = 7000;
    private const ALLOWED_MAX = 7500;

    private function service(): OilChangeProjectionService
    {
        return app(OilChangeProjectionService::class);
    }

    private function user(string $name, array $permissions = [], ?string $role = null): User
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
        if ($role) {
            $user->assignRole($role);
        }

        return $user;
    }

    /** The Supervisors who arrange the driver — the real people, named on the allow-list. */
    private function supervisors(): array
    {
        $waleed   = $this->user('Waleed', ['maintenance.delegate']);
        $abdullah = $this->user('Abdullah', ['maintenance.delegate']);
        config(['maintenance.checkpoint.default_user_ids' => [$waleed->id, $abdullah->id]]);

        return [$waleed, $abdullah];
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

    private function recall(Contract $contract): ContractOilDecision
    {
        $this->postJson('/api/Contract/' . $contract->id . '/oil-decision', ['decision' => 'recall'])
            ->assertSuccessful();

        return ContractOilDecision::where('contract_id', $contract->id)->orderByDesc('id')->firstOrFail();
    }

    private function stage(Contract $contract): ?string
    {
        return ContractOilDecision::where('contract_id', $contract->id)
            ->orderByDesc('id')->first()?->recallStage();
    }

    private function alertCount(User $user, string $key): int
    {
        return $user->notifications()->where('data->key', $key)->count();
    }

    // ═══════════════════════════════════════════════════════════════════════════════════════════
    //  A — RECALL NOW STOPS AT THE GATE
    // ═══════════════════════════════════════════════════════════════════════════════════════════

    public function test_a_recall_waits_for_sales_and_tells_no_driver(): void
    {
        [$waleed, $abdullah] = $this->supervisors();
        $driver = $this->user('Pool Driver', ['logistics.claim']);

        $contract = $this->rentalWithReading(remainingDays: 10, reading: 7600);
        $decision = $this->recall($contract);

        $this->assertSame(ContractOilDecision::STAGE_WAITING_SALES, $decision->recallStage());
        $this->assertTrue($decision->isAwaitingSalesConfirmation());
        $this->assertNull($decision->sales_confirmed_at);

        // Nothing moved, and nobody who moves cars was told anything.
        $this->assertSame(0, LogisticsTask::where('vehicle_id', $contract->vehicle_id)->count());
        $this->assertSame(0, $driver->notifications()->count());
        $this->assertSame(0, $this->alertCount($waleed, 'oil_recall_collection:' . $decision->id));
        $this->assertSame(0, $this->alertCount($abdullah, 'oil_recall_collection:' . $decision->id));

        // The board says so, in the payload the page actually reads.
        $recall = $this->service()->project($contract->fresh())['decision']['recall'];
        $this->assertSame(ContractOilDecision::STAGE_WAITING_SALES, $recall['stage']);
        $this->assertSame('sales', $recall['owner']);
        $this->assertTrue($recall['awaiting_sales']);
        $this->assertNull($recall['collection']);
        $this->assertFalse($recall['in_our_custody']);
    }

    // ═══════════════════════════════════════════════════════════════════════════════════════════
    //  B — SALES OK RELEASES EVERYTHING, ONCE
    // ═══════════════════════════════════════════════════════════════════════════════════════════

    public function test_b_sales_ok_audits_notifies_the_supervisors_and_opens_the_collection(): void
    {
        [$waleed, $abdullah] = $this->supervisors();
        $bystander = $this->user('Holds the permission but is not a supervisor', ['maintenance.delegate']);

        $contract = $this->rentalWithReading(remainingDays: 10, reading: 7600);
        $decision = $this->recall($contract);

        $this->postJson('/api/Contract/' . $contract->id . '/oil-recall/sales-confirm', [
            'note' => 'Customer agreed — Thursday morning.',
        ])->assertSuccessful();

        $decision->refresh();

        // 1. Recorded, with a real authenticated actor.
        $this->assertNotNull($decision->sales_confirmed_at);
        $this->assertSame($this->admin->id, $decision->sales_confirmed_by);
        $this->assertSame($this->admin->name, $decision->sales_confirmed_by_name);
        $this->assertSame('Customer agreed — Thursday morning.', $decision->sales_note);

        // 2. In the audit trail, against the ONE follow-up request.
        $event = VehicleLogEvent::where('maintenance_id', $decision->inspection_ticket_id)
            ->where('event_type', VehicleLogEvent::EVENT_OIL_RECALL_SALES_CONFIRMED)
            ->firstOrFail();
        $this->assertSame($this->admin->id, $event->actor_id);
        $this->assertSame($contract->vehicle_id, $event->vehicle_id);
        $this->assertSame(ContractOilDecision::STAGE_WAITING_SALES, $event->meta['from_stage']);
        $this->assertSame($decision->id, $event->meta['oil_decision_id']);

        // 3. Waleed and Abdullah are told; a bare permission holder is not.
        $key = 'oil_recall_collection:' . $decision->id;
        $this->assertSame(1, $this->alertCount($waleed, $key));
        $this->assertSame(1, $this->alertCount($abdullah, $key));
        $this->assertSame(0, $this->alertCount($bystander, $key));

        $body = $waleed->notifications()->where('data->key', $key)->first()->data['body'];
        $this->assertStringContainsString('confirmed by Sales', $body);
        $this->assertStringContainsString('arrange a driver', $body);
        $this->assertStringContainsString('oil change (required)', $body);

        // 4. The collection exists — pooled, nobody auto-assigned, linked to the same request.
        $task = LogisticsTask::where('vehicle_id', $contract->vehicle_id)->firstOrFail();
        $this->assertSame(LogisticsTask::STATUS_DISPATCHED, $task->status);
        $this->assertNull($task->assigned_to_id, 'no driver is auto-assigned — the Supervisors choose');
        $this->assertSame($decision->inspection_ticket_id, $task->maintenance_id);
        $this->assertSame($task->id, $decision->collection_task_id);
        $this->assertSame(ContractOilDecision::STAGE_READY_FOR_DRIVER, $decision->recallStage());

        // 5. The recall call was moved along rather than left ringing.
        $this->assertSame(
            OilRecallTask::STATUS_CONTACTED,
            OilRecallTask::where('contract_id', $contract->id)->value('status'),
        );
    }

    /** J — a second Sales OK is a no-op: no second collection, no second alert, no second event. */
    public function test_j_confirming_twice_changes_nothing(): void
    {
        [$waleed] = $this->supervisors();
        $contract = $this->rentalWithReading(remainingDays: 10, reading: 7600);
        $decision = $this->recall($contract);

        $this->postJson('/api/Contract/' . $contract->id . '/oil-recall/sales-confirm', [])->assertSuccessful();
        $firstStamp = $decision->fresh()->sales_confirmed_at;

        $this->postJson('/api/Contract/' . $contract->id . '/oil-recall/sales-confirm', [
            'note' => 'clicked again',
        ])->assertSuccessful();

        $this->assertSame(1, LogisticsTask::where('vehicle_id', $contract->vehicle_id)->count());
        $this->assertSame(1, $this->alertCount($waleed, 'oil_recall_collection:' . $decision->id));
        $this->assertSame(1, VehicleLogEvent::where('maintenance_id', $decision->inspection_ticket_id)
            ->where('event_type', VehicleLogEvent::EVENT_OIL_RECALL_SALES_CONFIRMED)->count());
        $this->assertSame(1, Maintenance::where('vehicle_id', $contract->vehicle_id)->count());
        $this->assertEquals($firstStamp, $decision->fresh()->sales_confirmed_at, 'the confirmation is written once');
        $this->assertNull($decision->fresh()->sales_note, 'a repeat click cannot rewrite what Sales said');
    }

    public function test_sales_ok_is_refused_when_there_is_no_open_recall(): void
    {
        $this->supervisors();
        $contract = $this->rentalWithReading(remainingDays: 10, reading: 7600);

        // Never decided at all.
        $this->postJson('/api/Contract/' . $contract->id . '/oil-recall/sales-confirm', [])
            ->assertStatus(422);

        // Decided the other way — a defer has nothing to collect.
        $this->postJson('/api/Contract/' . $contract->id . '/oil-decision', ['decision' => 'defer'])
            ->assertSuccessful();
        $this->postJson('/api/Contract/' . $contract->id . '/oil-recall/sales-confirm', [])
            ->assertStatus(422);
        $this->assertSame(0, LogisticsTask::where('vehicle_id', $contract->vehicle_id)->count());
    }

    // ═══════════════════════════════════════════════════════════════════════════════════════════
    //  C/D — DRIVER ASSIGNED, THEN CUSTODY
    // ═══════════════════════════════════════════════════════════════════════════════════════════

    public function test_cd_the_driver_claims_collects_and_the_car_stops_being_the_customers(): void
    {
        $this->supervisors();
        $driver = $this->user('Collecting Driver', ['logistics.claim', 'logistics.view']);

        $contract = $this->rentalWithReading(remainingDays: 10, reading: 7600);
        $decision = $this->recall($contract);
        $this->postJson('/api/Contract/' . $contract->id . '/oil-recall/sales-confirm', [])->assertSuccessful();

        $task = LogisticsTask::where('vehicle_id', $contract->vehicle_id)->firstOrFail();

        // The driver claims it through the EXISTING logistics flow — not a parallel driver system.
        $this->actingAs($driver);
        $this->postJson('/api/logistics/' . $task->id . '/claim')->assertSuccessful();
        $this->assertSame(ContractOilDecision::STAGE_DRIVER_ASSIGNED, $this->stage($contract));
        $this->assertFalse($decision->fresh()->inOurCustody(), 'claimed ≠ collected — the customer still has the car');

        // "Vehicle received from customer" — the explicit acknowledgement, i.e. the pick-up step.
        // A maintenance-linked move demands the odometer AND its photo here, which is exactly the
        // real reading this recall was raised to get.
        $this->post('/api/logistics/' . $task->id . '/pickup', [
            'odometer'       => 7900,
            'odometer_photo' => UploadedFile::fake()->image('odometer.jpg'),
        ])->assertSuccessful();

        $this->assertSame(LogisticsTask::STATUS_PICKED_UP, $task->fresh()->status);
        $this->assertSame(ContractOilDecision::STAGE_VEHICLE_COLLECTED, $this->stage($contract));
        $this->assertTrue($decision->fresh()->inOurCustody(), 'once collected, the car is ours');

        // And the inspection-review side stops saying "wait for the customer".
        $ctx = \App\Http\Resources\MaintenanceWorkflowResource::make(
            Maintenance::find($decision->inspection_ticket_id)
        )->resolve()['oil_context'];
        $this->assertTrue($ctx['in_our_custody']);
        $this->assertSame('vehicle_collected', $ctx['current_action']);
        $this->assertSame(ContractOilDecision::STAGE_VEHICLE_COLLECTED, $ctx['recall']['stage']);
        $this->assertSame($driver->name, $ctx['recall']['collection']['driver']);
    }

    // ═══════════════════════════════════════════════════════════════════════════════════════════
    //  E/F — TEST IS A CHOICE, OIL CHANGE IS NOT
    // ═══════════════════════════════════════════════════════════════════════════════════════════

    public function test_ef_the_test_is_optional_and_the_oil_change_cannot_be_removed(): void
    {
        $this->supervisors();
        $contract = $this->rentalWithReading(remainingDays: 10, reading: 7600);
        $decision = $this->recall($contract);
        $this->postJson('/api/Contract/' . $contract->id . '/oil-recall/sales-confirm', [])->assertSuccessful();

        // Oil change is required from the very first moment, before anyone instructs anything.
        $actions = $decision->fresh()->requiredActions();
        $this->assertTrue($actions['oil_change']['required']);
        $this->assertTrue($actions['oil_change']['locked']);
        $this->assertFalse($actions['test']['decided'], 'nobody has said yet whether a test is wanted');

        // The dispatcher asks for a test too.
        $this->postJson('/api/Contract/' . $contract->id . '/oil-recall/instructions', ['test_required' => true])
            ->assertSuccessful();

        $decision->refresh();
        $this->assertTrue($decision->test_required);
        $this->assertTrue($decision->requiredActions()['test']['required']);

        // The driver's own brief carries both, with the oil change stated as required.
        $notes = (string) LogisticsTask::where('vehicle_id', $contract->vehicle_id)->value('notes');
        $this->assertStringContainsString('Inspection/test + OIL CHANGE (required)', $notes);

        // F — dropping the test is allowed. Dropping the oil change is not expressible: the endpoint
        // takes `test_required` and nothing else, so these attempts are rejected or simply ignored.
        $this->postJson('/api/Contract/' . $contract->id . '/oil-recall/instructions', [
            'test_required'       => false,
            'oil_change_required' => false,   // not a field — must not reach anything
        ])->assertSuccessful();

        $decision->refresh();
        $this->assertFalse($decision->test_required, 'the test came off');
        $this->assertTrue($decision->requiredActions()['oil_change']['required'], 'the oil change did not');
        $this->assertStringContainsString(
            'OIL CHANGE (required)',
            (string) LogisticsTask::where('vehicle_id', $contract->vehicle_id)->value('notes'),
        );

        // A malformed instruction changes nothing at all.
        $this->postJson('/api/Contract/' . $contract->id . '/oil-recall/instructions', [])
            ->assertStatus(422);

        // The audit trail carries what was instructed, each time.
        $this->assertSame(2, VehicleLogEvent::where('maintenance_id', $decision->inspection_ticket_id)
            ->where('event_type', VehicleLogEvent::EVENT_OIL_RECALL_INSTRUCTED)->count());
    }

    // ═══════════════════════════════════════════════════════════════════════════════════════════
    //  G/H/I — ARRIVAL, ACTUAL MILEAGE, AND THE OIL CHANGE THAT SURVIVES ALL OF IT
    // ═══════════════════════════════════════════════════════════════════════════════════════════

    public function test_ghi_arrival_shows_live_figures_and_still_owes_the_oil_change(): void
    {
        $this->supervisors();
        $contract = $this->rentalWithReading(remainingDays: 10, reading: 7600);   // projected 9,600 → 2,100 over
        $decision = $this->recall($contract);
        $this->postJson('/api/Contract/' . $contract->id . '/oil-recall/sales-confirm', [])->assertSuccessful();
        $this->postJson('/api/Contract/' . $contract->id . '/oil-recall/instructions', ['test_required' => true])
            ->assertSuccessful();

        $request = Maintenance::find($decision->inspection_ticket_id);
        $snapshotOver = $request->trigger_detail['figures_at_decision']['over_allowance'];
        $this->assertSame(2100, $snapshotOver);

        // The car arrives early, on a real reading well short of the projection.
        $this->postJson('/api/Contract/' . $contract->id . '/mileage-reading', [
            'odometer'    => 7700,
            'reported_by' => 'Driver at collection',
        ])->assertSuccessful();

        $ctx = \App\Http\Resources\MaintenanceWorkflowResource::make($request->fresh())->resolve()['oil_context'];

        // G/H — the LIVE figures moved; the decision snapshot did not.
        $this->assertSame(7700, $ctx['live']['anchor_odometer']);
        $this->assertSame(self::ALLOWED_MAX, $ctx['live']['allowed_max']);
        $this->assertSame(2100, $ctx['at_decision']['over_allowance'], 'the original decision is preserved');
        $this->assertNotSame($ctx['at_decision']['over_allowance'], $ctx['live']['over_allowance_km']);

        // I — and the requirement the recall was raised for is still on the card, still locked.
        $this->assertTrue($ctx['recall']['required_actions']['oil_change']['required']);
        $this->assertTrue($ctx['recall']['required_actions']['oil_change']['locked']);
        $this->assertTrue($ctx['recall']['required_actions']['test']['required']);

        // The rental closes on the actual mileage → the oil change becomes a real ticket, and the
        // relay reads "oil service", then "completed".
        $contract->forceFill(['state' => 'closed', 'in_date' => now()->toDateString(), 'in_milage' => 7700])->save();
        $serviceTicket = $this->service()->settleOnReturn($contract->fresh());

        $this->assertNotNull($serviceTicket, 'past the 7,000 km limit — the oil change is owed');
        $this->assertSame(ContractOilDecision::STAGE_OIL_SERVICE, $this->stage($contract));

        $serviceTicket->forceFill(['workflow_status' => Maintenance::WF_CLOSED])->save();
        $this->assertSame(ContractOilDecision::STAGE_COMPLETED, $this->stage($contract));

        // Nothing is left running in anyone's queue.
        $this->assertSame(0, LogisticsTask::open()->where('vehicle_id', $contract->vehicle_id)->count());
        $this->assertSame(0, OilRecallTask::open()->where('contract_id', $contract->id)->count());
    }

    // ═══════════════════════════════════════════════════════════════════════════════════════════
    //  K/L — CHANGING THE ANSWER, BEFORE AND AFTER SALES OK
    // ═══════════════════════════════════════════════════════════════════════════════════════════

    /** K — recall → defer BEFORE Sales OK: nothing was raised, so nothing is stranded. */
    public function test_k_switching_to_defer_before_sales_ok_leaves_nothing_behind(): void
    {
        [$waleed] = $this->supervisors();
        $contract = $this->rentalWithReading(remainingDays: 10, reading: 7600);
        $decision = $this->recall($contract);

        $this->postJson('/api/Contract/' . $contract->id . '/oil-decision', ['decision' => 'defer'])
            ->assertSuccessful();

        $this->assertSame(0, LogisticsTask::where('vehicle_id', $contract->vehicle_id)->count());
        $this->assertSame(0, OilRecallTask::open()->where('contract_id', $contract->id)->count());
        $this->assertSame(0, $this->alertCount($waleed, 'oil_recall_collection:' . $decision->id));
        $this->assertNull($this->stage($contract), 'a defer runs no relay');
        // Still ONE request throughout.
        $this->assertSame(1, Maintenance::where('vehicle_id', $contract->vehicle_id)->count());
    }

    /** L — recall → defer AFTER Sales OK: the live collection is stood down, not orphaned. */
    public function test_l_switching_to_defer_after_sales_ok_cancels_the_collection(): void
    {
        $this->supervisors();
        $contract = $this->rentalWithReading(remainingDays: 10, reading: 7600);
        $this->recall($contract);
        $this->postJson('/api/Contract/' . $contract->id . '/oil-recall/sales-confirm', [])->assertSuccessful();

        $task = LogisticsTask::where('vehicle_id', $contract->vehicle_id)->firstOrFail();
        $this->assertTrue($task->isActive());

        $this->postJson('/api/Contract/' . $contract->id . '/oil-decision', ['decision' => 'defer'])
            ->assertSuccessful();

        $this->assertSame(LogisticsTask::STATUS_CANCELLED, $task->fresh()->status);
        $this->assertSame(0, LogisticsTask::open()->where('vehicle_id', $contract->vehicle_id)->count());
        $this->assertSame(0, OilRecallTask::open()->where('contract_id', $contract->id)->count());
        $this->assertSame(1, LogisticsTask::where('vehicle_id', $contract->vehicle_id)->count(), 'no second move was invented');
        $this->assertSame(1, Maintenance::where('vehicle_id', $contract->vehicle_id)->count());

        // Re-escalating starts the relay again from the gate — the cancelled move is not resurrected.
        $this->recall($contract);
        $this->assertSame(ContractOilDecision::STAGE_WAITING_SALES, $this->stage($contract));
        $this->assertSame(1, LogisticsTask::where('vehicle_id', $contract->vehicle_id)->count());
    }
}
