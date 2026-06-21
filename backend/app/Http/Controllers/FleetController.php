<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\VehicleRegistration;
use Illuminate\Http\Request;

class FleetController extends Controller
{
    /**
     * Vehicles whose registration or insurance expires within N days (default 30),
     * including ones already expired. Sorted by the most urgent first.
     */
    public function expiring(Request $request)
    {
        try {
            $days   = max(0, (int) $request->query('days', 30));
            $cutoff = now()->startOfDay()->addDays($days)->toDateString();

            $regs = VehicleRegistration::with(['vehicle', 'insuranceCompany'])
                ->where(function ($q) use ($cutoff) {
                    $q->whereNotNull('expiry_date')->where('expiry_date', '<=', $cutoff)
                        ->orWhere(function ($q2) use ($cutoff) {
                            $q2->whereNotNull('insurance_expiry')->where('insurance_expiry', '<=', $cutoff);
                        });
                })
                ->get()
                ->map(fn ($r) => [
                    'vehicle_id'             => $r->vehicle_id,
                    'vehicle'                => trim(optional($r->vehicle)->make . ' ' . optional($r->vehicle)->model),
                    'plate_no'               => optional($r->vehicle)->plate_no,
                    'chasis_no'              => $r->chasis_no,
                    'registration_expiry'    => optional($r->expiry_date)->toDateString(),
                    'registration_days_left' => $r->registration_days_left,
                    'insurance_expiry'       => optional($r->insurance_expiry)->toDateString(),
                    'insurance_days_left'    => $r->insurance_days_left,
                    'insurer'                => optional($r->insuranceCompany)->name,
                    'most_urgent_days_left'  => min(
                        $r->registration_days_left ?? PHP_INT_MAX,
                        $r->insurance_days_left ?? PHP_INT_MAX
                    ),
                ])
                ->sortBy('most_urgent_days_left')
                ->values();

            return ResponseHelper::SuccessResponse($regs, "Vehicles expiring within {$days} days", 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }
}
