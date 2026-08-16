<?php

namespace Tests\Foundation;

use App\Models\Contract;
use App\Models\FaultCatalog;
use App\Models\Maintenance;
use App\Models\Vehicle;
use App\Services\DiagnosticGateService;
use App\Services\MaintenanceWorkflowService;

/**
 * A SYSTEM SUGGESTION IS NOT A HUMAN DECISION.
 *
 * The mileage scanner raises inspection requests from odometers and dates. Nobody has been in those cars.
 * For a car sitting in the yard that is fine — a Controller decides the suggestion in the review queue
 * like any other. For a car OUT ON HIRE it is not: the suggestion asks for a test drive the car cannot
 * come in for, and it sat in front of the only person who could answer it — the one who had just driven
 * it — as a refusal ("this car already has an inspection request in progress").
 *
 * So: when a person drives a rented car and commits it to a garage, the scanner's suggestion is stood
 * down and their ticket goes ahead. Everything else is unchanged — a person's request still blocks a
 * second one, a request the Inspector already holds is never yanked out from under him, and a car in the
 * yard is still refused exactly as before.
 *
 * Nothing new was built for the "goes straight to maintenance" half: that door already existed
 * (openDirectDispatch → Needs Dispatch, contract opened, faults promoted), and closing the ticket already
 * restarts the check countdown through the same readyAnchor every other completed visit uses. Both are
 * asserted here so a later change cannot quietly route this case somewhere else.
 */
class RentedCarManualTestTest extends FoundationTestCase
{
    private function car(): Vehicle
    {
        // Only an active-fleet car may enter the workflow (assertActiveFleet).
        return $this->makeVehicle(['status' => 'ready']);
    }

    /** Put the car on hire: an open type-C contract with no in_date is "currently out". */
    private function rent(Vehicle $vehicle): Contract
    {
        return Contract::create([
            'contract_no'   => 'C-'.strtoupper(uniqid()),
            'contract_type' => 'C',
            'state'         => 'open',
            'vehicle_id'    => $vehicle->id,
            'out_date'      => now()->subDays(10)->toDateString(),
            'in_date'       => null,
        ]);
    }

    /** The scanner's suggestion — requested_by null, origin system_schedule, born in the review queue. */
    private function suggestion(Vehicle $vehicle): Maintenance
    {
        return app(MaintenanceWorkflowService::class)->systemRequestInspection($vehicle, [
            'note' => 'Routine service due (mileage).',
        ]);
    }

