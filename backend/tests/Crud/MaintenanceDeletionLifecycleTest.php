<?php

namespace Tests\Crud;

use App\Models\Maintenance;
use App\Models\MaintenanceTask;
use App\Models\MaintenanceTombstone;
use App\Models\Vehicle;
use App\Models\VehicleLogEvent;
use App\Services\WorkshopEventService;
use Illuminate\Support\Facades\DB;

/**
 * Regression cover for the maintenance DELETION LIFECYCLE.
 *
 * Every assertion here exists because the opposite behaviour shipped and destroyed real data: 79% of
 * the vehicle timeline (1,559 of 1,979 rows) was found detached from its ticket, and 49,453 repair
 * signatures sat one `maintenance:reload` away from deletion. These tests are the guard rail.
 *
 * ⚠️ EXECUTION IS BLOCKED IN THIS ENVIRONMENT — see [[migrations-cannot-run-from-empty]].
 * `phpunit.xml` pins the suite to sqlite `:memory:` and `RefreshDatabase` runs `migrate:fresh`, which
 * dies on a MySQL-only `MODIFY` statement in an older migration. The failure is environmental and
 * predates this work; it is NOT a signal about the code under test. To run these, repair the harness
 * first (point the suite at a cloned `laravel_test` schema rather than migrating from empty).
 *
 * The tests are written and committed anyway, deliberately: the harness will be fixed, and a lifecycle
 * this easy to regress must not wait for that before its expectations are written down.
 */
class MaintenanceDeletionLifecycleTest extends CrudTestCase
{
    private function makeTicket(array $overrides = []): Maintenance
    {
        $vehicle = Vehicle::first() ?? Vehicle::create([
            'plate_no' => 'TEST-' . uniqid(),
            'make'     => 'Test',
            'model'    => 'Car',
        ]);

        return Maintenance::create(array_merge([
            'vehicle_id'   => $vehicle->id,
            'origin'       => Maintenance::ORIGIN_MANUAL,
            'event_status' => 'OUT',
            'out_date'     => now()->subDays(3)->toDateString(),
            'garage'       => 'Test Garage',
        ], $overrides));
    }

    /** The core promise: deleting retires the row, it does not remove it. */
    public function test_delete_soft_deletes_and_keeps_the_row(): void
    {
        $ticket = $this->makeTicket();
        $id     = $ticket->id;

        app(WorkshopEventService::class)->tombstone($ticket, $this->admin->id);

        $this->assertNull(Maintenance::find($id), 'a retired ticket must be invisible to normal reads');
        $this->assertNotNull(Maintenance::withTrashed()->find($id), 'the row itself must still exist');
        $this->assertTrue(Maintenance::withTrashed()->find($id)->trashed());
    }

    /**
     * The defect that started all of this: a hard delete NULLed `vehicle_log_events.maintenance_id`
     * through `nullOnDelete`, detaching the timeline while leaving the rows present.
     */
    public function test_delete_does_not_orphan_the_vehicle_timeline(): void
    {
        $ticket = $this->makeTicket();

        $event = VehicleLogEvent::create([
            'vehicle_id'      => $ticket->vehicle_id,
            'maintenance_id'  => $ticket->id,
            'maintenance_ref' => $ticket->id,
            'event_type'      => VehicleLogEvent::EVENT_UNDER_REPAIR,
            'source_tag'      => Maintenance::FINDING_GARAGE,
            'occurred_at'     => now(),
        ]);

        app(WorkshopEventService::class)->tombstone($ticket, $this->admin->id);

        $event->refresh();
        $this->assertSame($ticket->id, $event->maintenance_id, 'the FK link must survive a retirement');
        $this->assertSame($ticket->id, $event->maintenance_ref, 'the FK-free twin must always survive');
    }

    /** Cascading children (faults, and their garage stints) must not be destroyed by a retirement. */
    public function test_delete_does_not_cascade_child_evidence(): void
    {
        $ticket = $this->makeTicket();

        $task = MaintenanceTask::create([
            'maintenance_id' => $ticket->id,
            'vehicle_id'     => $ticket->vehicle_id,
            'symptom'        => 'test fault',
            'status'         => MaintenanceTask::STATUS_PENDING,
        ]);

        app(WorkshopEventService::class)->tombstone($ticket, $this->admin->id);

        $this->assertDatabaseHas('maintenance_tasks', ['id' => $task->id]);
    }

