<?php

namespace Tests\Crud;

use App\Models\Maintenance;
use App\Models\MaintenanceTask;
use App\Services\MaintenanceWorkflowService;
use App\Services\NotificationScanner;
use Illuminate\Support\Carbon;

/**
 * THE THIRD ANSWER AT THE DECIDE STEP.
 *
 * Before this existed the inspector had two ways out: send the car in, or clear it — and a hard rule
 * that a clearance may not carry findings (see DecideClearanceFindingsTest). That rule is right, but it
 * left the fleet's most common situation with nowhere to go: a real fault that genuinely is not urgent.
 * The only way to file it was to untick the findings, which is deleting the evidence to satisfy a gate.
 *
 * "Deferred maintenance" is the answer to that. What these tests hold is the SHAPE of the promise:
 *
 *   • the fault is recorded exactly as thoroughly as one being sent in (findings → fault tasks);
 *   • nothing is dispatched — no garage, no driver, and the car stays rentable;
 *   • it cannot be used to postpone the two things that must never wait (critical, breakdown);
 *   • it cannot be used to say "later" without saying WHEN and WHY;
 *   • and something actually brings it back.
 *
 * The last one is the point of the whole feature. A deferral nothing ever reopens is a nicer-looking
 * way of losing a finding than unticking it was.
 */
class DeferredMaintenanceDecisionTest extends CrudTestCase
{
    /** A car with a diagnostic open and the inspector standing at the Decide step. */
    private function ticketAtDecide(int $odometer = 40000): Maintenance
    {
        $vehicleId = $this->makeVehicle(['status' => 'ready', 'odometer' => $odometer]);

        $ticket = app(MaintenanceWorkflowService::class)->open([
            'vehicle_id'     => $vehicleId,
            'trigger_reason' => Maintenance::TRIGGER_TEST_DRIVE,
            'test_odometer'  => $odometer,
        ], $this->admin);

        return $ticket->fresh();
    }

    /** The minimum a valid deferral has to say. */
    private function deferPayload(array $overrides = []): array
    {
        return array_merge([
            'decision'          => Maintenance::DECIDE_DEFERRED,
            'symptoms'          => ['Battery Replacement'],
            'fault_severity'    => 'routine',
            'deferral_trigger'  => Maintenance::DEFER_ON_DATE,
            'deferral_due_date' => Carbon::today()->addDays(30)->toDateString(),
            'deferred_reason'   => 'Minor — do it with the next oil change.',
        ], $overrides);
    }

    // ── What a deferral records ─────────────────────────────────────────────────────────────────

    public function test_a_deferral_records_the_fault_without_dispatching_the_car(): void
    {
        $ticket = $this->ticketAtDecide();

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/report", $this->deferPayload())
            ->assertSuccessful();

        $ticket->refresh();

        // Parked, not dispatched.
        $this->assertSame(Maintenance::WF_MAINTENANCE_DEFERRED, $ticket->workflow_status);
        $this->assertNull($ticket->vendor_id, 'a deferral must not pick a garage');
        $this->assertNull($ticket->assigned_driver_id, 'a deferral must not assign a driver');

        // …but the fault is on the record as fully as any other, which is the whole point: this is the
        // difference between deferring a repair and forgetting a finding.
        $this->assertCount(1, $ticket->findings ?? []);
        $this->assertSame(
            1,
            MaintenanceTask::where('maintenance_id', $ticket->id)->count(),
            'a deferred finding must still become a routable fault task — otherwise it is buried',
        );

        // And the decision itself is recorded, not just its consequence.
        $this->assertNotNull($ticket->deferred_at);
        $this->assertSame((int) $this->admin->id, (int) $ticket->deferred_by);
        $this->assertSame(Maintenance::DEFER_ON_DATE, $ticket->deferral_trigger);
        $this->assertNotEmpty($ticket->deferred_reason);
    }

    public function test_a_deferred_ticket_leaves_the_car_free_to_rent(): void
    {
        $ticket = $this->ticketAtDecide();

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/report", $this->deferPayload())
            ->assertSuccessful();

        // The mechanism that guarantees it: maintenance_deferred is outside WF_TICKET_STATES, which is
        // what every "is this car in maintenance?" question in the platform reads. Asserted on the
        // constant rather than on one caller's answer, because it is the constant the others all use.
        $this->assertNotContains(Maintenance::WF_MAINTENANCE_DEFERRED, Maintenance::WF_TICKET_STATES);
        $this->assertNotContains(Maintenance::WF_MAINTENANCE_DEFERRED, Maintenance::WF_TERMINAL, 'a deferral is open work, not a closed one');
    }

    // ── What may not be deferred ────────────────────────────────────────────────────────────────

