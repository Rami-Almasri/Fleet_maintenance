<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A row in `maintenances` plays one of two roles, told apart by `origin`:
 *
 *  - the maintenance "header" for a type-'U' contract (origin = 'contract'): garage,
 *    issue tags, who approved it, expected return — one per contract (contract_id 1:1).
 *  - a standalone WORKSHOP EVENT (origin = 'sheet' | 'manual' | 'customer-sheet'):
 *    one row per event (OUT / IN / Follow up / …) anchored to a VEHICLE, not a contract.
 *    'sheet'/'customer-sheet' come from the Google-Sheet sync; 'manual' is entered by hand
 *    in the dashboard. The two event sources are interchangeable everywhere the board reads
 *    the live garage log — manual events are the dashboard becoming the source of truth.
 */
class Maintenance extends Model
{
    use HasFactory;

    protected $table = 'maintenances';

    /** Origins that represent a standalone, vehicle-anchored workshop EVENT (not a contract header). */
    public const EVENT_ORIGINS = ['sheet', 'manual', 'customer-sheet'];

    /** Origin used for workshop events created/edited by hand in the dashboard. */
    public const ORIGIN_MANUAL = 'manual';

    /** Origins owned by the Google-Sheet sync — the ONLY rows a sheet reload may wipe. */
    public const SHEET_ORIGINS = ['sheet', 'customer-sheet'];

    /** The live garage/workshop log the maintenance board reads: imported sheet events + hand-entered ones. */
    public const WORKSHOP_LOG_ORIGINS = ['sheet', 'manual'];

    /** Workshop stages (event_status) the dashboard manages; 'IN' means the car came back. */
    public const STAGES = ['OUT', 'IN', 'Follow up', 'Change', 'Delay', 'Test', 'Under Test'];

    /**
     * Fleet Maintenance Workflow lifecycle (workflow_status) — the app-driven ticket states that
     * replace the WhatsApp relay. DISTINCT from the sheet stage (event_status): the
     * MaintenanceWorkflowService maps the lifecycle onto event_status so the board, SLA and
     * operational_status cascade keep working. The middle four are the Controller dashboard columns.
     */
    // Inspection Request Review Gate. A Driver/system-generated inspection request no longer jumps
    // straight to the Inspector (Abu Maroof) — it parks HERE first, visible only to the Controllers
    // (Lin & Marwa, `maintenance.manage`), who approve (→ inspection_requested, exactly the hand-off
    // that used to fire immediately) or reject (→ review_rejected, terminal, nothing sent externally).
    // Pre-ticket, like inspection_requested itself. See approveInspectionReview()/rejectInspectionReview().
    public const WF_PENDING_REVIEW    = 'pending_review';           // Stage -1: awaiting Controller review before the Inspector is notified
    // Terminal: the Controller rejected the request — no inspection ever happens, nothing external sent.
    public const WF_REVIEW_REJECTED   = 'review_rejected';
    public const WF_INSPECTION_REQUESTED  = 'inspection_requested';  // Stage 0: a Driver requested an inspection — NOT a ticket yet
    public const WF_INSPECTION_DIAGNOSTIC = 'inspection_diagnostic'; // Stage 1: test-drive diagnostic — NOT a ticket yet
    public const WF_INSPECTION_PENDING = 'inspection_pending';       // Stage 2 (requires maintenance · IN-SHOP): ticket OPENED, awaiting the Supervisor's dispatch decision
    // Repair-Location "On-Site (Mobile)" lane. A minor job (battery, bulb, tyre check) the Decide step
    // routed to be done WHERE THE CAR IS PARKED — so it never goes to a garage. This is a committed
    // ticket (a repair WILL happen) yet the car stays operationally FREE: the state is deliberately kept
    // OUT of WF_TICKET_STATES (like awaiting_invoice), so the operational-status cascade never reads the
    // car as In-Maintenance — it merely carries a "Pending Maintenance" tag until a single "Mark as
    // Serviced" (markServiced()) closes it, with NO dispatch, NO re-inspection and NO QA. See submitReport().
    public const WF_ON_SITE_PENDING    = 'on_site_pending';          // committed on-site job — car stays available, tagged "Pending Maintenance"
    public const WF_AWAITING_DISPATCH  = 'awaiting_dispatch';        // Phase 2: Supervisor picked the garage + assigned a driver — awaiting the driver's pickup
    public const WF_IN_TRANSIT         = 'in_transit';               // UC-3: odometer + garage captured, car en route

    // Planned garage transfer — HOW the car will actually move to its new garage, chosen by the
    // Supervisor at the moment Transfer is requested (beginGarageTransfer). Drives which pickup screen
    // the ticket resolves to at awaiting_dispatch: 'driver' → the normal dispatch() form, 'recovery' →
    // dispatchRecovery() (towing). Null = legacy default (driver), for tickets transferred before this
    // choice existed.
    public const TRANSPORT_DRIVER   = 'driver';
    public const TRANSPORT_RECOVERY = 'recovery';
    public const WF_UNDER_REPAIR       = 'under_repair';             // UC-4: garage received the car
    // Supervisor Video-Review gate: the garage has FINISHED the repair and sent its video (uploaded to
    // the ticket by Waleed/Abdullah). Before the car returns to service, a supervisor reviews that video
    // and either APPROVES it (→ ready_for_reinspection, the car goes to the final re-inspection) or, if
    // not satisfied, REQUESTS A RE-FIX (→ back to under_repair at the same garage). This is the
    // management authority gate the team asked for. See [[maintenance-workflow-engine]].
    public const WF_REPAIR_REVIEW      = 'repair_review';            // UC-5a: garage finished — supervisor reviewing the video before sign-off
    public const WF_READY_REINSPECTION = 'ready_for_reinspection';   // UC-5: garage done, awaiting re-inspection
    // Quality-Control gate: the closing re-inspection FAILED — one or more faults are still broken. The
    // car does NOT bounce straight back to the same garage; it returns to the Supervisor's dispatch queue
    // (standing out as "returned in a bad state") so they decide whether to send it back to the same
    // garage or move it to a different one. See [[maintenance-workflow-engine]].
    public const WF_REINSPECTION_FAILED = 'reinspection_failed';     // re-inspection failed → awaiting supervisor re-dispatch
    // Re-inspection PASSED — the repair is signed off and the car is physically sitting at the garage
    // waiting for a driver to bring it back to base. The car is still AT THE GARAGE (kept in WF_AT_GARAGE
    // / WF_TICKET_STATES) — distinct from WF_AWAITING_DISPATCH's "Ready for Pickup" label, which is the
    // OUTBOUND leg (car hasn't left base yet); this is the RETURN leg.
    public const WF_READY_FOR_PICKUP   = 'ready_for_pickup';         // UC-5b: signed off, awaiting driver pickup FROM the garage
    // The car has physically returned to base and is available again — same moment the operational-status
    // cascade frees the car (mirrors what `close()` used to do). The TICKET stays open (like
    // awaiting_invoice) until cost/invoice paperwork is finalized via close()/deferInvoice().
    public const WF_IN_OUR_PARK        = 'in_our_park';              // car back in the fleet pool, ticket still open pending paperwork
    // Repair signed off and the car is BACK IN SERVICE, but the paper invoice hasn't arrived. A distinct
    // "operationally complete, financially open" state: the car is freed (NOT in WF_TICKET_STATES, so it
    // never reads as in-maintenance) yet the ticket stays open (NOT in WF_TERMINAL) so the invoice tracker
    // + 3-day SLA can chase it. Entering the invoice (portal/line-items) or a manual "received" closes it.
    public const WF_AWAITING_INVOICE   = 'awaiting_invoice';         // returned to service; invoice outstanding
    public const WF_CLOSED             = 'closed';                    // UC-6: re-inspected, returned to service
    public const WF_DIAGNOSTIC_CLEARED = 'diagnostic_cleared';       // Stage 2 (no maintenance): diagnosis closed, no ticket ever
    // Customer-complaint TRIAGE lane (Abu Maroof). A complaint no longer jumps straight to the
    // Supervisors' garage-dispatch queue: it parks HERE first, where the Inspector (Abu Maroof) decides
    // how to handle it — talk to the customer, resolve it on-site, or send the car in (to a garage or to
    // his own diagnostic). Like the other pre-ticket states it is NOT a committed ticket: it never drives
    // operational_status, links no contract and raises no garage alert. See openComplaint() + routeComplaint().
    public const WF_COMPLAINT_TRIAGE   = 'complaint_triage';
    // Terminal: the complaint was handled WITHOUT a garage visit (repaired at the customer / resolved on
    // the spot). No cost/garage is required — it simply closes the loop with a resolution note in the audit.
    public const WF_COMPLAINT_RESOLVED = 'complaint_resolved';
    // Triage Routing Approval gate. When Abu Maroof (the Inspector) decides a complaint needs the car sent
    // in — to a garage or to his own diagnostic — his decision is now a RECOMMENDATION, not an executed
    // move: the ticket parks HERE while a Supervisor/delegate signs it off. His chosen destination +
    // optional replacement + note are held on `triage_route_request` until then. Like the other pre-ticket
    // states it is FENCED (NOT in WF_TICKET_STATES) — the car stays free and no garage is alerted until the
    // Supervisor Approves (→ the real route runs) or Rejects (→ back to complaint_triage). See
    // recommendTriageRoute() / approveTriageRoute() / rejectTriageRoute().
    public const WF_TRIAGE_APPROVAL_PENDING = 'triage_approval_pending';

