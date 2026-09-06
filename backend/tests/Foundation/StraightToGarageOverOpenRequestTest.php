<?php

namespace Tests\Foundation;

use App\Models\FaultCatalog;
use App\Models\Maintenance;
use App\Models\MaintenanceTask;
use App\Models\Vehicle;
use App\Services\MaintenanceWorkflowService;

/**
 * "STRAIGHT TO THE GARAGE" MUST NEVER END IN A TEST DRIVE.
 *
 * THE BUG. A request in flight had exactly two answers — approve (→ the Inspector drives the car) or
 * reject (→ nothing happens). So somebody who opened the Send a Car In form, chose 🔧 STRAIGHT TO THE
 * GARAGE — NO TEST DRIVE, and hit a car that already had a request open was refused, pointed at that
 * request's card, and found only an Approve button on it. Pressing the one forward button on the screen
 * started the very test drive they had just said was unnecessary. Every assertion here exists because
 * that path was silent: nothing crashed, the car simply went the wrong way.
 *
 * THE RULE THIS PINS: choosing the garage lands the car at Needs Dispatch — with routable work on it,
 * and with nothing left in the review queue — from every state an open request can be in, EXCEPT the one
 * where a test drive is already happening.
 */
class StraightToGarageOverOpenRequestTest extends FoundationTestCase
{
    private function car(): Vehicle
    {
        // Only an active-fleet car may enter the workflow (assertActiveFleet).
        return $this->makeVehicle(['status' => 'ready']);
    }

    private function workflow(): MaintenanceWorkflowService
    {
        return app(MaintenanceWorkflowService::class);
    }

    /** An open request awaiting the office's decision. */
    private function openRequest(Vehicle $vehicle, ?FaultCatalog $fault = null): Maintenance
    {
        // Every request has to say why, one way — a named fault here, or the requester's own words.
        $res = $this->postJson('/api/maintenance-tickets/request', array_filter([
            'vehicle_id'         => $vehicle->id,
            'trigger_reason'     => 'test_drive',
            'reported_faults'    => $fault ? [['fault_catalog_id' => $fault->id]] : null,
            'customer_complaint' => $fault ? null : 'Something sounded wrong on the drive back.',
        ]))->assertCreated();

        $ticket = Maintenance::findOrFail($this->idOf($res));
        $this->assertSame(Maintenance::WF_PENDING_REVIEW, $ticket->workflow_status);

        return $ticket;
    }

    /**
     * THE REVIEW GATE'S THIRD ANSWER. The request is converted where it stands: same row, same card,
     * same statement — but it is now a garage ticket and nobody is sent out to drive the car.
     */
    public function test_a_pending_request_can_be_sent_straight_to_the_garage(): void
    {
        $vehicle = $this->car();
        $fault   = FaultCatalog::active()->firstOrFail();
        $request = $this->openRequest($vehicle, $fault);

        $res = $this->postJson("/api/maintenance-tickets/{$request->id}/review/dispatch", [
            'customer_complaint' => 'The parts arrived — it just needs fitting.',
        ])->assertSuccessful();

        // THE SAME ROW. Not a second ticket that leaves the requester's card orphaned behind it.
        $this->assertSame($request->id, $this->idOf($res));

        $ticket = $request->fresh();
        $this->assertSame(Maintenance::WF_INSPECTION_PENDING, $ticket->workflow_status);
        // Parked: the car is not at a garage yet, so it must not read as an open garage event.
        $this->assertSame('IN', $ticket->event_status);
        // Decided, so the review queue stops offering a card nobody still has to decide.
        $this->assertSame($this->admin->id, $ticket->reviewed_by);
        $this->assertNotNull($ticket->reviewed_at);

        // WHAT THE SUPERVISOR WILL DISPATCH. The claim on the request was a suspicion while a test drive
        // was still coming; committing the car to a workshop is what turns it into work.
        $task = $ticket->tasks()->firstOrFail();
        $this->assertSame($fault->name, $task->symptom);
        $this->assertSame(MaintenanceTask::KIND_FAULT, $task->kind);
        $this->assertSame($fault->id, $task->fault_catalog_id);

        // The decider's words are appended to the thread, never written over the reporter's.
        $this->assertStringContainsString('just needs fitting', (string) $ticket->customer_complaint);
    }

