<?php

namespace Tests\Crud;

use App\Models\Contract;
use App\Models\Maintenance;
use App\Models\MaintenanceHandover;
use App\Models\Vehicle;
use Illuminate\Http\UploadedFile;

/**
 * Pause Maintenance & Return to Service — an OPERATIONAL PAUSE on a live workflow ticket. Pulling a
 * mid-repair car out for a customer must PAUSE the ticket (preserving every bit of its state) and free
 * the car, then Resume must continue from the EXACT stage it paused at — nothing closed, nothing lost,
 * nothing restarted.
 *
 * Enterprise Handover Workflow: both legs now require a full custody handover (odometer + photo, fuel,
 * exterior/interior condition, damage findings, missing accessories, notes, signature) — see
 * MaintenanceWorkflowService::pauseForRental()/resumeMaintenance() and HandoverComparisonService.
 */
class PauseResumeMaintenanceTest extends CrudTestCase
{
    /** A committed, in-progress ticket at $status with the car held in maintenance. */
    private function ticketAt(string $status, array $extra = []): Maintenance
    {
        $vehicleId = $this->makeVehicle();
        $vehicle   = Vehicle::find($vehicleId);
        $vehicle->update(['operational_status' => 'maintenance']);

        $ticket = Maintenance::create(array_merge([
            'vehicle_id'      => $vehicle->id,
            'vendor_id'       => $this->makeVendor(['name' => 'Ajman Sticar Shop']),
            'origin'          => Maintenance::ORIGIN_MANUAL,
            'workflow_status' => $status,
            'event_status'    => in_array($status, Maintenance::WF_PHYSICALLY_OUT_STATES, true) ? 'OUT' : 'IN',
            'out_date'        => now()->toDateString(),
            'findings'        => [['text' => 'Brake noise', 'source' => 'inspector']],
            'cost'            => 350,
        ], $extra));

        return $ticket->fresh();
    }

    /** A minimal, valid pause-leg handover payload (vehicle's makeVehicle() default odometer is 40000). */
    private function pausePayload(int $odometer = 40010, array $overrides = []): array
    {
        return array_merge([
            'reason'              => 'Customer needs this exact car today.',
            'pause_odometer'      => $odometer,
            'odometer_photo'      => UploadedFile::fake()->image('pause-odo.jpg'),
            'fuel_level'          => '1/2',
            'exterior_condition'  => 'Good',
            'interior_condition'  => 'Good',
            'damage_findings'     => [],
            'missing_accessories' => [],
            'notes'               => null,
            'signature'           => UploadedFile::fake()->image('pause-sig.png'),
        ], $overrides);
    }

    /** A minimal, valid resume-leg handover payload. */
    private function resumePayload(int $odometer = 40050, array $overrides = []): array
    {
        return array_merge([
            'resume_odometer'     => $odometer,
            'odometer_photo'      => UploadedFile::fake()->image('resume-odo.jpg'),
            'fuel_level'          => '1/2',
            'exterior_condition'  => 'Good',
            'interior_condition'  => 'Good',
            'damage_findings'     => [],
            'missing_accessories' => [],
            'notes'               => null,
            'signature'           => UploadedFile::fake()->image('resume-sig.png'),
        ], $overrides);
    }

    public function test_pause_preserves_the_ticket_and_frees_the_car(): void
    {
        $ticket  = $this->ticketAt(Maintenance::WF_UNDER_REPAIR);
        $vendorId = $ticket->vendor_id;

        $res = $this->postJson("/api/maintenance-tickets/{$ticket->id}/pause", $this->pausePayload());
        $res->assertSuccessful();

        $ticket->refresh();
        // Paused — not closed/cancelled. The stage it will resume at is remembered.
        $this->assertSame(Maintenance::WF_PAUSED_RETURNED_TO_SERVICE, $ticket->workflow_status);
        $this->assertSame(Maintenance::WF_UNDER_REPAIR, $ticket->paused_from_status);
        $this->assertNotNull($ticket->paused_at);
        $this->assertSame('Customer needs this exact car today.', $ticket->paused_reason);
        // Parked so the car reads free, but NOT terminal — the ticket stays open.
        $this->assertSame('IN', $ticket->event_status);
        // Every bit of state is preserved in place.
        $this->assertSame($vendorId, $ticket->vendor_id);
        $this->assertEquals(350, (int) $ticket->cost);
        $this->assertNotEmpty($ticket->findings);
        // The car is released back into service (no open contract, ticket no longer counts as maintenance).
        $vehicle = $ticket->vehicle->fresh();
        $this->assertSame('available', $vehicle->operational_status);
        $this->assertTrue((bool) $vehicle->is_deferred_maintenance);
    }