    // ── PRE-MAINTENANCE RECOMMENDATION QUEUE ────────────────────────────────────────────────────────
    // A recommendation is NOT a commitment to repair. When the Inspector files an in-shop "requires
    // maintenance" report, the ticket lands HERE — a lightweight review queue the Supervisor triages —
    // instead of jumping straight into the active dispatch pipeline (inspection_pending). Like the other
    // pre-ticket states these are FENCED: NOT in WF_TICKET_STATES, so the car is never counted as an
    // active maintenance job, never drives operational_status, links no contract and raises no garage
    // alert. Only the Supervisor's explicit "Start Maintenance" advances it into inspection_pending, from
    // which the existing workflow runs completely unchanged. See submitReport() + the recommendation* methods.
    public const WF_RECOMMENDATION_PENDING = 'recommendation_pending'; // awaiting the Supervisor's review
    // "Waiting for Parts" — the Supervisor approved the intent but the repair needs a spare part first. The
    // car stays in the recommendation queue (still fenced, still Available) until parts are ready; it is NOT
    // an active maintenance job while it waits. parts-ready returns it to recommendation_pending to start.
    public const WF_AWAITING_PARTS = 'awaiting_parts';
    // Terminal: the recommendation was dismissed WITHOUT any maintenance — either rejected or judged not
    // required. The reason + which disposition is stored on recommendation_disposition / recommendation_note.
    public const WF_RECOMMENDATION_DISMISSED = 'recommendation_dismissed';

    // ── PAUSED — RETURNED TO SERVICE (operational pause) ────────────────────────────────────────────
    // The repair had already started, but the car is urgently needed back in service — for a customer
    // rental or any other operational reason: the company temporarily INTERRUPTS the maintenance and
    // releases the car back into service (Available) WITHOUT closing, cancelling or completing the
    // ticket. All progress, notes, parts, photos, technician assignments and audit history are preserved
    // in place; the stage the ticket held is remembered in `paused_from_status`.
    // Like the pre-ticket / awaiting-invoice states this is DELIBERATELY NOT in WF_TICKET_STATES, so the
    // car is NOT counted as in-maintenance and is freely rentable while it waits — yet it is NOT in
    // WF_TERMINAL either, so the ticket stays OPEN. Full Enterprise Handover Workflow: both the pause
    // and the resume leg capture a full custody handover (odometer/fuel/condition/damage/signature —
    // see MaintenanceHandover), and once the vehicle is marked physically RETURNED
    // (`vehicle_returned_at`) it is blocked from being rented out again until the resume handover clears
    // (or an open MaintenanceIncident is acknowledged) — see OperationsService::vehicleInMaintenance().
    // "Resume Maintenance" restores paused_from_status and the car re-enters the pipeline at the
    // identical stage (nothing restarts). See pauseForRental()/resumeMaintenance().
    public const WF_PAUSED_RETURNED_TO_SERVICE = 'paused_returned_to_service';

    /** The two live recommendation-queue states (pending + waiting-for-parts). Fenced pre-ticket, shown
     *  only on the Maintenance Recommendations page — never on the active repair board. */
    public const WF_RECOMMENDATION_STATES = [self::WF_RECOMMENDATION_PENDING, self::WF_AWAITING_PARTS];

    /** How a dismissed recommendation was disposed of (recommendation_disposition column). */
    public const RECO_REJECTED     = 'rejected';
    public const RECO_NOT_REQUIRED = 'not_required';
    public const RECO_DISPOSITIONS = [self::RECO_REJECTED, self::RECO_NOT_REQUIRED];

    /** Every workflow state, in lifecycle order. */
    public const WORKFLOW_STATUSES = [
        self::WF_PENDING_REVIEW,
        self::WF_REVIEW_REJECTED,
        self::WF_INSPECTION_REQUESTED,
        self::WF_COMPLAINT_TRIAGE,
        self::WF_TRIAGE_APPROVAL_PENDING,
        self::WF_INSPECTION_DIAGNOSTIC,
        self::WF_RECOMMENDATION_PENDING,
        self::WF_AWAITING_PARTS,
        self::WF_INSPECTION_PENDING,
        self::WF_ON_SITE_PENDING,
        self::WF_AWAITING_DISPATCH,
        self::WF_IN_TRANSIT,
        self::WF_UNDER_REPAIR,
        self::WF_REPAIR_REVIEW,
        self::WF_READY_FOR_PICKUP,
        self::WF_IN_OUR_PARK,
        self::WF_READY_REINSPECTION,
        self::WF_REINSPECTION_FAILED,
        self::WF_PAUSED_RETURNED_TO_SERVICE,
        self::WF_AWAITING_INVOICE,
        self::WF_CLOSED,
        self::WF_DIAGNOSTIC_CLEARED,
        self::WF_COMPLAINT_RESOLVED,
        self::WF_RECOMMENDATION_DISMISSED,
    ];

    /** Terminal states — a finished lifecycle, excluded from the live pipeline. */
    public const WF_TERMINAL = [self::WF_CLOSED, self::WF_DIAGNOSTIC_CLEARED, self::WF_COMPLAINT_RESOLVED, self::WF_RECOMMENDATION_DISMISSED, self::WF_REVIEW_REJECTED];

    /** The car is back on the road (repair done) — closed OR awaiting-invoice OR already in the park.
     *  Used where "is the car operationally free?" matters, distinct from WF_TERMINAL (which means the
     *  ticket is fully finished). */
    public const WF_OPERATIONALLY_DONE = [self::WF_CLOSED, self::WF_AWAITING_INVOICE, self::WF_IN_OUR_PARK];

    /** How many days a ticket may sit in awaiting_invoice before it is flagged overdue (SLA). */
    public const INVOICE_SLA_DAYS = 3;

    /**
     * Fleet-default repair target (days) used by the repair-ETA gauge when a ticket has no explicit
     * `expected_return_date` promise — so a car in the shop always shows a live "day N of M" counter
     * instead of a dead "No ETA". A real ready-by date, once set at dispatch, overrides this. Tunable
     * via config('maintenance.default_repair_days'); the const is the fallback if that key is absent.
     */
    public const DEFAULT_REPAIR_DAYS = 4;

    // ── Accounting-bridge (reconciliation) flag ─────────────────────────────────────────────────
    /** An itemised garage invoice has been recorded and is waiting for the finance engine to reconcile
     *  it against the accounting API ("Financial-Pending-Reconciliation"). Set by syncLineItems. */
    public const RECON_PENDING    = 'pending';
    /** Finance has processed/matched the invoice — no further action. */
    public const RECON_RECONCILED = 'reconciled';

    /** The pre-ticket states: a Driver request or an in-progress diagnostic. Neither is a committed
     *  ticket — they raise no Logistics dispatch, link no contract and never drive operational_status. */
    public const WF_PRE_TICKET = [
        self::WF_PENDING_REVIEW,
        self::WF_REVIEW_REJECTED,
        self::WF_INSPECTION_REQUESTED,
        self::WF_COMPLAINT_TRIAGE,
        // A triage routing decision awaiting the Supervisor's approval is still pre-ticket — the car
        // hasn't been committed to a garage yet, so it stays free and raises no dispatch.
        self::WF_TRIAGE_APPROVAL_PENDING,
        self::WF_INSPECTION_DIAGNOSTIC,
        // The recommendation queue is pre-ticket too: an approved-to-repair decision hasn't been made yet,
        // so a car sitting in recommendation_pending / awaiting_parts is NOT an active maintenance job.
        self::WF_RECOMMENDATION_PENDING,
        self::WF_AWAITING_PARTS,
    ];

    /**
     * The states that count as a real, committed maintenance TICKET (a decision to repair was made).
     * `inspection_requested` / `inspection_diagnostic` are deliberately NOT here: neither is yet a
     * ticket, so they never notify Logistics, never link a contract, and never drive operational_status.
     */
    public const WF_TICKET_STATES = [
        self::WF_INSPECTION_PENDING,
        self::WF_AWAITING_DISPATCH,
        self::WF_IN_TRANSIT,
        self::WF_UNDER_REPAIR,
        self::WF_REPAIR_REVIEW,
        self::WF_READY_REINSPECTION,
        self::WF_REINSPECTION_FAILED,
        self::WF_READY_FOR_PICKUP,
        self::WF_CLOSED,
        // NOTE: WF_ON_SITE_PENDING is DELIBERATELY NOT here. An on-site (mobile) job is a committed
        // ticket, but the car never leaves service — so it must NOT drive operational_status to
        // "maintenance". Keeping it out is exactly what lets an on-site car stay Available with only a
        // "Pending Maintenance" tag (mirrors how awaiting_invoice keeps a returned car free).
        // NOTE: WF_IN_OUR_PARK is ALSO DELIBERATELY NOT here. It is a TRANSIENT checkpoint — arriveAtPark()
        // always auto-continues to closed (minor repair) or ready_for_reinspection (major repair) within
        // the same request, so no ticket ever rests here; whichever state it lands on governs freedom.
    ];

    /**
     * Maintenance Checkpoint tracking window — the active, committed in-repair stages a car passes
     * through while physically being worked on. The Checkpoint Scan monitors tickets in these states
     * for progress updates, and the "Maintenance Progress" dashboard lists them. Mirrors
     * WF_TICKET_STATES minus WF_CLOSED (a closed ticket needs no more follow-up).
     */
    public const CHECKPOINT_TRACKED_STATES = [
        self::WF_INSPECTION_PENDING,
        self::WF_AWAITING_DISPATCH,
        self::WF_IN_TRANSIT,
        self::WF_UNDER_REPAIR,
        self::WF_REPAIR_REVIEW,
        self::WF_READY_REINSPECTION,
        self::WF_REINSPECTION_FAILED,
        self::WF_READY_FOR_PICKUP,
    ];

    /**
     * The stages a live repair can be PAUSED from (Pause Maintenance & Return to Service). These are the
     * committed-ticket, in-progress states — a real repair is under way and the car is currently held in
     * maintenance, which is exactly when a customer might need it pulled out. Terminal, pre-ticket,
     * on-site (car already available), in-our-park (transient), awaiting-invoice (car already back) and
     * an already-closed ticket are all excluded. Mirrors WF_TICKET_STATES minus WF_CLOSED.
     */
    public const PAUSABLE_STATES = [
        self::WF_INSPECTION_PENDING,
        self::WF_AWAITING_DISPATCH,
        self::WF_IN_TRANSIT,
        self::WF_UNDER_REPAIR,
        self::WF_REPAIR_REVIEW,
        self::WF_READY_REINSPECTION,
        self::WF_REINSPECTION_FAILED,
        self::WF_READY_FOR_PICKUP,
    ];

