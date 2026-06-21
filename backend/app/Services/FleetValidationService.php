<?php

namespace App\Services;

use App\Exceptions\VehicleRestrictedException;
use App\Models\Vehicle;

/**
 * Decides whether a vehicle is allowed to do something (mainly: be rented).
 * Expired registration or insurance blocks renting — but NOT moving the car
 * (you may still transfer it to a garage or to sale prep to renew/dispose it).
 */
class FleetValidationService
{
    /** Asset statuses that can never be rented (OfficeManager set; for_sale is a flag, not blocking). */
    protected const NON_RENTABLE_STATUSES = ['sold', 'disposed', 'suspended', 'out_of_order', 'returned', 'office_use'];

    /**
     * Reasons a vehicle cannot be rented. Empty array = OK to rent.
     *
     * @return array<int, string>
     */
    public function rentBlockers(Vehicle $vehicle): array
    {
        $reasons = [];
        $today = now()->startOfDay();

        $reg = $vehicle->relationLoaded('registration') ? $vehicle->registration : $vehicle->registration()->first();

        if ($reg) {
            if ($reg->expiry_date && $reg->expiry_date->lt($today)) {
                $reasons[] = 'Registration expired on ' . $reg->expiry_date->toDateString();
            }
            if ($reg->insurance_expiry && $reg->insurance_expiry->lt($today)) {
                $reasons[] = 'Insurance expired on ' . $reg->insurance_expiry->toDateString();
            }
        }

        if (in_array($vehicle->status, self::NON_RENTABLE_STATUSES, true)) {
            $reasons[] = "Vehicle status is '{$vehicle->status}'";
        }

        return $reasons;
    }

    public function canRent(Vehicle $vehicle): bool
    {
        return $this->rentBlockers($vehicle) === [];
    }

    /**
     * Block the action if the vehicle can't be rented.
     *
     * @throws VehicleRestrictedException
     */
    public function assertCanRent(Vehicle $vehicle): void
    {
        $blockers = $this->rentBlockers($vehicle);

        if ($blockers !== []) {
            throw new VehicleRestrictedException(
                'Vehicle is restricted and cannot be rented: ' . implode('; ', $blockers)
            );
        }
    }
}