    public function test_resume_continues_from_the_exact_stage(): void
    {
        $ticket = $this->ticketAt(Maintenance::WF_UNDER_REPAIR);
        $this->postJson("/api/maintenance-tickets/{$ticket->id}/pause", $this->pausePayload())->assertSuccessful();

        $res = $this->postJson("/api/maintenance-tickets/{$ticket->id}/resume", $this->resumePayload());
        $res->assertSuccessful();

        $ticket->refresh();
        // Continues from EXACTLY where it paused — nothing restarts.
        $this->assertSame(Maintenance::WF_UNDER_REPAIR, $ticket->workflow_status);
        $this->assertSame('OUT', $ticket->event_status); // under_repair is a physically-out stage
        $this->assertNull($ticket->paused_from_status);
        // paused_at / paused_reason are DELIBERATELY KEPT as the historical stamp of the most recent pause.
        $this->assertNotNull($ticket->paused_at);
        // The car is back in the workshop and the "owes maintenance" flag is cleared.
        $vehicle = $ticket->vehicle->fresh();
        $this->assertSame('maintenance', $vehicle->operational_status);
        $this->assertFalse((bool) $vehicle->is_deferred_maintenance);
    }

    public function test_a_parked_stage_resumes_to_IN_not_OUT(): void
    {
        // inspection_pending: the car was still at our base (event IN) when paused — resume keeps it IN.
        $ticket = $this->ticketAt(Maintenance::WF_INSPECTION_PENDING);
        $this->postJson("/api/maintenance-tickets/{$ticket->id}/pause", $this->pausePayload())->assertSuccessful();
        $this->postJson("/api/maintenance-tickets/{$ticket->id}/resume", $this->resumePayload())->assertSuccessful();

        $ticket->refresh();
        $this->assertSame(Maintenance::WF_INSPECTION_PENDING, $ticket->workflow_status);
        $this->assertSame('IN', $ticket->event_status);
    }

    public function test_repeated_pause_resume_cycles_are_stable(): void
    {
        $ticket = $this->ticketAt(Maintenance::WF_READY_FOR_PICKUP);
        $odometer = 40010;

        for ($i = 0; $i < 3; $i++) {
            $this->postJson("/api/maintenance-tickets/{$ticket->id}/pause", $this->pausePayload($odometer))->assertSuccessful();
            $this->assertSame(Maintenance::WF_PAUSED_RETURNED_TO_SERVICE, $ticket->fresh()->workflow_status);
            $this->assertSame(Maintenance::WF_READY_FOR_PICKUP, $ticket->fresh()->paused_from_status);
            $odometer += 10;

            $this->postJson("/api/maintenance-tickets/{$ticket->id}/resume", $this->resumePayload($odometer))->assertSuccessful();
            $this->assertSame(Maintenance::WF_READY_FOR_PICKUP, $ticket->fresh()->workflow_status);
            $this->assertNull($ticket->fresh()->paused_from_status);
            $odometer += 10;
        }
    }

    public function test_double_pause_is_idempotent(): void
    {
        $ticket = $this->ticketAt(Maintenance::WF_UNDER_REPAIR);
        $this->postJson("/api/maintenance-tickets/{$ticket->id}/pause", $this->pausePayload(40010, ['reason' => 'first']))->assertSuccessful();
        // A retry / double-click no-ops instead of re-pausing or erroring.
        $this->postJson("/api/maintenance-tickets/{$ticket->id}/pause", $this->pausePayload(40020, ['reason' => 'second']))->assertSuccessful();

        $ticket->refresh();
        $this->assertSame(Maintenance::WF_PAUSED_RETURNED_TO_SERVICE, $ticket->workflow_status);
        $this->assertSame(Maintenance::WF_UNDER_REPAIR, $ticket->paused_from_status);
        $this->assertSame('first', $ticket->paused_reason); // the first pause stands
    }

