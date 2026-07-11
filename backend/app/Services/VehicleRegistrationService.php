<?php

namespace App\Services;

use App\Models\Vehicle;
use App\Models\VehicleRegistration;

class VehicleRegistrationService
{
    public function __construct()
    {
        //
    }
    public function index()
    {
        $registration = VehicleRegistration::with(['vehicle', 'insuranceCompany'])->paginate(50);
        return $registration;
    }

    /**
     * Vehicle-anchored registration + insurance coverage for EVERY active car.
     * Anchored on vehicles (not vehicle_registrations) so cars that have no
     * registration row at all still appear as "without registration / insurance".
     */
    public function coverage()
    {
        return Vehicle::with(['registration.insuranceCompany', 'openContract.customer'])
            ->whereIn('status', Vehicle::ACTIVE_STATUSES) // active fleet only: Ready (2) or Rented (3)
            ->orderBy('plate_no')
            ->get()
            ->map(function ($v) {
                $reg      = $v->registration;
                $contract = $v->openContract;
                return [
                    'vehicle_id'             => $v->id,
                    'plate_no'               => $v->plate_no,
                    'vin'                    => $v->vin,
                    'make'                   => $v->make,
                    'model'                  => $v->model,
                    'status'                 => $v->status,
                    'chasis_no'              => $reg?->chasis_no,
                    'registration_id'        => $reg?->id,

                    'registration_expiry'    => optional($reg?->expiry_date)->toDateString(),
                    'registration_days_left' => $reg?->registration_days_left,
                    'has_registration'       => (bool) ($reg && $reg->expiry_date),

                    'insurance_expiry'       => optional($reg?->insurance_expiry)->toDateString(),
                    'insurance_days_left'    => $reg?->insurance_days_left,
                    'has_insurance'          => (bool) ($reg && $reg->insurance_expiry),

                    'insurer'                => $reg?->insuranceCompany?->name,

                    // Current open contract (if any), so staff see the car's live contract status.
                    'has_open_contract'      => (bool) $contract,
                    'contract_no'            => $contract?->contract_no,
                    'contract_type'          => $contract?->contract_type,
                    'contract_state'         => $contract?->state,
                    'contract_customer'      => $contract?->customer?->name_en ?: $contract?->customer?->name_ar,
                ];
            })
            ->values();
    }
    public function store(array $data)
    {
        $registration = VehicleRegistration::create($data);
        return $registration;
    }
    public function update(array $data, VehicleRegistration $registration)
    {
        $registration->update($data);
        return $registration->refresh();
    }
    public function destroy(VehicleRegistration $registration)
    {
        $registration->delete();
    }
}
