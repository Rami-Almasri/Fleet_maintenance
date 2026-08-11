<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\LogisticsTask;
use App\Models\LogisticsTaskEvent;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The single place a Logistics Dispatch is raised, claimed, walked through its round trip and closed —
 * the data-driven replacement for the WhatsApp relay. One transaction per action keeps the side-effects
 * in lock-step (the task row, the vehicle's live "where is it?" mirror, the audit trail, the alerts):
 *
 *   dispatch()  → a coordinator raises the request. Unassigned → it lands in the driver POOL (every
 *                 driver is pinged). Pre-assigned → it goes straight to that driver as en_route.
 *   claim()     → the first driver to take a pooled request LOCKS it to themselves (→ en_route) and the
 *                 ping vanishes from the other drivers' bells. The coordinator is told who took it.
 *   advance()   → the assignee steps the trip along (Picked Up → Delivered → Returned/Arrived). The
 *                 "Returned" step captures a GPS fix proving the car is physically back at base.
 *   cancel()    → a coordinator calls the move off.
 *
 * Every status change is stamped into logistics_task_events (who + when, GPS on return) and pushes an
 * automatic update to the coordinator who created the task — no manual follow-up. The vehicle's
 * operational_status / transit_destination are a denormalised mirror so the grid answers "where is the
 * car?" from the row alone; completed_at is the canonical "is the car still out?" flag.
 */
class LogisticsDispatchService
{
    public function __construct(
        private NotificationScanner $notifier,
        private OperationsService $operations,
    ) {}

    /**
     * Raise a move for a vehicle. Guards: the car must be free (no open rental / maintenance) and not
     * already on a move. When no driver is named the task is POOLED — every driver is pinged and the
     * first to claim it owns it. When a driver is named it skips the pool and lands in their queue.
     */
    public function dispatch(Vehicle $vehicle, array $data, User $actor): LogisticsTask
    {
        $destination = trim((string) ($data['destination'] ?? ''));
        if ($destination === '') {
            throw new RuntimeException('A destination is required.');
        }

        // Don't move a car that's out on rent or in the garage — its open contract owns it.
        if (Contract::currentlyOpen()->where('vehicle_id', $vehicle->id)->whereIn('contract_type', ['C', 'U'])->exists()) {
            throw new RuntimeException('This car is on an open rental/maintenance contract and can\'t be dispatched.');
        }
        // One move at a time.
        if (LogisticsTask::open()->where('vehicle_id', $vehicle->id)->exists()) {
            throw new RuntimeException('This car is already on a dispatch. Close that move first.');
        }

        $assignee = isset($data['assigned_to_id']) ? User::find($data['assigned_to_id']) : null;

        return DB::transaction(function () use ($vehicle, $destination, $assignee, $actor, $data) {
            $task = LogisticsTask::create([
                'vehicle_id'        => $vehicle->id,
                'vehicle_plate'     => $vehicle->plate_no,
                'vehicle_label'     => trim($vehicle->make . ' ' . $vehicle->model) ?: null,
                'maintenance_id'    => $data['maintenance_id'] ?? null,
                'destination'       => $destination,
                'round_trip'        => (bool) ($data['round_trip'] ?? false),
                'assigned_to_id'    => $assignee?->id,
                'assigned_to_name'  => $assignee?->name,
                'assigned_by_id'    => $actor->id,
                'assigned_by_name'  => $actor->name ?: $actor->email,
                // Pre-assigned moves are already "claimed" (en_route); pooled ones await a claim.
                'status'            => $assignee ? LogisticsTask::STATUS_EN_ROUTE : LogisticsTask::STATUS_DISPATCHED,
                'status_changed_at' => now(),
                'notes'             => $data['notes'] ?? null,
                'dispatched_at'     => now(),
                'claimed_at'        => $assignee ? now() : null,
            ]);

            // Mirror the live movement onto the vehicle so the grid shows "In Transit to {destination}".
            $vehicle->update([
                'operational_status'  => 'in_transit',
                'transit_destination' => $destination,
            ]);

            $this->recordEvent($task, LogisticsTaskEvent::EVENT_DISPATCHED, $actor, [
                'to_status' => $task->status,
                'note'      => $assignee ? ('Assigned to ' . $assignee->name) : 'Pooled — awaiting a driver to claim',
            ]);

            if ($assignee) {
                // Straight into the named driver's My Queue + bell.
                $this->recordEvent($task, LogisticsTaskEvent::EVENT_CLAIMED, $actor, [
                    'to_status' => LogisticsTask::STATUS_EN_ROUTE,
                    'note'      => 'Pre-assigned by ' . ($actor->name ?: 'a coordinator'),
                ]);
                $this->notifier->notifyUser($assignee, $this->dispatchPayload($task, $vehicle, false));
            } else {
                // "Up for grabs" → ping the whole driver pool; the claim dismisses it for the others.
                $this->notifier->notifyByPermission('logistics.claim', $this->dispatchPayload($task, $vehicle, true), $actor->id);
            }

            return $task;
        });
    }

    /**
     * Raise the driver COLLECTION task for an oil recall — go to the customer, collect the car,
     * bring it to the workshop. This is the sanctioned exception to dispatch()'s open-rental guard:
     * the whole point of a recall is that the car IS on an open rental. Differences from a plain
     * dispatch, all deliberate:
     *
     *   - the vehicle's operational_status is NOT touched — the customer still holds the car, and
     *     nothing may claim it is "in transit" until a driver has actually picked it up;
     *   - the task is linked to the oil follow-up inspection request via maintenance_id, so the
     *     chain decision → request → collection → arrival stays traceable;
     *   - pooled by default (any logistics.claim driver may take it), same claim/notify machinery.
     *
     * Idempotent per vehicle: an existing open move is returned untouched instead of duplicated.
     */
    public function dispatchOilRecallCollection(
        Vehicle $vehicle,
        ?int $maintenanceId,
        string $notes,
        User $actor,
        string $destination = 'Workshop',
    ): LogisticsTask {
        $existing = LogisticsTask::open()->where('vehicle_id', $vehicle->id)->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($vehicle, $maintenanceId, $notes, $actor, $destination) {
            $task = LogisticsTask::create([
                'vehicle_id'        => $vehicle->id,
                'vehicle_plate'     => $vehicle->plate_no,
                'vehicle_label'     => trim($vehicle->make . ' ' . $vehicle->model) ?: null,
                'maintenance_id'    => $maintenanceId,
                // What kind of job this is, as a fact rather than a phrase in the notes. It is what
                // puts the car under "Cars to collect from customers" in the driver's own queue, and
                // what makes the odometer compulsory at the doorstep.
                'purpose'           => LogisticsTask::PURPOSE_CUSTOMER_COLLECTION,
                // Where the driver is taking it — our parking or a garage. The driver must be told
                // the actual destination, not a generic "Workshop", or he drives to the wrong place.
                'destination'       => $destination,
                'round_trip'        => false,
                'assigned_by_id'    => $actor->id,
                'assigned_by_name'  => $actor->name ?: $actor->email,
                'status'            => LogisticsTask::STATUS_DISPATCHED,
                'status_changed_at' => now(),
                'notes'             => $notes,
                'dispatched_at'     => now(),
            ]);

            $this->recordEvent($task, LogisticsTaskEvent::EVENT_DISPATCHED, $actor, [
                'to_status' => $task->status,
                'note'      => 'Oil recall — collect from the customer. Pooled, awaiting a driver to claim',
            ]);

            $this->notifier->notifyByPermission('logistics.claim', $this->dispatchPayload($task, $vehicle, true), $actor->id);

            return $task;
        });
    }

    /**
     * Raise the physical-transport task for a maintenance GARAGE TRANSFER — the custodian who will drive
     * the car from the old garage to the new one. Unlike a plain dispatch() this is intentionally allowed
     * while the car is on an open rental/maintenance contract (the whole point is that the car is IN
     * maintenance), so the contract guard is skipped; and it SUPERSEDES any stale open move on the car
     * (a re-transfer) instead of refusing. The custodian is pre-assigned (→ en_route) and gets a direct
     * "Action Required" alert, so the move lands in their My Queue and drives the ticket's live position.
     */
    public function dispatchForMaintenanceTransfer(Vehicle $vehicle, string $destination, int $assigneeId, int $maintenanceId, User $actor): LogisticsTask
    {
        $destination = trim($destination) ?: 'the new garage';
        $assignee = User::findOrFail($assigneeId);

        return DB::transaction(function () use ($vehicle, $destination, $assignee, $maintenanceId, $actor) {
            // Supersede any stale open move on this car so the one-move-per-vehicle invariant holds.
            foreach (LogisticsTask::open()->where('vehicle_id', $vehicle->id)->get() as $stale) {
                $this->forceClose($stale, $actor, LogisticsTask::STATUS_CANCELLED, LogisticsTaskEvent::EVENT_CANCELLED);
            }

            $task = LogisticsTask::create([
                'vehicle_id'        => $vehicle->id,
                'vehicle_plate'     => $vehicle->plate_no,
                'vehicle_label'     => trim($vehicle->make . ' ' . $vehicle->model) ?: null,
                'maintenance_id'    => $maintenanceId,
                'destination'       => $destination,
                'round_trip'        => false, // a one-way garage→garage hand-off
                'assigned_to_id'    => $assignee->id,
                'assigned_to_name'  => $assignee->name ?: $assignee->email,
                'assigned_by_id'    => $actor->id,
                'assigned_by_name'  => $actor->name ?: $actor->email,
                'status'            => LogisticsTask::STATUS_EN_ROUTE, // pre-assigned → already claimed
                'status_changed_at' => now(),
                'notes'             => 'Garage transfer — take the car to ' . $destination,
                'dispatched_at'     => now(),
                'claimed_at'        => now(),
            ]);

            $vehicle->update(['operational_status' => 'in_transit', 'transit_destination' => $destination]);

            $this->recordEvent($task, LogisticsTaskEvent::EVENT_DISPATCHED, $actor, [
                'to_status' => $task->status,
                'note'      => 'Garage transfer assigned to ' . ($assignee->name ?: 'a driver'),
            ]);
            $this->recordEvent($task, LogisticsTaskEvent::EVENT_CLAIMED, $actor, [
                'to_status' => LogisticsTask::STATUS_EN_ROUTE,
                'note'      => 'Pre-assigned by ' . ($actor->name ?: 'a coordinator'),
            ]);
            $this->notifier->notifyUser($assignee, $this->dispatchPayload($task, $vehicle, false));

            return $task;
        });
    }

    /**
     * Raise the movement task for an INTERNAL maintenance leg — the driver taking the car FROM our park TO
     * the garage (drop-off, from dispatch()) or FROM the garage back to base (return, from collectFromGarage()).
     * Unlike dispatchForMaintenanceTransfer() (a garage→garage hand-off that starts en_route to COLLECT) this
     * is the driver who ALREADY has the car in hand — they just picked it up — so it opens straight at
     * `picked_up`. It exists so Driver Availability, which reads LogisticsTask ONLY, sees the driver as busy
     * while they physically move the car, and so the ticket's live position reads "In Transit". It is closed
     * by the matching arrival step (markUnderRepair / arriveAtPark) via complete().
     *
     * Deliberately minimal vs a real dispatch: it does NOT touch the vehicle's operational_status /
     * transit_destination mirror (the maintenance cascade owns that during a repair) and raises no pool ping
     * (the driver is already on the job). Supersedes any stale open move on the car to hold the
     * one-move-per-vehicle invariant.
     */
    public function raiseMaintenanceLeg(Vehicle $vehicle, string $destination, int $assigneeId, int $maintenanceId, User $actor, ?string $notes = null): LogisticsTask
    {
        $destination = trim($destination) ?: 'the garage';
        $assignee    = User::findOrFail($assigneeId);

        return DB::transaction(function () use ($vehicle, $destination, $assignee, $maintenanceId, $actor, $notes) {
            // Supersede any stale open move on this car so the one-move-per-vehicle invariant holds.
            foreach (LogisticsTask::open()->where('vehicle_id', $vehicle->id)->get() as $stale) {
                $this->forceClose($stale, $actor, LogisticsTask::STATUS_CANCELLED, LogisticsTaskEvent::EVENT_CANCELLED);
            }

            $task = LogisticsTask::create([
                'vehicle_id'        => $vehicle->id,
                'vehicle_plate'     => $vehicle->plate_no,
                'vehicle_label'     => trim($vehicle->make . ' ' . $vehicle->model) ?: null,
                'maintenance_id'    => $maintenanceId,
                'destination'       => $destination,
                'round_trip'        => false, // the arrival step (markUnderRepair / arriveAtPark) closes it
                'assigned_to_id'    => $assignee->id,
                'assigned_to_name'  => $assignee->name ?: $assignee->email,
                'assigned_by_id'    => $actor->id,
                'assigned_by_name'  => $actor->name ?: $actor->email,
                'status'            => LogisticsTask::STATUS_PICKED_UP, // the driver already has the car in hand
                'status_changed_at' => now(),
                'notes'             => $notes,
                'dispatched_at'     => now(),
                'claimed_at'        => now(),
            ]);

            $this->recordEvent($task, LogisticsTaskEvent::EVENT_PICKED_UP, $actor, [
                'to_status' => LogisticsTask::STATUS_PICKED_UP,
                'note'      => $notes,
            ]);

            return $task;
        });
    }

    /**
     * A driver claims a pooled move — first one wins. A row lock + a re-check inside the transaction
     * make the race safe: the loser gets a clear "already taken" error. On success the task locks to
     * the driver (→ en_route), the coordinator is told who took it, and the pool ping is dismissed
     * from every other driver's bell.
     */
    public function claim(LogisticsTask $task, User $driver): LogisticsTask
    {
        return DB::transaction(function () use ($task, $driver) {
            // Re-read under a row lock so two simultaneous claims can't both win.
            $fresh = LogisticsTask::whereKey($task->getKey())->lockForUpdate()->first();
            if (! $fresh) {
                throw new RuntimeException('This dispatch no longer exists.');
            }
            if (! $fresh->isActive()) {
                throw new RuntimeException('This dispatch is already closed.');
            }
            if ($fresh->assigned_to_id) {
                if ((int) $fresh->assigned_to_id === (int) $driver->id) {
                    return $fresh; // idempotent: the same driver tapping twice
                }
                $who = $fresh->assigned_to_name ?: 'Another driver';
                throw new RuntimeException($who . ' already claimed this move.');
            }

            $fresh->update([
                'assigned_to_id'    => $driver->id,
                'assigned_to_name'  => $driver->name ?: $driver->email,
                'status'            => LogisticsTask::STATUS_EN_ROUTE,
                'status_changed_at' => now(),
                'claimed_at'        => now(),
            ]);

            $this->recordEvent($fresh, LogisticsTaskEvent::EVENT_CLAIMED, $driver, [
                'from_status' => LogisticsTask::STATUS_DISPATCHED,
                'to_status'   => LogisticsTask::STATUS_EN_ROUTE,
            ]);

            // Tell the coordinator who took it, and clear the "up for grabs" ping for everyone else.
            $this->notifyCoordinator($fresh, 'claimed', $driver);
            $this->notifier->resolveKeyForOthers('logistics_dispatch:' . $fresh->id, $driver->id);

            return $fresh;
        });
    }

    /**
     * The assignee marks the car PICKED UP — it's now with them, in transit to the destination. The
     * PRE-trip odometer reading rides in $meta['odometer'] (its photo is stored by the controller as a
     * 'pre' InspectionRecord — the mandatory before/after pair on a garage round trip).
     */
    public function pickup(LogisticsTask $task, User $actor, array $meta = []): LogisticsTask
    {
        return $this->advance($task, LogisticsTask::STATUS_PICKED_UP, $actor, $meta);
    }

    /**
     * The assignee marks the car DELIVERED — it's at the destination (e.g. the garage). On a one-way
     * move this is the terminal step, so the POST odometer reading (if any) rides in $meta['odometer'].
     */
    public function deliver(LogisticsTask $task, User $actor, array $meta = []): LogisticsTask
    {
        return $this->advance($task, LogisticsTask::STATUS_DELIVERED, $actor, $meta);
    }

    /**
     * The assignee marks the car RETURNED / ARRIVED back at base — the terminal step on a round trip.
     * Captures a GPS fix (best-effort: the move still closes if the browser denied location) so the
     * record proves the car is physically home, plus the POST odometer reading in $meta['odometer'].
     */
    public function returnToBase(LogisticsTask $task, User $actor, array $meta = []): LogisticsTask
    {
        return $this->advance($task, LogisticsTask::STATUS_RETURNED, $actor, $meta);
    }

    /**
     * Step a move one legal phase forward on behalf of its assignee. Re-guards the actor (only the
     * driver who owns it — or a coordinator overriding — may move it), the transition itself, stamps
     * the audit trail (with the odometer reading, when captured), mirrors the vehicle, and auto-updates
     * the coordinator. When the step closes the move (round-trip return, one-way delivery) the vehicle
     * is freed and its real status re-derived. $meta may carry: odometer, lat, lng, accuracy.
     */
    private function advance(LogisticsTask $task, string $to, User $actor, array $meta = []): LogisticsTask
    {
        return DB::transaction(function () use ($task, $to, $actor, $meta) {
            // Re-read under a row lock and RE-CHECK all guards on the fresh row inside the
            // transaction (mirrors claim()), so two concurrent taps of the same step can't both run
            // the side-effects — duplicate events/notifications and a double vehicle teardown on the
            // terminal step. Idempotent: a repeated request that finds the move already at $to no-ops.
            $task = LogisticsTask::whereKey($task->getKey())->lockForUpdate()->first();
            if (! $task) {
                throw new RuntimeException('This dispatch no longer exists.');
            }
            if ($task->status === $to) {
                return $task; // idempotent: the same step tapped twice / a retried request
            }
            if (! $task->isActive()) {
                throw new RuntimeException('This dispatch is already closed.');
            }
            $isAssignee = $task->assigned_to_id && (int) $task->assigned_to_id === (int) $actor->id;
            if (! $isAssignee && ! $actor->can('logistics.dispatch')) {
                throw new RuntimeException('Only the assigned driver can update this move.');
            }
            if (! $task->canMoveTo($to)) {
                throw new RuntimeException('That step isn\'t available from "' . $task->phaseLabel() . '".');
            }

            $from = $task->status;
            $closes = $task->isTerminalStep($to);

            $update = [
                'status'            => $to,
                'status_changed_at' => now(),
            ];

            // GPS proof-of-return — stamped on the "Returned / Arrived" step only.
            $lat = isset($meta['lat']) && $meta['lat'] !== null ? (float) $meta['lat'] : null;
            $lng = isset($meta['lng']) && $meta['lng'] !== null ? (float) $meta['lng'] : null;
            $acc = isset($meta['accuracy']) && $meta['accuracy'] !== null ? (float) $meta['accuracy'] : null;
            if ($to === LogisticsTask::STATUS_RETURNED) {
                $update['returned_at']       = now();
                $update['returned_lat']      = $lat;
                $update['returned_lng']      = $lng;
                $update['returned_accuracy'] = $acc;
            }

            if ($closes) {
                $update['completed_at']      = now();
                $update['completed_by_name'] = $actor->name ?: $actor->email;
            }

            $task->update($update);

            // The odometer reading (its photo is an InspectionRecord) rides into the audit note so the
            // before/after mileage is on the permanent trail. A >10 km gap from the last reading carries a
            // mandatory driver explanation (Odometer Continuity) — appended so the WHY lives on the trail too.
            $odometer = isset($meta['odometer']) && $meta['odometer'] !== null ? (int) $meta['odometer'] : null;
            $odoNote  = isset($meta['odometer_note']) && is_string($meta['odometer_note']) ? trim($meta['odometer_note']) : '';
            $odoText  = $odometer !== null
                ? ('Odometer ' . number_format($odometer) . ' km' . ($odoNote !== '' ? ' — ' . mb_substr($odoNote, 0, 2000) : ''))
                : null;

            $this->recordEvent($task, $this->eventForStatus($to), $actor, [
                'from_status' => $from,
                'to_status'   => $to,
                'lat'         => $to === LogisticsTask::STATUS_RETURNED ? $lat : null,
                'lng'         => $to === LogisticsTask::STATUS_RETURNED ? $lng : null,
                'accuracy'    => $to === LogisticsTask::STATUS_RETURNED ? $acc : null,
                'note'        => $odoText,
            ]);

            // On close, drop the transit mirror and let the canonical reconcile decide the real status.
            if ($closes && $task->vehicle) {
                $task->vehicle->update(['transit_destination' => null]);
                $this->operations->reconcileVehicleOperationalStatus($task->vehicle->refresh());
            }

            $this->notifyCoordinator($task, $to, $actor);

            return $task;
        });
    }

    /** Back-compat: the old "Mark Delivered" one-tap — close the move at its current terminal. */
    public function complete(LogisticsTask $task, User $actor): LogisticsTask
    {
        if (! $task->isActive()) {
            throw new RuntimeException('This dispatch is already closed.');
        }

        // Round trips end "returned", one-way moves end "delivered".
        $terminal = $task->round_trip ? LogisticsTask::STATUS_RETURNED : LogisticsTask::STATUS_DELIVERED;

        return $this->forceClose($task, $actor, $terminal, LogisticsTaskEvent::EVENT_DELIVERED);
    }

    /** Call off a move — same teardown as a close, recorded as cancelled. Coordinator only. */
    public function cancel(LogisticsTask $task, User $actor): LogisticsTask
    {
        if (! $task->isActive()) {
            throw new RuntimeException('This dispatch is already closed.');
        }

        return $this->forceClose($task, $actor, LogisticsTask::STATUS_CANCELLED, LogisticsTaskEvent::EVENT_CANCELLED);
    }

    /**
     * Supervisor override — hand the move to a different driver at any point in the cycle (one driver
     * takes the car out, a different one brings it back). A still-pooled move is pulled out of the pool
     * and locked to the new driver (→ en_route); an in-flight move keeps its phase. Alerts the new
     * driver ("Action Required"), releases the previous one, and clears any stale pool ping. The audit
     * trail records the handoff. Coordinator-only (gated on the route).
     */
    public function reassign(LogisticsTask $task, User $newDriver, User $actor): LogisticsTask
    {
        if (! $task->isActive()) {
            throw new RuntimeException('This dispatch is already closed — nothing to reassign.');
        }
        if (! $newDriver->can('logistics.view')) {
            throw new RuntimeException('That user can\'t be assigned a move (no logistics access).');
        }

        return DB::transaction(function () use ($task, $newDriver, $actor) {
            $previousId   = $task->assigned_to_id;
            $previousName = $task->assigned_to_name;
            if ((int) $previousId === (int) $newDriver->id) {
                return $task; // already theirs — no-op
            }

            $update = [
                'assigned_to_id'   => $newDriver->id,
                'assigned_to_name' => $newDriver->name ?: $newDriver->email,
            ];
            // Reassigning a still-pooled move claims it out of the pool to the new driver.
            if ($task->status === LogisticsTask::STATUS_DISPATCHED) {
                $update['status']            = LogisticsTask::STATUS_EN_ROUTE;
                $update['status_changed_at'] = now();
                $update['claimed_at']        = now();
            }
            $task->update($update);

            $this->recordEvent($task, LogisticsTaskEvent::EVENT_REASSIGNED, $actor, [
                'to_status' => $task->status,
                'note'      => 'Reassigned to ' . ($newDriver->name ?: 'another driver')
                                . ($previousName ? ' (from ' . $previousName . ')' : ''),
            ]);

            $plate = $task->vehicle_plate ?: ('#' . $task->vehicle_id);

            // The new driver now owns the move + its status updates.
            $this->notifier->notifyUser($newDriver, [
                'type'     => 'logistics_reassigned',
                'category' => 'operations',
                'severity' => 'warning',
                'title'    => 'Assigned to you · ' . $plate,
                'body'     => trim(($actor->name ?: 'A supervisor') . ' assigned ' . $plate
                                . ' (' . $task->phaseLabel() . ') to you. You now own its status updates.'),
                'url'      => '/logistics',
                'key'      => 'logistics_reassigned:' . $task->id . ':' . $newDriver->id,
                'icon'     => 'truck',
                'meta'     => ['task_id' => $task->id, 'plate' => $plate],
            ]);

            // The previous driver is released — the notification moves off them.
            if ($previousId) {
                $previous = User::find($previousId);
                if ($previous) {
                    $this->notifier->notifyUser($previous, [
                        'type'     => 'logistics_unassigned',
                        'category' => 'operations',
                        'severity' => 'info',
                        'title'    => 'Reassigned away · ' . $plate,
                        'body'     => trim($plate . ' is now ' . ($newDriver->name ?: 'another driver') . "'s — you're no longer assigned."),
                        'url'      => '/logistics',
                        'key'      => 'logistics_unassigned:' . $task->id . ':' . $previousId . ':' . $newDriver->id,
                        'icon'     => 'truck',
                        'meta'     => ['task_id' => $task->id, 'plate' => $plate],
                    ]);
                }
            }

            // If it had been pooled, clear the "up for grabs" ping from every other driver's bell.
            $this->notifier->resolveKeyForOthers('logistics_dispatch:' . $task->id, $newDriver->id);

            return $task;
        });
    }

    /**
     * Close a task to a terminal status regardless of the step ladder (used by complete()/cancel()),
     * clear the vehicle mirror and re-derive its true status. Lenient by design so any legacy/odd row
     * can always be put to bed.
     */
    private function forceClose(LogisticsTask $task, User $actor, string $status, string $event): LogisticsTask
    {
        return DB::transaction(function () use ($task, $actor, $status, $event) {
            $from = $task->status;
            $task->update([
                'status'            => $status,
                'status_changed_at' => now(),
                'completed_at'      => now(),
                'completed_by_name' => $actor->name ?: $actor->email,
            ]);

            $this->recordEvent($task, $event, $actor, ['from_status' => $from, 'to_status' => $status]);

            if ($task->vehicle) {
                $task->vehicle->update(['transit_destination' => null]);
                $this->operations->reconcileVehicleOperationalStatus($task->vehicle->refresh());
            }

            $this->notifyCoordinator($task, $status, $actor);

            return $task;
        });
    }

    /**
     * "Ping location" — a dispatcher asks the assignee where the car is. Stamps the ask and sends the
     * assignee a notification carrying the one-click reply presets; the key is unique per ping so
     * repeated asks each land instead of being deduped. Throws if nobody is assigned to the move.
     */
    public function ping(LogisticsTask $task, User $actor): LogisticsTask
    {
        if (! $task->isActive()) {
            throw new RuntimeException('This dispatch is closed — nothing to ping.');
        }
        if (! $task->assigned_to_id) {
            throw new RuntimeException('No one has claimed this dispatch yet — nothing to ping.');
        }
        $assignee = User::find($task->assigned_to_id);
        if (! $assignee) {
            throw new RuntimeException('The assignee no longer exists — reassign the dispatch.');
        }

        $task->update(['last_pinged_at' => now()]);

        $plate = $task->vehicle_plate ?: ('#' . $task->vehicle_id);
        $this->notifier->notifyUser($assignee, [
            'type'     => 'logistics_ping',
            'category' => 'operations',
            'severity' => 'warning',
            'title'    => 'Where is ' . $plate . '?',
            'body'     => trim(($actor->name ?: 'A coordinator') . ' is asking for your current location/status. Tap to reply.'),
            'url'      => '/logistics',
            'key'      => 'logistics_ping:' . $task->id . ':' . now()->timestamp, // unique per ping → never deduped
            'icon'     => 'map-pin',
            'meta'     => [
                'task_id'  => $task->id,
                'plate'    => $plate,
                'ping'     => true,                       // frontend renders one-click reply buttons
                'replies'  => LogisticsTask::STATUS_PRESETS,
                'asked_by' => $actor->name,
            ],
        ]);

        return $task;
    }

    /**
     * The assignee answers a ping (one-click preset or free text). Writes the last-known status, logs
     * it to the audit trail and notifies the coordinator so "where is the car?" is answered on the
     * record. Only the assignee — or a coordinator (logistics.dispatch) — may post status.
     */
    public function respondStatus(LogisticsTask $task, string $status, User $actor): LogisticsTask
    {
        $status = trim($status);
        if ($status === '') {
            throw new RuntimeException('Pick or type a status.');
        }
        $isAssignee = $task->assigned_to_id && (int) $task->assigned_to_id === (int) $actor->id;
        if (! $isAssignee && ! $actor->can('logistics.dispatch')) {
            throw new RuntimeException("Only the assigned driver can post this car's status.");
        }

        return DB::transaction(function () use ($task, $status, $actor) {
            $task->update([
                'last_status'    => $status,
                'last_status_at' => now(),
                'last_status_by' => $actor->id,
            ]);

            $this->recordEvent($task, LogisticsTaskEvent::EVENT_STATUS_UPDATE, $actor, ['note' => $status]);

            $plate = $task->vehicle_plate ?: ('#' . $task->vehicle_id);
            $this->notifier->notifyByPermission('logistics.dispatch', [
                'type'     => 'logistics_status',
                'category' => 'operations',
                'severity' => 'info',
                'title'    => $plate . ' · ' . $status,
                'body'     => trim(($actor->name ?: 'The driver') . ' set the status of ' . $plate . ' to “' . $status . '”.'),
                'url'      => '/logistics',
                'key'      => 'logistics_status:' . $task->id . ':' . now()->timestamp,
                'icon'     => 'map-pin',
                'meta'     => ['task_id' => $task->id, 'plate' => $plate, 'status' => $status],
            ], $actor->id);

            return $task;
        });
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────────

    /** Map a target phase to its audit-trail verb. */
    private function eventForStatus(string $status): string
    {
        return match ($status) {
            LogisticsTask::STATUS_PICKED_UP => LogisticsTaskEvent::EVENT_PICKED_UP,
            LogisticsTask::STATUS_DELIVERED => LogisticsTaskEvent::EVENT_DELIVERED,
            LogisticsTask::STATUS_RETURNED  => LogisticsTaskEvent::EVENT_RETURNED,
            LogisticsTask::STATUS_CANCELLED => LogisticsTaskEvent::EVENT_CANCELLED,
            default                         => LogisticsTaskEvent::EVENT_STATUS_UPDATE,
        };
    }

    /**
     * Append one immutable row to the move's audit trail. Best-effort: a logging hiccup must never
     * roll back the actual transition (mirrors VehicleLogService).
     */
    private function recordEvent(LogisticsTask $task, string $event, User $actor, array $opts = []): void
    {
        try {
            LogisticsTaskEvent::create([
                'logistics_task_id' => $task->id,
                'vehicle_id'        => $task->vehicle_id,
                'event'             => $event,
                'from_status'       => $opts['from_status'] ?? null,
                'to_status'         => $opts['to_status'] ?? null,
                'actor_id'          => $actor->id,
                'actor_name'        => $actor->name ?: $actor->email,
                'lat'               => $opts['lat'] ?? null,
                'lng'               => $opts['lng'] ?? null,
                'accuracy'          => $opts['accuracy'] ?? null,
                'note'              => $opts['note'] ?? null,
                'occurred_at'       => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('logistics audit event failed', ['task' => $task->id, 'event' => $event, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Push an automatic update to the coordinator who created the task — no manual follow-up. Skips
     * the actor when they ARE the coordinator (e.g. a coordinator closing their own move). The key is
     * unique per change so each update lands rather than being deduped.
     */
    private function notifyCoordinator(LogisticsTask $task, string $toStatus, User $actor): void
    {
        $coordinatorId = $task->assigned_by_id;
        if (! $coordinatorId || (int) $coordinatorId === (int) $actor->id) {
            return;
        }
        $coordinator = User::find($coordinatorId);
        if (! $coordinator) {
            return;
        }

        $plate  = $task->vehicle_plate ?: ('#' . $task->vehicle_id);
        $driver = $task->assigned_to_name ?: ($actor->name ?: 'A driver');

        [$verb, $severity] = match ($toStatus) {
            'claimed', LogisticsTask::STATUS_EN_ROUTE => ['claimed', 'info'],
            LogisticsTask::STATUS_PICKED_UP           => ['picked up', 'info'],
            LogisticsTask::STATUS_DELIVERED           => ['delivered', 'success'],
            LogisticsTask::STATUS_RETURNED            => ['returned to base', 'success'],
            LogisticsTask::STATUS_CANCELLED           => ['cancelled', 'warning'],
            default                                   => ['updated', 'info'],
        };

        $this->notifier->notifyUser($coordinator, [
            'type'     => 'logistics_update',
            'category' => 'operations',
            'severity' => $severity,
            'title'    => $plate . ' · ' . ucfirst($verb),
            'body'     => trim($driver . ' ' . $verb . ' ' . $plate
                            . ($task->destination ? ' (' . $task->destination . ')' : '') . '.'),
            'url'      => '/logistics',
            'key'      => 'logistics_update:' . $task->id . ':' . $toStatus . ':' . now()->timestamp,
            'icon'     => 'truck',
            'meta'     => [
                'task_id'     => $task->id,
                'plate'       => $plate,
                'status'      => $task->status,
                'phase_label' => $task->phaseLabel(),
                'driver'      => $task->assigned_to_name,
            ],
        ]);
    }

    /**
     * The notification delivered when a move is raised. For a POOLED request it's an "up for grabs"
     * ping to every driver (stable key so the claim can dismiss it for the others); for a pre-assigned
     * one it's an "Action Required" hand-off to the named driver. Both deep-link into /logistics.
     */
    private function dispatchPayload(LogisticsTask $task, Vehicle $vehicle, bool $pooled): array
    {
        $plate = $task->vehicle_plate ?: ($vehicle->plate_no ?: ('#' . $vehicle->id));
        $car   = trim(($task->vehicle_label ? $task->vehicle_label . ' ' : '') . '(' . $plate . ')');

        if ($pooled) {
            return [
                'type'     => 'logistics_dispatch',
                'category' => 'operations',
                'severity' => 'warning',
                'title'    => 'Move available: ' . $plate . ' → ' . $task->destination,
                'body'     => trim($car . ' needs moving to ' . $task->destination
                                . ($task->assigned_by_name ? ' — raised by ' . $task->assigned_by_name : '')
                                . '. Tap Claim to take the job.'),
                'url'      => '/logistics',
                'key'      => 'logistics_dispatch:' . $task->id, // STABLE → dismissed on claim for the others
                'icon'     => 'truck',
                'meta'     => [
                    'task_id'     => $task->id,
                    'plate'       => $plate,
                    'destination' => $task->destination,
                    'claimable'   => true,
                ],
            ];
        }

        return [
            'type'     => 'logistics_dispatch',
            'category' => 'operations',
            'severity' => 'warning',
            'title'    => 'Action Required: move ' . $plate,
            'body'     => trim('Move ' . $car . ' to ' . $task->destination
                            . ($task->assigned_by_name ? ' — assigned by ' . $task->assigned_by_name : '')),
            'url'      => '/logistics',
            'key'      => 'logistics_dispatch:' . $task->id,
            'icon'     => 'truck',
            'meta'     => [
                'task_id'     => $task->id,
                'plate'       => $plate,
                'destination' => $task->destination,
            ],
        ];
    }
}
