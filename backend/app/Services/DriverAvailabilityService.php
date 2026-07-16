<?php

namespace App\Services;

use App\Models\LogisticsTask;

/**
 * Driver Availability — a driver's physical "can they take a move right now?" state. A driver is BUSY for
 * exactly one reason: they are physically transporting a vehicle from A to B at this moment. The instant
 * the car is handed over — dropped at the garage, or parked back at base — the driver is AVAILABLE again,
 * even though the maintenance ticket is still very much open (being repaired, awaiting parts / sign-off).
 * Repair is the workshop's job, not the driver's.
 *
 * SINGLE SOURCE OF TRUTH: the LogisticsTask. Driver Status is NEVER read off a MaintenanceTicket's
 * workflow_status — the two are deliberately decoupled. A driver becomes busy only once a real movement
 * task exists AND is assigned to them AND is in a transport phase; the maintenance lifecycle
 * (under_repair, waiting_parts, ready_for_pickup, ...) has no say in it. In particular a car sitting at
 * ready_for_pickup does NOT make anyone busy until a RETURN LogisticsTask is created and a driver assigned.
 *
 *   BUSY      ⇐ the driver owns an open LogisticsTask in a TRANSPORT phase — en_route (going to collect),
 *               picked_up (driving the car to its destination), or the return leg (to_base). See
 *               LogisticsTask::TRANSPORT_STATUSES / scopeInTransport().
 *   AVAILABLE ⇐ everything else: no task, a task parked at its destination (delivered / at garage), or
 *               only a maintenance ticket (repair / awaiting parts / ready-for-pickup with no return move).
 *
 * NB the maintenance DROP-OFF (park→garage) and RETURN (garage→park) legs must each be raised as a
 * LogisticsTask for the driver to read as busy while driving them — the workflow status alone is not
 * enough here by design. Self-healing: derived from live tasks, so it can't drift like a manual toggle.
 */
class DriverAvailabilityService
{
    /**
     * Resolve the driving state of the given users. Returns a map keyed by user id holding the BUSY
     * activity (kind / label / vehicle / destination / since); a user ABSENT from the map is AVAILABLE.
     * One grouped query, no N+1.
     */
    public function busyByUser(array $userIds): array
    {
        $userIds = array_values(array_filter($userIds));
        if (empty($userIds)) {
            return [];
        }

        $busy = [];

        // The ONLY source: an open movement task in a TRANSPORT phase (the car is with the driver, in
        // motion). A task parked at its destination is not transport, so scopeInTransport() excludes it —
        // the driver is free. Newest phase change first, so a multi-task driver shows their latest leg.
        $tasks = LogisticsTask::inTransport()
            ->whereIn('assigned_to_id', $userIds)
            ->orderByDesc('status_changed_at')
            ->get()
            ->groupBy('assigned_to_id');

        foreach ($tasks as $uid => $rows) {
            $t = $rows->first();
            $busy[$uid] = [
                'kind'        => 'move',
                'label'       => $t->phaseLabel(),
                'vehicle'     => $t->vehicle_plate ?: $t->vehicle_label,
                'destination' => $t->destination,
                'since'       => optional($t->status_changed_at ?: $t->dispatched_at)->toIso8601String(),
                'last_status' => $t->last_status,
                'count'       => $rows->count(),
            ];
        }

        return $busy;
    }

    /** Convenience: the plain busy/available verdict for one user id. */
    public function statusFor(int $userId): string
    {
        return isset($this->busyByUser([$userId])[$userId]) ? 'busy' : 'available';
    }
}
