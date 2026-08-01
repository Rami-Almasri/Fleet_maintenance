<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Maintenance;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleLogEvent;
use Illuminate\Support\Carbon;

/**
 * Writes the vehicle historical event log — the audit trail behind the Maintenance Workflow.
 *
 * Centralised on purpose: the MaintenanceWorkflowService calls record() on every transition and
 * this service owns the two things that must be consistent across every event —
 *   1. the active maintenance-contract look-up (one query, one rule), and
 *   2. how an event is tagged (inspector vs garage) and described.
 *
 * It appends to `vehicle_log_events` only. It NEVER touches `maintenances`, so the visit row stays
 * the single source of truth for cost / board placement and nothing is double-counted.
 */
class VehicleLogService
{
    /**
     * Append one event to a vehicle's log for a workflow transition.
     *
     * Resolves the vehicle's currently-open maintenance contract itself (the centralised look-up),
     * derives the inspector/garage tag from the event type, and persists. Best-effort by design:
     * the audit trail must never break a transition, so a logging failure is swallowed (returns null)
     * rather than rolling back the state change the user just made.
     *
     * `$actor` is nullable so SYSTEM-generated events (e.g. the mileage scanner's auto-routine task)
     * can be logged with no human behind them — actor_id is nullable and reads as "System".
     *
     * @param array{description?:?string, meta?:array, source_tag?:?string, occurred_at?:?\DateTimeInterface} $opts
     */
    public function record(Maintenance $ticket, string $eventType, ?User $actor = null, array $opts = []): ?VehicleLogEvent
    {
        if (! $ticket->vehicle_id) {
            return null;
        }

        try {
            return VehicleLogEvent::create([
                'vehicle_id'         => $ticket->vehicle_id,
                'maintenance_id'     => $ticket->id,
                'maintenance_ref'    => $ticket->id,   // FK-free twin: survives the ticket's deletion
                'linked_contract_id' => $this->activeMaintenanceContractId($ticket->vehicle_id),
                'event_type'         => $eventType,
                'source_tag'         => $opts['source_tag']
                                        ?? VehicleLogEvent::SOURCE_BY_EVENT[$eventType]
                                        ?? Maintenance::FINDING_GARAGE,
                'workflow_status'    => $ticket->workflow_status,
                'description'        => $opts['description'] ?? null,
                'meta'               => $opts['meta'] ?? null,
                'actor_id'           => $actor?->id,
                'occurred_at'        => $opts['occurred_at'] ?? Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            report($e);   // never let an audit-log write sink a real workflow transition
            return null;
        }
    }

    /**
     * Append one event scoped to a single FAULT (maintenance_task). Same append-only audit trail as
     * record(), but stamped with maintenance_task_id so the per-fault timeline (identified → assigned →
     * transferred → resolved) is a single filtered read. Best-effort: a logging failure never breaks the
     * task transition that triggered it.
     *
     * @param array{description?:?string, meta?:array, source_tag?:?string, occurred_at?:?\DateTimeInterface} $opts
     */
    public function recordTask(\App\Models\MaintenanceTask $task, string $eventType, ?User $actor = null, array $opts = []): ?VehicleLogEvent
    {
        $vehicleId = $task->vehicle_id ?? $task->maintenance?->vehicle_id;
        if (! $vehicleId) {
            return null;
        }

        try {
            return VehicleLogEvent::create([
                'vehicle_id'          => $vehicleId,
                'maintenance_id'      => $task->maintenance_id,
                'maintenance_ref'     => $task->maintenance_id,   // FK-free twin (see record())
                'maintenance_task_id' => $task->id,
                'linked_contract_id'  => $this->activeMaintenanceContractId($vehicleId),
                'event_type'          => $eventType,
                'source_tag'          => $opts['source_tag']
                                         ?? VehicleLogEvent::SOURCE_BY_EVENT[$eventType]
                                         ?? Maintenance::FINDING_GARAGE,
                'workflow_status'     => $task->maintenance?->workflow_status,
                'description'         => $opts['description'] ?? null,
                'meta'                => $opts['meta'] ?? null,
                'actor_id'            => $actor?->id,
                'occurred_at'         => $opts['occurred_at'] ?? Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            report($e); // never let an audit-log write sink a real task transition
            return null;
        }
    }

    /**
     * Append one event scoped to the VEHICLE itself — no maintenance ticket required. This is how the
     * Pre-Delivery Readiness Gate and the Visual Condition Grade write to the same append-only trail
     * (who confirmed the car ready / graded it, when, and — via `meta` — which pillars were checked).
     *
     * Same best-effort contract as record(): a logging failure never breaks the action that triggered
     * it. `source_tag` defaults to 'readiness' (a distinct audit bucket from inspector/garage).
     *
     * @param array{description?:?string, meta?:array, source_tag?:?string, occurred_at?:?\DateTimeInterface} $opts
     */
    public function recordVehicle(Vehicle $vehicle, string $eventType, ?User $actor = null, array $opts = []): ?VehicleLogEvent
    {
        try {
            return VehicleLogEvent::create([
                'vehicle_id'         => $vehicle->id,
                'maintenance_id'     => null,
                'linked_contract_id' => $this->activeMaintenanceContractId($vehicle->id),
                'event_type'         => $eventType,
                'source_tag'         => $opts['source_tag']
                                        ?? VehicleLogEvent::SOURCE_BY_EVENT[$eventType]
                                        ?? 'readiness',
                'workflow_status'    => null,
                'description'        => $opts['description'] ?? null,
                'meta'               => $opts['meta'] ?? null,
                'actor_id'           => $actor?->id,
                'occurred_at'        => $opts['occurred_at'] ?? Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
            return null;
        }
    }

    /**
     * The vehicle's currently-open type-'U' (maintenance) contract id, newest out_date first, or null.
     * THE single source of truth for "which contract is this vehicle's maintenance event linked to":
     * best-effort, never auto-creates a contract (OfficeManager owns contract creation; we only link).
     */
    public function activeMaintenanceContractId(?int $vehicleId): ?int
    {
        if (! $vehicleId) {
            return null;
        }

        return Contract::where('contract_type', 'U')
            ->where('vehicle_id', $vehicleId)
            ->currentlyOpen()
            ->orderByDesc('out_date')
            ->value('id');
    }
}
