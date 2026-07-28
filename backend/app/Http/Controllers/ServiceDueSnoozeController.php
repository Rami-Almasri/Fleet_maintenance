<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\ServiceDueSnooze;
use App\Models\Vehicle;
use App\Services\MaintenanceOpsCenterService;
use Illuminate\Http\Request;

/**
 * Snooze / un-snooze a vehicle on the Maintenance Operations Center. A manager's deliberate "not now":
 * it hides the row from the actionable board until the snooze elapses, and NEVER touches the odometer,
 * interval or any service record (the km rule still holds). Write-gated (maintenance.initiate|manage).
 */
class ServiceDueSnoozeController extends Controller
{
    /** Snooze a vehicle. Supersedes any prior live snooze on the same car (latest wins). */
    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'vehicle_id'    => 'required|integer|exists:vehicles,id',
                'service_type'  => 'nullable|string|max:80',
                'snoozed_until' => 'nullable|date|after_or_equal:today',
                'reason'        => 'nullable|string|max:500',
            ]);

            $user = $request->user();

            // Retire any existing live snooze on this car — we keep the row, not delete it.
            ServiceDueSnooze::where('vehicle_id', $data['vehicle_id'])->where('active', true)->update(['active' => false]);

            $snooze = ServiceDueSnooze::create([
                'vehicle_id'      => $data['vehicle_id'],
                'service_type'    => $data['service_type'] ?? null,
                'snoozed_until'   => $data['snoozed_until'] ?? null,
                'reason'          => $data['reason'] ?? null,
                'active'          => true,
                'snoozed_by'      => $user?->id,
                'snoozed_by_name' => $user?->name,
            ]);

            MaintenanceOpsCenterService::flush();

            return ResponseHelper::SuccessResponse($snooze, 'Vehicle snoozed successfully', 201);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** Un-snooze a vehicle — the row returns to the actionable board immediately. */
    public function release(Vehicle $vehicle)
    {
        try {
            ServiceDueSnooze::where('vehicle_id', $vehicle->id)->where('active', true)->update(['active' => false]);

            MaintenanceOpsCenterService::flush();

            return ResponseHelper::SuccessResponse(['vehicle_id' => $vehicle->id], 'Vehicle un-snoozed successfully', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