    /** It leaves the review queue. A decided request that keeps showing up is the bug from the other end. */
    public function test_it_leaves_the_review_queue(): void
    {
        $request = $this->openRequest($this->car());

        $this->postJson("/api/maintenance-tickets/{$request->id}/review/dispatch", [])->assertSuccessful();

        $ids = collect(data_get($this->getJson('/api/maintenance-tickets/pending-review')->json(), 'data', []))
            ->pluck('id')->all();

        $this->assertNotContains($request->id, $ids);
    }

    /**
     * THE STATE THE OLD FORM DEAD-ENDED ON. The request is already with the Inspector — so a SECOND
     * ticket must not be opened behind his back, and openDirectDispatch() still refuses that. But the
     * DECISION is not refused: it is applied to the request he is holding, and no test drive happens.
     */
    public function test_a_request_the_inspector_holds_goes_to_the_garage_without_a_test_drive(): void
    {
        $vehicle = $this->car();
        $request = $this->openRequest($vehicle);

        $this->postJson("/api/maintenance-tickets/{$request->id}/review/approve", [])->assertSuccessful();
        $this->assertSame(Maintenance::WF_INSPECTION_REQUESTED, $request->fresh()->workflow_status);

        // Opening a second ticket on the same car is still refused — that part was never the bug.
        $refused = $this->postJson('/api/maintenance-tickets/direct-dispatch', [
            'vehicle_id'          => $vehicle->id,
            'request_reason_code' => 'parts_arrived',
        ])->assertStatus(422);
        // …and the refusal names the way through instead of leaving the caller at a wall. It must point
        // at the open request too, or "which one?" has no answer.
        $this->assertTrue((bool) data_get($refused->json(), 'data.send_to_garage'));
        $this->assertSame($request->id, (int) data_get($refused->json(), 'data.ticket_id'));

        // The decision itself goes through, on the request that is already open.
        $this->postJson("/api/maintenance-tickets/{$request->id}/review/dispatch", [
            'request_reason_code' => 'parts_arrived',
        ])->assertSuccessful();

        $ticket = $request->fresh();
        $this->assertSame(Maintenance::WF_INSPECTION_PENDING, $ticket->workflow_status);
        // The car never went for a test drive: it never entered the diagnostic stage.
        $this->assertNull($ticket->test_started_at);
    }

    /**
     * THE ONE STATE THAT IS NOT A TRAP. The Inspector is driving the car this second — the test this
     * door exists to skip is already under way, and his report lands the ticket in this very queue. So
     * the answer is "wait for him", stated as such, and NOT "your decision is unavailable".
     */
    public function test_a_test_drive_already_under_way_is_left_alone(): void
    {
        $vehicle = $this->car();
        $request = $this->openRequest($vehicle);
        $this->postJson("/api/maintenance-tickets/{$request->id}/review/approve", [])->assertSuccessful();

        $this->workflow()->startDiagnostic($request->fresh(), [
            'test_odometer' => (int) $vehicle->odometer + 10,
        ], $this->admin);

        $this->assertSame(Maintenance::WF_INSPECTION_DIAGNOSTIC, $request->fresh()->workflow_status);

        $res = $this->postJson("/api/maintenance-tickets/{$request->id}/review/dispatch", [])->assertStatus(422);
        $this->assertStringContainsString('test-driving', (string) data_get($res->json(), 'message'));

        // And it is left exactly where it was — a refused decision must never half-move a ticket.
        $this->assertSame(Maintenance::WF_INSPECTION_DIAGNOSTIC, $request->fresh()->workflow_status);
    }