    public function test_cannot_pause_a_non_pausable_ticket(): void
    {
        $ticket = $this->ticketAt(Maintenance::WF_UNDER_REPAIR, ['workflow_status' => Maintenance::WF_CLOSED, 'event_status' => 'IN']);

        $res = $this->postJson("/api/maintenance-tickets/{$ticket->id}/pause", $this->pausePayload());
        $res->assertStatus(422);
        $this->assertSame(Maintenance::WF_CLOSED, $ticket->fresh()->workflow_status);
    }

    public function test_cannot_resume_a_ticket_that_is_not_paused(): void
    {
        $ticket = $this->ticketAt(Maintenance::WF_UNDER_REPAIR);

        $res = $this->postJson("/api/maintenance-tickets/{$ticket->id}/resume", $this->resumePayload());
        $res->assertStatus(422);
        $this->assertSame(Maintenance::WF_UNDER_REPAIR, $ticket->fresh()->workflow_status);
    }

    public function test_renting_an_in_shop_car_pauses_the_ticket_instead_of_closing_it(): void
    {
        $ticket    = $this->ticketAt(Maintenance::WF_UNDER_REPAIR);
        $vehicleId = $ticket->vehicle_id;
        $customer  = $this->makeCustomer();

        // Create a rental straight on the in-shop car, deliberately pulling it out for a customer. This
        // is the AUTOMATED rental-pull call site (OperationsService::pauseWorkflowTicketForRental()) —
        // no human is present, so no handover payload is supplied (see pauseForRental()'s $handoverData
        // being nullable/optional and additive).
        $res = $this->postJson('/api/Contract', [
            'contract_no'           => 'C-' . strtoupper(uniqid()),
            'contract_type'         => 'C',
            'state'                 => 'open',
            'vehicle_id'            => $vehicleId,
            'customer_id'           => $customer,
            'pull_from_maintenance' => true,
        ]);
        $res->assertSuccessful();

        // The ticket was PAUSED (state preserved), NOT closed — the whole point of the change.
        $ticket->refresh();
        $this->assertSame(Maintenance::WF_PAUSED_RETURNED_TO_SERVICE, $ticket->workflow_status);
        $this->assertSame(Maintenance::WF_UNDER_REPAIR, $ticket->paused_from_status);

        // The rental is the single open contract and the car reads rented + owes-maintenance.
        $vehicle = Vehicle::find($vehicleId);
        $this->assertSame('rented', $vehicle->operational_status);
        $this->assertTrue((bool) $vehicle->is_deferred_maintenance);
        $this->assertSame(1, Contract::where('vehicle_id', $vehicleId)->where('state', 'open')->count());

        // And the same ticket can later be resumed from exactly where it paused, with a full handover
        // this time (a human is now present at the ticket UI).
        $this->postJson("/api/maintenance-tickets/{$ticket->id}/resume", $this->resumePayload())->assertSuccessful();
        $this->assertSame(Maintenance::WF_UNDER_REPAIR, $ticket->fresh()->workflow_status);
    }

    // ── Enterprise Handover Workflow ─────────────────────────────────────────────────────────

    public function test_pause_without_mandatory_handover_fields_is_rejected(): void
    {
        $ticket = $this->ticketAt(Maintenance::WF_UNDER_REPAIR);

        $res = $this->postJson("/api/maintenance-tickets/{$ticket->id}/pause", ['reason' => 'no handover data']);
        $res->assertStatus(422);

        // ResponseHelper::fromException() maps a ValidationException to 422 with the field errors
        // carried in `data` (not a top-level `errors` key) — see Helpers/ResponseHelper.php.
        $errors = $res->json('data') ?? [];
        foreach (['pause_odometer', 'odometer_photo', 'fuel_level', 'exterior_condition', 'interior_condition', 'signature'] as $field) {
            $this->assertArrayHasKey($field, $errors, "Expected a validation error for [{$field}]");
        }

        $this->assertSame(Maintenance::WF_UNDER_REPAIR, $ticket->fresh()->workflow_status);
    }