    /** Every destruction path must leave a marker, and it must survive its own subject. */
    public function test_delete_writes_a_ticket_deleted_audit_event(): void
    {
        $ticket = $this->makeTicket();

        app(WorkshopEventService::class)->tombstone($ticket, $this->admin->id);

        $log = VehicleLogEvent::where('event_type', VehicleLogEvent::EVENT_TICKET_DELETED)
            ->where('vehicle_id', $ticket->vehicle_id)
            ->latest('id')
            ->first();

        $this->assertNotNull($log, 'a retirement must be recorded on the vehicle trail');
        $this->assertSame($ticket->id, $log->meta['ticket_id'] ?? null, 'identity must live in meta, not only in the FK');
        $this->assertSame('tombstone', $log->meta['mode'] ?? null);
    }

    /** Restore is an undo, not a re-entry: same id, children still attached. */
    public function test_restore_brings_the_ticket_back_with_its_identity(): void
    {
        $ticket = $this->makeTicket();
        $id     = $ticket->id;

        $tombstone = app(WorkshopEventService::class)->tombstone($ticket, $this->admin->id);
        $restored  = app(WorkshopEventService::class)->restore($tombstone);

        $this->assertSame($id, $restored->id, 'restoring must not mint a new identity');
        $this->assertFalse((bool) $restored->restored_from_payload, 'a soft-deleted ticket restores from the row, not the payload');
        $this->assertNotNull(Maintenance::find($id), 'the ticket must be live again');
        $this->assertDatabaseMissing('maintenance_tombstones', ['id' => $tombstone->id]);
    }

    /**
     * THE NIGHTLY-JOB BLOCKER. `row_hash` is UNIQUE and a retired row keeps occupying it while being
     * invisible to a scoped read — so an importer that looked it up without `withTrashed()` would fall
     * through to create() and violate the constraint. That job runs unattended at 02:30.
     */
    public function test_soft_deleted_row_hash_is_found_through_the_trash(): void
    {
        $hash   = 'test-hash-' . uniqid();
        $ticket = $this->makeTicket(['origin' => 'sheet', 'row_hash' => $hash]);

        $ticket->delete();

        $this->assertNull(
            Maintenance::where('row_hash', $hash)->first(),
            'a scoped lookup cannot see it — this is exactly why the importer would have collided'
        );
        $this->assertNotNull(
            Maintenance::withTrashed()->where('row_hash', $hash)->first(),
            'withTrashed() must find it so the importer updates instead of inserting'
        );
    }

    /** A retired ticket must not hold a car in maintenance — the live-view rule, in raw SQL. */
    public function test_live_operational_query_excludes_retired_tickets(): void
    {
        $ticket = $this->makeTicket();

        $liveBefore = DB::table('maintenances')
            ->where('vehicle_id', $ticket->vehicle_id)
            ->whereNull('deleted_at')
            ->count();

        $ticket->delete();

        $liveAfter = DB::table('maintenances')
            ->where('vehicle_id', $ticket->vehicle_id)
            ->whereNull('deleted_at')
            ->count();

        $this->assertSame($liveBefore - 1, $liveAfter, 'the live view must drop a retired ticket');
    }

    /**
     * The counterpart rule: historical analytics keep counting it. Asserted as raw SQL WITHOUT a
     * deleted_at filter, which is precisely what OperationalKpiService and the intelligence layer run.
     */
    public function test_historical_query_still_counts_retired_tickets(): void
    {
        $ticket = $this->makeTicket();

        $before = DB::table('maintenances')->where('vehicle_id', $ticket->vehicle_id)->count();
        $ticket->delete();
        $after = DB::table('maintenances')->where('vehicle_id', $ticket->vehicle_id)->count();

        $this->assertSame($before, $after, 'a retired ticket is still a repair that happened');
    }

    /** Deleting through the deprecated entry point must behave identically — one canonical flow. */
    public function test_destroy_delegates_to_the_single_deletion_path(): void
    {
        $ticket = $this->makeTicket();
        $id     = $ticket->id;

        app(WorkshopEventService::class)->destroy($ticket, $this->admin->id);

        $this->assertNotNull(Maintenance::withTrashed()->find($id), 'destroy() must no longer hard-delete');
        $this->assertDatabaseHas('maintenance_tombstones', ['row_hash' => 'manual:' . $id]);
    }
}
