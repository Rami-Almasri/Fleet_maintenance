<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Requests\StoreWorkshopEventRequest;
use App\Http\Requests\UpdateWorkshopEventRequest;
use App\Http\Resources\WorkshopEventResource;
use App\Models\Maintenance;
use App\Services\WorkshopEventService;
use Illuminate\Http\Request;

/**
 * CRUD for workshop events — the dashboard owning the garage log (origin = 'manual'),
 * with the Google-Sheet import demoted to an optional, non-destructive sync.
 *
 * Reads return both hand-entered and synced events (one timeline); writes only ever
 * touch hand-entered ones — a synced sheet row is read-only here (its source is the sheet).
 */
class WorkshopEventController extends Controller
{
    public function __construct(private WorkshopEventService $service)
    {
    }

    /**
     * Workshop events, scoped by ?vehicle_id= (the car's whole garage log) or
     * ?contract_id= (only that contract's visit window — the problem & fix for one visit).
     * One of the two is required.
     */
    public function index(Request $request)
    {
        try {
            $filters = $request->only('vehicle_id', 'contract_id');
            if (empty($filters['vehicle_id']) && empty($filters['contract_id'])) {
                return ResponseHelper::FailureResponse(null, 'A vehicle_id or contract_id is required.', 422);
            }
            $events = $this->service->index($filters);

            return ResponseHelper::SuccessResponse(
                WorkshopEventResource::collection($events),
                'Workshop events retrieved successfully',
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    public function store(StoreWorkshopEventRequest $request)
    {
        try {
            $event = $this->service->store($request->validated());

            return ResponseHelper::SuccessResponse(
                WorkshopEventResource::make($event),
                'Workshop event created successfully',
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    public function show(Maintenance $workshopEvent)
    {
        try {
            $workshopEvent->load(['vendor', 'reason', 'vehicle:id,plate_no,make,model']);

            return ResponseHelper::SuccessResponse(
                WorkshopEventResource::make($workshopEvent),
                'Workshop event retrieved successfully',
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    public function update(UpdateWorkshopEventRequest $request, Maintenance $workshopEvent)
    {
        try {
            if (! $workshopEvent->isManual()) {
                return ResponseHelper::FailureResponse(null, 'Only hand-entered workshop events can be edited; this one is synced from the sheet.', 422);
            }
            $event = $this->service->update($request->validated(), $workshopEvent);

            return ResponseHelper::SuccessResponse(
                WorkshopEventResource::make($event),
                'Workshop event updated successfully',
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    public function destroy(Maintenance $workshopEvent)
    {
        try {
            if (! $workshopEvent->isManual()) {
                return ResponseHelper::FailureResponse(null, 'Only hand-entered workshop events can be deleted; this one is synced from the sheet.', 422);
            }
            $this->service->destroy($workshopEvent);

            return ResponseHelper::SuccessResponse(null, 'Workshop event deleted successfully', 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }
}