    public function test_resume_without_mandatory_handover_fields_is_rejected(): void
    {
        $ticket = $this->ticketAt(Maintenance::WF_UNDER_REPAIR);
        $this->postJson("/api/maintenance-tickets/{$ticket->id}/pause", $this->pausePayload())->assertSuccessful();

        $res = $this->postJson("/api/maintenance-tickets/{$ticket->id}/resume", []);
        $res->assertStatus(422);

        $errors = $res->json('data') ?? [];
        foreach (['resume_odometer', 'odometer_photo', 'fuel_level', 'exterior_condition', 'interior_condition', 'signature'] as $field) {
            $this->assertArrayHasKey($field, $errors, "Expected a validation error for [{$field}]");
        }

        $this->assertSame(Maintenance::WF_PAUSED_RETURNED_TO_SERVICE, $ticket->fresh()->workflow_status);
    }

    public function test_successful_pause_creates_a_handover_row_and_points_the_ticket_at_it(): void
    {
        $ticket = $this->ticketAt(Maintenance::WF_UNDER_REPAIR);

        $res = $this->postJson("/api/maintenance-tickets/{$ticket->id}/pause", $this->pausePayload(40010, [
            'fuel_level'         => '3/4',
            'exterior_condition' => 'Minor scuff on rear bumper',
            'interior_condition' => 'Clean',
            'notes'              => 'Handed to controller Marwa.',
        ]));
        $res->assertSuccessful();

        $ticket->refresh();
        $this->assertNotNull($ticket->last_pause_handover_id);

        $handover = MaintenanceHandover::find($ticket->last_pause_handover_id);
        $this->assertNotNull($handover);
        $this->assertSame(MaintenanceHandover::TYPE_PAUSE, $handover->type);
        $this->assertSame($ticket->id, $handover->maintenance_id);
        $this->assertSame($ticket->vehicle_id, $handover->vehicle_id);
        $this->assertSame(40010, (int) $handover->odometer_reading);
        $this->assertSame('3/4', $handover->fuel_level);
        $this->assertSame('Minor scuff on rear bumper', $handover->exterior_condition);
        $this->assertSame('Clean', $handover->interior_condition);
        $this->assertSame('Handed to controller Marwa.', $handover->notes);

        // The odometer photo is captured as an InspectionRecord in the same trail every other
        // checkpoint's photos live in (phase = 'pause').
        $this->assertDatabaseHas('inspection_records', [
            'vehicle_id' => $ticket->vehicle_id,
            'phase'      => 'pause',
            'body_part'  => 'odometer',
        ]);
        $this->assertNotNull($handover->fresh()->odometer_photo_inspection_record_id);
    }

    public function test_pause_odometer_regression_still_succeeds_never_hard_blocks(): void
    {
        // makeVehicle() defaults to odometer 40000 — submit a pause reading well below it. Pause never
        // hard-blocks on odometer (soft, never-blocking convention — see OdometerContinuityService).
        $ticket = $this->ticketAt(Maintenance::WF_UNDER_REPAIR);

        $res = $this->postJson("/api/maintenance-tickets/{$ticket->id}/pause", $this->pausePayload(39000));
        $res->assertSuccessful();

        $ticket->refresh();
        $this->assertSame(Maintenance::WF_PAUSED_RETURNED_TO_SERVICE, $ticket->workflow_status);
        $handover = MaintenanceHandover::find($ticket->last_pause_handover_id);
        $this->assertSame(39000, (int) $handover->odometer_reading);
    }

    public function test_clean_resume_finalizes_with_no_incident(): void
    {
        $ticket = $this->ticketAt(Maintenance::WF_UNDER_REPAIR);
        $this->postJson("/api/maintenance-tickets/{$ticket->id}/pause", $this->pausePayload(40010))->assertSuccessful();

        $res = $this->postJson("/api/maintenance-tickets/{$ticket->id}/resume", $this->resumePayload(40050));
        $res->assertSuccessful();
        $this->assertFalse((bool) $res->json('data.blocked_by_incident'));

        $ticket->refresh();
        $this->assertSame(Maintenance::WF_UNDER_REPAIR, $ticket->workflow_status);
        $this->assertNull($ticket->active_incident_id);

        $this->assertDatabaseHas('maintenance_handover_comparisons', [
            'maintenance_id'    => $ticket->id,
            'exceeds_threshold' => false,
        ]);
        $this->assertSame(0, \App\Models\MaintenanceIncident::where('maintenance_id', $ticket->id)->count());
    }

