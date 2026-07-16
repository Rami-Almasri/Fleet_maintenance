<?php

namespace Tests\Crud;

use App\Http\Resources\MaintenanceTaskResource;
use App\Models\Maintenance;
use App\Models\MaintenanceTask;
use App\Models\ServiceReminder;
use App\Models\Vehicle;
use App\Services\MaintenanceWorkflowService;

/**
 * Ticket = single source of truth for maintenance. A Service Reminder can no longer update the vehicle
 * directly: "completing" a reminder OPENS a maintenance ticket, the crew performs the service (Pending
 * Confirmation), and only when the ticket is CLOSED does the vehicle service record update
 * (MaintenanceWorkflowService::confirmRoutineServices).
 *
 *   Service Reminder → Maintenance Ticket → perform → Pending Confirmation → ticket closed → vehicle updated
 */
class ServiceReminderTicketFlowTest extends CrudTestCase
{
    /** Drive a just-opened service ticket to a closable state with its fault marked performed. */
    private function performAndReadyForClose(Maintenance $ticket, MaintenanceTask $task, int $returnOdometer): void
    {
        $ticket->update([
            'workflow_status' => Maintenance::WF_READY_REINSPECTION,
            'vendor_id'       => $this->makeVendor(),
            'return_odometer' => $returnOdometer,
            'event_status'    => 'OUT',
        ]);
        // Technician performed the service — the fault is completed but NOT yet confirmed to the vehicle.
        $task->update([
            'status'            => MaintenanceTask::STATUS_COMPLETED,
            'resolved_at'       => now(),
            'resolved_by'       => $this->admin->id,
            'current_vendor_id' => $ticket->vendor_id,
        ]);
    }

    /** Resolve the MaintenanceTaskResource payload for one task (proves the Pending Confirmation UI field). */
    private function confirmState(MaintenanceTask $task): ?string
    {
        return (new MaintenanceTaskResource($task->fresh()))->resolve(request())['service_confirmation'] ?? null;
    }

    public function test_oil_reminder_opens_ticket_and_only_closure_updates_the_vehicle(): void
    {
        // A car due for its oil service: interval 10,000 km, last done at 30,000, now at 42,000.
        $vehicleId = $this->makeVehicle(['status' => 'ready', 'odometer' => 42000]);
        $vehicle   = Vehicle::find($vehicleId);
        $vehicle->update(['service_interval_km' => 10000, 'last_service_odometer' => 30000]);

        // 1) SERVICE REMINDER CREATION.
        $res = $this->postJson('/api/ServiceReminders', [
            'vehicle_id'            => $vehicleId,
            'service_type'          => 'oil_change',
            'name'                  => 'Engine Oil',
            'interval_km'           => 10000,
            'last_service_odometer' => 30000,
        ]);
        $res->assertSuccessful();
        $reminderId = $this->idOf($res);

        // 2) OPENING A MAINTENANCE TICKET FROM THE REMINDER (no vehicle write).
        $res = $this->postJson("/api/ServiceReminders/{$reminderId}/complete", ['odometer' => 42000]);
        $res->assertSuccessful();

        $ticketId = (int) data_get($res->json(), 'data.ticket.id');
        $this->assertGreaterThan(0, $ticketId, 'completing the reminder must return a maintenance ticket id');
        $this->assertSame(Maintenance::WF_INSPECTION_PENDING, data_get($res->json(), 'data.ticket.workflow_status'));

        $ticket = Maintenance::find($ticketId);
        $this->assertSame($vehicleId, $ticket->vehicle_id);
        $this->assertTrue(
            collect($ticket->findings)->contains(fn ($f) => strtolower($f['text'] ?? '') === 'oil change'),
            'the ticket must be pre-filled with the Oil Change service'
        );

        $task = MaintenanceTask::where('maintenance_id', $ticketId)->first();
        $this->assertNotNull($task, 'the seeded finding must be promoted to a fault-task');
        $this->assertSame('Oil Change', $task->symptom);

        // 3) NO DIRECT VEHICLE UPDATE BEFORE CLOSURE — the oil anchor is untouched.
        $vehicle->refresh();
        $this->assertSame(30000, (int) $vehicle->last_service_odometer, 'vehicle must NOT be updated before ticket closure');
        $this->assertNull($vehicle->service_synced_at);
        $this->assertSame('service_due', $vehicle->serviceStatus()['status']);
        // The reminder is not rolled forward either.
        $this->assertSame(30000, (int) ServiceReminder::find($reminderId)->last_service_odometer);

        // Technician performs the service → Pending Confirmation (still no vehicle write).
        $this->performAndReadyForClose($ticket, $task, 42000);
        $this->assertSame('pending_confirmation', $this->confirmState($task));
        $this->assertSame(30000, (int) $vehicle->fresh()->last_service_odometer, 'performing must NOT update the vehicle');

        // 4) CLOSING THE TICKET UPDATES THE VEHICLE SERVICE HISTORY.
        app(MaintenanceWorkflowService::class)->close($ticket->fresh(), ['final_odometer' => 42000], $this->admin);
        $this->assertSame(Maintenance::WF_CLOSED, $ticket->fresh()->workflow_status);

        // 5) OIL REMINDER DATES / SERVICE STATUS UPDATE CORRECTLY.
        $vehicle->refresh();
        $this->assertSame(42000, (int) $vehicle->last_service_odometer, 'oil anchor advances to the service odometer on close');
        $this->assertNotNull($vehicle->service_synced_at);
        $this->assertSame('ok', $vehicle->serviceStatus()['status']);
        $this->assertSame(10000, (int) $vehicle->serviceStatus()['remaining']);

        $reminder = ServiceReminder::find($reminderId);
        $this->assertSame(42000, (int) $reminder->last_service_odometer, 'the oil reminder rolls forward on close');
        $this->assertSame(now()->toDateString(), optional($reminder->last_service_at)->toDateString());

        // The fault now reads Confirmed.
        $this->assertSame('confirmed', $this->confirmState($task));
    }

