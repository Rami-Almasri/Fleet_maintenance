<?php

namespace App\Services;

use App\Models\DriverObservation;
use App\Models\Maintenance;
use App\Models\User;
use App\Models\Vehicle;
use App\Exceptions\WorkflowTransitionException;
use Illuminate\Support\Facades\DB;

/**
 * Driver Handover Observation — the LIGHTWEIGHT path. A driver receives a car back and records what they
 * noticed (or what the renter casually mentioned). This is NOT customer support: no customer contact, no
 * decision fork, no Abu-Maroof complaint alert. The only escalation available is to raise an inspection
 * request — which lands in the Controllers' normal inspection-review queue (reusing requestInspection),
 * keeping the short lifecycle the design calls for:
 *
 *     observation → inspection review → inspection → maintenance (if needed) → closed
 *
 * The complaint counterpart with the rich lifecycle is ComplaintWorkflowService. See
 * [[driver-observation-entity]].
 */
class DriverObservationService
{
    public function __construct(private MaintenanceWorkflowService $workflow) {}

    /** Record an observation. Purely a log entry — nothing is escalated unless spawnInspection() is called. */
    public function create(array $data, User $driver): DriverObservation
    {
        $vehicleId = (int) ($data['vehicle_id'] ?? 0);
        $vehicle   = $vehicleId ? Vehicle::find($vehicleId) : null;
        if (! $vehicle) {
            throw new WorkflowTransitionException('A valid vehicle is required to log an observation.', ['field' => 'vehicle_id']);
        }
        $note = is_string($data['note'] ?? null) ? trim($data['note']) : '';
        if ($note === '') {
            throw new WorkflowTransitionException('Describe what you noticed.', ['field' => 'note']);
        }

        // Best-effort: the rental the car just came back from (context only — no customer follow-up).
        $contractId = $data['contract_id'] ?? null;
        if (! $contractId) {
            $contractId = $vehicle->contracts()->where('contract_type', 'C')->latest('id')->value('id');
        }

        return DriverObservation::create([
            'vehicle_id'  => $vehicleId,
            'driver_id'   => $driver->id,
            'contract_id' => $contractId,
            'note'        => $note,
            'photo'       => is_string($data['photo'] ?? null) ? $data['photo'] : null,
            'status'      => DriverObservation::STATUS_OPEN,
        ]);
    }

    /**
     * Raise an inspection request from an observation. Reuses the Driver inspection-request path, so it
     * enters the Controllers' review queue (pending_review) exactly like any other flagged car. Links the
     * spawned ticket back and marks the observation. Idempotent.
     */
    public function spawnInspection(DriverObservation $observation, User $actor): DriverObservation
    {
        if ($observation->inspection_request_id) {
            return $observation->fresh();
        }

        return DB::transaction(function () use ($observation, $actor) {
            $ticket = $this->workflow->requestInspection([
                'vehicle_id'         => $observation->vehicle_id,
                'trigger_reason'     => Maintenance::TRIGGER_PERIODIC,
                'customer_complaint' => $observation->note,
            ], $actor);

            $observation->inspection_request_id = $ticket->id;
            $observation->status = DriverObservation::STATUS_INSPECTION_REQUESTED;
            $observation->save();

            return $observation->fresh();
        });
    }

    /** Dismiss — nothing to act on. */
    public function dismiss(DriverObservation $observation): DriverObservation
    {
        $observation->status = DriverObservation::STATUS_DISMISSED;
        $observation->save();
        return $observation->fresh();
    }
}
