<?php

namespace Tests\Crud;

use App\Models\Maintenance;
use App\Models\MaintenanceTask;
use App\Models\User;
use App\Notifications\FleetAlert;
use App\Services\RecurringFaultService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/**
 * Opening a Recurring Fault Review must ALERT management — a car coming back with the same confirmed
 * fault is an oversight event, not just a row in a page nobody opens. Audience is by ROLE
 * (super-admin + admin) so it survives permission drift, and the confirming technician is excluded.
 */
class RecurringFaultReviewNotifyTest extends CrudTestCase
{
    public function test_opening_a_review_alerts_admins_and_super_admins(): void
    {
        $vehicleId = $this->makeVehicle(['odometer' => 41000]);
        $garageId  = $this->makeVendor();

        $admin = $this->makeUser('admin');
        // A non-admin with maintenance access must NOT be pulled in — the audience is role-scoped.
        $viewer = $this->makeUser('supervisor');

        // ── The PREVIOUS repair: same fault, fixed 20 days ago at a garage, 1 000 km ago.
        $oldTicket = $this->makeTicket($vehicleId, Maintenance::WF_CLOSED, ['return_odometer' => 40000]);
        $this->makeFault($oldTicket, $vehicleId, [
            'status'            => MaintenanceTask::STATUS_COMPLETED,
            'resolved_at'       => Carbon::now()->subDays(20),
            'current_vendor_id' => $garageId,
        ]);

        // ── The car is BACK with the same fault, now confirmed in the workshop.
        $newTicket = $this->makeTicket($vehicleId, Maintenance::WF_UNDER_REPAIR);
        $newFault  = $this->makeFault($newTicket, $vehicleId);

        Notification::fake();

        $review = app(RecurringFaultService::class)->onFaultConfirmed($newFault, $this->admin);

        $this->assertNotNull($review, 'the same fault fixed 20 days ago must open a review');

        // Both management roles are reached...
        Notification::assertSentTo($admin, FleetAlert::class, function (FleetAlert $n) use ($review, $vehicleId) {
            $p = $n->payload;

            return $p['type'] === 'maint_recurring_fault_review'
                && $p['key'] === 'recurring_fault_review:' . $review->id
                && $p['url'] === '/recurring-fault-reviews'
                && $p['meta']['review_id'] === $review->id
                && $p['meta']['vehicle_id'] === $vehicleId
                && $p['meta']['occurrence_count'] === 2
                && $p['meta']['days_since_repair'] === 20
                && $p['meta']['distance_since_repair'] === 1000
                && str_contains($p['body'], 'Engine overheating');
        });

        // ...the actor who just confirmed the fault is not told what they already know...
        Notification::assertNotSentTo($this->admin, FleetAlert::class);

        // ...and a non-management role is never in the audience.
        Notification::assertNotSentTo($viewer, FleetAlert::class);
    }

    public function test_a_fault_with_no_prior_fix_alerts_nobody(): void
    {
        $vehicleId = $this->makeVehicle();
        $admin     = $this->makeUser('admin');

        $ticket = $this->makeTicket($vehicleId, Maintenance::WF_UNDER_REPAIR);
        $fault  = $this->makeFault($ticket, $vehicleId);

        Notification::fake();

        $this->assertNull(app(RecurringFaultService::class)->onFaultConfirmed($fault, $this->admin));
        Notification::assertNotSentTo($admin, FleetAlert::class);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────────

    private function makeUser(string $role): User
    {
        $user = User::create([
            'name'     => $role . ' user',
            'email'    => $role . '.' . uniqid() . '@fleet.test',
            'password' => Hash::make('password'),
            'status'   => 'active',
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function makeTicket(int $vehicleId, string $status, array $extra = []): Maintenance
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

    private function makeFault(Maintenance $ticket, int $vehicleId, array $extra = []): MaintenanceTask
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
