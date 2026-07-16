<?php

namespace Tests\Crud;

use App\Models\Maintenance;
use App\Models\MaintenanceTask;
use App\Models\ServiceReminder;
use App\Models\Vehicle;

/**
 * Re-inspection PASS is the SINGLE source of truth for a vehicle's service data.
 *
 * The vehicle's service anchors (oil / battery), service history and reminders update ONLY when the final
 * QA re-inspection PASSES — never on repair completion, return-to-park, invoice steps, or an on-site
 * "Mark as Serviced". A routine service can no longer bypass the QA gate.
 *
 *   Repair/Service done → Ready for Re-inspection → PASS → confirm service data
 *                                                 → FAIL → follow-up fault, NO service update
 */
class ReinspectionServiceConfirmationTest extends CrudTestCase
{
    /** A car due for its oil service: interval 10,000 km, last done at 30,000, now reading 42,000. */
    private function makeOilDueVehicle(): Vehicle
    {
        $vehicleId = $this->makeVehicle(['status' => 'ready', 'odometer' => 42000]);
        $vehicle   = Vehicle::find($vehicleId);
        $vehicle->update(['service_interval_km' => 10000, 'last_service_odometer' => 30000]);

        return $vehicle;
    }

    // ── 1) OIL CHANGE — unchanged before PASS, confirmed (with the PASS odometer) on PASS ──────────────

    public function test_oil_change_updates_only_on_reinspection_pass(): void
    {
        $vehicle = $this->makeOilDueVehicle();
        $vendor  = $this->makeVendor();

        // Repair completed: the Oil Change fault is done and the car is back, awaiting the final QA pass.
        $ticket = Maintenance::create([
            'vehicle_id'      => $vehicle->id,
            'vendor_id'       => $vendor,
            'workflow_status' => Maintenance::WF_READY_REINSPECTION,
            'event_status'    => 'OUT',
            'fault_severity'  => 'routine',
            'return_odometer' => 42000,
        ]);
        MaintenanceTask::create([
            'maintenance_id'    => $ticket->id,
            'vehicle_id'        => $vehicle->id,
            'symptom'           => 'Oil Change',
            'status'            => MaintenanceTask::STATUS_COMPLETED,
            'resolved_at'       => now(),
            'resolved_by'       => $this->admin->id,
            'current_vendor_id' => $vendor,
        ]);

        // BEFORE PASS — repair completed, car back, but the vehicle service data is UNTOUCHED.
        $vehicle->refresh();
        $this->assertSame(30000, (int) $vehicle->last_service_odometer, 'oil anchor must not move before PASS');
        $this->assertNull($vehicle->service_synced_at, 'no service sync before PASS');
        $this->assertSame('service_due', $vehicle->serviceStatus()['status']);
        $this->assertNull(
            ServiceReminder::where('vehicle_id', $vehicle->id)->where('service_type', 'oil_change')->first(),
            'no oil reminder should exist before PASS'
        );

        // RE-INSPECTION PASS — captured at a DISTINCT odometer (42,500) to prove the anchor uses the
        // re-inspection reading, not the return/current reading.
        $res = $this->postJson("/api/maintenance-tickets/{$ticket->id}/close", ['final_odometer' => 42500]);
        $res->assertSuccessful();
        $this->assertSame(Maintenance::WF_CLOSED, $ticket->fresh()->workflow_status);

        // AFTER PASS — oil anchor advances to the PASS odometer; reminder rolls forward; history recorded.
        $vehicle->refresh();
        $this->assertSame(42500, (int) $vehicle->last_service_odometer, 'oil anchor advances to the re-inspection odometer on PASS');
        $this->assertNotNull($vehicle->service_synced_at);
        $this->assertSame('ok', $vehicle->serviceStatus()['status']);
        $this->assertSame(10000, (int) $vehicle->serviceStatus()['remaining']);

        $reminder = ServiceReminder::where('vehicle_id', $vehicle->id)->where('service_type', 'oil_change')->first();
        $this->assertNotNull($reminder, 'the oil reminder is created/rolled on PASS');
        $this->assertSame(42500, (int) $reminder->last_service_odometer, 'oil reminder anchor = PASS odometer');
        $this->assertSame(52500, (int) $reminder->next_due_odometer, 'next oil reminder moves forward one interval');
        $this->assertSame(now()->toDateString(), optional($reminder->last_service_at)->toDateString(), 'service history date recorded');
    }

    // ── 2) RE-INSPECTION FAIL — no oil update, no battery update, ticket stays open ────────────────────

