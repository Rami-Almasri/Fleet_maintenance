<?php

namespace Tests\Foundation;

use App\Models\FaultCatalog;
use App\Models\Maintenance;
use App\Models\MaintenanceTask;
use App\Models\ServiceCatalog;
use App\Models\Vehicle;

/**
 * THE REQUESTER'S STATEMENT and the second door.
 *
 * Two rules are worth a test because breaking either is silent:
 *
 *   1. EXCLUSIVITY. A request says why exactly ONE way — a named fault, a named service, a reason code,
 *      or a note. If naming a fault AND picking a reason ever started being accepted, nothing would
 *      crash; the ticket would simply carry two contradictory answers and every downstream reader would
 *      pick a different one.
 *   2. PROVENANCE. A named fault must come from the live vocabulary or from THIS car's own history; a
 *      named service must come from the service catalog. A hand-typed name would look identical in the
 *      column and be invisible to every catalog join, recurrence match and count that makes the field
 *      worth having.
 *   3. THE FAULT/SERVICE LINE. Planned work asked for at intake must arrive as kind=service in its own
 *      column, or a working oil schedule starts counting against the car as a failure.
 *
 * Plus the door itself: "straight to the garage" must land at Needs Dispatch with routable faults on
 * it, because a supervisor cannot dispatch an empty ticket.
 */
class SendCarInStatementTest extends FoundationTestCase
{
    private function car(): Vehicle
    {
        // Only an active-fleet car may enter the workflow (assertActiveFleet).
        return $this->makeVehicle(['status' => 'ready']);
    }

    /** A fault picked from the live catalog is stored with its catalog identity, not just its words. */
    public function test_a_catalog_fault_is_stored_with_its_catalog_identity(): void
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

