<?php

namespace Tests\Crud;

use App\Models\Maintenance;
use App\Models\MaintenanceTask;
use App\Models\Vehicle;
use App\Services\GarageInvoiceService;
use App\Services\MaintenanceTaskService;

/**
 * Garage-transfer invoice attribution: after a car is transferred from Garage A to Garage B, each
 * garage must be billed ONLY the faults it owns — resolved there or currently worked there
 * (maintenance_tasks.current_vendor_id) — never a fault it merely received and handed off unresolved.
 *
 * Regression for the reported bug: Garage A kept showing all faults (because it once had a stint on
 * every fault) while Garage B saw only the transferred ones, mis-attributing the invoice.
 */
class GarageTransferInvoiceAttributionTest extends CrudTestCase
{
    public function test_transferred_faults_move_to_the_new_garage_for_billing(): void
    {
        $garageA = $this->makeVendor(['name' => 'Garage A']);
        $garageB = $this->makeVendor(['name' => 'Garage B']);

        $vehicleId = $this->makeVehicle();
        $ticket = Maintenance::create([
            'vehicle_id'      => $vehicleId,
            'vendor_id'       => $garageA,
            'workflow_status' => Maintenance::WF_UNDER_REPAIR,
            'event_status'    => 'OUT',
        ]);

        // Three faults, all start life at Garage A.
        foreach (['Oil leak', 'Brake noise', 'AC not cooling'] as $symptom) {
            MaintenanceTask::create([
                'maintenance_id' => $ticket->id,
                'vehicle_id'     => $vehicleId,
                'symptom'        => $symptom,
                'status'         => MaintenanceTask::STATUS_PENDING,
            ]);
        }

        $tasks   = app(MaintenanceTaskService::class);
        $invoices = app(GarageInvoiceService::class);

        // Dispatch all three to Garage A (opens a stint + sets current_vendor_id on each).
        $tasks->dispatchFaults($ticket->fresh(), $garageA, null, $this->admin);

        // Garage A actually FIXES the oil leak; it keeps ownership of that fault.
        $oilLeak = $ticket->tasks()->where('symptom', 'Oil leak')->first();
        $oilLeak->update(['status' => MaintenanceTask::STATUS_COMPLETED]);

        // The car is transferred to Garage B for the still-open faults. This moves only the
        // non-terminal faults (Brake noise, AC) — the resolved Oil leak stays attributed to Garage A.
        $moved = $tasks->routeTicketToGarage($ticket->fresh(), $garageB, 'Specialist work needed', $this->admin);
        $this->assertSame(2, $moved, 'Only the two open faults should transfer; the fixed one stays.');

        // Attribution menu the team bills from.
        $garages = collect($invoices->garagesForTicket($ticket->fresh()))->keyBy('vendor_id');

        // Garage A bills ONLY what it fixed.
        $this->assertTrue($garages->has($garageA), 'Garage A should still be billable for what it fixed.');
        $this->assertSame(['Oil leak'], $garages[$garageA]['findings']);

        // Garage B bills the faults it now owns.
        $this->assertTrue($garages->has($garageB), 'Garage B should be billable for the transferred faults.');
        $this->assertEqualsCanonicalizing(['Brake noise', 'AC not cooling'], $garages[$garageB]['findings']);

        // The core regression guard: the transferred faults must NOT remain attached to Garage A.
        $this->assertNotContains('Brake noise', $garages[$garageA]['findings']);
        $this->assertNotContains('AC not cooling', $garages[$garageA]['findings']);
    }
}
