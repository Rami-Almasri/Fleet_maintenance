<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use App\Models\VehicleCheckRequirement;
use App\Services\VehicleCheckAnalyticsService;
use App\Services\VehicleCheckService;
use Illuminate\Http\Request;

/**
 * READ + one narrow write for the system-check obligation layer ([[VehicleCheckRequirement]]).
 *
 * DELIBERATELY NOT A RESOLUTION ENDPOINT. A check is answered exactly one way — on the inspection
 * report, at the Decide step, in the same transaction as the report itself
 * (MaintenanceWorkflowService::submitReport). Offering a second write path here would let a check be
 * closed without the inspection that closed it, which is precisely the accountability gap the entity
 * was built to shut. The only write below is `cancel`, which withdraws an obligation for a stated
 * SYSTEM reason and is permissioned accordingly.
 */
class VehicleCheckController extends Controller
{
    public function __construct(
        private VehicleCheckService $checks,
        private VehicleCheckAnalyticsService $analytics,
    ) {
    }

    /**
     * Everything ever asked of one car, newest first — the obligation half of its history.
     *
     * Includes CLOSED requirements on purpose: "we asked in May, an inspector looked, it was fine" is
     * the answer to most questions people bring to this screen, and it only exists because a clean
     * result is a row now.
     */
    public function forVehicle(Vehicle $vehicle, Request $request)
    {
        $rows = VehicleCheckRequirement::query()
            ->where('vehicle_id', $vehicle->id)
            ->when($request->boolean('open_only'), fn ($q) => $q->open())
            ->with('inspector:id,name')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        return response()->json([
            'data' => [
                'vehicle_id'   => $vehicle->id,
                'open'         => $rows->filter(fn ($r) => $r->isOpen())->count(),
                'requirements' => $rows->map(fn ($r) => $this->checks->present($r))->values(),
                'origin'       => 'Raised from this car\'s own service state by the Proactive Diagnostic '
                                . 'Monitor, or from an Intelligence recommendation. Each row is an obligation '
                                . 'somebody had to answer — not a suggestion and not a fault.',
            ],
        ]);
    }

    /** The full append-only chain for one requirement: raised → inspected → decided → resolved. */
    public function history(VehicleCheckRequirement $requirement)
    {
        return response()->json([
            'data' => [
                'requirement' => $this->checks->present($requirement),
                'events'      => $this->checks->history($requirement),
            ],
        ]);
    }

    /**
     * How often we ask, how often anyone looks, and what came of it.
     * See VehicleCheckAnalyticsService on why `override_rate` is a question, not a verdict.
     */
    public function analytics(Request $request)
    {
        $days = $request->filled('days') ? max(1, min(1095, (int) $request->input('days'))) : 180;

        return response()->json([
            'data' => $this->analytics->report($days) + [
                'overrides' => $this->analytics->overrides(10, $days),
                'events'    => $this->analytics->eventCounts($days),
            ],
        ]);
    }

    /**
     * Withdraw an obligation for a legitimate system/domain reason — a car leaving the fleet, a
     * duplicate raised by a rule change. The reason is mandatory and lands on the append-only trail,
     * so a cancelled check always says who withdrew it and why. It is never a way to clear a check
     * nobody wants to answer.
     */
    public function cancel(VehicleCheckRequirement $requirement, Request $request)
    {
        $data = $request->validate([
            'reason_code' => ['required', 'string', 'max:60'],
        ]);

        if (! $requirement->isOpen()) {
            return response()->json([
                'message' => 'This check is already closed.',
            ], 422);
        }

        $this->checks->cancel($requirement, $data['reason_code'], $request->user());

        return response()->json(['data' => $this->checks->present($requirement->refresh())]);
    }
}
