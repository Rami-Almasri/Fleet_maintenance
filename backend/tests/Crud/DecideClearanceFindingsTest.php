<?php

namespace Tests\Crud;

use App\Models\Maintenance;
use App\Models\MaintenanceTask;
use App\Models\Vehicle;
use App\Services\MaintenanceWorkflowService;

/**
 * THE DECIDE STEP MAY NOT CONTRADICT ITSELF.
 *
 * At Stage 2 the inspector taps the faults he found, then answers "requires maintenance" or "no
 * maintenance needed". Those two halves used to be independent: he could list three real faults and
 * still file the report as a clearance, and it saved without a word.
 *
 * The loss was silent. `submitReport` writes the findings onto the ticket regardless of the decision,
 * but a cleared diagnostic is TERMINAL and never runs syncFromFindings() — so the faults were recorded
 * as first-class evidence on a row that no lane, no garage and no queue would ever surface again, while
 * the log line said "no maintenance required". The car went back into service carrying faults the
 * platform had written down and buried in the same click.
 *
 * A car with findings needs a ticket. These tests hold both directions of that rule.
 */
class DecideClearanceFindingsTest extends CrudTestCase
{
    /**
     * A car with a diagnostic open and the inspector standing at the Decide step. Opened through the
     * service rather than the endpoint because POST /maintenance-tickets requires a real odometer PHOTO
     * upload; the state it produces (under_diagnosis) is identical either way.
     */
    private function ticketAtDecide(): Maintenance
    {
        $vehicleId = $this->makeVehicle(['status' => 'ready', 'odometer' => 40000]);

        $ticket = app(MaintenanceWorkflowService::class)->open([
            'vehicle_id'     => $vehicleId,
            'trigger_reason' => Maintenance::TRIGGER_TEST_DRIVE,
            'test_odometer'  => 40000,   // strict match against the car's current mileage
        ], $this->admin);

        $this->assertSame(Maintenance::WF_INSPECTION_DIAGNOSTIC, $ticket->workflow_status);

        return $ticket->fresh();
    }

    public function test_a_clearance_carrying_findings_is_refused(): void
    {
        $ticket = $this->ticketAtDecide();

        $res = $this->postJson("/api/maintenance-tickets/{$ticket->id}/report", [
            'requires_maintenance' => false,
            'symptoms'             => ['Battery Replacement', 'Brake Noise'],
        ]);

        $res->assertStatus(422);

        // Nothing was written: the ticket is still at the decision, with no findings and no tasks.
        $ticket->refresh();
        $this->assertSame(Maintenance::WF_INSPECTION_DIAGNOSTIC, $ticket->workflow_status, 'a refused report must not move the ticket');
        $this->assertEmpty($ticket->findings ?? [], 'a refused report must not record findings');
        $this->assertSame(0, MaintenanceTask::where('maintenance_id', $ticket->id)->count());
    }

    public function test_a_clearance_with_no_findings_still_closes_the_diagnostic(): void
    {
        $ticket = $this->ticketAtDecide();

        // The honest clearance: the car was checked and nothing was found. Notes and a recommendation are
        // still welcome — it is FINDINGS that assert a fault, and this report asserts none.
        $res = $this->postJson("/api/maintenance-tickets/{$ticket->id}/report", [
            'requires_maintenance' => false,
            'symptoms'             => [],
            'notes'                => 'Test drove 12 km, nothing to report.',
        ]);

        $res->assertSuccessful();
        $this->assertSame(Maintenance::WF_DIAGNOSTIC_CLEARED, $ticket->fresh()->workflow_status);
    }

    public function test_the_same_findings_open_a_ticket_when_the_decision_says_so(): void
    {
        $ticket = $this->ticketAtDecide();

        // The other way out of the refusal above: keep the faults, open the ticket. They are promoted
        // into routable fault-tasks, which is precisely what the clearance path never did.
        $res = $this->postJson("/api/maintenance-tickets/{$ticket->id}/report", [
            'requires_maintenance' => true,
            'symptoms'             => ['Battery Replacement'],
            'fault_severity'       => 'moderate',
        ]);

        $res->assertSuccessful();

        $ticket->refresh();
        $this->assertContains(
            $ticket->workflow_status,
            [Maintenance::WF_INSPECTION_PENDING, Maintenance::WF_ON_SITE_PENDING],
            'a "requires maintenance" report opens the ticket',
        );
        $this->assertCount(1, $ticket->findings ?? []);
        $this->assertSame(
            1,
            MaintenanceTask::where('maintenance_id', $ticket->id)->count(),
            'the finding must be promoted to a fault-task somebody can actually be given',
        );
    }

    public function test_the_refusal_names_the_findings_that_are_in_the_way(): void
    {
        $ticket = $this->ticketAtDecide();

        $res = $this->postJson("/api/maintenance-tickets/{$ticket->id}/report", [
            'requires_maintenance' => false,
            'symptoms'             => ['Oil Change'],
        ]);

        $res->assertStatus(422);

        // The inspector must be able to act on the refusal without guessing which fault blocked it.
        $body = json_encode($res->json());
        $this->assertStringContainsString('Oil Change', $body, 'the refusal must say which findings are in the way');

        // And the car itself is untouched — no service was recorded off a report that never filed.
        $vehicle = Vehicle::find($ticket->vehicle_id);
        $this->assertNull($vehicle->last_service_odometer);
    }
}