    private function sendToGarage(Vehicle $vehicle): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/maintenance-tickets/direct-dispatch', [
            'vehicle_id'          => $vehicle->id,
            'request_reason_code' => 'known_fault',
        ]);
    }

    // ── 1. The suggestion stays a suggestion ──────────────────────────────────────────────────────

    /**
     * The scanner flagging a rented car changes nothing about where that car is. It asks a question; it
     * does not commit the car to anything, and nobody is dispatched.
     */
    public function test_a_system_suggestion_on_a_rented_car_stays_a_suggestion(): void
    {
        $vehicle = $this->car();
        $this->rent($vehicle);

        $suggestion = $this->suggestion($vehicle);

        $this->assertSame(Maintenance::WF_PENDING_REVIEW, $suggestion->workflow_status);
        $this->assertNull($suggestion->requested_by, 'the scanner is not a person');
        $this->assertSame(Maintenance::SOURCE_SYSTEM_SCHEDULE, $suggestion->request_origin);
        // It has NOT entered the maintenance cycle: no dispatch state, no contract, no routable faults.
        $this->assertNotContains($suggestion->workflow_status, Maintenance::WF_TICKET_STATES);
        $this->assertSame(0, $suggestion->tasks()->count());
    }

    // ── 2. The human decision goes straight to the cycle ──────────────────────────────────────────

    /**
     * A person who drove the car and sent it in lands at Needs Dispatch — the supervisors' garage queue.
     * No review gate, no inspector approval, and the faults they named are real routable work, because a
     * supervisor cannot dispatch an empty ticket.
     */
    public function test_a_manual_decision_on_a_rented_car_enters_the_maintenance_cycle_directly(): void
    {
        $vehicle = $this->car();
        $this->rent($vehicle);
        $fault = FaultCatalog::active()->firstOrFail();

        $res = $this->postJson('/api/maintenance-tickets/direct-dispatch', [
            'vehicle_id'      => $vehicle->id,
            'reported_faults' => [['fault_catalog_id' => $fault->id]],
        ]);
        $res->assertCreated();

        $ticket = Maintenance::findOrFail($this->idOf($res));

        $this->assertSame(Maintenance::WF_INSPECTION_PENDING, $ticket->workflow_status);
        // It never passed through the review gate, and nobody reviewed it.
        $this->assertNull($ticket->reviewed_by);
        $this->assertNull($ticket->reviewed_at);
        // …and it is a real committed ticket, not a request.
        $this->assertContains($ticket->workflow_status, Maintenance::WF_TICKET_STATES);
        $this->assertGreaterThan(0, $ticket->tasks()->count(), 'named faults are promoted on this door');
    }

    // ── 3. The suggestion is retired by the decision ──────────────────────────────────────────────

    /**
     * THE FIX. The scanner's card is standing in the review queue; a person drives the car and sends it
     * in. Before, that was a 422. Now the card is stood down and the ticket goes ahead — and crucially
     * there is exactly ONE live thing on the car afterwards, not a review request AND a maintenance
     * ticket.
     */
    public function test_a_manual_decision_retires_the_system_suggestion_and_leaves_one_live_workflow(): void
    {
        $vehicle = $this->car();
        $this->rent($vehicle);
        $suggestion = $this->suggestion($vehicle);

        $res = $this->sendToGarage($vehicle);
        $res->assertCreated();
        $ticket = Maintenance::findOrFail($this->idOf($res));

        // The suggestion is retired, by the system, with the system-only code — nobody's name on it.
        $suggestion->refresh();
        $this->assertSame(Maintenance::WF_REVIEW_REJECTED, $suggestion->workflow_status);
        $this->assertSame(Maintenance::REVIEW_REJECT_SUPERSEDED_BY_TEST, $suggestion->review_rejection_code);
        $this->assertTrue(Maintenance::isSystemWithdrawal($suggestion->review_rejection_code));
        $this->assertNull($suggestion->reviewed_by, 'nobody reviewed it — it was withdrawn');
        // The evidence points at the ticket that answered it.
        $this->assertSame('manual_test', $suggestion->review_auto_context['source'] ?? null);
        $this->assertSame($ticket->id, $suggestion->review_auto_context['ticket_id'] ?? null);

        // ONE live workflow on this car: the maintenance ticket, and nothing in flight behind it.
        $this->assertNull(app(MaintenanceWorkflowService::class)->liveInspectionRequest($vehicle->id));
        $this->assertSame(Maintenance::WF_INSPECTION_PENDING, $ticket->workflow_status);
    }

    // ── 3a. The same rule on the OTHER doors ──────────────────────────────────────────────────────

    /**
     * The inspection door, driver voice. A person who has been in the rented car may raise their own
     * request even though the scanner's card is parked on it: their statement replaces the guess, and
     * exactly one live request is left behind — theirs.
     */
    public function test_a_human_request_replaces_the_suggestion_on_the_inspection_door(): void
    {
        $vehicle = $this->car();
        $this->rent($vehicle);
        $suggestion = $this->suggestion($vehicle);

        $res = $this->postJson('/api/maintenance-tickets/request', [
            'vehicle_id'          => $vehicle->id,
            'trigger_reason'      => 'test_drive',
            'request_reason_code' => 'feels_wrong',
        ]);
        $res->assertCreated();
        $mine = Maintenance::findOrFail($this->idOf($res));

        $this->assertSame(
            Maintenance::REVIEW_REJECT_SUPERSEDED_BY_TEST,
            $suggestion->refresh()->review_rejection_code
        );
        // The normal lifecycle is untouched: it is still a request awaiting a Controller, faults unpromoted.
        $this->assertSame(Maintenance::WF_PENDING_REVIEW, $mine->workflow_status);
        // …and it is the ONLY live request on the car.
        $this->assertSame(
            $mine->id,
            app(MaintenanceWorkflowService::class)->liveInspectionRequest($vehicle->id)?->id
        );
    }

    /**
     * The Controller's own door (`/request-inspection`) never had a duplicate guard at all — it would
     * happily have left her staring at her own request AND the scanner's generic card for one car. It
     * retires the suggestion on the same terms.
     */
    public function test_a_controller_request_replaces_the_suggestion_too(): void
    {
        $vehicle = $this->car();
        $this->rent($vehicle);
        $suggestion = $this->suggestion($vehicle);

        $res = $this->postJson('/api/maintenance-tickets/request-inspection', [
            'vehicle_id'     => $vehicle->id,
            'trigger_reason' => 'test_drive',
            'notes'          => 'Drove it this morning — pulls left under braking.',
        ]);
        $res->assertCreated();
        $mine = Maintenance::findOrFail($this->idOf($res));

        $this->assertSame(
            Maintenance::REVIEW_REJECT_SUPERSEDED_BY_TEST,
            $suggestion->refresh()->review_rejection_code
        );
        // Straight to the Inspector, as this door always has — no new stage invented.
        $this->assertSame(Maintenance::WF_INSPECTION_REQUESTED, $mine->workflow_status);
        $this->assertSame($mine->id, app(MaintenanceWorkflowService::class)->liveInspectionRequest($vehicle->id)?->id);
    }

    // ── 3b. …and only that. The duplicate protection is otherwise intact ──────────────────────────

    /**
     * A SECOND REPORT IS ADDED, NOT REFUSED. One request per car is achieved by growing the open one —
     * the second person has driven the car and found something the first report does not mention, and a
     * 422 threw that away to protect a queue count.
     */
    public function test_a_second_report_is_added_to_the_open_request(): void
    {
        $vehicle = $this->car();
        $this->rent($vehicle);
        $first = FaultCatalog::active()->firstOrFail();
        $second = FaultCatalog::active()->where('id', '!=', $first->id)->firstOrFail();

        $res = $this->postJson('/api/maintenance-tickets/request', [
            'vehicle_id'      => $vehicle->id,
            'trigger_reason'  => 'test_drive',
            'reported_faults' => [['fault_catalog_id' => $first->id]],
        ]);
        $res->assertCreated();
        $open = Maintenance::findOrFail($this->idOf($res));

        // Somebody else drives it and finds a DIFFERENT thing.
        $again = $this->postJson('/api/maintenance-tickets/request', [
            'vehicle_id'      => $vehicle->id,
            'trigger_reason'  => 'test_drive',
            'reported_faults' => [['fault_catalog_id' => $second->id]],
        ]);
        $again->assertSuccessful();

        // The SAME request came back — not a second one.
        $this->assertSame($open->id, $this->idOf($again));
        $this->assertSame(1, Maintenance::where('vehicle_id', $vehicle->id)->count());

        $open->refresh();
        // Both faults are on it now.
        $names = array_column($open->reported_faults, 'text');
        $this->assertContains($first->name, $names);
        $this->assertContains($second->name, $names);
        // The first reporter's words survived — the addition is a thread, not a replacement.
        $this->assertStringContainsString($first->name, (string) $open->customer_complaint);
        $this->assertStringContainsString('added', (string) $open->customer_complaint);
        // …and adding detail is not a decision: the stage did not move.
        $this->assertSame(Maintenance::WF_PENDING_REVIEW, $open->workflow_status);
    }

    /** The same fault reported twice is still one fault — an addition must not duplicate rows. */
    public function test_re_reporting_the_same_fault_does_not_duplicate_it(): void
    {
        $vehicle = $this->car();
        $fault   = FaultCatalog::active()->firstOrFail();
        $body    = [
            'vehicle_id'      => $vehicle->id,
            'trigger_reason'  => 'test_drive',
            'reported_faults' => [['fault_catalog_id' => $fault->id]],
        ];

        $res = $this->postJson('/api/maintenance-tickets/request', $body);
        $res->assertCreated();
        $this->postJson('/api/maintenance-tickets/request', $body)->assertSuccessful();

        $open = Maintenance::findOrFail($this->idOf($res));
        $this->assertCount(1, $open->reported_faults);
    }

    /**
     * A ticket outranks a request: sending the car to a garage stands down whatever was awaiting review,
     * including a person's own request. That is the "we already created a ticket" rule — there must not
     * be a card asking someone to approve a test for a car already on its way in.
     */
    public function test_opening_a_ticket_stands_down_a_human_request_too(): void
    {
        $vehicle = $this->car();
        $this->rent($vehicle);

        $res = $this->postJson('/api/maintenance-tickets/request', [
            'vehicle_id'          => $vehicle->id,
            'trigger_reason'      => 'test_drive',
            'request_reason_code' => 'feels_wrong',
        ]);
        $res->assertCreated();
        $request = Maintenance::findOrFail($this->idOf($res));

        $this->sendToGarage($vehicle)->assertCreated();

        $this->assertSame(
            Maintenance::REVIEW_REJECT_SUPERSEDED_BY_TEST,
            $request->refresh()->review_rejection_code
        );
        $this->assertNull(app(MaintenanceWorkflowService::class)->liveInspectionRequest($vehicle->id));
    }

    /**
     * Once a Controller has approved the suggestion the Inspector holds it and is on his way to the car.
     * Standing it down at that point would erase work already assigned, so the refusal stands.
     */
    public function test_an_approved_suggestion_is_not_stood_down(): void
    {
        $vehicle = $this->car();
        $this->rent($vehicle);
        $suggestion = $this->suggestion($vehicle);

        app(MaintenanceWorkflowService::class)
            ->approveInspectionReview($suggestion, [], $this->admin);

        $this->sendToGarage($vehicle)->assertStatus(422);

        $this->assertSame(
            Maintenance::WF_INSPECTION_REQUESTED,
            $suggestion->refresh()->workflow_status,
            'the inspector keeps what he was given'
        );
    }

    // ── 5. Nothing changes for a car that is not on hire ──────────────────────────────────────────

    /**
     * The RENTAL condition scopes the REQUEST door only. On the garage door a ticket outranks a request
     * whether the car is on hire or standing in the yard — the commitment is the same fact either way,
     * and leaving a card asking someone to approve a test for a car already going to a garage is the
     * backlog this whole rule exists to stop.
     */
    public function test_the_garage_door_stands_down_a_suggestion_on_a_yard_car_too(): void
    {
        $vehicle    = $this->car();   // deliberately NOT rented
        $suggestion = $this->suggestion($vehicle);

        $this->sendToGarage($vehicle)->assertCreated();

        $this->assertSame(
            Maintenance::REVIEW_REJECT_SUPERSEDED_BY_TEST,
            $suggestion->refresh()->review_rejection_code
        );
    }

    /**
     * …but the REQUEST door still leaves a yard car's suggestion alone: nobody is committing the car to
     * anything, so the guess and the new report simply merge into one request, exactly as two human
     * reports would.
     */
    public function test_the_request_door_adds_to_a_yard_cars_suggestion(): void
    {
        $vehicle    = $this->car();   // deliberately NOT rented
        $suggestion = $this->suggestion($vehicle);

        $res = $this->postJson('/api/maintenance-tickets/request', [
            'vehicle_id'          => $vehicle->id,
            'trigger_reason'      => 'test_drive',
            'request_reason_code' => 'feels_wrong',
        ]);
        $res->assertSuccessful();

        // Added to the suggestion rather than retiring it — no rental, so nothing outranks anything.
        $this->assertSame($suggestion->id, $this->idOf($res));
        $this->assertSame(Maintenance::WF_PENDING_REVIEW, $suggestion->refresh()->workflow_status);
    }

    /**
     * And the normal inspection lifecycle is unchanged: a person's request on an ordinary car is still
     * born in the review queue with no faults promoted, waiting on a Controller.
     */
    public function test_the_normal_inspection_request_is_unchanged(): void
    {
        $vehicle = $this->car();

        $res = $this->postJson('/api/maintenance-tickets/request', [
            'vehicle_id'          => $vehicle->id,
            'trigger_reason'      => 'test_drive',
            'request_reason_code' => 'feels_wrong',
        ]);
        $res->assertCreated();

        $ticket = Maintenance::findOrFail($this->idOf($res));
        $this->assertSame(Maintenance::WF_PENDING_REVIEW, $ticket->workflow_status);
        $this->assertSame($this->admin->id, $ticket->requested_by);
        $this->assertSame(0, $ticket->tasks()->count(), 'a claim is not a diagnosis');
    }

    // ── 3c. The retired request LEAVES the queue ──────────────────────────────────────────────────

    /**
     * The ticket IS the answer, so there is no card. Once a person has opened a maintenance ticket for
     * the car, leaving a row in /inspection-review would ask a Controller to decide about a car that is
     * already being dealt with on the board — the same reason `condition_cleared` is excluded.
     *
     * The withdrawal itself is untouched and stays on the request for audit; only the QUEUE drops it.
     */
    public function test_the_retired_request_leaves_the_review_queue_entirely(): void
    {
        $vehicle = $this->car();
        $this->rent($vehicle);
        $suggestion = $this->suggestion($vehicle);

        $workflow = app(MaintenanceWorkflowService::class);
        $this->assertTrue(
            $workflow->pendingReview()->contains('id', $suggestion->id),
            'precondition: the suggestion is in the queue before anyone acts'
        );

        $this->sendToGarage($vehicle)->assertCreated();

        $this->assertFalse(
            $workflow->pendingReview()->contains('id', $suggestion->id),
            'a ticket exists for this car — the card would be backlog'
        );

        // Gone from the queue, NOT gone from the record: the decision is still fully auditable.
        $suggestion->refresh();
        $this->assertSame(Maintenance::WF_REVIEW_REJECTED, $suggestion->workflow_status);
        $this->assertSame(Maintenance::REVIEW_REJECT_SUPERSEDED_BY_TEST, $suggestion->review_rejection_code);
        $this->assertNotNull($suggestion->review_auto_context['ticket_id'] ?? null);
    }

    // ── The requester's suspected cause ───────────────────────────────────────────────────────────

    /** The approved short-list for a catalog fault, keyed the way FaultCause keys it. */
    private function causesFor(\App\Models\FaultCatalog $fault)
    {
        return \App\Models\FaultCause::approved()->forSymptom($fault->name)->get();
    }

    /**
     * A person who has a hunch may name it. It is stored beside their fault as a SUSPICION and rendered
     * into the sentence as "thinks it's X" — never as a finding, and it changes nothing about where the
     * request goes: still the review queue, still no promoted tasks, still the Inspector's call.
     */
    public function test_a_requester_may_name_the_cause_they_suspect(): void
    {
        $vehicle = $this->car();
        $fault   = FaultCatalog::active()->firstOrFail();
        $cause   = $this->causesFor($fault)->firstOrFail();

        $res = $this->postJson('/api/maintenance-tickets/request', [
            'vehicle_id'      => $vehicle->id,
            'trigger_reason'  => 'test_drive',
            'reported_faults' => [['fault_catalog_id' => $fault->id, 'root_cause_id' => $cause->id]],
        ]);
        $res->assertCreated();

        $ticket = Maintenance::findOrFail($this->idOf($res));
        $this->assertSame($cause->root_cause, $ticket->reported_faults[0]['suspected_cause']);
        $this->assertSame($cause->id, $ticket->reported_faults[0]['suspected_cause_id']);
        // Whose guess it is survives the trip into prose.
        $this->assertStringContainsString('thinks it', (string) $ticket->customer_complaint);
        $this->assertStringContainsString($cause->root_cause, (string) $ticket->customer_complaint);
        // A suspicion is not a diagnosis: nothing was promoted and the Inspector still decides.
        $this->assertSame(Maintenance::WF_PENDING_REVIEW, $ticket->workflow_status);
        $this->assertSame(0, $ticket->tasks()->count());
    }

    /**
     * A cause belonging to a DIFFERENT fault is not a suspicion, it is a mismatch — dropped rather than
     * stored, exactly as an unprovable fault name is refused. The fault itself survives; only the
     * unsupported half of the claim goes.
     */
    public function test_a_cause_from_another_fault_is_not_stored(): void
    {
        $vehicle = $this->car();
        $fault   = FaultCatalog::active()->firstOrFail();
        // A cause that belongs to some OTHER symptom.
        $alien = \App\Models\FaultCause::approved()
            ->where('symptom_key', '!=', \App\Models\FaultCause::normalizeKey($fault->name))
            ->firstOrFail();

        $res = $this->postJson('/api/maintenance-tickets/request', [
            'vehicle_id'      => $vehicle->id,
            'trigger_reason'  => 'test_drive',
            'reported_faults' => [['fault_catalog_id' => $fault->id, 'root_cause_id' => $alien->id]],
        ]);
        $res->assertCreated();

        $ticket = Maintenance::findOrFail($this->idOf($res));
        $this->assertSame($fault->name, $ticket->reported_faults[0]['text']);
        $this->assertNull($ticket->reported_faults[0]['suspected_cause']);
        $this->assertNull($ticket->reported_faults[0]['suspected_cause_id']);
    }

    /**
     * On the straight-to-garage door there is no inspector coming, and the person holds diagnostic
     * authority — so the same pick is written onto the FINDING, which is where the Diagnosis step writes
     * a cause. Same tap, more authority behind it.
     */
    public function test_on_the_garage_door_the_pick_lands_on_the_finding(): void
    {
        $vehicle = $this->car();
        $fault   = FaultCatalog::active()->firstOrFail();
        $cause   = $this->causesFor($fault)->firstOrFail();

        $res = $this->postJson('/api/maintenance-tickets/direct-dispatch', [
            'vehicle_id'      => $vehicle->id,
            'reported_faults' => [['fault_catalog_id' => $fault->id, 'root_cause_id' => $cause->id]],
        ]);
        $res->assertCreated();

        $ticket = Maintenance::findOrFail($this->idOf($res));
        $this->assertSame($cause->root_cause, $ticket->findings[0]['root_cause']);
        $this->assertSame($cause->id, $ticket->findings[0]['root_cause_id']);
    }

    /** Saying nothing stays the normal case and must look like nothing — not an empty guess. */
    public function test_naming_no_cause_leaves_the_fields_null(): void
    {
        $vehicle = $this->car();
        $fault   = FaultCatalog::active()->firstOrFail();

        $res = $this->postJson('/api/maintenance-tickets/request', [
            'vehicle_id'      => $vehicle->id,
            'trigger_reason'  => 'test_drive',
            'reported_faults' => [['fault_catalog_id' => $fault->id]],
        ]);
        $res->assertCreated();

        $ticket = Maintenance::findOrFail($this->idOf($res));
        $this->assertNull($ticket->reported_faults[0]['suspected_cause']);
        $this->assertStringNotContainsString('thinks it', (string) $ticket->customer_complaint);
    }

    // ── Review the request for a rented car ───────────────────────────────────────────────────────

    /**
     * A rented car's request is REVIEWABLE. Being with a customer is a caution on the card, not a lock on
     * the decision — the reviewer approves and the request goes to the Inspector exactly as any other
     * does, waiting in his queue until the car is back.
     *
     * The server never enforced a rental hold here (approveInspectionReview has no such check); the hold
     * was a disabled button on the card. This pins the behaviour the button now matches, so a future
     * change to either side has to face the other.
     */
    public function test_a_rented_cars_request_can_be_approved(): void
    {
        $vehicle = $this->car();
        $this->rent($vehicle);

        $res = $this->postJson('/api/maintenance-tickets/request', [
            'vehicle_id'          => $vehicle->id,
            'trigger_reason'      => 'test_drive',
            'request_reason_code' => 'feels_wrong',
        ]);
        $res->assertCreated();
        $ticket = Maintenance::findOrFail($this->idOf($res));

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/review/approve", [])->assertSuccessful();

        $ticket->refresh();
        $this->assertSame(Maintenance::WF_INSPECTION_REQUESTED, $ticket->workflow_status);
        $this->assertSame($this->admin->id, $ticket->reviewed_by);
        // Still on hire — approving did not pretend otherwise, it just stopped waiting on it.
        $this->assertNotNull(app(MaintenanceWorkflowService::class)->openRentalFor($vehicle->id));
    }

    /**
     * WHAT THE INSPECTOR IS TOLD. The named fault and the requester's hunch travel in
     * `customer_complaint`, which is the field the approval alert quotes and the field his screen reads —
     * so "Knocking over bumps (thinks it's Failing strut mount)" reaches him as words, not as a JSON
     * column he would have to go looking for.
     */
    public function test_the_named_fault_and_hunch_reach_the_inspector(): void
    {
        $vehicle = $this->car();
        $this->rent($vehicle);
        $fault   = FaultCatalog::active()->firstOrFail();
        $cause   = $this->causesFor($fault)->firstOrFail();

        $res = $this->postJson('/api/maintenance-tickets/request', [
            'vehicle_id'      => $vehicle->id,
            'trigger_reason'  => 'test_drive',
            'reported_faults' => [['fault_catalog_id' => $fault->id, 'root_cause_id' => $cause->id]],
        ]);
        $res->assertCreated();
        $ticket = Maintenance::findOrFail($this->idOf($res));

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/review/approve", [])->assertSuccessful();

        // The statement survives the review gate intact — approving must not rewrite what was reported.
        $complaint = (string) $ticket->refresh()->customer_complaint;
        $this->assertStringContainsString($fault->name, $complaint);
        $this->assertStringContainsString($cause->root_cause, $complaint);
        $this->assertSame($fault->name, $ticket->reported_faults[0]['text']);
        $this->assertSame($cause->root_cause, $ticket->reported_faults[0]['suspected_cause']);
    }

    // ── 4. Completion feeds the SAME counters ─────────────────────────────────────────────────────

    /**
     * There is no separate counter for a manually-sent car. `workflowReadyAnchor` selects on
     * `workflow_status`, never on how the ticket was born, so a direct-dispatch ticket that closes
     * restarts the check countdown exactly as any other completed visit does.
     *
     * Asserted through the public readyAnchor() so this is the number the Inspection Review card, the
     * countdown tab and the withdrawal sweeps all read — not a private detail.
     */
    public function test_closing_a_manually_sent_ticket_restarts_the_same_countdown(): void
    {
        // Bought long ago and never in the shop since, so the anchor starts on the onboarding fallback and
        // a restart is visible as a DATE change — readyAnchor reports whole days, so a car created today
        // would close its ticket on the same day it was onboarded and the move would be invisible.
        $vehicle = $this->makeVehicle([
            'status'        => 'ready',
            'purchase_date' => now()->subDays(200)->toDateString(),
        ]);
        $this->rent($vehicle);
        $gate = app(DiagnosticGateService::class);

        $before = $gate->readyAnchor($vehicle);
        $this->assertSame('onboarding', $before['source']);
        $this->assertSame(200, $before['days_ago']);

        $res = $this->sendToGarage($vehicle);
        $res->assertCreated();
        $ticket = Maintenance::findOrFail($this->idOf($res));

        // Close it the way the lifecycle does — the state + stamp close() writes, which is the only
        // thing the anchor reads. (Driving the full dispatch→garage→return chain here would be testing
        // the whole workflow, not the counter.)
        $ticket->workflow_status = Maintenance::WF_CLOSED;
        $ticket->wf_closed_at    = now();
        $ticket->save();

        $after = $gate->readyAnchor($vehicle->refresh());

        $this->assertNotSame($before['at'], $after['at'], 'the countdown restarted');
        $this->assertSame('maintenance', $after['reason']);
        $this->assertSame('workflow', $after['source']);
        $this->assertSame($ticket->id, $after['source_id']);
        $this->assertSame(0, $after['days_ago']);
    }
}
