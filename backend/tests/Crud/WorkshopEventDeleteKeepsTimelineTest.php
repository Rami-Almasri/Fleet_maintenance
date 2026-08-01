<?php

namespace Tests\Crud;

use App\Models\Maintenance;
use App\Models\MaintenanceTombstone;
use App\Models\VehicleLogEvent;
use App\Services\VehicleLogService;

/**
 * Deleting a workshop event must never detach the vehicle's timeline from the ticket it described.
 *
 * THE INCIDENT. `WorkshopEventController::destroy` tombstoned SHEET-synced events (which Google Sheets
 * can re-supply) and HARD-DELETED hand-entered ones (which exist nowhere else). Because
 * `vehicle_log_events.maintenance_id` is declared `nullOnDelete`, every hard delete also silently NULLed
 * the ticket link on every event that ticket owned. The rows survived, so the timeline still rendered as
 * complete — while 78.8% of it (1,559 of 1,979 rows, measured 2026-08-01) pointed at nothing.
 *
 * Two independent guarantees are asserted here, because either one alone would have failed to save us:
 *   1. manual events are tombstoned, not destroyed  → the ticket is recoverable
 *   2. `maintenance_ref` survives regardless        → the timeline stays attributable even if it is not
 */
class WorkshopEventDeleteKeepsTimelineTest extends CrudTestCase
{
    /** A hand-entered workshop event (origin=manual, no sheet row_hash) with one timeline entry. */
    private function manualEventWithTimeline(): array
    {
        $vehicleId = $this->makeVehicle();

        $ticket = Maintenance::create([
            'vehicle_id'      => $vehicleId,
            'origin'          => Maintenance::ORIGIN_MANUAL,
            'workflow_status' => Maintenance::WF_UNDER_REPAIR,
            'out_date'        => now()->toDateString(),
        ]);

        // The timeline entry the incident used to orphan.
        app(VehicleLogService::class)->record($ticket, 'under_repair', $this->admin);

        $log = VehicleLogEvent::where('maintenance_id', $ticket->id)->firstOrFail();

        return [$ticket, $log];
    }

    public function test_the_fixture_starts_linked(): void
    {
        // Guards the guard: if the log entry were never written, every assertion below passes vacuously.
        [$ticket, $log] = $this->manualEventWithTimeline();

        $this->assertSame($ticket->id, $log->maintenance_id);
        $this->assertSame($ticket->id, $log->maintenance_ref, 'VehicleLogService must stamp the FK-free twin on write');
        $this->assertTrue($ticket->isManual(), 'fixture must be a hand-entered event to exercise the old hard-delete branch');
    }

    /** THE REGRESSION TEST. */
    public function test_deleting_a_manual_event_does_not_orphan_the_timeline(): void
    {
        [$ticket, $log] = $this->manualEventWithTimeline();

        $this->deleteJson('/api/Maintenance/events/' . $ticket->id)->assertSuccessful();

        $log->refresh();

        $this->assertSame(
            $ticket->id,
            $log->maintenance_ref,
            'maintenance_ref carries no foreign key precisely so no cascade can clear it',
        );
    }

    public function test_a_manual_event_is_soft_deleted_and_tombstoned_rather_than_destroyed(): void
    {
        [$ticket] = $this->manualEventWithTimeline();

        $this->deleteJson('/api/Maintenance/events/' . $ticket->id)->assertSuccessful();

        // SoftDeletes means the row is still THERE — which is the whole point. A hard delete would fire
        // the `nullOnDelete` cascade and detach every timeline entry; a soft delete fires nothing, so
        // the faults, stints, inspections and log events all stay attached to a recoverable ticket.
        $this->assertSoftDeleted('maintenances', ['id' => $ticket->id]);

        $this->assertSame(
            1,
            MaintenanceTombstone::where('row_hash', 'manual:' . $ticket->id)->count(),
            'a hand-entered event has no sheet row_hash, so it is filed under the synthetic manual:{id} key',
        );
    }

    /**
     * The live link must survive too, now that deletion is soft. This is the assertion that would have
     * caught the original incident directly: under the old hard delete `maintenance_id` went NULL here.
     */
    public function test_the_live_ticket_link_also_survives_a_soft_delete(): void
    {
        [$ticket, $log] = $this->manualEventWithTimeline();

        $this->deleteJson('/api/Maintenance/events/' . $ticket->id)->assertSuccessful();

        $this->assertSame($ticket->id, $log->refresh()->maintenance_id, 'a soft delete must not fire the nullOnDelete cascade');
    }

    public function test_a_tombstoned_manual_event_can_be_restored(): void
    {
        [$ticket] = $this->manualEventWithTimeline();

        $this->deleteJson('/api/Maintenance/events/' . $ticket->id)->assertSuccessful();

        $tombstone = MaintenanceTombstone::where('row_hash', 'manual:' . $ticket->id)->firstOrFail();
        $this->postJson('/api/Maintenance/events/tombstones/' . $tombstone->id . '/restore')->assertSuccessful();

        // Identity is what makes the timeline re-associable, so the original id must come back.
        $this->assertDatabaseHas('maintenances', ['id' => $ticket->id]);
    }
}
