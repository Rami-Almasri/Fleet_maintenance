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