    public function test_breaching_resume_opens_an_incident_and_blocks_the_transition(): void
    {
        $ticket = $this->ticketAt(Maintenance::WF_UNDER_REPAIR);
        $this->postJson("/api/maintenance-tickets/{$ticket->id}/pause", $this->pausePayload(40010, [
            'damage_findings' => [],
        ]))->assertSuccessful();

        // New damage at return that wasn't present at pause — a guaranteed threshold breach per
        // HandoverComparisonService::newDamages().
        $res = $this->postJson("/api/maintenance-tickets/{$ticket->id}/resume", $this->resumePayload(40050, [
            'damage_findings' => [['location' => 'front_bumper', 'severity' => 'moderate', 'note' => 'new scratch']],
        ]));
        $res->assertSuccessful(); // an expected, non-error outcome — still 200
        $this->assertTrue((bool) $res->json('data.blocked_by_incident'));

        $ticket->refresh();
        // The resume is HELD, not silently applied — the ticket stays paused.
        $this->assertSame(Maintenance::WF_PAUSED_RETURNED_TO_SERVICE, $ticket->workflow_status);
        $this->assertNotNull($ticket->active_incident_id);

        $incident = \App\Models\MaintenanceIncident::find($ticket->active_incident_id);
        $this->assertNotNull($incident);
        $this->assertSame('open', $incident->status);
        $this->assertSame($ticket->id, $incident->maintenance_id);

        // Nothing is lost — the resume handover itself is still permanently persisted.
        $this->assertNotNull($ticket->last_resume_handover_id);
        $resumeHandover = MaintenanceHandover::find($ticket->last_resume_handover_id);
        $this->assertNotNull($resumeHandover);
        $this->assertSame(MaintenanceHandover::TYPE_RESUME, $resumeHandover->type);
        $this->assertSame(40050, (int) $resumeHandover->odometer_reading);

        $this->assertDatabaseHas('maintenance_handover_comparisons', [
            'maintenance_id'    => $ticket->id,
            'exceeds_threshold' => true,
        ]);
    }

    public function test_acknowledging_the_incident_finalizes_the_deferred_resume_without_resubmission(): void
    {
        $ticket = $this->ticketAt(Maintenance::WF_UNDER_REPAIR);
        $this->postJson("/api/maintenance-tickets/{$ticket->id}/pause", $this->pausePayload(40010))->assertSuccessful();
        $this->postJson("/api/maintenance-tickets/{$ticket->id}/resume", $this->resumePayload(40050, [
            'damage_findings' => [['location' => 'front_bumper', 'severity' => 'moderate', 'note' => 'new scratch']],
        ]))->assertSuccessful();

        $ticket->refresh();
        $incidentId = $ticket->active_incident_id;
        $this->assertNotNull($incidentId);
        $this->assertSame(Maintenance::WF_PAUSED_RETURNED_TO_SERVICE, $ticket->workflow_status);

        $res = $this->postJson("/api/maintenance-tickets/{$ticket->id}/incidents/{$incidentId}/acknowledge", [
            'acknowledgement_note' => 'Reviewed — damage pre-existing, logged separately.',
        ]);
        $res->assertSuccessful();

        $ticket->refresh();
        // Resumed to the ORIGINAL pre-pause stage — no second resume submission was needed.
        $this->assertSame(Maintenance::WF_UNDER_REPAIR, $ticket->workflow_status);
        $this->assertNull($ticket->active_incident_id);

        $incident = \App\Models\MaintenanceIncident::find($incidentId);
        $this->assertSame('acknowledged', $incident->status);
        $this->assertNotNull($incident->acknowledged_by);
        $this->assertNotNull($incident->acknowledged_at);
        $this->assertSame('Reviewed — damage pre-existing, logged separately.', $incident->acknowledgement_note);
    }