        $this->assertSame(Maintenance::REPORT_MODE_FAULT, $ticket->request_detail_mode);
        $this->assertSame($fault->id, $ticket->reported_faults[0]['fault_catalog_id']);
        $this->assertSame($fault->name, $ticket->reported_faults[0]['text']);
        // The sentence is still written, so every surface that reads customer_complaint is unaffected.
        $this->assertStringContainsString($fault->name, (string) $ticket->customer_complaint);
        // A driver's claim is NOT a diagnosis: no faults are promoted on this door.
        $this->assertSame(0, $ticket->tasks()->count());
        $this->assertSame(Maintenance::WF_PENDING_REVIEW, $ticket->workflow_status);
    }

    /** Naming a fault AND picking a reason is two answers to one question — refused, not reconciled. */
    public function test_a_fault_and_a_reason_together_are_refused(): void
    {
        $vehicle = $this->car();

        $this->postJson('/api/maintenance-tickets/request', [
            'vehicle_id'          => $vehicle->id,
            'trigger_reason'      => 'test_drive',
            'reported_faults'     => [['fault_catalog_id' => FaultCatalog::active()->firstOrFail()->id]],
            'request_reason_code' => 'warning_light',
        ])->assertStatus(422);

        $this->assertSame(0, Maintenance::where('vehicle_id', $vehicle->id)->count());
    }

    /** A hand-typed fault name belongs to no vocabulary — that is what the note mode is for. */
    public function test_an_invented_fault_name_is_refused(): void
    {
        $vehicle = $this->car();

        $this->postJson('/api/maintenance-tickets/request', [
            'vehicle_id'      => $vehicle->id,
            'trigger_reason'  => 'test_drive',
            'reported_faults' => [['text' => 'the whatsit is bent']],
        ])->assertStatus(422);
    }

    /** A reason code is stored as the CODE; `other` records nothing on its own, so it needs the words. */
    public function test_reason_other_requires_the_words_that_explain_it(): void
    {
        $vehicle = $this->car();

        $this->postJson('/api/maintenance-tickets/request', [
            'vehicle_id'          => $vehicle->id,
            'trigger_reason'      => 'test_drive',
            'request_reason_code' => 'other',
        ])->assertStatus(422);

        $res = $this->postJson('/api/maintenance-tickets/request', [
            'vehicle_id'          => $vehicle->id,
            'trigger_reason'      => 'test_drive',
            'request_reason_code' => 'other',
            'customer_complaint'  => 'It smells of burning at the lights.',
        ])->assertCreated();

        $ticket = Maintenance::findOrFail($this->idOf($res));
        $this->assertSame(Maintenance::REPORT_MODE_REASON, $ticket->request_detail_mode);
        $this->assertSame('other', $ticket->request_reason_code);
        $this->assertSame('It smells of burning at the lights.', $ticket->customer_complaint);
    }

    /** The two doors have different reason lists — a dispatch reason is not an inspection reason. */
    public function test_a_dispatch_reason_is_refused_on_the_inspection_door(): void
    {
        $this->postJson('/api/maintenance-tickets/request', [
            'vehicle_id'          => $this->car()->id,
            'trigger_reason'      => 'test_drive',
            'request_reason_code' => 'parts_arrived',
        ])->assertStatus(422);
    }

    /**
     * "Straight to the garage": born at Needs Dispatch, past the review gate AND the test drive, with the
     * named faults already promoted so a supervisor has something to route.
     */
    public function test_direct_dispatch_opens_at_needs_dispatch_with_routable_faults(): void
    {
        $vehicle = $this->car();
        $fault   = FaultCatalog::active()->firstOrFail();

        $res = $this->postJson('/api/maintenance-tickets/direct-dispatch', [
            'vehicle_id'      => $vehicle->id,
            'reported_faults' => [['fault_catalog_id' => $fault->id]],
        ])->assertCreated();

        $ticket = Maintenance::findOrFail($this->idOf($res));

        $this->assertSame(Maintenance::WF_INSPECTION_PENDING, $ticket->workflow_status);
        $this->assertSame(Maintenance::SOURCE_WORKSHOP, $ticket->request_origin);
        // Whoever is logged in is the filer — never a value from the payload.
        $this->assertSame($this->admin->id, $ticket->requested_by);

        $task = $ticket->tasks()->firstOrFail();
        $this->assertSame($fault->name, $task->symptom);
        $this->assertSame(MaintenanceTask::KIND_FAULT, $task->kind);
        $this->assertSame($fault->id, $task->fault_catalog_id);

        // NOT a breakdown: the car is driveable, so it is not grounded and not forced to critical.
        $this->assertNotSame(Maintenance::FAULT_SEVERITY_CRITICAL, $ticket->fault_severity);
        $this->assertNotSame('red', $vehicle->fresh()->condition_grade);
    }

    /** A booked service is a PLANNED visit — it must not read to the foresight engine as a failure. */
    public function test_a_booked_service_is_tagged_routine_not_a_failure(): void
    {
        $res = $this->postJson('/api/maintenance-tickets/direct-dispatch', [
            'vehicle_id'          => $this->car()->id,
            'request_reason_code' => 'scheduled_service',
        ])->assertCreated();

        $ticket = Maintenance::findOrFail($this->idOf($res));
        $this->assertSame(Maintenance::CONTEXT_ROUTINE, $ticket->visit_context);
        $this->assertSame(Maintenance::TRIGGER_PERIODIC, $ticket->trigger_reason);
    }

    /**
     * NAMING THE SERVICE — the same visit as `scheduled_service`, except the ticket now says which job.
     *
     * Two things must hold at once and they pull in opposite directions: the work has to be REAL enough to
     * dispatch (a routable task on the ticket, or the supervisor has nothing to send) and it must never be
     * counted as a FAULT (kind = service, its own column, routine visit context). A regression on either
     * side is silent — one leaves the supervisor an empty ticket, the other quietly charges a working oil
     * schedule to the car's fault record.
     */
    public function test_a_named_service_becomes_routable_work_that_is_never_a_fault(): void
    {
        $vehicle = $this->car();
        $oil     = ServiceCatalog::where('slug', 'oil_change')->firstOrFail();

        $res = $this->postJson('/api/maintenance-tickets/direct-dispatch', [
            'vehicle_id'         => $vehicle->id,
            'requested_services' => [['service_catalog_id' => $oil->id]],
        ])->assertCreated();

        $ticket = Maintenance::findOrFail($this->idOf($res));

        $this->assertSame(Maintenance::REPORT_MODE_SERVICE, $ticket->request_detail_mode);
        $this->assertSame($oil->id, $ticket->requested_services[0]['service_catalog_id']);
        $this->assertSame($oil->name, $ticket->requested_services[0]['text']);
        // The fault column stays empty — this car is not claimed to have failed at anything.
        $this->assertNull($ticket->reported_faults);
        // Named work says the same thing the `scheduled_service` code says, so it is tagged the same way.
        $this->assertSame(Maintenance::CONTEXT_ROUTINE, $ticket->visit_context);
        $this->assertSame(Maintenance::TRIGGER_PERIODIC, $ticket->trigger_reason);
        // The sentence still carries it, for every surface that only reads customer_complaint.
        $this->assertStringContainsString($oil->name, (string) $ticket->customer_complaint);

        // Routable, and classified from the catalog rather than guessed at from its wording.
        $task = $ticket->tasks()->firstOrFail();
        $this->assertSame($oil->name, $task->symptom);
        $this->assertSame(MaintenanceTask::KIND_SERVICE, $task->kind);
        $this->assertSame($oil->id, $task->service_catalog_id);
        $this->assertNull($task->fault_catalog_id);
    }

    /**
     * TWO MENUS, NOT ONE. The form is offered the fault vocabulary and the service vocabulary as separate
     * lists, and neither may leak into the other — the moment "Oil Change" appears among the faults, one
     * tap by a person who was never asked the question puts planned work on the car's fault record.
     */
    public function test_the_form_is_offered_faults_and_services_as_separate_vocabularies(): void
    {
        $options = $this->getJson('/api/maintenance-tickets/request-options')->assertOk()->json('data');

        $faultNames   = collect($options['fault_groups'])->flatMap(fn ($g) => array_column($g['faults'], 'name'));
        $serviceNames = collect($options['service_groups'])->flatMap(fn ($g) => array_column($g['services'], 'name'));

        $this->assertTrue($serviceNames->contains('Oil Change'));
        $this->assertFalse($faultNames->contains('Oil Change'));
        $this->assertEmpty($serviceNames->intersect($faultNames));

        // The cadence rides along as a fact off the catalog row — what the person answers "is it due?" against.
        $oil = collect($options['service_groups'])->flatMap(fn ($g) => $g['services'])->firstWhere('name', 'Oil Change');
        $this->assertSame(10000, $oil['interval_km']);
    }

    /**
     * A service named on the TEST door is a request, not a decision: it is recorded as asked-for and
     * nothing is promoted, because the Controller reviewing the queue is who says the car goes. Somebody
     * with only `maintenance.logistics` can see no other door, so refusing this would leave them no way
     * to say "it's due an oil change" at all.
     */
    public function test_a_named_service_is_recorded_but_not_promoted_on_the_test_door(): void
    {
        $vehicle = $this->car();

        $res = $this->postJson('/api/maintenance-tickets/request', [
            'vehicle_id'         => $vehicle->id,
            'trigger_reason'     => 'test_drive',
            'requested_services' => [['slug' => 'oil_change']],
        ])->assertCreated();

        $ticket = Maintenance::findOrFail($this->idOf($res));

        $this->assertSame(Maintenance::WF_PENDING_REVIEW, $ticket->workflow_status);
        $this->assertSame(Maintenance::REPORT_MODE_SERVICE, $ticket->request_detail_mode);
        $this->assertSame('oil_change', $ticket->requested_services[0]['slug']);
        // A request is a brief, not a diagnosis — nothing is routable until somebody decides.
        $this->assertSame(0, $ticket->tasks()->count());
    }

    /**
     * A fault AND a service together is one answer about two jobs, and both survive in their own columns.
     * The visit is NOT routine — something is reported wrong with the car.
     */
    public function test_a_fault_and_a_service_can_be_named_together_and_stay_apart(): void
    {
        $vehicle = $this->car();
        $fault   = FaultCatalog::active()->firstOrFail();
        $oil     = ServiceCatalog::where('slug', 'oil_change')->firstOrFail();

        $res = $this->postJson('/api/maintenance-tickets/direct-dispatch', [
            'vehicle_id'         => $vehicle->id,
            'reported_faults'    => [['fault_catalog_id' => $fault->id]],
            'requested_services' => [['service_catalog_id' => $oil->id]],
        ])->assertCreated();

        $ticket = Maintenance::findOrFail($this->idOf($res));

        // The fault claim is the stronger statement, so it names the mode.
        $this->assertSame(Maintenance::REPORT_MODE_FAULT, $ticket->request_detail_mode);
        $this->assertSame($fault->name, $ticket->reported_faults[0]['text']);
        $this->assertSame($oil->name, $ticket->requested_services[0]['text']);
        // A visit carrying a reported fault is not a routine one, however much service rides with it.
        $this->assertNotSame(Maintenance::CONTEXT_ROUTINE, $ticket->visit_context);

        // BOTH became routable work, each typed by its own catalog — neither overwrote the other.
        $tasks = $ticket->tasks()->get();
        $this->assertCount(2, $tasks);
        $this->assertSame($fault->id, $tasks->firstWhere('kind', MaintenanceTask::KIND_FAULT)?->fault_catalog_id);
        $this->assertSame($oil->id, $tasks->firstWhere('kind', MaintenanceTask::KIND_SERVICE)?->service_catalog_id);
    }

    /** Named work and a reason code remain two answers to one question — still refused. */
    public function test_a_service_and_a_reason_code_together_are_refused(): void
    {
        $vehicle = $this->car();

        $this->postJson('/api/maintenance-tickets/direct-dispatch', [
            'vehicle_id'          => $vehicle->id,
            'requested_services'  => [['slug' => 'oil_change']],
            'request_reason_code' => 'parts_arrived',
        ])->assertStatus(422);

        $this->assertSame(0, Maintenance::where('vehicle_id', $vehicle->id)->count());
    }

    /** The service vocabulary is the catalog's, not the caller's — an unknown slug is not a service. */
    public function test_an_invented_service_name_is_refused(): void
    {
        $this->postJson('/api/maintenance-tickets/direct-dispatch', [
            'vehicle_id'         => $this->car()->id,
            'requested_services' => [['slug' => 'unicorn_polish']],
        ])->assertStatus(422);
    }

    /**
     * "Is it this again?" offers the car's OWN faults, and picking one records the claim as a link to the
     * ticket it came from — the requester's statement, checkable, rather than a guess to re-derive.
     *
     * FAULTS ONLY: a service repeating is the schedule working, so it is never offered here.
     */
    public function test_this_cars_own_history_is_offered_and_a_repeat_claim_is_recorded(): void
    {
        $vehicle = $this->car();

        // A closed visit that fixed a fault on THIS car.
        $past = Maintenance::create([
            'vehicle_id'      => $vehicle->id,
            'origin'          => Maintenance::ORIGIN_MANUAL,
            'workflow_status' => Maintenance::WF_CLOSED,
            'event_status'    => 'IN',
            'wf_closed_at'    => now()->subDays(20),
        ]);
        MaintenanceTask::create([
            'maintenance_id' => $past->id,
            'vehicle_id'     => $vehicle->id,
            'kind'           => MaintenanceTask::KIND_FAULT,
            'symptom'        => 'AC not cooling',
            'status'         => MaintenanceTask::STATUS_COMPLETED,
            'identified_at'  => now()->subDays(25),
            'resolved_at'    => now()->subDays(20),
        ]);

        $offered = $this->getJson("/api/maintenance-tickets/vehicle/{$vehicle->id}/recent-faults")
            ->assertOk()
            ->json('data.faults');

        $this->assertCount(1, $offered);
        $this->assertSame('AC not cooling', $offered[0]['text']);
        $this->assertTrue($offered[0]['fixed']);
        $this->assertSame($past->id, $offered[0]['ticket_id']);
        // FACT: fixed inside the recurrence window. NOT a claim that it has come back.
        $this->assertTrue($offered[0]['within_recurrence_window']);

        $res = $this->postJson('/api/maintenance-tickets/request', [
            'vehicle_id'      => $vehicle->id,
            'trigger_reason'  => 'test_drive',
            'reported_faults' => [['text' => 'AC not cooling', 'repeat_of_ticket_id' => $past->id]],
        ])->assertCreated();

        $ticket = Maintenance::findOrFail($this->idOf($res));
        $this->assertSame($past->id, $ticket->reported_faults[0]['repeat_of_ticket_id']);
    }

    /** A "same fault as last time" claim may only point at a ticket this car actually had. */
    public function test_a_repeat_claim_pointing_at_another_cars_ticket_is_not_recorded(): void
    {
        $mine  = $this->car();
        $other = $this->car();

        $theirs = Maintenance::create([
            'vehicle_id'      => $other->id,
            'origin'          => Maintenance::ORIGIN_MANUAL,
            'workflow_status' => Maintenance::WF_CLOSED,
            'event_status'    => 'IN',
        ]);

        // The claim is the ONLY thing legitimising this free-text fault name, so with the claim rejected
        // the fault has nothing behind it and the whole request is refused rather than silently softened.
        $this->postJson('/api/maintenance-tickets/request', [
            'vehicle_id'      => $mine->id,
            'trigger_reason'  => 'test_drive',
            'reported_faults' => [['text' => 'AC not cooling', 'repeat_of_ticket_id' => $theirs->id]],
        ])->assertStatus(422);
    }
}
