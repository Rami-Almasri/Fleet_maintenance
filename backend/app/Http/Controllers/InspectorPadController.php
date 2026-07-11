<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Resources\MaintenanceWorkflowResource;
use App\Models\InspectorPadFlag;
use App\Models\Maintenance;
use App\Models\Vehicle;
use App\Services\MaintenanceWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Inspector's Pad — the Inspector (Abu Maroof) flags cars with issue keywords + observations at any
 * time (store / index / destroy), and picks a car up for maintenance (pickup). Pick-up is the only
 * write that mints a ticket: it is odometer-gated and folds the car's pending flags into the new
 * ticket. See MaintenanceWorkflowService::pickupIntoMaintenance and the InspectorPadFlag model.
 */
class InspectorPadController extends Controller
{
    public function __construct(private MaintenanceWorkflowService $workflow)
    {
    }

    /**
     * The pending flags board — every un-consumed flag, newest first, optionally scoped to one car
     * (?vehicle_id=). The frontend groups them per vehicle.
     */
    public function index(Request $request)
    {
        try {
            $query = InspectorPadFlag::pending()
                ->with(['vehicle:id,plate_no,make,model', 'creator:id,name'])
                ->latest('id');

            if ($request->filled('vehicle_id')) {
                $query->where('vehicle_id', (int) $request->input('vehicle_id'));
            }

            $flags = $query->get()->map(fn (InspectorPadFlag $f) => $this->present($f));

            return ResponseHelper::SuccessResponse($flags, 'Pending flags', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** Log a new flag against a car — a keyword, an observation, or both (at least one is required). */
    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'vehicle_id'  => ['required', 'integer', Rule::exists('vehicles', 'id')],
                'keyword'     => ['nullable', 'string', 'max:120'],
                'observation' => ['nullable', 'string', 'max:2000'],
                'severity'    => ['nullable', Rule::in(Maintenance::FAULT_SEVERITIES)],
            ]);

            $keyword     = trim((string) ($data['keyword'] ?? ''));
            $observation = trim((string) ($data['observation'] ?? ''));
            if ($keyword === '' && $observation === '') {
                throw ValidationException::withMessages([
                    'keyword' => 'Add an issue keyword or an observation.',
                ]);
            }

            $flag = InspectorPadFlag::create([
                'vehicle_id'  => $data['vehicle_id'],
                'keyword'     => $keyword !== '' ? $keyword : null,
                'observation' => $observation !== '' ? $observation : null,
                'severity'    => $data['severity'] ?? null,
                'status'      => InspectorPadFlag::STATUS_PENDING,
                'created_by'  => $request->user()->id,
            ]);

            $flag->load(['vehicle:id,plate_no,make,model', 'creator:id,name']);

            return ResponseHelper::SuccessResponse($this->present($flag), 'Flag added', 201);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Remove a pending flag (a mistaken/duplicate note). A flag already consumed into a ticket is an
     * audit trail and cannot be deleted here.
     */
    public function destroy(InspectorPadFlag $flag)
    {
        try {
            if ($flag->status !== InspectorPadFlag::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'flag' => 'This flag was already picked up into a ticket and cannot be removed.',
                ]);
            }

            $flag->delete();

            return ResponseHelper::SuccessResponse(null, 'Flag removed', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Pick Up for maintenance — the odometer-gated intake. Mints a ticket carrying the car's pending
     * flags and moves it to Maintenance. A ticket is NEVER created without the odometer reading.
     */
    public function pickup(Request $request, Vehicle $vehicle)
    {
        try {
            $data = $request->validate([
                'odometer' => ['required', 'integer', 'min:1'],
                'note'     => ['nullable', 'string', 'max:2000'],
            ]);

            $ticket = $this->workflow->pickupIntoMaintenance(
                $vehicle,
                (int) $data['odometer'],
                $data['note'] ?? null,
                $request->user()
            );

            return ResponseHelper::SuccessResponse(
                MaintenanceWorkflowResource::make($ticket),
                'Picked up for maintenance — management notified to assign a garage',
                201
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** Shape one flag for the client (flat, with the car label + author). */
    private function present(InspectorPadFlag $flag): array
    {
        return [
            'id'          => $flag->id,
            'vehicle_id'  => $flag->vehicle_id,
            'vehicle'     => $flag->vehicle ? [
                'id'       => $flag->vehicle->id,
                'plate_no' => $flag->vehicle->plate_no,
                'make'     => $flag->vehicle->make,
                'model'    => $flag->vehicle->model,
            ] : null,
            'keyword'     => $flag->keyword,
            'observation' => $flag->observation,
            'severity'    => $flag->severity,
            'label'       => $flag->label(),
            'status'      => $flag->status,
            'created_by'  => $flag->creator->name ?? null,
            'created_at'  => optional($flag->created_at)->toIso8601String(),
        ];
    }
}
