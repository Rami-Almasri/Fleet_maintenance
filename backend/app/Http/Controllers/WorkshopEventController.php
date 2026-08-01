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
            // EVERY delete is a tombstone now — hand-entered events included.
            //
            // This used to hard-delete manual events on the reasoning that they are "app-owned" and so
            // ours to destroy. That had it backwards: a sheet event can be re-imported from Google
            // Sheets, a hand-entered one exists nowhere else. Destroying it also NULLed
            // `vehicle_log_events.maintenance_id` for every event it owned (`nullOnDelete`) — which is
            // how 78.8% of the vehicle timeline ended up detached from any ticket while the rows stayed
            // present, so the history still read as complete. See the maintenance_ref migration.
            $this->service->tombstone($workshopEvent, $request->user()?->id);

            return ResponseHelper::SuccessResponse(
                null,
                $workshopEvent->isManual()
                    ? 'Workshop event removed. Nothing was destroyed — restore it any time from the events list.'
                    : 'Workshop event removed and future syncs will skip it. Nothing was destroyed — restore it any time from the events list.',
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

            // Only the event row itself comes back — anything that cascaded away at delete time is
            // rebuilt from its own projection, never from the tombstone. Say so, rather than letting
            // "restored successfully" imply the ticket's full history returned with it.
            return ResponseHelper::SuccessResponse(
                WorkshopEventResource::make($event),
                match (true) {
                    // Pre-soft-delete tombstone: the row really was destroyed back then, so only its
                    // own columns come back. Say so — the user must not assume the faults returned.
                    $event->restored_with_new_id  => 'Workshop event restored under a NEW id (the original was taken). Its faults, costs and timeline links were destroyed when it was deleted and could not be recovered.',
                    $event->restored_from_payload => 'Workshop event restored from a pre-soft-delete backup — the row is back, but its faults, costs and timeline links were destroyed at delete time.',
                    default                       => 'Workshop event restored with its faults, costs and timeline intact.',
                },
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
