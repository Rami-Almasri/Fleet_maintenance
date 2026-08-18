<?php

namespace App\Services;

use App\Exceptions\WorkflowTransitionException;
use App\Models\Contract;
use App\Models\FaultCause;
use App\Models\InspectorPadFlag;
use App\Models\Maintenance;
use App\Models\MaintenanceHandover;
use App\Models\MaintenanceHandoverComparison;
use App\Models\MaintenanceIncident;
use App\Models\MaintenanceLineItem;
use App\Models\MaintenanceSwap;
use App\Models\MaintenanceTemporaryRelease;
use App\Models\OdometerBlockEvent;
use App\Models\ReviewReminder;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\GarageRecommendationDecision;
use App\Models\VehicleLogEvent;
use App\Models\Vendor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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
        // Stage -1 → Stage 0: the Controller (Lin/Marwa) reviews a Driver/system-generated request —
        // approve sends it on to the Inspector exactly as before; reject terminates it.
        Maintenance::WF_PENDING_REVIEW => [Maintenance::WF_INSPECTION_REQUESTED, Maintenance::WF_REVIEW_REJECTED],
        // Stage 0 → Stage 1: the Inspector picks up a Driver's request and starts the test drive.
        Maintenance::WF_INSPECTION_REQUESTED => [Maintenance::WF_INSPECTION_DIAGNOSTIC],
        // Stage 1 → Stage 2 decision: a diagnostic either becomes a ticket (in-shop → the dispatch
        // queue, OR on-site → the mobile lane) or is cleared. The Repair-Location choice at the Decide
        // step picks which committed branch it enters.
        // A filed report becomes a real ticket IMMEDIATELY: in-shop → inspection_pending (Needs Dispatch),
        // on-site → the mobile lane. There is no approval gate in between — see submitReport().
        Maintenance::WF_INSPECTION_DIAGNOSTIC => [Maintenance::WF_INSPECTION_PENDING, Maintenance::WF_ON_SITE_PENDING, Maintenance::WF_DIAGNOSTIC_CLEARED],
        // RETIRED approval gate. Nothing enters recommendation_pending any more; the entry is kept only so
        // legacy rows still transition out of it (a one-off migration moves them to inspection_pending).
        Maintenance::WF_RECOMMENDATION_PENDING => [Maintenance::WF_INSPECTION_PENDING],
        Maintenance::WF_RECOMMENDATION_DISMISSED => [],
        // On-Site (mobile) lane: "Mark as Serviced" completes the mobile job and routes it to the final
        // QA re-inspection (ready_for_reinspection) — service data is confirmed only on a PASS, exactly
        // like an in-shop repair (see confirmRoutineServices). It never closes directly. Or — if the job
        // turns out to need the workshop after all — it can be escalated into the in-shop dispatch queue.
        Maintenance::WF_ON_SITE_PENDING    => [Maintenance::WF_READY_REINSPECTION, Maintenance::WF_INSPECTION_PENDING],
        // Customer-complaint TRIAGE (Abu Maroof): resolve it on-site (terminal), OR RECOMMEND sending the
        // car in — which no longer routes it directly; it parks in the Triage Routing Approval gate until a
        // Supervisor signs it off. He can no longer move the car to a garage/diagnostic on his own.
        Maintenance::WF_COMPLAINT_TRIAGE => [
            Maintenance::WF_COMPLAINT_RESOLVED,
            Maintenance::WF_TRIAGE_APPROVAL_PENDING,
        ],
        // Triage Routing Approval (Supervisor sign-off of Abu Maroof's routing recommendation): APPROVE
        // executes his chosen destination — the garage-dispatch queue (inspection_pending) OR his own
        // diagnostic queue (inspection_requested); REJECT bounces it back to triage for him to reconsider.
        Maintenance::WF_TRIAGE_APPROVAL_PENDING => [
            Maintenance::WF_INSPECTION_PENDING,
            Maintenance::WF_INSPECTION_REQUESTED,
            Maintenance::WF_COMPLAINT_TRIAGE,
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
        //   • minor repair with NO routine service  → auto-continues straight to closed, freeing the car.
        //   • major repair, OR any ticket that performed a routine service (oil/battery/…) → auto-continues
        //     to ready_for_reinspection so the Inspector performs a final QA pass. The service data
        //     (oil/battery anchors, history, reminders) is confirmed ONLY on that PASS — never before.
        Maintenance::WF_IN_OUR_PARK        => [Maintenance::WF_CLOSED, Maintenance::WF_READY_REINSPECTION, Maintenance::WF_AWAITING_INVOICE],
        // Final QA re-inspection (major repairs + routine services, entered from in_our_park / on_site): sign off (closed /
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

    /**
     * The role that coordinates drivers — auto-watched (and alerted) on any prioritised ticket, and
     * itself assignable to a collection leg (see delegate()). PUBLIC because the picker that offers
     * those people (MaintenanceWorkflowController::assignableDrivers) must ask the same question as the
     * gate that accepts them; two copies of the string is how the two quietly drift apart.
     */
    public const SUPERVISOR_ROLE = 'supervisor';

    /**
     * Who may take a transport leg that is on another person's name (see maySupersedeDriver). The
     * supervisors don't only assign these moves — they drive them too — so the dispatch authority is what
     * unlocks the custody gates, not membership of the driver pool.
     */
    private const SUPERSEDE_DRIVER_PERMISSION = 'maintenance.delegate';

    /**
     * How long a system-withdrawn request keeps showing in the review queue as a notice. Long enough that
     * a Controller who saw the card before a weekend still learns what happened to it; short enough that
     * the queue stays a list of decisions to make, not an archive.
     */
    private const WITHDRAWN_NOTICE_DAYS = 7;

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
        private ReviewReminderService $reviewReminders,
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
    private function recordOdometerFlag(Maintenance $ticket, string $flagKey, int $reading, ?int $previous, string $stage, ?string $note = null, ?User $actor = null, ?bool $confirmed = null): array
    {
        $flag  = $this->continuity->evaluate($reading, $previous, $stage);

        // UNIVERSAL HARD BLOCK — a forward jump beyond MAX_JUMP_KM is a mis-typed dial, not a journey.
        // Enforced here rather than in assertStrictMatch() because that gate only covers the three
        // strict-match stages, and the reading that corrupted three vehicles' mileage (62,769 →
        // 6,276,888 km) came in on garage_out, which deliberately waives the tolerance nag entirely.
        // Every reading passes through this method, so this is the one place the rule cannot be bypassed.
        // Audited before throwing, exactly like the strict-match block: the transition rolls back, so this
        // log is the only record that someone tried to force the value.
        if ($flag['status'] === OdometerContinuityService::STATUS_IMPLAUSIBLE) {
            $delta = (int) $flag['delta'];
            $this->logOdometerBlock($ticket, $actor, $flagKey, $reading, $previous, $delta, OdometerContinuityService::STATUS_IMPLAUSIBLE, $note);
            throw new WorkflowTransitionException(
                number_format($reading) . ' km is ' . number_format($delta) . ' km above the previous reading of '
                . number_format((int) $previous) . ' km. A car cannot travel that far between two readings — '
                . 'this is almost certainly a typo. Re-check the dial.',
                ['field' => $flagKey]
            );
        }

        // The operator's tick of "I've checked — this reading is correct" on the continuity nag —
        // stamped alongside the note so the Mileage oversight board can show it was actively acked,
        // not just silently accepted.
        if ($confirmed !== null) {
            $flag['confirmed'] = $confirmed;
        }
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
        // STATUS_EXACT is the same event wearing a strict-match stage's label: a reading BELOW the previous
        // one. It used to be unreachable here because the gate threw first; at a review-not-block stage it
        // now arrives accepted, and a backward reading is exactly the thing a supervisor must be told about.
        $backward = in_array($flag['status'] ?? null, [
            OdometerContinuityService::STATUS_DISCREPANCY,
            OdometerContinuityService::STATUS_EXACT,
        ], true);
        if ($backward) {
            $this->notifyOdometerDiscrepancy($ticket, $flagKey, $flag, $actor);
        }

        // An at-our-park spot-check that ran further forward than the technical buffer: the reading is
        // ACCEPTED (see OdometerContinuityService — a car really can move between two stages, and blocking
        // it only taught people to re-type the old number), but a supervisor is asked to confirm it after
        // the fact on the odometer approval board.
        // Stage-aware: at "Needs Test Drive" a BACKWARD reading lands here too, because that stage no
        // longer refuses one — the approval board is now the only thing between the dial the inspector
        // read and the mileage we have stored.
        if ($this->continuity->needsSupervisorReview($flag, $stage)) {
            $this->fileStageDeviationForReview($ticket, $flagKey, $flag, $actor);
        }

        return $flag;
    }

    /**
     * File an accepted-but-unexpected stage reading onto the odometer approval queue (/odometer-approvals),
     * the same board that reviews significant manual odometer edits.
     *
     * The row is stamped `source = workflow_stage` so the reviewer knows the reading is ALREADY recorded —
     * approving confirms the movement was real; rejecting says it was a mis-read and puts the car back on
     * its previous reading. Idempotent per ticket+stage+reading, so a retried transition can't queue the
     * same deviation twice. Best-effort: the audit trail must never break the transition itself.
     */
    private function fileStageDeviationForReview(Maintenance $ticket, string $flagKey, array $flag, ?User $actor): void
    {
        try {
            $vehicle = $ticket->loadMissing('vehicle')->vehicle;
            if (! $vehicle) {
                return; // a workshop-log row with no car — nothing a reviewer could act on
            }

            $reading  = (int) $flag['reading'];
            $previous = (int) $flag['previous'];

            \App\Models\OdometerChangeRequest::firstOrCreate(
                [
                    'source'             => \App\Models\OdometerChangeRequest::SOURCE_WORKFLOW_STAGE,
                    // The car is part of the identity, not just a payload: a diagnostic opened straight
                    // into the workflow (open()) files its deviation while the ticket is still unsaved, so
                    // maintenance_id is null and would otherwise let two different cars' readings collide
                    // on the same (stage, km) pair.
                    'vehicle_id'         => $vehicle->id,
                    'maintenance_id'     => $ticket->id,
                    'stage_key'          => $flagKey,
                    'requested_odometer' => $reading,
                ],
                [
                    'previous_odometer' => $previous,
                    'delta'             => $reading - $previous,
                    'note'              => (string) ($flag['note'] ?? 'No note supplied.'),
                    'workflow_stage'    => $vehicle->operational_status,
                    'status'            => \App\Models\OdometerChangeRequest::STATUS_PENDING,
                    'requested_by_id'   => $actor?->id,
                    'requested_by'      => $actor?->name,
                ],
            );
        } catch (\Throwable $e) {
            report($e);
        }
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

        // "Needs Test Drive" reviews instead of blocking: this is the first time anyone physically reads
        // the dial, so whatever it says is accepted and recorded, and the deviation is filed to the
        // odometer approval board by recordOdometerFlag() instead of being refused here. Nothing below
        // this line applies — neither the backward block nor the mandatory-note nudge.
        // (The universal MAX_JUMP_KM typo guard still runs, in recordOdometerFlag.)
        if ($this->continuity->stageReviewsInsteadOfBlocking($stage)) {
            return;
        }

        if ($flag['status'] === OdometerContinuityService::STATUS_EXACT) {
            $delta = (int) $flag['delta'];
            // Audit the rejected attempt BEFORE throwing — the transition rolls back and leaves no trace on
            // the ticket, so this is the only record that someone tried to force an out-of-range value.
            // Only a BACKWARD reading reaches here now: a forward drift is accepted and reviewed after the
            // fact (see the AUTHORIZED branch below), because a car really can move between two stages.
            $this->logOdometerBlock($ticket, $actor, $flagKey, $reading, $previous, $delta, OdometerContinuityService::STATUS_EXACT, $note);
            throw new WorkflowTransitionException(
                number_format($reading) . ' km is ' . abs($delta) . ' km BELOW the previous stage ('
                . number_format($previous) . ' km) — an odometer can\'t run backwards. Re-check the dial.',
                ['field' => $field]
            );
        }

        // A forward drift is only asked to explain itself when it's big enough to reach a supervisor
        // (past the tolerance buffer — needsSupervisorReview). A drift INSIDE the buffer has no reader for
        // the sentence, so demanding one just trains drivers to type "ok" to clear the form; there the
        // confirmation tick and the odometer photo are the record. Mirrors needsNote() in the JS twin.
        if ($flag['status'] === OdometerContinuityService::STATUS_AUTHORIZED
            && $this->continuity->needsSupervisorReview($flag)
            && trim((string) $note) === '') {
            // A missing note is a form-completion nudge, NOT an unauthorised value — don't audit it as a block.
            throw new WorkflowTransitionException(
                'This reading is ' . (int) $flag['delta'] . ' km above the previous stage'
                . ' — the car shouldn\'t have moved at this point, so write what happened.'
                . ' The reading is accepted and sent to the supervisor for review.',
                ['field' => 'odometer_note']
            );
        }
    }

    /**
     * Hard "must be higher" gate — the car was physically driven between the previous checkpoint and this
     * one (pickup → garage arrival, garage → collected, collected → sign-off, garage → garage transfer,
     * test-drive start → end), so THIS reading can only be strictly HIGHER than the previous one. No
     * tolerance, no acknowledgment override — an equal or lower value is a mis-keyed reading, not a
     * legitimate edge case, and must be corrected before the transition can proceed. Mirrors the strict
     * pattern of assertStrictMatch(), but for "must increase" instead of "must match" stages.
     */
    private function assertMustIncrease(Maintenance $ticket, ?User $actor, string $flagKey, int $reading, ?int $previous, ?string $note, string $field, string $previousLabel, ?string $customMessage = null): void
    {
        if ($previous === null) {
            return; // nothing to compare against — this reading anchors the chain
        }
        if ($reading <= $previous) {
            $delta = $reading - $previous;
            // Audit the rejected attempt before throwing — the transition rolls back and leaves no trace
            // on the ticket, so this is the only record that someone tried to force an invalid value.
            $this->logOdometerBlock($ticket, $actor, $flagKey, $reading, $previous, $delta, 'must_increase', $note);
            throw new WorkflowTransitionException(
                // A stage may supply its own wording (e.g. the garage→park return leg spells out WHY the car
                // must have travelled); otherwise fall back to the generic "must be higher" sentence.
                $customMessage ?? (
                    'This reading (' . number_format($reading) . ' km) must be higher than the ' . $previousLabel . ' ('
                        . number_format($previous) . ' km) — the car was driven since then. Re-check the dial.'
                ),
                ['field' => $field]
            );
        }
    }

    /**
     * Hard "can't be lower" gate — a softer sibling of assertMustIncrease() for checkpoints where the car
     * MAY have moved since the previous reading but isn't guaranteed to (a garage road test is optional; a
     * final sign-off can land on the exact same reading as the checkpoint just before it). Equal is fine;
     * only a decrease is impossible and hard-blocked — no tolerance, no acknowledgment override.
     */
    private function assertNoDecrease(Maintenance $ticket, ?User $actor, string $flagKey, int $reading, ?int $previous, ?string $note, string $field, string $previousLabel): void
    {
        if ($previous === null) {
            return;
        }
        if ($reading < $previous) {
            $delta = $reading - $previous;
            $this->logOdometerBlock($ticket, $actor, $flagKey, $reading, $previous, $delta, 'must_increase', $note);
            throw new WorkflowTransitionException(
                'This reading (' . number_format($reading) . ' km) can\'t be lower than the ' . $previousLabel . ' ('
                    . number_format($previous) . ' km) — an odometer can\'t run backwards. Re-check the dial.',
                ['field' => $field]
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
     *   - hard-blocks any reading that would DECREASE the car's mileage (an odometer never runs
     *     backwards, whatever the stage — see assertNoDecrease);
     *   - stamps the Continuity verdict under the `transfer` key;
     *   - heals the car's canonical live mileage FORWARD only.
     * The odometer_flags mutation is left UNSAVED so it rides along with the caller's own transfer write
     * (rollbackForGarageTransfer) — no extra query; the vehicle heal is saved here.
     */
    public function recordGarageTransferOdometer(Maintenance $ticket, int $odometer, ?string $note = null, ?User $actor = null): array
    {
        $vehicle  = $ticket->vehicle;
        $previous = $vehicle && $vehicle->odometer !== null ? (int) $vehicle->odometer : null;

        $this->assertNoDecrease($ticket, $actor, 'transfer', $odometer, $previous, $note, 'odometer', 'car\'s last recorded reading');

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
     *
     * Transport method — 'driver' (default, backward compatible) or 'recovery'. This is ONLY the
     * Supervisor's choice of how the car will move; the actual pickup still runs through the existing
     * dispatch() (driver) or dispatchRecovery() (tow) service methods exactly as before — nothing about
     * markUnderRepair/routeTicketToGarage changes. A named `$driverId` is ignored for a recovery transfer:
     * a tow has no human custodian, mirroring dispatchRecovery()'s own rule.
     */
    public function beginGarageTransfer(Maintenance $ticket, Vendor $dest, ?int $driverId, ?string $reason, User $actor, string $transportMethod = Maintenance::TRANSPORT_DRIVER): Maintenance
    {
        $fromGarage = $ticket->garage ?: $ticket->vendor?->name;
        $isRecovery = $transportMethod === Maintenance::TRANSPORT_RECOVERY;

        $driver = (! $isRecovery && $driverId) ? User::find($driverId) : null;
        if (! $isRecovery && $driverId && ! $driver) {
            throw new WorkflowTransitionException('That driver no longer exists — pick another.', ['field' => 'assigned_to_id']);
        }
        if ($driver && ! $driver->can('maintenance.logistics')) {
            throw new WorkflowTransitionException('That user is not a driver — pick someone who can pick up cars.', ['field' => 'assigned_to_id']);
        }

        return DB::transaction(function () use ($ticket, $dest, $driver, $reason, $actor, $fromGarage, $transportMethod, $isRecovery) {
            // Row-lock + re-read INSIDE the transaction: two concurrent submits (double-click, a retried
            // request) both pass the earlier checks against the pre-lock snapshot, but only one can hold
            // this lock at a time — the second sees the FIRST one's committed write and, if it's an
            // identical transfer (same destination + method, already pending), no-ops instead of re-doing
            // it. This is what makes the whole action idempotent under real concurrency, not just
            // sequential double-clicks.
            $ticket = Maintenance::where('id', $ticket->id)->lockForUpdate()->firstOrFail();
            if ((int) $ticket->transfer_to_vendor_id === (int) $dest->id
                && $ticket->transfer_transport_method === $transportMethod
                && $ticket->workflow_status === Maintenance::WF_AWAITING_DISPATCH) {
                return $ticket;
            }

            // Record ONLY the destination — vendor_id stays the garage the car is physically at.
            $ticket->transfer_to_vendor_id = $dest->id;
            $ticket->transfer_transport_method = $transportMethod;

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
                    . ' (by ' . $actor->name . ')',
                'meta' => ['from_garage' => $fromGarage, 'to_garage' => $dest->name, 'to_vendor_id' => $dest->id, 'reason' => $reason, 'driver_id' => $driver?->id, 'transfer' => true],
            ]);

            // A distinct second timeline entry — not a duplicate of "Transfer requested" above, it answers
            // a different question (HOW, not WHERE): the Supervisor's Recovery-vs-Driver choice for this
            // leg. Logged in the same transaction/action as the request itself (one form submission).
            $this->log->record($ticket, VehicleLogEvent::EVENT_TRANSPORT_ASSIGNED, $actor, [
                'description' => $isRecovery
                    ? 'Recovery Truck assigned — a towing unit will collect the car for ' . $dest->name . '.'
                    : ($driver
                        ? 'Company Driver assigned — ' . $driver->name . ' will collect the car for ' . $dest->name . '.'
                        : 'Company Driver assigned — pickup open to the driver pool for ' . $dest->name . '.'),
                'meta' => ['transport_method' => $transportMethod, 'to_garage' => $dest->name, 'driver_id' => $driver?->id],
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
     * The pre-ticket states in which an inspection is ALREADY IN FLIGHT for a car: it is waiting in the
     * Controllers' review queue, it has been approved and is sitting with the Inspector, or he is
     * test-driving it right now. None of these mark the car as under maintenance, which is exactly why
     * the request form's picker (which hides `under_maintenance` cars) still offers such a car as
     * "Available" — this list is the fact the picker cannot see.
     *
     * Deliberately NOT included: review_rejected (terminal — a rejected request must never block a new,
     * better-argued one) and every committed ticket state (those cars are already hidden from the picker).
     */
    public const WF_INSPECTION_IN_FLIGHT = [
        Maintenance::WF_PENDING_REVIEW,
        Maintenance::WF_INSPECTION_REQUESTED,
        Maintenance::WF_INSPECTION_DIAGNOSTIC,
    ];

    /**
     * The car's LIVE in-flight inspection request, if any (soft-deleted rows excluded by the model scope).
     * ONE source for two consumers that must never disagree: the read the request form calls to show the
     * driver a note BEFORE they submit, and the duplicate guard inside requestInspection() below.
     */
    public function liveInspectionRequest(int $vehicleId): ?Maintenance
    {
        return Maintenance::query() // LIVE rows only — SoftDeletes applies, see [[softdelete-bypassed-by-raw-queries]]
            ->where('vehicle_id', $vehicleId)
            ->whereIn('workflow_status', self::WF_INSPECTION_IN_FLIGHT)
            ->with('requester:id,name')
            ->latest('id')
            ->first();
    }

    /**
     * The same fact shaped for the API: machine-readable CODES + params, never an English sentence — the
     * frontend renders the note from its own i18n catalog (see [[reason-code-contract]]). Null when the
     * car has nothing in flight, which is the normal case.
     */
    public function inspectionRequestState(int $vehicleId): ?array
    {
        $ticket = $this->liveInspectionRequest($vehicleId);
        if (! $ticket) {
            return null;
        }

        return [
            'ticket_id'      => $ticket->id,
            'state'          => $ticket->workflow_status,   // pending_review | inspection_requested | inspection_diagnostic
            'trigger_reason' => $ticket->trigger_reason,
            'request_origin' => $ticket->request_origin,
            // requested_by is null on a system-raised request — the scanner, not a person.
            'is_system'      => $ticket->requested_by === null,
            // WHAT WILL HAPPEN IF THEY SUBMIT ANYWAY — computed here, from the same rules the doors apply,
            // so the form states the outcome before they fill it in instead of refusing them after.
            //
            // can_add       — the request door will ADD this to the open request (one car, one story).
            //                 Always available: more detail is welcome at every stage, including while the
            //                 Inspector is driving the car, because it never moves the request.
            // can_supersede — the garage door will stand the open request DOWN and open a ticket instead.
            //                 Not once the Inspector holds it: that is assigned work, and a ticket opened
            //                 behind his back would compete with him for the same car.
            'can_add'        => true,
            'can_supersede'  => $ticket->workflow_status === Maintenance::WF_PENDING_REVIEW,
            'requested_by'   => $ticket->requester?->name ?? $ticket->driver,
            'requested_at'   => $ticket->requested_at?->toIso8601String(),
            'note'           => $ticket->customer_complaint,
            'url'            => $this->link($ticket),
        ];
    }

    /**
     * THE REQUESTER'S STATEMENT — turn "why is this car going in?" into data, once, for every door.
     *
     * A request used to carry a sentence. A sentence cannot be counted, cannot be matched against the
     * fault this car was in the shop for last month, and cannot tell a named fault from a shrug. So the
     * person answers exactly ONE way (see Maintenance::REPORT_MODES) and we keep what they picked:
     *
     *   fault   — they named fault types. Each row must prove where it came from: a live FaultCatalog row
     *             (the selectable vocabulary — see [[findings-vocabulary-contract]]) or a
     *             `repeat_of_ticket_id` pointing at THIS car's own closed ticket, which is the requester
     *             saying "it's the same thing as last time". Nothing else is accepted: a hand-typed fault
     *             name is not vocabulary, it is a note, and there is a mode for that.
     *   service — they named the PLANNED work it is due, from the live ServiceCatalog: an oil change, a
     *             tyre rotation, the annual A/C service. Nothing is wrong with the car and nothing here is
     *             a claim that something failed, which is why these never touch `reported_faults`.
     *   reason  — a CODE from the door's own list. `other` is the one code that records nothing by itself,
     *             so it (and only it) additionally requires the note that spells it out.
     *   note    — their own words.
     *
     * EXCLUSIVITY IS ENFORCED, not tidied up: sending named work AND a reason code is refused rather than
     * silently resolved, because picking which of the two answers to keep is picking what the car goes in
     * for, and that is not a decision this method is entitled to make.
     *
     * FAULTS AND SERVICES ARE THE EXCEPTION, and it is not a hole in the rule — it is the rule read
     * correctly. Both are the same KIND of answer ("here is the work, by name, from the catalogs"), so
     * naming a noise and an overdue oil change together is one answer about two items, exactly as naming
     * two faults is. A reason code is a different kind of answer — "I can't name the work" — and cannot
     * coexist with having named it. `mode` records the stronger half: a fault claim if any fault was
     * named, `service` when the visit is planned work only. The two lists stay in separate columns
     * regardless, because that is what keeps a due service from ever being counted as a failure.
     *
     * Returns the columns to stamp plus `sentence` — the same statement rendered back into prose for
     * `customer_complaint`, so the inspector's screen, the board card, the audit log and the notification
     * body all keep reading the one field they always read.
     *
     * @param array  $data  raw request payload
     * @param string $door  'inspection' | 'dispatch' — which reason list is legal here
     * @return array{mode:string, faults:?array, services:?array, reason_code:?string, sentence:?string}
     */
    private function requestStatement(array $data, string $door): array
    {
        $rawFaults   = array_values(array_filter((array) ($data['reported_faults'] ?? []), 'is_array'));
        $rawServices = array_values(array_filter((array) ($data['requested_services'] ?? []), 'is_array'));
        $reasonRaw   = $this->clean($data['request_reason_code'] ?? null);
        $noteRaw     = $this->clean($data['customer_complaint'] ?? null);

        // What they actually sent decides the mode; the explicit field is only honoured when it agrees
        // with the payload, so a stale radio button can never mislabel a real answer. Named work (faults
        // and/or services) is ONE answer for this purpose — see the exclusivity note above.
        $namedWork = $rawFaults || $rawServices;
        $given = array_keys(array_filter([
            'work'                          => $namedWork,
            Maintenance::REPORT_MODE_REASON => (bool) $reasonRaw,
        ]));

        if (count($given) > 1) {
            throw new WorkflowTransitionException(
                'Say it one way: name the work, or pick a reason — not both.',
                ['field' => 'request_detail_mode']
            );
        }

        // ── named work: faults, services, or both ─────────────────────────────────────────────────
        if ($namedWork) {
            $faults   = $rawFaults   ? $this->normalizeReportedFaults($rawFaults, (int) ($data['vehicle_id'] ?? 0)) : null;
            $services = $rawServices ? $this->normalizeRequestedServices($rawServices) : null;

            // A fault claim is the stronger statement about a car, so it names the mode when both are
            // present: the visit is not a routine one the moment something is reported wrong.
            $mode = $faults ? Maintenance::REPORT_MODE_FAULT : Maintenance::REPORT_MODE_SERVICE;

            // "Reported: Brake noise (thinks it's worn pads) · Service due: Oil Change — pulls left too".
            // A suspected cause reads as "thinks it's X" and never as a finding, because the sentence lands
            // in customer_complaint — the field the inspector, the board card and the notification all
            // quote. Whose guess it is has to survive the trip into prose.
            $parts = [];
            if ($faults) {
                $parts[] = 'Reported: ' . implode(', ', array_map(
                    fn ($f) => $f['text']
                        . ($f['repeat_of_ticket_id'] ? ' (reported as the same fault as #' . $f['repeat_of_ticket_id'] . ')' : '')
                        . ($f['suspected_cause'] ? ' (thinks it’s ' . $f['suspected_cause'] . ')' : ''),
                    $faults
                ));
            }
            if ($services) {
                $parts[] = 'Service due: ' . implode(', ', array_column($services, 'text'));
            }

            return [
                'mode'        => $mode,
                'faults'      => $faults,
                'services'    => $services,
                'reason_code' => null,
                // A note ALONGSIDE named work is detail about that work, not a second answer, so it rides
                // along rather than competing.
                'sentence'    => trim(implode(' · ', $parts) . ($noteRaw ? ' — ' . $noteRaw : '')),
            ];
        }

        $mode = $reasonRaw ? Maintenance::REPORT_MODE_REASON : Maintenance::REPORT_MODE_NOTE;

        // ── reason ────────────────────────────────────────────────────────────────────────────────
        if ($mode === Maintenance::REPORT_MODE_REASON) {
            $list = $door === 'dispatch'
                ? Maintenance::REQUEST_REASONS_DISPATCH
                : Maintenance::REQUEST_REASONS_INSPECTION;

            if (! array_key_exists($reasonRaw, $list)) {
                throw new WorkflowTransitionException('Pick a reason from the list.', ['field' => 'request_reason_code']);
            }
            // 'other' stores a code that means "not one of these" — on its own it records nothing at all.
            if ($reasonRaw === 'other' && $noteRaw === null) {
                throw new WorkflowTransitionException('Say what the reason is.', ['field' => 'customer_complaint']);
            }

            return [
                'mode'        => $mode,
                'faults'      => null,
                'services'    => null,
                'reason_code' => $reasonRaw,
                'sentence'    => $reasonRaw === 'other' ? $noteRaw : $list[$reasonRaw],
            ];
        }

        // ── note ──────────────────────────────────────────────────────────────────────────────────
        if ($noteRaw === null) {
            throw new WorkflowTransitionException(
                'Say why this car needs to go in — name the fault or service, pick a reason, or write it out.',
                ['field' => 'customer_complaint']
            );
        }

        return ['mode' => $mode, 'faults' => null, 'services' => null, 'reason_code' => null, 'sentence' => $noteRaw];
    }

    /**
     * Validate the picked SERVICE rows against the one thing that legitimises one: a live ServiceCatalog
     * row. Returns clean, storable rows; throws when a row cannot name one.
     *
     * There is no history escape hatch here and that asymmetry is deliberate. A fault may be claimed from
     * this car's own repair history ("it's the same thing as last time") because a failure recurring is
     * real information the catalog cannot supply. A service repeating is not a claim about anything — it
     * is the schedule working — so the catalog is the whole vocabulary and free text is a note.
     *
     * Capped at six, same as faults: a visit naming a dozen jobs is a full service, and that is what the
     * `general_service` row is for.
     *
     * @param  array<int,array> $rows
     * @return array<int,array{text:string, slug:string, service_catalog_id:int, category_key:?string}>
     */
    private function normalizeRequestedServices(array $rows): array
    {
        if (count($rows) > 6) {
            throw new WorkflowTransitionException('Name up to six services — pick General Service for a full one.', [
                'field' => 'requested_services',
            ]);
        }

        $catalogById   = \App\Models\ServiceCatalog::active()->get();
        $catalogBySlug = $catalogById->keyBy('slug');
        $catalogById   = $catalogById->keyBy('id');

        $out  = [];
        $seen = [];

        foreach ($rows as $row) {
            $catalog = null;
            if (! empty($row['service_catalog_id'])) {
                $catalog = $catalogById->get((int) $row['service_catalog_id']);
            } elseif (! empty($row['slug'])) {
                $catalog = $catalogBySlug->get((string) $row['slug']);
            }

            if (! $catalog) {
                throw new WorkflowTransitionException(
                    'Pick the service from the list.',
                    ['field' => 'requested_services']
                );
            }

            if (isset($seen[$catalog->id])) {
                continue;   // the same service named twice is still one service
            }
            $seen[$catalog->id] = true;

            $out[] = [
                // The catalog's own name, never the client's — the words are how the row reads and the
                // slug is what it IS, so a renamed catalog row keeps its history readable.
                'text'               => $catalog->name,
                'slug'               => $catalog->slug,
                'service_catalog_id' => $catalog->id,
                'category_key'       => $catalog->category_key,
            ];
        }

        if (! $out) {
            throw new WorkflowTransitionException('Name at least one service.', ['field' => 'requested_services']);
        }

        return $out;
    }

    /**
     * Validate the picked fault rows against the two things that may legitimise one: the live fault
     * vocabulary, or this car's own repair history. Returns clean, storable rows; throws when a row can
     * prove neither, because an unprovable fault name is exactly the free text the note mode exists for.
     *
     * Capped at six. A request naming a dozen faults is not a report, it is a shrug with a long list —
     * and the inspector's whole job is to find out which of them is real.
     *
     * @param  array<int,array> $rows
     * @return array<int,array{text:string, slug:?string, fault_catalog_id:?int, category_key:?string, severity:?string, repeat_of_ticket_id:?int}>
     */
    private function normalizeReportedFaults(array $rows, int $vehicleId): array
    {
        if (count($rows) > 6) {
            throw new WorkflowTransitionException('Name up to six faults — the inspector finds the rest.', [
                'field' => 'reported_faults',
            ]);
        }

        $catalogById   = \App\Models\FaultCatalog::active()->get()->keyBy('id');
        $catalogBySlug = $catalogById->keyBy('slug');

        // The curated Symptom → Root-Cause short-list, approved rows only, keyed exactly as the Diagnosis
        // step keys it. A cause is admissible ONLY against the fault it belongs to: picking "worn ball
        // joint" under "Engine noise" is not a suspicion, it is a mismatch, and the pair would be quietly
        // wrong everywhere afterwards.
        $causesBySymptom = \App\Models\FaultCause::approved()
            ->get(['id', 'symptom_key', 'root_cause'])
            ->groupBy('symptom_key');

        // Tickets this car has actually had — the only ones a "same fault as last time" claim may point at.
        // Scoped to the vehicle so a request can never reference another car's repair.
        $ownTicketIds = $vehicleId
            ? Maintenance::where('vehicle_id', $vehicleId)->pluck('id')->all()
            : [];

        $out  = [];
        $seen = [];

        foreach ($rows as $row) {
            $catalog = null;
            if (! empty($row['fault_catalog_id'])) {
                $catalog = $catalogById->get((int) $row['fault_catalog_id']);
            } elseif (! empty($row['slug'])) {
                $catalog = $catalogBySlug->get((string) $row['slug']);
            }

            $repeatOf = isset($row['repeat_of_ticket_id']) ? (int) $row['repeat_of_ticket_id'] : 0;
            if ($repeatOf && ! in_array($repeatOf, $ownTicketIds, true)) {
                $repeatOf = 0;   // not this car's history — the claim is dropped, the fault itself survives
            }

            $text = $catalog?->name ?: $this->clean($row['text'] ?? null);

            // A row with no catalog row AND no history behind it is untraceable vocabulary — refuse it
            // rather than quietly inventing a fault type nothing else in the system knows.
            if ($text === null || (! $catalog && ! $repeatOf)) {
                throw new WorkflowTransitionException(
                    'Pick the fault from the list, or from what this car was in for before.',
                    ['field' => 'reported_faults']
                );
            }

            $key = mb_strtolower($text);
            if (isset($seen[$key])) {
                continue;   // the same fault named twice is still one fault
            }
            $seen[$key] = true;

            // THE REQUESTER'S SUSPECTED CAUSE — optional, and a CLAIM in exactly the way the fault name
            // above is a claim. Same provenance rule too: it must be an approved row from this fault's own
            // short-list, so it can be counted and matched later; anything else is dropped rather than
            // stored, because a cause nothing in the system knows is worth less than no cause at all.
            //
            // It does NOT diagnose the car and it does NOT skip a stage. The Inspector still decides at the
            // Decide step, where the same short-list is offered to the person who actually has diagnostic
            // authority. This only records what the person who drove it thought it was.
            $cause = null;
            if (! empty($row['root_cause_id'])) {
                $cause = ($causesBySymptom[\App\Models\FaultCause::normalizeKey($text)] ?? collect())
                    ->firstWhere('id', (int) $row['root_cause_id']);
            }

            $out[] = [
                'text'                => $text,
                'slug'                => $catalog?->slug,
                'fault_catalog_id'    => $catalog?->id,
                'category_key'        => $catalog?->category_key ?: $this->clean($row['category_key'] ?? null),
                // A PREFILL hint carried for the inspector's convenience — never the grade. The grade is
                // set at the Decide step by the only person entitled to set it.
                'severity'            => $catalog?->default_severity,
                'repeat_of_ticket_id' => $repeatOf ?: null,
                // Null when they didn't guess, which is the normal case and must stay unremarkable.
                'suspected_cause'     => $cause?->root_cause,
                'suspected_cause_id'  => $cause?->id,
            ];
        }

        if (! $out) {
            throw new WorkflowTransitionException('Name at least one fault.', ['field' => 'reported_faults']);
        }

        return $out;
    }

    /**
     * "Is it this again?" — the faults THIS car has already been in the shop for, newest first, so the
     * person filling in a request is offered their own car's history instead of a blank catalog.
     *
     * Why it earns its place: the single most likely reason a car is going back in is the thing it went
     * in for last time not holding. Making that one tap (and stamping `repeat_of_ticket_id`) turns a
     * guess the workshop has to re-derive into a stated claim it can check.
     *
     * FAULTS ONLY (kind = fault). A planned service repeating is not a recurrence — it is a schedule
     * working — and offering "Oil change" here as something that might need re-fixing would be the same
     * mistake the Chronic Fault Watchdog already corrected (see faultHistory).
     *
     * Every field is a FACT read off the record. `days_since` is Derived (subtraction). Nothing here is a
     * judgement: `within_recurrence_window` states the fault was last fixed inside the configured window
     * — it does NOT claim the fault came back. Only the workshop confirms that (RecurringFaultService).
     *
     * @return array<int,array>
     */
    public function recentFaultsFor(Vehicle $vehicle, int $limit = 8): array
    {
        $windowDays = (int) config('parts_intelligence.recurrence.window_days', 90);

        $tasks = \App\Models\MaintenanceTask::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('kind', \App\Models\MaintenanceTask::KIND_FAULT)
            // A fault the workshop ruled never existed, or that was dropped, is not part of this car's
            // repair history and must not be offered back as "it might be this again".
            ->whereNull('marked_incorrect_at')
            ->whereNotIn('status', \App\Models\MaintenanceTask::NON_REPAIR_TERMINAL)
            ->with(['maintenance:id,vehicle_id,workflow_status,wf_closed_at,garage,vendor_id', 'maintenance.vendor:id,name'])
            ->orderByDesc('identified_at')
            ->orderByDesc('id')
            ->limit(120)
            ->get();

        $now      = Carbon::now();
        $grouped  = [];

        foreach ($tasks as $task) {
            $key = mb_strtolower(trim((string) $task->symptom));
            if ($key === '') {
                continue;
            }

            // First hit wins the headline — the list is newest-first, so that is the latest occurrence.
            if (! isset($grouped[$key])) {
                $at    = $task->resolved_at ?: $task->maintenance?->wf_closed_at ?: $task->identified_at;
                $fixed = $task->status === \App\Models\MaintenanceTask::STATUS_COMPLETED;
                $days  = $at ? (int) $at->diffInDays($now) : null;

                $grouped[$key] = [
                    'text'                     => $task->symptom,
                    'fault_catalog_id'         => $task->fault_catalog_id,
                    'category_key'             => $task->category_key,
                    'ticket_id'                => $task->maintenance_id,
                    // Is that ticket still open? Then this fault is not history, it is current — the
                    // form uses this to say so rather than offering it as something to report again.
                    'still_open'               => $task->maintenance
                        && ! in_array($task->maintenance->workflow_status, Maintenance::WF_TERMINAL, true),
                    'status'                   => $task->status,
                    'fixed'                    => $fixed,
                    'at'                       => $at?->toIso8601String(),
                    'days_since'               => $days,
                    'garage'                   => $task->maintenance?->vendor?->name ?: $task->maintenance?->garage,
                    'occurrences'              => 0,
                    // FACT: it was fixed inside the window. NOT a claim that it has come back.
                    'within_recurrence_window' => $fixed && $days !== null && $days <= $windowDays,
                ];
            }

            $grouped[$key]['occurrences']++;
        }

        return array_slice(array_values($grouped), 0, $limit);
    }

    /**
     * Stage 0. A Driver (Logistics) raises a "Request Inspection" on a car they suspect needs a look —
     * the new entry point the role-based design calls for. It is born in `pending_review`: no
     * ticket, no diagnostic, no contract, the car is NOT marked in maintenance (event_status 'IN').
     * The Inspector (Abu Maroof) is NOT notified yet — the request first sits in the Controllers'
     * (Lin & Marwa) review queue; only their approval sends it on (see approveInspectionReview()).
     * A `requested_by` stamp makes the request attributable and lets the result route straight back
     * to whoever raised it.
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

        // ONE live request per car. The system scanner has always deduped this way (see
        // InspectionsGenerateTasks, which skips a car already in the pipeline); the human path did not,
        // so a car sitting in the review queue could be flagged again and again — each duplicate splitting
        // one car's story across several rows in Lin & Marwa's queue. The form shows this same fact as a
        // note the moment the car is picked; this is the server-side guard behind it.
        // ONE REQUEST PER CAR — but "one" is achieved by ADDING to the open one, not by turning the second
        // person away. This used to be a 422, and the refusal was the wrong shape of answer: somebody who
        // has just driven the car and found something new was told to go and tell the office by some other
        // means. The rule the queue actually needs is that a car has one story, and a second report is the
        // next paragraph of it.
        //
        // (The scanner's SUGGESTION is no exception: its list of checks is information about this car, so
        // the human report is added underneath it rather than replacing it. Same on a rented car as on a
        // yard car — see weighInFlightRequest.)
        [$inFlight] = $this->weighInFlightRequest($vehicleId);
        if ($inFlight) {
            return $this->addToOpenRequest($inFlight, $data, $driver);
        }

        // 'driver_reported' is accepted here but NOT at the HTTP layer (the controller validates against
        // TRIGGER_REASONS) — it belongs to the internal Driver Observation escalation, which passes its
        // own request_origin along with it.
        $reason = $data['trigger_reason'] ?? null;
        if (! in_array($reason, array_merge(Maintenance::TRIGGER_REASONS, [Maintenance::TRIGGER_DRIVER_REPORTED]), true)) {
            throw new WorkflowTransitionException('Choose a reason: test drive, customer complaint, or routine maintenance.', [
                'field' => 'trigger_reason',
            ]);
        }

        // WHERE it came from — a plain driver request unless the caller names a more specific source
        // (the Driver Observation escalation does). Validated so a bad value can never reach the column.
        $origin = $data['request_origin'] ?? Maintenance::SOURCE_DRIVER_REQUEST;
        if (! in_array($origin, Maintenance::REQUEST_ORIGINS, true)) {
            $origin = Maintenance::SOURCE_DRIVER_REQUEST;
        }

        // WHAT they are reporting, kept as data — one of: named faults, a reason code, or their own
        // words. The rendered sentence still lands in customer_complaint, so nothing downstream changes.
        $statement = $this->requestStatement($data + ['vehicle_id' => $vehicleId], 'inspection');

        return DB::transaction(function () use ($vehicleId, $reason, $origin, $statement, $driver) {
            $ticket = new Maintenance();
            $ticket->origin          = Maintenance::ORIGIN_MANUAL;
            $ticket->vehicle_id      = $vehicleId;
            $ticket->workflow_status = Maintenance::WF_PENDING_REVIEW;
            $ticket->trigger_reason  = $reason;
            $ticket->request_origin  = $origin;
            $ticket->visit_context   = $reason === Maintenance::TRIGGER_PERIODIC
                ? Maintenance::CONTEXT_ROUTINE
                : 'standard';
            // The Driver's statement, rendered, rides along as the customer_complaint so the inspector
            // sees it exactly where he always has.
            $ticket->customer_complaint  = $statement['sentence'];
            // …and kept as data beside it. `reported_faults` is what the requester CLAIMS is wrong; it is
            // deliberately NOT promoted into findings/tasks here. A Driver flagging a car has no
            // diagnostic authority — the Inspector decides what this car's faults are at the Decide step,
            // and these rows are the brief he starts from, not his conclusion.
            $ticket->request_detail_mode = $statement['mode'];
            $ticket->reported_faults     = $statement['faults'];
            // A service named on THIS door is a request too, not a decision: nothing is promoted, and the
            // Controller reviewing the queue is the one who says the car goes. Naming it is still worth far
            // more than a note, because the job arrives as catalog vocabulary the workshop can be sent.
            $ticket->requested_services  = $statement['services'];
            $ticket->request_reason_code = $statement['reason_code'];

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
                // The statement goes into the trail as DATA too — codes and fault rows, not only the
                // sentence — so "what do drivers actually report?" is answerable from the audit log.
                'meta'        => [
                    'trigger_reason'      => $reason,
                    'request_origin'      => $origin,
                    'requested_by'        => $driver->name,
                    'request_detail_mode' => $statement['mode'],
                    'request_reason_code' => $statement['reason_code'],
                    'reported_faults'     => $statement['faults'],
                ],
            ]);

            // Hand off to the Controllers (Lin & Marwa) for review — NOT the Inspector yet. Abu Maroof
            // is only notified once a Controller approves (see approveInspectionReview()).
            $vehicle = $ticket->loadMissing('vehicle')->vehicle;
            $note    = $ticket->customer_complaint ? ' — “' . $ticket->customer_complaint . '”' : '';
            $this->notifier->notifyByPermission(self::NOTIFY_CONTROLLERS, [
                'type'     => 'maint_review_pending',
                'category' => 'maintenance',
                'severity' => 'warning',
                'title'    => 'Inspection request awaiting review · ' . $this->label($vehicle),
                'body'     => trim($driver->name . ' asked for a test drive on ' . $this->label($vehicle) . $note),
                'url'      => $this->link($ticket),
                'key'      => 'maint_wf:' . $ticket->id . ':pending_review',
                'icon'     => 'wrench',
                'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no, 'requested_by' => $driver->name],
            ], $driver->id);

            return $ticket->load($this->eager());
        });
    }

    /**
     * Stage 0 (manager entry). A Controller (Lin/Marwa, maintenance.manage) requests an inspection
     * directly — the split that keeps requesting an inspection separate from performing one. Because a
     * Controller IS the review authority, the request SKIPS the pending_review gate and is born already
     * approved in `inspection_requested` — Abu Maroof's queue — and he is notified IMMEDIATELY.
     *
     * It captures NO odometer / photo / diagnostic data: the manager only names the car, the reason and
     * optional notes. Every inspection reading (odometer, photo, OCR, tyres, battery, oil, findings) is
     * collected later, when the Inspector opens the assigned ticket and presses Start Inspection
     * (startDiagnostic → the existing `start` action). The manager is stamped as BOTH the requester and
     * the (auto-)reviewer so the request stays fully attributable.
     *
     * @param array{vehicle_id:int, trigger_reason:string, customer_complaint?:?string, test_kind?:?string} $data
     */
    public function requestInspectionByController(array $data, User $manager): Maintenance
    {
        $vehicleId = (int) ($data['vehicle_id'] ?? 0);
        $vehicle   = $vehicleId ? Vehicle::find($vehicleId) : null;
        if (! $vehicle) {
            throw new WorkflowTransitionException('A valid vehicle is required to request an inspection.', [
                'field' => 'vehicle_id',
            ]);
        }

        // Only an active-fleet car may enter the workflow — otherwise the ticket would be hidden by the
        // board's active-fleet filter the moment it's created.
        $this->assertActiveFleet($vehicle);

        $reason = $data['trigger_reason'] ?? null;
        if (! in_array($reason, Maintenance::TRIGGER_REASONS, true)) {
            throw new WorkflowTransitionException('Choose a reason: test drive, customer complaint, or routine maintenance.', [
                'field' => 'trigger_reason',
            ]);
        }

        // Whatever is already open on this car — the scanner's suggestion included — is JOINED, not
        // replaced, on the same terms as every other request door.
        [$inFlight] = $this->weighInFlightRequest($vehicleId);

        // A live request is added to instead — one car, one story, same as the driver door. This door
        // never had a duplicate guard at all, so before this it silently opened a second live request
        // and left two cards for one car.
        if ($inFlight) {
            return $this->addToOpenRequest($inFlight, $data + ['customer_complaint' => $data['customer_complaint'] ?? null], $manager);
        }

        return DB::transaction(function () use ($vehicle, $vehicleId, $reason, $data, $manager) {
            $now = Carbon::now();

            $ticket = new Maintenance();
            $ticket->origin          = Maintenance::ORIGIN_MANUAL;
            $ticket->vehicle_id      = $vehicleId;
            // A Controller is the review authority, so we land PAST the gate, straight in the Inspector's queue.
            $ticket->workflow_status = Maintenance::WF_INSPECTION_REQUESTED;
            $ticket->trigger_reason  = $reason;
            // Raised by the review authority itself — the source is the Controller, not a driver.
            $ticket->request_origin  = Maintenance::SOURCE_CONTROLLER;
            $ticket->visit_context   = $reason === Maintenance::TRIGGER_PERIODIC
                ? Maintenance::CONTEXT_ROUTINE
                : 'standard';
            // Which intake tab produced this (Routine oil/battery/tyres vs Scheduled park-time) — a
            // traceability tag only; the Inspector re-anchors the mileage chain at Start Inspection.
            if (isset($data['test_kind']) && in_array($data['test_kind'], Maintenance::TEST_KINDS, true)) {
                $ticket->test_kind = $data['test_kind'];
            }
            // The manager's statement, rendered, rides along as the customer_complaint so the Inspector
            // sees it where he always has — plus the structured fact beside it when she gave one.
            //
            // OPTIONAL here, unlike the driver door. A Controller opening the Routine or Scheduled intake
            // tab has already said why by choosing the tab ("this car is due its oil check"); demanding a
            // fault name on top would be asking her to invent a symptom for a car nobody has driven.
            $statement = ($data['reported_faults'] ?? null) || ($data['request_reason_code'] ?? null)
                ? $this->requestStatement($data + ['vehicle_id' => $vehicleId], 'inspection')
                : null;

            $ticket->customer_complaint = $statement
                ? $statement['sentence']
                : $this->clean($data['customer_complaint'] ?? null);
            $ticket->request_detail_mode = $statement['mode'] ?? ($ticket->customer_complaint ? Maintenance::REPORT_MODE_NOTE : null);
            $ticket->reported_faults     = $statement['faults'] ?? null;
            $ticket->request_reason_code = $statement['reason_code'] ?? null;

            // maintenance_type is intentionally NOT set — the manager requesting the inspection has no
            // diagnostic authority. The Inspector classifies the car when filing the report.

            $ticket->event_status   = 'IN';   // a requested inspection is not a garage event
            $ticket->requested_by   = $manager->id;
            $ticket->requested_at   = $now;
            $ticket->driver         = $manager->name;
            // Auto-approved: the Controller is the reviewer, stamped so the request stays attributable.
            $ticket->reviewed_by    = $manager->id;
            $ticket->reviewed_at    = $now;
            $ticket->review_sent_at = $now;
            $ticket->save();

            // The car is booked in for a look → its maintenance visit gets a contract for the whole trip.
            $this->openMaintenanceContract($ticket, $manager);

            $this->cascade($ticket->vehicle_id);

            $this->log->record($ticket, VehicleLogEvent::EVENT_INSPECTION_REQUESTED, $manager, [
                'description' => 'Inspection requested — ' . $this->reasonLabel($reason)
                                . ($ticket->customer_complaint ? ': “' . $ticket->customer_complaint . '”' : '')
                                . ' — assigned to Abu Maroof (by ' . $manager->name . ')',
                'meta'        => ['trigger_reason' => $reason, 'requested_by' => $manager->name, 'test_kind' => $ticket->test_kind],
            ]);

            // Hand off to the Inspector (Abu Maroof) IMMEDIATELY — the same alert the review-approval fires.
            $vehicle = $ticket->loadMissing('vehicle')->vehicle;
            $note    = $ticket->customer_complaint ? ' — “' . $ticket->customer_complaint . '”' : '';
            $this->notifier->notifyByPermission(self::NOTIFY_INSPECTOR, [
                'type'     => 'maint_inspection_requested',
                'category' => 'maintenance',
                'severity' => 'warning',
                'title'    => 'Inspection requested · ' . $this->label($vehicle),
                'body'     => trim($manager->name . ' requested an inspection on ' . $this->label($vehicle) . $note),
                'url'      => $this->link($ticket),
                'key'      => 'maint_wf:' . $ticket->id . ':inspection_requested',
                'icon'     => 'wrench',
                'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no, 'requested_by' => $manager->name],
            ], $manager->id);

            return $ticket->load($this->eager());
        });
    }

    /**
     * THE SECOND DOOR — a car that does not need testing, only fixing.
     *
     * The Request Inspection form asks the workshop a question: "something is wrong, please find out
     * what." Sometimes there is no question. The parts arrived and the car goes in to have them fitted;
     * the garage asked for it back; it is a booked service; the fault is already known and named. Sending
     * those through a test drive costs a day and answers nothing, so the ticket is born straight in the
     * SUPERVISORS' dispatch queue (`inspection_pending` — "Needs Dispatch" on the board), exactly where a
     * breakdown and an Inspector's-Pad pick-up already land. Same stage, same queue, same next step: a
     * supervisor picks the garage.
     *
     * What makes this its own method rather than a flag on requestInspection():
     *   - it skips the review gate AND the diagnostic — two stages, so it is a different journey, not a
     *     shortcut through the same one;
     *   - it is a COMMITMENT, not a suspicion. The car is going to a garage, so cascade() will read it as
     *     under maintenance, and it opens its maintenance contract at birth like every committed ticket;
     *   - the named faults ARE promoted into real routable faults here — unlike an inspection request,
     *     where they stay a claim. There is no inspector coming to convert them, and a supervisor cannot
     *     dispatch a ticket with nothing on it. Which is exactly why this door is gated to people with
     *     diagnostic/dispatch authority (`maintenance.initiate|maintenance.manage`) and not to drivers.
     *
     * NOT a breakdown: the car is driveable and is NOT grounded, NOT forced to 🔴 critical, and NOT
     * classified. Reporting a dead car is still openBreakdown() — a different fact with different
     * consequences (see [[breakdown-intake-feature]]).
     *
     * @param array{vehicle_id:int, reported_faults?:array, request_reason_code?:?string, customer_complaint?:?string} $data
     */
    public function openDirectDispatch(array $data, User $actor): Maintenance
    {
        $vehicleId = (int) ($data['vehicle_id'] ?? 0);
        $vehicle   = $vehicleId ? Vehicle::find($vehicleId) : null;
        if (! $vehicle) {
            throw new WorkflowTransitionException('A valid vehicle is required to send a car to the garage.', [
                'field' => 'vehicle_id',
            ]);
        }

        // Only an active-fleet car may enter the workflow — otherwise the ticket would be hidden by the
        // board's active-fleet filter the moment it's created.
        $this->assertActiveFleet($vehicle);

        // A car already in the pipeline must not be sent in twice: the second ticket would compete with
        // the first for the same physical car and split its story. Two facts make that true, and only
        // two — asking `openWorkflow()` instead (ANY non-terminal ticket) refused cars that are plainly
        // free, and the form had no way to know it: a car back in service with only its invoice
        // outstanding, an on-site job, a paused-and-rented repair, a fenced complaint/recommendation.
        // Each of those reads Available in the picker, so the person saw a green badge and a refusal in
        // the same breath.
        //
        //   1. the car is actually HELD — the same authority the picker hides cars by, so the two can
        //      never disagree (committed ticket, open U contract, manual garage event, returned-pending-
        //      handover). See OperationsService::vehicleInMaintenance().
        if ($this->operations->vehicleInMaintenance($vehicleId)) {
            $held = Maintenance::openWorkflow()->where('vehicle_id', $vehicleId)
                ->whereIn('workflow_status', Maintenance::WF_TICKET_STATES)
                ->orderByDesc('id')->first();
            throw new WorkflowTransitionException(
                'This car is already in the maintenance pipeline — it cannot be sent in twice.',
                array_filter([
                    'field'     => 'vehicle_id',
                    'ticket_id' => $held?->id,
                    'state'     => $held?->workflow_status,
                ], fn ($v) => $v !== null)
            );
        }

        //   2. a live inspection request is in flight — the pre-ticket states the picker CANNOT see, and
        //      the identical guard the inspection door applies. Same fact, same source, one message each.
        //
        //      ONE exception, and it is a difference in KIND, not a relaxation: a SYSTEM SUGGESTION on a
        //      car that is OUT ON HIRE. The scanner raises those from mileage and dates alone — nobody has
        //      been in the car. When the person who has just driven it commits it to a garage, that guess
        //      has been answered by the only authority that could answer it, so the suggestion is retired
        //      and this ticket goes ahead. Refusing instead would leave the human decision nowhere to go
        //      and the car sitting in a review queue nobody can honestly decide.
        [$inFlight, $superseded] = $this->weighInFlightRequest($vehicleId, 'dispatch');
        if ($inFlight) {
            throw new WorkflowTransitionException(
                'The inspector is already on this car — settle that inspection instead of opening a second ticket.',
                ['field' => 'vehicle_id', 'ticket_id' => $inFlight->id, 'state' => $inFlight->workflow_status]
            );
        }

        // Same four ways of saying why, judged against the DISPATCH reason list — the one whose entries
        // are decisions ("parts are in") rather than suspicions ("it felt wrong"). This is also the only
        // door that accepts a named SERVICE: work that is due has nothing to test-drive.
        $statement = $this->requestStatement($data + ['vehicle_id' => $vehicleId], 'dispatch');

        return DB::transaction(function () use ($vehicle, $vehicleId, $statement, $actor, $superseded) {
            $ticket = new Maintenance();
            $ticket->origin          = Maintenance::ORIGIN_MANUAL;
            $ticket->vehicle_id      = $vehicleId;
            // Born in the Supervisors' dispatch queue — no review gate, no test drive.
            $ticket->workflow_status = Maintenance::WF_INSPECTION_PENDING;
            // A booked service is a PLANNED visit and must be tagged as one, or the foresight engine
            // reads a scheduled oil change as the car failing. Everything else here is a real problem.
            // Naming the service outright says the same thing the `scheduled_service` code says, only
            // precisely, so it must tag the visit the same way — otherwise "Oil Change" would be the one
            // spelling of a routine visit that counted against the car. Mode `service` means services and
            // NOTHING ELSE was named: a visit that also carries a reported fault is not a routine one.
            $isPlanned = $statement['reason_code'] === 'scheduled_service'
                || $statement['mode'] === Maintenance::REPORT_MODE_SERVICE;
            $ticket->trigger_reason = $isPlanned ? Maintenance::TRIGGER_PERIODIC : Maintenance::TRIGGER_TEST_DRIVE;
            $ticket->visit_context  = $isPlanned ? Maintenance::CONTEXT_ROUTINE : 'standard';
            // WHERE it came from: whoever holds this door is the workshop side of the house — the same
            // source a breakdown intake carries, and never a driver (the route forbids it).
            $ticket->request_origin = Maintenance::SOURCE_WORKSHOP;

            $ticket->customer_complaint  = $statement['sentence'];
            $ticket->request_detail_mode = $statement['mode'];
            $ticket->reported_faults     = $statement['faults'];
            $ticket->requested_services  = $statement['services'];
            $ticket->request_reason_code = $statement['reason_code'];

            // Parked ('IN'): the car isn't at the garage yet, so it must not read as an open garage event.
            $ticket->event_status = 'IN';
            $ticket->requested_by = $actor->id;
            $ticket->requested_at = Carbon::now();
            $ticket->responsible  = $actor->name;

            // The named faults become the ticket's findings so the supervisor has something to dispatch.
            // Sourced as `inspector` because that is the finding-source contract's word for "found by us,
            // before the garage saw it" (Maintenance::FINDING_SOURCES has exactly two values), and this
            // door is held only by people with that authority. Severity is the catalog's PREFILL hint,
            // never a grade — the grade is still set by the person entitled to set it.
            //
            // Faults and services are APPENDED to one findings list, each carrying its own kind — the
            // ticket may legitimately hold both ("it pulls left and it's due an oil change"), and an
            // assignment here instead of an append would silently drop whichever came first.
            $findings = [];

            if ($statement['faults']) {
                // `kind` + `catalog_id` / `catalog_slug` are the keys EventClassificationService reads to
                // classify a finding authoritatively (classification_source = catalog) rather than by
                // guessing at its wording. A repeat-claim row carries no catalog id, so it falls through
                // to the resolver exactly as a legacy symptom always has.
                $findings = array_map(fn ($f) => [
                    'text'         => $f['text'],
                    'category_key' => $f['category_key'],
                    'kind'         => \App\Models\MaintenanceTask::KIND_FAULT,
                    'catalog_id'   => $f['fault_catalog_id'],
                    'catalog_slug' => $f['slug'],
                    'severity'     => $f['severity'],
                    'source'       => Maintenance::FINDING_INSPECTOR,
                    // The cause they picked rides onto the finding HERE and only here. This door is held
                    // by diagnostic/dispatch authority and there is no inspector coming behind it, so the
                    // pick is a diagnosis and belongs in the field the Diagnosis step writes. On the
                    // inspection door the same pick stays a suspicion on `reported_faults`, because the
                    // Inspector has not looked at the car yet.
                    'root_cause'    => $f['suspected_cause'],
                    'root_cause_id' => $f['suspected_cause_id'],
                    'at'           => Carbon::now()->toIso8601String(),
                ], $statement['faults']);
            }

            // The named services become findings on exactly the same footing, and this is the whole point
            // of asking which service rather than accepting "booked service work": a supervisor now has a
            // job to dispatch and the garage is told what to do in writing.
            //
            // `kind = service` is what keeps them honest downstream. Every one of these rows becomes a
            // maintenance_task classified from the ServiceCatalog (classification_source = catalog), so it
            // is counted in cost, history and profitability and excluded from Top Faults, recurrence and
            // the health score — see docs/Service-vs-Fault-Domain-Separation.md. No severity: planned work
            // is not graded, and a prefill hint here would be inventing one.
            if ($statement['services']) {
                $findings = array_merge($findings, array_map(fn ($s) => [
                    'text'         => $s['text'],
                    'category_key' => $s['category_key'],
                    'kind'         => \App\Models\MaintenanceTask::KIND_SERVICE,
                    'catalog_id'   => $s['service_catalog_id'],
                    'catalog_slug' => $s['slug'],
                    'severity'     => null,
                    'source'       => Maintenance::FINDING_INSPECTOR,
                    'at'           => Carbon::now()->toIso8601String(),
                ], $statement['services']));
            }

            $ticket->findings = $findings ?: null;

            $ticket->save();

            // Promote the findings into routable work — a ticket at Needs Dispatch with nothing on it is a
            // ticket a supervisor cannot act on. No-op when they picked a reason or wrote a note.
            if ($ticket->findings) {
                app(MaintenanceTaskService::class)->syncFromFindings($ticket, $actor);
            }

            // A committed visit gets its contract at birth, like every other committed ticket.
            $this->openMaintenanceContract($ticket, $actor);

            // The system's suggestion has been answered — retire it, so the car does not sit in BOTH the
            // review queue and the maintenance cycle. Deliberately AFTER the ticket exists: if anything
            // above throws, the whole thing rolls back and the suggestion is left standing rather than
            // silently deleted in exchange for nothing. Uses the same systemWithdraw() every other
            // system withdrawal goes through, so the card, the audit trail and the reminder cancellation
            // are identical to the three codes that came before it.
            if ($superseded) {
                $this->supersedeSuggestionByTest($superseded, $ticket, $actor);
            }

            $this->cascade($ticket->vehicle_id);

            $this->log->record($ticket, VehicleLogEvent::EVENT_REPORT_FILED, $actor, [
                'description' => 'Sent straight to the garage — no test drive'
                                . ($ticket->customer_complaint ? ': “' . $ticket->customer_complaint . '”' : '')
                                . ' (by ' . $actor->name . ')',
                'meta'        => [
                    'trigger_reason'      => $ticket->trigger_reason,
                    'request_origin'      => Maintenance::SOURCE_WORKSHOP,
                    'requested_by'        => $actor->name,
                    'request_detail_mode' => $statement['mode'],
                    'request_reason_code' => $statement['reason_code'],
                    'reported_faults'     => $statement['faults'],
                    'requested_services'  => $statement['services'],
                    'source'              => 'direct_dispatch',
                    // Which system suggestion this decision answered, when it answered one — the link that
                    // makes "why did that card vanish?" answerable from either end.
                    'superseded_request_id' => $superseded?->id,
                ],
            ]);

            // Hand off to the Supervisors (Waleed/Abdullah): this car needs a garage. Warning, not
            // critical — unlike a breakdown, nothing here says the car has stopped working.
            $vehicle = $ticket->loadMissing('vehicle')->vehicle;
            $note    = $ticket->customer_complaint ? ' — “' . $ticket->customer_complaint . '”' : '';
            $this->notifier->notifyByPermission(self::NOTIFY_DISPATCHER, [
                'type'     => 'maint_direct_dispatch',
                'category' => 'maintenance',
                'severity' => 'warning',
                'title'    => 'Needs a garage · ' . $this->label($vehicle),
                'body'     => trim($actor->name . ' sent ' . $this->label($vehicle)
                                . ' straight in — no test drive needed' . $note . '. Pick a garage.'),
                'url'      => $this->link($ticket),
                'key'      => 'maint_wf:' . $ticket->id . ':inspection_pending',
                'icon'     => 'wrench',
                'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no, 'direct_dispatch' => true],
            ], $actor->id);

            return $ticket->load($this->eager());
        });
    }

    /**
     * A SECOND REPORT ON A CAR THAT ALREADY HAS AN OPEN REQUEST — added to that request, not refused.
     *
     * The old behaviour was a 422 whose message ("no need to flag it again") answered a question nobody
     * asked. The person submitting is not repeating themselves; they have just driven the car and found
     * something the first report does not mention. Refusing that loses real information to protect a
     * queue count.
     *
     * What "adding" means, precisely:
     *   - NAMED WORK is merged into the open request — faults into `reported_faults` deduped by name,
     *     services into `requested_services` deduped by catalog row, each still capped at six. Named work
     *     is the part of a statement that genuinely accumulates: two people naming two different things is
     *     two things this car needs.
     *   - THE ORIGINAL STATEMENT IS NEVER OVERWRITTEN. The new sentence is appended to
     *     `customer_complaint` attributed to whoever added it, so the card reads as a thread and the first
     *     reporter's words survive intact. `request_reason_code` likewise stays as first answered — the
     *     first answer to "why is it going in" is not improved by being replaced.
     *   - THE STAGE DOES NOT MOVE. A request with the Inspector stays with the Inspector; one awaiting
     *     review keeps awaiting it. Adding detail is not a decision and must never look like one.
     *
     * Returns the OPEN request, so every caller's response is a ticket the client can link to.
     */
    private function addToOpenRequest(Maintenance $open, array $data, User $actor): Maintenance
    {
        // Validated exactly as a fresh request is — same exclusivity, same fault provenance. An addition
        // is held to the vocabulary rules, or it would be the back door around them.
        $statement = $this->requestStatement($data + ['vehicle_id' => $open->vehicle_id], 'inspection');

        return DB::transaction(function () use ($open, $statement, $actor) {
            $ticket = Maintenance::where('id', $open->id)->lockForUpdate()->firstOrFail();

            // Merge faults by name — the same fault named by two people is still one fault, and the cap
            // is the same six a single reporter gets.
            $existing = is_array($ticket->reported_faults) ? $ticket->reported_faults : [];
            if ($statement['faults']) {
                $seen  = [];
                $merged = [];
                foreach (array_merge($existing, $statement['faults']) as $f) {
                    $key = mb_strtolower((string) ($f['text'] ?? ''));
                    if ($key === '' || isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $merged[]   = $f;
                }
                $ticket->reported_faults     = array_slice($merged, 0, 6);
                $ticket->request_detail_mode = Maintenance::REPORT_MODE_FAULT;
            }

            // Named services accumulate the same way and for the same reason — two people can each know a
            // different job is due. Deduped by catalog row, because that is what identity means here.
            if ($statement['services']) {
                $existingServices = is_array($ticket->requested_services) ? $ticket->requested_services : [];
                $seen   = [];
                $merged = [];
                foreach (array_merge($existingServices, $statement['services']) as $s) {
                    $key = (string) ($s['service_catalog_id'] ?? $s['slug'] ?? '');
                    if ($key === '' || isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $merged[]   = $s;
                }
                $ticket->requested_services = array_slice($merged, 0, 6);
                // Only claim the visit as planned work if nothing has ever been reported wrong with it.
                if (! $ticket->reported_faults) {
                    $ticket->request_detail_mode = Maintenance::REPORT_MODE_SERVICE;
                }
            }

            // The thread, not a replacement.
            $ticket->customer_complaint = trim(
                (string) $ticket->customer_complaint
                . ($ticket->customer_complaint ? ' · ' : '')
                . $actor->name . ' added: ' . $statement['sentence']
            );
            $ticket->save();

            $this->log->record($ticket, VehicleLogEvent::EVENT_INSPECTION_REQUESTED, $actor, [
                'description' => $actor->name . ' added to the open request: “' . $statement['sentence'] . '”',
                'meta'        => [
                    'added_to_open_request' => true,
                    'request_detail_mode'   => $statement['mode'],
                    'request_reason_code'   => $statement['reason_code'],
                    'reported_faults'       => $statement['faults'],
                    'requested_services'    => $statement['services'],
                    'workflow_status'       => $ticket->workflow_status,
                ],
            ]);

            // Tell whoever is holding it that it changed under them — a Controller who read this card an
            // hour ago is deciding on wording that no longer says everything it says now.
            $vehicle = $ticket->loadMissing('vehicle')->vehicle;
            $this->notifier->notifyByPermission(
                $ticket->workflow_status === Maintenance::WF_PENDING_REVIEW
                    ? self::NOTIFY_CONTROLLERS
                    : self::NOTIFY_INSPECTOR,
                [
                    'type'     => 'maint_request_updated',
                    'category' => 'maintenance',
                    'severity' => 'info',
                    'title'    => 'More detail added · ' . $this->label($vehicle),
                    'body'     => trim($actor->name . ' added to the open request on ' . $this->label($vehicle)
                                    . ' — “' . $statement['sentence'] . '”'),
                    'url'      => $this->link($ticket),
                    'key'      => 'maint_wf:' . $ticket->id . ':request_updated',
                    'icon'     => 'wrench',
                    'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no, 'added_by' => $actor->name],
                ],
                $actor->id
            );

            return $ticket->load($this->eager());
        });
    }

    /**
     * The car's open RENTAL contract (type 'C', currently out), if any — "this car is with a customer
     * right now". Type 'U' is a maintenance contract and 'R' a booking; neither means the car is on hire.
     */
    public function openRentalFor(int $vehicleId): ?Contract
    {
        return Contract::query()
            ->where('vehicle_id', $vehicleId)
            ->where('contract_type', 'C')
            ->currentlyOpen()
            ->orderByDesc('id')
            ->first(['id', 'contract_no', 'out_date', 'customer_id']);
    }

    /**
     * Split what is in flight on this car into "what a new request joins" and "what a ticket stands down".
     * ONE reading for every door, so the driver's request, the Controller's request and the
     * straight-to-garage commitment can never disagree about the same car.
     *
     * @param  string $door 'inspection' — a request, which ADDS to what is open
     *                      'dispatch'   — a ticket, which SUPERSEDES what is open
     * @return array{0: ?Maintenance, 1: ?Maintenance}  [open, superseded] — at most one is non-null
     */
    private function weighInFlightRequest(int $vehicleId, string $door = 'inspection'): array
    {
        $inFlight = $this->liveInspectionRequest($vehicleId);
        if (! $inFlight) {
            return [null, null];
        }

        // A request the INSPECTOR already holds is never stood down from outside. He is on his way to the
        // car, or driving it; retiring it would erase assigned work, and this is the one state where the
        // honest answer really is "go and settle that one".
        if ($inFlight->workflow_status !== Maintenance::WF_PENDING_REVIEW) {
            return [$inFlight, null];
        }

        // A TICKET outranks a REQUEST. Somebody is committing the car to a workshop, which answers "should
        // someone look at this car?" more completely than any approval of a test drive could — so whatever
        // was waiting for review is retired rather than left as a second live thread. (The requester's own
        // question is not lost: the ticket carries their statement, and the retired row keeps the link.)
        if ($door === 'dispatch') {
            return [null, $inFlight];
        }

        // ON THE REQUEST DOOR NOTHING IS EVER RETIRED. A request is not a commitment — it is somebody
        // saying what they found — so it JOINS whatever is already open, whoever opened it and wherever
        // the car is. That includes the scanner's suggestion: "check brakes, check suspension" computed
        // from mileage is real information about this car, and replacing it with "steering vibration"
        // would throw away half the reason the Inspector is being sent out.
        //
        // This used to retire the scanner's guess when (and only when) the car was out on hire, which
        // made the same button behave differently on a rented car than on a yard car, and lost the
        // system's own list in the process. One request per car, one story on it, both ways.
        return [$inFlight, null];
    }

    /**
     * Retire the system's suggestion because a person drove the car and sent it in. Thin wrapper over
     * systemWithdraw() — the shared mechanics (row lock, review_rejected + system-only code, reminder
     * cancellation, bell cleanup, audit row) are deliberately NOT re-implemented here; this only supplies
     * the sentence and the evidence.
     *
     * Returns false when a Controller decided the request first, which is not a failure: their decision
     * outranks this one and the new ticket stands either way.
     */
    private function supersedeSuggestionByTest(Maintenance $suggestion, Maintenance $ticket, User $actor): bool
    {
        // The retired row may be the scanner's guess OR a person's request that a ticket has now overtaken,
        // and the sentence has to say which — "the system's suggestion was retired" written over somebody's
        // own report would be a false account of what happened to it.
        $wasSystem = $suggestion->requested_by === null;
        $rental    = $this->openRentalFor($ticket->vehicle_id);

        $sentence = $wasSystem
            ? $actor->name . ' drove this car and sent it to a garage, so the system\'s suggested test '
                . 'is no longer the open question — the car is already on its way in.'
            : $actor->name . ' opened a maintenance ticket for this car, so this request is answered by '
                . 'something bigger than an approval — the car is already on its way to a garage.';

        return $this->systemWithdraw(
            $suggestion,
            Maintenance::REVIEW_REJECT_SUPERSEDED_BY_TEST,
            $sentence,
            // The evidence: which ticket answered it, who decided, and whether the car was on hire at the
            // time — the three facts someone auditing the vanished card would ask for.
            [
                'source'        => 'manual_test',
                'ticket_id'     => $ticket->id,
                'decided_by'    => $actor->name,
                'decided_by_id' => $actor->id,
                'on_rental'     => $rental !== null,
                'rental_id'     => $rental?->id,
            ],
            // NOT `ticket_id` — systemWithdraw() writes this meta onto the SUGGESTION's own audit row, where
            // that key already means the suggestion. `superseded_by` names the other ticket unambiguously.
            ['superseded_by' => $ticket->id],
        );
    }

    /**
     * SYSTEM-generated routine inspection TASK (Phase-2 auto-routine). The mileage scanner found a
     * service-due car, so the SYSTEM raises the very same `pending_review` task a Driver would —
     * landing it in the Controllers' (Lin & Marwa) review queue with trigger_reason = periodic. It is
     * attributed to no user (requested_by null, driver = "System · Auto-Check"); the audit-log entry
     * is written exactly as for a Driver request, so the task is fully accountable. The Inspector is
     * NOT notified until a Controller approves it (see approveInspectionReview()).
     *
     * Dedup (don't re-task a car already in the pipeline) is the caller's job (the command checks
     * openWorkflow); here we only assert the car is still active fleet before writing.
     *
     * @param array{note?:?string, suggested_findings?:?array, conditions?:?array, service?:?array} $opts
     */
    public function systemRequestInspection(Vehicle $vehicle, array $opts = []): Maintenance
    {
        $this->assertActiveFleet($vehicle);

        return DB::transaction(function () use ($vehicle, $opts) {
            $ticket = new Maintenance();
            $ticket->origin             = Maintenance::ORIGIN_MANUAL;
            $ticket->vehicle_id         = $vehicle->id;
            $ticket->workflow_status    = Maintenance::WF_PENDING_REVIEW;
            $ticket->trigger_reason     = Maintenance::TRIGGER_PERIODIC;
            $ticket->request_origin     = Maintenance::SOURCE_SYSTEM_SCHEDULE; // the mileage scanner, no human
            $ticket->visit_context      = Maintenance::CONTEXT_ROUTINE; // planned service → foresight ignores it
            $ticket->customer_complaint = $this->clean($opts['note'] ?? 'Routine service due (mileage).');
            // Ready-entry-point chips for the Decide step (see DiagnosticGateService::dueChecks) — the
            // exact Findings-catalog keywords this ticket was raised for, so the Inspector taps to
            // confirm instead of hunting the picker for what the agenda note already told him to check.
            $ticket->suggested_findings = array_values(array_filter($opts['suggested_findings'] ?? []));
            // Trigger Detail — the machine-readable "why" snapshot the Inspection Review Queue renders so a
            // system request explains itself (rule fired, human reason, checklist, mileage/threshold/due
            // values at detection). Snapshot-at-creation: it must not drift if the car is serviced later.
            $ticket->trigger_detail     = $this->buildTriggerDetail($opts);
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

            // Hand off to the Controllers (Lin & Marwa) for review — same alert a Driver request raises,
            // just aimed at the review queue instead of the Inspector.
            $note = $ticket->customer_complaint ? ' — ' . $ticket->customer_complaint : '';
            $this->notifier->notifyByPermission(self::NOTIFY_CONTROLLERS, [
                'type'     => 'maint_review_pending',
                'category' => 'maintenance',
                'severity' => 'warning',
                'title'    => 'Routine inspection awaiting review · ' . $this->label($vehicle),
                'body'     => trim('System flagged ' . $this->label($vehicle) . ' for a routine test drive' . $note),
                'url'      => $this->link($ticket),
                'key'      => 'maint_wf:' . $ticket->id . ':pending_review',
                'icon'     => 'wrench',
                'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle->plate_no, 'requested_by' => 'system'],
            ]);

            return $ticket->load($this->eager());
        });
    }

    /**
     * Normalise the Proactive Diagnostic Monitor's raw conditions + service snapshot into the compact,
     * self-describing `trigger_detail` payload the Inspection Review Queue renders. Each rule keeps its
     * human "why" (`detail`) and its one-tap checklist (`finding_keywords`, unified from the single/plural
     * shapes DiagnosticGateService emits); the service block carries the mileage/threshold/overdue/due
     * values behind the oil rule. Returns null when the caller passed no structured context (e.g. a legacy
     * call) so the column stays empty rather than holding a hollow shell.
     *
     * @param array{note?:?string, conditions?:?array, service?:?array} $opts
     */
    private function buildTriggerDetail(array $opts): ?array
    {
        $conditions = array_values(array_filter($opts['conditions'] ?? [], 'is_array'));
        $service    = is_array($opts['service'] ?? null) ? $opts['service'] : null;

        if (empty($conditions) && $service === null) {
            return null; // nothing structured to record
        }

        $rules = array_map(function (array $c) {
            // Unify the single `finding_keyword` and the post-downtime `finding_keywords[]` into one list.
            $keywords = $c['finding_keywords'] ?? array_filter([$c['finding_keyword'] ?? null]);

            return array_filter([
                'key'              => $c['key'] ?? null,
                'label'            => $c['label'] ?? null,
                'directive'        => $c['directive'] ?? null,       // routine | downtime
                'severity'         => $c['severity'] ?? null,        // critical | moderate | routine
                'axis'             => $c['axis'] ?? null,            // km | date — which threshold it breached
                'why'              => $c['detail'] ?? null,          // the human "why this inspection is required"
                'idle_days'        => $c['days'] ?? null,            // post-downtime only
                'checklist'        => array_values($c['checklist'] ?? []),
                'finding_keywords' => array_values(array_filter($keywords)),
            ], fn ($v) => $v !== null && $v !== []);
        }, $conditions);

        return array_filter([
            'source'       => 'diagnostic_monitor',   // the rule engine that raised it (DiagnosticGateService)
            'generated_at' => Carbon::now()->toIso8601String(),
            'rules'        => array_values($rules),
            // Values behind the service/mileage rule at the moment of detection — current odometer, the
            // service interval (threshold), how far past it the car is, and the calendar next-due date.
            'service'      => $service ? array_filter([
                'current_km'  => $service['current'] ?? null,
                'interval_km' => $service['interval'] ?? null,
                'overdue_km'  => $service['overdue_km'] ?? null,
                'next_due_at' => $service['next_due_at'] ?? null,
                'status'      => $service['status'] ?? null,
            ], fn ($v) => $v !== null) : null,
        ], fn ($v) => $v !== null && $v !== []);
    }

    /**
     * Every request currently sitting in the Controllers' (Lin & Marwa) review queue, newest first.
     *
     * Also surfaces LEGACY system-generated requests that predate the review gate: the Proactive
     * Diagnostic Monitor ran for a while before approveInspectionReview() became the only path to
     * workflow_status = inspection_requested, so some system requests were written straight there
     * (requested_by null, reviewed_by null) and never passed through review. They're still fully
     * actionable on the board today — nothing is broken — but they never got a reviewer's accountable
     * sign-off, so they're included here too (tagged via is_legacy_unreviewed on the resource) with an
     * Acknowledge action instead of Approve/Reject.
     */
    public function pendingReview()
    {
        // Re-count before serving: sweep out requests reality already answered — a car that went into
        // the workshop on an OM maintenance contract OR on a garage-log event (sheet/hand-entered) since
        // the last look, and a system request whose car has since been to a workshop and COME BACK (its
        // count restarted, so it is no longer due). The OM sync runs hourly and the sheet import has no
        // schedule at all, so without this the queue (and its "N awaiting" count) can lie for hours.
        // Throttled: the page polls every 8s and the sweep answers the same way for a minute.
        if (Cache::add('review-queue:withdraw-sweep', 1, 60)) {
            try {
                $this->withdrawRequestsForMaintenanceContracts();
                $this->withdrawRequestsForWorkshopLog();
                $this->withdrawRequestsWhoseConditionCleared();
            } catch (\Throwable $e) {
                report($e); // a failed sweep must never take the queue down with it
            }
        }

        $tickets = Maintenance::where(function ($q) {
            $q->where('workflow_status', Maintenance::WF_PENDING_REVIEW)
                ->orWhere(function ($q2) {
                    $q2->where('workflow_status', Maintenance::WF_INSPECTION_REQUESTED)
                        ->whereNull('requested_by')
                        ->whereNull('reviewed_by');
                })
                // Requests the SYSTEM parked because the car is IN THE SHOP RIGHT NOW — an open OM
                // maintenance contract, or an open workshop-log trip. They need no decision; they are
                // here so the Controller who saw the card yesterday can see where it went, and so the
                // page answers "which of my test requests are waiting on a car that is being worked
                // on". The shop stay is the whole lifetime of the card (scoped below): the car comes
                // out, the card goes, and the system recounts that car from its new service anchor.
                //
                // Deliberately NOT `condition_cleared`: that withdrawal means the car has ALREADY been
                // and come back, so its request is simply finished — the recount is the answer, not a
                // card. Showing those turned a 30-decision queue into 143 rows.
                //
                // …and NOT `superseded_by_test`, for the same reason. That withdrawal means a person has
                // already opened a ticket for this car — THE TICKET IS THE ANSWER, and it is on the board
                // where work belongs. A card here would ask a Controller to look at a car that is already
                // being dealt with, which is the definition of backlog. The withdrawal row itself is
                // untouched and stays on the request for audit; only the QUEUE stops carrying it.
                ->orWhere(function ($q3) {
                    $q3->where('workflow_status', Maintenance::WF_REVIEW_REJECTED)
                        ->whereIn('review_rejection_code', [
                            Maintenance::REVIEW_REJECT_IN_MAINTENANCE_CONTRACT,
                            Maintenance::REVIEW_REJECT_IN_WORKSHOP_LOG,
                        ]);
                });
        })
            // Load the driver's attached evidence (photo/video) too, so the reviewer sees what the driver
            // saw right on the queue card — not just the count. Only this queue needs it inline.
            ->with(array_merge($this->eager(), ['media']))
            ->orderByDesc('requested_at')
            ->get()
            // Decisions first, notices after. A withdrawn request is news, not work, and must never push a
            // request that still needs a Controller below the fold just because it is newer.
            ->sortBy(fn ($t) => $t->workflow_status === Maintenance::WF_REVIEW_REJECTED ? 1 : 0)
            ->values();

        // 🛑 AN "IT IS IN THE SHOP" NOTICE IS ONLY NEWS WHILE THE CAR IS STILL IN THE SHOP.
        // The two shop-presence withdrawals (open OM maintenance contract / open workshop-log trip)
        // answer a request with "the car is being looked at right now". The moment the car comes back
        // out that sentence is no longer true, the request it answered is long dead, and the card is
        // pure backlog — it was pushing the queue from 30 real decisions to 143 rows. So the notice
        // lives exactly as long as the shop stay does: the car leaves, the notice leaves, the count
        // resets. (`condition_cleared` is deliberately NOT scoped here: that notice says the car has
        // ALREADY been and come back, so requiring it to still be in the shop would delete it always.)
        // Each notice is scoped by THE SAME FACT THAT PRODUCED IT — an open OM maintenance contract
        // for the contract sweep, an open workshop-log trip for the log sweep — so the notice and its
        // reason can never disagree (a generic "is it in maintenance?" test does not see a sheet-only
        // trip, and would delete a notice whose fact is still perfectly true).
        $shopNotices = $tickets->filter(fn ($t) => $t->workflow_status === Maintenance::WF_REVIEW_REJECTED
            && $t->vehicle_id
            && in_array($t->review_rejection_code, [
                Maintenance::REVIEW_REJECT_IN_MAINTENANCE_CONTRACT,
                Maintenance::REVIEW_REJECT_IN_WORKSHOP_LOG,
            ], true));

        if ($shopNotices->isNotEmpty()) {
            $ids = $shopNotices->pluck('vehicle_id')->unique()->values()->all();
            $onContract = Contract::where('contract_type', 'U')->currentlyOpen()
                ->whereIn('vehicle_id', $ids)->pluck('vehicle_id')->map(fn ($v) => (int) $v)->flip();
            $onLog = $this->openWorkshopLogEvents($ids);

            $tickets = $tickets->reject(function ($t) use ($shopNotices, $onContract, $onLog) {
                if (! $shopNotices->contains('id', $t->id)) {
                    return false;
                }

                return $t->review_rejection_code === Maintenance::REVIEW_REJECT_IN_MAINTENANCE_CONTRACT
                    ? ! $onContract->has((int) $t->vehicle_id)
                    : ! isset($onLog[(int) $t->vehicle_id]);
            })->values();
        }

        // Attach each car's last REAL inspection/test-drive (before this request) so the reviewer can see
        // when it was last looked at — and what was found — before approving yet another inspection.
        $vehicleIds  = $tickets->pluck('vehicle_id')->filter()->unique()->all();
        $lastTests   = $this->lastInspectionsForVehicles($vehicleIds);
        // Last-ready anchor per vehicle, computed ONCE per car (deduped) via the SAME engine the Post-
        // Downtime safety check uses — so the card's "last maintenance" can never contradict the system's
        // own "N days since last maintenance completion" flag.
        $anchorByVehicle = [];
        foreach ($tickets as $t) {
            if ($t->vehicle_id && $t->vehicle && ! array_key_exists($t->vehicle_id, $anchorByVehicle)) {
                $anchorByVehicle[$t->vehicle_id] = $this->gate->readyAnchor($t->vehicle);
            }
        }
        foreach ($tickets as $t) {
            $prior = $lastTests[$t->vehicle_id] ?? null;
            // Never surface the request's own row as its "last test".
            $t->last_test = ($prior && $prior['id'] !== $t->id) ? $prior : null;
            // The detector's own anchor: {at, days_ago, reason (maintenance|test|onboarding), source, …}.
            $t->last_maintenance = $anchorByVehicle[$t->vehicle_id] ?? null;
            // NOTE: the "last oil service" anchor (km + km-since) is computed straight off the eager-loaded
            // vehicle columns in the resource — no extra query needed here.
            //
            // Per-car Suggested Checks are deliberately NOT attached here either. Computing them for this
            // queue's ~137 distinct vehicles measured at 3.75s — roughly 4× the entire rest of this method
            // — because each car's repeat-fault report is its own set of queries. The panel fetches itself
            // from Vehicle/{id}/suggested-checks as each card scrolls into view, so the queue stays as
            // fast as it was and only the cards someone actually opens cost anything.
        }

        return $tickets;
    }

    /**
     * The most recent real inspection/test-drive for each given vehicle (a ticket that actually had a
     * diagnostic filed — inspected_at set). Keyed by vehicle_id. One batched query, no N+1.
     *
     * @param  int[]  $vehicleIds
     * @return array<int,array{id:int,at:string,ago_days:int,by:?string,severity:?string,summary:?string}>
     */
    private function lastInspectionsForVehicles(array $vehicleIds): array
    {
        $vehicleIds = array_values(array_unique(array_filter($vehicleIds)));
        if ($vehicleIds === []) {
            return [];
        }

        $rows = Maintenance::query()
            ->whereIn('vehicle_id', $vehicleIds)
            ->whereNotNull('inspected_at')
            ->orderByDesc('inspected_at')
            ->with('inspector:id,name')
            ->get(['id', 'vehicle_id', 'inspected_at', 'inspected_by', 'fault_severity', 'test_drive_report']);

        $out = [];
        foreach ($rows as $r) {
            if (isset($out[$r->vehicle_id])) {
                continue; // keep only each car's newest
            }
            $report  = is_array($r->test_drive_report) ? $r->test_drive_report : [];
            $summary = trim((string) ($report['recommended_action'] ?? ''));
            if ($summary === '') {
                $symptoms = array_filter(array_map('trim', (array) ($report['symptoms'] ?? [])));
                $summary  = $symptoms ? implode(', ', $symptoms) : '';
            }
            if (mb_strlen($summary) > 120) {
                $summary = mb_substr($summary, 0, 119) . '…';
            }

            $out[$r->vehicle_id] = [
                'id'       => $r->id,
                'at'       => $r->inspected_at->toIso8601String(),
                'ago_days' => (int) $r->inspected_at->diffInDays(Carbon::now()),
                'by'       => $r->inspector?->name,
                'severity' => $r->fault_severity,
                'summary'  => $summary !== '' ? $summary : null,
            ];
        }

        return $out;
    }

    /**
     * Stage -1 → Stage 0. A Controller (Lin/Marwa) approves an inspection request — the ONLY point
     * that hands it on to the Inspector (Abu Maroof). Re-fetches with a row lock so two reviewers
     * racing to act on the same request cannot both win (and the Inspector cannot be notified twice).
     *
     * @param array{notes?:?string} $data
     */
    public function approveInspectionReview(Maintenance $ticket, array $data, User $reviewer): Maintenance
    {
        return DB::transaction(function () use ($ticket, $data, $reviewer) {
            $locked = Maintenance::where('id', $ticket->id)->lockForUpdate()->firstOrFail();
            if ($locked->workflow_status !== Maintenance::WF_PENDING_REVIEW) {
                throw new WorkflowTransitionException('This request has already been reviewed.', [
                    'workflow_status' => $locked->workflow_status,
                ]);
            }

            $this->assertTransition($locked, Maintenance::WF_INSPECTION_REQUESTED);

            $now = Carbon::now();
            $locked->workflow_status = Maintenance::WF_INSPECTION_REQUESTED;
            $locked->reviewed_by     = $reviewer->id;
            $locked->reviewed_at     = $now;
            $locked->review_notes    = $this->clean($data['notes'] ?? null);
            $locked->review_sent_at  = $now;
            $locked->save();
            $ticket = $locked;

            // Approved → the car is booked in for a look, so its maintenance contract opens here.
            $this->openMaintenanceContract($ticket, $reviewer);

            $this->cascade($ticket->vehicle_id);

            // The request has been decided, so every "remind me to look at this again" anyone set on it is
            // now noise — a Controller must never be pinged at 16:00 to review a car they sent to Abu
            // Maroof at 14:00.
            $this->reviewReminders->cancelOnDecision($ticket);

            $this->log->record($ticket, VehicleLogEvent::EVENT_REVIEW_APPROVED, $reviewer, [
                'description' => 'Inspection request approved — sent to Abu Maroof (by ' . $reviewer->name . ')'
                                . ($ticket->review_notes ? ': “' . $ticket->review_notes . '”' : ''),
                'meta'        => ['reviewed_by' => $reviewer->name],
            ]);

            // Hand off to the Inspector (Abu Maroof) — identical to the alert requestInspection() /
            // systemRequestInspection() used to fire directly, so his experience is unchanged.
            $vehicle = $ticket->loadMissing('vehicle')->vehicle;
            $note    = $ticket->customer_complaint ? ' — “' . $ticket->customer_complaint . '”' : '';
            // The scheduler raised it — read the stored origin, not "nobody is stamped as requester"
            // (an escalated Driver Observation has a requester and is emphatically not a system request).
            $isSystem = $ticket->request_origin
                ? $ticket->request_origin === Maintenance::SOURCE_SYSTEM_SCHEDULE
                : $ticket->requested_by === null;
            // Say WHERE it came from in the alert itself, so the note the inspector reads arrives with
            // its provenance attached instead of as an anonymous quote.
            if ($isSystem) {
                $line = 'System flagged ' . $this->label($vehicle) . ' for a routine test drive';
            } elseif ($ticket->request_origin === Maintenance::SOURCE_DRIVER_OBSERVATION) {
                $line = $ticket->driver . ' logged an observation on ' . $this->label($vehicle) . ' and raised it for inspection';
            } else {
                $line = $ticket->driver . ' asked for a test drive on ' . $this->label($vehicle);
            }
            $this->notifier->notifyByPermission(self::NOTIFY_INSPECTOR, [
                'type'     => 'maint_inspection_requested',
                'category' => 'maintenance',
                'severity' => 'warning',
                'title'    => ($isSystem ? 'Routine inspection due · ' : 'Inspection requested · ') . $this->label($vehicle),
                'body'     => trim($line . $note),
                'url'      => $this->link($ticket),
                'key'      => 'maint_wf:' . $ticket->id . ':inspection_requested',
                'icon'     => 'wrench',
                'meta'     => [
                    'ticket_id'      => $ticket->id,
                    'plate'          => $vehicle?->plate_no,
                    'requested_by'   => $ticket->driver,
                    'request_origin' => $ticket->request_origin,
                ],
            ], $reviewer->id);

            // Let the original requester know their request was approved and sent on.
            if ($ticket->requested_by && $ticket->requested_by !== $reviewer->id) {
                $requester = User::find($ticket->requested_by);
                if ($requester) {
                    $this->notifier->notifyUser($requester, [
                        'type'     => 'maint_review_approved',
                        'category' => 'maintenance',
                        'severity' => 'info',
                        'title'    => 'Your inspection request was approved · ' . $this->label($vehicle),
                        'body'     => 'Sent to Abu Maroof for a test drive.',
                        'url'      => $this->link($ticket),
                        'key'      => 'maint_wf:' . $ticket->id . ':review_approved',
                        'icon'     => 'check',
                    ]);
                }
            }

            return $ticket->load($this->eager());
        });
    }

    /**
     * Stage -1 → terminal. A Controller (Lin/Marwa) rejects an inspection request — it never reaches
     * the Inspector and nothing is sent externally. Same locking discipline as the approve path.
     *
     * TWO PARTS TO A REJECTION, and they answer different questions:
     *   - `rejection_code`  — WHY, from a fixed list (Maintenance::REVIEW_REJECTION_REASONS). This is the
     *     countable part: "we reject 40% of routine requests because the car is out on hire" is a fact you
     *     can only get from a code, never from free text.
     *   - `rejection_reason` — the human detail, in the reviewer's own words. Optional, EXCEPT with the
     *     `other` code, which on its own records nothing.
     * A code with no legacy text is fine; text with no code is accepted too, because every caller written
     * before the code existed sends exactly that.
     *
     * `remind_at` is the "ask me again later" half: rejecting a request because the car is with a customer
     * is not the same as deciding the car is fine, and this is what stops the second half of that thought
     * from being lost. It books a personal reminder that deliberately OUTLIVES the rejection.
     *
     * @param array{rejection_reason?:?string, rejection_code?:?string, remind_at?:?string} $data
     */
    public function rejectInspectionReview(Maintenance $ticket, array $data, User $reviewer): Maintenance
    {
        $reason = trim((string) ($data['rejection_reason'] ?? ''));
        $code   = trim((string) ($data['rejection_code'] ?? '')) ?: null;

        if ($code !== null && ! array_key_exists($code, Maintenance::REVIEW_REJECTION_REASONS)) {
            throw new WorkflowTransitionException('That is not a rejection reason we recognise.', [
                'field' => 'rejection_code',
            ]);
        }

        if ($code === null && $reason === '') {
            throw new WorkflowTransitionException('Say why this inspection request is being rejected.', [
                'field' => 'rejection_code',
            ]);
        }

        // "Other" is the escape hatch, and an escape hatch with nothing written in it records nothing at
        // all — the one code that cannot stand alone.
        if ($code === Maintenance::REVIEW_REJECT_OTHER && $reason === '') {
            throw new WorkflowTransitionException('Choosing “Other reason” needs a short note saying what it was.', [
                'field' => 'rejection_reason',
            ]);
        }

        // The moment the reviewer wants to revisit this, if they asked for one. Parsed before the
        // transaction so a malformed date fails the whole rejection rather than half-applying it.
        $remindAt = $this->parseReminderMoment($data['remind_at'] ?? null);

        return DB::transaction(function () use ($ticket, $reason, $code, $remindAt, $reviewer) {
            $locked = Maintenance::where('id', $ticket->id)->lockForUpdate()->firstOrFail();
            if ($locked->workflow_status !== Maintenance::WF_PENDING_REVIEW) {
                throw new WorkflowTransitionException('This request has already been reviewed.', [
                    'workflow_status' => $locked->workflow_status,
                ]);
            }

            $this->assertTransition($locked, Maintenance::WF_REVIEW_REJECTED);

            $locked->workflow_status         = Maintenance::WF_REVIEW_REJECTED;
            $locked->reviewed_by             = $reviewer->id;
            $locked->reviewed_at             = Carbon::now();
            $locked->review_rejection_reason = $reason !== '' ? $reason : null;
            $locked->review_rejection_code   = $code;
            $locked->save();
            $ticket = $locked;

            $this->cascade($ticket->vehicle_id);

            // Nobody is waiting on this request any more — retire the "look at it again" reminders it
            // collected while it sat in the queue. The revisit reminder booked below is a different kind
            // and is created after this, so it is not swept up by it.
            $this->reviewReminders->cancelOnDecision($ticket);

            // The reason as a human reads it: the chosen reason, then the reviewer's own words if they
            // added any. A legacy caller that sent only text still reads exactly as it did before.
            $codeLabel = Maintenance::reviewRejectionLabel($code);
            $said      = trim(($codeLabel ?: '') . ($reason !== '' ? ($codeLabel ? ' — ' : '') . '“' . $reason . '”' : ''));

            $this->log->record($ticket, VehicleLogEvent::EVENT_REVIEW_REJECTED, $reviewer, [
                'description' => 'Inspection request rejected (by ' . $reviewer->name . '): ' . $said,
                'meta'        => [
                    'reviewed_by'      => $reviewer->name,
                    'rejection_reason' => $reason !== '' ? $reason : null,
                    'rejection_code'   => $code,
                ],
            ]);

            // "Rejected for now — ask me again on the 14th." Personal to the reviewer, and deliberately
            // NOT cancelled by the rejection that created it.
            if ($remindAt) {
                $this->reviewReminders->schedule(
                    $ticket,
                    $reviewer,
                    $remindAt,
                    $said !== '' ? 'You rejected this request: ' . $said : null,
                    ReviewReminder::KIND_REJECTED_REVISIT,
                );
            }

            if ($ticket->requested_by && $ticket->requested_by !== $reviewer->id) {
                $requester = User::find($ticket->requested_by);
                if ($requester) {
                    $vehicle = $ticket->loadMissing('vehicle')->vehicle;
                    $this->notifier->notifyUser($requester, [
                        'type'     => 'maint_review_rejected',
                        'category' => 'maintenance',
                        'severity' => 'warning',
                        'title'    => 'Your inspection request was rejected · ' . $this->label($vehicle),
                        // The chosen reason travels with it — the person who raised the request is the one
                        // who most needs to know WHICH reason, not just that someone said no.
                        'body'     => $said !== '' ? $said : 'No reason was recorded.',
                        'url'      => $this->link($ticket),
                        'key'      => 'maint_wf:' . $ticket->id . ':review_rejected',
                        'icon'     => 'x',
                    ]);
                }
            }

            return $ticket->load($this->eager());
        });
    }

    /**
     * The car went into the workshop while its request was still waiting to be reviewed — withdraw it.
     *
     * THE BUG THIS CLOSES. The Proactive Diagnostic Monitor flags a car for a test drive at 07:30 and the
     * request lands in the Controllers' queue. A day (or a week) later OfficeManager opens a maintenance
     * contract (type U) on that same car: it is now physically in the workshop, and every other screen in
     * the system says "In Maintenance". Only the review queue never heard — so a Controller still sees a
     * live "Needs Test Drive" card and is asked to decide about a car that has already gone. Rejecting it
     * by hand is not a decision anybody should have to make; the fact already decided it.
     *
     * So the system withdraws it, and — because a card that vanishes silently is just a different kind of
     * lie — it records WHICH contract did it (number, opened-at, customer) in `review_auto_context`, and
     * the queue keeps showing the request for a week as a withdrawn card carrying that note.
     *
     * Only `pending_review` requests are touched. Anything a human already approved is a real ticket with
     * an Inspector attached and is none of this method's business, and a car whose ONLY open maintenance
     * contract is the one this very workflow opened at dispatch is not "already in maintenance" — it is
     * this request, later. Both are excluded below.
     *
     * Idempotent: runs after every contract sync, withdraws nothing on the second pass.
     *
     * @return int how many requests were withdrawn
     */
    public function withdrawRequestsForMaintenanceContracts(): int
    {
        $pending = Maintenance::where('workflow_status', Maintenance::WF_PENDING_REVIEW)
            ->whereNotNull('vehicle_id')
            ->with('vehicle')
            ->get();

        if ($pending->isEmpty()) {
            return 0;
        }

        // One query for every open maintenance contract on the affected cars — newest first, so a car with
        // more than one open U contract is explained by the one that actually put it in the shop today.
        $contracts = Contract::where('contract_type', 'U')
            ->currentlyOpen()
            ->whereIn('vehicle_id', $pending->pluck('vehicle_id')->unique()->all())
            ->with('customer:id,name_en')
            ->orderByDesc('out_date')
            ->orderByDesc('id')
            ->get()
            ->groupBy('vehicle_id');

        $withdrawn = 0;
        foreach ($pending as $ticket) {
            $contract = ($contracts[$ticket->vehicle_id] ?? collect())->first(
                // Never withdraw a request because of the contract its own ticket opened downstream.
                fn ($c) => $c->id !== $ticket->linked_contract_id
            );
            if (! $contract) {
                continue;
            }

            if ($this->withdrawOneForMaintenanceContract($ticket, $contract)) {
                $withdrawn++;
            }
        }

        return $withdrawn;
    }

    /**
     * TRANSITIONAL twin of the contract sweep above — the garage LOG (sheet-imported + hand-entered
     * workshop events) as the withdrawing fact.
     *
     * While workshop trips are still being recorded on the N-Maintenance sheet instead of as OM
     * maintenance contracts, a car can be physically at a garage with no type-U contract anywhere — and
     * its inspection request would sit in the Controllers' queue asking about a car that has already
     * gone. So, for now, the log answers the request the same way a contract does, and the "N awaiting"
     * count re-counts. Once every trip opens an OM contract this sweep should stop finding anything on
     * its own (the contract sweep runs first at every call site); it can then be retired.
     *
     * Same rules as the maintenance board: per car the LATEST live workshop-log event wins, and it only
     * counts as "in the shop" if it is still open (stage ≠ 'IN', no past return date) AND recent
     * (out_date within WORKSHOP_LOG_LOOKBACK_DAYS) — ~70% of historical sheet rows are an OUT whose
     * return was never logged, and an ancient unclosed OUT must not withdraw anything.
     *
     * Idempotent, row-locked, and a human decision always outranks it (see systemWithdraw()).
     *
     * @return int how many requests were withdrawn
     */
    public function withdrawRequestsForWorkshopLog(): int
    {
        $pending = Maintenance::where('workflow_status', Maintenance::WF_PENDING_REVIEW)
            ->whereNotNull('vehicle_id')
            ->with('vehicle')
            ->get();

        if ($pending->isEmpty()) {
            return 0;
        }

        $events = $this->openWorkshopLogEvents($pending->pluck('vehicle_id')->unique()->all());

        $withdrawn = 0;
        foreach ($pending as $ticket) {
            $event = $events[$ticket->vehicle_id] ?? null;
            // Never withdraw a request because of its own row — a workflow ticket IS a `maintenances`
            // row with origin 'manual'. A pending request has no out_date and sits at stage 'IN', so it
            // can't qualify above; this guard makes that impossibility explicit rather than assumed.
            if (! $event || $event->id === $ticket->id) {
                continue;
            }

            if ($this->withdrawOneForWorkshopEvent($ticket, $event)) {
                $withdrawn++;
            }
        }

        return $withdrawn;
    }

    /**
     * The rule the two sweeps above are only snapshots of: the car has been to a workshop and COME BACK
     * since the system asked for a test.
     *
     * Both sweeps above ask "is the car away RIGHT NOW?", so they stop protecting the queue the moment the
     * car returns — which is precisely when the request became most obsolete. A stint that opened and
     * closed between two sweeps (or before those sweeps existed) leaves its request sitting in the queue
     * for ever, still quoting a day count the fleet stopped agreeing with weeks ago.
     *
     * The count is the arbiter. DiagnosticGateService::readyAnchor() is the single answer to "when did
     * this car last come back ready", across all three records that can say so — a closed workflow
     * ticket, a legacy garage-log return, or a closed OM maintenance contract — and it always takes the
     * LATEST of them. If that day falls after the request was raised, the clock has already restarted:
     * the condition the system complained about is gone, so the request goes with it.
     *
     * Deliberately limited to `system_schedule` requests. A driver, inspector or customer asked for a
     * reason a clock cannot see, and only a person may answer that. See request_origin — WHERE a request
     * came from is exactly the axis that decides whether it may be retired automatically.
     *
     * Idempotent, row-locked, and a human decision always outranks it (see systemWithdraw()).
     *
     * @return int how many requests were withdrawn
     */
    public function withdrawRequestsWhoseConditionCleared(): int
    {
        $pending = Maintenance::where('workflow_status', Maintenance::WF_PENDING_REVIEW)
            ->where('request_origin', Maintenance::SOURCE_SYSTEM_SCHEDULE)
            ->whereNotNull('vehicle_id')
            ->with('vehicle')
            ->get();

        $withdrawn = 0;
        foreach ($pending as $ticket) {
            if (! $ticket->vehicle || ! $ticket->created_at) {
                continue;
            }

            $anchor = $this->gate->readyAnchor($ticket->vehicle);
            if (! ($anchor['at'] ?? null)) {
                continue;
            }

            // Never let a ticket retire itself: a request that later closed as its own maintenance visit
            // would otherwise be its own evidence. (A pending_review row is not terminal so it cannot be
            // the anchor today — this makes that impossibility explicit rather than assumed.)
            if (($anchor['source_id'] ?? null) === $ticket->id && ($anchor['source'] ?? null) !== 'om_contract') {
                continue;
            }

            // Whole days only: a return recorded on the same date the request was raised is not proof the
            // workshop visit came after it, so it does not count.
            $returnedAt = Carbon::parse($anchor['at'])->startOfDay();
            if (! $returnedAt->greaterThan($ticket->created_at->copy()->startOfDay())) {
                continue;
            }

            if ($this->withdrawOneForClearedCondition($ticket, $anchor, $returnedAt)) {
                $withdrawn++;
            }
        }

        return $withdrawn;
    }

    /** Withdraw ONE pending system request whose clock has restarted — same lock, same rules. */
    private function withdrawOneForClearedCondition(Maintenance $ticket, array $anchor, Carbon $returnedAt): bool
    {
        $sentence = 'The car came back from maintenance on ' . $returnedAt->format('d M Y')
            . ' — the check clock restarted, so this routine test is no longer due.';

        return $this->systemWithdraw(
            $ticket,
            Maintenance::REVIEW_REJECT_CONDITION_CLEARED,
            $sentence,
            // The evidence: which record says the car came back, and when the request it answers was made.
            [
                'source'             => 'clock_restarted',
                'anchor_at'          => $returnedAt->toIso8601String(),
                'anchor_reason'      => $anchor['reason'] ?? null,       // maintenance | test
                'anchor_source'      => $anchor['source'] ?? null,       // workflow | legacy | om_contract
                'anchor_source_id'   => $anchor['source_id'] ?? null,
                'request_created_at' => $ticket->created_at?->toIso8601String(),
            ],
            ['anchor_source' => $anchor['source'] ?? null, 'anchor_source_id' => $anchor['source_id'] ?? null],
        );
    }

    /**
     * Every vehicle currently in the shop per the garage log — the refusal set for the Proactive
     * Diagnostic Monitor (never raise a request for a car already at a garage), the same fact the
     * sweep above uses to withdraw one raised earlier.
     *
     * MOVED to DiagnosticGateService (2026-08-11): "is this car at a garage right now" is a fact about
     * a car, and the gate is the one place allowed to answer it — the countdown, the parked list, the
     * clock hold and these sweeps must never disagree about who is in the shop. Delegated, not copied.
     *
     * @return int[]
     */
    public function vehicleIdsInWorkshopLog(): array
    {
        return array_keys($this->openWorkshopLogEvents(null));
    }

    /**
     * Each car's currently-open workshop-log event, keyed by vehicle_id. @see DiagnosticGateService.
     *
     * @param  int[]|null  $vehicleIds  limit to these vehicles (null = whole fleet)
     * @return array<int,Maintenance>
     */
    private function openWorkshopLogEvents(?array $vehicleIds): array
    {
        return $this->gate->openWorkshopLogEvents($vehicleIds);
    }

    /**
     * Withdraw ONE pending request against ONE open maintenance contract, under a row lock so a Controller
     * clicking Approve at the same moment as the sync cannot lose the race half-way. If the Controller got
     * there first the ticket is no longer `pending_review` and we leave it entirely alone — a human decision
     * outranks this one.
     */
    /**
     * Retire the inspection request an oil recall raised, because that recall is no longer having a test.
     *
     * The oil layer's own door into systemWithdraw(). It exists because on a recall the test answer ROUTES
     * the car — test ⇒ the Inspector and the inspection workflow, no test ⇒ the Supervisors, who read the
     * dial and pick the garage — so unticking the box has to take the card out of the review queue, not
     * merely reword a driver's brief and leave a Controller looking at a request for an inspection nobody
     * is going to do.
     *
     * Same discipline as every other system withdrawal: only a `pending_review` request is touched (a
     * request a human already approved belongs to the Inspector holding it), nobody's name is put on the
     * decision, and the card stays in the queue for a week carrying the note explaining itself.
     *
     * @return bool true when the request was actually withdrawn
     */
    public function withdrawOilFollowUpRequest(Maintenance $ticket, string $sentence, array $context = []): bool
    {
        return $this->systemWithdraw(
            $ticket,
            Maintenance::REVIEW_REJECT_OIL_TEST_NOT_WANTED,
            $sentence,
            ['source' => 'oil_projection'] + $context,
            array_intersect_key($context, array_flip(['contract_id', 'contract_no', 'oil_decision_id'])),
        );
    }

    private function withdrawOneForMaintenanceContract(Maintenance $ticket, Contract $contract): bool
    {
        $openedAt = $contract->out_date ? Carbon::parse($contract->out_date) : null;
        $sentence = 'OfficeManager opened maintenance contract '
            . ($contract->contract_no ? '#' . $contract->contract_no : '#' . $contract->id)
            . ($openedAt ? ' on ' . $openedAt->format('d M Y') : '')
            . ' — the car is already in maintenance.';

        return $this->systemWithdraw(
            $ticket,
            Maintenance::REVIEW_REJECT_IN_MAINTENANCE_CONTRACT,
            $sentence,
            // The evidence, so the note on the card is checkable rather than a claim.
            [
                'source'        => 'om_maintenance_contract',
                'contract_id'   => $contract->id,
                'contract_no'   => $contract->contract_no,
                'contract_type' => $contract->contract_type,
                'opened_at'     => $openedAt?->toIso8601String(),
                'customer'      => $contract->customer?->name_en,
            ],
            ['contract_id' => $contract->id, 'contract_no' => $contract->contract_no],
        );
    }

    /** Withdraw ONE pending request against ONE open workshop-log event — same lock, same rules. */
    private function withdrawOneForWorkshopEvent(Maintenance $ticket, Maintenance $event): bool
    {
        $garage   = trim((string) $event->garage) ?: null;
        $sentence = 'The garage log shows this car went '
            . ($garage ? 'to ' . $garage . ' ' : 'to a garage ')
            . 'on ' . $event->out_date->format('d M Y')
            . ' and has not come back — it is already in maintenance.';

        return $this->systemWithdraw(
            $ticket,
            Maintenance::REVIEW_REJECT_IN_WORKSHOP_LOG,
            $sentence,
            // The evidence: which log row (sheet-imported or hand-entered), which garage, since when.
            [
                'source'       => 'workshop_log',
                'event_id'     => $event->id,
                'event_origin' => $event->origin,
                'garage'       => $garage,
                'service_main' => trim((string) $event->service_main) ?: null,
                'opened_at'    => $event->out_date->toIso8601String(),
            ],
            ['event_id' => $event->id, 'garage' => $garage],
        );
    }

    /**
     * The shared mechanics of every SYSTEM withdrawal: flip the request to review_rejected with a
     * system-only code, under a row lock so a Controller clicking Approve at the same moment cannot lose
     * the race half-way. If the Controller got there first the ticket is no longer `pending_review` and
     * we leave it entirely alone — a human decision outranks this one.
     *
     * @param array $context  stored in review_auto_context (a `withdrawn_at` stamp is added here)
     * @param array $meta     extra keys for the vehicle-log entry and the requester's notification
     */
    private function systemWithdraw(Maintenance $ticket, string $code, string $sentence, array $context, array $meta = []): bool
    {
        return DB::transaction(function () use ($ticket, $code, $sentence, $context, $meta) {
            $locked = Maintenance::where('id', $ticket->id)->lockForUpdate()->first();
            if (! $locked || $locked->workflow_status !== Maintenance::WF_PENDING_REVIEW) {
                return false; // a human decided it first
            }

            $locked->workflow_status         = Maintenance::WF_REVIEW_REJECTED;
            // reviewed_by stays NULL on purpose: nobody reviewed this. Attributing it to a user would put a
            // decision in a person's name that they never made.
            $locked->reviewed_by             = null;
            $locked->reviewed_at             = Carbon::now();
            $locked->review_rejection_code   = $code;
            $locked->review_rejection_reason = $sentence;
            $locked->review_auto_context     = array_filter(
                $context + ['withdrawn_at' => Carbon::now()->toIso8601String()],
                fn ($v) => $v !== null && $v !== ''
            );
            $locked->save();
            $ticket = $locked;

            $this->cascade($ticket->vehicle_id);

            // Nobody is waiting on this request any more — retire its "look at it again" reminders, exactly
            // as a human rejection does.
            $this->reviewReminders->cancelOnDecision($ticket);

            $this->log->record($ticket, VehicleLogEvent::EVENT_REVIEW_REJECTED, null, [
                'description' => 'Inspection request withdrawn by the system: ' . $sentence,
                'meta'        => ['auto' => true, 'rejection_code' => $code] + $meta,
            ]);

            // Retire the "awaiting review" ping from every Controller's bell. Leaving it there would send
            // them to a card whose whole message is that there is nothing to do — the alert asked for a
            // decision that no longer exists. (Scoped to this withdrawal; the human approve/reject paths
            // are untouched.)
            $this->notifier->resolveKeyForOthers('maint_wf:' . $ticket->id . ':pending_review');

            // Tell the human who raised it (if a human did) that their request is no longer waiting — a
            // system-raised request has no requester to tell.
            if ($ticket->requested_by) {
                $requester = User::find($ticket->requested_by);
                if ($requester) {
                    $vehicle = $ticket->loadMissing('vehicle')->vehicle;
                    $this->notifier->notifyUser($requester, [
                        'type'     => 'maint_review_withdrawn',
                        'category' => 'maintenance',
                        'severity' => 'info',
                        'title'    => 'Your inspection request was withdrawn · ' . $this->label($vehicle),
                        'body'     => $sentence,
                        'url'      => $this->link($ticket),
                        'key'      => 'maint_wf:' . $ticket->id . ':review_withdrawn',
                        'icon'     => 'wrench',
                        // Deep-links the bell straight to the card carrying the withdrawal note.
                        'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no] + $meta,
                    ]);
                }
            }

            return true;
        });
    }

    /**
     * "Remind me about this request later" — a Controller parks a request in the queue instead of
     * deciding it now (the car is out on hire, the driver hasn't answered, it's the end of the shift).
     *
     * This is NOT a workflow transition and stamps nothing on the ticket: the request stays exactly where
     * it is, visible to everyone, and the only thing recorded is that one person wants to be pinged about
     * it at one moment. Deciding it later cancels the ping.
     *
     * Accepts EITHER a preset key ('2h', 'tomorrow_morning', …) or an explicit `remind_at` timestamp; the
     * preset wins if both arrive, because it is the one the reviewer actually clicked.
     *
     * @param array{preset?:?string, remind_at?:?string, note?:?string} $data
     */
    public function remindAboutReview(Maintenance $ticket, array $data, User $user): ReviewReminder
    {
        if ($ticket->workflow_status !== Maintenance::WF_PENDING_REVIEW) {
            throw new WorkflowTransitionException('This request has already been reviewed — there is nothing left to come back to.', [
                'workflow_status' => $ticket->workflow_status,
            ]);
        }

        $preset = trim((string) ($data['preset'] ?? '')) ?: null;
        $when   = $preset
            ? $this->reviewReminders->presetToMoment($preset)
            : $this->parseReminderMoment($data['remind_at'] ?? null);

        if (! $when) {
            throw new WorkflowTransitionException('Say when you want to be reminded.', [
                'field' => $preset ? 'preset' : 'remind_at',
            ]);
        }

        return $this->reviewReminders->schedule(
            $ticket,
            $user,
            $when,
            $data['note'] ?? null,
            ReviewReminder::KIND_PENDING_REVIEW,
        );
    }

    /** Drop the caller's own reminder on a request ("actually, I'll deal with it now"). */
    public function cancelReviewReminder(Maintenance $ticket, User $user): int
    {
        return $this->reviewReminders->cancelForUser($ticket, $user);
    }

    /**
     * Read a caller-supplied reminder moment, and refuse the two that cannot mean anything.
     *
     * A moment in the PAST would fire on the dispatcher's very next pass — indistinguishable from "remind
     * me now", which is what the request already does by sitting in the queue. A moment years out is
     * almost always a mistyped year, and silently accepting it means the reminder simply never arrives and
     * nobody ever learns why. A minute of slack absorbs the round-trip between the browser's clock and
     * the server's.
     */
    private function parseReminderMoment(mixed $raw): ?Carbon
    {
        if ($raw === null || $raw === '' || $raw === false) {
            return null;
        }

        try {
            $when = Carbon::parse((string) $raw);
        } catch (\Throwable) {
            throw new WorkflowTransitionException('That reminder time could not be read.', [
                'field' => 'remind_at',
            ]);
        }

        if ($when->lt(Carbon::now()->subMinute())) {
            throw new WorkflowTransitionException('That reminder time has already passed — pick a time in the future.', [
                'field' => 'remind_at',
            ]);
        }

        if ($when->gt(Carbon::now()->addYear())) {
            throw new WorkflowTransitionException('That reminder is more than a year away — check the date.', [
                'field' => 'remind_at',
            ]);
        }

        return $when;
    }

    /**
     * Retroactive sign-off for a LEGACY system-generated request that bypassed the review gate (see
     * pendingReview()'s doc comment). It's already at workflow_status = inspection_requested and fully
     * actionable on the board — this does NOT touch that; it only stamps reviewed_by/reviewed_at so the
     * accountability gap closes, without re-transitioning or re-notifying anyone.
     */
    public function acknowledgeLegacyInspectionRequest(Maintenance $ticket, User $reviewer): Maintenance
    {
        return DB::transaction(function () use ($ticket, $reviewer) {
            $locked = Maintenance::where('id', $ticket->id)->lockForUpdate()->firstOrFail();
            if ($locked->workflow_status !== Maintenance::WF_INSPECTION_REQUESTED
                || $locked->requested_by !== null
                || $locked->reviewed_by !== null) {
                throw new WorkflowTransitionException('This request is not a legacy unreviewed system request.', [
                    'workflow_status' => $locked->workflow_status,
                ]);
            }

            $locked->reviewed_by = $reviewer->id;
            $locked->reviewed_at = Carbon::now();
            $locked->save();
            $ticket = $locked;

            $this->log->record($ticket, VehicleLogEvent::EVENT_REVIEW_APPROVED, $reviewer, [
                'description' => 'Legacy system-generated request retroactively acknowledged (by ' . $reviewer->name . ') — '
                                . 'already in the Inspector\'s queue, no change to its stage',
                'meta'        => ['reviewed_by' => $reviewer->name, 'legacy' => true],
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
        $odoConfirmed = array_key_exists('odometer_confirmed', $data) ? (bool) $data['odometer_confirmed'] : null;
        $vehicle      = $ticket->loadMissing('vehicle')->vehicle;

        // "Being Inspected" strict-match gate: the car is still in our park, so the start-of-drive reading
        // must match its current mileage (a +1..5 km drift needs a note; a bigger/backward gap is blocked).
        $this->assertStrictMatch($ticket, $actor, 'test_drive', $testOdometer, $vehicle?->odometer !== null ? (int) $vehicle->odometer : null, OdometerContinuityService::STAGE_TEST, $odoNote, 'test_odometer');

        return DB::transaction(function () use ($ticket, $vehicle, $actor, $testOdometer, $odoNote, $odoConfirmed) {
            $ticket->workflow_status = Maintenance::WF_INSPECTION_DIAGNOSTIC;
            $ticket->event_status    = 'IN';
            $ticket->test_odometer   = $testOdometer; // start-of-drive anchor
            // Continuity check against the car's current mileage BEFORE we heal it forward (the heal runs
            // after save, so $vehicle->odometer here is still the prior reading).
            $this->recordOdometerFlag($ticket, 'test_drive', $testOdometer, $vehicle?->odometer !== null ? (int) $vehicle->odometer : null, OdometerContinuityService::STAGE_TEST, $odoNote, $actor, $odoConfirmed);
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
            // Opened straight into a diagnostic — the Inspector is the source, whatever the reason says.
            $ticket->request_origin  = Maintenance::SOURCE_INSPECTOR;
            $ticket->test_odometer   = $testOdometer; // start-of-drive anchor
            // Continuity check against the car's current mileage BEFORE it's healed forward (see below).
            $this->recordOdometerFlag($ticket, 'test_drive', $testOdometer, $vehicle->odometer !== null ? (int) $vehicle->odometer : null, OdometerContinuityService::STAGE_TEST, $data['odometer_note'] ?? null, $actor, array_key_exists('odometer_confirmed', $data) ? (bool) $data['odometer_confirmed'] : null);

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
        // The renter's phone — so the triage alert (and the Complaint Center) lets the Inspector call the
        // customer directly. Best-effort: null when the car isn't on rent right now.
        $renterPhone = $renter?->mobile1 ?: ($renter?->whatsapp ?: null);

        return DB::transaction(function () use ($vehicleId, $complaint, $faultSeverity, $actor, $rental, $renterName, $renterPhone) {
            $ticket = new Maintenance();
            $ticket->origin          = Maintenance::ORIGIN_MANUAL;
            $ticket->vehicle_id      = $vehicleId;
            // Born in the TRIAGE lane (Abu Maroof) — a complaint no longer jumps to the garage-dispatch
            // queue. He decides how to handle it (talk / resolve on-site / send in) before any garage.
            $ticket->workflow_status = Maintenance::WF_COMPLAINT_TRIAGE;
            $ticket->trigger_reason  = Maintenance::TRIGGER_CUSTOMER;
            $ticket->request_origin  = Maintenance::SOURCE_CUSTOMER; // the renter raised it
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
                    'customer_phone' => $renterPhone,
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
                                . ($renterPhone ? ' (📞 ' . $renterPhone . ')' : '')
                                . ' on ' . $this->label($vehicle)
                                . ' — “' . $complaint . '”. Triage it: call the customer, resolve on-site, or send the car in.'),
                'url'      => $this->link($ticket),
                'key'      => 'maint_wf:' . $ticket->id . ':complaint_triage',
                'icon'     => 'bell',
                'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no, 'fault_severity' => $faultSeverity, 'customer_complaint' => true, 'customer' => $renterName, 'customer_phone' => $renterPhone, 'contract_no' => $rental?->contract_no],
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

            // A complaint resolved on the spot never opened a contract; this is a no-op then. It runs
            // anyway so no close path can leave a workflow-opened contract hanging.
            $this->closeMaintenanceContract($ticket, $actor);

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
        // When this runs as an approval of Abu Maroof's recommendation, credit HIM in the audit alongside
        // the approving Supervisor (the actor). Null on any direct/legacy call.
        $recommendedBy = $this->clean($data['recommended_by_name'] ?? null);

        return DB::transaction(function () use ($ticket, $target, $destination, $replacementId, $note, $actor, $vehicle, $rental, $renterName, $recommendedBy) {
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

            // Either destination books the car in for work, so the visit's contract opens here too.
            $this->openMaintenanceContract($ticket, $actor);

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
                                    . ($recommendedBy ? ' (recommended by ' . $recommendedBy . ', approved by ' . $actor->name . ')' : ' (by ' . $actor->name . ')'),
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
     * Triage Routing Approval — Step 1 of 2 (Abu Maroof RECOMMENDS). He decides the complaint needs real
     * work, but he can no longer route the car himself: his chosen destination (a garage OR his own
     * diagnostic), any replacement he'd give the customer, and his note are RECORDED as a recommendation on
     * `triage_route_request`, and the ticket parks in the Triage Routing Approval gate
     * (triage_approval_pending) for a Supervisor/delegate to sign off. Nothing moves yet — the car stays
     * free, no garage is alerted and no replacement swap is arranged — until approveTriageRoute() runs.
     *
     * @param array{destination:string, replacement_vehicle_id?:?int, note?:?string} $data
     */
    public function recommendTriageRoute(Maintenance $ticket, array $data, User $actor): Maintenance
    {
        $this->assertComplaintTriage($ticket);

        $destination = $data['destination'] ?? null;
        if (! in_array($destination, ['garage', 'diagnostic'], true)) {
            throw new WorkflowTransitionException('Choose where to send the car: garage dispatch or diagnostic.', [
                'field' => 'destination',
            ]);
        }
        // Enforces "only from triage" — TRANSITIONS[complaint_triage] is the only source of this state.
        $this->assertTransition($ticket, Maintenance::WF_TRIAGE_APPROVAL_PENDING);

        $note          = $this->clean($data['note'] ?? null);
        $replacementId = ! empty($data['replacement_vehicle_id']) ? (int) $data['replacement_vehicle_id'] : null;

        return DB::transaction(function () use ($ticket, $destination, $replacementId, $note, $actor) {
            $ticket->triage_route_request = [
                'destination'            => $destination,
                'replacement_vehicle_id' => $replacementId,
                'note'                   => $note,
                'recommended_by'         => $actor->id,
                'recommended_by_name'    => $actor->name,
                'recommended_at'         => Carbon::now()->toIso8601String(),
            ];
            $ticket->workflow_status = Maintenance::WF_TRIAGE_APPROVAL_PENDING;
            $ticket->event_status    = 'IN';   // still parked — nothing has moved
            $ticket->save();

            $this->cascade($ticket->vehicle_id);

            $sentTo = $destination === 'garage' ? 'garage dispatch' : 'diagnostic inspection';
            $this->log->record($ticket, VehicleLogEvent::EVENT_STATUS_UPDATE, $actor, [
                'description' => 'Recommended sending the customer complaint to ' . $sentTo
                                . ' — awaiting supervisor approval'
                                . ($note ? ': “' . $note . '”' : '')
                                . ' (by ' . $actor->name . ')',
                'meta'        => ['source' => 'complaint_triage', 'action' => 'recommend_route', 'destination' => $destination],
            ]);

            // Ask the Supervisors/delegates to approve (or reject) the routing.
            $vehicle = $ticket->loadMissing('vehicle')->vehicle;
            $this->notifier->notifyByPermission(self::NOTIFY_DISPATCHER, [
                'type'     => 'maint_triage_route_pending',
                'category' => 'maintenance',
                'severity' => 'warning',
                'title'    => '🛂 Approve routing · ' . $this->label($vehicle),
                'body'     => trim('Abu Maroof recommends sending a customer complaint on ' . $this->label($vehicle)
                                . ' to ' . $sentTo . ' — approve or reject the routing.'),
                'url'      => $this->link($ticket),
                'key'      => 'maint_wf:' . $ticket->id . ':triage_route_pending',
                'icon'     => 'bell',
                'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no, 'customer_complaint' => true],
            ], $actor->id);

            return $ticket->load($this->eager());
        });
    }

    /**
     * Triage Routing Approval — Step 2 of 2, APPROVE. A Supervisor/delegate signs off Abu Maroof's routing
     * recommendation, and the stored decision is executed exactly as the direct route used to be: the car is
     * routed to the garage-dispatch queue or the diagnostic queue, the replacement (if any) is arranged on
     * the Swap board, and the next role is alerted. The recommendation payload is then cleared.
     */
    public function approveTriageRoute(Maintenance $ticket, User $approver): Maintenance
    {
        if ($ticket->workflow_status !== Maintenance::WF_TRIAGE_APPROVAL_PENDING) {
            throw new WorkflowTransitionException('This routing is not awaiting approval.', [
                'workflow_status' => $ticket->workflow_status,
            ]);
        }
        $request = is_array($ticket->triage_route_request) ? $ticket->triage_route_request : [];
        if (empty($request['destination'])) {
            throw new WorkflowTransitionException('This ticket has no routing recommendation to approve.');
        }

        // routeComplaint runs the real move — its assertTransition now legally fires from triage_approval_pending.
        // Pass the recommender's name through so the audit credits both Abu Maroof and the approving supervisor.
        $ticket = $this->routeComplaint($ticket, $request, $approver);

        $ticket->triage_route_request = null;
        $ticket->save();

        return $ticket->load($this->eager());
    }

    /**
     * Triage Routing Approval — Step 2 of 2, REJECT. A Supervisor/delegate rejects Abu Maroof's routing
     * recommendation: the car is NOT sent in and the complaint returns to the triage lane so he can
     * reconsider (talk to the customer, resolve on-site, or recommend a different routing). His original
     * recommendation is cleared and he is notified with the reason.
     */
    public function rejectTriageRoute(Maintenance $ticket, ?string $reason, User $actor): Maintenance
    {
        $this->assertTransition($ticket, Maintenance::WF_COMPLAINT_TRIAGE);

        return DB::transaction(function () use ($ticket, $reason, $actor) {
            $text = $this->clean($reason);
            $ticket->workflow_status      = Maintenance::WF_COMPLAINT_TRIAGE;
            $ticket->triage_route_request = null;
            $ticket->event_status         = 'IN';
            $ticket->save();

            $this->cascade($ticket->vehicle_id);

            $this->log->record($ticket, VehicleLogEvent::EVENT_STATUS_UPDATE, $actor, [
                'description' => 'Rejected the triage routing — back to triage'
                                . ($text ? ': “' . $text . '”' : '')
                                . ' (by ' . $actor->name . ')',
                'meta'        => ['source' => 'complaint_triage', 'action' => 'reject_route'],
            ]);

            // Send it back to Abu Maroof (the inspector) to reconsider.
            $vehicle = $ticket->loadMissing('vehicle')->vehicle;
            $this->notifier->notifyByPermission(self::NOTIFY_INSPECTOR, [
                'type'     => 'maint_triage_route_rejected',
                'category' => 'maintenance',
                'severity' => 'warning',
                'title'    => '↩️ Routing rejected · ' . $this->label($vehicle),
                'body'     => trim('A supervisor rejected sending ' . $this->label($vehicle) . ' in'
                                . ($text ? ' — “' . $text . '”.' : '.')
                                . ' Back in triage — reconsider how to handle the complaint.'),
                'url'      => $this->link($ticket),
                'key'      => 'maint_wf:' . $ticket->id . ':triage_route_rejected:' . Carbon::now()->timestamp,
                'icon'     => 'bell',
                'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no, 'customer_complaint' => true],
            ], $actor->id);

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
            // Reported from the floor by a technician or manager — not a driver, not the scheduler.
            $ticket->request_origin  = Maintenance::SOURCE_WORKSHOP;
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
            // The flags being folded in were pre-logged by the Inspector on his pad — he is the source.
            $ticket->request_origin   = Maintenance::SOURCE_INSPECTOR;
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

    /**
     * SERVICE INTAKE — turn a due Service Reminder / Service-Due alert into a maintenance ticket. This is
     * the ONLY way a scheduled service enters the system now: the vehicle page no longer logs service
     * directly. The car isn't picked up here; this mints (or reuses) the ticket the crew acts on, seeded
     * with a single routine-service finding whose text maps back to the service_type
     * (Maintenance::serviceLabelForType) so that, once a technician performs it and the ticket is CLOSED,
     * confirmRoutineServices() rolls the reminder + updates the vehicle. If the car already has an open
     * ticket, the service is appended to it instead of minting a duplicate ("open OR create").
     *
     * @return Maintenance the open (new or existing) ticket
     */
    public function openServiceTicket(Vehicle $vehicle, string $serviceLabel, ?int $odometer, User $actor): Maintenance
    {
        $this->assertActiveFleet($vehicle);
        $serviceLabel = $this->clean($serviceLabel) ?: 'Routine Service';

        return DB::transaction(function () use ($vehicle, $serviceLabel, $odometer, $actor) {
            // Already in the maintenance pipeline → append the service to that open ticket (no duplicate).
            if ($this->operations->vehicleInMaintenance($vehicle->id)) {
                $open = Maintenance::where('vehicle_id', $vehicle->id)
                    ->whereIn('workflow_status', array_values(array_diff(Maintenance::WF_TICKET_STATES, [Maintenance::WF_CLOSED])))
                    ->orderByDesc('id')
                    ->first();
                if ($open) {
                    $this->appendServiceFinding($open, $serviceLabel, $actor);
                    return $open->load($this->eager());
                }
            }

            $odo = ($odometer && $odometer > 0) ? (int) $odometer : ($vehicle->odometer ?: null);

            $ticket = new Maintenance();
            $ticket->origin            = Maintenance::ORIGIN_MANUAL;
            $ticket->vehicle_id        = $vehicle->id;
            // Born in the Supervisors' dispatch queue — a scheduled service skips the diagnostic.
            $ticket->workflow_status   = Maintenance::WF_INSPECTION_PENDING;
            $ticket->trigger_reason    = Maintenance::TRIGGER_PERIODIC;
            // Born from a due Service Reminder — the scheduler is the source, even though a human
            // pressed the button to convert it.
            $ticket->request_origin    = Maintenance::SOURCE_SYSTEM_SCHEDULE;
            $ticket->visit_context     = 'standard';
            $ticket->maintenance_type  = Maintenance::TYPE_ROUTINE;
            $ticket->fault_severity    = Maintenance::FAULT_SEVERITY_ROUTINE;
            $ticket->severity          = Maintenance::FAULT_SEVERITY_ROUTINE;
            $ticket->findings          = [$this->serviceFinding($serviceLabel, $actor)];
            $ticket->intake_odometer   = $odo;
            $ticket->maintenance_notes = 'Routine check due — ' . $serviceLabel;
            $ticket->event_status      = 'IN';
            $ticket->requested_by      = $actor->id;
            $ticket->requested_at      = Carbon::now();
            $ticket->responsible       = $actor->name;
            $ticket->save();

            // Promote the seeded finding into a first-class MaintenanceTask (Pending, unassigned).
            app(MaintenanceTaskService::class)->syncFromFindings($ticket, $actor);

            // The ticket is a committed WF_TICKET_STATE → the car reconciles to operational_status = maintenance.
            $this->cascade($ticket->vehicle_id);

            $this->log->record($ticket, VehicleLogEvent::EVENT_REPORT_FILED, $actor, [
                'description' => 'Routine service ticket opened — ' . $serviceLabel
                    . ($odo ? ' at ' . number_format($odo) . ' km' : '') . ' (by ' . $actor->name . ')',
                'meta'        => ['trigger_reason' => Maintenance::TRIGGER_PERIODIC, 'service' => $serviceLabel, 'intake_odometer' => $odo],
            ]);

            $vehicle = $ticket->loadMissing('vehicle')->vehicle;
            $this->notifier->notifyByPermission(self::NOTIFY_DISPATCHER, [
                'type'     => 'maint_service_intake',
                'category' => 'maintenance',
                'severity' => 'warning',
                'title'    => trim('🔧 Routine service due · ' . $this->label($vehicle)),
                'body'     => trim($serviceLabel . ' is due for ' . $this->label($vehicle) . '. Assign a garage.'),
                'url'      => $this->link($ticket),
                'key'      => 'maint_wf:' . $ticket->id . ':inspection_pending',
                'icon'     => 'wrench',
                'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no, 'service' => $serviceLabel],
            ], $actor->id);

            return $ticket->load($this->eager());
        });
    }

    /** Add a routine-service finding to an already-open ticket (idempotent by symptom) + promote to a task. */
    private function appendServiceFinding(Maintenance $ticket, string $serviceLabel, User $actor): void
    {
        $findings = is_array($ticket->findings) ? $ticket->findings : [];
        $key = mb_strtolower(trim($serviceLabel));
        foreach ($findings as $f) {
            if (mb_strtolower(trim((string) ($f['text'] ?? ''))) === $key) {
                return; // already on this ticket — nothing to add
            }
        }

        $findings[] = $this->serviceFinding($serviceLabel, $actor);
        $ticket->findings = $findings;
        $ticket->save();

        app(MaintenanceTaskService::class)->syncFromFindings($ticket, $actor);

        $this->log->record($ticket, VehicleLogEvent::EVENT_REPORT_FILED, $actor, [
            'description' => 'Service added to the open ticket — ' . $serviceLabel . ' (by ' . $actor->name . ')',
            'meta'        => ['service' => $serviceLabel],
        ]);
    }

    /** The findings-JSON shape for a seeded routine-service finding (matches submitReport's shape). */
    private function serviceFinding(string $serviceLabel, User $actor): array
    {
        return [
            'text'          => $serviceLabel,
            'category_key'  => Maintenance::categoryForKeyword($serviceLabel),
            'source'        => Maintenance::FINDING_INSPECTOR,
            'severity'      => Maintenance::FAULT_SEVERITY_ROUTINE,
            'root_cause'    => null,
            'root_cause_id' => null,
            'by'            => $actor->name,
            'at'            => Carbon::now()->toIso8601String(),
        ];
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

        // Where the diagnostic lands when it "requires maintenance":
        //   • on-site → the mobile lane (car stays available), unchanged.
        //   • in-shop → straight to inspection_pending (Needs Dispatch).
        //
        // There is NO approval gate between the report and the pipeline. A filed report is the inspector's
        // technical finding, and a technical finding does not need signing off before the car can be
        // dispatched — the Supervisor's real decision (which garage, which driver) already happens at
        // inspection_pending. The old recommendation_pending detour meant a car with a recommended action
        // sat waiting for an approval that added nothing, so it is gone; see
        // [[inspection-required-parts-split]]. Required parts raise their own Part Requests at submit time
        // and are handled by procurement in parallel — they never hold the repair up either.
        $target = ! $requiresMaintenance
            ? Maintenance::WF_DIAGNOSTIC_CLEARED
            : ($repairLocation === Maintenance::REPAIR_ON_SITE
                ? Maintenance::WF_ON_SITE_PENDING
                : Maintenance::WF_INSPECTION_PENDING);
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

        // Rental Eligibility — the inspector's ONE-TIME call, made here at the Decide step and carried by
        // the ticket for its whole life: may this car be rented BEFORE maintenance completes? Defaults to
        // false (mandatory) — the car stays grounded until the workshop finishes — unless the inspector
        // explicitly marks it deferrable, in which case a later rental pauses the ticket and it resumes on
        // return. A cleared diagnostic (no maintenance) carries no such decision, so it stays false.
        $deferrableForRental = $requiresMaintenance && ! empty($report['deferrable_for_rental']);

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

        // THE OIL CHANGE A RECALL OWES IS NOT OPTIONAL HERE EITHER.
        // If this ticket came from an oil recall, a paying customer's rental was interrupted BECAUSE
        // the car needs an oil change — a driver was sent, a customer inconvenienced. By the time the
        // inspector files his report that is a decision already taken, not one of six routine boxes
        // he may untick on his way past. The picker locks it; this is the same rule where it cannot
        // be bypassed by a crafted request, a stale tab, or a future screen that forgets.
        //
        // Appended, never rejected: refusing the whole report would lose an inspector's real work
        // over a checkbox, and the requirement is ours to re-assert, not his to satisfy.
        if ($requiresMaintenance && $this->oilChangeIsOwed($ticket)) {
            $already = array_filter($payload['symptoms'], fn ($s) => mb_strtolower($s) === 'oil change');
            if (! $already) {
                $payload['symptoms'][] = 'Oil Change';
            }
        }

        // A ticket must carry SOME finding (it explains the repair); a "no maintenance" clearance
        // may legitimately be empty (the car was fine).
        if ($requiresMaintenance && $payload['symptoms'] === [] && ! $payload['recommended_action'] && ! $payload['notes']) {
            throw new WorkflowTransitionException('Add at least a symptom, an action, or a note before opening a maintenance ticket.', [
                'field' => 'test_drive_report',
            ]);
        }

        // THE MIRROR RULE — a clearance may not carry findings. Without it the two halves of the Decide
        // step could contradict each other: an inspector tapped real faults and still filed "no
        // maintenance needed", and the report saved happily. The damage was silent, not cosmetic —
        // the findings below are written to the row REGARDLESS of the decision, but a cleared diagnostic
        // is terminal and never runs syncFromFindings(), so those faults became first-class evidence
        // attached to a ticket that no lane, no garage and no queue would ever show again. The car went
        // back into service carrying faults the platform had recorded and buried in the same click.
        // A car with findings needs a ticket: the inspector must either untick them or open one.
        if (! $requiresMaintenance && $payload['symptoms'] !== []) {
            throw new WorkflowTransitionException(
                'This report lists ' . count($payload['symptoms']) . ' finding(s), so it cannot be filed as “no maintenance needed”. '
                . 'Remove the findings, or choose “Requires maintenance” and open the ticket.',
                [
                    'field'    => 'test_drive_report',
                    'findings' => $payload['symptoms'],
                ],
            );
        }

        // WHERE + HOW MANY per symptom — the same shape as `causes` below, and keyed the same way, so
        // the three per-symptom side-channels the Decide step carries (cause, location, count) work
        // identically. Each entry: ['quantity' => int, 'locations' => string[]].
        //
        // Absent for every symptom the inspector did not localise, which is the normal case for the
        // fault types whose policy is `none`, and for every report filed before this existed.
        $detailChoices = [];
        $locationSvc   = app(FaultLocationService::class);
        foreach ((array) ($report['details'] ?? []) as $d) {
            if (! is_array($d) || ! isset($d['symptom'])) {
                continue;
            }
            $detailChoices[FaultCause::normalizeKey($d['symptom'])] = [
                'quantity'  => $locationSvc->normalizeQuantity($d['quantity'] ?? 1),
                'locations' => $locationSvc->normalizeSlugs((array) ($d['locations'] ?? [])),
            ];
        }

        // THE LOCATION GATE. A type whose policy says `required` (a scratch, a dent, a tyre, a light)
        // may not be filed without a place: "scratch" on a car nobody can point at is a fault the
        // workshop cannot find and the next inspector re-reports. Every offender is named in ONE
        // message rather than one resubmission each — the inspector is still on the screen and can
        // fix all of them in the same pass.
        if ($requiresMaintenance) {
            $needLocation = $locationSvc->findingsMissingRequiredLocation(array_map(
                fn ($text) => [
                    'text'         => $text,
                    'category_key' => Maintenance::categoryForKeyword($text),
                    'locations'    => $detailChoices[FaultCause::normalizeKey($text)]['locations'] ?? [],
                ],
                $payload['symptoms'],
            ));
            if ($needLocation) {
                throw new WorkflowTransitionException(
                    'Say where on the car: ' . implode(', ', $needLocation) . '.',
                    ['field' => 'details', 'findings' => $needLocation],
                );
            }
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
        $reportOdo      = isset($report['report_odometer']) && is_numeric($report['report_odometer']) ? (int) $report['report_odometer'] : null;
        $reportNote     = $report['odometer_note'] ?? null;
        $reportConfirmed = array_key_exists('odometer_confirmed', $report) ? (bool) $report['odometer_confirmed'] : null;
        // The car may not have moved at all — an inspector can diagnose a fault without a test drive — so
        // equal to the start-of-drive anchor is fine; only a decrease is impossible and hard-blocked.
        if ($reportOdo !== null && $reportOdo > 0) {
            $this->assertNoDecrease($ticket, $actor, 'report', $reportOdo, $ticket->test_odometer !== null ? (int) $ticket->test_odometer : null, $reportNote, 'report_odometer', 'start-of-drive reading');
        }

        return DB::transaction(function () use ($ticket, $payload, $target, $requiresMaintenance, $actor, $faultSeverity, $causeChoices, $detailChoices, $repairLocation, $deferrableForRental, $reportOdo, $reportNote, $reportConfirmed) {
            $ticket->test_drive_report = $payload;

            // The inspector's symptoms become first-class FINDINGS, source-stamped so they persist and
            // stay attributable ("Inspector-Identified") through every later stage of the workflow.
            // Each carries its resolved root cause (label + canonical fault_causes id) for analytics
            // + the eventual Odoo sync; a custom cause is recorded for admin review inside resolve().
            $vehicleForCheck = $ticket->loadMissing('vehicle')->vehicle;
            $ticket->findings = collect($payload['symptoms'])
                ->map(function ($text) use ($actor, $payload, $causeChoices, $detailChoices, $vehicleForCheck) {
                    $choice = $causeChoices[FaultCause::normalizeKey($text)] ?? null;
                    $detail = $detailChoices[FaultCause::normalizeKey($text)] ?? null;
                    [$cause, $causeId] = $choice
                        ? $this->resolveFaultCause($text, $choice['label'], $choice['id'], $actor)
                        : [null, null];

                    return [
                        'text'          => $text,
                        // Stamped at the ORIGIN, not left for readers to re-derive. Half a dozen
                        // consumers (garage routing, the recommendation engine, repair history) each
                        // called categoryForKeyword() on this text because the entry never carried it,
                        // and MaintenanceTask read `$f['category_key'] ?? null` from an entry that had
                        // no such key. Null here means the catalog does not know this text — a custom
                        // issue the inspector typed — which is a fact worth recording, not a gap.
                        'category_key'  => Maintenance::categoryForKeyword($text),
                        'source'        => Maintenance::FINDING_INSPECTOR,
                        'severity'      => $payload['severity'],
                        'root_cause'    => $cause,
                        'root_cause_id' => $causeId,
                        // WHAT IS WRONG, HOW MANY, AND WHERE — the last two stamped at the origin like
                        // category_key above, not left for readers to re-derive from prose. Always
                        // written (never conditionally omitted) so a re-filed report that CLEARS a
                        // location clears it on the fault too; absent on every finding written before
                        // this existed, which MaintenanceTaskService reads as "nothing to say".
                        'quantity'      => $detail['quantity'] ?? 1,
                        'locations'     => $detail['locations'] ?? [],
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
            // Rental Eligibility (deferrable vs mandatory) — the inspector's one-time decision, stamped on
            // the ticket so every later rental attempt respects it. false = mandatory (grounded until done).
            $ticket->deferrable_for_rental = $deferrableForRental;
            // End-of-test-drive odometer — recorded before save so the continuity flag rides along; heals the
            // car's canonical mileage forward (the drive moved the meter). The must-be-higher gate already
            // ran above; this just stamps the continuity flag for the audit trail.
            if ($reportOdo !== null && $reportOdo > 0) {
                $ticket->report_odometer = $reportOdo;
                $this->recordOdometerFlag($ticket, 'report', $reportOdo, $ticket->test_odometer !== null ? (int) $ticket->test_odometer : null, OdometerContinuityService::STAGE_TEST_END, $reportNote, $actor, $reportConfirmed);
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
                // Nothing wrong with the car: the visit ends at the test drive, so its contract closes
                // here rather than sitting open for a repair that will never happen.
                $this->closeMaintenanceContract($ticket, $actor);

                $this->log->record($ticket, VehicleLogEvent::EVENT_DIAGNOSTIC_CLEARED, $actor, [
                    'description' => 'Test drive cleared — no maintenance required (by ' . $actor->name . ')',
                    'meta'        => ['severity' => $payload['severity'], 'maintenance_type' => $payload['maintenance_type']],
                ]);

                return $ticket->load($this->eager());
            }

            $typeLabel = $payload['maintenance_type'] ? (Maintenance::MAINTENANCE_TYPES[$payload['maintenance_type']] ?? $payload['maintenance_type']) : null;
            $onSite    = $repairLocation === Maintenance::REPAIR_ON_SITE;
            $this->log->record($ticket, VehicleLogEvent::EVENT_REPORT_FILED, $actor, [
                'description' => ($onSite ? 'On-site maintenance ticket opened from test-drive report'
                        : 'Maintenance ticket opened from test-drive report')
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

            // In-Shop → hand the DISPATCH decision to the Supervisors
            // (Waleed/Abdullah): they review the report and pick the garage. The driver pool is only alerted
            // later, once a car is actually ready to collect (assignDispatch()). The fault severity rides into
            // the alert (emoji + colour) so the supervisor gauges the urgency the moment it lands.
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
     * DELEGATION — a Supervisor names the Logistics driver who collects the car for its garage run.
     * The driver reuses the "Where is the car?" assignment (assigned_driver_id) so pings + status
     * follow them; the overlay marks the task + flips the ticket to "Driver Assigned" and stamps who
     * delegated. The assigned driver is notified directly, carrying the fault-severity colour/symbol, and is
     * added as a watcher so they keep getting the ticket's updates.
     *
     * ONLY valid at Awaiting Pickup — the single stage where the garage is decided, the car is still parked
     * with us, and the open question is WHO drives it. Before that there is nothing to collect; after it the
     * car has already moved and its custody is owned by the dispatch/collect actions. `delegation_task` is
     * therefore never asked for: at this stage the leg is always a PICKUP.
     *
     * @param array{driver_id:int} $data
     */
    public function delegate(Maintenance $ticket, array $data, User $actor): Maintenance
    {
        if ($ticket->workflow_status !== Maintenance::WF_AWAITING_DISPATCH) {
            throw new WorkflowTransitionException('A driver can only be assigned while the ticket is Awaiting Pickup.', [
                'workflow_status' => $ticket->workflow_status,
            ]);
        }

        $task = Maintenance::DELEGATION_PICKUP;

        $driverId = (int) ($data['driver_id'] ?? 0);
        $driver   = $driverId ? User::find($driverId) : null;
        if (! $driver) {
            throw new WorkflowTransitionException('Select a driver to delegate to.', ['field' => 'driver_id']);
        }
        // Assigning it to yourself is not a delegation — it would notify the supervisor of their own
        // decision and leave the ticket reading "Driver Assigned" when nobody was actually briefed.
        // A supervisor who wants to take the car presses "Pick up" and becomes the custodian directly.
        if ($driver->id === $actor->id) {
            throw new WorkflowTransitionException('You cannot assign the car to yourself — use "Pick up" to take it yourself.', [
                'field' => 'driver_id',
            ]);
        }
        // The DRIVER pool (`logistics`) OR a SUPERVISOR. Still deliberately not "anyone holding
        // maintenance.logistics" — that permission reaches every manager and admin and would turn the
        // picker into a staff directory. But a supervisor genuinely does collect and return cars himself
        // when no driver is free, and until now the only way to record that was for him to press "Pick up",
        // which works only once he is already standing at the car. Naming him here lets the job be PLANNED:
        // it lands in his queue and rings his bell exactly as a driver's would. Mirrors assignableDrivers().
        if (! $driver->hasRole('logistics') && ! $driver->hasRole(self::SUPERVISOR_ROLE)) {
            throw new WorkflowTransitionException('That user is neither a driver nor a supervisor — pick someone from the list.', [
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
     * Normalise the decision-support recommendation payload for the garage_assigned audit meta. Records
     * what the data-driven engine suggested, the Supervisor's chosen garage, whether they FOLLOWED the
     * recommendation (chosen === recommended), and the reason — so "why this garage?" is auditable. Returns
     * null when the client sent nothing (older clients / manual assigns), keeping the meta backward-compatible.
     *
     * @param  array<string, mixed>|null  $rec
     * @return array<string, mixed>|null
     */
    private function recommendationMeta(?array $rec, Vendor $chosen): ?array
    {
        if (empty($rec)) {
            return null;
        }
        $recommendedId = isset($rec['recommended_vendor_id']) ? (int) $rec['recommended_vendor_id'] : null;
        $recommendedName = $recommendedId ? optional(Vendor::find($recommendedId))->name : null;

        return [
            'recommended_vendor_id' => $recommendedId,
            'recommended_garage'    => $recommendedName,
            'chosen_vendor_id'      => $chosen->id,
            'chosen_garage'         => $chosen->name,
            // followed = the Supervisor picked the engine's top suggestion; overridden otherwise.
            'followed'              => $recommendedId !== null ? ($recommendedId === $chosen->id) : null,
            'accepted'              => array_key_exists('accepted', $rec) ? (bool) $rec['accepted'] : null,
            'rank'                  => isset($rec['rank']) ? (int) $rec['rank'] : null,
            'score'                 => isset($rec['score']) ? (float) $rec['score'] : null,
            'confidence'            => $rec['confidence'] ?? null,
            'reason'                => isset($rec['reason']) ? $this->clean($rec['reason']) : null,
            'reasons'               => is_array($rec['reasons'] ?? null) ? array_slice($rec['reasons'], 0, 4) : null,
            'source'                => $rec['source'] ?? 'experience_engine',
            // WHY the supervisor differed. Recorded as fact, never as fault — most overrides are sound
            // operational judgement the engine has no way to see.
            'override_reason'       => $this->overrideReason($rec, $recommendedId, $chosen->id),
            'override_note'         => isset($rec['override_note']) ? $this->clean($rec['override_note']) : null,
        ];
    }

    /**
     * The override reason, validated against the configured taxonomy.
     *
     * Only meaningful when the supervisor actually differed — a reason attached to a followed
     * recommendation is a client bug, and storing it would corrupt every acceptance-rate figure that
     * reads this column. An unrecognised key degrades to `other` rather than being stored raw, so the
     * counts can never be polluted by a stale or misspelled client value.
     */
    private function overrideReason(array $rec, ?int $recommendedId, int $chosenId): ?string
    {
        if ($recommendedId === null || $recommendedId === $chosenId) {
            return null;
        }
        $reason = $rec['override_reason'] ?? null;
        if (! is_string($reason) || $reason === '') {
            return null;
        }
        return array_key_exists($reason, (array) config('garage_recommendation.override_reasons', []))
            ? $reason
            : 'other';
    }

    /**
     * Persist the durable garage-choice decision (garage_recommendation_decisions) — the canonical "why did
     * we send this car here?" record that survives independently of the audit log. Best-effort: a write
     * failure never blocks the dispatch the Supervisor just made.
     *
     * @param  array<string, mixed>|null  $rec
     */
    private function recordRecommendationDecision(Maintenance $ticket, ?array $rec, Vendor $chosen, User $actor): void
    {
        // EVERY garage assignment is recorded, including ones made with no recommendation on screen
        // (a re-dispatch back to the garage that botched the repair, an older client, a supervisor who
        // never opened the panel). Those rows carry a null `recommended_vendor_id` and are excluded
        // from the acceptance rate — you cannot accept advice that was never given — but they are the
        // only way to know how often the engine is bypassed entirely. Recording nothing would make the
        // acceptance rate look healthy precisely when nobody is using the recommendation at all.
        $rec = is_array($rec) ? $rec : [];

        try {
            $recommendedId = isset($rec['recommended_vendor_id']) ? (int) $rec['recommended_vendor_id'] : null;
            GarageRecommendationDecision::create([
                'maintenance_id'        => $ticket->id,
                'vehicle_id'            => $ticket->vehicle_id,
                'recommended_vendor_id' => $recommendedId,
                'chosen_vendor_id'      => $chosen->id,
                'accepted'              => (bool) ($rec['accepted'] ?? false),
                // NULL, not false, when no recommendation existed — "did not follow" and "there was
                // nothing to follow" are different facts and must not collapse into one.
                'followed'              => $recommendedId !== null ? ($recommendedId === $chosen->id) : null,
                'rank'                  => isset($rec['rank']) ? (int) $rec['rank'] : null,
                'score'                 => isset($rec['score']) ? (float) $rec['score'] : null,
                // The 0–100 the Supervisor actually saw, with the factor breakdown behind it and the
                // one-garage-vs-split call. Snapshotted because the engine's history dataset moves daily —
                // re-running it later answers a different question than the one asked at dispatch.
                'match_score'           => isset($rec['match_score']) ? (int) $rec['match_score'] : null,
                'confidence'            => $rec['confidence'] ?? null,
                'reasons'               => is_array($rec['reasons'] ?? null) ? array_slice($rec['reasons'], 0, 4) : null,
                'breakdown'             => is_array($rec['breakdown'] ?? null) ? $rec['breakdown'] : null,
                'strategy'              => is_array($rec['strategy'] ?? null) ? $rec['strategy'] : null,
                // The FORECAST that was on screen — turnaround, comeback risk, cost, start date, each
                // with its basis. Recorded so it can be scored against what actually happened.
                'expected_outcomes'     => is_array($rec['expected_outcomes'] ?? null) ? $rec['expected_outcomes'] : null,
                'fault_criticality'     => is_array($rec['fault_criticality'] ?? null) ? $rec['fault_criticality'] : null,
                'criteria'              => is_array($rec['criteria'] ?? null) ? $rec['criteria'] : null,
                // Which engine build, which policy, which tuning, which day's data. Without these an old
                // decision cannot be explained — re-running today's engine answers a different question.
                'engine_version'        => $rec['provenance']['engine_version'] ?? null,
                'policy_version'        => $rec['provenance']['policy_version'] ?? null,
                'config_fingerprint'    => $rec['provenance']['config_fingerprint'] ?? null,
                'data_snapshot'         => $rec['provenance']['data_snapshot'] ?? null,
                'actor_id'              => $actor->id,
                // ── The feedback loop ─────────────────────────────────────────────────────────────
                // `followed` alone says a human disagreed and nothing about whether we were wrong. The
                // reason, the gap they were willing to accept, and what their pick was measurably
                // better at are what turn "supervisors keep overriding us" into a testable claim.
                'override_reason'       => $this->overrideReason($rec, $recommendedId, $chosen->id),
                'override_note'         => isset($rec['override_note']) ? $this->clean($rec['override_note']) : null,
                'chosen_rank'           => isset($rec['chosen_rank']) ? (int) $rec['chosen_rank'] : null,
                'chosen_match_score'    => isset($rec['chosen_match_score']) ? (int) $rec['chosen_match_score'] : null,
                // The recommended score is sent SEPARATELY from `match_score` above: that one is the
                // chosen garage's (what the supervisor acted on), so computing the gap from it would
                // return 0 on every override and make every disagreement look like a tie.
                'score_gap'             => isset($rec['recommended_match_score'], $rec['chosen_match_score'])
                    ? (int) $rec['recommended_match_score'] - (int) $rec['chosen_match_score'] : null,
                // Measured, not claimed — so a stated reason can be checked against what the data says.
                'chosen_advantages'     => is_array($rec['chosen_advantages'] ?? null) ? $rec['chosen_advantages'] : null,
            ]);
        } catch (\Throwable $e) {
            report($e); // the decision log must never sink a real dispatch
        }
    }

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
            // Concurrency / double-submit guard: lock the row and re-validate the transition against the
            // freshly-read status, so a duplicate/concurrent assign-dispatch blocks then fails cleanly.
            $this->assertTransition(
                Maintenance::where('id', $ticket->id)->lockForUpdate()->firstOrFail(),
                Maintenance::WF_AWAITING_DISPATCH,
            );

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
                'meta' => ['garage' => $vendor->name, 'vendor_id' => $vendor->id, 'driver_id' => $driver?->id, 'driver' => $driver?->name, 'by' => $actor->name, 'note' => $note, 'sent_back' => $fromReinspectionFailed, 'from_garage' => $fromReinspectionFailed ? $previousGarage : null,
                    // Decision-support trail: what the data-driven recommendation engine suggested and whether
                    // the Supervisor followed it. Answers "why was this garage selected?" in the audit log.
                    'recommendation' => $this->recommendationMeta($data['recommendation'] ?? null, $vendor)],
            ]);

            // Canonical, durable record of the garage choice (survives independently of the audit log).
            $this->recordRecommendationDecision($ticket, $data['recommendation'] ?? null, $vendor, $actor);

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
    /**
     * May this person move a car that is on someone else's name?
     *
     * The custody gates below exist so a pickup can't be silently taken over with no accountability. But
     * the driver pool is not the only group that physically moves cars: the SUPERVISORS run cars to the
     * garage and bring them back themselves, routinely, and the gates were turning that everyday act into
     * "ask the supervisor to reassign it" — addressed to the supervisor. So anyone carrying the dispatch
     * authority (maintenance.delegate — supervisors, managers, admins) may step into a leg assigned to
     * someone else. Everyone else is still held to their own assignment.
     *
     * Nothing is lost by allowing it: every one of these transitions stamps who ACTUALLY did it
     * (`driver` / `dispatched_by` / `picked_up_from_garage_by`), so the audit trail names the real person
     * rather than the person the leg was planned for.
     */
    private function maySupersedeDriver(User $actor): bool
    {
        return $actor->can(self::SUPERSEDE_DRIVER_PERMISSION);
    }

    public function dispatch(Maintenance $ticket, array $data, User $actor): Maintenance
    {
        $this->assertTransition($ticket, Maintenance::WF_IN_TRANSIT);

        // Assigned-pickup gate. When the Supervisor named a driver for this pickup, the job is that
        // driver's — anyone else asking to take the car is refused and told whose it is. A pickup with
        // NO driver named stays open to the pool (that is how a garage transfer is raised: the leg is
        // left unassigned so whoever is free claims it — see transferGarage()). The driver's queue hides
        // the button under the same rule, so this only catches a stale tab or a direct API call.
        // A SUPERVISOR is exempt (see maySupersedeDriver): they run these moves themselves too.
        if ($ticket->assigned_driver_id && (int) $ticket->assigned_driver_id !== $actor->id && ! $this->maySupersedeDriver($actor)) {
            $assignee = $ticket->loadMissing('assignedDriver')->assignedDriver?->name;
            throw new WorkflowTransitionException(
                'This pickup is assigned to ' . ($assignee ?: 'another driver') . ' — ask the supervisor to reassign it if you are taking the car.',
                ['field' => 'assigned_driver_id', 'assigned_driver' => $assignee]
            );
        }

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
        // pickup reading must match the last recorded mileage — the end-of-test-drive reading (report_odometer)
        // when the inspector logged one, else the start-of-drive anchor, else the live odometer. Using the
        // START anchor here would wrongly hard-block every dispatch after a real test drive (e.g. the drive
        // itself covered 7 km, so comparing the pickup reading against the PRE-drive value always overshoots
        // the ±5 km buffer) — report_odometer is the actual last-known mileage the car sat at in our park.
        // A +1..5 km drift from THAT still needs a note; a bigger or backward gap is blocked as a typo /
        // unauthorised move.
        $lastRecorded = $ticket->report_odometer
            ?? $ticket->test_odometer
            ?? ($ticket->loadMissing('vehicle')->vehicle?->odometer !== null ? (int) $ticket->vehicle->odometer : null);
        $this->assertStrictMatch($ticket, $actor, 'dispatch', $odometer, $lastRecorded, OdometerContinuityService::STAGE_PARK_PICKUP, $data['odometer_note'] ?? null, 'dispatch_odometer');

        return DB::transaction(function () use ($ticket, $odometer, $vendor, $data, $actor, $lastRecorded) {
            // Concurrency / double-submit guard: take the ticket's row lock and RE-VALIDATE the transition
            // against the freshly-read status INSIDE the transaction. A duplicate click or a concurrent
            // transition on the same ticket blocks here until the first commits, then fails this guard
            // instead of double-writing (here: raising a SECOND in-transit logistics leg for one car).
            $this->assertTransition(
                Maintenance::where('id', $ticket->id)->lockForUpdate()->firstOrFail(),
                Maintenance::WF_IN_TRANSIT,
            );

            $ticket->dispatch_odometer = $odometer;
            // Pickup continuity (strict-match park stage): recorded against the same last-recorded anchor the
            // gate above checked. An in-range drift stamps 'authorized_deviation' (+ its note) for the
            // /oversight/mileage audit board; an exact match is 'verified'.
            $this->recordOdometerFlag($ticket, 'dispatch', $odometer, $lastRecorded, OdometerContinuityService::STAGE_PARK_PICKUP, $data['odometer_note'] ?? null, $actor, array_key_exists('odometer_confirmed', $data) ? (bool) $data['odometer_confirmed'] : null);
            $ticket->vendor_id         = $vendor->id;
            $ticket->garage            = $vendor->name;   // denormalised label the board shows
            $ticket->driver            = $actor->name;    // the Driver who took the car to the garage
            $ticket->dispatched_by     = $actor->id;
            $ticket->dispatched_at     = Carbon::now();
            // A supervisor who took the leg themselves becomes the person holding the car — otherwise the
            // ticket would keep pointing at the planned driver, and the return leg's custody gate would be
            // measured against someone who never had it.
            $ticket->assigned_driver_id = $actor->id;
            // A DRIVER has custody of this leg — clear any recovery flag left over from an EARLIER leg
            // (e.g. the car arrived here by tow, then gets driven onward from its next garage). Without
            // this, isRecovery() would keep reporting the stale prior leg's transport method instead of
            // this one's, which the arrival odometer gate below depends on being current.
            $ticket->recovery_unit_name  = null;
            $ticket->recovery_unit_phone = null;


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

            // BACKSTOP for the contract link. Normally the visit already has one: it was opened the
            // moment the ticket reached "Needs Test Drive". But a ticket the Inspector starts directly as
            // a diagnostic (open()) never passes through that stage, so it can arrive here with no
            // contract at all — and a visit without one is invisible to every money, history and
            // utilization surface that reads a maintenance visit from its type-'U' contract, including
            // the visit journey on the contract page. Link-or-create, never a second contract; idempotent,
            // so the normal path just re-links what's already there.
            // Stored in linked_contract_id, NOT contract_id (that's the unique 1:1 header column —
            // reusing it would collide with the linked contract's own header row).
            $this->openMaintenanceContract($ticket, $actor);

            // Pickup done → the car heads to the garage. It lands in the "Now at Garage" arrival
            // checkpoint (in_transit); the repair clock (repair_started_*) starts only once arrival is
            // confirmed with the MANDATORY arrival odometer (markUnderRepair) — NOT here.
            $ticket->workflow_status   = Maintenance::WF_IN_TRANSIT;
            $ticket->save();

            // Raise the transport leg so the DRIVER reads as busy while physically taking the car to the
            // garage (Driver Availability is sourced from LogisticsTask ONLY). This becomes the ticket's
            // activeMove(), driving its live "In Transit" position; markUnderRepair() closes it on arrival.
            if ($vehicle = $ticket->loadMissing('vehicle')->vehicle) {
                $this->logistics->raiseMaintenanceLeg(
                    $vehicle, $vendor->name, $actor->id, $ticket->id, $actor,
                    'Maintenance pickup — driving to ' . $vendor->name
                );
            }

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

        // At inspection_pending no garage is picked yet — that's the Supervisor's call, same as the
        // classic "Assign Garage" screen. A Driver only gets a say once a garage is already assigned
        // (awaiting_dispatch) and the tow turns out to be needed instead of a normal pickup.
        if ($ticket->workflow_status === Maintenance::WF_INSPECTION_PENDING && ! $actor->can('maintenance.delegate')) {
            throw new WorkflowTransitionException(
                'Only a Supervisor can dispatch a recovery before a garage has been assigned.',
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

        // A recovery is a TOW, not a drive — the car is disabled and never moves under its own power between
        // the last recorded reading and the truck picking it up, so the reading must match EXACTLY (not
        // just "not lower"). The client locks the field to this value; enforce it here too so a direct API
        // call can't slip in a different number. Skipped when there's no anchor to match (e.g. bootstrap).
        $lastRecorded = $ticket->test_odometer
            ?? ($ticket->loadMissing('vehicle')->vehicle?->odometer !== null ? (int) $ticket->vehicle->odometer : null);
        if ($lastRecorded !== null && $odometer !== $lastRecorded) {
            $this->logOdometerBlock($ticket, $actor, 'dispatch', $odometer, $lastRecorded, $odometer - $lastRecorded, 'must_increase', $data['odometer_note'] ?? null);
            throw new WorkflowTransitionException(
                'A towed car can\'t change mileage — the reading must match the last recorded one ('
                    . number_format($lastRecorded) . ' km), not ' . number_format($odometer) . ' km.',
                ['field' => 'dispatch_odometer']
            );
        }

        return DB::transaction(function () use ($ticket, $odometer, $vendor, $unitName, $unitPhone, $data, $actor, $lastRecorded) {
            $ticket->dispatch_odometer = $odometer;
            // Pickup continuity — identical to a driver dispatch: >= the last recorded mileage, else flagged.
            $this->recordOdometerFlag($ticket, 'dispatch', $odometer, $lastRecorded, OdometerContinuityService::STAGE_PICKUP, $data['odometer_note'] ?? null, $actor, array_key_exists('odometer_confirmed', $data) ? (bool) $data['odometer_confirmed'] : null);

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

            // Same contract backstop as the driver dispatch above — a recovery-truck collection is just
            // as much a maintenance visit, and it must not be the one that ends up without a contract.
            $this->openMaintenanceContract($ticket, $actor);
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

        // The arrival reading vs the pickup reading: a DRIVEN leg must show a strictly higher reading (the
        // car covered real distance under its own power) — an equal-or-lower value is a mis-keyed entry.
        // A RECOVERY leg (isRecovery() — see dispatch()'s reset, so this always reflects the CURRENT leg,
        // never a stale earlier one) is towed, not driven: it doesn't accumulate mileage, so the SAME
        // reading at arrival is legitimate — only a decrease is still impossible and blocked.
        $pickup = $ticket->dispatch_odometer !== null ? (int) $ticket->dispatch_odometer : null;
        if ($ticket->isRecovery()) {
            $this->assertNoDecrease($ticket, $actor, 'receive', $odometer, $pickup, $data['odometer_note'] ?? null, 'receive_odometer', 'pickup reading');
        } else {
            $this->assertMustIncrease($ticket, $actor, 'receive', $odometer, $pickup, $data['odometer_note'] ?? null, 'receive_odometer', 'pickup reading');
        }

        return DB::transaction(function () use ($ticket, $data, $actor, $odometer) {
            // Concurrency / double-submit guard: lock the row and re-validate the transition against the
            // freshly-read status, so a duplicate/concurrent arrival blocks then fails cleanly.
            $this->assertTransition(
                Maintenance::where('id', $ticket->id)->lockForUpdate()->firstOrFail(),
                Maintenance::WF_UNDER_REPAIR,
            );

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
            $ticket->transfer_transport_method = null; // and its transport-method choice — a FUTURE transfer asks fresh, never inherits this one

            $ticket->receive_odometer = $odometer;
            // Garage-intake continuity vs the pickup reading: the drive to the garage moves the meter
            // forward (fine), only a backward reading is a discrepancy.
            $this->recordOdometerFlag($ticket, 'receive', $odometer, $ticket->dispatch_odometer, OdometerContinuityService::STAGE_GARAGE_IN, $data['odometer_note'] ?? null, $actor, array_key_exists('odometer_confirmed', $data) ? (bool) $data['odometer_confirmed'] : null);
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

            // Stamp the ARRIVAL on every open stint at this garage. `repair_started_at` on the ticket is
            // the same moment, but it is one column re-stamped at each check-in — so the previous
            // garage's arrival is overwritten the instant this one confirms. Recording it per stint is
            // what makes "how long was it AT that garage" (as opposed to in its custody, drive included)
            // and "how long did the move take" answerable per garage rather than only for the last one.
            // Only ever fills a null: an arrival is a fact about one moment and is never re-written.
            \App\Models\MaintenanceTaskAssignment::whereIn(
                'maintenance_task_id',
                $ticket->tasks()->select('id')
            )
                ->where('vendor_id', $ticket->vendor_id)
                ->whereNull('released_at')
                ->whereNull('arrived_at')
                ->update(['arrived_at' => Carbon::now()]);

            // Fault LIST (not a prose sentence) for the single "In Workshop" event logged below — the
            // timeline card shows a "Show Faults (N)" toggle instead of spelling every fault into the
            // description, so a 6-fault ticket doesn't turn into a paragraph. Prefer the open faults on the
            // ticket's task container (current_vendor_id isn't reliably stamped pre-arrival — a fault only
            // gets routed to a garage via the supervisor's assign-dispatch step, not every dispatch path,
            // e.g. a Recovery tow never calls it) — so every non-terminal task counts, not just ones already
            // tagged to this vendor. Falls back to the inspector's findings, then the raw complaint, so the
            // event never reads as fault-less just because the container has no task rows yet.
            $faults = $ticket->tasks()
                ->whereNotIn('status', \App\Models\MaintenanceTask::TERMINAL)
                ->pluck('symptom')
                ->filter()
                ->values();
            if ($faults->isEmpty()) {
                $faults = collect($ticket->findings ?? [])->pluck('text')->filter()->values();
            }
            if ($faults->isEmpty() && $ticket->customer_complaint) {
                $faults = collect([$ticket->customer_complaint]);
            }

            // Confirm Handover: the custodian's arrival IS the delivery of the transport task — close the
            // ticket's active move so the car is no longer "In Transit" and the live position flips to
            // In Workshop. (First-arrival check-ins usually have no linked move → no-op.)
            $move = $ticket->activeMove()->first();
            if ($move) {
                $this->logistics->complete($move, $actor);
            }

            $this->cascade($ticket->vehicle_id);
            // Description stays a short, fixed two-sentence summary — everything else (faults, odometer,
            // confirmed-by) is structured `meta` the timeline card renders as chips + an expandable list,
            // never inlined into the sentence itself.
            $this->log->record($ticket, VehicleLogEvent::EVENT_UNDER_REPAIR, $actor, [
                'description' => 'Vehicle arrived at ' . ($ticket->garage ?: 'the workshop') . '. Repair work has started.',
                'meta'        => [
                    'garage'          => $ticket->garage,
                    'receive_odometer' => $odometer,
                    'garage_feedback' => $ticket->garage_feedback,
                    'faults'          => $faults->all() ?: null,
                    'confirmed_by'    => $actor->name,
                ],
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

        $locationSvc = app(FaultLocationService::class);

        $incoming = collect($findings)
            ->map(fn ($f) => [
                'text'          => $this->clean(is_array($f) ? ($f['text'] ?? null) : $f),
                'severity'      => $this->clean(is_array($f) ? ($f['severity'] ?? null) : null),
                'root_cause'    => $this->clean(is_array($f) ? ($f['root_cause'] ?? null) : null),
                'root_cause_id' => is_array($f) ? ($f['root_cause_id'] ?? null) : null,
                // WHERE + HOW MANY — the mechanic answers the same two questions the inspector does.
                // Carried on the finding itself here (rather than in a parallel `details` list as the
                // Decide step does) because this payload is already one object per finding.
                'quantity'      => $locationSvc->normalizeQuantity(is_array($f) ? ($f['quantity'] ?? 1) : 1),
                'locations'     => $locationSvc->normalizeSlugs(is_array($f) ? (array) ($f['locations'] ?? []) : []),
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

        // The same location gate the Decide step applies. A fault type that requires a place requires
        // it whoever found it: a garage-discovered scratch nobody located is exactly as unfindable as
        // an inspector-reported one, and letting the second door through would make the rule advisory.
        $needLocation = $locationSvc->findingsMissingRequiredLocation($clean->all());
        if ($needLocation) {
            throw new WorkflowTransitionException(
                'Say where on the car: ' . implode(', ', $needLocation) . '.',
                ['field' => 'findings', 'findings' => $needLocation],
            );
        }

        return DB::transaction(function () use ($ticket, $clean, $actor) {
            $vehicle = $ticket->loadMissing('vehicle')->vehicle;
            $garage  = $ticket->garage; // the workshop that DISCOVERED the fault — stamped per finding
            $added = $clean->map(function ($f) use ($actor, $vehicle, $garage) {
                [$cause, $causeId] = $this->resolveFaultCause($f['text'], $f['root_cause'], $f['root_cause_id'], $actor);

                return [
                    'text'          => $f['text'],
                    'source'        => Maintenance::FINDING_GARAGE,
                    'severity'      => $f['severity'],
                    'root_cause'    => $cause,
                    'root_cause_id' => $causeId,
                    // Carried onto the promoted fault by MaintenanceTaskService::syncFromFindings.
                    'quantity'      => $f['quantity'],
                    'locations'     => $f['locations'],
                    'by'            => $actor->name,
                    // Stamp the garage that DISCOVERED the fault (the ticket's current workshop) onto the
                    // finding, so "Garage-Identified" can name where it was found — not just that it came
                    // from a garage. Immutable on the finding even if the car later transfers garages.
                    'garage'        => $garage,
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
            // Concurrency / double-submit guard: lock the row and re-validate the transition against the
            // freshly-read status, so a duplicate/concurrent mark-ready blocks then fails cleanly.
            $this->assertTransition(
                Maintenance::where('id', $ticket->id)->lockForUpdate()->firstOrFail(),
                Maintenance::WF_REPAIR_REVIEW,
            );

            if ($odometer >= 1) {
                $ticket->return_odometer = $odometer;
                // Garage IN vs OUT continuity: when we have an intake reading, OUT must be >= IN — a positive
                // delta beyond tolerance means the garage road-tested it ('test_drive', confirm-not-block).
                // With no intake to compare, fall back to a plain forward-continuity check against the last
                // reading (pickup, else the test anchor).
                if ($ticket->receive_odometer !== null) {
                    $this->recordOdometerFlag($ticket, 'return', $odometer, $ticket->receive_odometer, OdometerContinuityService::STAGE_GARAGE_OUT, $data['odometer_note'] ?? null, $actor, array_key_exists('odometer_confirmed', $data) ? (bool) $data['odometer_confirmed'] : null);
                } else {
                    $this->recordOdometerFlag($ticket, 'return', $odometer, $ticket->dispatch_odometer ?? $ticket->test_odometer, OdometerContinuityService::STAGE_RETURN, $data['odometer_note'] ?? null, $actor, array_key_exists('odometer_confirmed', $data) ? (bool) $data['odometer_confirmed'] : null);
                }
            }
            if (array_key_exists('garage_feedback', $data)) {
                $ticket->garage_feedback = $this->clean($data['garage_feedback']);
            }
            if (array_key_exists('cost', $data) && $data['cost'] !== '' && $data['cost'] !== null) {
                $ticket->cost = $data['cost'];
            }

            // Granular time-per-fault: when the car comes back, the driver/dispatcher may attribute
            // the mechanic's ACTUAL labor time to each specific fault. The AUTHORITATIVE record is the
            // per-attempt stint ledger (FaultRepairTimeService — write-once per attempt, so a re-fix
            // round can never overwrite attempt #1's hours). The findings-JSON stamp below is kept only
            // as a display cache for the legacy closing summary; it is no longer read by analytics.
            if (! empty($data['repair_times']) && is_array($data['repair_times'])) {
                $ticket->findings = $this->applyRepairTimes($ticket->findings ?? [], $data['repair_times']);
                $this->recordAttemptLaborBatch($ticket, $data['repair_times'], $actor);
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
        // A road test is OPTIONAL (not every job needs one), so equal to the arrival reading is legitimate —
        // only a decrease is impossible and hard-blocked (see assertNoDecrease vs the stricter assertMustIncrease).
        $odometer = (int) ($data['return_odometer'] ?? 0);
        if ($odometer >= 1) {
            $prevGuard = $ticket->receive_odometer ?? $ticket->dispatch_odometer ?? $ticket->test_odometer;
            $this->assertNoDecrease($ticket, $actor, 'return', $odometer, $prevGuard !== null ? (int) $prevGuard : null, $data['odometer_note'] ?? null, 'return_odometer', 'garage-arrival reading');
        }

        return DB::transaction(function () use ($ticket, $data, $actor, $odometer) {
            if ($odometer >= 1) {
                $ticket->return_odometer = $odometer;
                $prev = $ticket->receive_odometer ?? $ticket->dispatch_odometer ?? $ticket->test_odometer;
                $this->recordOdometerFlag($ticket, 'return', $odometer, $prev !== null ? (int) $prev : null, OdometerContinuityService::STAGE_GARAGE_OUT, $data['odometer_note'] ?? null, $actor, array_key_exists('odometer_confirmed', $data) ? (bool) $data['odometer_confirmed'] : null);
            }
            $ticket->picked_up_from_garage_at = Carbon::now();
            $ticket->picked_up_from_garage_by = $actor->id;
            $ticket->save();

            // Raise the RETURN transport leg so the collecting driver reads as busy while driving the car
            // back to base (Driver Availability is sourced from LogisticsTask ONLY). arriveAtPark() closes it.
            if ($vehicle = $ticket->loadMissing('vehicle')->vehicle) {
                $this->logistics->raiseMaintenanceLeg(
                    $vehicle, 'Base / Parking', $actor->id, $ticket->id, $actor,
                    'Returning from ' . ($ticket->garage ?: 'the garage') . ' to base'
                );
            }

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
     *   • minor repair (routine) with NO routine service → straight to closed, the car is freed immediately.
     *   • major repair (critical/moderate) OR any ticket that performed a routine service (oil/battery/…)
     *     → to ready_for_reinspection; the Inspector must perform a final QA pass before the car is freed.
     *     The vehicle's service data is confirmed ONLY on that PASS (see confirmRoutineServices), so a
     *     routine service can never bypass QA. cascade() is deferred until AFTER the branch decision so the
     *     car is never briefly readable as "available" mid-transaction.
     *
     * @param array{cost?:mixed, vendor_id?:?int, notes?:?string, defer_invoice?:bool} $data
     */
    public function arriveAtPark(Maintenance $ticket, array $data, User $actor): Maintenance
    {
        $this->assertTransition($ticket, Maintenance::WF_IN_OUR_PARK);

        // Custody continuity — the driver who collected the car from the garage MUST be the same driver who
        // brings it back to base. The return leg is a single custody chain: whoever signed the car out of the
        // garage (collectFromGarage → picked_up_from_garage_by) owns it until it is physically at our park.
        // This prevents a hand-off in transit with no accountability. Only enforced when a collector is on
        // record (it always is for a HTTP-driven flow, since collect-from-garage precedes arrive-at-park);
        // legacy/imported tickets with no collector are left unblocked. A SUPERVISOR is exempt — they run
        // these legs themselves, and a real hand-off (driver collects, supervisor brings it in) is a normal
        // day, not an accountability hole: the arrival stamps who actually closed it.
        if ($ticket->picked_up_from_garage_by !== null
            && (int) $ticket->picked_up_from_garage_by !== (int) $actor->id
            && ! $this->maySupersedeDriver($actor)) {
            $collector = $ticket->loadMissing('pickedUpFromGarageBy')->pickedUpFromGarageBy;
            throw new WorkflowTransitionException(
                'This car was collected from the garage by ' . ($collector?->name ?: 'another driver')
                . '. The same person who collected it must complete the arrival at our park.',
                [
                    'field'                    => 'actor',
                    'picked_up_from_garage_by' => (int) $ticket->picked_up_from_garage_by,
                    'actor_id'                 => (int) $actor->id,
                ]
            );
        }

        // Arrival-at-park odometer (mandatory — validated required at the controller edge) — the reading the
        // moment the car is physically back at base, and the at-base anchor the final QA sign-off's strict
        // ±5 km cap compares against. The return leg (garage → our park) is a DRIVEN trip — the car covered
        // real distance getting back — so this reading must be strictly HIGHER than the garage-OUT reading
        // (equal is impossible: a driven car can't arrive on the same odometer it left on). Uses the same
        // must-increase gate as the driven garage arrival (markUnderRepair), not the softer no-decrease one.
        // Guard runs OUTSIDE the transaction (it may throw + log a block event before any state changes),
        // mirroring collectFromGarage. The >= 1 guard is kept defensively for any non-HTTP caller.
        $odometer = (int) ($data['park_odometer'] ?? 0);
        if ($odometer >= 1) {
            $prevGuard = $ticket->return_odometer ?? $ticket->receive_odometer ?? $ticket->dispatch_odometer ?? $ticket->test_odometer;
            $prev = $prevGuard !== null ? (int) $prevGuard : null;
            $this->assertMustIncrease(
                $ticket, $actor, 'park', $odometer, $prev, $data['odometer_note'] ?? null, 'park_odometer', 'garage departure reading',
                $prev !== null
                    ? 'Arrival odometer (' . number_format($odometer) . ' km) must be greater than the garage departure reading ('
                        . number_format($prev) . ' km) — the vehicle must have travelled from the garage to our parking.'
                    : null
            );
        }

        return DB::transaction(function () use ($ticket, $data, $actor, $odometer) {
            $ticket->park_arrived_at = Carbon::now();
            $ticket->park_arrived_by = $actor->id;
            $ticket->workflow_status = Maintenance::WF_IN_OUR_PARK;

            if ($odometer >= 1) {
                $ticket->park_odometer = $odometer;
                $prev = $ticket->return_odometer ?? $ticket->receive_odometer ?? $ticket->dispatch_odometer ?? $ticket->test_odometer;
                $this->recordOdometerFlag($ticket, 'park', $odometer, $prev !== null ? (int) $prev : null, OdometerContinuityService::STAGE_RETURN, $data['odometer_note'] ?? null, $actor, array_key_exists('odometer_confirmed', $data) ? (bool) $data['odometer_confirmed'] : null);
            }

            $ticket->save();

            // The driver's arrival at base IS the delivery of the return transport leg — close the ticket's
            // active move so the collecting driver reads as available again (Driver Availability = open
            // LogisticsTask only) and the live position stops reading "In Transit".
            $move = $ticket->activeMove()->first();
            if ($move) {
                $this->logistics->complete($move, $actor);
            }

            // Forward-only heal of the car's canonical mileage with the arrival reading (mirrors the other
            // capture points).
            if ($odometer >= 1 && ($vehicle = $ticket->loadMissing('vehicle')->vehicle)) {
                $this->applyTestOdometer($vehicle, $odometer);
            }

            $this->log->record($ticket, VehicleLogEvent::EVENT_READY, $actor, [
                'description' => 'Arrived back at our park (by ' . $actor->name . ')',
                'meta'        => ['garage' => $ticket->garage, 'odometer' => $odometer >= 1 ? $odometer : null],
            ]);

            if (! $ticket->isMajorRepair() && ! $this->needsServiceReinspection($ticket) && ! $this->needsQualityVerdict($ticket)) {
                // Minor repair (routine) that performed NO routine service and NO garage repair work —
                // nothing to confirm to the vehicle and nothing for QC to judge, so auto-close straight
                // away; close() owns the cascade that frees the car and the closing summary/notifications.
                return $this->close($ticket, $data, $actor);
            }

            // Major repair (critical/moderate) OR a routine service (oil/battery/…) — do NOT free the car
            // yet, and do NOT touch the vehicle's service data. Route to the final QA
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
        // Is this close a genuine RE-INSPECTION PASS? Only a sign-off coming off ready_for_reinspection is
        // the QC pass that may write the vehicle's service data (oil/battery anchors, history, reminders).
        // A minor no-service ticket auto-closing from in_our_park is NOT a pass and must never touch it.
        $fromReinspection = $ticket->workflow_status === Maintenance::WF_READY_REINSPECTION;

        // Final re-inspection odometer (optional) — the QC reading the inspector captures at sign-off, when
        // the car is physically back for the pass check. Recorded BEFORE the defer-invoice branch so BOTH
        // the deferred and full-close paths persist it (the PASS branch defaults to deferred). Runs the
        // continuity check vs the last recorded reading and heals the car's canonical mileage forward; the
        // operator's >10 km-gap note rides along on the stored flag. Stored under the 'reinspect' flag key —
        // it never overwrites the garage-OUT return_odometer captured at mark-ready.
        $reinspectOdo = isset($data['final_odometer']) && is_numeric($data['final_odometer']) ? (int) $data['final_odometer'] : null;
        if ($reinspectOdo !== null && $reinspectOdo > 0) {
            // The final QA sign-off is an at-OUR-PARK spot check: once the car is physically back at base
            // (the park-arrival reading), it must NOT accumulate more than the ±5 km buffer before it's
            // signed off — a bigger jump is an unlogged drive or a typo, so it's strictly capped. That cap
            // is only valid against an at-base anchor: WHEN we have the park-arrival reading we compare to
            // it strictly; WITHOUT it (legacy tickets / skipped capture) we can't tell a typo from the
            // legitimate garage→base drive, so we fall back to the lenient no-decrease guard vs the last
            // recorded reading.
            $strict = $ticket->park_odometer !== null;
            $prev   = $strict
                ? (int) $ticket->park_odometer
                : ($ticket->return_odometer ?? $ticket->receive_odometer ?? $ticket->dispatch_odometer ?? $ticket->test_odometer);
            $prevInt = $prev !== null ? (int) $prev : null;
            if ($strict) {
                $this->assertStrictMatch($ticket, $actor, 'reinspect', $reinspectOdo, $prevInt, OdometerContinuityService::STAGE_REINSPECT, $data['odometer_note'] ?? null, 'final_odometer');
            } else {
                $this->assertNoDecrease($ticket, $actor, 'reinspect', $reinspectOdo, $prevInt, $data['odometer_note'] ?? null, 'final_odometer', 'last recorded reading');
            }
            $ticket->reinspect_odometer = $reinspectOdo; // its own column → a distinct row in the mileage timeline
            $this->recordOdometerFlag($ticket, 'reinspect', $reinspectOdo, $prevInt, $strict ? OdometerContinuityService::STAGE_REINSPECT : OdometerContinuityService::STAGE_RETURN, $data['odometer_note'] ?? null, $actor, array_key_exists('odometer_confirmed', $data) ? (bool) $data['odometer_confirmed'] : null);
            if ($vehicle = $ticket->loadMissing('vehicle')->vehicle) {
                $this->applyTestOdometer($vehicle, $reinspectOdo); // forward-only heal, mirrors the other capture points
            }
        }

        // Deferred-invoice decoupling — the sign-off passed but the paper invoice isn't ready. The car
        // STILL returns to service; the ticket parks in awaiting_invoice for the tracker + 3-day SLA to chase.
        if (! empty($data['defer_invoice'])) {
            return $this->deferInvoice($ticket, $data, $actor, $fromReinspection);
        }

        $this->assertTransition($ticket, Maintenance::WF_CLOSED);

        // Apply any closing values before the guardrail check.
        if (array_key_exists('cost', $data) && $data['cost'] !== '' && $data['cost'] !== null) {
            // The SAME rule as recordCost, from the same method — a closing screen is not a licence to
            // type over money that invoices already account for.
            $this->guardTypedCost($ticket);
            $ticket->cost = $data['cost'];
        }
        if (! empty($data['vendor_id'])) {
            $ticket->vendor_id = (int) $data['vendor_id'];
        }

        // Cost is intentionally NOT gated here (deferred-cost decoupling) — only the garage is, and only for
        // an in-shop repair. An ON-SITE (mobile) job never reaches a garage, so it carries no vendor_id.
        if (! $ticket->isOnSite() && ! $ticket->vendor_id) {
            throw new WorkflowTransitionException('A garage (vendor) is required before closing the ticket.', [
                'field' => 'vendor_id',
            ]);
        }

        // CLOSED must mean "financially complete" — otherwise the state says the repair is finished while
        // its money still cannot be traced to a document. But the car is physically back, so blocking the
        // operational close would only teach people to work around it. The workflow already has the right
        // answer for that: AWAITING_INVOICE, the deferred-invoice lane. So a ticket whose money is not yet
        // documented does all of its operational closing work and lands there instead of CLOSED.
        $financial   = app(FinancialCompletenessService::class)->check($ticket);
        $targetState = $financial['complete'] ? Maintenance::WF_CLOSED : Maintenance::WF_AWAITING_INVOICE;

        // If that lane is not reachable from here, refusing is the honest outcome — never a silent close.
        if (! $financial['complete'] && ! $this->canTransition($ticket, Maintenance::WF_AWAITING_INVOICE)) {
            throw new WorkflowTransitionException(
                'This ticket cannot be closed yet. ' . app(FinancialCompletenessService::class)->refusalMessage($financial),
                ['field' => 'financial', 'blockers' => $financial['blockers']],
            );
        }

        return DB::transaction(function () use ($ticket, $data, $actor, $fromReinspection, $targetState, $financial) {
            // Concurrency / double-submit guard: take the ticket's row lock and RE-VALIDATE the close
            // transition against the freshly-read status INSIDE the transaction. Two people (or a
            // double-click) closing the same ticket previously BOTH ran confirmRoutineServices — double
            // oil/battery anchors, duplicated service reminders + history, two CLOSED events. The second
            // caller now blocks here until the first commits, then fails this guard cleanly.
            $this->assertTransition(
                Maintenance::where('id', $ticket->id)->lockForUpdate()->firstOrFail(),
                $targetState,
            );

            // Car came back → 'IN' records the return and the cascade frees it. The inspector may
            // pass the date the car actually came back; if they don't, it's today.
            $ticket->event_status    = 'IN';
            $ticket->actual_in_date  = ! empty($data['actual_in_date'])
                ? Carbon::parse($data['actual_in_date'])->startOfDay()
                : Carbon::today();
            $ticket->wf_closed_by    = $actor->id;
            $ticket->wf_closed_at    = Carbon::now();
            $ticket->workflow_status = $targetState;

            // Parked in the deferred-invoice lane: stamp WHEN the wait started so the outstanding-invoice
            // trackers pick it up, exactly as deferInvoice() does.
            if ($targetState === Maintenance::WF_AWAITING_INVOICE) {
                $ticket->awaiting_invoice_since = Carbon::now();
            }

            // Auto-generate the LEGACY-FORMAT maintenance note from the ticket's structured workflow
            // data (inspector report + findings + garage notes + dates/garage/odometer), so the closed
            // visit reads like the team's old hand-typed log on every history surface — board garage-log,
            // per-vehicle Service History and the workshop-events modal all render `maintenance_notes`
            // unchanged. The inspector's optional closing remark is folded in. See composeClosingSummary().
            $ticket->maintenance_notes = $this->composeClosingSummary($ticket, $actor, $data['notes'] ?? null);

            $ticket->save();

            // Final QA passed (or the ticket closed straight from our park): the car is signed back into
            // service, so the maintenance contract this visit opened is closed here. Runs on the
            // deferred-invoice path too — the car is physically back either way, and an invoice still to
            // arrive is a finance matter, not a reason to keep the car "in maintenance" on every board.
            $this->closeMaintenanceContract($ticket, $actor);

            $this->cascade($ticket->vehicle_id);   // → available unless another movement holds it

            // Re-inspection PASS is the SINGLE source of truth for the vehicle's service data. Only a
            // sign-off coming off ready_for_reinspection confirms the routine services performed on the
            // ticket (oil/battery anchors, service history, reminders). A minor no-service ticket that
            // auto-closed from in_our_park is not a QC pass and never reaches here with $fromReinspection.
            if ($fromReinspection) {
                $this->confirmRoutineServices($ticket, $actor);
            }

            // Cost may legitimately be pending (deferred-cost decoupling) — say so rather than "AED 0".
            $costLabel = $ticket->cost !== null ? 'cost AED ' . number_format((float) $ticket->cost) : 'cost pending';

            $this->log->record($ticket, VehicleLogEvent::EVENT_CLOSED, $actor, [
                'description' => 'Re-inspected and returned to service · ' . $costLabel . ' (by ' . $actor->name . ')',
                'meta'        => ['cost' => $ticket->cost !== null ? (float) $ticket->cost : null, 'garage' => $ticket->garage],
            ]);

            $vehicle = $ticket->loadMissing('vehicle')->vehicle;

            // B4 — release the condition grounding. A car grounded Red (breakdown / critical) or
            // flagged Yellow (needs maintenance) has now passed the final re-inspection, so its
            // rent-blocking grade no longer holds. Freeing operational_status alone is NOT enough:
            // rentBlockedByCondition() keeps a Red/Yellow car out of the rental pool, so it would stay
            // unbookable until someone manually re-graded it. Clear it to Green. Orange (cosmetic,
            // still rentable) is deliberately left untouched.
            if ($vehicle && in_array($vehicle->condition_grade, ['red', 'yellow'], true)) {
                $vehicle->update([
                    'condition_grade'     => 'green',
                    'condition_note'      => 'Auto-cleared on maintenance close #' . $ticket->id . ' — passed final re-inspection.',
                    'condition_graded_at' => Carbon::now(),
                    'condition_graded_by' => $actor->name ?? (string) $actor->id,
                ]);
            }

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
     * PAUSE MAINTENANCE & RETURN TO SERVICE — an operational pause, NOT a close/cancel. The repair had
     * already started but a customer urgently needs this car, so the company temporarily interrupts the
     * work and releases the car back into service (Available) while keeping the ticket fully intact:
     *
     *   • the exact stage the ticket held is remembered in `paused_from_status` (restored verbatim on
     *     resume — nothing restarts); every finding, part, photo, note, technician assignment, odometer
     *     reading and log entry stays on the row untouched;
     *   • workflow_status → paused_returned_to_service (open, but fenced out of WF_TICKET_STATES so the canonical
     *     "in maintenance" rule no longer counts the car — it becomes rentable);
     *   • event_status → 'IN' so the operational cascade + the manual-garage rule stop reading the car as
     *     physically in the shop, freeing it (its out_date is preserved so the mileage story is intact);
     *   • the standing "owes maintenance" flag is raised on the vehicle (unless the caller already owns it),
     *     so the car surfaces on the Vehicles list + fires the "back from rental, still owes the shop"
     *     reminder when it returns.
     *
     * Idempotent + concurrency-safe (row lock + re-check): a double-submit / retry no-ops. `$flagVehicle`
     * is false when the rental-creation path already owns the deferred-maintenance flag (OperationsService),
     * true for a direct "Pause" from the ticket. `$actor` is nullable — a rental-form pull is a system
     * side-effect with no acting user.
     *
     * Enterprise Handover Workflow — `$handoverData` is OPTIONAL and additive: the automated rental-pull
     * call site (OperationsService::pauseWorkflowTicketForRental()) has no human present and never
     * supplies it, so the core pause transition below is completely unchanged when it's null. When a
     * human pauses from the ticket UI, the controller supplies the captured custody-transfer fields
     * (odometer_reading, fuel_level, exterior_condition, interior_condition, damage_findings,
     * missing_accessories, notes — plus the photo/signature record ids the controller saves best-effort
     * AFTER this call, exactly like every other odometer checkpoint's storeOdometerPhoto() convention).
     * The handover row itself is best-effort (try/catch + report()): a handover-write hiccup must never
     * block the actual pause.
     */
    public function pauseForRental(Maintenance $ticket, ?string $reason, ?User $actor, bool $flagVehicle = true, ?array $handoverData = null): Maintenance
    {
        if ($ticket->isPausedReturnedToService()) {
            return $ticket->load($this->eager()); // already paused — no-op
        }
        if (! $ticket->isPausable()) {
            throw new WorkflowTransitionException(
                'This maintenance ticket can\'t be paused from its current stage.',
                ['from' => $ticket->workflow_status]
            );
        }

        $reason = $this->clean($reason);

        return DB::transaction(function () use ($ticket, $reason, $actor, $flagVehicle, $handoverData) {
            // Row-lock + re-read so two concurrent pauses (double-click / retry) can't both apply — the
            // second sees the first's committed write and no-ops instead of re-pausing.
            $ticket = Maintenance::where('id', $ticket->id)->lockForUpdate()->firstOrFail();
            if ($ticket->isPausedReturnedToService()) {
                return $ticket->load($this->eager());
            }
            if (! $ticket->isPausable()) {
                throw new WorkflowTransitionException(
                    'This maintenance ticket can\'t be paused from its current stage.',
                    ['from' => $ticket->workflow_status]
                );
            }

            $fromStatus = $ticket->workflow_status;
            $ticket->paused_from_status = $fromStatus;   // where to resume — restored verbatim
            $ticket->paused_at          = Carbon::now();
            $ticket->paused_by          = $actor?->id;
            $ticket->paused_reason      = $reason;
            $ticket->workflow_status    = Maintenance::WF_PAUSED_RETURNED_TO_SERVICE;
            // Park the event: the car is released back into service, not in the shop. 'IN' takes it out of
            // the manual-garage "in maintenance" set; out_date is intentionally KEPT so the odometer/mileage
            // history is unbroken and resume can put it straight back to 'OUT'.
            $ticket->event_status = 'IN';
            $ticket->save(); // booted() re-stamps last_state_change_at → a fresh paused clock

            $this->cascade($ticket->vehicle_id); // → available/rented (no longer counted as maintenance)

            // Raise the standing "owes the workshop" flag unless the rental path already owns it.
            $vehicle = $ticket->loadMissing('vehicle')->vehicle;
            if ($flagVehicle && $vehicle) {
                $this->operations->flagDeferredMaintenance(
                    $vehicle,
                    $reason ?: 'Maintenance paused — car released back into service.',
                    $actor?->name
                );
            }

            $this->log->record($ticket, VehicleLogEvent::EVENT_RETURNED_TO_SERVICE, $actor, [
                'description' => 'Maintenance paused & car returned to service (was at ' . $this->stageLabel($fromStatus) . ')'
                    . ($reason ? ' · ' . $reason : '')
                    . ($actor ? ' (by ' . $actor->name . ')' : ' (rental pull)'),
                'meta' => ['paused_from' => $fromStatus, 'reason' => $reason],
            ]);

            // Tell the controllers + supervisors the car left the shop early and owes a return visit.
            $this->notifier->notifyByAnyPermission([self::NOTIFY_CONTROLLERS, self::NOTIFY_DISPATCHER], [
                'type'     => 'maint_returned_to_service',
                'category' => 'maintenance',
                'severity' => 'warning',
                'title'    => '⏸️ Maintenance paused · ' . $this->label($vehicle),
                'body'     => trim($this->label($vehicle) . ' was pulled out of the workshop and released back into service — the repair is paused at '
                    . $this->stageLabel($fromStatus) . ' and will resume when the car returns.'
                    . ($reason ? ' Reason: ' . $reason . '.' : '')),
                'url'      => $this->link($ticket),
                'key'      => 'maint_wf:' . $ticket->id . ':paused',
                'icon'     => 'pause',
                'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no, 'paused_from' => $fromStatus],
            ], $actor?->id);

            // Enterprise Handover Workflow — the pause-leg custody handover, best-effort. Never present on
            // the automated rental-pull call site.
            if ($handoverData !== null) {
                try {
                    $handover = MaintenanceHandover::create([
                        'maintenance_id'       => $ticket->id,
                        'vehicle_id'           => $ticket->vehicle_id,
                        'type'                 => MaintenanceHandover::TYPE_PAUSE,
                        'odometer_reading'     => (int) ($handoverData['odometer_reading'] ?? 0),
                        'fuel_level'           => $handoverData['fuel_level'] ?? '',
                        'exterior_condition'   => $handoverData['exterior_condition'] ?? '',
                        'interior_condition'   => $handoverData['interior_condition'] ?? '',
                        'damage_findings'      => $handoverData['damage_findings'] ?? [],
                        'missing_accessories'  => $handoverData['missing_accessories'] ?? [],
                        'notes'                => $handoverData['notes'] ?? null,
                        'actor_id'             => $actor?->id,
                        'occurred_at'          => Carbon::now(),
                        'workflow_status_snapshot' => $fromStatus,
                        'reason'               => $reason,
                    ]);
                    $ticket->last_pause_handover_id = $handover->id;
                    $ticket->save();
                } catch (\Throwable $e) {
                    report($e); // logged — the pause transition already committed
                }
            }

            return $ticket->load($this->eager());
        });
    }

    /**
     * VEHICLE PHYSICALLY RETURNED — a light checkpoint, no odometer/handover required: it just stamps
     * "the car is back" so the return handover paperwork is chased. From this moment the car is treated
     * as unavailable to rent again (OperationsService::vehicleInMaintenance() reads
     * Maintenance::isReturnedPendingHandover()) until the resume handover clears (or an incident is
     * acknowledged) — a deliberate operational-status implication, not just a label change.
     *
     * Idempotent + concurrency-safe. Only legal while the ticket is paused AND still out (isPausedOut()).
     */
    public function markVehicleReturned(Maintenance $ticket, ?User $actor, ?string $note = null): Maintenance
    {
        if (! $ticket->isPausedOut()) {
            throw new WorkflowTransitionException(
                'This ticket has no paused-and-out vehicle to mark returned.',
                ['from' => $ticket->workflow_status]
            );
        }

        $note = $this->clean($note);

        return DB::transaction(function () use ($ticket, $actor, $note) {
            $ticket = Maintenance::where('id', $ticket->id)->lockForUpdate()->firstOrFail();
            if (! $ticket->isPausedOut()) {
                return $ticket->load($this->eager()); // already marked returned by a concurrent request
            }

            $ticket->vehicle_returned_at = Carbon::now();
            $ticket->vehicle_returned_by = $actor?->id;
            $ticket->save();

            $this->cascade($ticket->vehicle_id); // → maintenance again (physically back, handover due)

            $this->log->record($ticket, VehicleLogEvent::EVENT_VEHICLE_RETURNED, $actor, [
                'description' => 'Vehicle physically returned — return handover due'
                    . ($note ? ' · ' . $note : '')
                    . ($actor ? ' (by ' . $actor->name . ')' : ''),
                'meta' => ['note' => $note],
            ]);

            $vehicle = $ticket->loadMissing('vehicle')->vehicle;
            $this->notifier->notifyByAnyPermission([self::NOTIFY_DISPATCHER, self::NOTIFY_CONTROLLERS], [
                'type'     => 'maint_vehicle_returned',
                'category' => 'maintenance',
                'severity' => 'info',
                'title'    => '🚗 Vehicle returned · ' . $this->label($vehicle),
                'body'     => trim($this->label($vehicle) . ' is physically back — complete the return handover to resume the paused repair.'),
                'url'      => $this->link($ticket),
                'key'      => 'maint_wf:' . $ticket->id . ':vehicle_returned',
                'icon'     => 'car',
                'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no],
            ], $actor?->id);

            return $ticket->load($this->eager());
        });
    }

    /**
     * RESUME MAINTENANCE — the car is back (or staff choose to send it in again): the SAME ticket
     * continues from the EXACT stage it paused at, UNLESS the mandatory return handover reveals a
     * discrepancy against the pause handover beyond the configured thresholds — in which case the
     * resume is held open (an Incident is raised) until a supervisor acknowledges it. Nothing restarts;
     * the full history is untouched either way, and the resume handover itself is ALWAYS permanently
     * saved regardless of what happens next.
     *
     * `$handoverData` is mandatory (every human-driven resume captures a full custody handover); every
     * call site is the /resume controller action — there is no automated resume.
     *
     * Idempotent + concurrency-safe. Robust to interrupted operations: if paused_from_status is somehow
     * missing it falls back to the dispatch queue (inspection_pending) so the ticket can never get stuck.
     */
    public function resumeMaintenance(Maintenance $ticket, User $actor, array $handoverData): Maintenance
    {
        if (! $ticket->isPausedReturnedToService()) {
            throw new WorkflowTransitionException(
                'This ticket isn\'t paused — there is nothing to resume.',
                ['from' => $ticket->workflow_status]
            );
        }

        return DB::transaction(function () use ($ticket, $actor, $handoverData) {
            $ticket = Maintenance::where('id', $ticket->id)->lockForUpdate()->firstOrFail();
            if (! $ticket->isPausedReturnedToService()) {
                return $ticket->load($this->eager()); // already resumed by a concurrent request
            }

            // Step 1 — the resume-leg custody handover, ALWAYS captured (best-effort write; a storage
            // hiccup here still lets the resume proceed straight to finalizing, matching the "never block
            // the transition" convention of every other checkpoint).
            $resumeHandover = null;
            try {
                $resumeHandover = MaintenanceHandover::create([
                    'maintenance_id'       => $ticket->id,
                    'vehicle_id'           => $ticket->vehicle_id,
                    'type'                 => MaintenanceHandover::TYPE_RESUME,
                    'odometer_reading'     => (int) ($handoverData['odometer_reading'] ?? 0),
                    'fuel_level'           => $handoverData['fuel_level'] ?? '',
                    'exterior_condition'   => $handoverData['exterior_condition'] ?? '',
                    'interior_condition'   => $handoverData['interior_condition'] ?? '',
                    'damage_findings'      => $handoverData['damage_findings'] ?? [],
                    'missing_accessories'  => $handoverData['missing_accessories'] ?? [],
                    'notes'                => $handoverData['notes'] ?? null,
                    'actor_id'             => $actor->id,
                    'occurred_at'          => Carbon::now(),
                    'workflow_status_snapshot' => $ticket->workflow_status,
                ]);
                $ticket->last_resume_handover_id = $resumeHandover->id;
                $ticket->save();
            } catch (\Throwable $e) {
                report($e);
            }

            // Operational hand-off to the Inspector (Abu Maroof): the driver has just completed the pickup
            // handover — the vehicle has been RECEIVED back from the customer — so it's his moment to look it
            // over. Fires here (right when the return handover is captured, before the discrepancy branch) so
            // it always lands on receipt. Reuses the handover's existing note verbatim; no new field.
            $receiveNote = $this->clean($handoverData['notes'] ?? null);
            $vehicle = $ticket->loadMissing('vehicle')->vehicle;
            $this->notifier->notifyByPermission(self::NOTIFY_INSPECTOR, [
                'type'     => 'maint_vehicle_received',
                'category' => 'maintenance',
                'severity' => 'info',
                'title'    => '🚗 Received from customer · ' . $this->label($vehicle),
                'body'     => trim($this->label($vehicle) . ' has been received back from the customer'
                                . ($actor ? ' by ' . $actor->name : '') . '.'
                                . ($receiveNote ? ' Handover note: “' . $receiveNote . '”.' : '')
                                . ' Inspect it as it re-enters the workshop.'),
                'url'      => $this->link($ticket),
                'key'      => 'maint_wf:' . $ticket->id . ':vehicle_received:' . Carbon::now()->timestamp,
                'icon'     => 'truck',
                'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no, 'note' => $receiveNote],
            ], $actor->id);

            // Step 2/3 — compare against the pause-leg handover, IF one exists (a legacy ticket paused
            // before this feature shipped has none — skip comparison/incident logic entirely and finalize).
            $pauseHandover = $ticket->lastPauseHandover;

            if ($pauseHandover && $resumeHandover) {
                $comparison = app(HandoverComparisonService::class)->compare($pauseHandover, $resumeHandover);

                $comparisonRow = null;
                try {
                    $comparisonRow = MaintenanceHandoverComparison::create([
                        'maintenance_id'       => $ticket->id,
                        'pause_handover_id'    => $pauseHandover->id,
                        'resume_handover_id'   => $resumeHandover->id,
                        'mileage_delta'        => $comparison['mileage_delta'],
                        'fuel_delta'           => $comparison['fuel_delta'],
                        'new_damages'          => $comparison['new_damages'],
                        'missing_accessories'  => $comparison['missing_accessories'],
                        'condition_changes'    => $comparison['condition_changes'],
                        'exceeds_threshold'    => $comparison['exceeds_threshold'],
                        'threshold_breaches'   => $comparison['threshold_breaches'],
                        'generated_at'         => Carbon::now(),
                    ]);
                } catch (\Throwable $e) {
                    report($e);
                }

                if ($comparison['exceeds_threshold']) {
                    try {
                        $incident = MaintenanceIncident::create([
                            'maintenance_id' => $ticket->id,
                            'comparison_id'  => $comparisonRow?->id,
                            'type'           => MaintenanceIncident::TYPE_HANDOVER_DISCREPANCY,
                            'severity'       => 'moderate',
                            'description'    => $this->composeIncidentDescription($comparison['threshold_breaches']),
                            'status'         => MaintenanceIncident::STATUS_OPEN,
                        ]);
                        $ticket->active_incident_id = $incident->id;
                        $ticket->save();

                        $this->log->record($ticket, VehicleLogEvent::EVENT_HANDOVER_INCIDENT, $actor, [
                            'description' => 'Return handover flagged a discrepancy vs the pause handover — resume held pending acknowledgement.',
                            'meta' => ['comparison_id' => $comparisonRow?->id, 'breaches' => $comparison['threshold_breaches']],
                        ]);

                        $vehicle = $ticket->loadMissing('vehicle')->vehicle;
                        $this->notifier->notifyByAnyPermission([self::NOTIFY_DISPATCHER, self::NOTIFY_CONTROLLERS], [
                            'type'     => 'maint_handover_incident',
                            'category' => 'maintenance',
                            'severity' => 'warning',
                            'title'    => '⚠️ Handover discrepancy · ' . $this->label($vehicle),
                            'body'     => trim($this->label($vehicle) . ' returned with a flagged discrepancy — resume is on hold until a supervisor acknowledges it.'),
                            'url'      => $this->link($ticket),
                            'key'      => 'maint_wf:' . $ticket->id . ':handover_incident',
                            'icon'     => 'alert-triangle',
                            'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no],
                        ], $actor->id);
                    } catch (\Throwable $e) {
                        report($e);
                    }

                    // The resume handover is already permanently saved above — return WITHOUT finalizing
                    // the stage transition. workflow_status stays paused_returned_to_service.
                    return $ticket->load($this->eager());
                }
            }

            return $this->finalizeResume($ticket, $actor);
        });
    }

    /**
     * Shared finalize step for a resume that is NOT (or no longer) gated by an incident — used by both
     * resumeMaintenance()'s clean path and acknowledgeIncident() (which finalizes the ALREADY-captured
     * resume handover, no re-submission needed). Restores paused_from_status verbatim, puts event_status
     * back to what that stage implies, clears the pause bookkeeping EXCEPT paused_at/paused_reason (kept
     * permanently as the historical stamp of the most recent pause — event-sourced, see
     * Maintenance::WF_PAUSED_RETURNED_TO_SERVICE), clears the vehicle-returned + active-incident pointers,
     * and re-derives the car's operational status (→ maintenance again).
     */
    private function finalizeResume(Maintenance $ticket, ?User $actor): Maintenance
    {
        // Restore the exact stage. Guard against a lost anchor (never expected) so it can't get stuck.
        $restore = in_array($ticket->paused_from_status, Maintenance::PAUSABLE_STATES, true)
            ? $ticket->paused_from_status
            : Maintenance::WF_INSPECTION_PENDING;

        $ticket->workflow_status     = $restore;
        $ticket->event_status        = in_array($restore, Maintenance::WF_PHYSICALLY_OUT_STATES, true) ? 'OUT' : 'IN';
        $ticket->paused_from_status  = null;
        $ticket->paused_by           = null;
        // paused_at / paused_reason are DELIBERATELY KEPT — they become the permanent historical stamp of
        // the most recent pause once resumed, not cleared bookkeeping.
        $ticket->vehicle_returned_at = null;
        $ticket->vehicle_returned_by = null;
        $ticket->active_incident_id  = null;
        $ticket->save(); // booted() re-stamps last_state_change_at → the resumed stage's clock restarts

        $this->cascade($ticket->vehicle_id); // → maintenance (the car is back in the workflow)

        // The debt is settled — the car is (back) in the workshop pipeline, so clear the standing flag.
        $vehicle = $ticket->loadMissing('vehicle')->vehicle;
        if ($vehicle) {
            $this->operations->resolveDeferredMaintenance($vehicle);
        }

        $this->log->record($ticket, VehicleLogEvent::EVENT_RESUMED, $actor, [
            'description' => 'Maintenance resumed — continuing at ' . $this->stageLabel($restore)
                . ($actor ? ' (by ' . $actor->name . ')' : ''),
            'meta' => ['resumed_to' => $restore],
        ]);

        $this->notifier->notifyByAnyPermission([self::NOTIFY_CONTROLLERS, self::NOTIFY_DISPATCHER], [
            'type'     => 'maint_resumed',
            'category' => 'maintenance',
            'severity' => 'info',
            'title'    => '▶️ Maintenance resumed · ' . $this->label($vehicle),
            'body'     => trim($this->label($vehicle) . ' is back — the paused repair continues at '
                . $this->stageLabel($restore) . '.'),
            'url'      => $this->link($ticket),
            'key'      => 'maint_wf:' . $ticket->id . ':resumed',
            'icon'     => 'play',
            'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no, 'resumed_to' => $restore],
        ], $actor?->id);

        return $ticket->load($this->eager());
    }

    /**
     * ACKNOWLEDGE INCIDENT — a supervisor (maintenance.manage) reviews the flagged discrepancy and clears
     * it, finalizing the resume that was held pending acknowledgement. No re-capture: the resume handover
     * was already permanently saved in resumeMaintenance()'s step 1 regardless of the gate.
     *
     * Idempotent: acknowledging an already-acknowledged incident is a no-op.
     */
    public function acknowledgeIncident(MaintenanceIncident $incident, ?User $actor, ?string $note = null): Maintenance
    {
        $note = $this->clean($note);

        return DB::transaction(function () use ($incident, $actor, $note) {
            $incident = MaintenanceIncident::where('id', $incident->id)->lockForUpdate()->firstOrFail();
            $ticket   = Maintenance::where('id', $incident->maintenance_id)->lockForUpdate()->firstOrFail();

            if ($incident->status === MaintenanceIncident::STATUS_ACKNOWLEDGED) {
                return $ticket->load($this->eager()); // already cleared — no-op
            }

            $incident->acknowledged_by      = $actor?->id;
            $incident->acknowledged_at      = Carbon::now();
            $incident->acknowledgement_note = $note;
            $incident->status               = MaintenanceIncident::STATUS_ACKNOWLEDGED;
            $incident->save();

            $this->log->record($ticket, VehicleLogEvent::EVENT_INCIDENT_ACKNOWLEDGED, $actor, [
                'description' => 'Handover discrepancy acknowledged — resume finalized'
                    . ($note ? ' · ' . $note : '')
                    . ($actor ? ' (by ' . $actor->name . ')' : ''),
                'meta' => ['incident_id' => $incident->id],
            ]);

            return $this->finalizeResume($ticket, $actor);
        });
    }

    /**
     * TEMPORARILY RELEASE VEHICLE — the car physically leaves the workshop mid-repair (a road test, a
     * customer test/delivery, an external inspection, storage, …) while the ticket stays EXACTLY where it
     * is. This is deliberately NOT a pause: workflow_status is untouched (the car is still counted as
     * in-maintenance and stays out of the rentable pool), the ticket is never closed/completed, and the
     * repair simply continues when the car returns. All that changes is a vehicle-level overlay
     * (active_temporary_release_id) plus an immutable out-leg log row capturing WHO/WHY/WHEN + odometer_out.
     *
     * `$data` = ['reason', 'reason_note', 'taken_by', 'odometer_out']. Idempotent + concurrency-safe (row
     * lock + re-check): a double-submit no-ops on the already-out ticket.
     */
    public function temporarilyReleaseVehicle(Maintenance $ticket, array $data, User $actor): Maintenance
    {
        if (! $ticket->isTempReleasable()) {
            throw new WorkflowTransitionException(
                $ticket->isTemporarilyReleased()
                    ? 'This vehicle is already temporarily released — record its return first.'
                    : 'This maintenance ticket can\'t release the vehicle from its current stage.',
                ['from' => $ticket->workflow_status]
            );
        }

        return DB::transaction(function () use ($ticket, $data, $actor) {
            $ticket = Maintenance::where('id', $ticket->id)->lockForUpdate()->firstOrFail();
            if (! $ticket->isTempReleasable()) {
                return $ticket->load($this->eager()); // a concurrent request already released it
            }

            $release = MaintenanceTemporaryRelease::create([
                'maintenance_id'           => $ticket->id,
                'vehicle_id'               => $ticket->vehicle_id,
                'reason'                   => $data['reason'],
                'reason_note'              => $this->clean($data['reason_note'] ?? null),
                'taken_by'                 => trim((string) $data['taken_by']),
                'released_by'              => $actor->id,
                'released_at'              => Carbon::now(),
                'odometer_out'             => (int) $data['odometer_out'],
                'workflow_status_snapshot' => $ticket->workflow_status,
            ]);

            $ticket->active_temporary_release_id = $release->id;
            $ticket->save(); // workflow_status intentionally UNCHANGED — no cascade, the car stays in-maintenance

            $this->log->record($ticket, VehicleLogEvent::EVENT_TEMP_RELEASED, $actor, [
                'description' => 'Vehicle temporarily released from the workshop (' . $release->reasonLabel() . ')'
                    . ' — taken by ' . $release->taken_by
                    . ' at ' . number_format($release->odometer_out) . ' km'
                    . ($release->reason_note ? ' · ' . $release->reason_note : '')
                    . ' (by ' . $actor->name . ')',
                'meta' => [
                    'temporary_release_id' => $release->id,
                    'reason'               => $release->reason,
                    'taken_by'             => $release->taken_by,
                    'odometer_out'         => $release->odometer_out,
                    'from_status'          => $ticket->workflow_status,
                ],
            ]);

            $vehicle = $ticket->loadMissing('vehicle')->vehicle;
            $this->notifier->notifyByAnyPermission([self::NOTIFY_CONTROLLERS, self::NOTIFY_DISPATCHER], [
                'type'     => 'maint_temp_released',
                'category' => 'maintenance',
                'severity' => 'info',
                'title'    => '🚗 Temporarily released · ' . $this->label($vehicle),
                'body'     => trim($this->label($vehicle) . ' was taken out of the workshop for ' . $release->reasonLabel()
                    . ' (by ' . $release->taken_by . ') — the repair stays open and continues when it returns.'),
                'url'      => $this->link($ticket),
                'key'      => 'maint_wf:' . $ticket->id . ':temp_released:' . $release->id,
                'icon'     => 'car',
                'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no, 'reason' => $release->reason],
            ], $actor->id);

            return $ticket->load($this->eager());
        });
    }

    /**
     * RETURN TEMPORARILY-RELEASED VEHICLE — the car is back at the workshop; close the open release leg
     * (odometer_in + distance) and clear the overlay so the ticket presents at its (unchanged) stage
     * again. The distance driven while out is recorded and treated as VALID travel: the authoritative
     * vehicle mileage is advanced to odometer_in so the ongoing repair's remaining odometer checkpoints
     * compare against the post-release reading and never mis-flag the trip as a discrepancy.
     *
     * `$data` = ['odometer_in', 'return_note']. Idempotent + concurrency-safe. Guards a backward reading
     * (an odometer can't return lower than it left).
     */
    public function returnTemporarilyReleasedVehicle(Maintenance $ticket, array $data, User $actor): Maintenance
    {
        if (! $ticket->isTemporarilyReleased()) {
            throw new WorkflowTransitionException(
                'This vehicle isn\'t temporarily released — there is nothing to bring back.',
                ['from' => $ticket->workflow_status]
            );
        }

        $odometerIn = (int) $data['odometer_in'];

        return DB::transaction(function () use ($ticket, $data, $actor, $odometerIn) {
            $ticket = Maintenance::where('id', $ticket->id)->lockForUpdate()->firstOrFail();
            if (! $ticket->isTemporarilyReleased()) {
                return $ticket->load($this->eager()); // a concurrent request already brought it back
            }

            $release = MaintenanceTemporaryRelease::where('id', $ticket->active_temporary_release_id)
                ->lockForUpdate()->first();

            // Defensive: the pointer is set but the row is gone (never expected) — just clear the overlay.
            if (! $release) {
                $ticket->active_temporary_release_id = null;
                $ticket->save();
                return $ticket->load($this->eager());
            }

            // An odometer can't come back LOWER than it left — reject a backward reading outright.
            if ($odometerIn < $release->odometer_out) {
                throw new WorkflowTransitionException(
                    'The return reading (' . number_format($odometerIn) . ' km) can\'t be lower than the '
                    . 'out reading (' . number_format($release->odometer_out) . ' km) — re-check the dial.',
                    ['field' => 'return_odometer']
                );
            }

            $distance = $odometerIn - $release->odometer_out;

            $release->returned_at  = Carbon::now();
            $release->returned_by  = $actor->id;
            $release->odometer_in  = $odometerIn;
            $release->distance_km  = $distance;
            $release->return_note  = $this->clean($data['return_note'] ?? null);
            $release->save();

            $ticket->active_temporary_release_id = null;
            $ticket->save();

            // Absorb the distance driven while out as VALID travel — advance the authoritative mileage so
            // the repair's remaining odometer checkpoints anchor on the post-trip reading (never a
            // backward/discrepancy flag). Never move it backwards.
            $vehicle = $ticket->loadMissing('vehicle')->vehicle;
            if ($vehicle && ($vehicle->odometer === null || $odometerIn > (int) $vehicle->odometer)) {
                $vehicle->odometer = $odometerIn;
                $vehicle->save();
            }

            $this->log->record($ticket, VehicleLogEvent::EVENT_TEMP_RETURNED, $actor, [
                'description' => 'Vehicle returned to the workshop from a temporary release (' . $release->reasonLabel() . ')'
                    . ' at ' . number_format($odometerIn) . ' km · ' . number_format($distance) . ' km driven while out'
                    . ($release->return_note ? ' · ' . $release->return_note : '')
                    . ' (by ' . $actor->name . ')',
                'meta' => [
                    'temporary_release_id' => $release->id,
                    'odometer_out'         => $release->odometer_out,
                    'odometer_in'          => $odometerIn,
                    'distance_km'          => $distance,
                ],
            ]);

            $this->notifier->notifyByAnyPermission([self::NOTIFY_CONTROLLERS, self::NOTIFY_DISPATCHER], [
                'type'     => 'maint_temp_returned',
                'category' => 'maintenance',
                'severity' => 'info',
                'title'    => '🔧 Back at the workshop · ' . $this->label($vehicle),
                'body'     => trim($this->label($vehicle) . ' is back from its temporary release — '
                    . number_format($distance) . ' km driven while out. The repair continues.'),
                'url'      => $this->link($ticket),
                'key'      => 'maint_wf:' . $ticket->id . ':temp_returned:' . $release->id,
                'icon'     => 'wrench',
                'meta'     => ['ticket_id' => $ticket->id, 'plate' => $vehicle?->plate_no, 'distance_km' => $distance],
            ], $actor->id);

            return $ticket->load($this->eager());
        });
    }

    /** Auto-compose an Incident's description from the comparison's threshold breaches. */
    private function composeIncidentDescription(array $breaches): string
    {
        if (empty($breaches)) {
            return 'Handover comparison flagged a discrepancy.';
        }
        return implode(' ', array_filter(array_map(fn ($b) => $b['detail'] ?? null, $breaches)));
    }

    /** Human label for a workflow stage, for pause/resume audit copy. Falls back to the raw key. */
    private function stageLabel(?string $status): string
    {
        return match ($status) {
            Maintenance::WF_INSPECTION_PENDING  => 'Awaiting Dispatch',
            Maintenance::WF_AWAITING_DISPATCH   => 'Awaiting Pickup',
            Maintenance::WF_IN_TRANSIT          => 'In Transit',
            Maintenance::WF_UNDER_REPAIR        => 'In Workshop',
            Maintenance::WF_REPAIR_REVIEW       => 'Repair Review',
            Maintenance::WF_READY_FOR_PICKUP    => 'Ready for Pickup',
            Maintenance::WF_READY_REINSPECTION  => 'Final QA Re-inspection',
            Maintenance::WF_REINSPECTION_FAILED => 'Re-inspection Failed',
            default                             => $status ? str_replace('_', ' ', $status) : 'its previous stage',
        };
    }

    /**
     * Deferred-invoice sign-off — the car passed re-inspection/QA and RETURNS TO SERVICE now, but the
     * invoice isn't ready. Operationally this behaves like close() (event 'IN', cascade frees the car,
     * the legacy closing note is composed) EXCEPT the ticket parks in `awaiting_invoice` instead of
     * `closed`, stamping the SLA clock. It's picked up by the /invoices/pending-submission tracker and
     * the daily 3-day overdue scan; entering the invoice (portal/line-items) or "mark received" closes it.
     */
    public function deferInvoice(Maintenance $ticket, array $data, User $actor, bool $fromReinspection = false): Maintenance
    {
        $this->assertTransition($ticket, Maintenance::WF_AWAITING_INVOICE);

        if (! empty($data['vendor_id'])) {
            $ticket->vendor_id = (int) $data['vendor_id'];
        }
        // Only an in-shop repair is gated on a garage. An ON-SITE (mobile) job — e.g. a routine oil change
        // done in our park — never reaches a garage and carries no vendor_id, so it signs off without one.
        // Mirrors the identical exemption in close(); deferInvoice is the default PASS path, so without this
        // an on-site ticket at Final inspection is wrongly blocked by "a garage is required".
        if (! $ticket->isOnSite() && ! $ticket->vendor_id) {
            throw new WorkflowTransitionException('A garage (vendor) is required before signing off the repair.', [
                'field' => 'vendor_id',
            ]);
        }

        return DB::transaction(function () use ($ticket, $data, $actor, $fromReinspection) {
            // Concurrency / double-submit guard (same as close()): lock the row and re-validate the
            // transition against the freshly-read status, so a double-click / concurrent sign-off can't
            // run confirmRoutineServices twice (double service anchors + reminders).
            $this->assertTransition(
                Maintenance::where('id', $ticket->id)->lockForUpdate()->firstOrFail(),
                Maintenance::WF_AWAITING_INVOICE,
            );

            // Car is physically back and freed for service — identical to close(), minus the financial close.
            $ticket->event_status    = 'IN';
            $ticket->actual_in_date  = ! empty($data['actual_in_date'])
                ? Carbon::parse($data['actual_in_date'])->startOfDay()
                : Carbon::today();
            $ticket->workflow_status = Maintenance::WF_AWAITING_INVOICE;
            $ticket->awaiting_invoice_since = Carbon::now();
            $ticket->maintenance_notes = $this->composeClosingSummary($ticket, $actor, $data['notes'] ?? null);
            $ticket->save();

            // Same as close(): the car is physically back, so the visit's contract closes with it. A
            // pending invoice is a finance matter and must not keep the car booked into the workshop.
            $this->closeMaintenanceContract($ticket, $actor);

            $this->cascade($ticket->vehicle_id);   // → back in service (awaiting_invoice is NOT WF_TICKET_STATES)

            // Deferring the invoice is still a PASSED final re-inspection — the car returned to service, so
            // this is the moment the routine services are confirmed to the vehicle. The later invoice-close
            // (finalizeInvoice) is a purely financial step and must NOT re-touch the service data.
            if ($fromReinspection) {
                $this->confirmRoutineServices($ticket, $actor);
            }

            $this->log->record($ticket, VehicleLogEvent::EVENT_AWAITING_INVOICE, $actor, [
                'description' => 'Re-inspected and returned to service — invoice pending (by ' . $actor->name . ')',
                'meta'        => ['garage' => $ticket->garage, 'awaiting_invoice_since' => $ticket->awaiting_invoice_since?->toIso8601String()],
            ]);

            $vehicle = $ticket->loadMissing('vehicle')->vehicle;

            // B4 — release the condition grounding, exactly as close() does. Signing off with the
            // invoice deferred is still a PASSED final re-inspection: the car is back in service, so a
            // Red (breakdown/critical) or Yellow grade no longer holds. Without this the car's
            // operational_status is freed but rentBlockedByCondition() keeps it out of the rental pool
            // until a manual re-grade — the exact gap that left awaiting-invoice cars unbookable.
            if ($vehicle && in_array($vehicle->condition_grade, ['red', 'yellow'], true)) {
                $vehicle->update([
                    'condition_grade'     => 'green',
                    'condition_note'      => 'Auto-cleared on sign-off (invoice pending) #' . $ticket->id . ' — passed final re-inspection.',
                    'condition_graded_at' => Carbon::now(),
                    'condition_graded_by' => $actor->name ?? (string) $actor->id,
                ]);
            }

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
     * "Mark as Serviced" — complete the hands-on work of an ON-SITE (mobile) ticket. The mobile job is done
     * where the car is parked (NO garage dispatch, NO transit), but it does NOT close the ticket and it does
     * NOT touch the vehicle's service data. Instead it routes the ticket to the final QA re-inspection
     * (ready_for_reinspection) — exactly like an in-shop repair — so the service anchors (oil/battery),
     * history and reminders are confirmed ONLY on a re-inspection PASS (see close → confirmRoutineServices).
     * This closes the loophole where an on-site oil/battery service could update the vehicle bypassing QA.
     *
     * A mobile job carries no garage vendor, so close() relaxes its vendor gate for on-site tickets.
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
        $this->assertTransition($ticket, Maintenance::WF_READY_REINSPECTION);

        // A mobile vendor is OPTIONAL for on-site work (there may be no garage at all). Record the cost
        // if it was captured on the spot; otherwise it stays deferred like every other ticket.
        if (array_key_exists('cost', $data) && $data['cost'] !== '' && $data['cost'] !== null) {
            $ticket->cost = $data['cost'];
        }
        if (! empty($data['vendor_id'])) {
            $ticket->vendor_id = (int) $data['vendor_id'];
        }

        return DB::transaction(function () use ($ticket, $data, $actor) {
            // The car is physically present (never left), and the mobile work is finished — but it is NOT
            // roadworthy-confirmed until the inspector's QA pass. Route to the re-inspection stage; the
            // vehicle's service data stays untouched until that PASS closes the ticket.
            $ticket->event_status    = 'IN';
            $ticket->repair_location = Maintenance::REPAIR_ON_SITE; // survives the status change → close() knows it's on-site
            $ticket->workflow_status = Maintenance::WF_READY_REINSPECTION;
            // Preserve the on-site mechanic's notes (the controller has already folded the mobile vendor
            // name in) as garage feedback, so they carry into the closing summary composed at the PASS.
            if ($note = $this->clean($data['notes'] ?? null)) {
                $ticket->garage_feedback = trim(($ticket->garage_feedback ? $ticket->garage_feedback . "\n" : '') . $note);
            }
            $ticket->save();

            // ready_for_reinspection ∈ WF_TICKET_STATES → the car reads "in maintenance" through the QA
            // pass. Reconcile so that state settles.
            $this->cascade($ticket->vehicle_id);

            $vehicle   = $ticket->loadMissing('vehicle')->vehicle;
            $costLabel = $ticket->cost !== null ? 'cost AED ' . number_format((float) $ticket->cost) : 'cost pending';

            $this->log->record($ticket, VehicleLogEvent::EVENT_READY, $actor, [
                'description' => 'On-site service completed · ' . $costLabel . ' — pending final QA re-inspection (by ' . $actor->name . ')',
                'meta'        => ['cost' => $ticket->cost !== null ? (float) $ticket->cost : null, 'repair_location' => Maintenance::REPAIR_ON_SITE],
            ]);

            // Ask the Inspector for the final QA pass — the single point that returns the car to service and
            // confirms any routine service to the vehicle record.
            $this->notifier->notifyByPermission(self::NOTIFY_INSPECTOR, [
                'type'     => 'maint_ready_reinspect',
                'category' => 'maintenance',
                'severity' => 'warning',
                'title'    => 'Final QA re-inspection · ' . $this->label($vehicle),
                'body'     => trim($this->label($vehicle) . ' was serviced on-site · ' . $costLabel
                                . ' — give it the final QA pass to return it to service.'),
                'url'      => $this->link($ticket),
                'key'      => 'maint_wf:' . $ticket->id . ':ready_for_reinspection',
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

        // A ticket may not close carrying money that cannot be traced to a document. This is the gate
        // that turns the audit from a report into a workflow: without it, "0% traceable" simply happens
        // again on the next ticket. The refusal always names the specific next action, and there is
        // always a documented way past it — record the invoice, or record an adjustment that explains
        // the figure. See FinancialCompletenessService.
        $check = app(FinancialCompletenessService::class)->check($ticket);
        if (! $check['complete']) {
            throw new WorkflowTransitionException(
                'This ticket cannot be closed yet. ' . app(FinancialCompletenessService::class)->refusalMessage($check),
                ['field' => 'financial', 'blockers' => $check['blockers']],
            );
        }

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

            // The vehicle's service data was already confirmed at the re-inspection PASS (deferInvoice) —
            // receiving the invoice is a purely FINANCIAL step and must never touch the service anchors,
            // history or reminders. See confirmRoutineServices (fired only on the PASS).

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

        $this->guardTypedCost($ticket);

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
            // The lines, the variance gate and the reconciliation flag are ALL owned by the invoice this
            // delegates to — there is one implementation of each, not two that can drift.
            $this->applyLineItems($ticket, $items, $actor, $receiptTotal, $this->clean($varianceExplanation));

            // The ticket's receipt total + headline reconciliation status are rolled up from its
            // invoices (Maintenance::recalcInvoiceAggregate, fired by the invoice's own save hook), so
            // nothing is set here that the documents do not already say.
            $ticket->refresh();
            $variance = $receiptTotal !== null ? round((float) $ticket->cost - (float) $receiptTotal, 2) : 0.0;

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
     * Replace the ticket's Parts + Labor lines — THROUGH the garage invoice that backs them.
     *
     * This used to write maintenance_line_items straight onto the ticket. It was a second, competing
     * implementation of invoice entry: it duplicated the Diagnosis-First and variance gates, and — the
     * real damage — every line it produced carried no `maintenance_invoice_id`, so it was untraceable by
     * construction. That single path is where the AED 13,685 of "real lines never attached to any
     * invoice" in the traceability audit came from.
     *
     * So it now resolves the ticket's bill and delegates to {@see MaintenanceInvoiceService}, the one
     * audited writer. The endpoint, the request shape and the UI are unchanged; what changes is that
     * every line now lands on a document and carries its structured origin.
     *
     * Ambiguity is refused rather than guessed: a ticket worked in two garages has two bills, and
     * "replace the ticket's lines" has no single correct meaning there — the caller is sent to the
     * invoice they actually mean.
     */
    private function applyLineItems(
        Maintenance $ticket,
        array $items,
        User $actor,
        ?float $receiptTotal = null,
        ?string $varianceExplanation = null,
    ): void {
        $invoices = app(MaintenanceInvoiceService::class);
        $existing = $ticket->invoices()->get();

        if ($existing->count() > 1) {
            throw new WorkflowTransitionException(
                'This ticket carries ' . $existing->count() . ' invoices, so "replace all lines" is ambiguous. '
                . 'Edit the specific garage invoice these lines belong to.',
                ['field' => 'line_items', 'invoice_ids' => $existing->pluck('id')->all()],
            );
        }

        $payload = ['line_items' => $items];
        if ($receiptTotal !== null || $varianceExplanation !== null) {
            $payload['receipt_total']        = $receiptTotal;
            $payload['variance_explanation'] = $varianceExplanation;
        }

        if ($invoice = $existing->first()) {
            $invoices->update($invoice, $payload, $actor);

            return;
        }

        // No bill yet — open the one these lines belong to. It takes the ticket's garage when there is
        // one; with no garage it is an in-house bill, which is a real document rather than the absence
        // of one. Non-incorrect faults are attached so cost still attributes per fault, and an incorrect
        // fault is deliberately left off (billing one is refused by IncorrectFaultCostGuard).
        $payload['vendor_id']   = $ticket->vendor_id;
        $payload['is_internal'] = $ticket->vendor_id === null;
        $payload['task_ids']    = $ticket->tasks()
            ->whereNull('marked_incorrect_at')
            ->pluck('id')
            ->all();

        $invoices->create($ticket, $payload, $actor);
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

        // FAULTS ONLY. This is the Chronic Fault Watchdog — "you have repaired this before" — and a
        // planned service repeating is not a chronic anything. Without the filter, picking "Oil Change"
        // at the Decide step warned the inspector that the car had the same problem four times before
        // (audit M2).
        $classifier = app(\App\Services\EventClassificationService::class);
        $tags = $tags->filter(fn ($t) => $classifier->labelKind($t) === \App\Models\MaintenanceTask::KIND_FAULT)->values();

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
    /** Is this move legal from where the ticket stands? The question form of {@see assertTransition()}. */
    private function canTransition(Maintenance $ticket, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$ticket->workflow_status] ?? [], true);
    }

    /**
     * The ONE rule about hand-typed totals, so the closing screen and the deferred-cost screen cannot
     * disagree about it.
     *
     * A typed total is how 311 of 354 tickets ended up with a cost no document supports. Once a ticket
     * carries real billed lines, typing over their sum would silently contradict the invoices underneath,
     * so it is refused and the caller is pointed at the two paths that leave a document behind.
     */
    private function guardTypedCost(Maintenance $ticket): void
    {
        if (! $ticket->lineItems()->exists()) {
            return;
        }

        throw new WorkflowTransitionException(
            'This ticket is itemised — its cost is the sum of its invoice lines (AED '
            . number_format((float) $ticket->cost, 2) . ') and cannot be typed over. To change it, edit '
            . 'the invoice it came from, or record an adjustment explaining the difference.',
            ['field' => 'cost', 'itemised_total' => (float) $ticket->cost],
        );
    }

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
     * Open the car's MAINTENANCE CONTRACT (type 'U') the moment the ticket reaches "Needs Test Drive".
     *
     * The maintenance visit now has a contract for its whole life — from the moment we decide to look at
     * the car to the moment Final QA signs it back into service — instead of only existing when OfficeManager
     * happened to have opened one. That contract is what every money, history and utilization surface in the
     * system reads a maintenance visit from ([[maintenance-contracts]]), so a visit without one is invisible
     * to all of them.
     *
     * Two things this deliberately does NOT do:
     *  • it does not close the car's other open contracts. A car can be out on a live RENTAL and still be
     *    booked in for a look ("Rental is King" — see [[maintenance-open-across-rental]]); closing the
     *    rental here would end a real customer's contract as a side effect of raising an inspection.
     *  • it does not create a second contract when one is already open (OM's, or one we opened earlier).
     *    The ticket links to whatever is already there.
     *
     * Best-effort: the contract is bookkeeping around the repair, never a reason the repair can't proceed.
     */
    private function openMaintenanceContract(Maintenance $ticket, ?User $actor = null): void
    {
        try {
            if (! $ticket->vehicle_id) {
                return; // a workshop-log row with no car — nothing to open a contract against
            }

            // Already covered? Link to the open contract rather than stacking a second one on the car.
            if ($existing = $this->log->activeMaintenanceContractId($ticket->vehicle_id)) {
                if ((int) $ticket->linked_contract_id !== (int) $existing) {
                    $ticket->forceFill(['linked_contract_id' => $existing])->save();
                }

                return;
            }

            $vehicle = $ticket->loadMissing('vehicle')->vehicle;

            $contract = Contract::create([
                'vehicle_id'    => $ticket->vehicle_id,
                'contract_type' => 'U',
                'state'         => 'open',
                'out_date'      => Carbon::today()->toDateString(),
                'out_milage'    => $vehicle?->odometer,
                'opened_by'     => $actor?->name,
                // Marked as ours, not OfficeManager's: the contract sync matches on contract_no +
                // contract_type, and this row deliberately carries no contract_no, so a sync can never
                // mistake it for an OM contract or overwrite it.
                'origin'        => 'web',
                'source'        => 'workflow',
                'reference'     => 'WF-' . $ticket->id,
            ]);

            $ticket->forceFill(['linked_contract_id' => $contract->id])->save();

            $this->log->record($ticket, VehicleLogEvent::EVENT_CONTRACT_OPENED, $actor, [
                'description' => 'Maintenance contract opened for this visit',
                'meta'        => ['contract_id' => $contract->id],
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Close the maintenance contract this visit opened — the car is signed off and back in service.
     *
     * Called from every terminal close, not only the Final QA sign-off, so a visit that ends another
     * legitimate way (an on-site job marked serviced, a close that lands in the deferred-invoice lane)
     * can't leave its contract open forever and hold the car "in maintenance" on every board that reads
     * open type-U contracts.
     *
     * Only closes a contract WE opened for THIS ticket (`linked_contract_id` + `source = workflow`). An
     * OfficeManager contract the ticket merely linked to belongs to OM and is left for OM to close.
     */
    private function closeMaintenanceContract(Maintenance $ticket, ?User $actor = null): void
    {
        try {
            if (! $ticket->linked_contract_id) {
                return;
            }

            $contract = Contract::find($ticket->linked_contract_id);
            if (! $contract || $contract->source !== 'workflow' || $contract->in_date !== null) {
                return; // not ours to close, or already closed
            }

            $contract->forceFill([
                'state'      => 'closed',
                'in_date'    => ($ticket->actual_in_date ?: Carbon::today())->toDateString(),
                'in_milage'  => $ticket->reinspect_odometer ?? $ticket->park_odometer ?? $ticket->return_odometer,
                'closed_by'  => $actor?->name,
            ])->save();

            $this->log->record($ticket, VehicleLogEvent::EVENT_CONTRACT_CLOSED, $actor, [
                'description' => 'Maintenance contract closed — car back in service',
                'meta'        => ['contract_id' => $contract->id],
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
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
            Maintenance::TRIGGER_DRIVER_REPORTED => 'driver-reported issue',
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
    /**
     * TICKET-AS-SOURCE-OF-TRUTH confirmation. Called at the single point a ticket is officially CLOSED
     * (close / markServiced / finalizeInvoice — each ticket hits exactly one, exactly once, since a
     * closed ticket can't re-open). For every routine service that was PERFORMED on this ticket (a
     * completed oil-change / battery fault, left Pending Confirmation at fix time), we now apply it to
     * the vehicle master record: Vehicle::recordServiceDone rolls the ServiceReminder forward, stamps the
     * last-service anchor (oil) / battery_last_changed (battery), and advances the odometer — so the
     * car's Service Status recomputes. Anchored to the ticket's confirmed closing odometer, dated to when
     * the fault was actually resolved. Best-effort per fault: a failure is reported and never sinks the
     * close. No planned or unconfirmed work ever reaches the vehicle — only a closed ticket does.
     */
    /**
     * Does this ticket carry a ROUTINE SERVICE (oil / battery / filters / tyres) that must be confirmed to
     * the vehicle record? Keyed off the fault symptoms (set at diagnosis, before the repair). When true, a
     * minor ticket is NOT allowed to auto-close at park arrival — it is routed through the final QA
     * re-inspection so the service data updates ONLY on a PASS, never on a bare return-to-park. Matches the
     * same resolver confirmRoutineServices() uses at close, so routing and confirmation stay in lock-step.
     */
    /**
     * Did a garage actually repair something on this ticket? If so it must pass the QC gate before it
     * closes, whatever its severity grading.
     *
     * WHY THIS EXISTS. A routine-graded ticket used to auto-close from in_our_park, skipping
     * ready_for_reinspection entirely — and the QC verdict is only written by a close that comes OFF
     * that gate. Measured effect: 12 of 16 closed tickets carried no verdict (38% coverage), and every
     * one of them had a vendor and at least one fault. They were real garage repairs whose outcome
     * nobody recorded. Severity was the wrong test: "routine" describes how urgent the fault was, not
     * whether a workshop took the car apart.
     *
     * The verdict is the platform's ONLY non-proxy evidence about repair quality — the difference
     * between "the fault came back" and "a repair a human passed has failed". Every ticket that closed
     * without one is evidence that cannot be recovered later, because the car has gone.
     *
     * WHAT THIS DELIBERATELY DOES NOT DO: auto-write a "fixed" verdict on the closing path. That would
     * lift coverage to 100% overnight and destroy the very thing being measured — a verdict is worth
     * something precisely because a human looked at the car. Asserted verdicts would poison the only
     * honest dataset the platform has.
     *
     * Flagged so it can be switched off if it overloads the inspector queue: the cost of this rule is
     * a real QC step on jobs that previously closed themselves.
     */
    private function needsQualityVerdict(Maintenance $ticket): bool
    {
        if (! config('features.maintenance.require_qc_verdict', true)) {
            return false;
        }

        // A garage was involved AND there was something for it to fix. Both halves matter: a ticket with
        // no vendor never reached a workshop, and one with no faults has no outcome to judge.
        //
        // Owned by the model, alongside its set-based twin `Maintenance::verdictEligible()`. The
        // evidence ledger asks the same question for a different reason — "should this ticket have
        // produced evidence?" — and if the two ever drifted, the platform would be measuring its QC
        // coverage against a rule it does not actually enforce.
        return $ticket->needsVerdictEvidence();
    }

    private function needsServiceReinspection(Maintenance $ticket): bool
    {
        return $ticket->tasks()
            ->whereNotIn('status', \App\Models\MaintenanceTask::NON_REPAIR_TERMINAL)
            ->with('serviceCatalog:id,service_reminder_type')
            ->get(['id', 'symptom', 'kind', 'service_catalog_id'])
            ->contains(fn ($task) => $task->serviceReminderType() !== null);
    }

    private function confirmRoutineServices(Maintenance $ticket, User $actor): void
    {
        $vehicle = $ticket->loadMissing('vehicle')->vehicle;
        if (! $vehicle) {
            return;
        }

        // The confirmed reading at sign-off: the QC re-inspection odometer, else the garage-OUT return,
        // else the arrival reading, else the car's current mileage.
        $odo = $ticket->reinspect_odometer
            ?? $ticket->return_odometer
            ?? $ticket->receive_odometer
            ?? $vehicle->odometer;
        if ($odo === null) {
            return; // nothing to anchor the service to
        }

        $completed = $ticket->tasks()
            ->where('status', \App\Models\MaintenanceTask::STATUS_COMPLETED)
            ->with('serviceCatalog:id,service_reminder_type')
            ->get();

        foreach ($completed as $task) {
            // TYPE FIRST. This is the single place a maintenance action reaches the vehicle master record
            // (odometer anchor + reminder roll-forward), so it reads the stored domain type — never the
            // symptom wording. A fault named "Oil Change" must not stamp the car as serviced, and a
            // catalog-linked service must roll even when its wording differs from the reminder's label
            // ("Brake Pads (service)" → brake_pads). See docs/Service-Fault-Separation-Audit.md C2.
            $type = $task->serviceReminderType();
            if (! $type) {
                continue; // a fault, an inspection, or a service with no recurring reminder behind it
            }

            // Catalog-linked services (and the curated routine keywords behind legacy rows) always sync —
            // oil & battery also re-anchor the car's serviceStatus / battery date. A service resolved ONLY
            // by a Service-Reminder LABEL (the legacy text shim) rolls ONLY an EXISTING reminder, so a
            // loosely-named legacy row cannot silently spawn a brand-new reminder schedule.
            $isAuthoritative = $task->service_catalog_id !== null
                || Maintenance::routineServiceTypeFor($task->symptom) !== null;
            if (! $isAuthoritative && ! $vehicle->serviceReminders()->where('service_type', $type)->exists()) {
                continue;
            }

            try {
                $date     = optional($task->resolved_at)->toDateString() ?: Carbon::now()->toDateString();
                $reminder = $vehicle->recordServiceDone($type, (int) $odo, $date);

                $this->log->recordTask($task, VehicleLogEvent::EVENT_SERVICE_LOGGED, $actor, [
                    'description' => $reminder->displayName() . ' confirmed @ ' . number_format((int) $odo) . ' km'
                        . ($reminder->next_due_odometer ? ' — next due at ' . number_format((int) $reminder->next_due_odometer) . ' km' : '')
                        . ' (ticket closed by ' . $actor->name . ')',
                    'meta'        => [
                        'service_type'       => $type,
                        'odometer'           => (int) $odo,
                        'next_due_odometer'  => $reminder->next_due_odometer,
                        'confirmed_at_close' => true,
                    ],
                ]);
            } catch (\Throwable $e) {
                report($e); // confirming the next-service schedule must never break the ticket close
            }
        }
    }

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

    /**
     * Does this ticket owe an oil change that a recall already committed the fleet to?
     *
     * True only while it is genuinely outstanding: a recall exists, and the change has not been
     * recorded. Once the workshop records it the requirement is MET, and re-adding it to a later
     * report would put the same job on the ticket twice.
     *
     * Reads both shapes the reference takes — a request the oil lifecycle RAISED (whole
     * trigger_detail) and one it ADOPTED (the oil layer under `oil_projection`).
     */
    private function oilChangeIsOwed(Maintenance $ticket): bool
    {
        $detail = $ticket->trigger_detail;
        if (! is_array($detail)) {
            return false;
        }

        $oil = ($detail['source'] ?? null) === 'oil_projection'
            ? $detail
            : ($detail['oil_projection'] ?? null);

        if (! is_array($oil) || empty($oil['contract_oil_decision_id'])) {
            return false;
        }

        $decision = \App\Models\ContractOilDecision::find($oil['contract_oil_decision_id']);

        return $decision
            && $decision->decision === \App\Models\ContractOilDecision::DECISION_RECALL
            && ! $decision->isOilChanged();
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

    /**
     * Persist the Make-Ready per-fault labor entries onto each fault's CURRENT attempt (the stint the
     * mark-fixed already closed) via FaultRepairTimeService — write-once per attempt, so a duplicate
     * submit is a harmless no-op and a re-fix round appends instead of overwriting. Rows are matched by
     * `task_id` when the client sends one, else by the fault's symptom text (the legacy shape).
     * Best-effort per row: one bad row must never roll back the Make-Ready transition.
     *
     * @param array<int, array{task_id?:int|string, text?:string, hours?:mixed}> $times
     */
    private function recordAttemptLaborBatch(Maintenance $ticket, array $times, User $actor): void
    {
        $svc   = app(\App\Services\FaultRepairTimeService::class);
        $tasks = $ticket->tasks()->get();

        foreach ($times as $row) {
            if (! is_array($row)) {
                continue;
            }
            $hours = $row['hours'] ?? null;
            if ($hours === null || $hours === '' || ! is_numeric($hours)) {
                continue;
            }

            $task = null;
            if (! empty($row['task_id'])) {
                $task = $tasks->firstWhere('id', (int) $row['task_id']);
            } elseif (($text = $this->clean($row['text'] ?? null)) !== null) {
                $task = $tasks->first(fn ($t) => mb_strtolower(trim((string) $t->symptom)) === mb_strtolower($text));
            }
            if (! $task) {
                continue; // no matching fault on this ticket — the findings stamp still carries the label
            }

            try {
                $svc->recordAttemptLabor($task, (float) $hours, $actor);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /** Relations every transition returns hydrated for the API. */
    private function eager(): array
    {
        return ['vendor', 'reason', 'vehicle:id,plate_no,make,model,year,code,operational_status,odometer,last_service_odometer,service_synced_at,service_due_date,purchase_date,created_at', 'inspector:id,name', 'requester:id,name', 'linkedContract:id,contract_no', 'assignedDriver:id,name', 'delegatedBy:id,name', 'pickedUpFromGarageBy:id,name', 'watchers:id,name', 'lineItems', 'activeTemporaryRelease', 'temporaryReleases', 'driverObservation:id,inspection_request_id'];
    }
}
