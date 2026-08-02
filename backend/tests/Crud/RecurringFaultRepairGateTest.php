<?php

namespace Tests\Crud;

use App\Exceptions\WorkflowTransitionException;
use App\Models\Maintenance;
use App\Models\MaintenanceTask;
use App\Services\MaintenanceTaskService;
use Illuminate\Support\Carbon;

/**
 * The REPAIR GATE — the money guard behind Recurring Fault Reviews.
 *
 * Confirming a fault that this car was already fixed for opens a review case AND freezes the repair
 * (repair_gate = pending). Until a manager rules, the workshop must not be able to start work on it or
 * mark it Fixed — otherwise we pay a second time for a fault a garage just charged us to repair, and the
 * review case becomes a rubber stamp on work that already happened.
 *
 * Reopening and cancelling stay allowed: the gate blocks REPAIRING, not correcting.
 */
class RecurringFaultRepairGateTest extends CrudTestCase
{
    public function test_confirming_a_recurring_fault_freezes_the_repair(): void
    {
        [$fault] = $this->recurringFault();

        $this->assertSame(MaintenanceTask::GATE_PENDING, $fault->repair_gate);
        $this->assertTrue($fault->isRepairBlocked());
    }

    public function test_a_frozen_fault_cannot_be_marked_fixed(): void
    {
        [$fault] = $this->recurringFault();

        $this->expectException(WorkflowTransitionException::class);
        $this->expectExceptionMessage('awaiting repair approval');

        app(MaintenanceTaskService::class)->setStatus(
            $fault,
            MaintenanceTask::STATUS_COMPLETED,
            $this->admin,
            'Replaced the radiator again',
        );
    }

    public function test_a_frozen_fault_cannot_even_be_started(): void
    {
        [$fault] = $this->recurringFault();

        $this->expectException(WorkflowTransitionException::class);

        app(MaintenanceTaskService::class)->setStatus($fault, MaintenanceTask::STATUS_IN_PROGRESS, $this->admin);
    }

    public function test_approving_the_gate_releases_the_repair(): void
    {
        [$fault] = $this->recurringFault();
        $svc     = app(MaintenanceTaskService::class);

        $fault = $svc->resolveRepairGate($fault, $this->admin, true, 'Goodwill re-repair approved.');
        $this->assertSame(MaintenanceTask::GATE_APPROVED, $fault->repair_gate);

        $fixed = $svc->setStatus($fault, MaintenanceTask::STATUS_COMPLETED, $this->admin, 'Radiator replaced');
        $this->assertSame(MaintenanceTask::STATUS_COMPLETED, $fixed->status);
        $this->assertNotNull($fixed->resolved_at);
    }

    public function test_rejecting_the_gate_cancels_the_fault_instead_of_repairing_it(): void
    {
        [$fault] = $this->recurringFault();

        $fault = app(MaintenanceTaskService::class)
            ->resolveRepairGate($fault, $this->admin, false, 'Garage must redo this under warranty.');

        $this->assertSame(MaintenanceTask::GATE_REJECTED, $fault->repair_gate);
        $this->assertSame(MaintenanceTask::STATUS_CANCELLED, $fault->status, 'a rejected re-repair is not paid for twice');
    }

    public function test_a_normal_first_time_fault_is_never_gated(): void
    {
        $vehicleId = $this->makeVehicle();
        $ticket    = $this->ticket($vehicleId, Maintenance::WF_UNDER_REPAIR);
        $fault     = $this->fault($ticket, $vehicleId);

        $fault = app(MaintenanceTaskService::class)
            ->confirmFault($fault, MaintenanceTask::CONFIRM_CONFIRMED, $this->admin);

        $this->assertNull($fault->repair_gate, 'no prior fix ⇒ nothing to review, nothing to block');

        // ...and it marks Fixed with no approval step at all.
        $fixed = app(MaintenanceTaskService::class)
            ->setStatus($fault, MaintenanceTask::STATUS_COMPLETED, $this->admin, 'Fixed first time');
        $this->assertSame(MaintenanceTask::STATUS_COMPLETED, $fixed->status);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────────

    /**
     * A car whose "Engine overheating" was fixed 20 days ago, now back with the same fault and confirmed
     * in the workshop — which is exactly what trips the gate.
     *
     * @return array{0:MaintenanceTask, 1:int}
     */
    private function recurringFault(): array
    {
        $vehicleId = $this->makeVehicle(['odometer' => 41000]);
        $garageId  = $this->makeVendor();

        $old = $this->ticket($vehicleId, Maintenance::WF_CLOSED, ['return_odometer' => 40000]);
        $this->fault($old, $vehicleId, [
            'status'            => MaintenanceTask::STATUS_COMPLETED,
            'resolved_at'       => Carbon::now()->subDays(20),
            'current_vendor_id' => $garageId,
        ]);

        $new   = $this->ticket($vehicleId, Maintenance::WF_UNDER_REPAIR);
        $fault = app(MaintenanceTaskService::class)
            ->confirmFault($this->fault($new, $vehicleId), MaintenanceTask::CONFIRM_CONFIRMED, $this->admin);

        return [$fault, $vehicleId];
    }

    private function ticket(int $vehicleId, string $status, array $extra = []): Maintenance
    {
        $ticket = new Maintenance();
        $ticket->vehicle_id      = $vehicleId;
        $ticket->origin          = Maintenance::ORIGIN_MANUAL;
        $ticket->workflow_status = $status;
        $ticket->event_status    = 'OUT';
        $ticket->forceFill($extra);
        $ticket->save();

        return $ticket;
    }

    private function fault(Maintenance $ticket, int $vehicleId, array $extra = []): MaintenanceTask
    {
        $fault = new MaintenanceTask();
        $fault->forceFill(array_merge([
            'maintenance_id' => $ticket->id,
            'vehicle_id'     => $vehicleId,
            'symptom'        => 'Engine overheating',
            'category_key'   => 'cooling',
            'status'         => MaintenanceTask::STATUS_PENDING,
        ], $extra))->save();

        return $fault;
    }
}
