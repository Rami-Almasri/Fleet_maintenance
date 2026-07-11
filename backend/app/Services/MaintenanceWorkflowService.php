<?php

namespace App\Services;

use App\Exceptions\WorkflowTransitionException;
use App\Models\FaultCause;
use App\Models\InspectorPadFlag;
use App\Models\Maintenance;
use App\Models\MaintenanceLineItem;
use App\Models\MaintenanceSwap;
use App\Models\OdometerBlockEvent;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleLogEvent;
use App\Models\Vendor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The Fleet Maintenance Workflow engine — the server-side state machine that replaces the manual
 * WhatsApp relay between the Inspector (Abu Maroof) → Logistics/Delivery → Controllers (Lin & Marwa).
 *
 * A workflow ticket IS a hand-entered workshop event (a `maintenances` row, origin = 'manual'),
 * so it flows through the same board, SLA, cost and utilisation surfaces every other workshop event
 * does. What this service adds on top is the LIFECYCLE (workflow_status) and its guards:
 *
 *   UC-1  open()            inspection_diagnostic   Inspector picks vehicle + reason → diagnostic
 *   UC-2  submitReport()    inspection_pending      Inspector files the test-drive report → ticket born
 *         assignDispatch()  awaiting_dispatch       Supervisor reviews, picks the garage + assigns a driver
 *   UC-3  dispatch()        under_repair            Driver captures odometer + picks the car up
 *   UC-4  markUnderRepair() under_repair            Driver relays "garage received it"
 *   UC-5  markReady()       ready_for_reinspection  Driver relays "garage finished"
 *   UC-6  close()               closed              Re-inspected, returned to service
 *        markReinspectionFailed() reinspection_failed  Re-inspection failed → back to the Supervisor to re-dispatch
 *
 * The lifecycle is mapped onto the legacy event_status so the rest of the system needs no changes:
 *   - from in_transit through ready_for_reinspection the car is physically out → event_status 'OUT'
 *     (out_date stamped at dispatch), which the operational_status cascade reads as "In Maintenance";
 *   - on close the event becomes 'IN' with an actual_in_date, which frees the car.
 * During inspection_pending / awaiting_dispatch the ticket is parked at 'IN' (no out_date) so a car
 * merely being inspected is NOT yet counted as in the garage — and the Rental-First rule still wins,
 * so opening a ticket on a live rental never steals the car's "rented" status.
 *
 * Every transition is guarded: an out-of-sequence move, or a handoff missing its required data,
 * throws WorkflowTransitionException (mapped to 422 by the API). Each transition stamps WHO + WHEN
 * and fires a FleetAlert to the next role automatically — the data is generated, never typed.
 */
class MaintenanceWorkflowService
{
    /**
     * The only legal moves out of each state. Anything not listed throws. ready_for_reinspection
     * can go forward (closed) or bounce back (under_repair) when a re-inspection fails.
     */
    private const TRANSITIONS = [
        // Stage 0 → Stage 1: the Inspector picks up a Driver's request and starts the test drive.
        Maintenance::WF_INSPECTION_REQUESTED => [Maintenance::WF_INSPECTION_DIAGNOSTIC],
        // Stage 1 → Stage 2 decision: a diagnostic either becomes a ticket (in-shop → the dispatch
        // queue, OR on-site → the mobile lane) or is cleared. The Repair-Location choice at the Decide
        // step picks which committed branch it enters.
        Maintenance::WF_INSPECTION_DIAGNOSTIC => [Maintenance::WF_INSPECTION_PENDING, Maintenance::WF_ON_SITE_PENDING, Maintenance::WF_DIAGNOSTIC_CLEARED],
        // On-Site (mobile) lane: a single "Mark as Serviced" closes it (no garage, no re-inspection),
        // or — if the job turns out to need the workshop after all — it can be escalated into the
        // in-shop dispatch queue (inspection_pending) rather than closed.
        Maintenance::WF_ON_SITE_PENDING    => [Maintenance::WF_CLOSED, Maintenance::WF_INSPECTION_PENDING],
        // Customer-complaint TRIAGE (Abu Maroof): resolve it on-site (terminal), OR send the car in —
        // straight to the Supervisors' garage-dispatch queue, OR into his own inspection queue first.
        Maintenance::WF_COMPLAINT_TRIAGE => [
            Maintenance::WF_COMPLAINT_RESOLVED,
            Maintenance::WF_INSPECTION_PENDING,
            Maintenance::WF_INSPECTION_REQUESTED,
        ],
        Maintenance::WF_COMPLAINT_RESOLVED => [],
        // Phase 2 — the Supervisor (dispatcher) reviews the ticket, picks the garage + assigns a driver.
        Maintenance::WF_INSPECTION_PENDING => [Maintenance::WF_AWAITING_DISPATCH],
        // Phase 3a — the assigned driver picks the car up (pickup odometer) and heads to the garage.
        Maintenance::WF_AWAITING_DISPATCH  => [Maintenance::WF_IN_TRANSIT],
        // Phase 3b — "Now at Garage" arrival checkpoint: the driver/shop confirms arrival plus the
        // MANDATORY arrival odometer (markUnderRepair), moving the car into the workshop (under_repair)
        // where the repair — cost + faults — is actually managed.
        Maintenance::WF_IN_TRANSIT         => [Maintenance::WF_UNDER_REPAIR],
        // Garage done → the repair goes to the Supervisor's Video-Review gate (markReady()). It never
        // reaches Ready for Pickup until a supervisor has reviewed the garage's video.
        Maintenance::WF_UNDER_REPAIR       => [Maintenance::WF_REPAIR_REVIEW],
        // Supervisor Video-Review gate: a supervisor (Waleed/Abdullah) reviewed the garage's video and
        // either APPROVES it → Ready for Pickup, the car can now be collected (approveRepair()) — or, if
        // not satisfied, REQUESTS A RE-FIX, sending it back to the same garage (→ under_repair).
        Maintenance::WF_REPAIR_REVIEW      => [Maintenance::WF_READY_FOR_PICKUP, Maintenance::WF_UNDER_REPAIR],
        // Signed off by the garage (and, if the video-review gate is on, the supervisor) — sitting at the
        // garage awaiting the driver's collection trip (collectFromGarage() records the mandatory
        // "receiving the car" photos, no status change) then the return leg (arriveAtPark()).
        Maintenance::WF_READY_FOR_PICKUP   => [Maintenance::WF_IN_OUR_PARK],
        // The driver has physically brought the car back to base (arriveAtPark(), mandatory arrival
        // photos). From here the system auto-branches by repair severity (isMajorRepair()) in the SAME
        // request — this state is transient, never a place a ticket rests:
        //   • minor repair  → auto-continues straight to closed, freeing the car immediately.
        //   • major repair  → auto-continues to ready_for_reinspection so the Inspector performs a final
        //     QA pass before the car can be marked Available — the car stays "in maintenance" until then.
        Maintenance::WF_IN_OUR_PARK        => [Maintenance::WF_CLOSED, Maintenance::WF_READY_REINSPECTION, Maintenance::WF_AWAITING_INVOICE],
        // Final QA re-inspection (major repairs only, entered from in_our_park): sign off (closed /
        // awaiting_invoice) or, when a fault is still broken, fail it (→ reinspection_failed).
        Maintenance::WF_READY_REINSPECTION => [Maintenance::WF_CLOSED, Maintenance::WF_AWAITING_INVOICE, Maintenance::WF_REINSPECTION_FAILED],
        // A failed re-inspection sits in the Supervisor's dispatch queue; assigning a garage re-dispatches it.
        Maintenance::WF_REINSPECTION_FAILED => [Maintenance::WF_AWAITING_DISPATCH],
        // Awaiting invoice → the invoice arrives (portal/line-items) or is marked received → fully closed.
        Maintenance::WF_AWAITING_INVOICE   => [Maintenance::WF_CLOSED],
        Maintenance::WF_CLOSED             => [],
        Maintenance::WF_DIAGNOSTIC_CLEARED => [],
    ];

    /**
     * The permission that identifies each role we alert on a handoff — we notify whoever ACTS NEXT.
     * These are the dedicated workflow permissions, so each alert lands only on the role that owns
     * the next step (Logistics never sees a controller alert, and vice-versa).
     */
    private const NOTIFY_LOGISTICS   = 'maintenance.logistics'; // Drivers/Delivery: the pickup hand-off
    private const NOTIFY_DISPATCHER  = 'maintenance.delegate';  // Supervisors (Waleed/Abdullah): review the ticket, pick the garage + assign a driver
    private const NOTIFY_INSPECTOR   = 'maintenance.initiate';  // Inspector (+ controllers/managers who hold it): re-inspection
    private const NOTIFY_CONTROLLERS = 'maintenance.manage';    // Controllers (Lin & Marwa) + managers: progress visibility

    /** The role that coordinates drivers — auto-watched (and alerted) on any prioritised ticket. */
    private const SUPERVISOR_ROLE = 'supervisor';

    /**
     * Whether the auto-generated closing summary inlines the final repair cost. Kept OFF while the
     * "Financial Decoupling" is in effect (the team's history is a money-free Technical Service Log;
     * mirrors the front-end SHOW_FINANCIALS flag). The cost is always captured in the structured
     * `cost` column regardless — this only controls whether it appears in the narrative note. Flip
     * to true (alongside SHOW_FINANCIALS) to restore the legacy "· Cost: AED …" line.
     */
    private const INCLUDE_COST_IN_SUMMARY = false;

    public function __construct(
        private OperationsService $operations,
        private NotificationScanner $notifier,
        private VehicleLogService $log,
        private OdometerContinuityService $continuity,
        private LogisticsDispatchService $logistics,
        private DiagnosticGateService $gate,
    ) {}

    /**
     * Run one odometer reading through the Continuity Rules and stamp the verdict onto the ticket's
     * odometer_flags map (keyed by capture stage). Pure classification — never blocks or throws; the
     * Supervisor reads the stored verdict (e.g. a backward "Discrepancy") from the drawer afterwards.
     *
     * The caller owns the SAVE (this only mutates the in-memory attribute) so the flag rides along with
     * the transition's own write inside its DB transaction — no extra query.
     *
     * A BACKWARD "discrepancy" (an odometer that ran backwards — a real data error that is nonetheless
     * accepted, unlike the hard-blocked strict-match stages) also fires a supervisor/controller bell here,
     * so a suspicious reading isn't only visible passively on the drawer / oversight board. Pass $actor so
     * the alert can name who entered it. Notifying inside the caller's transaction is fine: the reading was
     * accepted, so the txn commits and the notification persists with it.
     *
     * @param  string  $flagKey  where to file it: 'test_drive' | 'dispatch' | 'receive' | 'return'
     * @param  string  $stage    which rule applies: an OdometerContinuityService::STAGE_* constant
     */
    private function recordOdometerFlag(Maintenance $ticket, string $flagKey, int $reading, ?int $previous, string $stage, ?string $note = null, ?User $actor = null): array
    {
        $flag  = $this->continuity->evaluate($reading, $previous, $stage);
        // Context-aware tolerance: a site↔garage / garage↔garage move is a deliberate road trip, so a
        // forward mileage increase is EXPECTED — the UI waives the ±10 km note/confirm nag for it and the
        // reading arrives without a note. Stamp the flag so downstream readers (drawer, mileage story)
        // know the tolerance was intentionally waived rather than silently skipped. (A backward reading
        // still classifies as a Discrepancy inside evaluate(), whatever the stage.)
        if ($this->continuity->stageIgnoresTolerance($stage)) {
            $flag['tolerance_waived'] = true;
        }
        // The operator's written explanation for a >10 km gap from the previous reading (mandatory in the
        // UI when it fires). Stored on the flag so the Supervisor reads WHY the meter jumped in the drawer.
        $note = is_string($note) ? trim($note) : null;
        if ($note !== null && $note !== '') {
            $flag['note'] = mb_substr($note, 0, 2000);
        }
        $flags = $ticket->odometer_flags ?? [];
        $flags[$flagKey] = $flag;
        $ticket->odometer_flags = $flags;

        // A backward reading was recorded — alert the supervisors/controllers so it's actively chased, not
        // just left on the audit board. (The hard-blocked strict-match stages never reach here as a
        // discrepancy — they throw and are logged via logOdometerBlock instead.)
        if (($flag['status'] ?? null) === OdometerContinuityService::STATUS_DISCREPANCY) {
            $this->notifyOdometerDiscrepancy($ticket, $flagKey, $flag, $actor);
        }

        return $flag;
    }