    /**
     * The committed-ticket stages at which the car is physically OUT (event_status 'OUT' — dispatched to
     * the garage and not yet returned). Used to restore event_status EXACTLY on resume: a ticket paused
     * from one of these goes back to 'OUT', anything else (inspection_pending / awaiting_dispatch — the
     * car was still parked at base) goes back to 'IN'. Mirrors the lifecycle→event_status mapping the
     * workflow service applies at each transition (out_date is stamped at dispatch / in_transit).
     */
    public const WF_PHYSICALLY_OUT_STATES = [
        self::WF_IN_TRANSIT,
        self::WF_UNDER_REPAIR,
        self::WF_REPAIR_REVIEW,
        self::WF_READY_FOR_PICKUP,
        self::WF_READY_REINSPECTION,
        self::WF_REINSPECTION_FAILED,
    ];

    /** Can this ticket be paused (released back into service) from its current stage? */
    public function isPausable(): bool
    {
        return in_array($this->workflow_status, self::PAUSABLE_STATES, true);
    }

    /**
     * Rental Eligibility — did the inspector allow this car to be rented BEFORE maintenance completes?
     * true = deferrable (a rental pauses the ticket, it resumes on return); false = mandatory
     * maintenance (grounded until the workshop finishes). Decided once at the Decide step.
     */
    public function isDeferrableForRental(): bool
    {
        return (bool) $this->deferrable_for_rental;
    }

    /** Is this ticket currently paused, released back into service (repair on hold)? */
    public function isPausedReturnedToService(): bool
    {
        return $this->workflow_status === self::WF_PAUSED_RETURNED_TO_SERVICE;
    }

    /** Paused AND the vehicle has been marked physically returned — a handover is due before resuming. */
    public function isReturnedPendingHandover(): bool
    {
        return $this->isPausedReturnedToService() && $this->vehicle_returned_at !== null;
    }

    /** Paused and still out (no return handover captured yet) — the car is with the customer/operation. */
    public function isPausedOut(): bool
    {
        return $this->isPausedReturnedToService() && $this->vehicle_returned_at === null;
    }

    // ── Temporary Vehicle Release ──────────────────────────────────────────────────────────────────
    // The car leaves the workshop mid-repair (a road test, a customer test/delivery, an external
    // inspection, storage, …) while the ticket stays put. Unlike a Pause & Return to Service, the
    // workflow_status is NEVER changed, the car is NOT freed for rental, and the ticket is NOT
    // closed/completed — the same repair simply continues when the car returns. The overlay is expressed
    // purely through active_temporary_release_id (a pointer to the open MaintenanceTemporaryRelease row).

    /**
     * The stages a car may be temporarily taken out from. Same committed-ticket, in-progress set as a
     * pause: a real repair is under way (or awaiting one) and there is a physical car to take out. A
     * paused ticket (car already released into service) and terminal/pre-ticket/on-site tickets are
     * excluded by virtue of not being in this set. Mirrors PAUSABLE_STATES.
     */
    public const TEMP_RELEASABLE_STATES = self::PAUSABLE_STATES;

    /** Is the car currently out on a temporary release (repair continues untouched while it's away)? */
    public function isTemporarilyReleased(): bool
    {
        return $this->active_temporary_release_id !== null;
    }

    /**
     * Can the car be temporarily taken out from its current stage? Only a committed, in-progress ticket
     * whose car isn't ALREADY out (temporary release or an operational pause) qualifies.
     */
    public function isTempReleasable(): bool
    {
        return in_array($this->workflow_status, self::TEMP_RELEASABLE_STATES, true)
            && ! $this->isTemporarilyReleased()
            && ! $this->isPausedReturnedToService();
    }

    /**
     * REPAIR LOCATION — the Supervisor's "where does this repair happen?" decision, made at the Decide
     * step. It splits a committed ticket into two lanes with very different consequences:
     *   • in_shop — the classic workshop pipeline (dispatch → garage → re-inspection); car → In-Maintenance.
     *   • on_site — a mobile/minor job done where the car is parked; car stays Available (WF_ON_SITE_PENDING).
     * Null on a cleared diagnostic and on every legacy / non-workflow row.
     */
    public const REPAIR_IN_SHOP  = 'in_shop';
    public const REPAIR_ON_SITE  = 'on_site';
    public const REPAIR_LOCATIONS = [self::REPAIR_IN_SHOP, self::REPAIR_ON_SITE];

    public const REPAIR_LOCATION_LABELS = [
        self::REPAIR_IN_SHOP => 'In-Shop (Workshop)',
        self::REPAIR_ON_SITE => 'On-Site (Mobile)',
    ];

    /** True when this ticket is the On-Site (mobile) lane — the car stays available, tagged only. */
    public function isOnSite(): bool
    {
        return $this->repair_location === self::REPAIR_ON_SITE
            || $this->workflow_status === self::WF_ON_SITE_PENDING;
    }

    /** True when the car was moved to the garage by a Recovery (towing) unit rather than a driver. */
    public function isRecovery(): bool
    {
        return $this->recovery_unit_name !== null && trim((string) $this->recovery_unit_name) !== '';
    }

    /**
     * The "In Workshop" stage — the point from which a fault can be worked (in_progress) or marked
     * fixed (completed). It begins once the car has ARRIVED and been checked in (under_repair) — NOT
     * while it is still on the way ("Now at Garage" / in_transit), where only arrival + the mandatory
     * odometer are logged and no repair happens yet — and runs through the repair to done, up to and
     * including Ready for Pickup (the car is still physically at the garage there; the Fix-All gate at
     * markReady() already required every fault resolved before reaching it). ready_for_reinspection is
     * DELIBERATELY NOT here: by the time a major repair reaches it the car has already left the garage
     * and is back at our park — it is a QA sign-off step, not a repair step. `closed` is included so a
     * finished ticket's faults can still be corrected after the fact.
     */
    public const WF_AT_GARAGE = [self::WF_UNDER_REPAIR, self::WF_REPAIR_REVIEW, self::WF_READY_FOR_PICKUP, self::WF_CLOSED];

    /** Is the car checked in at the workshop yet? (Gate for working/resolving individual faults.) */
    public function hasReachedGarage(): bool
    {
        return in_array($this->workflow_status, self::WF_AT_GARAGE, true);
    }

    /**
     * Repair-severity classification for the arrival-at-park auto-branch: does this ticket need a final
     * QA re-inspection before the car can be marked Available, or can it auto-close straight away?
     * Reuses the Inspector's MANDATORY fault_severity grade (🔴critical/🟡moderate/🟢routine, set at the
     * Decide step — see [[fault-severity-feature]]) rather than inventing a second classification: a
     * critical or moderate ticket is treated as a "major repair" (electrical/mechanical-style — QA
     * required); only a routine ticket (oil/tyres/battery-check-style — no fault graded above routine)
     * auto-closes. A ticket with no severity set (legacy/task-less) defaults to major (safer: send it
     * through QA rather than silently auto-closing).
     */
    public function isMajorRepair(): bool
    {
        return $this->fault_severity !== self::FAULT_SEVERITY_ROUTINE;
    }

