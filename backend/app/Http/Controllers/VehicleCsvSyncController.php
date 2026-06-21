<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Services\VehicleCsvSyncService;
use Illuminate\Http\Request;

class VehicleCsvSyncController extends Controller
{
    /**
     * Read-only SHEET ↔ API side-by-side: the Faster sheet cars matched to the API by VIN,
     * with every field that differs between the two sources. Writes nothing.
     *
     * The result is cached (both source reads are slow); pass ?fresh=1 to recompute now.
     */
    public function diff(Request $request, VehicleCsvSyncService $sync)
    {
        try {
            $fresh = $request->boolean('fresh');

            return ResponseHelper::SuccessResponse(
                $sync->sheetVsApi($fresh),
                $fresh ? 'Sheet vs API comparison recomputed' : 'Sheet vs API comparison computed',
                200
            );
        } catch (\Throwable $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }
}