    public function test_mark_returned_flips_vehicle_back_into_maintenance(): void
    {
        $ticket = $this->ticketAt(Maintenance::WF_UNDER_REPAIR);
        $this->postJson("/api/maintenance-tickets/{$ticket->id}/pause", $this->pausePayload())->assertSuccessful();

        $ticket->refresh();
        $this->assertSame('available', $ticket->vehicle->fresh()->operational_status);
        $this->assertNull($ticket->vehicle_returned_at);

        $res = $this->postJson("/api/maintenance-tickets/{$ticket->id}/mark-returned", ['note' => 'Dropped off at base.']);
        $res->assertSuccessful();

        $ticket->refresh();
        $this->assertNotNull($ticket->vehicle_returned_at);
        $this->assertNotNull($ticket->vehicle_returned_by);
        // Still paused — mark-returned is a light checkpoint, not a resume.
        $this->assertSame(Maintenance::WF_PAUSED_RETURNED_TO_SERVICE, $ticket->workflow_status);

        // Physically back but not yet handed over — blocked from being rented out again.
        $vehicle = $ticket->vehicle->fresh();
        $this->assertSame('maintenance', $vehicle->operational_status);
    }

    // ── VehicleLogEvent audit trail ──────────────────────────────────────────────────────────

    public function test_vehicle_log_events_are_recorded_with_the_renamed_event_types(): void
    {
        $ticket = $this->ticketAt(Maintenance::WF_UNDER_REPAIR);

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/pause", $this->pausePayload(40010))->assertSuccessful();
        $this->assertDatabaseHas('vehicle_log_events', [
            'maintenance_id' => $ticket->id,
            'event_type'     => \App\Models\VehicleLogEvent::EVENT_RETURNED_TO_SERVICE,
        ]);

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/mark-returned", [])->assertSuccessful();
        $this->assertDatabaseHas('vehicle_log_events', [
            'maintenance_id' => $ticket->id,
            'event_type'     => \App\Models\VehicleLogEvent::EVENT_VEHICLE_RETURNED,
        ]);

        // A clean resume logs EVENT_RESUMED.
        $this->postJson("/api/maintenance-tickets/{$ticket->id}/resume", $this->resumePayload(40050))->assertSuccessful();
        $this->assertDatabaseHas('vehicle_log_events', [
            'maintenance_id' => $ticket->id,
            'event_type'     => \App\Models\VehicleLogEvent::EVENT_RESUMED,
        ]);

        // Now a second pause/return/breaching-resume cycle to exercise the incident event types.
        $this->postJson("/api/maintenance-tickets/{$ticket->id}/pause", $this->pausePayload(40060))->assertSuccessful();
        $this->postJson("/api/maintenance-tickets/{$ticket->id}/resume", $this->resumePayload(40100, [
            'damage_findings' => [['location' => 'rear_door', 'severity' => 'critical', 'note' => 'dent']],
        ]))->assertSuccessful();
        $this->assertDatabaseHas('vehicle_log_events', [
            'maintenance_id' => $ticket->id,
            'event_type'     => \App\Models\VehicleLogEvent::EVENT_HANDOVER_INCIDENT,
        ]);

        $incidentId = $ticket->fresh()->active_incident_id;
        $this->postJson("/api/maintenance-tickets/{$ticket->id}/incidents/{$incidentId}/acknowledge", [])->assertSuccessful();
        $this->assertDatabaseHas('vehicle_log_events', [
            'maintenance_id' => $ticket->id,
            'event_type'     => \App\Models\VehicleLogEvent::EVENT_INCIDENT_ACKNOWLEDGED,
        ]);
    }

    // ── Rename-regression guard (cheap, no DB) ──────────────────────────────────────────────────

    public function test_workflow_statuses_use_the_renamed_paused_status_only(): void
    {
        $this->assertContains('paused_returned_to_service', Maintenance::WORKFLOW_STATUSES);
        $this->assertNotContains('paused_for_rental', Maintenance::WORKFLOW_STATUSES);
        $this->assertSame('paused_returned_to_service', Maintenance::WF_PAUSED_RETURNED_TO_SERVICE);
        $this->assertFalse(defined(Maintenance::class . '::WF_PAUSED_FOR_RENTAL'));
    }

    public function test_vehicle_log_event_uses_the_renamed_event_type_only(): void
    {
        $this->assertSame('returned_to_service', \App\Models\VehicleLogEvent::EVENT_RETURNED_TO_SERVICE);
        $this->assertFalse(defined(\App\Models\VehicleLogEvent::class . '::EVENT_PAUSED_FOR_RENTAL'));
    }
}