    /**
     * THE FORM'S OWN PATH. Somebody opens Send a Car In on a car that already has a request open, picks
     * the garage door and NAMES the work while deciding. That naming is the whole point of the door —
     * it is what the supervisor dispatches — so it must ride along with the decision rather than being
     * dropped on the way, and it must not open a second ticket to carry it.
     */
    public function test_work_named_while_deciding_becomes_work_to_dispatch(): void
    {
        $vehicle = $this->car();
        $request = $this->openRequest($vehicle);          // opened with words only, no named work
        $fault   = FaultCatalog::active()->firstOrFail();

        $this->postJson("/api/maintenance-tickets/{$request->id}/review/dispatch", [
            'reported_faults'    => [['fault_catalog_id' => $fault->id]],
            'customer_complaint' => 'We already know what it is.',
        ])->assertSuccessful();

        $ticket = $request->fresh();
        $this->assertSame(Maintenance::WF_INSPECTION_PENDING, $ticket->workflow_status);

        // Named once, dispatched once.
        $this->assertSame(1, $ticket->tasks()->count());
        $this->assertSame($fault->name, $ticket->tasks()->firstOrFail()->symptom);

        // One ticket for one car — the request became it rather than being left beside it.
        $this->assertSame(1, Maintenance::where('vehicle_id', $vehicle->id)
            ->whereIn('workflow_status', array_merge(
                [Maintenance::WF_PENDING_REVIEW, Maintenance::WF_INSPECTION_REQUESTED],
                [Maintenance::WF_INSPECTION_PENDING]
            ))->count());

        // The decider's sentence is on the card once, not twice.
        $this->assertSame(1, substr_count((string) $ticket->customer_complaint, 'We already know what it is.'));
    }

    /**
     * THE DECISION IS WRITTEN DOWN — who overruled the test, why, and how the car travels.
     *
     * Without this the stage change was the only trace, and "who decided this car did not need to be
     * driven?" had no answer at all. It is exactly the call worth auditing: it trades a day of diagnosis
     * for one person's judgement, and the car comes back for the same fault a fortnight later often
     * enough that somebody will ask.
     */
    public function test_the_decision_records_who_why_and_how_it_travels(): void
    {
        $request = $this->openRequest($this->car());

        $this->postJson("/api/maintenance-tickets/{$request->id}/review/dispatch", [
            'request_reason_code' => 'parts_arrived',
            'transport'           => Maintenance::TRANSPORT_RECOVERY,
        ])->assertSuccessful();

        $ticket = $request->fresh();
        $this->assertNotNull($ticket->sent_to_garage_at);
        $this->assertSame($this->admin->id, $ticket->sent_to_garage_by);
        $this->assertSame('parts_arrived', $ticket->sent_to_garage_reason_code);
        $this->assertSame(Maintenance::TRANSPORT_RECOVERY, $ticket->sent_to_garage_transport);

        // …and it lands on the car's timeline as its OWN event, not buried in "fault report filed",
        // carrying the whole decision so the row can be read a year later without the ticket beside it.
        $event = \App\Models\VehicleLogEvent::where('maintenance_id', $ticket->id)
            ->where('event_type', \App\Models\VehicleLogEvent::EVENT_SENT_STRAIGHT_TO_GARAGE)
            ->latest('id')->first();

        $this->assertNotNull($event, 'the decision must leave a timeline row of its own');
        $this->assertStringContainsString($this->admin->name, (string) $event->description);
        $this->assertStringContainsString('The parts are in', (string) $event->description);
        $this->assertStringContainsString('Recovery truck', (string) $event->description);
        $this->assertSame('parts_arrived', data_get($event->meta, 'reason_code'));
        $this->assertSame(Maintenance::TRANSPORT_RECOVERY, data_get($event->meta, 'transport'));
        $this->assertTrue((bool) data_get($event->meta, 'overruled_test'));
    }

