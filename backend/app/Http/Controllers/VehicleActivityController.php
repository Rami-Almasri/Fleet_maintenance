<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\Vehicle;
use App\Services\ActivityFeedService;
use App\Services\VehicleLifeStreamService;
use Illuminate\Http\Request;

/**
 * The Activity Audit Trail read API — the "total transparency" surface.
 *
 *  - vehicle() : one car's full history, newest-first (the Vehicle History Timeline).
 *  - feed()    : the fleet-wide Global Activity Feed with action-category / time-window / actor
 *                filters (the manager view — "show me all pre-rental checks in the last 24 hours").
 *
 * Both are pure reads over ActivityFeedService, which unions vehicle_log_events + logistics_task_events
 * + inspection_records into one shape. No new store, no writes here.
 */
class VehicleActivityController extends Controller
{
    public function __construct(private ActivityFeedService $activity)
    {
    }

    /** One vehicle's unified timeline (every source), newest first. */
    public function vehicle(Vehicle $vehicle, Request $request)
    {
        try {
            $limit = max(1, min(500, (int) $request->integer('limit', 300)));
            $events = $this->activity->forVehicle($vehicle->id, $limit);

            return ResponseHelper::SuccessResponse([
                'vehicle' => [
                    'id'    => $vehicle->id,
                    'plate' => $vehicle->plate_no,
                    'model' => trim(($vehicle->make ?? '') . ' ' . ($vehicle->model ?? '')),
                ],
                'events' => $events,
                'count'  => count($events),
            ], 'Vehicle activity timeline retrieved', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * The fleet status strip for the Vehicle Life-Stream page — one live "where is it now" chip per car
     * (In Garage / Waiting for Parts / Out for Delivery / With Customer / Available) plus per-chip counts.
     */
    public function fleetStatus(VehicleLifeStreamService $lifeStream)
    {
        try {
            return ResponseHelper::SuccessResponse(
                $lifeStream->fleet(),
                'Fleet life status retrieved',
                200
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** The fleet-wide manager feed, filtered + paginated. */
    public function feed(Request $request)
    {
        try {
            $data = $request->validate([
                'category'   => 'nullable|string|in:' . implode(',', ActivityFeedService::CATEGORIES),
                'vehicle_id' => 'nullable|integer|exists:vehicles,id',
                'actor_id'   => 'nullable|integer|exists:users,id',
                'q'          => 'nullable|string|max:120',
                'from'       => 'nullable|date',
                'to'         => 'nullable|date',
                'limit'      => 'nullable|integer|min:1|max:200',
                'page'       => 'nullable|integer|min:1',
            ]);

            return ResponseHelper::SuccessResponse(
                $this->activity->global($data),
                'Activity feed retrieved',
                200
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
