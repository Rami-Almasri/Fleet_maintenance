<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * In-Service Date — the single source of truth for "when did a car start earning", shared by every
 * performance dashboard (Utilization, Profitability, …) so they can never diverge.
 *
 * Definition: a vehicle's In-Service Date is the out_date of its FIRST rental ('C') contract.
 * The gap between purchase_date and that first rental is new-car onboarding (registration, fit-out,
 * NEW-CAR prep) — capital tied up, but NOT operational time. Anchoring utilization/downtime on the
 * In-Service Date keeps those percentages honest; purchase_date is kept separately as "Owned Since"
 * metadata so the dead-capital / ROI gap stays visible.
 *
 * A car that has been purchased but never rented has NO In-Service Date (null) → it is
 * "Pending Service / Onboarding": shown with that label instead of a skewed 0% utilization.
 */
class ServiceWindowService
{
    /**
     * First-rental (In-Service) date per vehicle, as 'Y-m-d'. Vehicles with no rental yet are absent
     * from the map (caller treats a missing key as Pending Service / Onboarding).
     *
     * @param  array<int>|null  $vehicleIds  limit to these vehicles (null = whole fleet)
     * @return array<int,string>  vehicle_id => 'Y-m-d'
     */
    public function inServiceDates(?array $vehicleIds = null): array
    {
        $rows = DB::table('contracts')
            ->where('contract_type', 'C')
            ->whereNotNull('vehicle_id')
            ->whereNotNull('out_date')
            ->when($vehicleIds, fn ($q) => $q->whereIn('vehicle_id', $vehicleIds))
            ->groupBy('vehicle_id')
            ->select('vehicle_id', DB::raw('MIN(out_date) as first_out'))
            ->get();

        $out = [];
        foreach ($rows as $r) {
            if ($r->first_out) {
                $out[(int) $r->vehicle_id] = substr((string) $r->first_out, 0, 10);
            }
        }

        return $out;
    }
}