    /** The garage door records the same decision — both roads into the queue answer the same audit. */
    public function test_the_garage_door_records_the_same_decision(): void
    {
        $res = $this->postJson('/api/maintenance-tickets/direct-dispatch', [
            'vehicle_id'          => $this->car()->id,
            'request_reason_code' => 'garage_callback',
            'transport'           => Maintenance::TRANSPORT_DRIVER,
        ])->assertCreated();

        $ticket = Maintenance::findOrFail($this->idOf($res));
        $this->assertNotNull($ticket->sent_to_garage_at);
        $this->assertSame($this->admin->id, $ticket->sent_to_garage_by);
        $this->assertSame('garage_callback', $ticket->sent_to_garage_reason_code);
        $this->assertSame(Maintenance::TRANSPORT_DRIVER, $ticket->sent_to_garage_transport);
    }

    /**
     * PICKING "RECOVERY" ROUTES THE CAR TO A TOW, with or without a truck booked yet.
     *
     * Two facts, and the second is the one that used to be lost. The towing unit, when it is already
     * arranged, is written onto the SAME fields the Recovery dispatch step reads, so that form opens
     * filled in instead of asking a question somebody already answered. And the CHOICE itself makes the
     * ticket a recovery — before this, `is_recovery` keyed only off a booked truck, so a car nobody
     * could drive was shown to the supervisor on the ordinary driver dispatch form and the tow was
     * discovered by a driver standing next to it.
     */
    public function test_choosing_recovery_logs_the_towing_unit(): void
    {
        $request = $this->openRequest($this->car());

        $this->postJson("/api/maintenance-tickets/{$request->id}/review/dispatch", [
            'transport'           => Maintenance::TRANSPORT_RECOVERY,
            'recovery_unit_name'  => 'Recovery Truck #05',
            'recovery_unit_phone' => '0501234567',
        ])->assertSuccessful();

        $ticket = $request->fresh();
        $this->assertSame(Maintenance::TRANSPORT_RECOVERY, $ticket->sent_to_garage_transport);
        $this->assertSame('Recovery Truck #05', $ticket->recovery_unit_name);
        $this->assertSame('0501234567', $ticket->recovery_unit_phone);
        $this->assertTrue($ticket->isRecovery());

        // The truck is named on the timeline row too — that is the line somebody reads six months later
        // while matching a towing company's invoice to the trips it actually made.
        $event = \App\Models\VehicleLogEvent::where('maintenance_id', $ticket->id)
            ->where('event_type', \App\Models\VehicleLogEvent::EVENT_SENT_STRAIGHT_TO_GARAGE)
            ->latest('id')->first();
        $this->assertStringContainsString('Recovery Truck #05', (string) $event->description);
        $this->assertSame('Recovery Truck #05', data_get($event->meta, 'recovery_unit'));
    }

    /** …and the choice alone is enough: a tow with no truck booked yet still routes as a recovery. */
    public function test_recovery_without_a_booked_truck_is_still_a_recovery(): void
    {
        $request = $this->openRequest($this->car());

        $res = $this->postJson("/api/maintenance-tickets/{$request->id}/review/dispatch", [
            'transport' => Maintenance::TRANSPORT_RECOVERY,
        ])->assertSuccessful();

        $ticket = $request->fresh();
        $this->assertNull($ticket->recovery_unit_name);
        $this->assertTrue($ticket->isRecovery(), 'the decision alone must route the car to the tow path');
        // …and the client is told, so the list can show "unit not logged yet" rather than a blank.
        $this->assertTrue((bool) data_get($res->json(), 'data.is_recovery'));
        $this->assertNull(data_get($res->json(), 'data.sent_to_garage.recovery_unit'));
    }