    public function test_reinspection_fail_confirms_no_service_data(): void
    {
        $vehicle = $this->makeOilDueVehicle();
        $this->assertNull($vehicle->battery_last_changed);
        $vendor = $this->makeVendor();

        $ticket = Maintenance::create([
            'vehicle_id'      => $vehicle->id,
            'vendor_id'       => $vendor,
            'workflow_status' => Maintenance::WF_READY_REINSPECTION,
            'event_status'    => 'OUT',
            'fault_severity'  => 'moderate',
            'return_odometer' => 42000,
        ]);
        $oil = MaintenanceTask::create([
            'maintenance_id'    => $ticket->id,
            'vehicle_id'        => $vehicle->id,
            'symptom'           => 'Oil Change',
            'status'            => MaintenanceTask::STATUS_IN_PROGRESS,
            'current_vendor_id' => $vendor,
        ]);
        // A second routine service the garage already marked fixed (completed). It still must NOT reach the
        // vehicle, because the re-inspection as a WHOLE fails — service data is written only on a PASS.
        MaintenanceTask::create([
            'maintenance_id'    => $ticket->id,
            'vehicle_id'        => $vehicle->id,
            'symptom'           => 'Battery Replacement',
            'status'            => MaintenanceTask::STATUS_COMPLETED,
            'resolved_at'       => now(),
            'resolved_by'       => $this->admin->id,
            'current_vendor_id' => $vendor,
        ]);

        // FAIL the re-inspection: the oil fault came back still broken.
        $res = $this->postJson("/api/maintenance-tickets/{$ticket->id}/reopen", [
            'failed_task_ids' => [$oil->id],
            'reason'          => 'Oil still leaking after the service.',
        ]);
        $res->assertSuccessful();

        // Ticket stays OPEN — back to the supervisor's dispatch queue, NOT closed.
        $this->assertSame(Maintenance::WF_REINSPECTION_FAILED, $ticket->fresh()->workflow_status);

        // NO service data touched — neither oil nor battery.
        $vehicle->refresh();
        $this->assertSame(30000, (int) $vehicle->last_service_odometer, 'oil anchor must not move on FAIL');
        $this->assertNull($vehicle->service_synced_at, 'no service sync on FAIL');
        $this->assertNull($vehicle->battery_last_changed, 'battery date must not be stamped on FAIL');
        $this->assertSame('service_due', $vehicle->serviceStatus()['status']);
        $this->assertNull(
            ServiceReminder::where('vehicle_id', $vehicle->id)->whereIn('service_type', ['oil_change', 'battery'])->first(),
            'no service reminder is rolled on FAIL'
        );

        // Follow-up: the failed fault is reopened (dropped back to pending, detached from the garage).
        $this->assertSame(MaintenanceTask::STATUS_PENDING, $oil->fresh()->status);
    }

    // ── 3) ON-SITE — "Mark as Serviced" does not close; it requires a PASS before updating ─────────────

    public function test_on_site_mark_serviced_requires_reinspection_pass(): void
    {
        $vehicle = $this->makeOilDueVehicle();

        // An on-site (mobile) oil-change ticket, car parked with us the whole time.
        $ticket = Maintenance::create([
            'vehicle_id'      => $vehicle->id,
            'workflow_status' => Maintenance::WF_ON_SITE_PENDING,
            'repair_location' => Maintenance::REPAIR_ON_SITE,
            'event_status'    => 'IN',
            'fault_severity'  => 'routine',
        ]);
        MaintenanceTask::create([
            'maintenance_id' => $ticket->id,
            'vehicle_id'     => $vehicle->id,
            'symptom'        => 'Oil Change',
            'status'         => MaintenanceTask::STATUS_IN_PROGRESS,
        ]);

        // "Mark as Serviced" — the mobile work is done.
        $res = $this->postJson("/api/maintenance-tickets/{$ticket->id}/mark-serviced", [
            'notes' => 'Oil changed on-site.',
        ]);
        $res->assertSuccessful();

        // It does NOT close the final workflow — it routes to the QA re-inspection.
        $this->assertSame(
            Maintenance::WF_READY_REINSPECTION,
            $ticket->fresh()->workflow_status,
            'Mark as Serviced must not close — it must require a re-inspection PASS'
        );

        // And the vehicle service data is still untouched.
        $vehicle->refresh();
        $this->assertSame(30000, (int) $vehicle->last_service_odometer, 'on-site service must not update the vehicle before PASS');
        $this->assertNull($vehicle->service_synced_at);

        // RE-INSPECTION PASS — the mobile job has no garage, so close() relaxes the vendor gate.
        $res = $this->postJson("/api/maintenance-tickets/{$ticket->id}/close", ['final_odometer' => 42000]);
        $res->assertSuccessful();
        $this->assertSame(Maintenance::WF_CLOSED, $ticket->fresh()->workflow_status);

        // NOW the on-site service is confirmed to the vehicle.
        $vehicle->refresh();
        $this->assertSame(42000, (int) $vehicle->last_service_odometer, 'on-site service is confirmed only on the PASS');
        $this->assertNotNull($vehicle->service_synced_at);
        $this->assertSame('ok', $vehicle->serviceStatus()['status']);
    }
}