    /**
     * Alert supervisors + controllers that a BACKWARD odometer reading was recorded (an accepted, non-blocked
     * discrepancy — an odometer can't run backwards, so it's a data error worth chasing). Best-effort: audit
     * notification must never break the transition. Its sibling for REJECTED attempts is logOdometerBlock().
     */
    private function notifyOdometerDiscrepancy(Maintenance $ticket, string $flagKey, array $flag, ?User $actor): void
    {
        try {
            $vehicle  = $ticket->loadMissing('vehicle')->vehicle;
            $reading  = (int) ($flag['reading'] ?? 0);
            $previous = $flag['previous'] ?? null;
            $this->notifier->notifyByAnyPermission([self::NOTIFY_DISPATCHER, self::NOTIFY_CONTROLLERS], [
                'type'     => 'odometer_discrepancy',
                'category' => 'maintenance',
                'severity' => 'warning',
                'title'    => '↩️ Odometer ran backwards · ' . $this->label($vehicle),
                'body'     => trim(($actor?->name ?? $ticket->responsible ?? 'Someone') . ' recorded ' . number_format($reading) . ' km'
                              . ($previous !== null ? ' — below the previous ' . number_format((int) $previous) . ' km' : '')
                              . ' at ' . $this->blockStageLabel($flagKey) . '. An odometer can’t run backwards — please verify.'),
                'url'      => $this->link($ticket),
                'key'      => 'odo_discrepancy:' . $ticket->id . ':' . $flagKey . ':' . $reading,
                'icon'     => 'alert-triangle',
                'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no, 'stage' => $flagKey, 'reading' => $reading, 'previous' => $previous, 'by' => $actor?->name],
            ], $actor?->id);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Strict-match odometer gate for the internal park spot-checks — "Being Inspected" (the inspector's
     * test capture) and "Awaiting Pickup" (the driver collecting the car for the garage). The car hasn't
     * moved, so the reading must equal the previous stage's; a 1..TOLERANCE km forward drift is allowed
     * ONLY with a written note (which then surfaces on the /oversight/mileage board for a supervisor to
     * audit the "authorized" deviation); a backward reading or a jump beyond the buffer is rejected outright
     * as a typo or an unauthorised move. A no-op when there is no previous reading to compare against.
     * Mirrors the frontend hard block in odometerContinuity.js — throwing here keeps the rule enforced even
     * if a client bypasses the modal.
     */
    private function assertStrictMatch(Maintenance $ticket, ?User $actor, string $flagKey, int $reading, ?int $previous, string $stage, ?string $note, string $field): void
    {
        if ($previous === null) {
            return; // nothing to match against — this reading anchors the chain
        }
        $flag = $this->continuity->evaluate($reading, $previous, $stage);

        if ($flag['status'] === OdometerContinuityService::STATUS_EXACT) {
            $delta = (int) $flag['delta'];
            // Audit the rejected attempt BEFORE throwing — the transition rolls back and leaves no trace on
            // the ticket, so this is the only record that someone tried to force an out-of-range value.
            $this->logOdometerBlock($ticket, $actor, $flagKey, $reading, $previous, $delta, OdometerContinuityService::STATUS_EXACT, $note);
            throw new WorkflowTransitionException(
                'The reading must match the previous stage (' . number_format($previous) . ' km) — up to '
                . OdometerContinuityService::TOLERANCE_KM . ' km higher is allowed with a note. '
                . number_format($reading) . ' km is ' . abs($delta) . ' km '
                . ($delta < 0 ? 'lower' : 'higher') . '; re-check the dial.',
                ['field' => $field]
            );
        }

        if ($flag['status'] === OdometerContinuityService::STATUS_AUTHORIZED && trim((string) $note) === '') {
            // A missing note is a form-completion nudge, NOT an unauthorised value — don't audit it as a block.
            throw new WorkflowTransitionException(
                'This reading is ' . (int) $flag['delta'] . ' km above the previous stage — add a short note explaining why before continuing.',
                ['field' => 'odometer_note']
            );
        }
    }

    /**
     * Persist a REJECTED odometer attempt (an out-of-range / backward strict-match reading, or a garage
     * arrival that wasn't higher than pickup) and alert supervisors + controllers. Because the caller is
     * about to throw and roll its transition back, this runs OUTSIDE that transaction (every strict gate is
     * checked BEFORE the DB::transaction opens) so the audit row survives the rejection. Both the write and
     * the notify are best-effort — audit logging must never break the (already-failing) request path. The
     * row is surfaced on /oversight/mileage alongside the recorded odometer_flags.
     */
    private function logOdometerBlock(Maintenance $ticket, ?User $actor, string $flagKey, int $reading, ?int $previous, ?int $delta, string $status, ?string $note): void
    {
        try {
            OdometerBlockEvent::create([
                'maintenance_id' => $ticket->id,
                'vehicle_id'     => $ticket->vehicle_id,
                'stage_key'      => $flagKey,
                'status'         => $status,
                'previous'       => $previous,
                'reading'        => $reading,
                'delta'          => $delta,
                'note'           => is_string($note) && trim($note) !== '' ? mb_substr(trim($note), 0, 2000) : null,
                'actor_id'       => $actor?->id,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }

        try {
            $vehicle = $ticket->loadMissing('vehicle')->vehicle;
            $this->notifier->notifyByAnyPermission([self::NOTIFY_DISPATCHER, self::NOTIFY_CONTROLLERS], [
                'type'     => 'odometer_blocked',
                'category' => 'maintenance',
                'severity' => 'warning',
                'title'    => '🚫 Odometer entry blocked · ' . $this->label($vehicle),
                'body'     => trim(($actor?->name ?? 'Someone') . ' tried to enter ' . number_format($reading) . ' km'
                              . ($previous !== null ? ' (expected ' . number_format($previous) . ' km)' : '')
                              . ' at ' . $this->blockStageLabel($flagKey) . ' — rejected by the odometer rules.'),
                'url'      => $this->link($ticket),
                'key'      => 'odo_block:' . $ticket->id . ':' . $flagKey . ':' . $reading,
                'icon'     => 'alert-triangle',
                'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no, 'stage' => $flagKey, 'reading' => $reading, 'previous' => $previous, 'by' => $actor?->name],
            ], $actor?->id);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Human label for a blocked-attempt capture stage (mirrors WorkflowOversightController::STAGE_MAP). */
    private function blockStageLabel(string $flagKey): string
    {
        return match ($flagKey) {
            'test_drive' => 'Being Inspected (test drive)',
            'report'     => 'Being Inspected (decide)',
            'dispatch'   => 'Awaiting Pickup',
            'receive'    => 'Garage Arrival',
            'return'     => 'Collected from Garage',
            'transfer'   => 'Garage Transfer',
            'reinspect'  => 'Re-Inspection Sign-off',
            default      => 'an odometer stage',
        };
    }

    /**
     * Mileage Gate for a whole-car garage transfer — the mandatory odometer reading captured the moment
     * the Supervisor switches the car from one garage to another (the transaction is rejected upstream
     * unless it's present). It:
     *   - stamps the Continuity verdict under the `transfer` key (a backward reading shows as Discrepancy);
     *   - heals the car's canonical live mileage FORWARD only (odometers never run backwards).
     * The odometer_flags mutation is left UNSAVED so it rides along with the caller's own transfer write
     * (rollbackForGarageTransfer) — no extra query; the vehicle heal is saved here.
     */
    public function recordGarageTransferOdometer(Maintenance $ticket, int $odometer, ?string $note = null, ?User $actor = null): array
    {
        $vehicle  = $ticket->vehicle;
        $previous = $vehicle && $vehicle->odometer !== null ? (int) $vehicle->odometer : null;

        $flag = $this->recordOdometerFlag($ticket, 'transfer', $odometer, $previous, OdometerContinuityService::STAGE_TRANSFER, $note, $actor);

        if ($vehicle) {
            $this->applyTestOdometer($vehicle, $odometer); // forward-only heal, mirrors the test-drive anchor
        }

        return $flag;
    }

    /**
     * Workflow Stage Rollback for a whole-car garage transfer. A transfer is a PHYSICAL move: the car is
     * now en route to the new garage, not in it. So if it had already arrived (under_repair or later), we
     * kick the ticket back to `in_transit` ("Awaiting Garage Arrival") and clear the stale arrival reading —
     * forcing the new garage/driver to run the mandatory "Now at Garage" check-in (arrival odometer) before
     * any work resumes on the (now In-Transit) faults. A car still pre-arrival (awaiting_dispatch/in_transit)
     * keeps its stage; either way this SAVE persists the caller's re-pointed garage + the mileage-gate
     * odometer_flags in one write, refreshes the board, and alerts Logistics to check the car in.
     */
    public function rollbackForGarageTransfer(Maintenance $ticket, User $actor): void
    {
        $wasAtGarage = $ticket->hasReachedGarage(); // under_repair or later — the car had physically arrived
        if ($wasAtGarage) {
            $ticket->workflow_status  = Maintenance::WF_IN_TRANSIT; // en route to the new garage
            $ticket->receive_odometer = null;                       // arrival reading is re-captured at check-in
            $ticket->event_status     = 'OUT';
            // Stop the "At Garage" clock: the car has LEFT this garage. It restarts (repair_started_at) at
            // the next garage's arrival check-in, so the current-garage stint is measured fresh per garage
            // and a transfer never carries the previous garage's time forward.
            $ticket->repair_started_at = null;
            $ticket->repair_started_by = null;
        }

        DB::transaction(function () use ($ticket) {
            $ticket->save(); // booted() re-stamps last_state_change_at → a fresh transit clock for the new leg
            $this->cascade($ticket->vehicle_id);
            // The custodian is alerted directly by the transport task (dispatchForMaintenanceTransfer),
            // so no broad "confirm arrival" ping is raised here — the move owns the hand-off.
        });
    }

    /**
     * PLANNED garage transfer — the car is physically AT its current garage and the Supervisor is moving
     * it to another one. Unlike the pre-arrival re-route (a plain destination change), the ground truth is
     * preserved: `vendor_id` KEEPS pointing at the garage the car is at, and only the intended DESTINATION
     * is recorded (`transfer_to_vendor_id`). The ticket drops to "Awaiting Pickup" so a driver collects the
     * car; the actual hand-over (fault stints + vendor re-point) is deferred to the destination arrival
     * check-in (markUnderRepair), keeping the single-garage invariant intact for the whole leg. Mirrors
     * assignDispatch's driver-delegation overlay + pickup alert, so a transfer flows through the same
     * pickup → in-transit → arrival path as a first dispatch. The odometer flag was already stamped
     * (recordGarageTransferOdometer) and rides along with this save.
     */
    public function beginGarageTransfer(Maintenance $ticket, Vendor $dest, ?int $driverId, ?string $reason, User $actor): Maintenance
    {
        $fromGarage = $ticket->garage ?: $ticket->vendor?->name;

        $driver = $driverId ? User::find($driverId) : null;
        if ($driverId && ! $driver) {
            throw new WorkflowTransitionException('That driver no longer exists — pick another.', ['field' => 'assigned_to_id']);
        }
        if ($driver && ! $driver->can('maintenance.logistics')) {
            throw new WorkflowTransitionException('That user is not a driver — pick someone who can pick up cars.', ['field' => 'assigned_to_id']);
        }

        return DB::transaction(function () use ($ticket, $dest, $driver, $reason, $actor, $fromGarage) {
            // Record ONLY the destination — vendor_id stays the garage the car is physically at.
            $ticket->transfer_to_vendor_id = $dest->id;

            // The car is leaving its current garage → stop that garage's repair clock and clear the stale
            // arrival/pickup readings; a fresh pickup reading is captured at collection (dispatch()) and a
            // fresh arrival reading at the destination check-in (markUnderRepair).
            $ticket->repair_started_at = null;
            $ticket->repair_started_by = null;
            $ticket->receive_odometer  = null;
            $ticket->dispatch_odometer = null;
            $ticket->event_status      = 'OUT'; // still physically out (sitting at a garage)

            // Release the PREVIOUS custodian. Whoever brought the car to this garage has finished their leg —
            // their task ends once they've delivered it here. The onward move is a brand-new pickup that ANY
            // driver can step in and claim ("I'll take it"). If we left the old dispatched_by/driver on the
            // ticket, the destination arrival check-in (markUnderRepair's custody gate) would stay locked to
            // that first driver and force the hand-off back through him. Clearing it makes the transfer a
            // clean open pickup: whoever collects the car next (dispatch()) becomes the sole custodian who
            // must confirm its arrival at the destination garage.
            $ticket->dispatched_by = null;
            $ticket->dispatched_at = null;
            $ticket->driver        = null;

            // Assign the pickup driver (same delegation overlay assignDispatch uses) when one is named — that
            // is the person designated to receive the car. With none named the pickup is open to the pool, so
            // clear any stale delegation from the previous leg rather than leaving the old driver attached.
            if ($driver) {
                $ticket->assigned_driver_id = $driver->id;
                $ticket->delegation_task    = Maintenance::DELEGATION_PICKUP;
                $ticket->delegation_status  = Maintenance::DELEGATION_ASSIGNED;
                $ticket->delegated_by       = $actor->id;
                $ticket->delegated_at       = Carbon::now();
            } else {
                $ticket->assigned_driver_id = null;
                $ticket->delegation_task    = null;
                $ticket->delegation_status  = null;
                $ticket->delegated_by       = null;
                $ticket->delegated_at       = null;
            }

            $ticket->workflow_status = Maintenance::WF_AWAITING_DISPATCH; // "Awaiting Pickup"
            $ticket->save(); // booted() re-stamps last_state_change_at → a fresh Awaiting-Pickup clock

            if ($driver) {
                $ticket->watchers()->syncWithoutDetaching([
                    $driver->id => ['added_by' => $actor->id, 'reason' => 'delegated'],
                ]);
            }

            $this->cascade($ticket->vehicle_id);

            $this->log->record($ticket, VehicleLogEvent::EVENT_GARAGE_ASSIGNED, $actor, [
                'description' => 'Transfer requested — car at ' . ($fromGarage ?: 'the garage') . ' → ' . $dest->name
                    . ($reason ? ' · ' . $reason : '')
                    . ($driver ? ', pickup by ' . $driver->name : ', pickup open to the pool')
                    . ' (by ' . $actor->name . ')',
                'meta' => ['from_garage' => $fromGarage, 'to_garage' => $dest->name, 'to_vendor_id' => $dest->id, 'reason' => $reason, 'driver_id' => $driver?->id, 'transfer' => true],
            ]);

            // Alert whoever picks up next — the named driver directly, or the whole Driver pool.
            $vehicle = $ticket->loadMissing('vehicle')->vehicle;
            if ($driver) {
                $this->notifier->notifyUser($driver, [
                    'type'     => 'maint_pickup_assigned',
                    'category' => 'maintenance',
                    'severity' => 'warning',
                    'title'    => trim('🔀 Transfer pickup · ' . $this->label($vehicle)),
                    'body'     => trim($actor->name . ' assigned you to move ' . $this->label($vehicle)
                                    . ' from ' . ($fromGarage ?: 'its garage') . ' to ' . $dest->name
                                    . ' — collect it and capture the odometer.'),
                    'url'      => $this->link($ticket),
                    'key'      => 'maint_wf:' . $ticket->id . ':awaiting_dispatch:' . $driver->id,
                    'icon'     => 'truck',
                    'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no, 'from_garage' => $fromGarage, 'garage' => $dest->name, 'transfer' => true, 'assigned_by' => $actor->name],
                ]);
            } else {
                $this->notifier->notifyByPermission(self::NOTIFY_LOGISTICS, [
                    'type'     => 'maint_pickup_ready',
                    'category' => 'maintenance',
                    'severity' => 'warning',
                    'title'    => trim('🔀 Transfer ready for pickup · ' . $this->label($vehicle)),
                    'body'     => trim($this->label($vehicle) . ' is being moved from ' . ($fromGarage ?: 'its garage')
                                    . ' to ' . $dest->name . ' — collect it and capture the odometer.'),
                    'url'      => $this->link($ticket),
                    'key'      => 'maint_wf:' . $ticket->id . ':awaiting_dispatch',
                    'icon'     => 'truck',
                    'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no, 'from_garage' => $fromGarage, 'garage' => $dest->name, 'transfer' => true],
                ], $actor->id);
            }

            return $ticket->load($this->eager());
        });
    }

    // ── STAGE 0 — Driver requests an inspection (NOT a ticket, NOT a diagnostic yet) ──

    /**
     * Stage 0. A Driver (Logistics) raises a "Request Inspection" on a car they suspect needs a look —
     * the new entry point the role-based design calls for. It is born in `inspection_requested`: no
     * ticket, no diagnostic, no contract, the car is NOT marked in maintenance (event_status 'IN').
     * The only side-effect is an alert to the Inspector (Abu Maroof) — "this car needs a test drive",
     * carrying the Driver's notes — and a `requested_by` stamp so the request is attributable and the
     * result can be routed straight back to whoever raised it.
     *
     * @param array{vehicle_id:int, trigger_reason:string, customer_complaint?:?string} $data
     */
    public function requestInspection(array $data, User $driver): Maintenance
    {
        $vehicleId = (int) ($data['vehicle_id'] ?? 0);
        $vehicle   = $vehicleId ? Vehicle::find($vehicleId) : null;
        if (! $vehicle) {
            throw new WorkflowTransitionException('A valid vehicle is required to request an inspection.', [
                'field' => 'vehicle_id',
            ]);
        }

        // Only an active-fleet car may enter the workflow — otherwise the ticket would be hidden
        // by the board's active-fleet filter the moment it's created.
        $this->assertActiveFleet($vehicle);

        $reason = $data['trigger_reason'] ?? null;
        if (! in_array($reason, Maintenance::TRIGGER_REASONS, true)) {
            throw new WorkflowTransitionException('Choose a reason: test drive, customer complaint, or routine maintenance.', [
                'field' => 'trigger_reason',
            ]);
        }

        return DB::transaction(function () use ($vehicleId, $reason, $data, $driver) {
            $ticket = new Maintenance();
            $ticket->origin          = Maintenance::ORIGIN_MANUAL;
            $ticket->vehicle_id      = $vehicleId;
            $ticket->workflow_status = Maintenance::WF_INSPECTION_REQUESTED;
            $ticket->trigger_reason  = $reason;
            $ticket->visit_context   = $reason === Maintenance::TRIGGER_PERIODIC
                ? Maintenance::CONTEXT_ROUTINE
                : 'standard';
            // The Driver's notes ride along as the customer_complaint so the inspector sees them.
            $ticket->customer_complaint = $this->clean($data['customer_complaint'] ?? null);

            // maintenance_type is intentionally NOT set here — the Driver flagging a car for inspection
            // has no diagnostic authority. The Inspector sets the classification when filing the report.

            // Parked: a requested inspection must not read as a garage event.
            $ticket->event_status = 'IN';
            $ticket->requested_by = $driver->id;
            $ticket->requested_at = Carbon::now();
            $ticket->driver       = $driver->name;
            $ticket->save();

            $this->cascade($ticket->vehicle_id);

            $this->log->record($ticket, VehicleLogEvent::EVENT_INSPECTION_REQUESTED, $driver, [
                'description' => 'Inspection requested — ' . $this->reasonLabel($reason)
                                . ($ticket->customer_complaint ? ': “' . $ticket->customer_complaint . '”' : '')
                                . ' (by ' . $driver->name . ')',
                'meta'        => ['trigger_reason' => $reason, 'requested_by' => $driver->name],
            ]);

            // Hand off to the Inspector (Abu Maroof) — he acts next: run the test drive.
            $vehicle = $ticket->loadMissing('vehicle')->vehicle;
            $note    = $ticket->customer_complaint ? ' — “' . $ticket->customer_complaint . '”' : '';
            $this->notifier->notifyByPermission(self::NOTIFY_INSPECTOR, [
                'type'     => 'maint_inspection_requested',
                'category' => 'maintenance',
                'severity' => 'warning',
                'title'    => 'Inspection requested · ' . $this->label($vehicle),
                'body'     => trim($driver->name . ' asked for a test drive on ' . $this->label($vehicle) . $note),
                'url'      => $this->link($ticket),
                'key'      => 'maint_wf:' . $ticket->id . ':inspection_requested',
                'icon'     => 'wrench',
                'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no, 'requested_by' => $driver->name],
            ], $driver->id);

            return $ticket->load($this->eager());
        });
    }

    /**
     * SYSTEM-generated routine inspection TASK (Phase-2 auto-routine). The mileage scanner found a
     * service-due car, so the SYSTEM raises the very same `inspection_requested` task a Driver would
     * — landing it in Abu Maroof's queue with trigger_reason = periodic. It is attributed to no user
     * (requested_by null, driver = "System · Auto-Check"); the audit-log entry and the inspector alert
     * are written exactly as for a Driver request, so the task is fully accountable and the Inspector
     * still cannot self-start — he only acts on a task the system put in front of him.
     *
     * Dedup (don't re-task a car already in the pipeline) is the caller's job (the command checks
     * openWorkflow); here we only assert the car is still active fleet before writing.
     *
     * @param array{note?:?string, suggested_findings?:?array} $opts
     */
    public function systemRequestInspection(Vehicle $vehicle, array $opts = []): Maintenance
    {
        $this->assertActiveFleet($vehicle);

        return DB::transaction(function () use ($vehicle, $opts) {
            $ticket = new Maintenance();
            $ticket->origin             = Maintenance::ORIGIN_MANUAL;
            $ticket->vehicle_id         = $vehicle->id;
            $ticket->workflow_status    = Maintenance::WF_INSPECTION_REQUESTED;
            $ticket->trigger_reason     = Maintenance::TRIGGER_PERIODIC;
            $ticket->visit_context      = Maintenance::CONTEXT_ROUTINE; // planned service → foresight ignores it
            $ticket->customer_complaint = $this->clean($opts['note'] ?? 'Routine service due (mileage).');
            // Ready-entry-point chips for the Decide step (see DiagnosticGateService::dueChecks) — the
            // exact Findings-catalog keywords this ticket was raised for, so the Inspector taps to
            // confirm instead of hunting the picker for what the agenda note already told him to check.
            $ticket->suggested_findings = array_values(array_filter($opts['suggested_findings'] ?? []));
            $ticket->event_status       = 'IN';   // a requested inspection is not a garage event
            $ticket->requested_by       = null;    // system-generated, no human requester
            $ticket->requested_at       = Carbon::now();
            $ticket->driver             = 'System · Auto-Check';
            $ticket->save();

            $this->cascade($ticket->vehicle_id);

            $this->log->record($ticket, VehicleLogEvent::EVENT_INSPECTION_REQUESTED, null, [
                'description' => 'Routine inspection auto-requested by the mileage scanner'
                                . ($ticket->customer_complaint ? ': ' . $ticket->customer_complaint : ''),
                'meta'        => ['trigger_reason' => Maintenance::TRIGGER_PERIODIC, 'requested_by' => 'system', 'auto' => true],
            ]);

            // Hand off to the Inspector (Abu Maroof) — same alert a Driver request raises.
            $note = $ticket->customer_complaint ? ' — ' . $ticket->customer_complaint : '';
            $this->notifier->notifyByPermission(self::NOTIFY_INSPECTOR, [
                'type'     => 'maint_inspection_requested',
                'category' => 'maintenance',
                'severity' => 'warning',
                'title'    => 'Routine inspection due · ' . $this->label($vehicle),
                'body'     => trim('System flagged ' . $this->label($vehicle) . ' for a routine test drive' . $note),
                'url'      => $this->link($ticket),
                'key'      => 'maint_wf:' . $ticket->id . ':inspection_requested',
                'icon'     => 'wrench',
                'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle->plate_no, 'requested_by' => 'system'],
            ]);

            return $ticket->load($this->eager());
        });
    }

    /**
     * Stage 0 → Stage 1. The Inspector (Abu Maroof) picks up a Driver's request and starts the
     * diagnostic test drive. The request becomes a live diagnostic and the inspector handoff is
     * stamped; from here the existing two-stage decision (submitReport) takes over.
     *
     * The odometer reading is mandatory here too — captured before the drive as the chain's start
     * anchor and the car's canonical current mileage (requireTestOdometer / applyTestOdometer).
     *
     * @param array{test_odometer:int} $data
     */
    public function startDiagnostic(Maintenance $ticket, array $data, User $actor): Maintenance
    {
        $this->assertTransition($ticket, Maintenance::WF_INSPECTION_DIAGNOSTIC);

        $testOdometer = $this->requireTestOdometer($data);
        $odoNote      = $data['odometer_note'] ?? null;
        $vehicle      = $ticket->loadMissing('vehicle')->vehicle;

        // "Being Inspected" strict-match gate: the car is still in our park, so the start-of-drive reading
        // must match its current mileage (a +1..5 km drift needs a note; a bigger/backward gap is blocked).
        $this->assertStrictMatch($ticket, $actor, 'test_drive', $testOdometer, $vehicle?->odometer !== null ? (int) $vehicle->odometer : null, OdometerContinuityService::STAGE_TEST, $odoNote, 'test_odometer');

        return DB::transaction(function () use ($ticket, $vehicle, $actor, $testOdometer, $odoNote) {
            $ticket->workflow_status = Maintenance::WF_INSPECTION_DIAGNOSTIC;
            $ticket->event_status    = 'IN';
            $ticket->test_odometer   = $testOdometer; // start-of-drive anchor
            // Continuity check against the car's current mileage BEFORE we heal it forward (the heal runs
            // after save, so $vehicle->odometer here is still the prior reading).
            $this->recordOdometerFlag($ticket, 'test_drive', $testOdometer, $vehicle?->odometer !== null ? (int) $vehicle->odometer : null, OdometerContinuityService::STAGE_TEST, $odoNote, $actor);
            $ticket->inspected_by    = $actor->id;
            $ticket->inspected_at    = Carbon::now();
            $ticket->test_started_at = Carbon::now(); // downtime clock starts at the test drive
            $ticket->responsible     = $actor->name;
            $ticket->save();

            $this->cascade($ticket->vehicle_id);

            // The test-drive reading is the car's canonical current mileage — heal it forward.
            if ($vehicle) {
                $this->applyTestOdometer($vehicle, $testOdometer);
            }

            $this->log->record($ticket, VehicleLogEvent::EVENT_DIAGNOSTIC_STARTED, $actor, [
                'description' => 'Test drive started on a Driver request · odometer ' . number_format($testOdometer)
                                . ' km (by ' . $actor->name . ')',
                'meta'        => ['trigger_reason' => $ticket->trigger_reason, 'requested_by' => $ticket->requester?->name, 'test_odometer' => $testOdometer],
            ]);

            return $ticket->load($this->eager());
        });
    }

    // ── STAGE 1 — Inspector starts a diagnostic test drive (NOT a ticket yet) ────

    /**
     * Stage 1. Abu Maroof selects a vehicle + reason and starts a DIAGNOSTIC test drive. This is
     * deliberately NOT a maintenance ticket: it is born in `inspection_diagnostic`, raises no
     * Logistics alert, links no contract, and never marks the car "in maintenance" (event_status
     * stays 'IN'). Only the Stage-2 decision (submitReport + requires_maintenance) turns it into a
     * real ticket — so cars that don't need work never clutter the system as tickets.
     *
     * The odometer is captured HERE, before the drive — the first, mandatory link in the mileage
     * chain and the car's new canonical reading (see requireTestOdometer / applyTestOdometer).
     *
     * @param array{vehicle_id:int, trigger_reason:string, customer_complaint?:?string, test_odometer:int} $data
     */
    public function open(array $data, User $actor): Maintenance
    {
        $vehicleId = (int) ($data['vehicle_id'] ?? 0);
        $vehicle   = $vehicleId ? Vehicle::find($vehicleId) : null;
        if (! $vehicle) {
            throw new WorkflowTransitionException('A valid vehicle is required to start a diagnostic.', [
                'field' => 'vehicle_id',
            ]);
        }

        // Only an active-fleet car may enter the workflow — otherwise the diagnostic would be hidden
        // by the board's active-fleet filter the moment it's created.
        $this->assertActiveFleet($vehicle);

        $reason = $data['trigger_reason'] ?? null;
        if (! in_array($reason, Maintenance::TRIGGER_REASONS, true)) {
            throw new WorkflowTransitionException('Choose a reason: test drive, customer complaint, or routine maintenance.', [
                'field' => 'trigger_reason',
            ]);
        }

        // The odometer reading is mandatory before the test drive — the chain's start anchor.
        $testOdometer = $this->requireTestOdometer($data);

        // "Being Inspected" strict-match gate — the car is still in our park, so the start reading must match
        // its current mileage. Runs BEFORE the ticket is created, so a blocked attempt spawns no ticket; the
        // audit row is logged against the vehicle (maintenance_id null). A transient ticket carries the
        // vehicle so the block logger/notifier can name the car without a save.
        $guardCtx = new Maintenance();
        $guardCtx->vehicle_id = $vehicleId;
        $guardCtx->setRelation('vehicle', $vehicle);
        $this->assertStrictMatch($guardCtx, $actor, 'test_drive', $testOdometer, $vehicle->odometer !== null ? (int) $vehicle->odometer : null, OdometerContinuityService::STAGE_TEST, $data['odometer_note'] ?? null, 'test_odometer');

        return DB::transaction(function () use ($vehicle, $vehicleId, $reason, $data, $actor, $testOdometer) {
            $ticket = new Maintenance();
            $ticket->origin          = Maintenance::ORIGIN_MANUAL;
            $ticket->vehicle_id      = $vehicleId;
            $ticket->workflow_status = Maintenance::WF_INSPECTION_DIAGNOSTIC;
            $ticket->trigger_reason  = $reason;
            $ticket->test_odometer   = $testOdometer; // start-of-drive anchor
            // Continuity check against the car's current mileage BEFORE it's healed forward (see below).
            $this->recordOdometerFlag($ticket, 'test_drive', $testOdometer, $vehicle->odometer !== null ? (int) $vehicle->odometer : null, OdometerContinuityService::STAGE_TEST, $data['odometer_note'] ?? null, $actor);

            // A periodic visit is planned service → tag 'routine' so foresight's Chronic/Act-now
            // signals ignore it (see [[rental-first-policy]]). A reported fault stays standard.
            $ticket->visit_context = $reason === Maintenance::TRIGGER_PERIODIC
                ? Maintenance::CONTEXT_ROUTINE
                : 'standard';

            // Which intake tab this came from (Routine oil/battery/tyres vs Scheduled park-time) — a
            // traceability tag only; both are periodic tests flowing through the same pipeline.
            if (isset($data['test_kind']) && in_array($data['test_kind'], Maintenance::TEST_KINDS, true)) {
                $ticket->test_kind = $data['test_kind'];
            }

            if ($reason === Maintenance::TRIGGER_CUSTOMER) {
                $ticket->customer_complaint = $this->clean($data['customer_complaint'] ?? null);
            }

            // No maintenance_type at open: the test drive hasn't happened, so there's nothing to
            // classify yet. The Inspector sets it when filing the report (submitReport); a manager
            // can correct it afterwards via updateMaintenanceType().

            // Parked: not in the garage yet, so it must not read as an open garage event.
            $ticket->event_status = 'IN';
            $ticket->inspected_by = $actor->id;
            $ticket->inspected_at = Carbon::now();
            $ticket->test_started_at = Carbon::now(); // downtime clock starts at the test drive
            $ticket->responsible  = $actor->name;
            $ticket->save();

            // No status change here (car may be available or on rental) — but reconcile so a freshly
            // freed car is correct, and so the row is consistent with the rest of the cascade.
            $this->cascade($ticket->vehicle_id);

            // The test-drive reading is the car's canonical current mileage — heal it forward.
            $this->applyTestOdometer($vehicle, $testOdometer);

            $this->log->record($ticket, VehicleLogEvent::EVENT_DIAGNOSTIC_STARTED, $actor, [
                'description' => 'Test drive started — ' . $this->reasonLabel($reason)
                                . ' · odometer ' . number_format($testOdometer) . ' km (by ' . $actor->name . ')',
                'meta'        => ['trigger_reason' => $reason, 'test_odometer' => $testOdometer],
            ]);

            return $ticket->load($this->eager());
        });
    }

    // ── COMPLAINT INTAKE — Operations logs a customer complaint (no test drive) ───

    /**
     * Complaint Intake — the Operations controllers (Marwa & Leen) log a customer complaint against a
     * car. This is the customer-facing entry point of the workflow and, unlike the inspector's flow, it
     * deliberately SKIPS the test-drive/diagnostic: the customer has already described the fault and Ops
     * doesn't have the car in hand to read an odometer.
     *
     * The ticket is born in the TRIAGE lane (`complaint_triage`) — Abu Maroof's Pending-Triage queue,
     * NOT the garage-dispatch queue. He filters unnecessary garage trips out of the pipeline: he can
     * talk to the customer (logComplaintCall), resolve it on-site (resolveComplaintOnSite → terminal),
     * or send the car in (routeComplaint → garage dispatch OR his own diagnostic, optionally arranging a
     * replacement swap). Only THEN, if he routes to the garage, are the Supervisors alerted to assign one.
     *
     * Triage is a pre-ticket state (fenced like the diagnostic): it never drives operational_status, links
     * no contract and raises no garage alert. The complaint's identity rides on trigger_reason =
     * customer_reported (+ the board's "Customer Complaint" badge); the fault text lives in the purpose-built
     * `customer_complaint` column.
     *
     * One hand-off fires on submit: the Inspector (NOTIFY_INSPECTOR) is asked to triage.
     *
     * @param array{vehicle_id:int, fault_description:string, fault_severity:string} $data
     */
    public function openComplaint(array $data, User $actor): Maintenance
    {
        $vehicleId = (int) ($data['vehicle_id'] ?? 0);
        $vehicle   = $vehicleId ? Vehicle::find($vehicleId) : null;
        if (! $vehicle) {
            throw new WorkflowTransitionException('A valid vehicle is required to log a complaint.', [
                'field' => 'vehicle_id',
            ]);
        }

        // Only an active-fleet car may enter the workflow — otherwise the ticket would be hidden by the
        // board's active-fleet filter the moment it's created.
        $this->assertActiveFleet($vehicle);

        $complaint = $this->clean($data['fault_description'] ?? null);
        if ($complaint === null) {
            throw new WorkflowTransitionException('Describe the customer\'s complaint before submitting.', [
                'field' => 'fault_description',
            ]);
        }

        // Ops no longer grades urgency at intake — the Inspector assigns the fault severity later in the
        // diagnostic. Default to 'moderate' so the ticket has a valid headline grade until then.
        $faultSeverity = $data['fault_severity'] ?? null;
        if (! in_array($faultSeverity, Maintenance::FAULT_SEVERITIES, true)) {
            $faultSeverity = 'moderate';
        }

        // Resolve WHO currently has the car — the customer on the open rental (type-'C') contract.
        // A complaint is, by definition, raised by the renter, so we name them in the dispatch alert
        // and the audit trail (and the intake form surfaces the same context). Best-effort: stays
        // null if the car isn't actually on rent right now (nothing depends on it being present).
        $rental     = $vehicle->contracts()
            ->where('contract_type', 'C')
            ->currentlyOpen()
            ->with('customer')
            ->latest('id')
            ->first();
        $renter     = $rental?->customer;
        $renterName = $renter?->name_en ?: ($renter?->name_ar ?: null);

        return DB::transaction(function () use ($vehicleId, $complaint, $faultSeverity, $actor, $rental, $renterName) {
            $ticket = new Maintenance();
            $ticket->origin          = Maintenance::ORIGIN_MANUAL;
            $ticket->vehicle_id      = $vehicleId;
            // Born in the TRIAGE lane (Abu Maroof) — a complaint no longer jumps to the garage-dispatch
            // queue. He decides how to handle it (talk / resolve on-site / send in) before any garage.
            $ticket->workflow_status = Maintenance::WF_COMPLAINT_TRIAGE;
            $ticket->trigger_reason  = Maintenance::TRIGGER_CUSTOMER;
            $ticket->visit_context   = 'standard';
            $ticket->customer_complaint = $complaint;
            $ticket->fault_severity  = $faultSeverity;
            $ticket->severity        = $faultSeverity; // the board reads `severity` as the headline urgency
            // Default the classification to Routine Maintenance (a Supervisor can reclassify via
            // updateMaintenanceType); the true type is confirmed once the garage diagnoses the fault.
            $ticket->maintenance_type = Maintenance::TYPE_ROUTINE;
            // Parked ('IN'): the car isn't in the garage yet, so it must not read as an open garage event.
            $ticket->event_status    = 'IN';
            // Ops logged it → they are the requester, so the "car is back" loop routes to them too.
            $ticket->requested_by    = $actor->id;
            $ticket->requested_at    = Carbon::now();
            $ticket->responsible     = $actor->name;
            $ticket->save();

            // No status steal — reconcile so a freshly freed car reads correctly (the car may be
            // available, on rental, or already parked; opening a complaint never changes that).
            $this->cascade($ticket->vehicle_id);

            $this->log->record($ticket, VehicleLogEvent::EVENT_REPORT_FILED, $actor, [
                'description' => 'Customer complaint logged'
                                . ($renterName ? ' by ' . $renterName : '')
                                . ': “' . $complaint . '” (by ' . $actor->name . ')',
                'meta'        => [
                    'trigger_reason' => Maintenance::TRIGGER_CUSTOMER,
                    'fault_severity' => $faultSeverity,
                    'source'         => 'complaint_intake',
                    // WHO reported it — the renter on the car's open contract (best-effort).
                    'customer'       => $renterName,
                    'contract_id'    => $rental?->id,
                    'contract_no'    => $rental?->contract_no,
                ],
            ]);

            $vehicle  = $ticket->loadMissing('vehicle')->vehicle;
            $sevMeta  = Maintenance::FAULT_SEVERITY_META[$faultSeverity] ?? null;
            $sevTail  = $sevMeta ? ' · ' . $sevMeta['emoji'] . ' ' . $sevMeta['label'] : '';

            // Single hand-off — the Inspector (Abu Maroof) OWNS the triage. The Supervisors are NOT alerted
            // to assign a garage yet: that only happens if Abu Maroof decides the car has to go in (see
            // routeComplaint → the garage-dispatch alert fires there). This keeps unnecessary garage trips
            // out of the pipeline — he can just talk to the customer or resolve it on-site.
            $this->notifier->notifyByPermission(self::NOTIFY_INSPECTOR, [
                'type'     => 'maint_complaint_triage',
                'category' => 'maintenance',
                'severity' => $sevMeta['severity'] ?? 'warning',
                'title'    => trim('📣 Customer complaint — triage · ' . $this->label($vehicle)),
                'body'     => trim($actor->name . ' logged a customer complaint'
                                . ($renterName ? ' from ' . $renterName : '')
                                . ' on ' . $this->label($vehicle)
                                . ' — “' . $complaint . '”. Triage it: talk to the customer, resolve on-site, or send the car in.'),
                'url'      => $this->link($ticket),
                'key'      => 'maint_wf:' . $ticket->id . ':complaint_triage',
                'icon'     => 'bell',
                'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no, 'fault_severity' => $faultSeverity, 'customer_complaint' => true, 'customer' => $renterName, 'contract_no' => $rental?->contract_no],
            ], $actor->id);

            return $ticket->load($this->eager());
        });
    }

    // ── CUSTOMER-COMPLAINT TRIAGE (Abu Maroof) ──────────────────────────────────

    /**
     * Triage action #1 — "Spoke with the customer". Abu Maroof records that a conversation took place;
     * it is a logged touchpoint, NOT a resolution, so the ticket stays in the triage lane. Repeatable
     * (a complaint may need several calls). The note is optional — the button alone marks the contact.
     */
    public function logComplaintCall(Maintenance $ticket, ?string $note, User $actor): Maintenance
    {
        $this->assertComplaintTriage($ticket);

        return DB::transaction(function () use ($ticket, $note, $actor) {
            $text  = $this->clean($note);
            $entry = [
                'type'  => 'customer_call',
                'text'  => $text ?: 'Spoke with the customer',
                'by'    => $actor->name,
                'by_id' => $actor->id,
                'at'    => Carbon::now()->toIso8601String(),
            ];
            $ticket->follow_ups = collect($ticket->follow_ups ?? [])->push($entry)->values()->all();
            $ticket->save();

            $this->log->record($ticket, VehicleLogEvent::EVENT_STATUS_UPDATE, $actor, [
                'description' => 'Spoke with the customer about the complaint'
                                . ($text ? ': “' . $text . '”' : '')
                                . ' (by ' . $actor->name . ')',
                'meta'        => ['source' => 'complaint_triage', 'action' => 'customer_call'],
            ]);

            // Keep Ops (the controllers who logged it) in the loop that contact was made.
            $vehicle = $ticket->loadMissing('vehicle')->vehicle;
            $this->notifier->notifyByPermission(self::NOTIFY_CONTROLLERS, [
                'type'     => 'maint_complaint_call',
                'category' => 'maintenance',
                'severity' => 'info',
                'title'    => '📞 Customer contacted · ' . $this->label($vehicle),
                'body'     => trim($actor->name . ' spoke with the customer about the complaint on ' . $this->label($vehicle)
                                . ($text ? ' — “' . $text . '”.' : '.')),
                'url'      => $this->link($ticket),
                'key'      => 'maint_wf:' . $ticket->id . ':complaint_call:' . Carbon::now()->timestamp,
                'icon'     => 'bell',
                'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no],
            ], $actor->id);

            return $ticket->load($this->eager());
        });
    }

    /**
     * Triage action #2 — "Resolved on site". The issue was handled at the customer (a quick fix, or the
     * customer was simply told a replacement is coming and the car itself needs nothing) — so NO garage
     * trip is needed. Closes the complaint as resolved (terminal `complaint_resolved`); no cost/garage is
     * required. The car never left the customer, so its operational status is untouched (cascade only
     * re-reconciles derived state).
     */
    public function resolveComplaintOnSite(Maintenance $ticket, ?string $note, User $actor): Maintenance
    {
        $this->assertTransition($ticket, Maintenance::WF_COMPLAINT_RESOLVED);

        return DB::transaction(function () use ($ticket, $note, $actor) {
            $text = $this->clean($note);
            $ticket->workflow_status = Maintenance::WF_COMPLAINT_RESOLVED;
            $ticket->event_status    = 'IN';           // never became a garage event
            $ticket->wf_closed_by    = $actor->id;
            $ticket->wf_closed_at    = Carbon::now();
            $ticket->garage_feedback = $text ?: $ticket->garage_feedback;
            $ticket->save();

            $this->cascade($ticket->vehicle_id);       // no status change, but keeps derived state honest

            $this->log->record($ticket, VehicleLogEvent::EVENT_CLOSED, $actor, [
                'description' => 'Customer complaint resolved on-site — no garage visit'
                                . ($text ? ': “' . $text . '”' : '')
                                . ' (by ' . $actor->name . ')',
                'meta'        => ['source' => 'complaint_triage', 'resolution' => 'on_site'],
            ]);

            $vehicle = $ticket->loadMissing('vehicle')->vehicle;
            $this->notifier->notifyByPermission(self::NOTIFY_CONTROLLERS, [
                'type'     => 'maint_complaint_onsite_resolved',
                'category' => 'maintenance',
                'severity' => 'success',
                'title'    => '✅ Complaint resolved on-site · ' . $this->label($vehicle),
                'body'     => trim($actor->name . ' resolved the customer complaint on ' . $this->label($vehicle)
                                . ' on-site — no garage visit needed'
                                . ($text ? '. “' . $text . '”' : '.')),
                'url'      => $this->link($ticket),
                'key'      => 'maint_wf:' . $ticket->id . ':complaint_resolved',
                'icon'     => 'check',
                'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no, 'customer_complaint' => true],
            ], $actor->id);

            return $ticket->load($this->eager());
        });
    }

    /**
     * Triage action #3 — "Send the car in". Abu Maroof decides the complaint needs real work, so the car
     * leaves the customer. He controls WHERE it goes:
     *   - destination 'garage'     → the Supervisors' garage-dispatch queue (inspection_pending). ONLY NOW
     *                                are the Supervisors alerted to assign a garage (the standard flow).
     *   - destination 'diagnostic' → his own inspection queue (inspection_requested) so his team test-drives
     *                                and diagnoses it before committing to a garage.
     * Optionally he arranges a REPLACEMENT for the customer in the same move — recorded on the Maintenance
     * Swap board (the complained car = the original, the chosen pool car = the replacement), so the fleet's
     * one swap system stays the source of truth for "who is driving what".
     *
     * @param array{destination:string, replacement_vehicle_id?:?int, note?:?string} $data
     */
    public function routeComplaint(Maintenance $ticket, array $data, User $actor): Maintenance
    {
        $destination = $data['destination'] ?? null;
        $target = match ($destination) {
            'garage'     => Maintenance::WF_INSPECTION_PENDING,
            'diagnostic' => Maintenance::WF_INSPECTION_REQUESTED,
            default      => null,
        };
        if ($target === null) {
            throw new WorkflowTransitionException('Choose where to send the car: garage dispatch or diagnostic.', [
                'field' => 'destination',
            ]);
        }
        // Also enforces "only from triage" — TRANSITIONS[complaint_triage] is the only source of these targets.
        $this->assertTransition($ticket, $target);

        // Resolve WHO currently has the car — the renter on the open 'C' contract — so a replacement swap
        // is attributed to the right customer + contract (best-effort; null if the car isn't on rent).
        $vehicle    = $ticket->loadMissing('vehicle')->vehicle;
        $rental     = $vehicle?->contracts()->where('contract_type', 'C')->currentlyOpen()->with('customer')->latest('id')->first();
        $renter     = $rental?->customer;
        $renterName = $renter?->name_en ?: ($renter?->name_ar ?: null);

        $note          = $this->clean($data['note'] ?? null);
        $replacementId = ! empty($data['replacement_vehicle_id']) ? (int) $data['replacement_vehicle_id'] : null;

        return DB::transaction(function () use ($ticket, $target, $destination, $replacementId, $note, $actor, $vehicle, $rental, $renterName) {
            // (a) Arrange the replacement, if one was chosen — on the shared Maintenance Swap board.
            $swap = $replacementId ? $this->arrangeReplacement($ticket, $replacementId, $rental, $renterName, $actor) : null;

            // (b) Move the ticket. The garage path makes it a committed ticket (inspection_pending); the
            // diagnostic path re-enters the inspector's pre-ticket inspection queue (inspection_requested).
            $ticket->workflow_status = $target;
            $ticket->event_status    = 'IN';   // still parked — a driver hasn't taken it to a garage yet
            if ($note) {
                $ticket->garage_feedback = $note;
            }
            $ticket->save();

            $this->cascade($ticket->vehicle_id);

            $sentTo = $destination === 'garage' ? 'garage dispatch' : 'diagnostic inspection';
            $this->log->record(
                $ticket,
                $destination === 'garage' ? VehicleLogEvent::EVENT_GARAGE_ASSIGNED : VehicleLogEvent::EVENT_INSPECTION_REQUESTED,
                $actor,
                [
                    'description' => 'Complaint sent to ' . $sentTo
                                    . ($swap ? ' — replacement ' . ($swap->replacement_plate ?: '#' . $swap->replacement_vehicle_id) . ' given to the customer' : '')
                                    . ($note ? ': “' . $note . '”' : '')
                                    . ' (by ' . $actor->name . ')',
                    'meta'        => ['source' => 'complaint_triage', 'destination' => $destination, 'swap_id' => $swap?->id],
                ]
            );

            // Alert whoever acts next on the chosen path.
            if ($destination === 'garage') {
                // NOW the Supervisors are asked to assign a garage — the prioritised complaint dispatch.
                $sevMeta = Maintenance::FAULT_SEVERITY_META[$ticket->fault_severity] ?? null;
                $sevTail = $sevMeta ? ' · ' . $sevMeta['emoji'] . ' ' . $sevMeta['label'] : '';
                $this->notifier->notifyByPermission(self::NOTIFY_DISPATCHER, [
                    'type'     => 'maint_complaint_intake',
                    'category' => 'maintenance',
                    'severity' => $sevMeta['severity'] ?? 'warning',
                    'title'    => trim('📣 Customer complaint' . $sevTail . ' · ' . $this->label($vehicle)),
                    'body'     => trim('Abu Maroof sent a customer complaint in on ' . $this->label($vehicle)
                                    . ($renterName ? ' (from ' . $renterName . ')' : '')
                                    . $sevTail . ' — “' . ($ticket->customer_complaint ?: 'see ticket') . '”. Assign a garage now (priority).'),
                    'url'      => $this->link($ticket),
                    'key'      => 'maint_wf:' . $ticket->id . ':inspection_pending',
                    'icon'     => 'wrench',
                    'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no, 'customer_complaint' => true, 'customer' => $renterName],
                ], $actor->id);
            } else {
                // The diagnostic path re-enters the inspector's own queue (his team test-drives it).
                $this->notifier->notifyByPermission(self::NOTIFY_INSPECTOR, [
                    'type'     => 'maint_complaint_diagnostic',
                    'category' => 'maintenance',
                    'severity' => 'warning',
                    'title'    => '🔍 Inspect customer complaint · ' . $this->label($vehicle),
                    'body'     => trim('Abu Maroof sent a customer complaint in for diagnostic on ' . $this->label($vehicle)
                                    . ' — start the test drive when the car is in.'),
                    'url'      => $this->link($ticket),
                    'key'      => 'maint_wf:' . $ticket->id . ':inspection_requested',
                    'icon'     => 'bell',
                    'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no, 'customer_complaint' => true],
                ], $actor->id);
            }

            return $ticket->load($this->eager());
        });
    }

    /**
     * Record a replacement car for the customer on the shared Maintenance Swap board (original = the
     * complained car, replacement = the chosen pool car). Guards against double-booking either car — the
     * same rules the swap board's own store() enforces. Returns the created swap, or null if a swap already
     * covers this rental (idempotent: a repeat route call won't stack duplicate swaps).
     */
    private function arrangeReplacement(Maintenance $ticket, int $replacementId, $rental, ?string $renterName, User $actor): ?MaintenanceSwap
    {
        if ($replacementId === (int) $ticket->vehicle_id) {
            throw new WorkflowTransitionException('The replacement must be a different car.', ['field' => 'replacement_vehicle_id']);
        }
        if (! Vehicle::whereKey($replacementId)->exists()) {
            throw new WorkflowTransitionException('The chosen replacement car does not exist.', ['field' => 'replacement_vehicle_id']);
        }
        if (MaintenanceSwap::active()->where('replacement_vehicle_id', $replacementId)->exists()) {
            throw new WorkflowTransitionException('That replacement car is already standing in for another rental.', ['field' => 'replacement_vehicle_id']);
        }
        // Already have a replacement for this rental → don't stack a second one.
        if (MaintenanceSwap::active()->where('original_vehicle_id', $ticket->vehicle_id)->exists()) {
            return null;
        }

        $orig = Vehicle::find($ticket->vehicle_id);
        $repl = Vehicle::find($replacementId);

        return MaintenanceSwap::create([
            'original_vehicle_id'    => $ticket->vehicle_id,
            'original_plate'         => $orig?->plate_no,
            'original_car'           => $orig ? (trim($orig->make . ' ' . $orig->model) ?: null) : null,
            'original_contract_id'   => $rental?->id,
            'original_contract_no'   => $rental?->contract_no,
            'tenant_name'            => $renterName,
            'reason'                 => 'Customer complaint #' . $ticket->id,
            'replacement_vehicle_id' => $replacementId,
            'replacement_plate'      => $repl?->plate_no,
            'replacement_car'        => $repl ? (trim($repl->make . ' ' . $repl->model) ?: null) : null,
            'status'                 => MaintenanceSwap::STATUS_ACTIVE,
            'assigned_by'            => $actor->name ?: $actor->email,
            'notes'                  => 'Arranged from complaint triage',
        ]);
    }

    /** Guard: an action is only valid while the complaint sits in the triage lane. */
    private function assertComplaintTriage(Maintenance $ticket): void
    {
        if ($ticket->workflow_status !== Maintenance::WF_COMPLAINT_TRIAGE) {
            throw new WorkflowTransitionException('This action is only available while the complaint is in triage.', [
                'workflow_status' => $ticket->workflow_status,
            ]);
        }
    }

    // ── BREAKDOWN INTAKE — a not-driveable car logged directly (no test drive) ────

    /**
     * Breakdown Intake — the EMERGENCY entry point. Abu Maroof (or a manager) logs a car that is NOT
     * driveable: it has failed and needs immediate intervention. Like a complaint it deliberately SKIPS
     * the test-drive diagnostic — you cannot road-test a dead car — so the ticket is born straight in
     * `inspection_pending` (the Supervisors' dispatch queue). What makes a Breakdown distinct, and why
     * it is its own method rather than a maintenance_type on the normal flow:
     *   - the car is GROUNDED on the spot — condition_grade → red (a hard booking barrier, hidden from
     *     the Available pool) and, because a breakdown is a committed ticket, operational_status →
     *     maintenance once cascade() runs;
     *   - it is classified `breakdown` and graded 🔴 critical automatically (a breakdown is, by
     *     definition, the top urgency — nothing to ask).
     * A brief description of the failure is mandatory. The grounding + critical grade live in
     * applyBreakdownConsequences() so the exact same rules fire when a breakdown is instead discovered
     * at the Decide step or set by a manager reclassification.
     *
     * @param array{vehicle_id:int, fault_description:string} $data
     */
    public function openBreakdown(array $data, User $actor): Maintenance
    {
        $vehicleId = (int) ($data['vehicle_id'] ?? 0);
        $vehicle   = $vehicleId ? Vehicle::find($vehicleId) : null;
        if (! $vehicle) {
            throw new WorkflowTransitionException('A valid vehicle is required to report a breakdown.', [
                'field' => 'vehicle_id',
            ]);
        }

        // Only an active-fleet car may enter the workflow — otherwise the ticket would be hidden by the
        // board's active-fleet filter the moment it's created.
        $this->assertActiveFleet($vehicle);

        $description = $this->clean($data['fault_description'] ?? null);
        if ($description === null) {
            throw new WorkflowTransitionException('Describe the failure before reporting a breakdown.', [
                'field' => 'fault_description',
            ]);
        }

        return DB::transaction(function () use ($vehicle, $vehicleId, $description, $actor) {
            $ticket = new Maintenance();
            $ticket->origin          = Maintenance::ORIGIN_MANUAL;
            $ticket->vehicle_id      = $vehicleId;
            // Born directly in the Supervisors' dispatch queue — a breakdown skips the (impossible) drive.
            $ticket->workflow_status = Maintenance::WF_INSPECTION_PENDING;
            $ticket->trigger_reason  = Maintenance::TRIGGER_BREAKDOWN;
            // A real failure — must NOT be tagged 'routine', or foresight would ignore it as planned.
            $ticket->visit_context   = 'standard';
            $ticket->customer_complaint = $description;
            // A breakdown is, by definition, the top urgency.
            $ticket->fault_severity  = Maintenance::FAULT_SEVERITY_CRITICAL;
            $ticket->severity        = Maintenance::FAULT_SEVERITY_CRITICAL; // the board reads `severity`
            $ticket->maintenance_type = Maintenance::TYPE_BREAKDOWN;
            // Parked ('IN'): the car isn't in the garage yet, so it must not read as an open garage event.
            $ticket->event_status    = 'IN';
            $ticket->requested_by    = $actor->id;
            $ticket->requested_at    = Carbon::now();
            $ticket->responsible     = $actor->name;
            $ticket->save();

            // Ground the car (condition_grade → red) — then reconcile so operational_status reads 'maintenance'.
            $this->applyBreakdownConsequences($ticket, $vehicle, $actor, $description);
            $this->cascade($ticket->vehicle_id);

            $this->log->record($ticket, VehicleLogEvent::EVENT_REPORT_FILED, $actor, [
                'description' => 'Breakdown reported — car grounded (RED): “' . $description . '” (by ' . $actor->name . ')',
                'meta'        => ['trigger_reason' => Maintenance::TRIGGER_BREAKDOWN, 'maintenance_type' => Maintenance::TYPE_BREAKDOWN, 'fault_severity' => Maintenance::FAULT_SEVERITY_CRITICAL, 'source' => 'breakdown_intake'],
            ]);

            $vehicle = $ticket->loadMissing('vehicle')->vehicle;

            // Hand-off #1 — the Supervisors (Waleed/Abdullah) act next: assign a garage NOW. Critical
            // severity so the dispatch alert reads as the emergency it is.
            $this->notifier->notifyByPermission(self::NOTIFY_DISPATCHER, [
                'type'     => 'maint_breakdown_intake',
                'category' => 'maintenance',
                'severity' => 'critical',
                'title'    => trim('🔴 BREAKDOWN · ' . $this->label($vehicle)),
                'body'     => trim($actor->name . ' reported a breakdown on ' . $this->label($vehicle)
                                . ' — “' . $description . '”. The car is grounded — assign a garage immediately.'),
                'url'      => $this->link($ticket),
                'key'      => 'maint_wf:' . $ticket->id . ':inspection_pending',
                'icon'     => 'wrench',
                'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no, 'breakdown' => true, 'fault_severity' => Maintenance::FAULT_SEVERITY_CRITICAL],
            ], $actor->id);

            // Hand-off #2 — heads-up to the Inspector (Abu Maroof): this car will come back for the
            // final re-inspection sign-off.
            $this->notifier->notifyByPermission(self::NOTIFY_INSPECTOR, [
                'type'     => 'maint_breakdown_headsup',
                'category' => 'maintenance',
                'severity' => 'warning',
                'title'    => trim('Heads-up · breakdown incoming · ' . $this->label($vehicle)),
                'body'     => trim($this->label($vehicle) . ' broke down — “' . $description . '”. Expect it for the final re-inspection.'),
                'url'      => $this->link($ticket),
                'key'      => 'maint_wf:' . $ticket->id . ':breakdown_headsup',
                'icon'     => 'bell',
                'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no, 'fault_severity' => Maintenance::FAULT_SEVERITY_CRITICAL],
            ], $actor->id);

            return $ticket->load($this->eager());
        });
    }

    /**
     * The consequences EVERY breakdown inherits, no matter how it came to be classified `breakdown` —
     * reported directly (openBreakdown), chosen by the inspector at the Decide step (submitReport), or
     * set by a manager reclassification (updateMaintenanceType):
     *   1. GROUND the car — condition_grade → red, which the booking guard (ContractService) treats as a
     *      hard barrier and the Available counts hide. Stamped with who/when so the grounding is auditable.
     *   2. FORCE 🔴 critical severity — an undriveable car is, by definition, the top urgency.
     * Idempotent: re-applying (e.g. a submitReport right after openBreakdown) only re-stamps the same
     * state, and each write is guarded so it is a no-op when already set. The CALLER owns cascade() so
     * operational_status is reconciled once, after the vehicle row is saved.
     */
    private function applyBreakdownConsequences(Maintenance $ticket, Vehicle $vehicle, User $actor, ?string $note = null): void
    {
        // Force 🔴 critical severity — an undriveable car is, by definition, the top urgency, so it can
        // NEVER read as moderate/routine no matter which entry point set the type (intake, the inspector's
        // Decide step, or a manager reclassification). This keeps `fault_severity` (the grade) AND the
        // board's `severity` headline in lock-step with the RED grounding below.
        if ($ticket->fault_severity !== Maintenance::FAULT_SEVERITY_CRITICAL) {
            $ticket->fault_severity = Maintenance::FAULT_SEVERITY_CRITICAL;
            $ticket->severity       = Maintenance::FAULT_SEVERITY_CRITICAL; // board reads `severity` as headline urgency
            $ticket->save();
        }

        // Ground the car from the rental interface (red = hard booking barrier). Never downgrade an
        // already-red grade; only escalate to red when it isn't already grounded.
        if ($vehicle->condition_grade !== 'red') {
            $previousGrade = $vehicle->condition_grade;
            $vehicle->condition_grade     = 'red';
            $vehicle->condition_note      = trim('Breakdown' . ($note ? ': ' . $note : '')) . ' (auto — grounded by ' . $actor->name . ')';
            $vehicle->condition_graded_at = Carbon::now();
            $vehicle->condition_graded_by = $actor->id;
            $vehicle->save();

            // Traceability: this auto-ground bypasses VehicleController::updateCondition, so emit the
            // condition_graded event ourselves — otherwise the grade change to RED happens silently and
            // the vehicle timeline would show the breakdown ticket but not the grounding it caused.
            $this->log->recordVehicle($vehicle, VehicleLogEvent::EVENT_CONDITION_GRADED, $actor, [
                'description' => 'Grounded ' . ($previousGrade ?: 'none') . ' → red (breakdown)',
                'meta'        => ['pillar' => 'Damage Assessment', 'from' => $previousGrade, 'to' => 'red', 'auto' => true, 'trigger' => 'breakdown', 'maintenance_id' => $ticket->id],
            ]);
        }
    }

    // ── INSPECTOR'S-PAD PICK-UP — odometer-gated intake that folds pending flags into a ticket ────

    /**
     * Pick Up for maintenance. The odometer-gated intake the Inspector's Pad feeds: a user takes a car
     * in, enters the mandatory current odometer, and the system mints a real maintenance ticket carrying
     * every flag Abu Maroof pre-logged on the pad for that car. Deliberately mirrors openComplaint —
     * the ticket is born straight in the Supervisors' dispatch queue (inspection_pending, a real
     * WF_TICKET_STATE), so cascade() flips the car to operational_status = maintenance automatically. It
     * skips the test drive (like breakdown), which is why it carries its own trigger_reason.
     *
     * The pending pad flags are copied onto the ticket's `findings` (source = inspector) so the mechanic
     * sees them immediately, and each flag is stamped `consumed` + linked to this ticket — the ticket now
     * owns the fault; the flag becomes an audit trail, never a second source of truth.
     *
     * Guards: an active-fleet car only, a positive odometer (the hard gate — no ticket without it), and
     * one open ticket per car (a car already in the pipeline can't be picked up again).
     */
    public function pickupIntoMaintenance(Vehicle $vehicle, int $odometer, ?string $note, User $actor): Maintenance
    {
        $this->assertActiveFleet($vehicle);

        if ($odometer <= 0) {
            throw new WorkflowTransitionException('Enter the current odometer reading to pick the car up.', [
                'field' => 'odometer',
            ]);
        }

        // One open ticket per car — don't mint a duplicate for a car already in the maintenance pipeline.
        if ($this->operations->vehicleInMaintenance($vehicle->id)) {
            throw new WorkflowTransitionException(
                'This car already has an open maintenance ticket — open it instead of picking it up again.',
                ['field' => 'vehicle_id']
            );
        }

        $note = $this->clean($note);

        return DB::transaction(function () use ($vehicle, $odometer, $note, $actor) {
            // Pull every pending pad flag for this car (oldest first), with its author for attribution.
            $flags = InspectorPadFlag::pending()
                ->with('creator')
                ->where('vehicle_id', $vehicle->id)
                ->orderBy('id')
                ->get();

            // The ticket's headline grade = the worst severity among the flags (ungraded → routine).
            $rank  = [
                Maintenance::FAULT_SEVERITY_ROUTINE  => 1,
                Maintenance::FAULT_SEVERITY_MODERATE => 2,
                Maintenance::FAULT_SEVERITY_HIGH     => 3,
                Maintenance::FAULT_SEVERITY_CRITICAL => 4,
            ];
            $faultSeverity = Maintenance::FAULT_SEVERITY_ROUTINE;
            foreach ($flags as $flag) {
                if (isset($rank[$flag->severity]) && $rank[$flag->severity] > $rank[$faultSeverity]) {
                    $faultSeverity = $flag->severity;
                }
            }

            // Copy each flag onto the ticket's findings using the same shape submitReport writes.
            $findings = [];
            foreach ($flags as $flag) {
                $text = $flag->label();
                if ($text === '') {
                    continue;
                }
                $findings[] = [
                    'text'          => $text,
                    'source'        => Maintenance::FINDING_INSPECTOR,
                    'severity'      => in_array($flag->severity, Maintenance::FAULT_SEVERITIES, true) ? $flag->severity : $faultSeverity,
                    'root_cause'    => null,
                    'root_cause_id' => null,
                    'by'            => $flag->creator->name ?? $actor->name,
                    'at'            => optional($flag->created_at)->toIso8601String() ?? Carbon::now()->toIso8601String(),
                ];
            }

            $ticket = new Maintenance();
            $ticket->origin           = Maintenance::ORIGIN_MANUAL;
            $ticket->vehicle_id       = $vehicle->id;
            // Born in the Supervisors' dispatch queue — a pick-up skips the diagnostic (like a complaint).
            $ticket->workflow_status  = Maintenance::WF_INSPECTION_PENDING;
            $ticket->trigger_reason   = Maintenance::TRIGGER_PICKUP;
            $ticket->visit_context    = 'standard';
            $ticket->maintenance_type = Maintenance::TYPE_ROUTINE; // a Supervisor can reclassify on diagnosis
            $ticket->fault_severity   = $faultSeverity;
            $ticket->severity         = $faultSeverity;            // the board reads `severity` as the headline
            $ticket->findings         = $findings;
            $ticket->intake_odometer  = $odometer;                // the mandatory gate, stamped on the ticket
            $ticket->maintenance_notes = $note;
            // Parked ('IN'): the car isn't at the garage yet, so it must not read as an open garage event.
            $ticket->event_status     = 'IN';
            $ticket->requested_by     = $actor->id;
            $ticket->requested_at     = Carbon::now();
            $ticket->responsible      = $actor->name;
            $ticket->save();

            // Fold the flags into the ticket — one bulk stamp so they leave the pad's pending board.
            if ($flags->isNotEmpty()) {
                InspectorPadFlag::whereIn('id', $flags->pluck('id'))->update([
                    'status'                     => InspectorPadFlag::STATUS_CONSUMED,
                    'consumed_by_maintenance_id' => $ticket->id,
                    'consumed_at'                => Carbon::now(),
                ]);
            }

            // The ticket is a committed WF_TICKET_STATE → the car reconciles to operational_status = maintenance.
            $this->cascade($ticket->vehicle_id);

            $count = $flags->count();
            $this->log->record($ticket, VehicleLogEvent::EVENT_REPORT_FILED, $actor, [
                'description' => 'Picked up for maintenance at ' . number_format($odometer) . ' km — '
                                . $count . ' inspector flag' . ($count === 1 ? '' : 's') . ' attached'
                                . ' (by ' . $actor->name . ')',
                'meta'        => [
                    'trigger_reason'  => Maintenance::TRIGGER_PICKUP,
                    'fault_severity'  => $faultSeverity,
                    'intake_odometer' => $odometer,
                    'flags'           => $count,
                    'source'          => 'inspector_pad_pickup',
                ],
            ]);

            $vehicle = $ticket->loadMissing('vehicle')->vehicle;
            $sevMeta = Maintenance::FAULT_SEVERITY_META[$faultSeverity] ?? null;
            $sevTail = $sevMeta ? ' · ' . $sevMeta['emoji'] . ' ' . $sevMeta['label'] : '';

            // Hand-off — the Supervisors (Waleed/Abdullah) act next: assign a garage.
            $this->notifier->notifyByPermission(self::NOTIFY_DISPATCHER, [
                'type'     => 'maint_pickup_intake',
                'category' => 'maintenance',
                'severity' => $sevMeta['severity'] ?? 'warning',
                'title'    => trim('🔧 Picked up for maintenance' . $sevTail . ' · ' . $this->label($vehicle)),
                'body'     => trim($actor->name . ' picked up ' . $this->label($vehicle) . ' for maintenance'
                                . ' at ' . number_format($odometer) . ' km'
                                . ($count ? ' with ' . $count . ' inspector flag' . ($count === 1 ? '' : 's') : '')
                                . '. Assign a garage.'),
                'url'      => $this->link($ticket),
                'key'      => 'maint_wf:' . $ticket->id . ':inspection_pending',
                'icon'     => 'wrench',
                'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no, 'fault_severity' => $faultSeverity, 'flags' => $count],
            ], $actor->id);

            return $ticket->load($this->eager());
        });
    }

    // ── Symptom → Root-Cause diagnostic resolution ──────────────────────────────

    /**
     * Resolve the root cause a user picked (or typed) for one symptom into a canonical fault_causes
     * row, returning `[label, id]` to stamp onto the finding. This is what makes the diagnostic
     * structured + Odoo-syncable: every chosen cause maps to a stable row.
     *
     *   - Empty cause → [null, null] (the symptom carries no cause; the picker enforces selection,
     *     but the server never hard-fails a finding for a missing cause).
     *   - Known cause (from the picker, carries an id that matches the symptom) → reuse that row.
     *   - Custom cause (typed, no/foreign id) → firstOrCreate a row keyed on (symptom, cause). A
     *     genuinely new pair lands `pending` for admin review; one that happens to match an existing
     *     approved cause simply reuses it. Either way the finding gets a real fault_causes id.
     *
     * `usage_count` is bumped on every use so the analytics ("why do Jetour T2s overheat?") are free.
     */
    private function resolveFaultCause(?string $symptomLabel, ?string $causeLabel, $causeId, User $actor): array
    {
        $causeLabel = $this->clean($causeLabel);
        if ($causeLabel === null) {
            return [null, null];
        }

        $symptomKey = FaultCause::normalizeKey($symptomLabel);

        // Trust an id only if it actually belongs to this symptom (guards against a stale/mismatched
        // client payload silently mis-attributing a cause).
        $row = $causeId ? FaultCause::find($causeId) : null;
        if (! $row || $row->symptom_key !== $symptomKey) {
            $row = FaultCause::firstOrCreate(
                ['symptom_key' => $symptomKey, 'root_cause' => $causeLabel],
                [
                    'symptom_label' => $symptomLabel ?: $causeLabel,
                    'category_key'  => $this->categoryForSymptom($symptomLabel),
                    'status'        => FaultCause::STATUS_PENDING, // → flagged for administrative review
                    'source'        => FaultCause::SOURCE_USER,
                    'submitted_by'  => $actor->id,
                ],
            );
        }

        $row->increment('usage_count');

        return [$row->root_cause, $row->id];
    }

    /**
     * Reality-check a finding's text against the car's LIVE diagnostic status. Non-null only when the
     * text is one of the monitored routines (Oil Change / Battery Replacement / Tire Rotation / Tire
     * Change) AND the vehicle's actual status says it is NOT due right now — someone is logging a
     * scheduled service the car doesn't need. Stamped onto the finding at creation time (before
     * completing it would roll the reminder forward and change the status), so it stays an honest
     * snapshot of what was true the moment it was reported.
     *
     * @return array{status:string,summary:string}|null
     */
    private function statusConflictFor(string $text, ?Vehicle $vehicle): ?array
    {
        $serviceType = Maintenance::routineServiceTypeFor($text);
        if (! $serviceType || ! $vehicle) {
            return null;
        }

        $status = $this->gate->routineStatus($vehicle, $serviceType);
        if (($status['status'] ?? null) !== 'ok') {
            return null; // due/overdue (correctly flagged) or no_data (unknown) — no conflict to report
        }

        return ['status' => 'ok', 'summary' => $status['summary'] ?? null];
    }

    /** Map a findings keyword to its catalog category (engine / brakes / …); null if unknown. Cached. */
    private function categoryForSymptom(?string $symptomLabel): ?string
    {
        if (! isset($this->symptomCategoryMap)) {
            $this->symptomCategoryMap = [];
            foreach (config('maintenance_findings.categories', []) as $cat) {
                foreach (($cat['keywords'] ?? []) as $kw) {
                    $this->symptomCategoryMap[FaultCause::normalizeKey($kw)] = $cat['key'] ?? null;
                }
            }
        }

        return $this->symptomCategoryMap[FaultCause::normalizeKey($symptomLabel)] ?? null;
    }

    /** @var array<string,?string>|null lazy keyword→category lookup for categoryForSymptom() */
    private ?array $symptomCategoryMap = null;

    // ── STAGE 2 — Inspector files the report + the repair decision ───────────────

    /**
     * Stage 2, the decision point. Abu Maroof files the test-drive report and decides:
     *   - $requiresMaintenance = true  → the diagnostic becomes a TICKET (`inspection_pending`).
     *     THIS is the moment a ticket is created; Logistics is notified to dispatch.
     *   - $requiresMaintenance = false → the diagnostic is CLEARED (`diagnostic_cleared`, terminal).
     *     The car needed no work, so no ticket ever exists — nothing to clutter the board.
     * The report is kept on the row in both cases (an audit trail of the inspection).
     *
     * @param array{symptoms?:array, severity?:?string, fault_severity?:?string, recommended_action?:?string, notes?:?string, maintenance_type?:?string} $report
     */
    public function submitReport(Maintenance $ticket, array $report, bool $requiresMaintenance, User $actor): Maintenance
    {
        // Repair Location (only meaningful when a ticket is actually opened). 'on_site' routes the ticket
        // into the mobile lane (car stays available); anything else defaults to the in-shop pipeline.
        $repairLocation = ($requiresMaintenance && ($report['repair_location'] ?? null) === Maintenance::REPAIR_ON_SITE)
            ? Maintenance::REPAIR_ON_SITE
            : ($requiresMaintenance ? Maintenance::REPAIR_IN_SHOP : null);

        $target = $requiresMaintenance
            ? ($repairLocation === Maintenance::REPAIR_ON_SITE ? Maintenance::WF_ON_SITE_PENDING : Maintenance::WF_INSPECTION_PENDING)
            : Maintenance::WF_DIAGNOSTIC_CLEARED;
        $this->assertTransition($ticket, $target);

        // An emergency Breakdown is never a mobile job — it grounds the car and must go to a workshop.
        // Reject the contradiction rather than silently downgrading the safety consequence.
        if ($repairLocation === Maintenance::REPAIR_ON_SITE
            && ($report['maintenance_type'] ?? null) === Maintenance::TYPE_BREAKDOWN) {
            throw new WorkflowTransitionException('A breakdown must be repaired in-shop — it cannot be handled on-site.', [
                'field' => 'repair_location',
            ]);
        }

        // The inspector's MANDATORY fault-severity grade (🔴 critical / 🟡 moderate / 🟢 routine) —
        // a "Requires maintenance" decision cannot be filed without it. It becomes the headline urgency
        // on the board + the supervisor's dispatch alert, and feeds the per-finding severity.
        $faultSeverity = isset($report['fault_severity']) && in_array($report['fault_severity'], Maintenance::FAULT_SEVERITIES, true)
            ? $report['fault_severity']
            : null;
        if ($requiresMaintenance && ! $faultSeverity) {
            throw new WorkflowTransitionException('Tag the fault severity (critical, moderate or routine) before opening a maintenance ticket.', [
                'field' => 'fault_severity',
            ]);
        }

        $payload = [
            'symptoms'           => array_values(array_filter(array_map(
                fn ($s) => trim((string) $s),
                (array) ($report['symptoms'] ?? [])
            ))),
            'severity'           => $this->clean($report['severity'] ?? null) ?: $faultSeverity,
            'recommended_action' => $this->clean($report['recommended_action'] ?? null),
            'notes'              => $this->clean($report['notes'] ?? null),
            // The inspector's official maintenance classification — set here (the diagnostic decision
            // point) rather than on the Driver's request, because the Driver has no diagnostic authority.
            // When a ticket is opened without a chosen type it DEFAULTS to Routine Maintenance (a
            // manager can still correct it via updateMaintenanceType()). A cleared diagnostic needs
            // none — the car required no work — so it stays null.
            'maintenance_type'   => (isset($report['maintenance_type']) && array_key_exists($report['maintenance_type'], Maintenance::MAINTENANCE_TYPES))
                ? $report['maintenance_type']
                : ($requiresMaintenance ? Maintenance::TYPE_ROUTINE : null),
        ];

        // A ticket must carry SOME finding (it explains the repair); a "no maintenance" clearance
        // may legitimately be empty (the car was fine).
        if ($requiresMaintenance && $payload['symptoms'] === [] && ! $payload['recommended_action'] && ! $payload['notes']) {
            throw new WorkflowTransitionException('Add at least a symptom, an action, or a note before opening a maintenance ticket.', [
                'field' => 'test_drive_report',
            ]);
        }

        // The inspector's chosen root cause per symptom (Symptom → Root-Cause diagnostic), keyed by
        // normalised symptom so we can zip it onto the findings below. Each entry: [label, id].
        $causeChoices = [];
        foreach ((array) ($report['causes'] ?? []) as $c) {
            if (! is_array($c) || ! isset($c['symptom'])) {
                continue;
            }
            $causeChoices[FaultCause::normalizeKey($c['symptom'])] = [
                'label' => $c['root_cause'] ?? null,
                'id'    => $c['root_cause_id'] ?? null,
            ];
        }

        // End-of-test-drive odometer (optional) — the reading captured at the Decide step, forward from the
        // start-of-drive test_odometer anchor. Its own column + 'report' flag key; the >10 km note rides along.
        $reportOdo  = isset($report['report_odometer']) && is_numeric($report['report_odometer']) ? (int) $report['report_odometer'] : null;
        $reportNote = $report['odometer_note'] ?? null;

        // "Being Inspected" strict-match gate at the Decide step: the end-of-test-drive reading is measured
        // against the start-of-drive anchor — a short test loop of up to 5 km is allowed with a note, more
        // (or a backward reading) is blocked as a mis-read or an unauthorised long drive.
        if ($reportOdo !== null && $reportOdo > 0) {
            $this->assertStrictMatch($ticket, $actor, 'report', $reportOdo, $ticket->test_odometer !== null ? (int) $ticket->test_odometer : null, OdometerContinuityService::STAGE_TEST, $reportNote, 'report_odometer');
        }

        return DB::transaction(function () use ($ticket, $payload, $target, $requiresMaintenance, $actor, $faultSeverity, $causeChoices, $repairLocation, $reportOdo, $reportNote) {
            $ticket->test_drive_report = $payload;

            // The inspector's symptoms become first-class FINDINGS, source-stamped so they persist and
            // stay attributable ("Inspector-Identified") through every later stage of the workflow.
            // Each carries its resolved root cause (label + canonical fault_causes id) for analytics
            // + the eventual Odoo sync; a custom cause is recorded for admin review inside resolve().
            $vehicleForCheck = $ticket->loadMissing('vehicle')->vehicle;
            $ticket->findings = collect($payload['symptoms'])
                ->map(function ($text) use ($actor, $payload, $causeChoices, $vehicleForCheck) {
                    $choice = $causeChoices[FaultCause::normalizeKey($text)] ?? null;
                    [$cause, $causeId] = $choice
                        ? $this->resolveFaultCause($text, $choice['label'], $choice['id'], $actor)
                        : [null, null];

                    return [
                        'text'          => $text,
                        'source'        => Maintenance::FINDING_INSPECTOR,
                        'severity'      => $payload['severity'],
                        'root_cause'    => $cause,
                        'root_cause_id' => $causeId,
                        'by'            => $actor->name,
                        'at'            => Carbon::now()->toIso8601String(),
                        // Reality-check against the live diagnostic status — non-null only when this text
                        // is a monitored routine (oil/battery/tyres) AND the car's actual status says it
                        // is NOT due. Surfaces as an inline warning now, and a Data Health audit row later.
                        'status_check'  => $this->statusConflictFor($text, $vehicleForCheck),
                    ];
                })->values()->all();

            // Carry the report's severity onto the row so the board can read it.
            if ($payload['severity']) {
                $ticket->severity = $payload['severity'];
            }
            // The inspector's mandatory fault-severity grade for this ticket.
            if ($faultSeverity) {
                $ticket->fault_severity = $faultSeverity;
            }
            // Lock in the inspector's classification. Only set when currently null — once the
            // inspector has made the call it can only be corrected via updateMaintenanceType().
            if ($payload['maintenance_type'] !== null && $ticket->maintenance_type === null) {
                $ticket->maintenance_type = $payload['maintenance_type'];
            }
            // Stamp the Repair-Location lane the ticket is entering (null for a cleared diagnostic). The
            // car is NEVER marked out for an on-site job — event_status stays 'IN' so the operational
            // cascade keeps it available (WF_ON_SITE_PENDING is outside WF_TICKET_STATES anyway).
            $ticket->repair_location = $repairLocation;
            // End-of-test-drive odometer — recorded before save so the continuity flag rides along; heals the
            // car's canonical mileage forward (the drive moved the meter). Continuity vs the start anchor.
            if ($reportOdo !== null && $reportOdo > 0) {
                $ticket->report_odometer = $reportOdo;
                $this->recordOdometerFlag($ticket, 'report', $reportOdo, $ticket->test_odometer !== null ? (int) $ticket->test_odometer : null, OdometerContinuityService::STAGE_TEST, $reportNote, $actor);
            }
            $ticket->workflow_status = $target;
            $ticket->save();

            $vehicle = $ticket->loadMissing('vehicle')->vehicle;

            // Forward-only heal of the canonical odometer with the end-of-drive reading (mirrors the other
            // capture points). Runs after the ticket save, alongside the existing vehicle-side updates below.
            if ($reportOdo !== null && $reportOdo > 0 && $vehicle) {
                $this->applyTestOdometer($vehicle, $reportOdo);
            }

            // A Breakdown discovered at the decision point inherits the exact same emergency consequences
            // as one reported directly: ground the car (red) + force 🔴 critical severity. Centralised so
            // the rule holds no matter which entry point set the type; cascade re-derives operational_status.
            if ($requiresMaintenance && $ticket->maintenance_type === Maintenance::TYPE_BREAKDOWN && $vehicle) {
                $this->applyBreakdownConsequences($ticket, $vehicle, $actor, $payload['recommended_action'] ?? null);
                $this->cascade($ticket->vehicle_id);
            }

            // The Driver who requested this inspection is told the RESULT either way — the design's
            // "notify the Driver of the result" step, routed straight to whoever raised it.
            $this->notifyResultToRequester($ticket, $vehicle, $requiresMaintenance, $actor);

            // A cleared diagnostic stops here — no ticket, so Logistics has nothing to dispatch.
            if (! $requiresMaintenance) {
                $this->log->record($ticket, VehicleLogEvent::EVENT_DIAGNOSTIC_CLEARED, $actor, [
                    'description' => 'Test drive cleared — no maintenance required (by ' . $actor->name . ')',
                    'meta'        => ['severity' => $payload['severity'], 'maintenance_type' => $payload['maintenance_type']],
                ]);

                return $ticket->load($this->eager());
            }

            $typeLabel = $payload['maintenance_type'] ? (Maintenance::MAINTENANCE_TYPES[$payload['maintenance_type']] ?? $payload['maintenance_type']) : null;
            $onSite    = $repairLocation === Maintenance::REPAIR_ON_SITE;
            $this->log->record($ticket, VehicleLogEvent::EVENT_REPORT_FILED, $actor, [
                'description' => ($onSite ? 'On-site maintenance ticket opened from test-drive report' : 'Maintenance ticket opened from test-drive report')
                    . ($typeLabel ? ' · ' . $typeLabel : '')
                    . ' (by ' . $actor->name . ')',
                'meta'        => ['severity' => $payload['severity'], 'symptoms' => $payload['symptoms'], 'maintenance_type' => $payload['maintenance_type'], 'repair_location' => $repairLocation],
            ]);

            $sevMeta = $faultSeverity ? (Maintenance::FAULT_SEVERITY_META[$faultSeverity] ?? null) : null;
            $sevTail = $sevMeta ? ' · ' . $sevMeta['emoji'] . ' ' . $sevMeta['label'] : '';

            if ($onSite) {
                // On-Site (mobile) lane: there is NO garage dispatch. The car stays where it's parked and
                // available; the maintenance team (controllers/managers) get a task to perform the service
                // on the spot, then close it with "Mark as Serviced". No driver, no supervisor hand-off.
                $this->notifier->notifyByPermission(self::NOTIFY_CONTROLLERS, [
                    'type'     => 'maint_on_site_task',
                    'category' => 'maintenance',
                    'severity' => $sevMeta['severity'] ?? 'info',
                    'title'    => trim('🧰 On-site service needed' . $sevTail . ' · ' . $this->label($vehicle)),
                    'body'     => trim($this->label($vehicle) . ' needs a minor on-site job (inspected by ' . $actor->name
                                    . ')' . $sevTail . ' — service it where it\'s parked, then mark it serviced. The car stays available.'),
                    'url'      => $this->link($ticket),
                    'key'      => 'maint_wf:' . $ticket->id . ':on_site_pending',
                    'icon'     => 'wrench',
                    'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no, 'fault_severity' => $faultSeverity, 'repair_location' => Maintenance::REPAIR_ON_SITE],
                ], $actor->id);

                return $ticket->load($this->eager());
            }

            // In-Shop → hand the DISPATCH decision to the Supervisors (Waleed/Abdullah): they review the
            // report and pick the garage. The driver pool is only alerted later, once a car is actually
            // ready to collect (assignDispatch()). The fault severity rides into the alert (emoji + colour)
            // so the supervisor gauges the urgency the moment it lands.
            $this->notifier->notifyByPermission(self::NOTIFY_DISPATCHER, [
                'type'     => 'maint_dispatch_ready',
                'category' => 'maintenance',
                'severity' => $sevMeta['severity'] ?? 'warning',
                'title'    => trim(($sevMeta['emoji'] ?? '') . ' New maintenance ticket' . $sevTail . ' · ' . $this->label($vehicle)),
                'body'     => trim($this->label($vehicle) . ' needs maintenance (inspected by ' . $actor->name
                                . ')' . $sevTail . ' — review it and pick a garage.'),
                'url'      => $this->link($ticket),
                'key'      => 'maint_wf:' . $ticket->id . ':inspection_pending',
                'icon'     => 'wrench',
                'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no, 'fault_severity' => $faultSeverity],
            ], $actor->id);

            return $ticket->load($this->eager());
        });
    }

    // ── Supervisor Delegation — delegate a driver ───────────────────────────────

    /**
     * DELEGATION — a Supervisor assigns a specific Logistics driver to pick up / drop off the car.
     * The driver reuses the "Where is the car?" assignment (assigned_driver_id) so pings + status
     * follow them; the overlay marks the task + flips the ticket to "Driver Assigned" and stamps who
     * delegated. The assigned driver is notified directly, carrying the fault-severity colour/symbol, and is
     * added as a watcher so they keep getting the ticket's updates.
     *
     * @param array{driver_id:int, delegation_task:string} $data
     */
    public function delegate(Maintenance $ticket, array $data, User $actor): Maintenance
    {
        if (in_array($ticket->workflow_status, Maintenance::WF_TERMINAL, true)) {
            throw new WorkflowTransitionException('Cannot delegate a closed or cleared ticket.', [
                'workflow_status' => $ticket->workflow_status,
            ]);
        }

        $task = $data['delegation_task'] ?? null;
        if (! in_array($task, Maintenance::DELEGATION_TASKS, true)) {
            throw new WorkflowTransitionException('Choose a task: pickup or dropoff.', ['field' => 'delegation_task']);
        }

        $driverId = (int) ($data['driver_id'] ?? 0);
        $driver   = $driverId ? User::find($driverId) : null;
        if (! $driver) {
            throw new WorkflowTransitionException('Select a driver to delegate to.', ['field' => 'driver_id']);
        }
        if (! $driver->can('maintenance.logistics')) {
            throw new WorkflowTransitionException('That user is not a logistics driver — pick someone who can pick up / drop off cars.', [
                'field' => 'driver_id',
            ]);
        }

        return DB::transaction(function () use ($ticket, $driver, $task, $actor) {
            // The delegated driver becomes the CURRENT assigned driver (pings/status follow them).
            $ticket->assigned_driver_id = $driver->id;
            $ticket->delegation_task    = $task;
            $ticket->delegation_status  = Maintenance::DELEGATION_ASSIGNED;
            $ticket->delegated_by       = $actor->id;
            $ticket->delegated_at       = Carbon::now();
            $ticket->save();

            // The assigned driver watches the ticket too, so the loop stays closed.
            $ticket->watchers()->syncWithoutDetaching([
                $driver->id => ['added_by' => $actor->id, 'reason' => 'delegated'],
            ]);

            $taskLabel = $task === Maintenance::DELEGATION_PICKUP ? 'pick up' : 'drop off';
            $this->log->record($ticket, VehicleLogEvent::EVENT_DELEGATED, $actor, [
                'description' => $actor->name . ' assigned ' . $driver->name . ' to ' . $taskLabel . ' the car',
                'meta'        => ['driver_id' => $driver->id, 'driver' => $driver->name, 'task' => $task, 'by' => $actor->name],
            ]);

            // Alert the specific driver — carry the fault-severity colour/symbol for at-a-glance urgency.
            $vehicle  = $ticket->loadMissing('vehicle')->vehicle;
            $meta     = $ticket->fault_severity ? (Maintenance::FAULT_SEVERITY_META[$ticket->fault_severity] ?? null) : null;
            $emoji    = $meta['emoji'] ?? '';
            $severity = $meta['severity'] ?? 'warning';
            $sevTail  = $meta ? ' · ' . $emoji . ' ' . $meta['label'] : '';
            $this->notifier->notifyUser($driver, [
                'type'     => 'maint_delegated',
                'category' => 'maintenance',
                'severity' => $severity,
                'title'    => trim($emoji . ' ' . ucfirst($taskLabel) . ' assigned · ' . $this->label($vehicle)),
                'body'     => trim($actor->name . ' assigned you to ' . $taskLabel . ' ' . $this->label($vehicle) . $sevTail . '.'),
                'url'      => $this->link($ticket),
                'key'      => 'maint_wf:' . $ticket->id . ':delegated:' . $driver->id . ':' . $task . ':' . Carbon::now()->timestamp,
                'icon'     => 'truck',
                'meta'     => [
                    'ticket_id'      => $ticket->id,
                    'plate'          => $vehicle?->plate_no,
                    'task'           => $task,
                    'assigned_by'    => $actor->name,
                    'fault_severity' => $ticket->fault_severity,
                    'severity_emoji' => $emoji,
                ],
            ]);

            return $ticket->load($this->eager());
        });
    }

    // ── PHASE 2 — Supervisor (dispatcher) picks the garage + assigns a driver ────

    /**
     * Phase 2 — the DISPATCHER's call. A Supervisor (Waleed/Abdullah) reviews an open ticket
     * (inspection_pending), chooses the destination garage from the vendor list and assigns a driver
     * to collect the car. This is the clear separation of authority the operation needs: the garage
     * decision belongs to the Supervisor, not the driver who merely executes the pickup.
     *
     * The car does NOT physically move yet — it stays parked ('IN', no out_date), so a still-rented
     * car keeps its "rented" status and is not yet counted in the garage. Only the driver's pickup
     * (dispatch()) takes it out. The assigned driver is notified directly to collect the car; if no
     * specific driver is named the whole Driver pool is alerted that a car is ready for pickup.
     *
     * @param array{vendor_id:int, driver_id?:?int, expected_return_date?:?string} $data
     */
    public function assignDispatch(Maintenance $ticket, array $data, User $actor): Maintenance
    {
        $this->assertTransition($ticket, Maintenance::WF_AWAITING_DISPATCH);

        // Snapshot BEFORE we overwrite the garage: was this a re-dispatch of a car that just failed
        // re-inspection, and if so which garage did it come back broken from? Keeping the same garage is
        // the default/expected path (no alert); moving it to a DIFFERENT garage is a judgement call an
        // admin should see. Captured here because the transaction below reassigns vendor_id/garage.
        $fromReinspectionFailed = $ticket->workflow_status === Maintenance::WF_REINSPECTION_FAILED;
        $previousVendorId       = (int) $ticket->vendor_id;
        $previousGarage         = $ticket->garage;

        $vendorId = (int) ($data['vendor_id'] ?? 0);
        $vendor   = $vendorId ? Vendor::find($vendorId) : null;
        if (! $vendor) {
            throw new WorkflowTransitionException('Select the destination garage from the list before assigning the dispatch.', [
                'field' => 'vendor_id',
            ]);
        }

        // "Came back broken → sent to a DIFFERENT garage" — the one case admins want flagged.
        $garageChanged = $fromReinspectionFailed && $previousVendorId && $previousVendorId !== $vendor->id;

        // The driver is optional — the Supervisor may assign a specific driver, or leave the pickup
        // open to the Driver pool. Sometimes a Supervisor takes the car themselves (they also hold
        // maintenance.logistics), in which case they assign it to themselves.
        $driverId = (int) ($data['driver_id'] ?? 0);
        $driver   = $driverId ? User::find($driverId) : null;
        if ($driverId && ! $driver) {
            throw new WorkflowTransitionException('That driver no longer exists — pick another.', ['field' => 'driver_id']);
        }
        if ($driver && ! $driver->can('maintenance.logistics')) {
            throw new WorkflowTransitionException('That user is not a driver — pick someone who can pick up cars.', [
                'field' => 'driver_id',
            ]);
        }

        return DB::transaction(function () use ($ticket, $vendor, $driver, $data, $actor, $garageChanged, $previousGarage, $fromReinspectionFailed) {
            $ticket->vendor_id = $vendor->id;
            $ticket->garage    = $vendor->name;   // denormalised label the board shows
            if (! empty($data['expected_return_date'])) {
                $ticket->expected_return_date = $data['expected_return_date'];
            }

            // The car stays parked ('IN') until the driver physically collects it (dispatch()).
            // dispatched_at is deliberately NOT stamped here — the "At Garage" clock must start at the
            // real pickup, not at this decision — so stage timing stays honest.
            $ticket->event_status = 'IN';

            if ($driver) {
                $ticket->assigned_driver_id = $driver->id;
                $ticket->delegation_task    = Maintenance::DELEGATION_PICKUP;
                $ticket->delegation_status  = Maintenance::DELEGATION_ASSIGNED;
                $ticket->delegated_by       = $actor->id;
                $ticket->delegated_at       = Carbon::now();
            }

            // Optional free-text NOTE the Supervisor writes — ONLY meaningful when they are CHANGING the
            // garage (moving a came-back-broken car to a different shop). Ignored otherwise, so a note can
            // never ride along on a same-garage or first-time assign. Shows in the car's maintenance
            // history log + the ticket's follow-up log.
            $note = $garageChanged ? $this->clean($data['note'] ?? null) : null;

            // "Sent back" note — when this is a re-dispatch of a car that FAILED re-inspection, leave a
            // visible note on the ticket (shown in the follow-up log + the audit trail) so anyone can see
            // the car was returned to a garage, and whether to the SAME shop or a DIFFERENT one.
            $sentBackNote = null;
            if ($fromReinspectionFailed) {
                $sentBackNote = $garageChanged
                    ? 'Sent back after failed re-inspection — moved to a different garage: ' . ($previousGarage ?: 'previous garage') . ' → ' . $vendor->name
                    : 'Sent back after failed re-inspection — returned to the same garage: ' . $vendor->name;
                if ($note) {
                    $sentBackNote .= ' — Note: ' . $note;
                }
                $ticket->follow_ups = collect($ticket->follow_ups ?? [])->push([
                    'text'        => $sentBackNote,
                    'kind'        => 'sent_back',            // tagged so surfaces can flag it without text-matching
                    'note'        => $note,
                    'from_garage' => $previousGarage,
                    'to_garage'   => $vendor->name,
                    'changed'     => $garageChanged,
                    'by'          => $actor->name,
                    'by_id'       => $actor->id,
                    'at'          => Carbon::now()->toIso8601String(),
                ])->values()->all();
            }

            $ticket->workflow_status = Maintenance::WF_AWAITING_DISPATCH;
            $ticket->save();

            // The assigned driver watches the ticket so the loop stays closed.
            if ($driver) {
                $ticket->watchers()->syncWithoutDetaching([
                    $driver->id => ['added_by' => $actor->id, 'reason' => 'delegated'],
                ]);
            }

            $this->cascade($ticket->vehicle_id);

            $this->log->record($ticket, VehicleLogEvent::EVENT_GARAGE_ASSIGNED, $actor, [
                'description' => ($sentBackNote ?: 'Dispatch assigned — garage ' . $vendor->name
                        . ($note ? ' · Note: ' . $note : ''))
                    . ($driver ? ', driver ' . $driver->name : ', pickup open to the pool')
                    . ' (by ' . $actor->name . ')',
                'meta' => ['garage' => $vendor->name, 'vendor_id' => $vendor->id, 'driver_id' => $driver?->id, 'driver' => $driver?->name, 'by' => $actor->name, 'note' => $note, 'sent_back' => $fromReinspectionFailed, 'from_garage' => $fromReinspectionFailed ? $previousGarage : null],
            ]);

            $vehicle  = $ticket->loadMissing('vehicle')->vehicle;
            $meta     = $ticket->fault_severity ? (Maintenance::FAULT_SEVERITY_META[$ticket->fault_severity] ?? null) : null;
            $emoji    = $meta['emoji'] ?? '';
            $severity = $meta['severity'] ?? 'warning';
            $sevTail  = $meta ? ' · ' . $emoji . ' ' . $meta['label'] : '';

            if ($driver) {
                // A specific driver was named — alert them directly to go collect the car.
                $this->notifier->notifyUser($driver, [
                    'type'     => 'maint_pickup_assigned',
                    'category' => 'maintenance',
                    'severity' => $severity,
                    'title'    => trim($emoji . ' Pickup assigned · ' . $this->label($vehicle)),
                    'body'     => trim($actor->name . ' assigned you to take ' . $this->label($vehicle)
                                    . ' to ' . $vendor->name . $sevTail . ' — capture the odometer and dispatch it.'),
                    'url'      => $this->link($ticket),
                    'key'      => 'maint_wf:' . $ticket->id . ':awaiting_dispatch:' . $driver->id,
                    'icon'     => 'truck',
                    'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no, 'garage' => $vendor->name, 'assigned_by' => $actor->name],
                ]);
            } else {
                // No specific driver — fan the pickup hand-off to the whole Driver pool.
                $this->notifier->notifyByPermission(self::NOTIFY_LOGISTICS, [
                    'type'     => 'maint_pickup_ready',
                    'category' => 'maintenance',
                    'severity' => 'warning',
                    'title'    => 'Ready for pickup · ' . $this->label($vehicle),
                    'body'     => trim($this->label($vehicle) . ' is assigned to ' . $vendor->name
                                    . ' — capture the odometer and dispatch it to the garage.'),
                    'url'      => $this->link($ticket),
                    'key'      => 'maint_wf:' . $ticket->id . ':awaiting_dispatch',
                    'icon'     => 'truck',
                    'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no, 'garage' => $vendor->name],
                ], $actor->id);
            }

            // Admin audit alert: a failed-re-inspection car was re-dispatched to a DIFFERENT garage than
            // the one it came back broken from. Keeping the same garage (the default) stays silent. Sent to
            // ALL ADMINS (super-admin + admin roles) as oversight; the actor is NOT excluded — every admin
            // (including one who performed the change themselves) gets the record.
            if ($garageChanged) {
                $this->notifier->notifyByRole(['super-admin', 'admin'], [
                    'type'     => 'maint_redispatch_garage_changed',
                    'category' => 'maintenance',
                    'severity' => 'warning',
                    'title'    => trim('🔀 Garage changed on re-dispatch · ' . $this->label($vehicle)),
                    'body'     => trim($actor->name . ' sent ' . $this->label($vehicle)
                                    . ' to a different garage after it failed re-inspection: '
                                    . ($previousGarage ?: 'previous garage') . ' → ' . $vendor->name . '.'
                                    . ($note ? ' Note: ' . $note : '')),
                    'url'      => $this->link($ticket),
                    'key'      => 'maint_wf:' . $ticket->id . ':garage_changed:' . $vendor->id,
                    'icon'     => 'wrench',
                    'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no, 'from_garage' => $previousGarage, 'to_garage' => $vendor->name, 'by' => $actor->name],
                ]);
            }

            return $ticket->load($this->eager());
        });
    }

    // ── UC-3 — Driver picks the car up and takes it to the garage ───────────────

    /**
     * The assigned Driver captures the odometer (photographed into inspection_records separately) and
     * picks the car up. The garage was already chosen by the Supervisor at dispatch-assignment, so the
     * driver just confirms it (or it falls back to the assigned vendor). The car now physically leaves
     * service: event_status flips to 'OUT' with today's out_date, so the operational_status cascade
     * marks it "In Maintenance". Controllers are alerted that the car is in transit.
     *
     * @param array{dispatch_odometer:int, vendor_id?:?int, expected_return_date?:?string} $data
     */
    public function dispatch(Maintenance $ticket, array $data, User $actor): Maintenance
    {
        $this->assertTransition($ticket, Maintenance::WF_IN_TRANSIT);

        $odometer = (int) ($data['dispatch_odometer'] ?? 0);
        if ($odometer <= 0) {
            throw new WorkflowTransitionException('Capture the odometer reading before dispatching the car.', [
                'field' => 'dispatch_odometer',
            ]);
        }

        // The garage is strictly the Supervisor's call (set at dispatch-assignment). The driver cannot
        // pick or change it — we use ONLY the garage already on the ticket. A ticket can't reach pickup
        // without one, but we guard anyway.
        $vendorId = (int) $ticket->vendor_id;
        $vendor   = $vendorId ? Vendor::find($vendorId) : null;
        if (! $vendor) {
            throw new WorkflowTransitionException('No destination garage is set — the supervisor must assign one before pickup.', [
                'field' => 'vendor_id',
            ]);
        }

        // "Awaiting Pickup" strict-match gate: the car is still in our park until the driver takes it, so the
        // pickup reading must match the last recorded mileage (the test-drive anchor, else the live odometer)
        // — a +1..5 km drift needs a note; a bigger or backward gap is blocked as a typo / unauthorised move.
        $lastRecorded = $ticket->test_odometer
            ?? ($ticket->loadMissing('vehicle')->vehicle?->odometer !== null ? (int) $ticket->vehicle->odometer : null);
        $this->assertStrictMatch($ticket, $actor, 'dispatch', $odometer, $lastRecorded, OdometerContinuityService::STAGE_PARK_PICKUP, $data['odometer_note'] ?? null, 'dispatch_odometer');

        return DB::transaction(function () use ($ticket, $odometer, $vendor, $data, $actor, $lastRecorded) {
            $ticket->dispatch_odometer = $odometer;
            // Pickup continuity (strict-match park stage): recorded against the same last-recorded anchor the
            // gate above checked. An in-range drift stamps 'authorized_deviation' (+ its note) for the
            // /oversight/mileage audit board; an exact match is 'verified'.
            $this->recordOdometerFlag($ticket, 'dispatch', $odometer, $lastRecorded, OdometerContinuityService::STAGE_PARK_PICKUP, $data['odometer_note'] ?? null, $actor);
            $ticket->vendor_id         = $vendor->id;
            $ticket->garage            = $vendor->name;   // denormalised label the board shows
            $ticket->driver            = $actor->name;    // the Driver who took the car to the garage
            $ticket->dispatched_by     = $actor->id;
            $ticket->dispatched_at     = Carbon::now();


            // Car physically out now → legacy stage 'OUT' + out_date drives "In Maintenance".
            // The dispatcher may pass the date the car actually left; if they don't, it's today.
            $ticket->event_status   = 'OUT';
            $ticket->out_date       = ! empty($data['out_date'])
                ? Carbon::parse($data['out_date'])->startOfDay()
                : Carbon::today();
            $ticket->actual_in_date = null;            // can't be "back" while it's leaving
            if (! empty($data['expected_return_date'])) {
                $ticket->expected_return_date = $data['expected_return_date'];
            }

            // Contract-link materialises HERE — only once the repair is actually being dispatched.
            // Attach the ticket to the vehicle's open maintenance (type-'U') contract if one exists.
            // Best-effort: stays null when no maintenance contract is open (OM owns contracts; we link,
            // never create one). Stored in linked_contract_id, NOT contract_id (that's the unique 1:1
            // header column — reusing it would collide with the linked contract's own header row).
            $ticket->linked_contract_id = $this->resolveMaintenanceContractId($ticket);

            // Pickup done → the car heads to the garage. It lands in the "Now at Garage" arrival
            // checkpoint (in_transit); the repair clock (repair_started_*) starts only once arrival is
            // confirmed with the MANDATORY arrival odometer (markUnderRepair) — NOT here.
            $ticket->workflow_status   = Maintenance::WF_IN_TRANSIT;
            $ticket->save();

            // Car is physically out → recompute availability (→ "In Maintenance").
            $this->cascade($ticket->vehicle_id);

            $this->log->record($ticket, VehicleLogEvent::EVENT_DISPATCHED, $actor, [
                'description' => 'تم استلام السيارة والتوجّه إلى ' . $vendor->name . ' · قراءة العداد ' . number_format($odometer) . ' كم (بواسطة ' . $actor->name . ')',
                'meta'        => ['garage' => $vendor->name, 'vendor_id' => $vendor->id, 'dispatch_odometer' => $odometer],
            ]);

            // Pickup, NOT arrival: the driver has the car and is on the way. Controllers get a heads-up;
            // the "arrived at the garage — follow up" alert fires later at the arrival checkpoint
            // (markUnderRepair), once the car is actually there.
            $vehicle = $ticket->loadMissing('vehicle')->vehicle;
            $this->notifier->notifyByPermission(self::NOTIFY_CONTROLLERS, [
                'type'     => 'maint_in_transit',
                'category' => 'maintenance',
                'severity' => 'info',
                'title'    => '🚗 تم الاستلام · ' . $this->label($vehicle),
                'body'     => trim($actor->name . ' استلم ' . $this->label($vehicle) . ' ومتوجّه إلى ' . $vendor->name
                                . ' · العداد ' . number_format($odometer) . ' كم.'),
                'url'      => $this->link($ticket),
                'key'      => 'maint_wf:' . $ticket->id . ':in_transit',
                'icon'     => 'truck',
                'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no, 'garage' => $vendor->name, 'driver' => $actor->name],
            ], $actor->id);

            return $ticket->load($this->eager());
        });
    }

    // ── UC-3 (Recovery variant) — a Recovery Truck (winch) TOWS a broken-down car to the garage ──

    /**
     * Recovery dispatch — the towing counterpart of dispatch(), DECOUPLED from the classic
     * assign-then-pickup sequence. A Breakdown ticket is born already knowing it needs a tow (the car
     * won't start) — there's no meaningful "assign a garage, then wait for a driver" interval for it, since
     * the recovery truck IS the move: dispatching it is a single action. So this method:
     *   - is reachable straight from `inspection_pending` (the moment a breakdown ticket is born) OR from
     *     `awaiting_dispatch` (a car that later turns out undriveable after the classic assign step) — its
     *     own explicit guard below, deliberately NOT the shared TRANSITIONS table, so the ordinary driver
     *     dispatch() (which legitimately needs the supervisor's prior assign-dispatch bookkeeping — the
     *     driver assignment, the split-dispatch fault routing) is untouched;
     *   - accepts the destination GARAGE inline (`vendor_id`) when the ticket doesn't have one yet — so a
     *     supervisor can pick "who tows it + where to" in one form, never forced through a separate
     *     "Assign Garage" screen first. If the ticket already has a garage (the classic assign step ran),
     *     that garage is reused and `vendor_id` is optional.
     *
     * Same mandatory odometer/condition gate as a driver pickup (the photo is required at the API edge).
     * The only difference from a normal dispatch is that the moving entity is a RECOVERY UNIT, not a
     * driver, and every log/alert says so:
     *   - the towing unit (name + operator mobile) is captured in place of an assigned driver;
     *   - the car stays Disabled / In-Maintenance throughout (no status change on this leg);
     *   - the audit + notifications read "Vehicle being recovered by [Unit] to [Garage]".
     *
     * @param array{dispatch_odometer:int, recovery_unit_name:string, recovery_unit_phone?:?string, vendor_id?:?int, out_date?:?string, expected_return_date?:?string} $data
     */
    public function dispatchRecovery(Maintenance $ticket, array $data, User $actor): Maintenance
    {
        // Recovery's own guard: reachable from either the moment a ticket is born (inspection_pending —
        // the common breakdown case) or from awaiting_dispatch (a car assigned the classic way that turns
        // out to need towing after all). Not routed through assertTransition()/TRANSITIONS on purpose —
        // see the docblock above.
        if (! in_array($ticket->workflow_status, [Maintenance::WF_INSPECTION_PENDING, Maintenance::WF_AWAITING_DISPATCH], true)) {
            throw new WorkflowTransitionException(
                "A recovery can only be dispatched while the ticket is awaiting a garage decision or pickup, not from '{$ticket->workflow_status}'.",
                ['from' => $ticket->workflow_status]
            );
        }

        $odometer = (int) ($data['dispatch_odometer'] ?? 0);
        if ($odometer <= 0) {
            throw new WorkflowTransitionException('Capture the odometer reading before the recovery unit takes the car.', [
                'field' => 'dispatch_odometer',
            ]);
        }

        $unitName = $this->clean($data['recovery_unit_name'] ?? null);
        if ($unitName === null) {
            throw new WorkflowTransitionException('Enter the recovery unit name (e.g. "Recovery Truck #05" or the towing company).', [
                'field' => 'recovery_unit_name',
            ]);
        }
        $unitPhone = $this->clean($data['recovery_unit_phone'] ?? null);

        // The garage — reuse the ticket's existing one (the classic assign step already ran), or accept it
        // inline here (a fresh assignment, folded into this single recovery action). Either way a
        // destination is mandatory before the tow leaves.
        $vendorId = (int) ($data['vendor_id'] ?? $ticket->vendor_id ?? 0);
        $vendor   = $vendorId ? Vendor::find($vendorId) : null;
        if (! $vendor) {
            throw new WorkflowTransitionException('Select the garage the recovery truck should tow the car to.', [
                'field' => 'vendor_id',
            ]);
        }
        return DB::transaction(function () use ($ticket, $odometer, $vendor, $unitName, $unitPhone, $data, $actor) {
            $ticket->dispatch_odometer = $odometer;
            // Pickup continuity — identical to a driver dispatch: >= the last recorded mileage, else flagged.
            $lastRecorded = $ticket->test_odometer
                ?? ($ticket->loadMissing('vehicle')->vehicle?->odometer !== null ? (int) $ticket->vehicle->odometer : null);
            $this->recordOdometerFlag($ticket, 'dispatch', $odometer, $lastRecorded, OdometerContinuityService::STAGE_PICKUP, $data['odometer_note'] ?? null, $actor);

            $ticket->vendor_id           = $vendor->id;
            $ticket->garage              = $vendor->name;
            // The moving entity is a RECOVERY UNIT, not a driver. Store it on its own fields; keep the
            // denormalised `driver` label populated (so "who has the car" surfaces aren't blank) — the
            // is_recovery flag tells every client to label it a Recovery Unit, never a person.
            $ticket->recovery_unit_name  = $unitName;
            $ticket->recovery_unit_phone = $unitPhone;
            $ticket->driver              = $unitName;
            // No human driver has custody on a recovery leg — clear any stale delegation so the arrival
            // check-in isn't locked to a driver who never touched the car (a supervisor confirms arrival).
            $ticket->assigned_driver_id  = null;
            $ticket->dispatched_by       = $actor->id;
            $ticket->dispatched_at       = Carbon::now();

            // Car physically out now (being towed) → legacy 'OUT' + out_date drives "In Maintenance".
            $ticket->event_status   = 'OUT';
            $ticket->out_date       = ! empty($data['out_date'])
                ? Carbon::parse($data['out_date'])->startOfDay()
                : Carbon::today();
            $ticket->actual_in_date = null;
            if (! empty($data['expected_return_date'])) {
                $ticket->expected_return_date = $data['expected_return_date'];
            }

            $ticket->linked_contract_id = $this->resolveMaintenanceContractId($ticket);
            $ticket->workflow_status    = Maintenance::WF_IN_TRANSIT;
            $ticket->save();

            $this->cascade($ticket->vehicle_id);

            // Exact phrasing the team asked for: "Vehicle being recovered by [Unit Name] to [Garage Name]"
            // — the phone rides along parenthetically, never inside the unit-name slot itself.
            $unitLabel = $unitName . ($unitPhone ? ' (' . $unitPhone . ')' : '');
            $this->log->record($ticket, VehicleLogEvent::EVENT_DISPATCHED, $actor, [
                'description' => 'Vehicle being recovered by ' . $unitLabel . ' to ' . $vendor->name
                                . ' · odometer ' . number_format($odometer) . ' km (logged by ' . $actor->name . ')',
                'meta'        => ['garage' => $vendor->name, 'vendor_id' => $vendor->id, 'dispatch_odometer' => $odometer, 'recovery' => true, 'recovery_unit' => $unitName, 'recovery_phone' => $unitPhone],
            ]);

            // Controllers get the heads-up that the car is on a recovery truck, not with a driver.
            $vehicle = $ticket->loadMissing('vehicle')->vehicle;
            $this->notifier->notifyByPermission(self::NOTIFY_CONTROLLERS, [
                'type'     => 'maint_recovery_in_transit',
                'category' => 'maintenance',
                'severity' => 'warning',
                'title'    => '🛻 Recovery en route · ' . $this->label($vehicle),
                'body'     => trim('Vehicle being recovered by ' . $unitName . ' to ' . $vendor->name
                                . ' · odometer ' . number_format($odometer) . ' km.'),
                'url'      => $this->link($ticket),
                'key'      => 'maint_wf:' . $ticket->id . ':in_transit',
                'icon'     => 'truck',
                'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no, 'garage' => $vendor->name, 'recovery_unit' => $unitName],
            ], $actor->id);

            return $ticket->load($this->eager());
        });
    }

    // Location tracking (reassign / ping / one-click status reply) is now CANONICAL on the Logistics
    // Dispatch system (LogisticsDispatchService) — one channel for every vehicle movement, garage or
    // showroom. The duplicate per-maintenance-ticket methods that lived here were removed.

    // ── UC-4 — Logistics relays "the garage has the car" ────────────────────────

    /**
     * "Now at Garage" arrival checkpoint → the workshop. The driver who physically picked the car up
     * (dispatch()) is the one who confirms it ARRIVED — he has custody of the car, so he is the ONLY one
     * who can log the MANDATORY arrival odometer (its photo is enforced at the API edge). There is no
     * supervisor override: whoever picked the car up must be the one to check it in — no exceptions.
     * Only here does the repair clock start (repair_started_*) and the car move into under_repair — the
     * "In Workshop" stage where cost + faults are managed. Kept strictly separate from pickup + repair.
     */
    public function markUnderRepair(Maintenance $ticket, array $data, User $actor): Maintenance
    {
        $this->assertTransition($ticket, Maintenance::WF_UNDER_REPAIR);

        if ($ticket->dispatched_by && (int) $ticket->dispatched_by !== (int) $actor->id) {
            throw new WorkflowTransitionException(
                ($ticket->driver ?: 'The driver who picked up the car') . ' has custody of this car — only they can check it in at the garage.',
                ['field' => 'receive_odometer']
            );
        }

        $odometer = (int) ($data['receive_odometer'] ?? 0);
        if ($odometer < 1) {
            throw new WorkflowTransitionException('Log the arrival odometer to check the car in at the garage.', [
                'field' => 'receive_odometer',
            ]);
        }

        // The car was driven to the garage, so the arrival reading MUST be higher than the pickup
        // reading — an equal-or-lower value is an error or a mis-keyed entry, not a valid arrival.
        // (Skipped when there's no pickup reading to compare against, e.g. a ticket with no dispatch.)
        $pickup = $ticket->dispatch_odometer !== null ? (int) $ticket->dispatch_odometer : null;
        if ($pickup !== null && $odometer <= $pickup) {
            // Audit the rejected arrival reading before throwing (the check-in rolls back, leaving no trace).
            $this->logOdometerBlock($ticket, $actor, 'receive', $odometer, $pickup, $odometer - $pickup, 'must_increase', $data['odometer_note'] ?? null);
            throw new WorkflowTransitionException(
                'The arrival odometer (' . number_format($odometer) . ' km) must be higher than the pickup reading ('
                    . number_format($pickup) . ' km) — the car was driven to the garage. Re-check the reading.',
                ['field' => 'receive_odometer']
            );
        }

        return DB::transaction(function () use ($ticket, $data, $actor, $odometer) {
            // ── Deferred Garage Hand-over ─────────────────────────────────────────────────────────
            // If this arrival is the destination of a PLANNED transfer, the car has now physically reached
            // the new garage — perform the hand-over the transfer request deliberately deferred: move every
            // open fault's stint to the destination and re-point the ticket's single current garage. Only
            // now does vendor_id stop pointing at the garage the car left. (No-op on a first arrival, when
            // there's no pending transfer.) Runs FIRST so the arrival logging below reads the new garage.
            if ($ticket->transfer_to_vendor_id && (int) $ticket->transfer_to_vendor_id !== (int) $ticket->vendor_id) {
                $dest = Vendor::find($ticket->transfer_to_vendor_id);
                if ($dest) {
                    app(MaintenanceTaskService::class)->routeTicketToGarage($ticket, $dest->id, 'Arrived on transfer', $actor, $odometer);
                    $ticket->vendor_id = $dest->id;
                    $ticket->garage    = $dest->name; // denormalised label the board shows
                }
            }
            $ticket->transfer_to_vendor_id = null; // the move is complete — clear the pending destination

            $ticket->receive_odometer = $odometer;
            // Garage-intake continuity vs the pickup reading: the drive to the garage moves the meter
            // forward (fine), only a backward reading is a discrepancy.
            $this->recordOdometerFlag($ticket, 'receive', $odometer, $ticket->dispatch_odometer, OdometerContinuityService::STAGE_GARAGE_IN, $data['odometer_note'] ?? null, $actor);
            if (array_key_exists('garage_feedback', $data)) {
                $ticket->garage_feedback = $this->clean($data['garage_feedback']);
            }
            if (! empty($data['expected_return_date'])) {
                $ticket->expected_return_date = $data['expected_return_date'];
            }
            $ticket->event_status      = 'OUT';   // still physically out
            $ticket->repair_started_by = $actor->id;
            $ticket->repair_started_at = Carbon::now();
            $ticket->workflow_status   = Maintenance::WF_UNDER_REPAIR;
            $ticket->save();

            // Faults that rode in on a transfer arrive workable again: In Transit → Pending at the new
            // garage (their open stint already points here). A no-op on a first arrival (no transit faults).
            $ticket->tasks()
                ->where('status', \App\Models\MaintenanceTask::STATUS_TRANSFERRED)
                ->update(['status' => \App\Models\MaintenanceTask::STATUS_PENDING]);

            // Per-fault "now at this garage" confirmation lands HERE, at the In-Workshop check-in — NOT at
            // dispatch (garage pick), where it's suppressed. Only faults actually AT this garage are logged:
            // a Pending-Assignment fault (current_vendor_id null) is on the car but not routed here yet, so
            // it's skipped — it'll be logged when the delegate assigns it from the Dispatch Queue.
            foreach ($ticket->tasks()->whereNotIn('status', \App\Models\MaintenanceTask::TERMINAL)->get() as $task) {
                if ((int) $task->current_vendor_id !== (int) $ticket->vendor_id) {
                    continue;
                }
                $this->log->recordTask($task, VehicleLogEvent::EVENT_TASK_ASSIGNED, $actor, [
                    'description' => 'Fault "' . $task->symptom . '" under repair at ' . ($ticket->garage ?: 'the garage'),
                    'meta'        => ['vendor_id' => $ticket->vendor_id, 'garage' => $ticket->garage],
                ]);
            }

            // Confirm Handover: the custodian's arrival IS the delivery of the transport task — close the
            // ticket's active move so the car is no longer "In Transit" and the live position flips to
            // In Workshop. (First-arrival check-ins usually have no linked move → no-op.)
            $move = $ticket->activeMove()->first();
            if ($move) {
                $this->logistics->complete($move, $actor);
            }

            $this->cascade($ticket->vehicle_id);
            $this->log->record($ticket, VehicleLogEvent::EVENT_UNDER_REPAIR, $actor, [
                'description' => 'وصلت السيارة إلى ' . ($ticket->garage ?: 'الكراج') . ' وتم إدخالها · قراءة عداد الوصول '
                    . number_format($odometer) . ' كم، والإصلاح جارٍ (بواسطة ' . $actor->name . ')',
                'meta'        => ['garage' => $ticket->garage, 'receive_odometer' => $odometer, 'garage_feedback' => $ticket->garage_feedback],
            ]);
            $this->notifyControllers($ticket, 'maint_under_repair', 'info', 'Under repair', $actor);

            // The car is NOW physically at the garage — ping the SUPERVISORS (Waleed/Abdullah) to follow
            // up with the garage. The hand-off from the driver's job (get the car there) to management's
            // (chase the repair); it fires at arrival, not at pickup.
            $vehicle = $ticket->loadMissing('vehicle')->vehicle;
            $this->notifier->notifyByPermission(self::NOTIFY_DISPATCHER, [
                'type'     => 'maint_arrived_at_garage',
                'category' => 'maintenance',
                'severity' => 'info',
                'title'    => '🔧 Arrived at garage · ' . $this->label($vehicle),
                'body'     => trim($this->label($vehicle) . ' is now at ' . ($ticket->garage ?: 'the garage')
                                . ' · arrival odometer ' . number_format($odometer) . ' km — follow up with the garage.'),
                'url'      => $this->link($ticket),
                'key'      => 'maint_wf:' . $ticket->id . ':arrived_at_garage',
                'icon'     => 'wrench',
                'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no, 'garage' => $ticket->garage, 'driver' => $actor->name],
            ], $actor->id);

            return $ticket->load($this->eager());
        });
    }

    /**
     * Stage 3 (Follow-up & Repair): append GARAGE-identified findings to a ticket that's under
     * repair. Each is stamped source='garage' and APPENDED to the inspector's original findings —
     * never replacing them — so the audit trail shows exactly what was diagnosed before (Inspector)
     * vs discovered during the repair (Garage). Allowed only while under_repair; no state change;
     * Controllers are alerted for accountability.
     *
     * @param array<int,array{text:string, severity?:?string}|string> $findings
     */
    public function addGarageFindings(Maintenance $ticket, array $findings, User $actor): Maintenance
    {
        if ($ticket->workflow_status !== Maintenance::WF_UNDER_REPAIR) {
            throw new WorkflowTransitionException('Garage findings can only be added while the car is under repair.', [
                'workflow_status' => $ticket->workflow_status,
            ]);
        }

        $incoming = collect($findings)
            ->map(fn ($f) => [
                'text'          => $this->clean(is_array($f) ? ($f['text'] ?? null) : $f),
                'severity'      => $this->clean(is_array($f) ? ($f['severity'] ?? null) : null),
                'root_cause'    => $this->clean(is_array($f) ? ($f['root_cause'] ?? null) : null),
                'root_cause_id' => is_array($f) ? ($f['root_cause_id'] ?? null) : null,
            ])
            ->filter(fn ($f) => $f['text'] !== null)
            // Collapse duplicates within this same submission (case-insensitive on text).
            ->unique(fn ($f) => mb_strtolower($f['text']))
            ->values();

        if ($incoming->isEmpty()) {
            throw new WorkflowTransitionException('Add at least one finding.', ['field' => 'findings']);
        }

        // Keep the audit trail clean: the garage must NOT re-report anything already on the ticket —
        // above all the Inspector-Identified findings, which are immutable here. Drop any incoming text
        // that already exists in the findings JSON (any source), matched case-insensitively.
        $existing = collect($ticket->findings ?? [])
            ->pluck('text')
            ->filter()
            ->map(fn ($t) => mb_strtolower(trim((string) $t)))
            ->flip();

        $clean = $incoming
            ->reject(fn ($f) => $existing->has(mb_strtolower($f['text'])))
            ->values();

        if ($clean->isEmpty()) {
            throw new WorkflowTransitionException(
                'Those issues were already reported on this ticket — nothing new to add.',
                ['field' => 'findings'],
            );
        }

        return DB::transaction(function () use ($ticket, $clean, $actor) {
            $vehicle = $ticket->loadMissing('vehicle')->vehicle;
            $added = $clean->map(function ($f) use ($actor, $vehicle) {
                [$cause, $causeId] = $this->resolveFaultCause($f['text'], $f['root_cause'], $f['root_cause_id'], $actor);

                return [
                    'text'          => $f['text'],
                    'source'        => Maintenance::FINDING_GARAGE,
                    'severity'      => $f['severity'],
                    'root_cause'    => $cause,
                    'root_cause_id' => $causeId,
                    'by'            => $actor->name,
                    'at'            => Carbon::now()->toIso8601String(),
                    'status_check'  => $this->statusConflictFor($f['text'], $vehicle),
                ];
            });
            $ticket->findings = collect($ticket->findings ?? [])->concat($added)->values()->all();
            $ticket->save();

            $this->notifier->notifyByPermission(self::NOTIFY_CONTROLLERS, [
                'type'     => 'maint_garage_finding',
                'category' => 'maintenance',
                'severity' => 'info',
                'title'    => 'Garage finding added · ' . $this->label($vehicle),
                'body'     => trim($this->label($vehicle) . ' — ' . $added->count() . ' new garage-identified '
                                . ($added->count() === 1 ? 'finding' : 'findings') . ' by ' . $actor->name . '.'),
                'url'      => $this->link($ticket),
                'key'      => 'maint_wf:' . $ticket->id . ':finding:' . Carbon::now()->timestamp,
                'icon'     => 'wrench',
                'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no],
            ], $actor->id);

            return $ticket->load($this->eager());
        });
    }

    // ── UC-5 — Logistics relays "the garage is done" ────────────────────────────

    /**
     * Garage finished; the car goes to the Supervisor's Video-Review gate (repair_review) — NOT straight
     * to Ready for Pickup. No odometer is captured here: the car doesn't move inside the workshop, so the
     * reading would just duplicate the garage-arrival one (the return-to-service reading is taken at the
     * re-inspection sign-off). Optional close-out cost + garage feedback + per-fault time/line-items only.
     * A supervisor (Waleed/Abdullah) then reviews the garage's video and either approves it for pickup
     * (approveRepair()) or sends it back for a re-fix (requestRefix()).
     */
    public function markReady(Maintenance $ticket, array $data, User $actor): Maintenance
    {
        $this->assertTransition($ticket, Maintenance::WF_REPAIR_REVIEW);

        // Fix-All gate: the car can't be marked ready while any fault is still open — every fault must be
        // fixed (or cancelled as a non-issue) first. Task-less legacy tickets (total 0) are exempt.
        $open = $ticket->tasksProgress()['open'] ?? 0;
        if ($open > 0) {
            throw new WorkflowTransitionException(
                'Fix or cancel all faults before marking the car ready — ' . $open . ' still open.',
                ['field' => 'tasks', 'open' => $open],
            );
        }

        // "Maintenance complete" no longer captures an odometer — the car doesn't move inside the workshop,
        // so a reading here would just duplicate the garage-arrival (receive) one. The return-to-service
        // reading is taken later at the re-inspection sign-off. Kept OPTIONAL (not rejected) for backward
        // compatibility: if a reading is still supplied it's recorded, otherwise the step proceeds without one.
        $odometer = (int) ($data['return_odometer'] ?? 0);

        return DB::transaction(function () use ($ticket, $data, $actor, $odometer) {
            if ($odometer >= 1) {
                $ticket->return_odometer = $odometer;
                // Garage IN vs OUT continuity: when we have an intake reading, OUT must be >= IN — a positive
                // delta beyond tolerance means the garage road-tested it ('test_drive', confirm-not-block).
                // With no intake to compare, fall back to a plain forward-continuity check against the last
                // reading (pickup, else the test anchor).
                if ($ticket->receive_odometer !== null) {
                    $this->recordOdometerFlag($ticket, 'return', $odometer, $ticket->receive_odometer, OdometerContinuityService::STAGE_GARAGE_OUT, $data['odometer_note'] ?? null, $actor);
                } else {
                    $this->recordOdometerFlag($ticket, 'return', $odometer, $ticket->dispatch_odometer ?? $ticket->test_odometer, OdometerContinuityService::STAGE_RETURN, $data['odometer_note'] ?? null, $actor);
                }
            }
            if (array_key_exists('garage_feedback', $data)) {
                $ticket->garage_feedback = $this->clean($data['garage_feedback']);
            }
            if (array_key_exists('cost', $data) && $data['cost'] !== '' && $data['cost'] !== null) {
                $ticket->cost = $data['cost'];
            }

            // Granular time-per-fault: when the car comes back, the driver/dispatcher may attribute
            // the ACTUAL repair time to each specific fault tag. We write the hours straight onto the
            // matching finding object (the source of truth for the fault), so each tag carries its own
            // repair time — the structured data that powers Garage Efficiency & Fault Recurrence reports.
            if (! empty($data['repair_times']) && is_array($data['repair_times'])) {
                $ticket->findings = $this->applyRepairTimes($ticket->findings ?? [], $data['repair_times']);
            }

            // Structured Parts + Labor breakdown: the garage step is the natural place to itemise the
            // bill. When line items are supplied they REPLACE the ticket's set and OWN the cost (the
            // manual `cost` above is ignored in favour of the auto-summed parts_total + labor_total).
            if (array_key_exists('line_items', $data) && is_array($data['line_items'])) {
                $this->applyLineItems($ticket, $data['line_items'], $actor);
            }
            $ticket->event_status = 'OUT';   // not back until re-inspected / QA-passed & closed
            $ticket->ready_by     = $actor->id;
            $ticket->ready_at     = Carbon::now();
            $ticket->returned_at  = Carbon::now(); // car is physically back from the garage → garage clock stops
            $ticket->workflow_status = Maintenance::WF_REPAIR_REVIEW;
            $ticket->save();

            $this->cascade($ticket->vehicle_id);

            // The Supervisor Video-Review gate is optional (config/features.php → video_review). While it is
            // PARKED, "garage done" promotes straight to the final re-inspection; the video-review
            // logic below is skipped but kept intact, ready to flip back on.
            $videoReview = (bool) config('features.video_review');
            $this->log->record($ticket, VehicleLogEvent::EVENT_READY, $actor, [
                'description' => ($videoReview
                    ? 'Repair finished — awaiting the supervisor\'s video review · return odometer '
                    : 'Repair finished · return odometer ')
                    . number_format($odometer) . ' km (by ' . $actor->name . ')',
                'meta'        => ['return_odometer' => $odometer, 'garage' => $ticket->garage],
            ]);

            if (! $videoReview) {
                // Gate parked → advance to Ready for Pickup now, exactly as approveRepair would (no video
                // required). The car passes through repair_review transiently within this same request.
                return $this->promoteFromRepair($ticket, $actor);
            }

            // The garage is done. The SUPERVISORS (Waleed/Abdullah) own the next step: watch the garage's
            // video on the ticket, then approve it for re-inspection or send it back for a re-fix. Only
            // they get the actionable alert — the inspector isn't pulled in until a supervisor approves.
            $vehicle  = $ticket->loadMissing('vehicle')->vehicle;
            $hasVideo = $ticket->media()->exists();
            $this->notifier->notifyByPermission(self::NOTIFY_DISPATCHER, [
                'type'     => 'maint_repair_review',
                'category' => 'maintenance',
                'severity' => 'warning',
                'title'    => 'Review the repair · ' . $this->label($vehicle),
                'body'     => trim($this->label($vehicle) . ' is back from ' . ($ticket->garage ?: 'the garage')
                                . ' — review the video' . ($hasVideo ? '' : ' (awaiting upload)')
                                . ', then approve for re-inspection or request a re-fix.'),
                'url'      => $this->link($ticket),
                'key'      => 'maint_wf:' . $ticket->id . ':repair_review',
                'icon'     => 'check',
                'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no, 'has_video' => $hasVideo],
            ], $actor->id);

            return $ticket->load($this->eager());
        });
    }

    // ── UC-5a — Supervisor Video-Review gate (Waleed / Abdullah) ────────────────

    /**
     * Supervisor Video-Review — APPROVE. A supervisor (Waleed/Abdullah) has watched the garage's video and
     * is satisfied, so the car moves on to Ready for Pickup. The garage's video is the record the review
     * is based on, so at least one must be uploaded first. This is the ONLY route out of repair_review to
     * pickup — logistics isn't pulled in until a supervisor has signed off on the video.
     */
    public function approveRepair(Maintenance $ticket, User $actor): Maintenance
    {
        if ($ticket->workflow_status !== Maintenance::WF_REPAIR_REVIEW) {
            throw new WorkflowTransitionException('This ticket is not awaiting the supervisor\'s repair review.', [
                'workflow_status' => $ticket->workflow_status,
            ]);
        }
        // The pickup hand-off is based on the garage's evidence — refuse to advance a repair nobody has
        // any media for. A photo or a video both count.
        if (! $ticket->media()->exists()) {
            throw new WorkflowTransitionException('Upload the garage\'s repair photo or video before approving — the repair review is based on it.', [
                'field' => 'media',
            ]);
        }

        return $this->promoteFromRepair($ticket, $actor);
    }

    /**
     * Advance a repaired car out of the garage-done stage to Ready for Pickup. Shared by the Supervisor
     * Video-Review approval (approveRepair, video required) AND — while the video-review gate is parked
     * (config/features.php → video_review = false) — by markReady, which promotes straight through with
     * no video required. Assumes the ticket is currently in repair_review.
     */
    private function promoteFromRepair(Maintenance $ticket, User $actor): Maintenance
    {
        $this->assertTransition($ticket, Maintenance::WF_READY_FOR_PICKUP);

        return DB::transaction(function () use ($ticket, $actor) {
            $ticket->workflow_status = Maintenance::WF_READY_FOR_PICKUP;
            $ticket->save();

            $this->cascade($ticket->vehicle_id);
            $this->log->record($ticket, VehicleLogEvent::EVENT_READY, $actor, [
                'description' => 'Repair signed off — ready for pickup from ' . ($ticket->garage ?: 'the garage') . ' (by ' . $actor->name . ')',
                'meta'        => ['garage' => $ticket->garage],
            ]);

            // Hand off to LOGISTICS (drivers) — go collect the car from the garage.
            $vehicle = $ticket->loadMissing('vehicle')->vehicle;
            $this->notifier->notifyByPermission(self::NOTIFY_LOGISTICS, [
                'type'     => 'maint_ready_for_pickup',
                'category' => 'maintenance',
                'severity' => 'info',
                'title'    => 'Ready for pickup · ' . $this->label($vehicle),
                'body'     => trim($this->label($vehicle) . ' is signed off at ' . ($ticket->garage ?: 'the garage')
                                . ' — collect it and bring it back to base.'),
                'url'      => $this->link($ticket),
                'key'      => 'maint_wf:' . $ticket->id . ':ready_for_pickup',
                'icon'     => 'check',
                'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no],
            ], $actor->id);

            // Notify the original requester their car passed review and is on its way back.
            if ($ticket->requested_by && $ticket->requested_by !== $actor->id) {
                $requester = User::find($ticket->requested_by);
                if ($requester) {
                    $this->notifier->notifyUser($requester, [
                        'type'     => 'maint_ready_for_requester',
                        'category' => 'maintenance',
                        'severity' => 'info',
                        'title'    => 'Your car is on its way back · ' . $this->label($vehicle),
                        'body'     => $this->label($vehicle) . ' is signed off at ' . ($ticket->garage ?: 'the garage')
                                        . ' and awaiting pickup.',
                        'url'      => $this->link($ticket),
                        'key'      => 'maint_wf:' . $ticket->id . ':ready_requester',
                        'icon'     => 'check',
                    ]);
                }
            }

            return $ticket->load($this->eager());
        });
    }

    // ── UC-5b — Driver collects the car from the garage (mandatory photo checkpoint) ────────────

    /**
     * The driver has physically COLLECTED the car from the garage — the first of the two mandatory
     * checkpoints on the return leg (the second is arriveAtPark()). Captures the garage-OUT odometer here
     * (the car leaves the garage at this moment) into return_odometer + a 'return' continuity flag, and
     * heals the car's canonical mileage forward. No workflow_status change: the ticket stays at
     * ready_for_pickup while the car is en route back to base. The photo AND the reading are validated
     * `required` at the controller edge (MaintenanceWorkflowController::collectFromGarage).
     */
    public function collectFromGarage(Maintenance $ticket, array $data, User $actor): Maintenance
    {
        if ($ticket->workflow_status !== Maintenance::WF_READY_FOR_PICKUP) {
            throw new WorkflowTransitionException('This ticket is not awaiting pickup from the garage.', [
                'workflow_status' => $ticket->workflow_status,
            ]);
        }

        // The garage-OUT reading — captured the moment the driver collects the car (this is when it
        // physically leaves the garage, now that "Maintenance complete" no longer takes a reading). Stored
        // in return_odometer; a forward delta vs the garage-arrival reading means the garage road-tested it.
        $odometer = (int) ($data['return_odometer'] ?? 0);

        return DB::transaction(function () use ($ticket, $data, $actor, $odometer) {
            if ($odometer >= 1) {
                $ticket->return_odometer = $odometer;
                $prev = $ticket->receive_odometer ?? $ticket->dispatch_odometer ?? $ticket->test_odometer;
                $this->recordOdometerFlag($ticket, 'return', $odometer, $prev !== null ? (int) $prev : null, OdometerContinuityService::STAGE_GARAGE_OUT, $data['odometer_note'] ?? null, $actor);
            }
            $ticket->picked_up_from_garage_at = Carbon::now();
            $ticket->picked_up_from_garage_by = $actor->id;
            $ticket->save();

            // Forward-only heal of the car's canonical mileage with the collection reading (mirrors the
            // other capture points).
            if ($odometer >= 1 && ($vehicle = $ticket->loadMissing('vehicle')->vehicle)) {
                $this->applyTestOdometer($vehicle, $odometer);
            }

            $this->log->record($ticket, VehicleLogEvent::EVENT_READY, $actor, [
                'description' => 'Car collected from ' . ($ticket->garage ?: 'the garage') . ' — en route to base (by ' . $actor->name . ')',
                'meta'        => ['garage' => $ticket->garage, 'odometer' => $odometer >= 1 ? $odometer : null],
            ]);

            return $ticket->load($this->eager());
        });
    }

    /**
     * Supervisor Video-Review — REQUEST A RE-FIX. The supervisor watched the garage's video and is NOT
     * satisfied, so the car goes back to the SAME garage for more work (→ under_repair) carrying the
     * reason. Distinct from a re-inspection failure (which returns the car to the dispatch queue to be
     * re-dispatched): this keeps the car at the garage — the work simply isn't finished. A reason is required so the
     * garage knows what to fix; it's logged to the follow-up trail + garage feedback.
     */
    public function requestRefix(Maintenance $ticket, ?string $reason, User $actor): Maintenance
    {
        if ($ticket->workflow_status !== Maintenance::WF_REPAIR_REVIEW) {
            throw new WorkflowTransitionException('A re-fix can only be requested while the repair is under review.', [
                'workflow_status' => $ticket->workflow_status,
            ]);
        }
        $this->assertTransition($ticket, Maintenance::WF_UNDER_REPAIR);

        $text = $this->clean($reason);
        if ($text === null) {
            throw new WorkflowTransitionException('Say what still needs fixing before sending it back to the garage.', ['field' => 'reason']);
        }

        return DB::transaction(function () use ($ticket, $text, $actor) {
            $ticket->garage_feedback = trim(($ticket->garage_feedback ? $ticket->garage_feedback . "\n" : '') . 'Re-fix requested: ' . $text);
            $ticket->follow_ups = collect($ticket->follow_ups ?? [])->push([
                'text'  => 'Re-fix requested: ' . $text,
                'by'    => $actor->name,
                'by_id' => $actor->id,
                'at'    => Carbon::now()->toIso8601String(),
            ])->values()->all();
            // Back at the garage: still physically out, and the review reading no longer holds (the garage
            // clock runs again until it's finished a second time).
            $ticket->event_status    = 'OUT';
            $ticket->returned_at     = null;
            $ticket->workflow_status = Maintenance::WF_UNDER_REPAIR;
            $ticket->save();

            $this->cascade($ticket->vehicle_id);

            $garage = $ticket->vendor?->name ?: ($ticket->garage ?: 'the garage');
            $this->log->record($ticket, VehicleLogEvent::EVENT_UNDER_REPAIR, $actor, [
                'description' => 'Supervisor requested a re-fix — sent back to ' . $garage . ': ' . $text . ' (by ' . $actor->name . ')',
                'meta'        => ['garage' => $garage, 'reason' => $text, 're_fix' => true],
            ]);

            // Tell the drivers/coordinators (who relay to the garage + will pick the car up again) and the
            // controllers (visibility) that the car is back at the garage for more work.
            $vehicle = $ticket->loadMissing('vehicle')->vehicle;
            $this->notifier->notifyByAnyPermission([self::NOTIFY_LOGISTICS, self::NOTIFY_CONTROLLERS], [
                'type'     => 'maint_refix_requested',
                'category' => 'maintenance',
                'severity' => 'warning',
                'title'    => 'Re-fix requested · ' . $this->label($vehicle),
                'body'     => trim($this->label($vehicle) . ' — the supervisor sent it back to ' . $garage . ': ' . $text),
                'url'      => $this->link($ticket),
                'key'      => 'maint_wf:' . $ticket->id . ':refix:' . Carbon::now()->timestamp,
                'icon'     => 'wrench',
                'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no, 'garage' => $garage],
            ], $actor->id);

            return $ticket->load($this->eager());
        });
    }

    // ── UC-5d — Driver arrives back at base (mandatory photo checkpoint + severity auto-branch) ──

    /**
     * The driver has physically brought the car back to base — the second mandatory photo checkpoint on
     * the return leg (collectFromGarage() is the first). The photo file itself is validated `required` at
     * the controller edge (MaintenanceWorkflowController::arriveAtPark) — this action CANNOT complete
     * without it.
     *
     * Auto-branches by repair severity (Maintenance::isMajorRepair(), keyed off the inspector's mandatory
     * fault_severity grade) within the SAME transaction — no manual gate, no ticket ever rests in
     * in_our_park:
     *   • minor repair (routine)           → straight to closed, the car is freed immediately.
     *   • major repair (critical/moderate) → to ready_for_reinspection; the Inspector must perform a
     *     final QA pass before the car is freed. cascade() is deferred until AFTER the branch decision
     *     so the car is never briefly readable as "available" mid-transaction.
     *
     * @param array{cost?:mixed, vendor_id?:?int, notes?:?string, defer_invoice?:bool} $data
     */
    public function arriveAtPark(Maintenance $ticket, array $data, User $actor): Maintenance
    {
        $this->assertTransition($ticket, Maintenance::WF_IN_OUR_PARK);

        return DB::transaction(function () use ($ticket, $data, $actor) {
            $ticket->park_arrived_at = Carbon::now();
            $ticket->park_arrived_by = $actor->id;
            $ticket->workflow_status = Maintenance::WF_IN_OUR_PARK;
            $ticket->save();

            $this->log->record($ticket, VehicleLogEvent::EVENT_READY, $actor, [
                'description' => 'Arrived back at our park (by ' . $actor->name . ')',
                'meta'        => ['garage' => $ticket->garage],
            ]);

            if (! $ticket->isMajorRepair()) {
                // Minor repair (routine) — auto-close straight away; close() owns the cascade that frees
                // the car and the closing summary/notifications.
                return $this->close($ticket, $data, $actor);
            }

            // Major repair (critical/moderate) — do NOT free the car yet. Route to the final QA
            // re-inspection instead of closing. This transition is intentionally NOT written as its own
            // timeline entry: the "Arrived back at our park" event above already covers the driver's leg,
            // and the QA re-inspection surfaces as the ticket's live stage — a second log line here just
            // duplicated it. The inspector is still pinged below.
            $this->assertTransition($ticket, Maintenance::WF_READY_REINSPECTION);
            $ticket->workflow_status = Maintenance::WF_READY_REINSPECTION;
            $ticket->save();

            $this->cascade($ticket->vehicle_id); // recompute — stays "maintenance" (ready_for_reinspection ∈ WF_TICKET_STATES)

            $vehicle = $ticket->loadMissing('vehicle')->vehicle;
            $this->notifier->notifyByPermission(self::NOTIFY_INSPECTOR, [
                'type'     => 'maint_ready_reinspect',
                'category' => 'maintenance',
                'severity' => 'warning',
                'title'    => 'Final QA re-inspection · ' . $this->label($vehicle),
                'body'     => trim($this->label($vehicle) . ' is back at base — graded '
                                . ($ticket->fault_severity ?: 'non-routine') . ', so it needs your final QA pass before it can go back on the road.'),
                'url'      => $this->link($ticket),
                'key'      => 'maint_wf:' . $ticket->id . ':ready_for_reinspection',
                'icon'     => 'check',
                'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no],
            ], $actor->id);

            // Notify the original requester their car is back but still pending a final QA check.
            if ($ticket->requested_by && $ticket->requested_by !== $actor->id) {
                $requester = User::find($ticket->requested_by);
                if ($requester) {
                    $this->notifier->notifyUser($requester, [
                        'type'     => 'maint_ready_for_requester',
                        'category' => 'maintenance',
                        'severity' => 'info',
                        'title'    => 'Your car is back · ' . $this->label($vehicle),
                        'body'     => $this->label($vehicle) . ' is back at base and in the final QA re-inspection stage.',
                        'url'      => $this->link($ticket),
                        'key'      => 'maint_wf:' . $ticket->id . ':ready_requester',
                        'icon'     => 'check',
                    ]);
                }
            }

            return $ticket->load($this->eager());
        });
    }

    // ── UC-6 — Re-inspect/finalize and return to service ────────────────────────

    /**
     * Signs the ticket off: records the return ('IN' + actual_in_date) and frees the car. Called either
     * automatically by arriveAtPark() (minor repair, straight off in_our_park) or manually by the
     * Inspector after a passed final QA re-inspection (major repair, off ready_for_reinspection).
     *
     * Financial Decoupling (Deferred Cost): the repair COST is deliberately NOT required to close —
     * the car is signed back into service the moment it is roadworthy, and the final invoice cost is
     * entered LATER (recordCost(), once the paperwork is processed). A garage (vendor) is still
     * required, since the workflow always assigns one at dispatch. Pass cost/vendor_id here only if
     * you happen to have them at sign-off.
     *
     * @param array{cost?:mixed, vendor_id?:?int, notes?:?string} $data
     */
    public function close(Maintenance $ticket, array $data, User $actor): Maintenance
    {
        // Final re-inspection odometer (optional) — the QC reading the inspector captures at sign-off, when
        // the car is physically back for the pass check. Recorded BEFORE the defer-invoice branch so BOTH
        // the deferred and full-close paths persist it (the PASS branch defaults to deferred). Runs the
        // continuity check vs the last recorded reading and heals the car's canonical mileage forward; the
        // operator's >10 km-gap note rides along on the stored flag. Stored under the 'reinspect' flag key —
        // it never overwrites the garage-OUT return_odometer captured at mark-ready.
        $reinspectOdo = isset($data['final_odometer']) && is_numeric($data['final_odometer']) ? (int) $data['final_odometer'] : null;
        if ($reinspectOdo !== null && $reinspectOdo > 0) {
            $prev = $ticket->return_odometer ?? $ticket->receive_odometer ?? $ticket->dispatch_odometer ?? $ticket->test_odometer;
            $ticket->reinspect_odometer = $reinspectOdo; // its own column → a distinct row in the mileage timeline
            $this->recordOdometerFlag($ticket, 'reinspect', $reinspectOdo, $prev !== null ? (int) $prev : null, OdometerContinuityService::STAGE_RETURN, $data['odometer_note'] ?? null, $actor);
            if ($vehicle = $ticket->loadMissing('vehicle')->vehicle) {
                $this->applyTestOdometer($vehicle, $reinspectOdo); // forward-only heal, mirrors the other capture points
            }
        }

        // Deferred-invoice decoupling — the sign-off passed but the paper invoice isn't ready. The car
        // STILL returns to service; the ticket parks in awaiting_invoice for the tracker + 3-day SLA to chase.
        if (! empty($data['defer_invoice'])) {
            return $this->deferInvoice($ticket, $data, $actor);
        }

        $this->assertTransition($ticket, Maintenance::WF_CLOSED);

        // Apply any closing values before the guardrail check.
        if (array_key_exists('cost', $data) && $data['cost'] !== '' && $data['cost'] !== null) {
            $ticket->cost = $data['cost'];
        }
        if (! empty($data['vendor_id'])) {
            $ticket->vendor_id = (int) $data['vendor_id'];
        }

        // Cost is intentionally NOT gated here (deferred-cost decoupling) — only the garage is.
        if (! $ticket->vendor_id) {
            throw new WorkflowTransitionException('A garage (vendor) is required before closing the ticket.', [
                'field' => 'vendor_id',
            ]);
        }

        return DB::transaction(function () use ($ticket, $data, $actor) {
            // Car came back → 'IN' records the return and the cascade frees it. The inspector may
            // pass the date the car actually came back; if they don't, it's today.
            $ticket->event_status    = 'IN';
            $ticket->actual_in_date  = ! empty($data['actual_in_date'])
                ? Carbon::parse($data['actual_in_date'])->startOfDay()
                : Carbon::today();
            $ticket->wf_closed_by    = $actor->id;
            $ticket->wf_closed_at    = Carbon::now();
            $ticket->workflow_status = Maintenance::WF_CLOSED;

            // Auto-generate the LEGACY-FORMAT maintenance note from the ticket's structured workflow
            // data (inspector report + findings + garage notes + dates/garage/odometer), so the closed
            // visit reads like the team's old hand-typed log on every history surface — board garage-log,
            // per-vehicle Service History and the workshop-events modal all render `maintenance_notes`
            // unchanged. The inspector's optional closing remark is folded in. See composeClosingSummary().
            $ticket->maintenance_notes = $this->composeClosingSummary($ticket, $actor, $data['notes'] ?? null);

            $ticket->save();

            $this->cascade($ticket->vehicle_id);   // → available unless another movement holds it

            // Cost may legitimately be pending (deferred-cost decoupling) — say so rather than "AED 0".
            $costLabel = $ticket->cost !== null ? 'cost AED ' . number_format((float) $ticket->cost) : 'cost pending';

            $this->log->record($ticket, VehicleLogEvent::EVENT_CLOSED, $actor, [
                'description' => 'Re-inspected and returned to service · ' . $costLabel . ' (by ' . $actor->name . ')',
                'meta'        => ['cost' => $ticket->cost !== null ? (float) $ticket->cost : null, 'garage' => $ticket->garage],
            ]);

            $vehicle = $ticket->loadMissing('vehicle')->vehicle;

            // Close the loop with the Controllers (Marwa & Leen). For a CUSTOMER COMPLAINT the copy is
            // tailored into a Resolution Notification — they are the first to know the car passed the final
            // re-inspection so they can call the customer back and confirm the fix. Any other ticket gets the standard
            // "back in service" note. Same recipients (maintenance.manage), different framing.
            $isComplaint = $ticket->trigger_reason === Maintenance::TRIGGER_CUSTOMER;
            $this->notifier->notifyByPermission(self::NOTIFY_CONTROLLERS, $isComplaint ? [
                'type'     => 'maint_complaint_resolved',
                'category' => 'maintenance',
                'severity' => 'success',
                'title'    => '✅ Complaint resolved · call the customer · ' . $this->label($vehicle),
                'body'     => trim($this->label($vehicle) . ' passed final sign-off and is back in service — '
                                . 'the customer complaint is resolved. Close the loop with the customer.'
                                . ($ticket->customer_complaint ? ' Original issue: “' . $ticket->customer_complaint . '”.' : '')),
                'url'      => $this->link($ticket),
                'key'      => 'maint_wf:' . $ticket->id . ':closed',
                'icon'     => 'check',
                'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no, 'customer_complaint' => true],
            ] : [
                'type'     => 'maint_closed',
                'category' => 'maintenance',
                'severity' => 'success',
                'title'    => 'Back in service · ' . $this->label($vehicle),
                'body'     => trim($this->label($vehicle) . ' re-inspected and returned to service · ' . $costLabel . '.'),
                'url'      => $this->link($ticket),
                'key'      => 'maint_wf:' . $ticket->id . ':closed',
                'icon'     => 'check',
                'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no],
            ], $actor->id);

            // Notify the original requester that the repair is fully done and the car is back in service.
            if ($ticket->requested_by && $ticket->requested_by !== $actor->id) {
                $requester = User::find($ticket->requested_by);
                if ($requester) {
                    $this->notifier->notifyUser($requester, [
                        'type'     => 'maint_closed_for_requester',
                        'category' => 'maintenance',
                        'severity' => 'success',
                        'title'    => 'Car returned to service · ' . $this->label($vehicle),
                        'body'     => $this->label($vehicle) . ' has passed re-inspection and is back in the fleet.',
                        'url'      => $this->link($ticket),
                        'key'      => 'maint_wf:' . $ticket->id . ':closed_requester',
                        'icon'     => 'check',
                    ]);
                }
            }

            return $ticket->load($this->eager());
        });
    }

    /**
     * Deferred-invoice sign-off — the car passed re-inspection/QA and RETURNS TO SERVICE now, but the
     * invoice isn't ready. Operationally this behaves like close() (event 'IN', cascade frees the car,
     * the legacy closing note is composed) EXCEPT the ticket parks in `awaiting_invoice` instead of
     * `closed`, stamping the SLA clock. It's picked up by the /invoices/pending-submission tracker and
     * the daily 3-day overdue scan; entering the invoice (portal/line-items) or "mark received" closes it.
     */
    public function deferInvoice(Maintenance $ticket, array $data, User $actor): Maintenance
    {
        $this->assertTransition($ticket, Maintenance::WF_AWAITING_INVOICE);

        if (! empty($data['vendor_id'])) {
            $ticket->vendor_id = (int) $data['vendor_id'];
        }
        if (! $ticket->vendor_id) {
            throw new WorkflowTransitionException('A garage (vendor) is required before signing off the repair.', [
                'field' => 'vendor_id',
            ]);
        }

        return DB::transaction(function () use ($ticket, $data, $actor) {
            // Car is physically back and freed for service — identical to close(), minus the financial close.
            $ticket->event_status    = 'IN';
            $ticket->actual_in_date  = ! empty($data['actual_in_date'])
                ? Carbon::parse($data['actual_in_date'])->startOfDay()
                : Carbon::today();
            $ticket->workflow_status = Maintenance::WF_AWAITING_INVOICE;
            $ticket->awaiting_invoice_since = Carbon::now();
            $ticket->maintenance_notes = $this->composeClosingSummary($ticket, $actor, $data['notes'] ?? null);
            $ticket->save();

            $this->cascade($ticket->vehicle_id);   // → back in service (awaiting_invoice is NOT WF_TICKET_STATES)

            $this->log->record($ticket, VehicleLogEvent::EVENT_AWAITING_INVOICE, $actor, [
                'description' => 'Re-inspected and returned to service — invoice pending (by ' . $actor->name . ')',
                'meta'        => ['garage' => $ticket->garage, 'awaiting_invoice_since' => $ticket->awaiting_invoice_since?->toIso8601String()],
            ]);

            $vehicle = $ticket->loadMissing('vehicle')->vehicle;
            // Controllers + the Supervisor (who chases the garage) are told the invoice is now outstanding.
            $this->notifier->notifyByAnyPermission([self::NOTIFY_CONTROLLERS, self::NOTIFY_DISPATCHER], [
                'type'     => 'maint_awaiting_invoice',
                'category' => 'maintenance',
                'severity' => 'info',
                'title'    => 'Back in service · invoice pending · ' . $this->label($vehicle),
                'body'     => trim($this->label($vehicle) . ' passed sign-off and is back in the fleet — the garage '
                                . 'invoice is still outstanding. Due within ' . Maintenance::INVOICE_SLA_DAYS . ' days.'),
                'url'      => '/invoices/pending-submission',
                'key'      => 'maint_wf:' . $ticket->id . ':awaiting_invoice',
                'icon'     => 'invoice',
                'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no],
            ], $actor->id);

            return $ticket->load($this->eager());
        });
    }

    /**
     * "Mark as Serviced" — complete an ON-SITE (mobile) ticket in one step. The mobile job is done where
     * the car is parked, so this is the entire back-half of the workflow collapsed into a single action:
     * NO garage dispatch, NO transit, NO re-inspection and NO QA. The car was never marked out of service
     * (it only carried a "Pending Maintenance" tag), so this simply resolves the faults, closes the ticket
     * and clears the tag.
     *
     * Deliberately separate from close(): close() is the workshop sign-off and REQUIRES a garage (vendor);
     * an on-site job has none. It is strictly the WF_ON_SITE_PENDING → closed exit.
     *
     * @param array{notes?:?string, cost?:mixed, vendor_id?:?int, actual_in_date?:?string} $data
     */
    public function markServiced(Maintenance $ticket, array $data, User $actor): Maintenance
    {
        if ($ticket->workflow_status !== Maintenance::WF_ON_SITE_PENDING) {
            throw new WorkflowTransitionException('Only an on-site (mobile) ticket can be marked serviced.', [
                'workflow_status' => $ticket->workflow_status,
            ]);
        }
        $this->assertTransition($ticket, Maintenance::WF_CLOSED);

        // A mobile vendor is OPTIONAL for on-site work (there may be no garage at all). Record the cost
        // if it was captured on the spot; otherwise it stays deferred like every other ticket.
        if (array_key_exists('cost', $data) && $data['cost'] !== '' && $data['cost'] !== null) {
            $ticket->cost = $data['cost'];
        }
        if (! empty($data['vendor_id'])) {
            $ticket->vendor_id = (int) $data['vendor_id'];
        }

        return DB::transaction(function () use ($ticket, $data, $actor) {
            // The car never physically left, so there is no OUT → IN journey; we still stamp an IN date so
            // the closed visit reads consistently in the history surfaces.
            $ticket->event_status    = 'IN';
            $ticket->actual_in_date  = ! empty($data['actual_in_date'])
                ? Carbon::parse($data['actual_in_date'])->startOfDay()
                : Carbon::today();
            $ticket->wf_closed_by    = $actor->id;
            $ticket->wf_closed_at    = Carbon::now();
            $ticket->workflow_status = Maintenance::WF_CLOSED;
            $ticket->maintenance_notes = $this->composeClosingSummary($ticket, $actor, $data['notes'] ?? null);
            $ticket->save();

            // The car was already available (on_site_pending is outside WF_TICKET_STATES); reconcile anyway
            // so any lingering derived state settles and the "Pending Maintenance" tag clears.
            $this->cascade($ticket->vehicle_id);

            $vehicle   = $ticket->loadMissing('vehicle')->vehicle;
            $costLabel = $ticket->cost !== null ? 'cost AED ' . number_format((float) $ticket->cost) : 'cost pending';

            $this->log->record($ticket, VehicleLogEvent::EVENT_CLOSED, $actor, [
                'description' => 'On-site service completed · ' . $costLabel . ' (by ' . $actor->name . ')',
                'meta'        => ['cost' => $ticket->cost !== null ? (float) $ticket->cost : null, 'repair_location' => Maintenance::REPAIR_ON_SITE],
            ]);

            // Close the loop with the Controllers — the on-site job is done and the car is untouched-available.
            $this->notifier->notifyByPermission(self::NOTIFY_CONTROLLERS, [
                'type'     => 'maint_on_site_done',
                'category' => 'maintenance',
                'severity' => 'success',
                'title'    => '✅ On-site service done · ' . $this->label($vehicle),
                'body'     => trim($this->label($vehicle) . ' was serviced on-site and stays available · ' . $costLabel . '.'),
                'url'      => $this->link($ticket),
                'key'      => 'maint_wf:' . $ticket->id . ':closed',
                'icon'     => 'check',
                'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no, 'repair_location' => Maintenance::REPAIR_ON_SITE],
            ], $actor->id);

            return $ticket->load($this->eager());
        });
    }

    /**
     * The outstanding invoice landed — move an awaiting_invoice ticket to fully CLOSED. Called explicitly
     * ("mark invoice received" on the tracker) and automatically once an itemised invoice is recorded
     * (syncLineItems / an accepted garage-portal submission). The car is already in service, so this only
     * flips the financial state + stamps the close.
     */
    public function finalizeInvoice(Maintenance $ticket, User $actor): Maintenance
    {
        $this->assertTransition($ticket, Maintenance::WF_CLOSED);

        return DB::transaction(function () use ($ticket, $actor) {
            $ticket->workflow_status       = Maintenance::WF_CLOSED;
            $ticket->awaiting_invoice_since = null;
            $ticket->wf_closed_by          = $actor->id;
            $ticket->wf_closed_at          = Carbon::now();
            $ticket->save();

            $this->log->record($ticket, VehicleLogEvent::EVENT_INVOICE_RECEIVED, $actor, [
                'description' => 'Outstanding invoice received — ticket closed (by ' . $actor->name . ')',
                'meta'        => ['cost' => $ticket->cost !== null ? (float) $ticket->cost : null],
            ]);

            return $ticket->load($this->eager());
        });
    }

    /**
     * Financial Decoupling (Deferred Cost) — record the final repair COST after the fact, once the
     * invoice paperwork is processed. Works on a ticket in ANY state (typically a closed one whose
     * cost was deferred at sign-off): it never changes the workflow_status, it just fills in the money
     * and stamps who/when so the late entry is auditable. Controllers are notified the cost landed.
     */
    public function recordCost(Maintenance $ticket, mixed $cost, User $actor): Maintenance
    {
        if (! is_numeric($cost) || (float) $cost < 0) {
            throw new WorkflowTransitionException('Enter a valid repair cost (a number, 0 or more).', ['field' => 'cost']);
        }

        return DB::transaction(function () use ($ticket, $cost, $actor) {
            $ticket->cost             = round((float) $cost, 2);
            $ticket->cost_recorded_at = Carbon::now();
            $ticket->cost_recorded_by = $actor->id;
            $ticket->save();

            $this->log->record($ticket, VehicleLogEvent::EVENT_COST_RECORDED, $actor, [
                'description' => 'Final repair cost recorded · AED ' . number_format((float) $ticket->cost, 2) . ' (by ' . $actor->name . ')',
                'meta'        => ['cost' => (float) $ticket->cost, 'by' => $actor->name],
            ]);

            $vehicle = $ticket->loadMissing('vehicle')->vehicle;
            $this->notifier->notifyByPermission(self::NOTIFY_CONTROLLERS, [
                'type'     => 'maint_cost_recorded',
                'category' => 'maintenance',
                'severity' => 'info',
                'title'    => 'Repair cost recorded · ' . $this->label($vehicle),
                'body'     => trim($this->label($vehicle) . ' — final repair cost AED '
                                . number_format((float) $ticket->cost, 2) . ' entered by ' . $actor->name . '.'),
                'url'      => $this->link($ticket),
                'key'      => 'maint_wf:' . $ticket->id . ':cost_recorded:' . Carbon::now()->timestamp,
                'icon'     => 'wrench',
                'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no, 'cost' => (float) $ticket->cost],
            ], $actor->id);

            return $ticket->load($this->eager());
        });
    }

    /**
     * Path A (Manual Entry) — request an itemised invoice from the garage. A garage is a Vendor with no
     * login, so this is NOT an in-app message to the vendor: it stamps the request on the ticket
     * (invoice_requested_at/by), logs it, and alerts our own controllers — the people who phone/email
     * the garage — carrying the garage's name + contact. Once the invoice arrives the team keys the
     * Parts + Labor in via syncLineItems (or, later, OCR fills the same line shape). Works in any state.
     */
    public function requestInvoice(Maintenance $ticket, User $actor): Maintenance
    {
        return DB::transaction(function () use ($ticket, $actor) {
            $ticket->invoice_requested_at = Carbon::now();
            $ticket->invoice_requested_by = $actor->id;
            $ticket->save();

            $ticket->loadMissing('vendor', 'vehicle');
            $vehicle = $ticket->vehicle;
            $garage  = $ticket->vendor?->name ?: ($ticket->garage ?: 'the garage');
            $contact = $ticket->vendor
                ? trim(implode(' · ', array_filter([$ticket->vendor->phone, $ticket->vendor->email])))
                : '';

            $this->log->record($ticket, VehicleLogEvent::EVENT_INVOICE_REQUESTED, $actor, [
                'description' => 'Itemised invoice requested from ' . $garage . ' (by ' . $actor->name . ')',
                'meta'        => ['garage' => $garage, 'vendor_id' => $ticket->vendor_id, 'by' => $actor->name],
            ]);

            $this->notifier->notifyByPermission(self::NOTIFY_CONTROLLERS, [
                'type'     => 'maint_invoice_requested',
                'category' => 'maintenance',
                'severity' => 'info',
                'title'    => 'Invoice requested · ' . $this->label($vehicle),
                'body'     => trim('Request an itemised invoice from ' . $garage
                                . ' for ' . $this->label($vehicle) . ($contact ? ' (' . $contact . ')' : '')
                                . ' — enter Parts & Labor once it arrives.'),
                'url'      => $this->link($ticket),
                'key'      => 'maint_wf:' . $ticket->id . ':invoice_requested:' . Carbon::now()->timestamp,
                'icon'     => 'invoice',
                'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no, 'garage' => $garage],
            ], $actor->id);

            return $ticket->load($this->eager());
        });
    }

    /**
     * Structured Parts + Labor breakdown — record/replace the itemised cost of a ticket, AFTER the
     * fact if need be (the "deferred edit" path: works on a ticket in ANY state, exactly like
     * recordCost). The line set is replaced wholesale, the parts/labor totals + canonical `cost`
     * are re-derived, who/when is stamped, and the Controllers are alerted. This is the home of the
     * Odoo-mappable data — each line is a part (BOM component) or labor (expense) line.
     *
     * Garage Invoice Validation: when a `$receiptTotal` is supplied it is checked against the itemised
     * grand total the lines just produced. A mismatch beyond a one-cent tolerance is BLOCKED unless the
     * caller also supplies a `$varianceExplanation` — so an unexplained gap between the paper receipt and
     * the keyed lines can never be posted. Recording the invoice also raises the accounting-bridge flag
     * (`reconciliation_status = pending`) so the downstream finance engine can pick the ticket up.
     *
     * @param array<int,array> $items raw line rows from the request (validated at the API edge)
     */
    public function syncLineItems(
        Maintenance $ticket,
        array $items,
        User $actor,
        ?float $receiptTotal = null,
        ?string $varianceExplanation = null,
    ): Maintenance {
        return DB::transaction(function () use ($ticket, $items, $actor, $receiptTotal, $varianceExplanation) {
            $this->applyLineItems($ticket, $items, $actor);

            // Variance gate — the itemised sum (now on $ticket->cost) vs the hand-keyed receipt total.
            // A gap over one cent must be explained, or the save is rejected before anything is stamped.
            $explanation = $this->clean($varianceExplanation);
            $variance    = 0.0;
            if ($receiptTotal !== null) {
                $variance = round((float) $ticket->cost - (float) $receiptTotal, 2);
                if (abs($variance) > 0.01 && $explanation === null) {
                    throw new WorkflowTransitionException(
                        'The itemised total (AED ' . number_format((float) $ticket->cost, 2) . ') does not match the '
                        . 'receipt total (AED ' . number_format((float) $receiptTotal, 2) . '). Add a variance '
                        . 'explanation to record it.',
                        ['field' => 'variance_explanation', 'variance' => $variance],
                    );
                }
            }

            $ticket->receipt_total = $receiptTotal;
            // Keep the note only while it is actually needed (a real mismatch); a matching invoice clears it.
            $ticket->variance_explanation = ($receiptTotal !== null && abs($variance) > 0.01) ? $explanation : null;

            // Accounting bridge — flag a real itemised invoice as pending reconciliation for the finance
            // engine ("Financial-Pending-Reconciliation"). An empty submission leaves the flag untouched.
            if ($ticket->lineItems()->count() > 0) {
                $ticket->reconciliation_status     = Maintenance::RECON_PENDING;
                $ticket->reconciliation_flagged_at = Carbon::now();
            }

            $ticket->cost_recorded_at = Carbon::now();
            $ticket->cost_recorded_by = $actor->id;
            $ticket->save();

            $count = $ticket->lineItems()->count();
            $this->log->record($ticket, VehicleLogEvent::EVENT_COST_RECORDED, $actor, [
                'description' => 'Parts & labor itemised — ' . $count . ' '
                    . ($count === 1 ? 'line' : 'lines') . ' · parts AED ' . number_format((float) $ticket->parts_total, 2)
                    . ' + labor AED ' . number_format((float) $ticket->labor_total, 2)
                    . ' = AED ' . number_format((float) $ticket->cost, 2) . ' (by ' . $actor->name . ')'
                    . ($receiptTotal !== null
                        ? ' · receipt AED ' . number_format((float) $receiptTotal, 2)
                            . (abs($variance) > 0.01 ? ' · variance AED ' . number_format($variance, 2) . ' explained' : ' · matches')
                        : '')
                    . ' · flagged for reconciliation',
                'meta'        => [
                    'parts_total'          => (float) $ticket->parts_total,
                    'labor_total'          => (float) $ticket->labor_total,
                    'cost'                 => (float) $ticket->cost,
                    'lines'                => $count,
                    'receipt_total'        => $receiptTotal,
                    'variance'             => $variance,
                    'reconciliation_status'=> $ticket->reconciliation_status,
                    'by'                   => $actor->name,
                ],
            ]);

            $hasVariance = $receiptTotal !== null && abs($variance) > 0.01;
            $vehicle = $ticket->loadMissing('vehicle')->vehicle;
            $this->notifier->notifyByPermission(self::NOTIFY_CONTROLLERS, [
                'type'     => 'maint_cost_itemized',
                'category' => 'maintenance',
                // A recorded gap between the receipt and the keyed lines is worth flagging louder.
                'severity' => $hasVariance ? 'warning' : 'info',
                'title'    => ($hasVariance ? 'Invoice recorded with variance · ' : 'Parts & labor recorded · ') . $this->label($vehicle),
                'body'     => trim($this->label($vehicle) . ' — ' . $count . ' '
                                . ($count === 1 ? 'line' : 'lines') . ' totalling AED '
                                . number_format((float) $ticket->cost, 2)
                                . ($hasVariance
                                    ? ' vs receipt AED ' . number_format((float) $receiptTotal, 2)
                                        . ' (variance AED ' . number_format($variance, 2) . ')'
                                    : '')
                                . ' entered by ' . $actor->name . ' — pending reconciliation.'),
                'url'      => $this->link($ticket),
                'key'      => 'maint_wf:' . $ticket->id . ':cost_itemized:' . Carbon::now()->timestamp,
                'icon'     => 'wrench',
                'meta'     => [
                    'ticket_id'     => $ticket->id,
                    'plate'         => $vehicle?->plate_no,
                    'cost'          => (float) $ticket->cost,
                    'receipt_total' => $receiptTotal,
                    'variance'      => $variance,
                ],
            ], $actor->id);

            // Deferred-invoice: recording the itemised invoice on an awaiting_invoice ticket satisfies the
            // outstanding-invoice obligation → close it out (the car is already back in service).
            if ($ticket->workflow_status === Maintenance::WF_AWAITING_INVOICE) {
                $this->finalizeInvoice($ticket, $actor);
            }

            return $ticket->load($this->eager());
        });
    }

    /**
     * Replace the ticket's Parts + Labor lines with a fresh set and re-derive the totals on the row.
     * Each raw row is normalised into a MaintenanceLineItem: a 'part' carries name/qty/unit price +
     * the install date & warranty months that power durability tracking; a 'labor' line is hours ×
     * rate. The line's `vehicle_id` is denormalised from the ticket (fast TCO/lifespan reports), and
     * `installed_odometer` falls back to the ticket's return reading when the caller leaves it blank.
     *
     * Does NOT save the ticket — it sets parts_total/labor_total/cost in memory via
     * recalcLineItemTotals() and leaves persistence to the caller (so it composes inside markReady's
     * own save, and syncLineItems saves explicitly with its audit stamps).
     *
     * @param array<int,array> $items
     */
    private function applyLineItems(Maintenance $ticket, array $items, User $actor): void
    {
        // Diagnosis-First: every line MUST be attributed to a finding that exists on this ticket — no
        // ghost costs. Build the allowed set (inspector + garage findings, matched case-insensitively
        // on text) once, and validate the WHOLE incoming batch before touching anything, so an unlinked
        // or unknown line fails fast with no side effects.
        $findingTexts = collect($ticket->findings ?? [])
            ->pluck('text')
            ->filter()
            ->map(fn ($t) => mb_strtolower(trim((string) $t)))
            ->flip();

        foreach ($items as $row) {
            if (! is_array($row) || $this->clean($row['description'] ?? null) === null) {
                continue; // a line with no description is meaningless — skipped, never persisted
            }
            $finding = $this->clean($row['finding_text'] ?? null);
            if ($finding === null) {
                throw new WorkflowTransitionException(
                    'Every part or labor line must be linked to a finding on this ticket (Diagnosis-First).',
                    ['field' => 'finding_text'],
                );
            }
            if (! $findingTexts->has(mb_strtolower($finding))) {
                throw new WorkflowTransitionException(
                    "“{$finding}” is not a finding on this ticket — link each cost to an existing symptom.",
                    ['field' => 'finding_text'],
                );
            }
        }

        // Wholesale replace — the editor always submits the full current set.
        $ticket->lineItems()->delete();

        $fallbackOdo = $ticket->return_odometer ?: $ticket->receive_odometer ?: null;

        foreach ($items as $row) {
            if (! is_array($row)) {
                continue;
            }
            $kind = ($row['kind'] ?? null) === MaintenanceLineItem::KIND_LABOR
                ? MaintenanceLineItem::KIND_LABOR
                : MaintenanceLineItem::KIND_PART;

            $description = $this->clean($row['description'] ?? null);
            if ($description === null) {
                continue; // a line with no description is meaningless — skip it
            }

            $isPart = $kind === MaintenanceLineItem::KIND_PART;
            $qty    = isset($row['quantity']) && is_numeric($row['quantity']) ? round((float) $row['quantity'], 2) : 1;
            $price  = isset($row['unit_price']) && is_numeric($row['unit_price']) ? round((float) $row['unit_price'], 2) : 0;

            $ticket->lineItems()->create([
                'vehicle_id'         => $ticket->vehicle_id,
                'kind'               => $kind,
                'finding_text'       => $this->clean($row['finding_text'] ?? null),
                'category_key'       => $this->clean($row['category_key'] ?? null),
                'description'        => $description,
                'part_number'        => $isPart ? $this->clean($row['part_number'] ?? null) : null,
                // Lightweight tire tracking — audit trail captured only when a tire part is fitted
                // (category 'tyres'); harmless nulls on every other line.
                'tire_brand'         => $isPart ? $this->clean($row['tire_brand'] ?? null) : null,
                'tire_dot'           => $isPart ? $this->clean($row['tire_dot'] ?? null) : null,
                'tire_tread_mm'      => $isPart && isset($row['tire_tread_mm']) && is_numeric($row['tire_tread_mm']) ? (float) $row['tire_tread_mm'] : null,
                'quantity'           => max(0, $qty),
                'uom'                => $this->clean($row['uom'] ?? null) ?: ($isPart ? 'unit' : 'hour'),
                'unit_price'         => max(0, $price),
                // Durability/warranty only make sense for a part.
                'installed_on'       => $isPart ? ($this->clean($row['installed_on'] ?? null) ?: $ticket->actual_in_date?->toDateString() ?: null) : null,
                'installed_odometer' => $isPart ? ((isset($row['installed_odometer']) && is_numeric($row['installed_odometer'])) ? (int) $row['installed_odometer'] : $fallbackOdo) : null,
                'warranty_months'    => $isPart && isset($row['warranty_months']) && is_numeric($row['warranty_months']) ? (int) $row['warranty_months'] : null,
                'created_by'         => $actor->id,
                // Provenance: the capture method. Defaults to 'manual'; an OCR/import pipeline sends the
                // same line shape with entry_source='ocr'; an accepted garage-portal submission tags 'garage'.
                'entry_source'       => in_array(($row['entry_source'] ?? null), ['manual', 'ocr', 'import', 'garage'], true)
                                            ? $row['entry_source'] : 'manual',
            ]);
        }

        // Re-read the freshly written set and roll the totals onto the ticket row (no save here).
        $ticket->load('lineItems');
        $ticket->recalcLineItemTotals();
    }

    /**
     * Chronic Fault Watchdog — for a set of fault tags, look up the vehicle's prior CLOSED repairs of
     * the SAME fault, so the inspector is warned about a recurring issue the moment they pick the tag.
     * For each tag we return how many times it was repaired before plus the most-recent occurrence's
     * date, downtime and garage (and the hours that fault took, when it was attributed). Tags with no
     * history are dropped, so the caller only ever shows real recurrences.
     *
     * @param  array<int,string> $tags
     * @return array<int,array{tag:string, occurrences:int, last:?array, history:array}>
     */
    public function faultHistory(Vehicle $vehicle, array $tags, ?int $excludeTicketId = null): array
    {
        $tags = collect($tags)
            ->map(fn ($t) => $this->clean($t))
            ->filter()
            ->unique(fn ($t) => mb_strtolower($t))
            ->values();
        if ($tags->isEmpty()) {
            return [];
        }

        // Only CLOSED tickets count as "repaired before" — newest first so the first match is the last repair.
        $closed = Maintenance::where('vehicle_id', $vehicle->id)
            ->where('workflow_status', Maintenance::WF_CLOSED)
            ->when($excludeTicketId, fn ($q) => $q->where('id', '!=', $excludeTicketId))
            ->with('vendor:id,name')
            ->orderByDesc('wf_closed_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return $tags->map(function (string $tag) use ($closed) {
            $low = mb_strtolower($tag);
            $occurrences = [];

            foreach ($closed as $tk) {
                $match = collect($tk->findings ?? [])
                    ->first(fn ($f) => mb_strtolower(trim((string) ($f['text'] ?? ''))) === $low);
                if (! $match) {
                    continue;
                }

                $downtime = ($tk->test_started_at && $tk->returned_at)
                    ? max(0, $tk->returned_at->getTimestamp() - $tk->test_started_at->getTimestamp())
                    : null;

                $occurrences[] = [
                    'ticket_id'        => $tk->id,
                    'date'             => optional($tk->wf_closed_at ?: $tk->actual_in_date ?: $tk->returned_at)->toIso8601String(),
                    'garage'           => $tk->vendor?->name ?: $tk->garage,
                    'downtime_seconds' => $downtime,
                    'repair_hours'     => isset($match['repair_hours']) && is_numeric($match['repair_hours'])
                        ? (float) $match['repair_hours'] : null,
                    'source'           => $match['source'] ?? null,
                ];
            }

            return [
                'tag'         => $tag,
                'occurrences' => count($occurrences),
                'last'        => $occurrences[0] ?? null,   // $closed is newest-first → first hit is the latest repair
                'history'     => $occurrences,
            ];
        })->filter(fn ($i) => $i['occurrences'] > 0)->values()->all();
    }

    /**
     * The closing re-inspection FAILED — one or more faults are still not fixed (the Quality-Control
     * gate). The car does NOT bounce straight back to the same garage: it returns to the SUPERVISOR's
     * dispatch queue (workflow_status → reinspection_failed) so they read the failure log ("Garage X
     * returned the car but fault A is still broken") and decide whether to send it back to the same
     * garage or move it to a different one. The per-fault blame + counters are stamped by the caller
     * (MaintenanceTaskService::failReinspection) BEFORE this runs; here we flip the ticket, keep the
     * car "In Maintenance" (still not roadworthy), clear the stale pickup delegation, and alert the
     * supervisors. `$failed` is the summary of still-broken faults (id / symptom / garage) for the copy.
     *
     * @param array<int,array{id:int,symptom:?string,garage:?string}> $failed
     */
    public function markReinspectionFailed(Maintenance $ticket, ?string $reason, array $failed, User $actor, ?int $redispatchVendorId = null): Maintenance
    {
        $this->assertTransition($ticket, Maintenance::WF_REINSPECTION_FAILED);

        return DB::transaction(function () use ($ticket, $reason, $failed, $actor, $redispatchVendorId) {
            $reason = $this->clean($reason);
            if ($reason) {
                $ticket->garage_feedback = trim(($ticket->garage_feedback
                    ? $ticket->garage_feedback . "\n" : '') . 'Re-inspection failed: ' . $reason);
            }

            // Still not roadworthy → stays 'OUT' / "In Maintenance". Clear the stale pickup delegation
            // so the Supervisor's re-dispatch starts clean (they assign a garage + driver afresh).
            $ticket->event_status       = 'OUT';
            $ticket->actual_in_date     = null;
            $ticket->assigned_driver_id = null;
            $ticket->delegation_task    = null;
            $ticket->delegation_status  = null;
            $ticket->workflow_status    = Maintenance::WF_REINSPECTION_FAILED;
            $ticket->save();

            $this->cascade($ticket->vehicle_id);

            $garage      = $ticket->vendor?->name ?: ($ticket->garage ?: 'the garage');
            $failedNames = array_values(array_filter(array_map(fn ($f) => $f['symptom'] ?? null, $failed)));
            $failedList  = implode(', ', $failedNames);
            $count       = count($failed);

            $this->log->record($ticket, VehicleLogEvent::EVENT_REOPENED, $actor, [
                'description' => 'Re-inspection FAILED — ' . ($count ?: 'the') . ' fault' . ($count === 1 ? '' : 's')
                    . ' still not fixed at ' . $garage . ($failedList ? ' (' . $failedList . ')' : '')
                    . ' → returned to the supervisor for re-dispatch'
                    . ($reason ? ': ' . $reason : '') . ' (by ' . $actor->name . ')',
                'meta'        => ['reason' => $reason, 'garage' => $garage, 'failed' => $failed],
            ]);

            // Alert the SUPERVISORS (dispatchers) — this is their call now: re-dispatch to the same
            // garage or a different one. Carries the garage blame so they can flag/blacklist a repeat offender.
            $vehicle = $ticket->loadMissing('vehicle')->vehicle;
            $this->notifier->notifyByPermission(self::NOTIFY_DISPATCHER, [
                'type'     => 'maint_reinspection_failed',
                'category' => 'maintenance',
                'severity' => 'critical',
                'title'    => '⛔ Re-inspection failed · ' . $this->label($vehicle),
                'body'     => trim($garage . ' returned ' . $this->label($vehicle) . ' but '
                    . ($count ? $count . ' fault' . ($count === 1 ? '' : 's') . ' still not fixed'
                        . ($failedList ? ' (' . $failedList . ')' : '') : 'the fault is still not fixed')
                    . ' — re-dispatch it: back to ' . $garage . ' or to another garage.'),
                'url'      => $this->link($ticket),
                'key'      => 'maint_wf:' . $ticket->id . ':reinspection_failed:' . Carbon::now()->timestamp,
                'icon'     => 'wrench',
                'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no, 'garage' => $garage, 'failed_count' => $count],
            ], $actor->id);

            // Inspector re-routed to a different garage → pre-select it for the supervisor's re-dispatch.
            // Applied AFTER the blame log + notification above (which name the garage it came back broken
            // from), so only the re-dispatch target moves — the failure blame is untouched. The `garage`
            // string is left as-is (the blame name), so the supervisor still sees where it came back from.
            if ($redispatchVendorId && (int) $ticket->vendor_id !== (int) $redispatchVendorId) {
                $ticket->vendor_id = $redispatchVendorId;
                $ticket->save();
            }

            return $ticket->load($this->eager());
        });
    }

    // ── Follow-up log — the Driver's running notes while the car is out ──────────

    /**
     * Record a Driver follow-up while the car is out (in transit / under repair) — the design's
     * "every follow-up log entry made by the Driver is recorded". Each note is APPENDED to the
     * ticket's `follow_ups` log (never overwritten) and stamped with who wrote it and when, so the
     * back-and-forth that used to live on WhatsApp is now an attributable trail on the ticket.
     * No state change; the Controllers are alerted so progress stays visible.
     */
    public function addFollowUp(Maintenance $ticket, ?string $note, User $actor): Maintenance
    {
        $text = $this->clean($note);
        if ($text === null) {
            throw new WorkflowTransitionException('Write the follow-up note before logging it.', ['field' => 'note']);
        }

        // A follow-up makes sense from the moment the car is out for pickup, through the repair and review.
        if (! in_array($ticket->workflow_status, [Maintenance::WF_AWAITING_DISPATCH, Maintenance::WF_IN_TRANSIT, Maintenance::WF_UNDER_REPAIR, Maintenance::WF_REPAIR_REVIEW], true)) {
            throw new WorkflowTransitionException('Follow-ups can only be logged while the car is awaiting pickup, in transit, under repair or under review.', [
                'workflow_status' => $ticket->workflow_status,
            ]);
        }

        return DB::transaction(function () use ($ticket, $text, $actor) {
            $entry = [
                'text'  => $text,
                'by'    => $actor->name,
                'by_id' => $actor->id,
                'at'    => Carbon::now()->toIso8601String(),
            ];
            $ticket->follow_ups = collect($ticket->follow_ups ?? [])->push($entry)->values()->all();
            $ticket->save();

            $vehicle = $ticket->loadMissing('vehicle')->vehicle;
            $this->notifier->notifyByPermission(self::NOTIFY_CONTROLLERS, [
                'type'     => 'maint_follow_up',
                'category' => 'maintenance',
                'severity' => 'info',
                'title'    => 'Follow-up · ' . $this->label($vehicle),
                'body'     => trim($actor->name . ': ' . $text),
                'url'      => $this->link($ticket),
                // Per-entry key (timestamped) so each follow-up notifies once and never dedups away.
                'key'      => 'maint_wf:' . $ticket->id . ':follow_up:' . Carbon::now()->timestamp,
                'icon'     => 'wrench',
                'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no, 'by' => $actor->name],
            ], $actor->id);

            return $ticket->load($this->eager());
        });
    }

    // ── Metadata update — classification may change as the repair reveals more ───

    /**
     * Correct the maintenance type mid-lifecycle. The inspector may open a ticket as "Routine" and
     * discover during repair that it is actually an "Insurance Incident". This method updates the
     * classification and records a VehicleLogEvent so the vehicle timeline clearly shows the change
     * (e.g. "Maintenance type updated: Routine → Insurance Incident") and managers can see when and
     * by whom the reclassification happened. Allowed on any non-terminal ticket.
     */
    public function updateMaintenanceType(Maintenance $ticket, string $newType, User $actor): Maintenance
    {
        if (! array_key_exists($newType, Maintenance::MAINTENANCE_TYPES)) {
            throw new WorkflowTransitionException('Invalid maintenance type.', ['field' => 'maintenance_type']);
        }

        if (in_array($ticket->workflow_status, Maintenance::WF_TERMINAL, true)) {
            throw new WorkflowTransitionException(
                'Cannot reclassify a closed or cleared ticket.',
                ['workflow_status' => $ticket->workflow_status],
            );
        }

        $oldType    = $ticket->maintenance_type;
        $oldLabel   = Maintenance::MAINTENANCE_TYPES[$oldType] ?? $oldType ?? 'Unclassified';
        $newLabel   = Maintenance::MAINTENANCE_TYPES[$newType];

        return DB::transaction(function () use ($ticket, $newType, $oldLabel, $newLabel, $actor) {
            $ticket->maintenance_type = $newType;
            $ticket->save();

            // Reclassifying TO Breakdown grounds the car + forces QA, exactly as a directly-reported one
            // (a manager confirming the car is undriveable must pull it from the pool). Reclassifying
            // AWAY from breakdown intentionally does NOT auto-lift the grounding — a human re-grades the
            // condition once the car is verified safe, so the safety barrier is never dropped silently.
            if ($newType === Maintenance::TYPE_BREAKDOWN) {
                $vehicle = $ticket->loadMissing('vehicle')->vehicle;
                if ($vehicle) {
                    $this->applyBreakdownConsequences($ticket, $vehicle, $actor, null);
                    $this->cascade($ticket->vehicle_id);
                }
            }

            $this->log->record($ticket, VehicleLogEvent::EVENT_TYPE_CHANGED, $actor, [
                'description' => 'Maintenance type updated: ' . $oldLabel . ' → ' . $newLabel . ' (by ' . $actor->name . ')',
                'meta'        => [
                    'old_type'  => $oldLabel,  // captured before save; getOriginal() returns new value after save
                    'new_type'  => $newType,
                    'old_label' => $oldLabel,
                    'new_label' => $newLabel,
                    'by'        => $actor->name,
                ],
            ]);

            // Notify Controllers so the reclassification is visible without anyone having to check.
            $vehicle = $ticket->loadMissing('vehicle')->vehicle;
            $this->notifier->notifyByPermission(self::NOTIFY_CONTROLLERS, [
                'type'     => 'maint_type_changed',
                'category' => 'maintenance',
                'severity' => 'info',
                'title'    => 'Type reclassified · ' . $this->label($vehicle),
                'body'     => trim($this->label($vehicle) . ' — ticket reclassified from ' . $oldLabel . ' to ' . $newLabel . ' by ' . $actor->name . '.'),
                'url'      => $this->link($ticket),
                'key'      => 'maint_wf:' . $ticket->id . ':type_changed:' . Carbon::now()->timestamp,
                'icon'     => 'wrench',
                'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no, 'old_type' => $oldLabel, 'new_type' => $newLabel],
            ], $actor->id);

            return $ticket->load($this->eager());
        });
    }

    // ── Guard & helpers ─────────────────────────────────────────────────────────

    /**
     * The Inspector's test-drive odometer is mandatory before the drive starts — the first link in
     * the maintenance mileage chain (test → dispatch → receive → return). A placeholder (0 / blank)
     * is rejected so the chain can never start on a missing reading.
     *
     * @param array{test_odometer?:mixed} $data
     */
    private function requireTestOdometer(array $data): int
    {
        $odometer = (int) ($data['test_odometer'] ?? 0);
        if ($odometer <= 0) {
            throw new WorkflowTransitionException('Capture the odometer reading before starting the test drive.', [
                'field' => 'test_odometer',
            ]);
        }

        return $odometer;
    }

    /**
     * Adopt a test-drive reading as the car's canonical current mileage, healing the live odometer
     * FORWARD only — odometers never run backwards, so a lower reading never overwrites a higher one
     * (mirrors the Global Mileage Baseline correction policy, see [[global-mileage-baseline]]).
     */
    private function applyTestOdometer(Vehicle $vehicle, int $odometer): void
    {
        if ($vehicle->odometer === null || (int) $vehicle->odometer < $odometer) {
            $vehicle->odometer = $odometer;
            $vehicle->save();
        }
    }

    /**
     * Only an ACTIVE-fleet car (Vehicle::ACTIVE_STATUSES — ready/rented) may enter the workflow.
     * A sold / out-of-service car would create a ticket the board's active-fleet filter immediately
     * hides (the "invisible ticket" defect), so we reject it at the source and name the car's status.
     */
    private function assertActiveFleet(Vehicle $vehicle): void
    {
        if (in_array($vehicle->status, Vehicle::ACTIVE_STATUSES, true)) {
            return;
        }

        $label = ucwords(str_replace(['_', '-'], ' ', trim((string) $vehicle->status)) ?: 'unknown');
        throw new WorkflowTransitionException(
            "Cannot request inspection for this vehicle: Status is {$label}. Please contact management if this is an error.",
            ['field' => 'vehicle_id', 'vehicle_status' => $vehicle->status],
        );
    }

    /** Reject any move the current state does not permit. */
    private function assertTransition(Maintenance $ticket, string $to): void
    {
        $from = $ticket->workflow_status;

        if ($from === null) {
            throw new WorkflowTransitionException('This maintenance row is not a workflow ticket.', [
                'to' => $to,
            ]);
        }

        $allowed = self::TRANSITIONS[$from] ?? [];
        if (! in_array($to, $allowed, true)) {
            throw new WorkflowTransitionException(
                "A maintenance ticket cannot move from '{$from}' to '{$to}'.",
                ['from' => $from, 'to' => $to, 'allowed' => $allowed]
            );
        }
    }

    /** Standard controller alert for the simple mid-lifecycle steps. */
    private function notifyControllers(Maintenance $ticket, string $type, string $severity, string $title, User $actor): void
    {
        $vehicle = $ticket->loadMissing('vehicle')->vehicle;
        $this->notifier->notifyByPermission(self::NOTIFY_CONTROLLERS, [
            'type'     => $type,
            'category' => 'maintenance',
            'severity' => $severity,
            'title'    => $title . ' · ' . $this->label($vehicle),
            'body'     => trim($this->label($vehicle) . ' — ' . strtolower($title) . ' by ' . $actor->name . '.'),
            'url'      => $this->link($ticket),
            'key'      => 'maint_wf:' . $ticket->id . ':' . $ticket->workflow_status,
            'icon'     => 'wrench',
            'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no],
        ], $actor->id);
    }

    /**
     * Tell the Driver who REQUESTED this inspection what the inspector decided — "Maintenance
     * Required" or "Cleared". Targeted straight at that one user (requested_by); when the inspector
     * self-initiated (no requester) there is nobody waiting on a result, so this is a no-op.
     */
    private function notifyResultToRequester(Maintenance $ticket, ?Vehicle $vehicle, bool $requiresMaintenance, User $actor): void
    {
        if (! $ticket->requested_by) {
            return;
        }
        $requester = $ticket->requester ?: User::find($ticket->requested_by);
        if (! $requester || (int) $requester->id === (int) $actor->id) {
            return; // nobody to tell, or the requester is the one who just filed the report
        }

        $this->notifier->notifyUser($requester, [
            'type'     => 'maint_inspection_result',
            'category' => 'maintenance',
            'severity' => $requiresMaintenance ? 'warning' : 'success',
            'title'    => ($requiresMaintenance ? 'Maintenance required · ' : 'Cleared · ') . $this->label($vehicle),
            'body'     => $requiresMaintenance
                ? trim($this->label($vehicle) . ' needs maintenance — ' . $actor->name . ' opened a ticket. Dispatch it to a garage.')
                : trim($this->label($vehicle) . ' was test-driven by ' . $actor->name . ' — no maintenance needed.'),
            'url'      => $this->link($ticket),
            'key'      => 'maint_wf:' . $ticket->id . ':result:' . ($requiresMaintenance ? 'required' : 'cleared'),
            'icon'     => $requiresMaintenance ? 'wrench' : 'check',
            'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no],
        ]);
    }

    /**
     * The maintenance contract to link a dispatched ticket to: the vehicle's currently-open type-'U'
     * (maintenance) contract, newest out_date first. Returns its id, or null when none is open — the
     * link is best-effort and we never auto-create a contract (OfficeManager owns contract creation).
     */
    private function resolveMaintenanceContractId(Maintenance $ticket): ?int
    {
        // One look-up rule, owned by VehicleLogService, so the visit's link and every log
        // event's link can never disagree about which contract is active.
        return $this->log->activeMaintenanceContractId($ticket->vehicle_id);
    }

    /** Re-derive the car's live operational_status after the ticket changed its garage state. */
    private function cascade(?int $vehicleId): void
    {
        if ($vehicleId && $vehicle = Vehicle::find($vehicleId)) {
            $this->operations->reconcileVehicleOperationalStatus($vehicle);
        }
    }

    /** A short "Make Model (PLATE)" label for alert copy. */
    private function label(?Vehicle $vehicle): string
    {
        if (! $vehicle) {
            return 'Vehicle';
        }
        $name = trim($vehicle->make . ' ' . $vehicle->model) ?: 'Vehicle';
        return $vehicle->plate_no ? $name . ' (' . $vehicle->plate_no . ')' : $name;
    }

    /** Deep link to the (Phase 2) controller board, scoped to this ticket. */
    private function link(Maintenance $ticket): string
    {
        return '/maintenance-workflow/' . $ticket->id;
    }

    /** Human label for a trigger_reason, for the audit-log description line. */
    private function reasonLabel(string $reason): string
    {
        return [
            Maintenance::TRIGGER_PERIODIC   => 'routine maintenance',
            Maintenance::TRIGGER_CUSTOMER   => 'customer complaint',
            Maintenance::TRIGGER_TEST_DRIVE => 'test drive',
            Maintenance::TRIGGER_BREAKDOWN  => 'breakdown',
        ][$reason] ?? $reason;
    }

    /** Trim a string input to null when empty. */
    /**
     * Build a LEGACY-FORMAT maintenance note from a closed ticket's structured workflow data, so the
     * history keeps the team's familiar reading style ("OUT/IN · garage · repair details") while the
     * content is now scraped — 100% accurate, auto-timestamped, photos linked — instead of hand-typed.
     *
     * Sources, in reading order: dispatch/return dates + garage (header), trigger + complaint (why),
     * Abu Maroof's test-drive report (diagnosis), inspector & garage findings, garage feedback (work
     * done), driver follow-ups, odometer out→in (with the linked photos on the ticket), and finally
     * the inspector's own closing remark. Cost is appended only when the financial decoupling is lifted
     * (see INCLUDE_COST_IN_SUMMARY). The result is stored in `maintenance_notes`, which every history
     * surface already renders unchanged.
     */
    private function composeClosingSummary(Maintenance $ticket, User $actor, ?string $closingNote): string
    {
        $ticket->loadMissing('vendor', 'inspector', 'lineItems');
        $lines = [];

        // 1) Header — OUT → IN · garage (the dates/garage also show as their own columns). An on-site
        // (mobile) job has no OUT journey and no garage, so it reads as a single-line service note.
        $in = $ticket->actual_in_date ? $ticket->actual_in_date->format('d M Y') : '—';
        if ($ticket->isOnSite()) {
            $lines[] = "On-site service · {$in}" . ($ticket->vendor?->name ? ' · ' . $ticket->vendor->name : '');
        } else {
            $out    = $ticket->out_date ? $ticket->out_date->format('d M Y') : '—';
            $garage = $ticket->vendor?->name ?: ($ticket->garage ?: 'Garage not recorded');
            $lines[] = "OUT {$out} → IN {$in} · {$garage}";
        }

        // 2) Why the car went in.
        $reasonBits = [];
        if ($ticket->trigger_reason) {
            $reasonBits[] = ucfirst($this->reasonLabel($ticket->trigger_reason));
        }
        if ($type = (Maintenance::MAINTENANCE_TYPES[$ticket->maintenance_type] ?? null)) {
            $reasonBits[] = $type;
        }
        if ($complaint = $this->clean($ticket->customer_complaint)) {
            $reasonBits[] = '“' . $complaint . '”';
        }
        if ($reasonBits) {
            $lines[] = 'Reason: ' . implode(' — ', $reasonBits);
        }

        // 3) Inspector's diagnosis (Abu Maroof's test-drive report — structured or legacy string).
        $report = is_array($ticket->test_drive_report) ? $ticket->test_drive_report : [];
        $diag = [];
        if (! empty($report['symptoms']) && is_array($report['symptoms'])) {
            $syms = array_filter(array_map([$this, 'clean'], $report['symptoms']));
            if ($syms) {
                $diag[] = implode(', ', $syms);
            }
        }
        if ($notes = $this->clean($report['notes'] ?? null)) {
            $diag[] = $notes;
        }
        if (is_string($ticket->test_drive_report) && ($legacy = $this->clean($ticket->test_drive_report))) {
            $diag[] = $legacy;
        }
        if ($diag) {
            $who  = $ticket->inspector?->name ? ' (' . $ticket->inspector->name . ')' : '';
            $line = 'Inspection' . $who . ': ' . implode('. ', $diag) . '.';
            if ($rec = $this->clean($report['recommended_action'] ?? null)) {
                $line .= ' Recommended: ' . $rec . '.';
            }
            $lines[] = $line;
        }

        // 4) Findings, grouped by who diagnosed them (sourceless findings count as the inspector's).
        $findings = collect(is_array($ticket->findings) ? $ticket->findings : []);
        $inspectorItems = $findings->filter(fn ($f) => ($f['source'] ?? Maintenance::FINDING_INSPECTOR) === Maintenance::FINDING_INSPECTOR);
        $garageItems    = $findings->where('source', Maintenance::FINDING_GARAGE);
        foreach (['Inspector findings' => $inspectorItems, 'Garage findings' => $garageItems] as $label => $set) {
            // Each fault carries its own attributed repair time (repair_hours) when one was recorded
            // at the garage-return step, so the legacy note reads e.g. "Brake Pads (4h), Oil Filter (1h)".
            $texts = $set->map(function ($f) {
                $text = $this->clean($f['text'] ?? null);
                if ($text === null) {
                    return null;
                }
                $hours = $f['repair_hours'] ?? null;
                return is_numeric($hours) ? $text . ' (' . $this->formatHours((float) $hours) . ')' : $text;
            })->filter()->unique()->values()->all();
            if ($texts) {
                $lines[] = $label . ': ' . implode(', ', $texts) . '.';
            }
        }

        // 5) Garage feedback — what the workshop actually did.
        if ($feedback = $this->clean($ticket->garage_feedback)) {
            $lines[] = 'Garage notes: ' . $feedback;
        }

        // 6) Driver follow-ups, oldest→newest.
        $follows = array_filter(collect(is_array($ticket->follow_ups) ? $ticket->follow_ups : [])
            ->map(fn ($f) => is_array($f) ? $this->clean($f['text'] ?? $f['note'] ?? null) : $this->clean($f))
            ->all());
        if ($follows) {
            $lines[] = 'Follow-ups: ' . implode(' · ', $follows);
        }

        // 7) Odometer out→in — the readings the dispatch/return PHOTOS are linked to on the ticket.
        if ($ticket->dispatch_odometer !== null || $ticket->return_odometer !== null) {
            $o = $ticket->dispatch_odometer !== null ? number_format($ticket->dispatch_odometer) : '—';
            $r = $ticket->return_odometer !== null ? number_format($ticket->return_odometer) : '—';
            $delta = ($ticket->dispatch_odometer !== null && $ticket->return_odometer !== null)
                ? ' (' . number_format(max(0, $ticket->return_odometer - $ticket->dispatch_odometer)) . ' km)'
                : '';
            $lines[] = "Odometer: {$o} → {$r} km{$delta} · photos linked on ticket #{$ticket->id}";
        }

        // 8) Final cost + Parts/Labor breakdown — only once the financial decoupling is lifted.
        if (self::INCLUDE_COST_IN_SUMMARY && $ticket->cost !== null) {
            if ($ticket->cost_is_itemized) {
                // Itemised: list the parts (with quantities) and the labor, then the split total.
                $parts = $ticket->lineItems->where('kind', MaintenanceLineItem::KIND_PART)
                    ->map(fn ($li) => $li->description
                        . ((float) $li->quantity != 1.0 ? ' ×' . rtrim(rtrim(number_format((float) $li->quantity, 2), '0'), '.') : '')
                        . ' (AED ' . number_format((float) $li->line_total, 2) . ')')
                    ->all();
                if ($parts) {
                    $lines[] = 'Parts: ' . implode(', ', $parts) . '.';
                }
                $labor = $ticket->lineItems->where('kind', MaintenanceLineItem::KIND_LABOR)
                    ->map(fn ($li) => $li->description . ' (AED ' . number_format((float) $li->line_total, 2) . ')')
                    ->all();
                if ($labor) {
                    $lines[] = 'Labor: ' . implode(', ', $labor) . '.';
                }
                $lines[] = 'Cost: AED ' . number_format((float) $ticket->parts_total, 2) . ' parts + AED '
                    . number_format((float) $ticket->labor_total, 2) . ' labor = AED ' . number_format((float) $ticket->cost, 2);
            } else {
                $lines[] = 'Cost: AED ' . number_format((float) $ticket->cost, 2);
            }
        }

        // 9) The inspector's own closing remark, if they typed one on the re-inspection.
        if ($remark = $this->clean($closingNote)) {
            $lines[] = 'Closing note: ' . $remark;
        }

        // 10) Provenance — this note was generated, not hand-typed.
        $lines[] = '— Auto-generated on close · ' . Carbon::now()->format('d M Y') . ' · ' . $actor->name;

        return implode("\n", $lines);
    }

    private function clean(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    /**
     * Granular time-per-fault: stamp the attributed `repair_hours` onto each matching finding, matched
     * by text (case-insensitive). The fault objects are the source of truth, so the time lives right
     * next to the tag it belongs to — never replacing the finding, only enriching it.
     *
     * @param  array $findings  the ticket's existing findings JSON
     * @param  array $times     [{text, hours}, ...] from the garage-return step
     */
    private function applyRepairTimes(array $findings, array $times): array
    {
        $map = [];
        foreach ($times as $row) {
            if (! is_array($row)) {
                continue;
            }
            $text  = $this->clean($row['text'] ?? null);
            $hours = $row['hours'] ?? null;
            if ($text === null || $hours === '' || $hours === null || ! is_numeric($hours)) {
                continue;
            }
            $map[mb_strtolower($text)] = round((float) $hours, 2);
        }
        if (! $map) {
            return $findings;
        }

        return collect($findings)->map(function ($f) use ($map) {
            $key = mb_strtolower(trim((string) ($f['text'] ?? '')));
            if (array_key_exists($key, $map)) {
                $f['repair_hours'] = $map[$key];
            }
            return $f;
        })->values()->all();
    }

    /** A compact "4h" / "1.5h" repair-time label (trims a trailing ".0"). */
    private function formatHours(float $hours): string
    {
        return rtrim(rtrim(number_format($hours, 1, '.', ''), '0'), '.') . 'h';
    }

    /** Relations every transition returns hydrated for the API. */
    private function eager(): array
    {
        return ['vendor', 'reason', 'vehicle:id,plate_no,make,model', 'inspector:id,name', 'requester:id,name', 'linkedContract:id,contract_no', 'assignedDriver:id,name', 'delegatedBy:id,name', 'watchers:id,name', 'lineItems'];
    }
}