    public function test_battery_reminder_updates_battery_date_only_on_closure(): void
    {
        $vehicleId = $this->makeVehicle(['status' => 'ready', 'odometer' => 42000]);
        $vehicle   = Vehicle::find($vehicleId);
        $this->assertNull($vehicle->battery_last_changed);

        // SERVICE REMINDER CREATION (battery).
        $res = $this->postJson('/api/ServiceReminders', [
            'vehicle_id'   => $vehicleId,
            'service_type' => 'battery',
            'name'         => 'Battery',
            'interval_days' => 730,
        ]);
        $res->assertSuccessful();
        $reminderId = $this->idOf($res);

        // OPEN A TICKET FROM THE REMINDER — pre-filled "Battery Replacement".
        $res = $this->postJson("/api/ServiceReminders/{$reminderId}/complete", ['odometer' => 42000]);
        $res->assertSuccessful();
        $ticketId = (int) data_get($res->json(), 'data.ticket.id');
        $ticket   = Maintenance::find($ticketId);
        $this->assertTrue(collect($ticket->findings)->contains(fn ($f) => strtolower($f['text'] ?? '') === 'battery replacement'));

        $task = MaintenanceTask::where('maintenance_id', $ticketId)->first();
        $this->assertNotNull($task);
        $this->assertSame('Battery Replacement', $task->symptom);

        // NO DIRECT VEHICLE UPDATE BEFORE CLOSURE.
        $this->assertNull($vehicle->fresh()->battery_last_changed, 'battery date must NOT change before closure');

        $this->performAndReadyForClose($ticket, $task, 42000);
        $this->assertSame('pending_confirmation', $this->confirmState($task));
        $this->assertNull($vehicle->fresh()->battery_last_changed, 'performing must NOT change the battery date');

        // CLOSE → battery date updates.
        app(MaintenanceWorkflowService::class)->close($ticket->fresh(), ['final_odometer' => 42000], $this->admin);
        $this->assertSame(Maintenance::WF_CLOSED, $ticket->fresh()->workflow_status);

        $vehicle->refresh();
        $this->assertNotNull($vehicle->battery_last_changed, 'battery_last_changed is stamped on ticket closure');
        $this->assertSame(now()->toDateString(), optional($vehicle->battery_last_changed)->toDateString());
        $this->assertSame('confirmed', $this->confirmState($task));
    }

    public function test_reminder_complete_creates_no_second_ticket_when_one_is_open(): void
    {
        $vehicleId = $this->makeVehicle(['status' => 'ready', 'odometer' => 42000]);

        $res = $this->postJson('/api/ServiceReminders', [
            'vehicle_id' => $vehicleId, 'service_type' => 'oil_change', 'name' => 'Engine Oil', 'interval_km' => 10000,
        ]);
        $reminderId = $this->idOf($res);

        $first  = (int) data_get($this->postJson("/api/ServiceReminders/{$reminderId}/complete", ['odometer' => 42000])->json(), 'data.ticket.id');
        $second = (int) data_get($this->postJson("/api/ServiceReminders/{$reminderId}/complete", ['odometer' => 42000])->json(), 'data.ticket.id');

        // "Open OR create": the second call reuses the same open ticket rather than minting a duplicate.
        $this->assertSame($first, $second);
        $this->assertSame(1, Maintenance::where('vehicle_id', $vehicleId)->count());
    }
}
