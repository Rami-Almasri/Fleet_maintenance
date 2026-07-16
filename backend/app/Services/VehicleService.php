<?php

namespace App\Services;

use App\Models\Vehicle;

class VehicleService
{
    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }
    public function index()
    {
        // count each car's currently-open maintenance + rental contracts so the list
        // can flag which cars are in the garage / out on rent right now.
        return Vehicle::withCount([
            'contracts as open_maintenance_count' => fn ($q) => $q->where('contract_type', 'U')->currentlyOpen(),
            'contracts as open_rental_count' => fn ($q) => $q->where('contract_type', 'C')->currentlyOpen(),
            'contracts as open_booking_count' => fn ($q) => $q->upcomingReservation(),
            // total open movements of ANY kind — 0 means the car is free to use
            'contracts as open_contract_count' => fn ($q) => $q->currentlyOpen(),
            // Repair Location — an OPEN on-site (mobile) ticket. The car stays available, so this drives
            // the "Pending Maintenance" tag on the list without touching operational_status.
            'maintenances as open_on_site_count' => fn ($q) => $q->where('workflow_status', \App\Models\Maintenance::WF_ON_SITE_PENDING),
            // MANDATORY maintenance — an open committed ticket the inspector marked NON-deferrable at the
            // Decide step. The car is grounded until the workshop completes it; the rental form reads this
            // to block the pull-out outright (vs a deferrable ticket, which it offers to pause).
            'maintenances as mandatory_maintenance_count' => fn ($q) => $q
                ->openWorkflow()
                ->whereIn('workflow_status', \App\Models\Maintenance::WF_TICKET_STATES)
                ->where('deferrable_for_rental', false),
        ])->get();
    }
    public function store(array $data)
    {
        $vehicle = Vehicle::create($data);
        return $vehicle;
    }
    public function update(array $data, Vehicle $vehicle)
    {
        $vehicle->update($data);
        return $vehicle->refresh();
    }
    public function destroy(Vehicle $vehicle)
    {
        $vehicle->delete();
    }
}
