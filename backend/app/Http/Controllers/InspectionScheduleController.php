<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Requests\StoreInspectionScheduleRequest;
use App\Http\Requests\UpdateInspectionScheduleRequest;
use App\Http\Resources\InspectionScheduleResource;
use App\Models\InspectionSchedule;
use Illuminate\Http\Request;

/**
 * Recurring SAFETY / OPERATIONS inspection schedules (Fleetio "Schedules"). CRUD plus a
 * `complete` action that rolls the last-inspected anchors forward and recomputes the next
 * due point. Live status (overdue | due_soon | ok) comes from the resource.
 */
class InspectionScheduleController extends Controller
{
    /** List schedules, newest-due first. Filter by ?vehicle_id, ?active, ?status. */
    public function index(Request $request)
    {
        try {
            $schedules = InspectionSchedule::query()
                ->with(['vehicle', 'assignee'])
                ->when($request->filled('vehicle_id'), fn ($q) => $q->where('vehicle_id', $request->integer('vehicle_id')))
                ->when($request->has('active'), fn ($q) => $q->where('active', $request->boolean('active')))
                ->orderByRaw('next_due_at IS NULL')   // dated rows first
                ->orderBy('next_due_at')
                ->get();

            // Status is derived (odometer/clock), so filter it in-memory after loading.
            if ($request->filled('status')) {
                $status = $request->string('status');
                $schedules = $schedules->filter(fn ($s) => $s->statusInfo()['status'] === (string) $status)->values();
            }

            return ResponseHelper::SuccessResponse(
                InspectionScheduleResource::collection($schedules),
                'Inspection schedules retrieved successfully',
                200
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    public function store(StoreInspectionScheduleRequest $request)
    {
        try {
            $schedule = new InspectionSchedule($request->validated());
            $schedule->active = $request->boolean('active', true);
            $schedule->recomputeNextDue();  // created_at null → anchors off "now"
            $schedule->save();

            return ResponseHelper::SuccessResponse(
                InspectionScheduleResource::make($schedule->load(['vehicle', 'assignee'])),
                'Inspection schedule created successfully',
                201
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    public function show(InspectionSchedule $inspectionSchedule)
    {
        try {
            return ResponseHelper::SuccessResponse(
                InspectionScheduleResource::make($inspectionSchedule->load(['vehicle', 'assignee'])),
                'Inspection schedule retrieved successfully',
                200
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    public function update(UpdateInspectionScheduleRequest $request, InspectionSchedule $inspectionSchedule)
    {
        try {
            $inspectionSchedule->fill($request->validated());
            $inspectionSchedule->recomputeNextDue();
            $inspectionSchedule->save();

            return ResponseHelper::SuccessResponse(
                InspectionScheduleResource::make($inspectionSchedule->load(['vehicle', 'assignee'])),
                'Inspection schedule updated successfully',
                200
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Mark this scheduled inspection as done now: stamp the anchors (time = now, meter =
     * given odometer or the car's current one) and roll the next-due point forward.
     */
    public function complete(Request $request, InspectionSchedule $inspectionSchedule)
    {
        try {
            $request->validate(['odometer' => 'nullable|integer|min:0']);

            $inspectionSchedule->last_inspected_at = now();
            $inspectionSchedule->last_inspected_odometer =
                $request->filled('odometer')
                    ? $request->integer('odometer')
                    : $inspectionSchedule->vehicle?->odometer;
            $inspectionSchedule->recomputeNextDue();
            $inspectionSchedule->save();

            return ResponseHelper::SuccessResponse(
                InspectionScheduleResource::make($inspectionSchedule->load(['vehicle', 'assignee'])),
                'Inspection logged; next due point advanced',
                200
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    public function destroy(InspectionSchedule $inspectionSchedule)
    {
        try {
            $inspectionSchedule->delete();

            return ResponseHelper::SuccessResponse(null, 'Inspection schedule deleted successfully', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