    public function test_a_critical_fault_cannot_be_deferred(): void
    {
        $ticket = $this->ticketAtDecide();

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/report", $this->deferPayload([
            'fault_severity' => 'critical',
        ]))->assertStatus(422);

        $this->assertSame(Maintenance::WF_INSPECTION_DIAGNOSTIC, $ticket->fresh()->workflow_status);
    }

    public function test_a_breakdown_cannot_be_deferred(): void
    {
        $ticket = $this->ticketAtDecide();

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/report", $this->deferPayload([
            'maintenance_type' => Maintenance::TYPE_BREAKDOWN,
        ]))->assertStatus(422);

        $this->assertSame(Maintenance::WF_INSPECTION_DIAGNOSTIC, $ticket->fresh()->workflow_status);
    }

    // ── A deferral has to say when and why ──────────────────────────────────────────────────────

    public function test_a_deferral_without_a_trigger_is_refused(): void
    {
        $ticket = $this->ticketAtDecide();

        $payload = $this->deferPayload();
        unset($payload['deferral_trigger'], $payload['deferral_due_date']);

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/report", $payload)->assertStatus(422);
        $this->assertSame(Maintenance::WF_INSPECTION_DIAGNOSTIC, $ticket->fresh()->workflow_status);
    }

    public function test_a_deferral_without_a_reason_is_refused(): void
    {
        $ticket = $this->ticketAtDecide();

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/report", $this->deferPayload([
            'deferred_reason' => '',
        ]))->assertStatus(422);
    }

    public function test_a_follow_up_date_in_the_past_is_refused(): void
    {
        $ticket = $this->ticketAtDecide();

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/report", $this->deferPayload([
            'deferral_due_date' => Carbon::today()->subDay()->toDateString(),
        ]))->assertStatus(422);
    }

    public function test_a_mileage_target_the_car_has_already_passed_is_refused(): void
    {
        // Otherwise the deferral is due the instant it is saved, which reads as a bug rather than as
        // the instruction it was meant to be.
        $ticket = $this->ticketAtDecide(80000);

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/report", $this->deferPayload([
            'deferral_trigger'      => Maintenance::DEFER_ON_MILEAGE,
            'deferral_due_date'     => null,
            'deferral_due_odometer' => 70000,
        ]))->assertStatus(422);
    }

    // ── What brings it back ─────────────────────────────────────────────────────────────────────

    public function test_a_date_deferral_becomes_due_when_the_date_arrives(): void
    {
        $ticket = $this->ticketAtDecide();

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/report", $this->deferPayload([
            'deferral_due_date' => Carbon::today()->addDays(10)->toDateString(),
        ]))->assertSuccessful();

        $scanner = app(NotificationScanner::class);

        $keys = collect($scanner->detect())->pluck('key');
        $this->assertNotContains('deferred_maint_due:' . $ticket->id, $keys, 'nothing is due before its date');

        // Ten days pass. Nothing was scheduled anywhere — the condition is recomputed from the row, which
        // is exactly why an extended rental or a rescheduled service can never leave a stale alarm behind.
        Carbon::setTestNow(Carbon::now()->addDays(11));
        $keys = collect($scanner->detect())->pluck('key');
        Carbon::setTestNow();

        $this->assertContains('deferred_maint_due:' . $ticket->id, $keys, 'the follow-up must actually come back');
    }

    public function test_a_mileage_deferral_becomes_due_when_the_car_reaches_the_reading(): void
    {
        $ticket = $this->ticketAtDecide(40000);

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/report", $this->deferPayload([
            'deferral_trigger'      => Maintenance::DEFER_ON_MILEAGE,
            'deferral_due_date'     => null,
            'deferral_due_odometer' => 50000,
        ]))->assertSuccessful();

        $scanner = app(NotificationScanner::class);
        $this->assertNotContains('deferred_maint_due:' . $ticket->id, collect($scanner->detect())->pluck('key'));

        $ticket->refresh()->vehicle->update(['odometer' => 50250]);

        $this->assertContains('deferred_maint_due:' . $ticket->id, collect($scanner->detect())->pluck('key'));
    }

    // ── Sending it in ───────────────────────────────────────────────────────────────────────────

    public function test_sending_a_deferred_repair_in_puts_it_in_the_dispatch_queue(): void
    {
        $ticket = $this->ticketAtDecide();

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/report", $this->deferPayload())
            ->assertSuccessful();

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/activate-deferred", [])
            ->assertSuccessful();

        $ticket->refresh();
        $this->assertSame(Maintenance::WF_INSPECTION_PENDING, $ticket->workflow_status);
        $this->assertSame(Maintenance::REPAIR_IN_SHOP, $ticket->repair_location, 'in-shop is the safe default');

        // The deferral columns SURVIVE activation on purpose: how long the fleet carried this fault is
        // one of the few honest inputs into "should we have gone earlier?", and clearing them would
        // erase the only evidence the decision was ever made.
        $this->assertNotNull($ticket->deferred_at);
        $this->assertNotNull($ticket->deferral_activated_at);

        // …and the alert stops firing, because the ticket is no longer parked.
        $this->assertNotContains(
            'deferred_maint_due:' . $ticket->id,
            collect(app(NotificationScanner::class)->detect())->pluck('key'),
        );
    }

    public function test_only_a_parked_ticket_can_be_sent_in(): void
    {
        $ticket = $this->ticketAtDecide();

        // Still at the diagnostic — there is nothing deferred to activate.
        $this->postJson("/api/maintenance-tickets/{$ticket->id}/activate-deferred", [])
            ->assertStatus(422);
    }

    // ── The old boolean still means what it always meant ────────────────────────────────────────

    public function test_the_legacy_boolean_still_opens_and_clears(): void
    {
        $open = $this->ticketAtDecide();
        $this->postJson("/api/maintenance-tickets/{$open->id}/report", [
            'requires_maintenance' => true,
            'symptoms'             => ['Battery Replacement'],
            'fault_severity'       => 'moderate',
        ])->assertSuccessful();
        $this->assertSame(Maintenance::WF_INSPECTION_PENDING, $open->fresh()->workflow_status);

        $clear = $this->ticketAtDecide();
        $this->postJson("/api/maintenance-tickets/{$clear->id}/report", [
            'requires_maintenance' => false,
            'symptoms'             => [],
        ])->assertSuccessful();
        $this->assertSame(Maintenance::WF_DIAGNOSTIC_CLEARED, $clear->fresh()->workflow_status);
    }

    public function test_a_decision_that_contradicts_the_legacy_boolean_is_refused(): void
    {
        // A client bug must not silently become a wrong decision about a real car.
        $ticket = $this->ticketAtDecide();

        $this->postJson("/api/maintenance-tickets/{$ticket->id}/report", $this->deferPayload([
            'requires_maintenance' => true,
        ]))->assertStatus(422);
    }
}
