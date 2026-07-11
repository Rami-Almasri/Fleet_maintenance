<?php

namespace Tests\Crud;

/**
 * CRUD + lifecycle for the Reminders section (service + contact reminders) and
 * the Inspection Schedules — the Fleetio-style recurring-task surfaces.
 */
class RemindersAndSchedulesTest extends CrudTestCase
{
    // ── Service Reminders ────────────────────────────────────────────────────
    public function test_service_reminder_crud_and_complete(): void
    {
        $vehicle = $this->makeVehicle(['odometer' => 50000]);

        $res = $this->postJson('/api/ServiceReminders', [
            'vehicle_id'   => $vehicle,
            'service_type' => 'oil_change',
            'name'         => 'Engine Oil',
            'interval_km'  => 10000,
            'last_service_odometer' => 45000,
        ]);
        $res->assertSuccessful();
        $id = $this->idOf($res);

        $this->getJson('/api/ServiceReminders')->assertSuccessful();
        $this->getJson("/api/ServiceReminders?vehicle_id=$vehicle")->assertSuccessful();
        $this->getJson("/api/ServiceReminders/$id")->assertSuccessful();

        $this->postJson("/api/ServiceReminders/$id", ['interval_km' => 12000])->assertSuccessful();

        // Log the service done → rolls the next-due forward.
        $this->postJson("/api/ServiceReminders/$id/complete", ['odometer' => 50000])->assertSuccessful();

        // Alert the crew this car needs service (writes notifications).
        $this->postJson("/api/ServiceReminders/$id/notify")->assertSuccessful();

        $this->deleteJson("/api/ServiceReminders/$id")->assertSuccessful();
    }

    public function test_service_reminder_requires_vehicle(): void
    {
        $this->postJson('/api/ServiceReminders', ['service_type' => 'oil_change'])->assertStatus(422);
    }

    // ── Contact Reminders ────────────────────────────────────────────────────
    public function test_contact_reminder_crud(): void
    {
        $vendor = $this->makeVendor();

        $res = $this->postJson('/api/ContactReminders', [
            'vendor_id' => $vendor,
            'subject'   => 'Call garage about brake parts',
            'due_at'    => '2026-07-10',
            'status'    => 'open',
        ]);
        $res->assertSuccessful();
        $id = $this->idOf($res);

        $this->getJson('/api/ContactReminders')->assertSuccessful();
        $this->postJson("/api/ContactReminders/$id", ['status' => 'done', 'subject' => 'Call garage about brake parts'])
            ->assertSuccessful();
        $this->deleteJson("/api/ContactReminders/$id")->assertSuccessful();
    }

    // ── Inspection Schedules ─────────────────────────────────────────────────
    public function test_inspection_schedule_crud_and_complete(): void
    {
        $vehicle = $this->makeVehicle();

        $res = $this->postJson('/api/InspectionSchedules', [
            'vehicle_id'    => $vehicle,
            'name'          => 'Monthly Safety Check',
            'pillar'        => 'safety',
            'interval_type' => 'time',
            'interval_days' => 30,
        ]);
        $res->assertSuccessful();
        $id = $this->idOf($res);

        $this->getJson('/api/InspectionSchedules')->assertSuccessful();
        $this->getJson("/api/InspectionSchedules/$id")->assertSuccessful();
        $this->postJson("/api/InspectionSchedules/$id", ['interval_days' => 60])->assertSuccessful();
        $this->postJson("/api/InspectionSchedules/$id/complete", ['odometer' => 41000])->assertSuccessful();
        $this->deleteJson("/api/InspectionSchedules/$id")->assertSuccessful();
    }

    public function test_inspection_schedule_requires_name_and_interval_type(): void
    {
        $this->postJson('/api/InspectionSchedules', ['pillar' => 'safety'])->assertStatus(422);
    }
}