    /**
     * Resolve a finding keyword back to its catalog category key (engine / electrical / …). Built once
     * from config; a keyword we don't recognise returns null.
     */
    public static function categoryForKeyword(?string $keyword): ?string
    {
        if (! $keyword) {
            return null;
        }
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (config('maintenance_findings.categories', []) as $category) {
                foreach ($category['keywords'] ?? [] as $kw) {
                    $map[mb_strtolower($kw)] = $category['key'];
                }
            }
        }
        return $map[mb_strtolower($keyword)] ?? null;
    }

    /**
     * Is this finding/fault a SCHEDULED routine service (oil change, battery, …) rather than a one-off
     * fault? Returns the matching ServiceReminder service_type slug (oil_change / battery / …) when the
     * symptom text exactly matches a routine keyword in config('maintenance_findings.routine_service_types'),
     * or null for an ordinary fault. When such a fault is confirmed at ticket close its Service Reminder
     * rolls forward — see MaintenanceWorkflowService::confirmRoutineServices + Vehicle::recordServiceDone.
     * (Broader sibling: serviceTypeForSymptom, which also matches other reminder types by label.)
     */
    public static function routineServiceTypeFor(?string $symptom): ?string
    {
        $key = mb_strtolower(trim((string) $symptom));
        if ($key === '') {
            return null;
        }
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach ((array) config('maintenance_findings.routine_service_types', []) as $keyword => $type) {
                $map[mb_strtolower(trim($keyword))] = $type;
            }
        }
        return $map[$key] ?? null;
    }

    /**
     * Broader sibling of routineServiceTypeFor: resolve a fault symptom to the ServiceReminder
     * service_type slug whose loop should roll forward when the fault is confirmed at ticket close.
     * This is the resolver MaintenanceWorkflowService::confirmRoutineServices uses — the SINGLE place a
     * maintenance action reaches the vehicle record.
     *
     *   1) a curated routine keyword (oil change, battery replacement, filters, tyres) — via
     *      routineServiceTypeFor; OR
     *   2) any other Service-Reminder type matched by its display label (Brake Pads, A/C Service,
     *      Transmission Service, …). We deliberately EXCLUDE 'battery' (only the exact "Battery
     *      Replacement" routine keyword may stamp the battery date — a bare "Battery" fault must not) and
     *      the catch-all 'general'.
     */
    public static function serviceTypeForSymptom(?string $symptom): ?string
    {
        if ($type = self::routineServiceTypeFor($symptom)) {
            return $type;
        }

        $key = mb_strtolower(trim((string) $symptom));
        if ($key === '') {
            return null;
        }

        static $labelMap = null;
        if ($labelMap === null) {
            $labelMap = [];
            foreach (\App\Models\ServiceReminder::TYPE_LABELS as $slug => $label) {
                if (in_array($slug, ['battery', 'general'], true)) {
                    continue;
                }
                $labelMap[mb_strtolower(trim($label))] = $slug;
            }
        }
        return $labelMap[$key] ?? null;
    }

    /**
     * Inverse of serviceTypeForSymptom: the canonical finding text to seed on a ticket for a given
     * service_type slug, so a reminder → ticket → close round-trips (confirmRoutineServices can match it
     * back). Prefers the curated routine keyword ('battery' → "Battery Replacement", 'oil_change' →
     * "Oil Change") so the exact-match matcher recognises it; else the ServiceReminder label.
     */
    public static function serviceLabelForType(?string $slug): ?string
    {
        if (! $slug) {
            return null;
        }
        foreach ((array) config('maintenance_findings.routine_service_types', []) as $keyword => $type) {
            if ($type === $slug) {
                return \Illuminate\Support\Str::title(trim($keyword));
            }
        }
        return \App\Models\ServiceReminder::TYPE_LABELS[$slug] ?? \Illuminate\Support\Str::title(str_replace('_', ' ', $slug));
    }

    /**
     * Why an inspector opened the ticket:
     *   - periodic   = Routine Maintenance (scheduled mileage/time service) → tagged 'routine'
     *                  so the foresight engine ignores it as a planned visit, not a failure.
     *   - customer_reported = Customer Complaint (issue raised by the client).
     *   - test_drive = Test Drive Report (an inspector-led proactive check).
     */
    public const TRIGGER_PERIODIC   = 'periodic';
    public const TRIGGER_CUSTOMER   = 'customer_reported';
    public const TRIGGER_TEST_DRIVE = 'test_drive';
    // Emergency entry point: the car is NOT driveable and was logged directly (openBreakdown), so it
    // never went through the diagnostic test drive. Kept OUT of TRIGGER_REASONS on purpose — the
    // diagnostic-open endpoints (store / requestInspection) must not accept it as a test-drive reason;
    // it is only ever set by the breakdown intake.
    public const TRIGGER_BREAKDOWN  = 'breakdown';
    // Inspector's-Pad pick-up: a car taken in for maintenance carrying the flags Abu Maroof pre-logged
    // on the pad. Like breakdown it is its own intake path (no test drive), so it is kept OUT of
    // TRIGGER_REASONS — the diagnostic-open endpoints must not accept it as a test-drive reason.
    public const TRIGGER_PICKUP     = 'inspector_pickup';
    public const TRIGGER_REASONS    = [self::TRIGGER_PERIODIC, self::TRIGGER_CUSTOMER, self::TRIGGER_TEST_DRIVE];

    /**
     * TEST KIND — which intake tab the diagnostic came from (a periodic test can be one of two kinds).
     * Purely a traceability tag on the ticket; both kinds flow through the same diagnostic → decide
     * pipeline and are tagged visit_context = routine (planned, so foresight ignores them):
     *   - routine_check      = the Routine tab: an Oil / Battery / Tyres check (add faults if found).
     *   - scheduled_dormancy = the Scheduled tab: a park-time (idle-duration) based check.
     */
    public const TEST_ROUTINE_CHECK      = 'routine_check';
    public const TEST_SCHEDULED_DORMANCY = 'scheduled_dormancy';
    public const TEST_KINDS              = [self::TEST_ROUTINE_CHECK, self::TEST_SCHEDULED_DORMANCY];

    /** Finding sources — who diagnosed the issue (drives the grouped Inspector/Garage display). */
    public const FINDING_INSPECTOR = 'inspector'; // Abu Maroof, on the test drive
    public const FINDING_GARAGE    = 'garage';    // the workshop, during the repair
    public const FINDING_SOURCES   = [self::FINDING_INSPECTOR, self::FINDING_GARAGE];

    /**
     * Workflow-ticket classification — WHY the car is in the shop. Set when the inspector opens
     * the ticket (or updated mid-lifecycle when the true nature is discovered during repair,
     * e.g. what started as Routine is found to be an Insurance Incident).
     * These values live in the existing `maintenance_type` column; sheet-imported rows use
     * the sheet's own free-text values so there is no collision.
     */
    public const TYPE_ROUTINE          = 'routine';
    public const TYPE_BREAKDOWN        = 'breakdown';
    public const TYPE_INS_INCIDENT     = 'ins_incident';
    public const TYPE_NON_INS_INCIDENT = 'non_ins_incident';
    public const TYPE_MODIFICATION     = 'modification';
    public const TYPE_UPGRADE          = 'upgrade';

    public const MAINTENANCE_TYPES = [
        self::TYPE_ROUTINE          => 'Routine Maintenance',
        self::TYPE_BREAKDOWN        => 'Breakdown',
        self::TYPE_INS_INCIDENT     => 'Insurance Incident',
        self::TYPE_NON_INS_INCIDENT => 'Non-Insurance Incident',
        self::TYPE_MODIFICATION     => 'Modification',
        self::TYPE_UPGRADE          => 'Upgrade',
    ];

    /**
     * Role split of the classification list — the "Context-Aware Classification" rule. A TECHNICIAN
     * (Abu Maroof, permission maintenance.initiate) may only ever open or close a ticket as one of the
     * two OPERATIONAL states he can actually judge: Routine or Breakdown. The ADMINISTRATIVE types
     * (Insurance / Non-Insurance Incident, Modification, Upgrade) carry legal, insurance, billing or
     * ownership consequences, so only a MANAGER (permission maintenance.manage) may set them — and only
     * via the reclassify surface (updateMaintenanceType). This keeps "why the test was done" honestly
     * coupled to the final classification and stops a technician inventing a logical conflict (e.g.
     * self-declaring an Insurance Incident); if he finds accident damage on a routine test he must flag
     * it to a manager, who reclassifies. The gate is enforced in MaintenanceWorkflowController
     * (submitReport validation + the permission-scoped findings catalog) and on the manage-only route.
     */
    public const TYPES_TECHNICIAN = [self::TYPE_ROUTINE, self::TYPE_BREAKDOWN];
    public const TYPES_ADMIN      = [self::TYPE_INS_INCIDENT, self::TYPE_NON_INS_INCIDENT, self::TYPE_MODIFICATION, self::TYPE_UPGRADE];

    /**
     * "Rental-First" visit context. Only 'routine' changes behaviour (excluded from the
     * foresight Chronic / Act-now signals); 'accident_rental' and 'standard' count as before,
     * as does a null (untagged) value. See [[rental-first-policy]] in project memory.
     */
    public const VISIT_CONTEXTS = ['routine', 'accident_rental', 'standard'];
    public const CONTEXT_ROUTINE = 'routine';

    /**
     * Ticket FAULT SEVERITY — the inspector's MANDATORY diagnostic grade for the whole ticket,
     * assessed at the Decide step (Abu Maroof cannot file "Requires maintenance" without it). It is
     * the headline urgency a supervisor reads to gauge the garage dispatch, surfaced as a colour +
     * symbol everywhere (board chip, command view, dispatch alert). Distinct from per-finding `severity`.
     */
    public const FAULT_SEVERITY_CRITICAL = 'critical';
    public const FAULT_SEVERITY_HIGH     = 'high';
    public const FAULT_SEVERITY_MODERATE = 'moderate';
    public const FAULT_SEVERITY_ROUTINE  = 'routine';
    // Ordered worst → least so any UI that iterates the list renders red → orange → yellow → green.
    public const FAULT_SEVERITIES        = [self::FAULT_SEVERITY_CRITICAL, self::FAULT_SEVERITY_HIGH, self::FAULT_SEVERITY_MODERATE, self::FAULT_SEVERITY_ROUTINE];

    /**
     * Per-severity presentation: the emoji that rides into notification copy so a supervisor reads the
     * urgency at a glance, the FleetAlert `severity` it maps to (drives the card colour), a human
     * label, and the chip `tone` the board uses. 🟠 High sits between Critical and Moderate — urgent,
     * but not a grounded-car emergency.
     */
    public const FAULT_SEVERITY_META = [
        self::FAULT_SEVERITY_CRITICAL => ['emoji' => '🔴', 'severity' => 'critical', 'label' => 'Critical', 'tone' => 'red'],
        self::FAULT_SEVERITY_HIGH     => ['emoji' => '🟠', 'severity' => 'warning',  'label' => 'High',     'tone' => 'orange'],
        self::FAULT_SEVERITY_MODERATE => ['emoji' => '🟡', 'severity' => 'warning',  'label' => 'Moderate', 'tone' => 'amber'],
        self::FAULT_SEVERITY_ROUTINE  => ['emoji' => '🟢', 'severity' => 'success',  'label' => 'Routine',  'tone' => 'green'],
    ];

    /**
     * Delegation overlay — a Supervisor assigns a specific Logistics driver to pick up / drop off the
     * car. The driver reuses `assigned_driver_id`; these mark the task + the "Driver Assigned" state.
     */
    public const DELEGATION_PICKUP   = 'pickup';
    public const DELEGATION_DROPOFF  = 'dropoff';
    public const DELEGATION_TASKS    = [self::DELEGATION_PICKUP, self::DELEGATION_DROPOFF];
    public const DELEGATION_ASSIGNED = 'driver_assigned';

    /** One-click status replies a driver can send back to a "Ping location" (plus free text). */
    public const STATUS_PRESETS = ['At site', 'In traffic', 'Arrived'];

    protected $fillable = [
        'contract_id',
        'vehicle_id',
        'vendor_id',
        // Planned Garage Transfer — the destination garage while the car is still physically at its
        // current one (vendor_id). Set at transfer-request, cleared on arrival at the destination.
        'transfer_to_vendor_id',
        'maintenance_reason_id',
        // Provenance of the category above: 'sheet' (from import), 'backfill'
        // (system-matched later), or 'manual' (human-set). See reason_matched_at.
        'reason_source',
        'reason_matched_at',
        'approval_status',
        'approved_amount',
        'approved_at',
        'maintenance_tags',
        'responsible',
        'approved_by',
        'expected_return_date',
        // Maintenance Checkpoint — the canonical promised ready-by date the whole progress-tracking system
        // measures against (ETA gauge, dashboard, escalation, overdue). expected_duration_days is the
        // duration typed at intake (we derive the date from it; a hand-edited date then wins).
        // last_checkpoint_at anchors "does this car still need an update in the current window".
        'expected_completion_date', 'expected_duration_days', 'last_checkpoint_at',
        'maintenance_notes',
        // sheet maintenance log (origin = 'sheet')
        'origin',
        'row_hash',
        'car_label',
        'plate',
        'event_status',
        'out_date',
        'follow_date',
        'actual_in_date',
        'base_on',
        'driver',
        'liable_party',
        'charge_to',
        'garage',
        'maintenance_type',
        'visit_context',
        'service_main',
        'service_sup',
        'damage_location',
        'severity',
        'fault_severity',
        'spare_part',
        'invoice_no',
        'cost',
        // Structured Parts + Labor breakdown roll-up (see maintenance_line_items). `cost` stays the
        // canonical grand total; these split it so the breakdown shows without re-summing the lines.
        'parts_total',
        'labor_total',
        'cost_is_itemized',
        // Garage Invoice Validation — the hand-keyed receipt grand total the itemised lines are checked
        // against, plus the mandatory note explaining any mismatch, and the accounting-bridge flag that
        // hands the itemised invoice to the finance/reconciliation engine (see syncLineItems).
        'receipt_total',
        'variance_explanation',
        'reconciliation_status',
        'reconciliation_flagged_at',
        'cost_notes',
        // customer-cases sheet log (origin = 'customer-sheet')
        'bill_receive',
        // Fleet Maintenance Workflow (origin = 'manual' ticket lifecycle)
        'workflow_status',
        // Repair Location — 'in_shop' (workshop pipeline) | 'on_site' (mobile job, car stays available).
        'repair_location',
        // Rental Eligibility — the inspector's one-time Decide-step call: may this car be rented BEFORE
        // maintenance is finished? false = mandatory (grounded until complete); true = deferrable (rental
        // pauses the ticket, resumes on return). Read by ContractEligibilityService::maintenanceCheck().
        'deferrable_for_rental',
        // When the ticket ENTERED its current workflow_status — the "time in stage" anchor. Stamped
        // automatically on every status change (see booted()); never hand-set by the workflow service.
        'last_state_change_at',
        // Pause Maintenance & Return to Service — the stage the ticket held before an operational pause
        // (restored verbatim on resume), plus who paused it, when, and why. paused_at/paused_reason stay
        // permanently as the historical stamp of the most recent pause (event-sourced); only
        // paused_from_status/paused_by are reset once a resume finalizes.
        'paused_from_status', 'paused_at', 'paused_by', 'paused_reason',
        // Enterprise Handover Workflow — when/by-whom the vehicle was marked physically returned, the
        // open discrepancy incident gating a resume, and quick pointers to the latest pause/resume
        // handover rows (see MaintenanceHandover / MaintenanceHandoverComparison / MaintenanceIncident).
        'vehicle_returned_at', 'vehicle_returned_by', 'active_incident_id',
        'last_pause_handover_id', 'last_resume_handover_id',
        // Temporary Vehicle Release — the currently-open release (car out of the shop mid-repair for a
        // road test / customer test / external inspection / storage). Null when the car is at the shop.
        // The ticket's workflow_status is UNCHANGED throughout — this is a vehicle-level overlay only.
        'active_temporary_release_id',
        'linked_contract_id',
        'trigger_reason',
        'customer_complaint',
        'suggested_findings',
        'trigger_detail',
        'test_drive_report',
        'findings',
        'test_odometer',
        'report_odometer',
        'intake_odometer',
        'dispatch_odometer',
        'receive_odometer',
        'return_odometer',
        'reinspect_odometer',
        'park_odometer',
        'garage_feedback',
        'requested_by', 'requested_at',
        // Inspection Request Review Gate — Controller (Lin & Marwa) sign-off before the request is
        // sent to the Inspector.
        'reviewed_by', 'reviewed_at', 'review_notes', 'review_rejection_reason', 'review_sent_at',
        'follow_ups',
        // Stage-timing anchors — durations are subtraction over these (see migration).
        'test_started_at', 'returned_at',
        'inspected_by', 'inspected_at',
        'dispatched_by', 'dispatched_at',
        // Current assigned driver — shared with the Supervisor delegation overlay. (Live location/
        // status moved to the canonical Logistics Dispatch task.)
        'assigned_driver_id',
        // Recovery (towing) — the winch/tow unit that recovered a broken-down car to the garage, captured
        // in place of an assigned driver on the recovery leg. See dispatchRecovery().
        'recovery_unit_name', 'recovery_unit_phone',
        // Which intake tab produced this ticket (routine_check | scheduled_dormancy) — traceability only.
        'test_kind',
        // Supervisor Notification & Delegation — priority tag + delegation overlay.
        'priority',
        'delegation_task', 'delegation_status', 'delegated_by', 'delegated_at',
        'repair_started_by', 'repair_started_at',
        'ready_by', 'ready_at',
        // Return-leg photo checkpoints — Ready for Pickup → In Our Park.
        'picked_up_from_garage_by', 'picked_up_from_garage_at',
        'park_arrived_by', 'park_arrived_at',
        'wf_closed_by', 'wf_closed_at',
        // Financial Decoupling (Deferred Cost) — who recorded the final cost after close, and when.
        'cost_recorded_at', 'cost_recorded_by',
        // Path A (Manual Entry) — when/by-whom we asked the garage for an itemised invoice.
        'invoice_requested_at', 'invoice_requested_by',
        // Awaiting-Invoice SLA — when the ticket entered awaiting_invoice (the 3-day overdue clock).
        'awaiting_invoice_since',
        // Pre-Maintenance Recommendation queue — the Supervisor's triage of an inspection recommendation
        // before it becomes an active maintenance job. scheduled_for = "review again on"; disposition +
        // note = how a dismissed recommendation was closed / a parts note; parts_ready = the spare arrived.
        'recommendation_scheduled_for',
        'recommendation_disposition',
        'recommendation_note',
        'recommendation_parts_ready',
        'recommendation_reviewed_by',
        'recommendation_reviewed_at',
        // Triage Routing Approval — Abu Maroof's recommended routing (destination + optional replacement +
        // note + who/when) held while it awaits a Supervisor's approve/reject.
        'triage_route_request',
    ];

    protected $casts = [
        'maintenance_tags'     => 'array',
        'expected_return_date' => 'date',
        'expected_completion_date' => 'date',
        'expected_duration_days'   => 'integer',
        'last_checkpoint_at'   => 'datetime',
        'out_date'             => 'date',
        'follow_date'          => 'date',
        'actual_in_date'       => 'date',
        'approved_amount'      => 'decimal:2',
        'cost'                 => 'decimal:2',
        'parts_total'          => 'decimal:2',
        'labor_total'          => 'decimal:2',
        'cost_is_itemized'     => 'boolean',
        'receipt_total'        => 'decimal:2',
        'reconciliation_flagged_at' => 'datetime',
        'approved_at'          => 'datetime',
        'reason_matched_at'    => 'datetime',
        // workflow
        'test_drive_report'    => 'array',
        'findings'             => 'array',
        'suggested_findings'   => 'array',
        'trigger_detail'       => 'array', // snapshot of WHY the system raised a periodic request (see systemRequestInspection)
        'follow_ups'           => 'array',
        'test_odometer'        => 'integer',
        'report_odometer'      => 'integer',
        'intake_odometer'      => 'integer',
        'dispatch_odometer'    => 'integer',
        'receive_odometer'     => 'integer',
        'return_odometer'      => 'integer',
        'reinspect_odometer'   => 'integer',
        'park_odometer'        => 'integer',
        'odometer_flags'       => 'array', // per-stage Odometer Continuity verdicts (OdometerContinuityService)
        'requested_at'         => 'datetime',
        'reviewed_at'          => 'datetime',
        'review_sent_at'       => 'datetime',
        'last_state_change_at' => 'datetime',
        'paused_at'            => 'datetime',
        'vehicle_returned_at'  => 'datetime',
        'test_started_at'      => 'datetime',
        'returned_at'          => 'datetime',
        'inspected_at'         => 'datetime',
        'dispatched_at'        => 'datetime',
        'delegated_at'         => 'datetime',
        'repair_started_at'    => 'datetime',
        'ready_at'             => 'datetime',
        'picked_up_from_garage_at' => 'datetime',
        'park_arrived_at'      => 'datetime',
        'wf_closed_at'         => 'datetime',
        'cost_recorded_at'     => 'datetime',
        'invoice_requested_at' => 'datetime',
        'awaiting_invoice_since' => 'datetime',
        // Recommendation queue triage
        'recommendation_scheduled_for' => 'datetime',
        'recommendation_reviewed_at'   => 'datetime',
        'recommendation_parts_ready'   => 'boolean',
        // Rental Eligibility — deferrable (true) vs mandatory (false) maintenance.
        'deferrable_for_rental'        => 'boolean',
        // Triage Routing Approval — the pending routing recommendation (JSON).
        'triage_route_request'         => 'array',
    ];

    /**
     * "Time in Stage" anchor, stamped centrally. Whenever `workflow_status` actually changes — on
     * creation (null → first stage) or any transition (setStatus / assignDispatch / dispatch / close /
     * reopen …) — we record the moment the ticket ENTERED its new stage. Living here rather than in the
     * workflow service guarantees the anchor can never drift from the stage it records and that a
     * future transition site can't forget to set it. `saveQuietly()` (e.g. recalcFromTasks) doesn't
     * touch workflow_status, so those quiet writes correctly leave the anchor untouched.
     */
    protected static function booted(): void
    {
        static::saving(function (self $ticket) {
            if ($ticket->isDirty('workflow_status')) {
                $ticket->last_state_change_at = now();
            }
        });
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /** The user (logistics driver) currently responsible for the car at the ticket's active stage. */
    public function assignedDriver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_driver_id');
    }

    /** The Supervisor who last triaged this recommendation (approve / reject / schedule / parts), if any. */
    public function recommendationReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recommendation_reviewed_by');
    }

    /** The Supervisor who delegated the current driver (pickup/dropoff), if any. */
    public function delegatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegated_by');
    }

    /**
     * Users watching this ticket — currently the delegated driver (reason 'delegated'), so pings +
     * status updates follow whoever is moving the car.
     */
    public function watchers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'maintenance_watchers')
            ->withPivot(['added_by', 'reason'])
            ->withTimestamps();
    }

    /**
     * Maintenance Checkpoint responsible users — the follow-up owners (default Waleed & Abdullah,
     * editable per ticket) who receive the checkpoint reminders and may submit updates. The ONLY
     * recipients the Checkpoint Scan targets for this ticket (falling back to the default supervisors
     * when empty; see MaintenanceCheckpointService::recipientsFor).
     */
    public function responsibles(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'maintenance_responsibles')
            ->withPivot(['added_by'])
            ->withTimestamps();
    }

    /** This ticket's progress checkpoints, newest first. */
    public function checkpoints(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MaintenanceCheckpoint::class, 'maintenance_id')->latest();
    }

    /** Presentation meta (emoji + colour + label) for the ticket's current fault severity, or null. */
    public function faultSeverityMeta(): ?array
    {
        return $this->fault_severity ? (self::FAULT_SEVERITY_META[$this->fault_severity] ?? null) : null;
    }

    /** Standalone workshop events (sheet log + hand-entered), not contract headers. */
    public function scopeWorkshopEvents(Builder $q): Builder
    {
        return $q->whereIn('origin', self::EVENT_ORIGINS);
    }

    /** True for a hand-entered event — the rows the sheet sync must never overwrite. */
    public function isManual(): bool
    {
        return $this->origin === self::ORIGIN_MANUAL;
    }

    /** Workflow tickets (any row carrying a lifecycle state). */
    public function scopeWorkflowTickets(Builder $q): Builder
    {
        return $q->whereNotNull('workflow_status');
    }

    /** Live workflow rows — on the board (diagnostics + open tickets), excluding terminal states. */
    public function scopeOpenWorkflow(Builder $q): Builder
    {
        return $q->whereNotNull('workflow_status')
            ->whereNotIn('workflow_status', self::WF_TERMINAL);
    }

    /** The pre-maintenance Recommendation queue — pending review + waiting-for-parts (fenced pre-ticket). */
    public function scopeRecommendationQueue(Builder $q): Builder
    {
        return $q->whereIn('workflow_status', self::WF_RECOMMENDATION_STATES);
    }

    /** True while this ticket is in the recommendation queue (not yet an approved/active maintenance job). */
    public function isRecommendation(): bool
    {
        return in_array($this->workflow_status, self::WF_RECOMMENDATION_STATES, true);
    }

    /** Triage routing recommendations awaiting a Supervisor's approve/reject (fenced pre-ticket). */
    public function scopeTriageApprovalQueue(Builder $q): Builder
    {
        return $q->where('workflow_status', self::WF_TRIAGE_APPROVAL_PENDING);
    }

    /** True while Abu Maroof's routing decision is parked awaiting the Supervisor's approval. */
    public function isTriageApproval(): bool
    {
        return $this->workflow_status === self::WF_TRIAGE_APPROVAL_PENDING;
    }

    /** Tickets sitting in awaiting_invoice — the car is back in service but the invoice hasn't landed. */
    public function scopeAwaitingInvoice(Builder $q): Builder
    {
        return $q->where('workflow_status', self::WF_AWAITING_INVOICE);
    }

    /** Days the ticket has been waiting on its invoice (null unless it's in awaiting_invoice). */
    public function invoiceDaysWaiting(): ?int
    {
        if ($this->workflow_status !== self::WF_AWAITING_INVOICE || ! $this->awaiting_invoice_since) {
            return null;
        }

        return (int) $this->awaiting_invoice_since->startOfDay()->diffInDays(today());
    }

    /** True once an awaiting-invoice ticket has blown the SLA (waiting > INVOICE_SLA_DAYS). */
    public function invoiceIsOverdue(): bool
    {
        return $this->workflow_status === self::WF_AWAITING_INVOICE
            && $this->awaiting_invoice_since
            && $this->awaiting_invoice_since->lt(now()->subDays(self::INVOICE_SLA_DAYS));
    }

    /**
     * Repair-ETA gauge — "the car needs N days; are we still inside that window?".
     * The target is `expected_return_date` (the ready-by date the supervisor/garage sets at dispatch
     * or check-in) when set; otherwise a fleet-default N-day target (DEFAULT_REPAIR_DAYS) measured
     * from the in-shop start, so a car in the shop always shows a live "day N of M" counter instead
     * of a dead "No ETA". `is_estimated` flags which of the two is in play. The in-shop clock is
     * anchored to the best available start stamp (garage arrival → dispatch out-date → pickup →
     * ticket creation). All maths is at DAY granularity so it reads like the shop floor talks
     * ("day 5 of 4"), never fractional hours.
     *
     * status: 'unknown' (no start stamp at all — never happens for a real ticket) ·
     *         'on_track' (before the target day) · 'due_today' (target day is today) ·
     *         'overdue' (past the target day).
     *
     * @return array{has_eta:bool, is_estimated:bool, status:string, expected_on:?string,
     *               started_on:?string, days_allotted:?int, days_elapsed:?int, days_left:int, days_over:int}
     */
    public function repairEta(): array
    {
        return self::etaFromDates(
            $this->repair_started_at ?? $this->out_date ?? $this->dispatched_at ?? $this->created_at,
            $this->effectiveExpectedCompletion(),
        );
    }

    /**
     * The canonical promised ready-by date every progress surface measures against: the Checkpoint
     * completion date if set, else the legacy dispatch `expected_return_date`, else null (the ETA
     * math then falls back to the fleet-default window). Single accessor so the ETA gauge, dashboard,
     * escalation and overdue detection can never disagree on which date is "the promise".
     */
    public function effectiveExpectedCompletion(): ?\Carbon\Carbon
    {
        return $this->expected_completion_date ?? $this->expected_return_date;
    }

    /**
     * The pure day-math behind the repair-ETA gauge, shared by BOTH the workflow ticket
     * (repairEta) and the contract-derived dashboard list (which reads its start/promise off the
     * open type-U maintenance contract). `$start` = when the car went into the shop; `$expected` =
     * the promised ready-by date, or null to fall back to the fleet-default N-day target. Single
     * source of truth so every surface reddens on the same day.
     *
     * @return array{has_eta:bool, is_estimated:bool, status:string, expected_on:?string,
     *               started_on:?string, days_allotted:?int, days_elapsed:?int, days_left:int, days_over:int}
     */
    public static function etaFromDates(?\Carbon\Carbon $start, ?\Carbon\Carbon $expected): array
    {
        $start = $start?->copy()->startOfDay();

        // No anchor to measure from at all — can't say anything.
        if (! $start) {
            return [
                'has_eta' => false, 'is_estimated' => false, 'status' => 'unknown', 'expected_on' => null,
                'started_on' => null, 'days_allotted' => null, 'days_elapsed' => null, 'days_left' => 0, 'days_over' => 0,
            ];
        }

        $today       = today();
        $hasPromise  = (bool) $expected;
        $defaultDays = (int) config('maintenance.default_repair_days', self::DEFAULT_REPAIR_DAYS);
        // The ready-by date: the real promise if one was set, else start + the fleet-default target.
        $expected = $hasPromise ? $expected->copy()->startOfDay() : $start->copy()->addDays($defaultDays);

        // Signed day delta: >0 means today is PAST the target date (overdue).
        $daysOver = (int) round($expected->diffInDays($today, false));
        $status   = $daysOver > 0 ? 'overdue' : ($daysOver === 0 ? 'due_today' : 'on_track');

        return [
            'has_eta'       => true,
            'is_estimated'  => ! $hasPromise,     // target is the default, not an explicitly set promise
            'status'        => $status,
            'expected_on'   => $expected->toDateString(),
            'started_on'    => $start->toDateString(),
            'days_allotted' => max(0, (int) round($start->diffInDays($expected, false))),
            'days_elapsed'  => max(0, (int) round($start->diffInDays($today, false))),
            'days_left'     => $daysOver < 0 ? abs($daysOver) : 0,
            'days_over'     => $daysOver > 0 ? $daysOver : 0,
        ];
    }

    /** The inspector who opened this ticket (snapshot survives a user deletion via nullOnDelete). */
    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }

    /** The Driver (Logistics) who requested the inspection that started this workflow, if any. */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** The Controller (Lin/Marwa) who approved or rejected the inspection review. */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Who recorded the final (deferred) repair cost after the ticket was closed. */
    public function costRecordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cost_recorded_by');
    }

    /** The Driver (Logistics) who collected the car from the garage on the return leg. */
    public function pickedUpFromGarageBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'picked_up_from_garage_by');
    }

    /** The maintenance (type-'U') contract this ticket was linked to at dispatch, if any. */
    public function linkedContract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'linked_contract_id');
    }

    /** The car this maintenance event is for (sheet log rows link straight to the vehicle). */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** The garage / workshop. */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** Every garage-choice decision recorded for this ticket (newest first) — the "why this garage?" trail. */
    public function recommendationDecisions()
    {
        return $this->hasMany(GarageRecommendationDecision::class)->latest();
    }

    /** The most recent garage-choice decision — surfaced on the ticket for the "Why this garage?" card. */
    public function latestRecommendationDecision()
    {
        return $this->hasOne(GarageRecommendationDecision::class)->latestOfMany();
    }

    /**
     * The DESTINATION garage of an in-flight transfer — set the moment a garage→garage move is
     * requested and the ticket drops to "Awaiting Pickup", cleared the moment the car checks in at
     * the destination. While it's set, `vendor` stays the garage the car is physically AT; this is
     * where it's headed. Drives the "→ [new garage]" tag on the live position.
     */
    public function transferToVendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'transfer_to_vendor_id');
    }

    /**
     * The physical MOVES this ticket has generated (pickup, garage-to-garage transfer, return) — the
     * "execution layer" of the lifecycle. A move is a LogisticsTask linked back by maintenance_id; it is
     * NOT a side-task but the moving part of THIS ticket. (Linked by a loose column, no DB FK.)
     */
    public function logisticsTasks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(LogisticsTask::class, 'maintenance_id');
    }

    /** The currently-open move for this ticket (the car is in/awaiting transit right now), if any. */
    public function activeMove(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(LogisticsTask::class, 'maintenance_id')
            ->whereNull('completed_at')
            ->latestOfMany();
    }

    /**
     * The car's LIVE POSITION — the single, unified "where is this car and what's happening to it",
     * derived automatically from the ticket's own moving parts so the maintenance board needs no second
     * logistics board. It fuses three signals, in priority:
     *
     *   1. an OPEN move (LogisticsTask) that is actually rolling  → "In Transit" (+ destination + driver)
     *   2. the ticket's single current garage (vendor_id)         → which garage the car is physically at
     *   3. the ticket's own `workflow_status`                     → the lifecycle stage label
     *
     * So a transfer between garages reads "In Transit" on the SAME ticket card (not a separate task log),
     * and "under_repair" reads "In Workshop · <garage>". Returns a presentation-ready shape the board,
     * the command view and the vehicle profile all render identically.
     *
     * @return array{phase:string,label:string,detail:?string,tone:string,garage:?string,destination:?string,driver:?string,since:?string,open_ticket_id:?int,moving:bool,transfer?:bool}
     */
    public function livePosition(): array
    {
        $status = $this->workflow_status;

        $base = fn (string $phase, string $label, string $tone, ?string $detail = null, array $extra = []) => array_merge([
            'phase'          => $phase,
            'label'          => $label,
            'tone'           => $tone,
            'detail'         => $detail,
            'garage'         => null,
            'destination'    => null,
            'driver'         => null,
            'since'          => null,
            'moving'         => false,
            'open_ticket_id' => in_array($status, self::WF_TERMINAL, true) || $status === null ? null : $this->id,
        ], $extra);

        if ($status === null || in_array($status, self::WF_TERMINAL, true)) {
            return $base('closed', 'Closed', 'green', null);
        }

        // (0) Temporary Vehicle Release — the car has physically left the workshop for a road test /
        // customer test / external inspection / storage while the SAME repair continues. This is the car's
        // real-world location right now, so it OUTRANKS the stage label below (the ticket_status is still
        // e.g. under_repair — that's shown separately as the lifecycle stage). It never coincides with a
        // logistics move (a temp release isn't a dispatched task), so no conflict with the moving check.
        if ($this->isTemporarilyReleased()) {
            $rel    = $this->activeTemporaryRelease;
            $reason = $rel?->reasonLabel();
            return $base('temporarily_released', 'Temporarily Released', 'amber',
                'Out of the workshop' . ($reason ? ' · ' . $reason : '') . ' — repair still open, resumes on return',
                [
                    'garage'    => $this->vendor?->name ?: ($this->garage ?: null),
                    'since'     => optional($rel?->released_at)->toIso8601String(),
                    'taken_by'  => $rel?->taken_by,
                ]);
        }

        // (2) The car's single current garage — the ticket's own vendor (the Supervisor's dispatch /
        // transfer decision); every open fault sits at this one garage under the single-garage model.
        $garage = $this->vendor?->name ?: ($this->garage ?: null);

        // The destination of an in-flight garage→garage transfer (null when the car isn't mid-transfer).
        // While set, `$garage` above is where the car physically IS; this is where it's being taken.
        $transferTo = $this->transfer_to_vendor_id ? ($this->transferToVendor?->name ?: null) : null;

        // (1) A rolling move overrides the lane — the car is physically on the road right now. "delivered"
        // / "at_destination" means it has ARRIVED, so that is NOT moving (the stint/workshop wins).
        $move   = $this->relationLoaded('activeMove') ? $this->activeMove : $this->activeMove()->first();
        $moving = $move && in_array($move->status, [
            LogisticsTask::STATUS_EN_ROUTE, LogisticsTask::STATUS_PICKED_UP, LogisticsTask::STATUS_IN_TRANSIT,
            LogisticsTask::STATUS_TO_DESTINATION, LogisticsTask::STATUS_TO_BASE,
        ], true);

        if ($moving) {
            $dest = $move->destination ?: $garage ?: 'the garage';
            $who  = $move->assigned_to_name ? ' · ' . $move->assigned_to_name : '';
            return $base('in_transit', 'In Transit', 'blue', 'En route to ' . $dest . $who, [
                'garage'      => $garage,
                'destination' => $dest,
                'driver'      => $move->assigned_to_name,
                'since'       => optional($move->status_changed_at)->toIso8601String(),
                'moving'      => true,
            ]);
        }

        // (3) Lifecycle stage. A car still awaiting pickup, or at the garage, reads from the stint vendor.
        return match ($status) {
            self::WF_INSPECTION_REQUESTED  => $base('inspection_requested', 'Inspection Requested', 'violet', 'A driver asked for a test drive'),
            self::WF_COMPLAINT_TRIAGE      => $base('complaint_triage', 'Pending Triage', 'amber', 'Customer complaint — Abu Maroof to triage'),
            self::WF_TRIAGE_APPROVAL_PENDING => $base('triage_approval_pending', 'Awaiting Routing Approval', 'violet', 'Abu Maroof recommended sending the car in — awaiting the supervisor\'s approval'),
            self::WF_INSPECTION_DIAGNOSTIC => $base('under_diagnosis', 'Under Diagnosis', 'violet', 'Test-drive diagnostic in progress'),
            // Pre-maintenance recommendation queue — a recommendation awaiting the Supervisor's review, or
            // approved-but-waiting-for-parts. Fenced: the car is NOT an active maintenance job here.
            self::WF_RECOMMENDATION_PENDING => $base('recommendation_pending', 'Pending Recommendation', 'violet', 'Recommended action awaiting the supervisor\'s approval'),
            self::WF_AWAITING_PARTS         => $base('awaiting_parts', 'Waiting for Parts', 'amber', 'Approved — waiting for the spare part before maintenance can start'),
            self::WF_INSPECTION_PENDING    => $base('awaiting_dispatch', 'Awaiting Dispatch', 'amber', "Awaiting the supervisor's garage decision"),
            // On-Site (mobile) job — the car is NOT out; it stays where it's parked, tagged only.
            self::WF_ON_SITE_PENDING       => $base('on_site_pending', 'Pending On-Site Service', 'teal', 'Minor job — to be done where the car is parked (stays available)'),
            // "Awaiting Pickup" — a driver has yet to collect the car. On a garage→garage TRANSFER the car
            // is STILL at its current garage (ground truth is vendor_id), so we name that as the location and
            // tag the destination it's being moved to; the board reads "Awaiting Pickup · [current] → [new]".
            self::WF_AWAITING_DISPATCH     => $transferTo
                ? $base('awaiting_pickup', 'Awaiting Pickup', 'amber', 'At ' . ($garage ?: 'the garage') . ' — transfer to ' . $transferTo . ', awaiting driver pickup', ['garage' => $garage, 'destination' => $transferTo, 'transfer' => true])
                : $base('awaiting_pickup', 'Awaiting Pickup', 'amber', 'Garage assigned' . ($garage ? ' (' . $garage . ')' : '') . ' — awaiting driver pickup', ['garage' => $garage]),
            // In Transit — en route to the destination. On a transfer the destination is transfer_to_vendor
            // (vendor_id still points at the garage the car left); otherwise it's the assigned garage.
            self::WF_IN_TRANSIT            => $base('in_transit', 'In Transit', 'blue', 'En route to ' . ($transferTo ?: $garage ?: 'the garage'), ['garage' => $transferTo ?: $garage, 'destination' => $transferTo ?: $garage, 'moving' => true, 'transfer' => (bool) $transferTo]),
            self::WF_UNDER_REPAIR          => $base('in_workshop', 'In Workshop', 'red', 'Under repair' . ($garage ? ' · ' . $garage : ''), ['garage' => $garage]),
            self::WF_REPAIR_REVIEW         => $base('repair_review', 'Repair Review', 'violet', 'Garage finished — supervisor reviewing the video before sign-off' . ($garage ? ' · ' . $garage : ''), ['garage' => $garage]),
            self::WF_READY_REINSPECTION    => $base('awaiting_reinspection', 'Final QA Re-inspection', 'amber', 'Major repair — back at our park, pending the Inspector\'s final QA sign-off'),
            self::WF_REINSPECTION_FAILED   => $base('reinspection_failed', 'Re-inspection Failed', 'red', 'Faults still unresolved — awaiting the supervisor\'s re-dispatch' . ($garage ? ' · last: ' . $garage : ''), ['garage' => $garage]),
            self::WF_READY_FOR_PICKUP      => $base('ready_for_pickup', 'Ready for Pickup', 'teal', 'Signed off — awaiting driver pickup from the garage' . ($garage ? ' · ' . $garage : ''), ['garage' => $garage]),
            self::WF_IN_OUR_PARK           => $base('in_our_park', 'In Our Park', 'green', 'Back at base — being auto-evaluated (closes immediately, or routes to final QA for major repairs)'),
            // Paused — returned to service — the repair is on hold and the car has been released back into
            // service, so it is NOT moving and NOT at the garage right now; the ticket simply remembers
            // where it will resume.
            self::WF_PAUSED_RETURNED_TO_SERVICE => $base('paused', 'Paused — Returned to Service', 'slate', 'Maintenance paused — car released back into service' . ($garage ? ' · resumes at ' . $garage : ''), ['garage' => $garage]),
            default                        => $base('open', $this->workflow_status, 'slate', null),
        };
    }

    /** Structured Parts + Labor lines that make up this ticket's cost (newest visit's breakdown). */
    public function lineItems(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MaintenanceLineItem::class, 'maintenance_id');
    }

    /**
     * The garage bills on this ticket — one per garage/visit ([[one Ticket → many Invoices]]). Each covers
     * only the faults it fixed and reconciles on its own clock; the ticket's `cost` is the sum of them.
     */
    public function invoices(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MaintenanceInvoice::class, 'maintenance_id')->latest('id');
    }

    /** Every garage-portal submission for this ticket (issued links + submitted/reviewed invoices). */
    public function garageInvoices(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(GarageInvoiceSubmission::class, 'maintenance_id');
    }

    /** The one garage invoice currently awaiting the team's audit (drives the "Awaiting Audit" flag). */
    public function pendingGarageInvoice(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(GarageInvoiceSubmission::class, 'maintenance_id')
            ->where('status', GarageInvoiceSubmission::STATUS_SUBMITTED)
            ->latestOfMany();
    }

    // ── Enterprise Handover Workflow (Pause & Return to Service) ────────────────────────────────

    /** Every custody-transfer event (pause + resume legs) ever captured on this ticket. */
    public function handovers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MaintenanceHandover::class, 'maintenance_id');
    }

    /** Every generated pause↔resume comparison report on this ticket (one per resume, always). */
    public function handoverComparisons(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MaintenanceHandoverComparison::class, 'maintenance_id');
    }

    /** Every discrepancy incident ever raised on this ticket's handovers. */
    public function incidents(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MaintenanceIncident::class, 'maintenance_id');
    }

    /** The open incident currently gating a resume, if any. */
    public function activeIncident(): BelongsTo
    {
        return $this->belongsTo(MaintenanceIncident::class, 'active_incident_id');
    }

    // ── Temporary Vehicle Release ──────────────────────────────────────────────────────────────────

    /** Every temporary release ever logged on this ticket (each out→in round trip), newest first. */
    public function temporaryReleases(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MaintenanceTemporaryRelease::class, 'maintenance_id')->latest('id');
    }

    /** The currently-open temporary release (car is out right now), if any. */
    public function activeTemporaryRelease(): BelongsTo
    {
        return $this->belongsTo(MaintenanceTemporaryRelease::class, 'active_temporary_release_id');
    }

    /** The most recent pause-leg handover (the "before" snapshot a resume is compared against). */
    public function lastPauseHandover(): BelongsTo
    {
        return $this->belongsTo(MaintenanceHandover::class, 'last_pause_handover_id');
    }

    /** The most recent resume-leg handover. */
    public function lastResumeHandover(): BelongsTo
    {
        return $this->belongsTo(MaintenanceHandover::class, 'last_resume_handover_id');
    }

    /**
     * Video Evidence — the garage's repair videos, uploaded to the ticket by a supervisor (Waleed/
     * Abdullah) as the permanent record the repair review is based on. Newest first. See [[maintenance-workflow-engine]].
     */
    public function media(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MaintenanceMedia::class, 'maintenance_id')->latest();
    }

    /**
     * The independently-routable FAULTS this ticket contains. The ticket is a CONTAINER: each task
     * carries its own status + garage and can be transferred without closing the ticket; the ticket's
     * cost / fault_severity / vendor_id are roll-ups of these tasks (see recalcFromTasks()).
     */
    public function tasks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MaintenanceTask::class, 'maintenance_id');
    }

    /** Asset Layer (read-only convenience): component installs/removals this ticket caused. */
    public function componentEvents(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ComponentEvent::class, 'maintenance_id');
    }

    /** Asset Layer (read-only convenience): service records born from this ticket. */
    public function serviceRecordEntries(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ServiceRecord::class, 'maintenance_id');
    }

    /**
     * Post-Repair Inspection verdicts recorded against this ticket at the final QC gate — the durable
     * "did the fix hold?" record (fixed / still_exists / new_issue) that layers on top of the close /
     * reopen transitions. Newest first. See [[reinspection-qc-layer]] / RepairInspectionService.
     */
    public function repairInspections(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(RepairInspection::class, 'maintenance_id')->latest('inspection_date');
    }

    /**
     * A live snapshot of where the ticket's faults stand, derived (never stored) from its tasks. The
     * authoritative lifecycle stays the engine-driven `workflow_status` (the ticket still closes
     * explicitly); this is the at-a-glance fault progress the container shows alongside it.
     *
     * @return array{total:int, pending:int, in_progress:int, completed:int, transferred:int, cancelled:int, open:int, pending_assignment:int, all_resolved:bool}
     */
    public function tasksProgress(): array
    {
        $tasks   = $this->relationLoaded('tasks') ? $this->tasks : $this->tasks()->get();
        $byState = $tasks->countBy('status');
        $total   = $tasks->count();
        $open    = $tasks->whereNotIn('status', MaintenanceTask::TERMINAL)->count();
        // Split-dispatch Dispatch Queue size: open faults not yet routed to any garage (pending + no stint).
        $pendingAssignment = $tasks->filter(fn (MaintenanceTask $x) => $x->isPendingAssignment())->count();

        return [
            'total'        => $total,
            'pending'      => (int) $byState->get(MaintenanceTask::STATUS_PENDING, 0),
            'in_progress'  => (int) $byState->get(MaintenanceTask::STATUS_IN_PROGRESS, 0),
            'completed'    => (int) $byState->get(MaintenanceTask::STATUS_COMPLETED, 0),
            'transferred'  => (int) $byState->get(MaintenanceTask::STATUS_TRANSFERRED, 0),
            'cancelled'    => (int) $byState->get(MaintenanceTask::STATUS_CANCELLED, 0),
            'open'         => $open,
            // Faults awaiting the delegate's garage assignment (the Dispatch Queue badge count).
            'pending_assignment' => $pendingAssignment,
            // Every fault resolved (and at least one existed) — the signal the container is ready to close.
            'all_resolved' => $total > 0 && $open === 0,
        ];
    }

    /**
     * Re-derive the ticket-level roll-ups from its child tasks. The ticket is the billing + headline
     * CONTAINER, so it shows the right total and the worst severity across its faults:
     *
     *   cost           ← Σ task line items, via recalcLineItemTotals() (lines own the grand total)
     *   fault_severity ← the worst severity among still-OPEN faults (falls back to the worst overall)
     *
     * Single-garage model: `vendor_id` is the ticket's ONE current garage and is owned by the Supervisor's
     * dispatch / transfer actions (assignDispatch / transferGarage) — it is deliberately NOT derived here,
     * so a per-fault cost change can never silently re-point the car to a different garage. Likewise this
     * does NOT touch `workflow_status` (engine-driven; the ticket closes explicitly — see tasksProgress()
     * for the derived "all faults resolved" signal). Writes quietly so it can be fired from child model
     * events without looping. Pass $fresh=true to reload the tasks/lineItems relations first (callers
     * inside a child's save hook need the just-written state).
     */
    public function recalcFromTasks(bool $fresh = false): void
    {
        if ($fresh) {
            $this->load(['tasks', 'lineItems']);
        }
        $tasks = $this->relationLoaded('tasks') ? $this->tasks : $this->tasks()->get();

        // Grand total stays owned by the line items (unchanged contract for every reader of `cost`).
        $this->recalcLineItemTotals();

        if ($tasks->isNotEmpty()) {
            $open = $tasks->whereNotIn('status', MaintenanceTask::TERMINAL);
            $pool = $open->isNotEmpty() ? $open : $tasks; // no open faults → grade by the whole set

            // Headline severity = the worst grade in the pool.
            $worst = $pool->filter(fn ($t) => isset(MaintenanceTask::SEVERITY_RANK[$t->severity]))
                ->sortByDesc(fn ($t) => MaintenanceTask::SEVERITY_RANK[$t->severity])
                ->first();
            if ($worst) {
                $this->fault_severity = $worst->severity;
            }
        }

        $this->saveQuietly();
    }

    /**
     * Roll the ticket's INVOICE-level fields up from its child invoices (see [[one Ticket → many
     * Invoices]]): the ticket's `receipt_total` becomes the sum of the per-invoice receipt totals, and its
     * headline `reconciliation_status` reads "reconciled" only once EVERY invoice is reconciled (else
     * "pending"). The canonical `cost` is NOT touched here — it stays owned by the line items
     * (recalcLineItemTotals), so it keeps summing across all invoices' lines transparently. A ticket with
     * no invoices is left exactly as-is (legacy lump-sum rows keep their hand-entered columns). Writes
     * quietly so it can fire from the MaintenanceInvoice save/delete hooks without looping.
     */
    public function recalcInvoiceAggregate(bool $fresh = false): void
    {
        if ($fresh) {
            $this->load('invoices');
        }
        $invoices = $this->relationLoaded('invoices') ? $this->invoices : $this->invoices()->get();

        if ($invoices->isEmpty()) {
            // The last invoice was just removed — clear the invoice-derived aggregates so no stale receipt
            // total / reconciliation flag lingers. (Only ever reached from an invoice save/delete hook, so
            // a pure legacy lump-sum row that never had invoices is never touched here.)
            $this->receipt_total             = null;
            $this->reconciliation_status     = null;
            $this->reconciliation_flagged_at = null;
            $this->saveQuietly();
            return;
        }

        $withReceipt = $invoices->whereNotNull('receipt_total');
        $this->receipt_total = $withReceipt->isNotEmpty()
            ? round((float) $withReceipt->sum('receipt_total'), 2)
            : null;

        $this->reconciliation_status = $invoices->every(fn ($i) => $i->reconciliation_status === self::RECON_RECONCILED)
            ? self::RECON_RECONCILED
            : self::RECON_PENDING;

        $earliest = $invoices->pluck('reconciliation_flagged_at')->filter()->min();
        $this->reconciliation_flagged_at = $earliest ?: $this->reconciliation_flagged_at;

        $this->saveQuietly();
    }

    /**
     * Re-derive the parts/labor totals (and the canonical `cost`) from this ticket's line items.
     * Called whenever the line set changes. When the ticket has been itemised, `cost` is OWNED by
     * the lines (parts_total + labor_total); an empty set clears the itemised flag and leaves any
     * manually-entered lump-sum `cost` untouched, so nothing is silently zeroed.
     *
     * Pure in-memory: it sets the attributes but does NOT save — the caller persists, so this can
     * run inside the same transaction as the line writes.
     */
    public function recalcLineItemTotals(): void
    {
        $items = $this->relationLoaded('lineItems') ? $this->lineItems : $this->lineItems()->get();

        if ($items->isEmpty()) {
            // The cost was owned by the lines (itemised) and every line is now gone — e.g. the last
            // invoice on the ticket was deleted — so the itemised total is genuinely zero. A hand-typed
            // lump sum (never itemised) is still preserved exactly as the manager entered it.
            if ($this->cost_is_itemized) {
                $this->cost = 0;
            }
            $this->parts_total      = null;
            $this->labor_total      = null;
            $this->cost_is_itemized = false;
            return;
        }

        $parts = round((float) $items->where('kind', MaintenanceLineItem::KIND_PART)->sum('line_total'), 2);
        $labor = round((float) $items->where('kind', MaintenanceLineItem::KIND_LABOR)->sum('line_total'), 2);

        $this->parts_total      = $parts;
        $this->labor_total      = $labor;
        $this->cost             = round($parts + $labor, 2); // line items OWN the grand total once itemised
        $this->cost_is_itemized = true;
    }

    /** The classified situation (links to the controlled reason->status vocabulary). */
    public function reason(): BelongsTo
    {
        return $this->belongsTo(MaintenanceReason::class, 'maintenance_reason_id');
    }
}
