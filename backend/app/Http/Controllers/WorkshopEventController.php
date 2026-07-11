<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Requests\StoreWorkshopEventRequest;
use App\Http\Requests\UpdateWorkshopEventRequest;
use App\Http\Resources\WorkshopEventResource;
use App\Models\Maintenance;
use App\Models\MaintenanceTombstone;
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

            // Live events + the "removed" ghosts the user tombstoned, merged into one
            // newest-first timeline (ghosts carry a Restore button on the frontend).
            $active = WorkshopEventResource::collection($this->service->index($filters))->toArray($request);
            $ghosts = $this->service->tombstonesFor($filters)->map(fn ($t) => $this->ghostShape($t))->all();

            $merged = array_merge($active, $ghosts);
            usort($merged, function ($a, $b) {
                // newest out_date first; rows with no out_date sink to the bottom
                $ad = $a['out_date'] ?? null;
                $bd = $b['out_date'] ?? null;
                if ($ad === $bd) {
                    return 0;
                }
                if ($ad === null) {
                    return 1;
                }
                if ($bd === null) {
                    return -1;
                }
                return strcmp($bd, $ad);
            });

            return ResponseHelper::SuccessResponse(
                $merged,
                'Workshop events retrieved successfully',
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** A tombstoned (deleted) sheet event, shaped like a workshop event for the list. */
    private function ghostShape(MaintenanceTombstone $tombstone): array
    {
        $d = $tombstone->display ?? [];

        return array_merge([
            'id'                   => null,
            'tombstoned'           => true,
            'tombstone_id'         => $tombstone->id,
            'editable'             => false,
            'vehicle_id'           => $tombstone->vehicle_id,
            'removed_at'           => optional($tombstone->updated_at)->toDateTimeString(),
        ], $d);
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
            return ResponseHelper::fromException($e);
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
            return ResponseHelper::fromException($e);
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
            return ResponseHelper::fromException($e);
        }
    }

    public function destroy(Request $request, Maintenance $workshopEvent)
    {
        try {
            // Hand-entered events are app-owned → truly removed. Sheet-synced events are
            // owned by the Google Sheet → we tombstone them (hide + skip on future syncs),
            // and they can be restored from the list.
            if ($workshopEvent->isManual()) {
                $this->service->destroy($workshopEvent);

                return ResponseHelper::SuccessResponse(null, 'Workshop event deleted successfully', 200);
            }

            $this->service->tombstone($workshopEvent, $request->user()?->id);

            return ResponseHelper::SuccessResponse(
                null,
                'Workshop event removed. It will stay hidden on future syncs — you can restore it from the events list.',
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** Bring back a tombstoned (deleted) sheet event. */
    public function restore(MaintenanceTombstone $tombstone)
    {
        try {
            $event = $this->service->restore($tombstone);

            return ResponseHelper::SuccessResponse(
                WorkshopEventResource::make($event),
                'Workshop event restored successfully',
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