    /** Choosing a driver never leaves a towing unit behind — a ticket cannot claim both. */
    public function test_a_driver_choice_carries_no_towing_unit(): void
    {
        $request = $this->openRequest($this->car());

        $this->postJson("/api/maintenance-tickets/{$request->id}/review/dispatch", [
            'transport'          => Maintenance::TRANSPORT_DRIVER,
            // Sent anyway by a client that forgot to clear its own form — the server drops it.
            'recovery_unit_name' => 'Recovery Truck #05',
        ])->assertSuccessful();

        $ticket = $request->fresh();
        $this->assertSame(Maintenance::TRANSPORT_DRIVER, $ticket->sent_to_garage_transport);
        $this->assertNull($ticket->recovery_unit_name);
        $this->assertFalse($ticket->isRecovery());
    }

    /** The filter behind the tab: every car that skipped a test, from either door, and nothing else. */
    public function test_the_filter_lists_cars_that_skipped_a_test(): void
    {
        // One from each door…
        $converted = $this->openRequest($this->car());
        $this->postJson("/api/maintenance-tickets/{$converted->id}/review/dispatch", [
            'transport' => Maintenance::TRANSPORT_RECOVERY,
        ])->assertSuccessful();

        $direct = Maintenance::findOrFail($this->idOf(
            $this->postJson('/api/maintenance-tickets/direct-dispatch', [
                'vehicle_id'          => $this->car()->id,
                'request_reason_code' => 'parts_arrived',
            ])->assertCreated()
        ));

        // …and one car that went the ordinary way, which must NOT appear: the list is the exception, and
        // a list that includes the rule tells nobody anything.
        $ordinary = $this->openRequest($this->car());
        $this->postJson("/api/maintenance-tickets/{$ordinary->id}/review/approve", [])->assertSuccessful();

        $ids = collect(data_get($this->getJson('/api/maintenance-tickets/sent-to-garage')->json(), 'data', []))
            ->pluck('id')->all();

        $this->assertContains($converted->id, $ids);
        $this->assertContains($direct->id, $ids);
        $this->assertNotContains($ordinary->id, $ids);

        // Filtering by how the car travelled keeps only the ones that travelled that way.
        $towed = collect(data_get(
            $this->getJson('/api/maintenance-tickets/sent-to-garage?transport=recovery')->json(),
            'data',
            []
        ))->pluck('id')->all();

        $this->assertContains($converted->id, $towed);
        $this->assertNotContains($direct->id, $towed);
    }

    /** A request that has already been decided cannot be decided again, from either direction. */
    public function test_a_decided_request_cannot_be_sent_to_the_garage(): void
    {
        $request = $this->openRequest($this->car());

        $this->postJson("/api/maintenance-tickets/{$request->id}/review/dispatch", [])->assertSuccessful();
        $this->postJson("/api/maintenance-tickets/{$request->id}/review/dispatch", [])->assertStatus(422);
        // …and the test-drive door is shut behind it too: the car is committed to a workshop now.
        $this->postJson("/api/maintenance-tickets/{$request->id}/review/approve", [])->assertStatus(422);
    }

    /**
     * The form reads its way through from the server, never from its own guess: the flag that decides
     * whether the garage door converts or waits is computed by the same rule the endpoint enforces.
     */
    public function test_the_form_is_told_the_garage_door_is_open(): void
    {
        $vehicle = $this->car();
        $request = $this->openRequest($vehicle);

        $state = data_get(
            $this->getJson("/api/maintenance-tickets/vehicle/{$vehicle->id}/inspection-request")->json(),
            'data'
        );
        $this->assertTrue((bool) $state['can_send_to_garage']);

        $this->postJson("/api/maintenance-tickets/{$request->id}/review/approve", [])->assertSuccessful();

        $state = data_get(
            $this->getJson("/api/maintenance-tickets/vehicle/{$vehicle->id}/inspection-request")->json(),
            'data'
        );
        // Still open with the Inspector holding it — a second ticket is refused, this decision is not.
        $this->assertFalse((bool) $state['can_supersede']);
        $this->assertTrue((bool) $state['can_send_to_garage']);
    }
}
