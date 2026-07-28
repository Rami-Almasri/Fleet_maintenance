<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\DriverObservation;
use App\Services\DriverObservationService;
use Illuminate\Http\Request;

/**
 * Driver Handover Observations — the lightweight internal-note API. Drivers log what they noticed when a
 * car came back; an observation may raise an inspection request (which enters the normal review queue).
 * Deliberately spartan — no customer contact, no complaint escalation. See App\Services\DriverObservationService
 * and [[driver-observation-entity]].
 */
class DriverObservationController extends Controller
{
    public function __construct(private DriverObservationService $observations) {}

    /** GET /driver-observations — recent observations (optionally ?vehicle_id= / ?status=). */
    public function index(Request $request)
    {
        try {
            $query = DriverObservation::query()
                ->with(['vehicle:id,plate_no,make,model,year', 'driver:id,name', 'inspectionRequest:id,workflow_status'])
                ->orderByDesc('created_at')
                ->orderByDesc('id');

            if ($request->filled('vehicle_id')) {
                $query->where('vehicle_id', (int) $request->input('vehicle_id'));
            }
            if ($request->filled('status')) {
                $query->where('status', $request->input('status'));
            }

            $rows = $query->limit(200)->get()->map(fn (DriverObservation $o) => [
                'id'                    => $o->id,
                'vehicle_id'            => $o->vehicle_id,
                'plate'                 => $o->vehicle?->plate_no,
                'car'                   => trim(($o->vehicle?->make ?? '') . ' ' . ($o->vehicle?->model ?? '')) ?: null,
                'note'                  => $o->note,
                'photo'                 => $o->photo,
                'status'                => $o->status,
                'driver'                => $o->driver?->name,
                'inspection_request_id' => $o->inspection_request_id,
                'inspection_status'     => $o->inspectionRequest?->workflow_status,
                'created_at'            => $o->created_at?->toIso8601String(),
            ]);

            return ResponseHelper::SuccessResponse(['rows' => $rows->all()], 'Observations retrieved', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** POST /driver-observations — log an observation. Body: vehicle_id (req), note (req), photo?, contract_id?. */
    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'vehicle_id'  => ['required', 'integer'],
                'note'        => ['required', 'string', 'max:2000'],
                'photo'       => ['nullable', 'string'],
                'contract_id' => ['nullable', 'integer'],
            ]);
            $observation = $this->observations->create($data, $request->user());
            return ResponseHelper::SuccessResponse($observation->fresh(), 'Observation recorded', 201);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** POST /driver-observations/{observation}/request-inspection — raise an inspection request from it. */
    public function requestInspection(Request $request, DriverObservation $observation)
    {
        try {
            $observation = $this->observations->spawnInspection($observation, $request->user());
            return ResponseHelper::SuccessResponse($observation, 'Inspection requested', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** POST /driver-observations/{observation}/dismiss — nothing to act on. */
    public function dismiss(Request $request, DriverObservation $observation)
    {
        try {
            $observation = $this->observations->dismiss($observation);
            return ResponseHelper::SuccessResponse($observation, 'Observation dismissed', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
