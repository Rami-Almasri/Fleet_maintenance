<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One append-only entry in a vehicle's historical event log — the audit trail of the
 * Maintenance Workflow. Each row records a single lifecycle transition (who, what, when),
 * tagged inspector vs garage, with the maintenance contract that was active at the time.
 *
 * This is NOT a workshop event: it never lands on the /maintenance board and carries no money
 * (the `maintenances` visit row owns cost). It exists purely for history / audit tracking.
 * See [[maintenance-workflow-engine]] and VehicleLogService.
 */
class VehicleLogEvent extends Model
{
    // ── Event types (the lifecycle action that was logged) ───────────────────────
    public const EVENT_INSPECTION_REQUESTED = 'inspection_requested'; // Stage 0 requestInspection(): Driver asks for a test drive
    public const EVENT_REVIEW_APPROVED    = 'review_approved'; // approveInspectionReview(): Controller (Lin/Marwa) approved → sent to the Inspector
    public const EVENT_REVIEW_REJECTED    = 'review_rejected'; // rejectInspectionReview(): Controller (Lin/Marwa) rejected — nothing sent externally
    public const EVENT_DIAGNOSTIC_STARTED = 'diagnostic_started'; // UC-1 open()/startDiagnostic(): Inspector starts a test drive
    /**
     * A TEST DRIVE WAS OVERRULED. Somebody — the system or a person — asked for this car to be driven,
     * and somebody else decided it did not need to be: it went straight to a garage instead
     * (dispatchInsteadOfTest), or was sent there without a test ever being asked for (openDirectDispatch).
     *
     * Its OWN type rather than the generic `report_filed` it used to share, because the question it
     * answers is asked on its own and asked often: who decided this car did not need to be looked at,
     * and why? A row filed under "report filed" cannot be found by anyone asking that. The meta carries
     * the whole decision — who, the reason CODE, how the car travels, and which stage it was pulled out of.
     */
    public const EVENT_SENT_STRAIGHT_TO_GARAGE = 'sent_straight_to_garage';
    public const EVENT_REPORT_FILED       = 'report_filed';       // UC-2 submitReport(requires=true): ticket born
    public const EVENT_DIAGNOSTIC_CLEARED = 'diagnostic_cleared'; // UC-2 submitReport(requires=false): no work needed
    // UC-2 submitReport(decision=deferred): a real fault, recorded whole, with the repair postponed to a
    // named moment. The pair below is the whole life of that decision — deferred, then finally sent in.
    public const EVENT_MAINTENANCE_DEFERRED  = 'maintenance_deferred';
    public const EVENT_DEFERRED_ACTIVATED    = 'deferred_activated';
    public const EVENT_GARAGE_ASSIGNED    = 'garage_assigned';    // Phase 2 assignDispatch(): supervisor picked the garage + assigned a driver
    public const EVENT_DISPATCHED         = 'dispatched';         // UC-3 dispatch(): driver picked the car up and took it to the garage
    public const EVENT_UNDER_REPAIR       = 'under_repair';       // UC-4 markUnderRepair(): garage received it
    public const EVENT_READY              = 'ready';              // UC-5 markReady(): garage finished
    public const EVENT_CLOSED             = 'closed';             // UC-6 close(): re-inspected, back in service
    public const EVENT_REOPENED           = 'reopened';          // markReinspectionFailed(): re-inspection failed → back to the supervisor
    public const EVENT_TYPE_CHANGED      = 'type_changed';      // updateMaintenanceType(): classification corrected mid-lifecycle
    public const EVENT_REASSIGNED        = 'reassigned';        // reassign(): supervisor moved the car to a different driver
    public const EVENT_STATUS_UPDATE     = 'status_update';     // respondStatus(): driver posted the car's current location/status
    public const EVENT_PRIORITIZED       = 'prioritized';       // LEGACY (priority tag retired → fault_severity); kept so old timeline rows still resolve
    public const EVENT_DELEGATED         = 'delegated';         // delegate(): supervisor assigned a driver to pickup/dropoff
    public const EVENT_COST_RECORDED     = 'cost_recorded';     // recordCost(): final (deferred) repair cost entered after close
    public const EVENT_INVOICE_REQUESTED = 'invoice_requested'; // requestInvoice(): asked the garage for an itemised invoice (Path A)
    public const EVENT_TRANSPORT_ASSIGNED = 'transport_assigned'; // beginGarageTransfer(): Supervisor chose Recovery Truck vs Company Driver for the transfer leg
    public const EVENT_RETURNED_TO_SERVICE = 'returned_to_service'; // pauseForRental(): repair interrupted, car released back into service (ticket kept)
    public const EVENT_RESUMED           = 'resumed';           // resumeMaintenance(): car back — the SAME ticket continues from the exact stage it paused at
    // Enterprise Handover Workflow — the vehicle was physically handed back but the resume handover
    // paperwork is still pending, a discrepancy was flagged on the pause↔resume comparison, or that
    // discrepancy was cleared.
    public const EVENT_VEHICLE_RETURNED     = 'vehicle_returned';     // markVehicleReturned(): car is physically back, handover due
    public const EVENT_HANDOVER_INCIDENT    = 'handover_incident';    // resumeMaintenance(): comparison breached a threshold — resume gated
    public const EVENT_INCIDENT_ACKNOWLEDGED = 'incident_acknowledged'; // acknowledgeIncident(): discrepancy cleared, resume finalized
    // Temporary Vehicle Release — the car left the shop mid-repair (road test / customer test / external
    // inspection / storage) and came back; the ticket's workflow_status never changed. See
    // temporarilyReleaseVehicle() / returnTemporarilyReleasedVehicle().
    public const EVENT_TEMP_RELEASED        = 'temp_released';        // car temporarily taken out of the workshop, ticket stays open
    public const EVENT_TEMP_RETURNED        = 'temp_returned';        // car brought back to the workshop, distance recorded
    // The legs BETWEEN those two moments — the release is a round trip (dispatch out → pickup → arrive
    // at the destination → dispatch back → pickup → arrive at the garage), and every hand-over of the
    // car is logged so the trip reads as a continuous story rather than a gap between out and in.
    public const EVENT_TEMP_MOVE            = 'temp_release_move';    // a leg of the release round trip turned over

    // ── Oil recall relay (a car brought back mid-rental for oil) ────────────────────────────────────
    // The gate and the brief. The first is the moment a recall is allowed to move at all — Sales have
    // agreed the return with the customer — and it is the only thing standing between an oil decision
    // and a driver being sent to a customer's doorstep, so it is audited as its own event rather than
    // folded into a generic status update.
    public const EVENT_OIL_RECALL_SALES_CONFIRMED = 'oil_recall_sales_confirmed'; // confirmSales(): Sales agreed the return → collection released
    public const EVENT_OIL_RECALL_INSTRUCTED      = 'oil_recall_instructed';      // setCollectionInstructions(): what the car owes on arrival (oil change always)
    // The hand-over at the gate. A recalled car with NO test has nobody waiting for it — there is no
    // inspection request, so no review card and no Inspector. This is the event that hands it to the
    // Supervisors as a real ticket in their dispatch queue, where reading the dial and picking the garage
    // is what they already do all day.
    public const EVENT_OIL_RECALL_HANDED_TO_SUPERVISOR = 'oil_recall_handed_to_supervisor'; // handOverAtWorkshop()
    // The far end, and the only event here that changes the CAR rather than the arrangement: the oil
    // was physically changed and the reading recorded, so the next interval runs from that number.
    public const EVENT_OIL_CHANGE_RECORDED        = 'oil_change_recorded';        // recordOilChange(): oil changed at N km → the car's service anchor moved
    // …and the step that actually ends a recall: the customer has their rental back.
    public const EVENT_OIL_RECALL_RETURNED        = 'oil_recall_returned';        // markReturnedToCustomer(): car handed back after the change
    // ── Ticket DESTRUCTION — the one event that must outlive its own subject ────────────────────────
    // A deleted ticket used to leave no trace at all: its rows vanished (or, for `nullOnDelete` children
    // like this table, quietly detached), so "no history" and "history destroyed" were indistinguishable
    // afterwards. That ambiguity is what makes a completeness/confidence flag unable to tell the truth.
    //
    // NOTE THE SELF-REFERENCE PROBLEM: this row's own `maintenance_id` is nulled the instant the ticket
    // it describes is deleted. The ticket's identity is therefore ALSO written into `meta` (ticket_id,
    // origin, workflow_status, and the child rows lost), because meta is the only part of the row the
    // cascade cannot reach. Always read the deleted ticket's id from meta, never from maintenance_id.
    public const EVENT_TICKET_DELETED = 'ticket_deleted';   // a maintenance ticket was destroyed (hard delete / tombstone)

    // ── Readiness-gate events (not part of the maintenance workflow, but on the same vehicle trail) ──
    public const EVENT_READINESS_CONFIRMED = 'readiness_confirmed'; // setReady(): a clean car returned to service (no advisories were open)
    public const EVENT_READINESS_OVERRIDE  = 'readiness_override';  // setReady(): a user proceeded PAST open readiness advisories — logged with the reason
    public const EVENT_CONDITION_GRADED    = 'condition_graded';    // updateCondition(): the Visual Condition Grade was set/changed
    public const EVENT_CLEANING_UPDATED    = 'cleaning_updated';    // ReadinessController::setChecklistField(): a car's cleaning status changed (e.g. dirty → clean)
    public const EVENT_ODOMETER_CORRECTED  = 'odometer_corrected';  // OdometerChangeRequestController::approve(): a significant manual odometer edit was approved
    // ── Per-fault (maintenance_task) events — each scoped to one task via maintenance_task_id ─────
    // ── The ODOO FINANCIAL BRIDGE (§33) ────────────────────────────────────────────────────────────
    // A cost leaving FleetView for the accounting system is a financial act on the car, so it belongs
    // in the car's own timeline rather than only in an integration log. These five are the moments an
    // auditor asks about: an obligation was recognised, somebody approved it, it went, it landed, it
    // did not. The per-attempt technical detail (payloads, error codes, retries) stays in
    // financial_event_attempts — this is the human-readable trail beside the repair it paid for.
    public const EVENT_FINANCIAL_EVENT_RAISED = 'financial_event_raised'; // a cost was recognised as owed to Odoo
    public const EVENT_FINANCIAL_APPROVED     = 'financial_approved';     // a person approved it for sending
    public const EVENT_FINANCIAL_SYNC_STARTED = 'financial_sync_started'; // a push began (status → SENDING)
    public const EVENT_FINANCIAL_SYNCED       = 'financial_synced';       // Odoo confirmed the document
    public const EVENT_FINANCIAL_SYNC_FAILED  = 'financial_sync_failed';  // Odoo refused / could not be reached
    public const EVENT_FINANCIAL_CANCELLED    = 'financial_cancelled';    // the obligation was withdrawn

    public const EVENT_TASK_IDENTIFIED   = 'task_identified';   // a fault was logged on the ticket
    public const EVENT_TASK_ASSIGNED     = 'task_assigned';     // the fault was routed to a garage (stint opened)
    public const EVENT_TASK_TRANSFERRED  = 'task_transferred';  // the fault was moved to a different garage
    public const EVENT_TASK_RESOLVED     = 'task_resolved';     // the fault was fixed (or cancelled)
    public const EVENT_SERVICE_LOGGED    = 'service_logged';    // a routine service fault (oil/battery) was completed → its Service Reminder rolled forward
    public const EVENT_TASK_REINSPECTION_FAILED = 'task_reinspection_failed'; // QC: the garage returned it unfixed, failed re-inspection
    public const EVENT_TASK_MARKED_INCORRECT    = 'task_marked_incorrect';    // delegate overruled the inspector — the fault was a mis-diagnosis
    public const EVENT_TASK_LABOR_CORRECTED     = 'task_labor_corrected';     // an already-recorded attempt labor time was deliberately corrected (old → new + reason)

    // ── The per-fault WORK CLOCK (maintenance_task_work_sessions) ──────────────────────────────────
    // Four events because a repair's time is four separate facts: when hands went on the fault, when
    // they came off and WHY it stopped, when they went back on, and when the bench released it. A
    // timeline that only says "worked 6h" cannot distinguish three hours of labor from three hours of
    // waiting for a part — these events are what makes that distinction auditable after the fact.
    public const EVENT_TASK_WORK_STARTED  = 'task_work_started';  // a work session opened on this fault
    public const EVENT_TASK_WORK_PAUSED   = 'task_work_paused';   // work stopped; meta.block_reason says what for
    public const EVENT_TASK_WORK_RESUMED  = 'task_work_resumed';  // the block ended and work resumed
    public const EVENT_TASK_WORK_STOPPED  = 'task_work_stopped';  // the running interval was closed by a release/transfer/resolve
    // An exceptional, permission-gated acceptance of labor hours ABOVE the recorded active-work ceiling.
    public const EVENT_TASK_LABOR_OVERRIDE = 'task_labor_override';

    // ── System check requirements — the obligation chain ([[VehicleCheckRequirement]]) ──────────────
    // The system asked for a check; an inspector answered it; a decision was taken; the obligation
    // ended. Four events rather than one because the whole point of the entity is that these are
    // SEPARATE facts: a car whose battery check was raised and never answered must look different on
    // the timeline from one where an inspector looked and found nothing. Each row carries the
    // requirement id, its reason code + params, and the structured result/decision in `meta`.
    public const EVENT_CHECK_RAISED    = 'check_raised';    // raise(): a condition became a required check
    public const EVENT_CHECK_INSPECTED = 'check_inspected'; // recordResult(): a human answered it
    public const EVENT_CHECK_DECIDED   = 'check_decided';   // decide()/linkAction(): what to do about it
    public const EVENT_CHECK_RESOLVED  = 'check_resolved';  // resolve()/cancel(): the obligation ended

    // ── Severity Review (Diagnostic QC) — a supervisor's decision on an under-graded ticket ─────────
    public const EVENT_SEVERITY_UPGRADED    = 'severity_upgraded';    // QC upgrade applied: fault_severity raised to the recommendation
    public const EVENT_SEVERITY_REVIEW_KEPT = 'severity_review_kept'; // QC "keep current": the recommendation was reviewed and dismissed as a false alarm

    // ── Finding approval — a finding the car's own data disagrees with ([[FindingApprovalService]]) ──
    // Three events rather than one, for the same reason the check chain has four: "someone tried to log
    // an oil change this car did not need" and "a manager approved it anyway" are separate facts, and the
    // car's history is unreadable if the second one silently erases the first. WHO TRIED is on the
    // `required` row; WHO DECIDED is on the approved/rejected one, which also names the requester.
    public const EVENT_FINDING_APPROVAL_REQUIRED = 'finding_approval_required'; // logged, but held: the data disagrees
    public const EVENT_FINDING_APPROVED          = 'finding_approved';          // an approver overruled the data — the job goes ahead
    public const EVENT_FINDING_REJECTED          = 'finding_rejected';          // an approver refused it — it never becomes work

    // Deferred-invoice decoupling — repair signed off with the invoice still pending, then received.
    // The maintenance visit's own contract (type 'U'), opened when the car is booked in for a look and
    // closed when Final QA signs it back into service — see MaintenanceWorkflowService::openMaintenanceContract().
    public const EVENT_CONTRACT_OPENED  = 'maintenance_contract_opened';
    public const EVENT_CONTRACT_CLOSED  = 'maintenance_contract_closed';

    public const EVENT_AWAITING_INVOICE = 'awaiting_invoice';  // closed operationally, invoice deferred
    public const EVENT_INVOICE_RECEIVED = 'invoice_received';  // the outstanding invoice landed → fully closed

    // Garage Invoice Portal — the outside garage self-submits an itemised invoice, the team audits it.
    public const EVENT_GARAGE_INVOICE_SUBMITTED = 'garage_invoice_submitted'; // garage sent an invoice via the public link
    public const EVENT_GARAGE_INVOICE_ACCEPTED  = 'garage_invoice_accepted';  // team accepted it → applied to the ticket
    public const EVENT_GARAGE_INVOICE_REJECTED  = 'garage_invoice_rejected';  // team rejected it

    // HISTORICAL — the retired pre-maintenance approval gate and its parts branch. Nothing writes these any
    // more (a report now opens a ticket immediately, and required parts raise their own part requests), but
    // rows logged while the gate was live still exist and must keep rendering on the vehicle timeline.
    // See [[inspection-required-parts-split]].
    public const EVENT_RECOMMENDATION_APPROVED  = 'recommendation_approved';  // gate approved → entered the dispatch pipeline
    public const EVENT_RECOMMENDATION_DISMISSED = 'recommendation_dismissed'; // gate dismissed: rejected / not-required, no maintenance
    public const EVENT_RECOMMENDATION_SCHEDULED = 'recommendation_scheduled'; // gate deferred to a later date
    public const EVENT_PARTS_ORDERED            = 'parts_ordered';            // waited on a spare before starting
    public const EVENT_PARTS_READY              = 'parts_ready';              // the spare arrived → ready to start

    // ── Parts Purchase + Repair Intelligence — the part-request lifecycle on the vehicle trail ──────
    // The INSPECTOR's technical requirement, recorded during the report — what the repair is expected to
    // need. Deliberately its own event, BEFORE part_requested: nothing is ordered and no money is committed
    // until the coordinator converts it once the garage is known. See MaintenanceRequiredPartService.
    public const EVENT_PART_REQUIRED          = 'part_required';           // inspection listed a part the repair will need
    public const EVENT_PART_REQUESTED         = 'part_requested';          // a part request was opened (customer or garage source)
    public const EVENT_PART_APPROVED          = 'part_approved';           // the request was approved for purchase
    public const EVENT_PART_REJECTED          = 'part_rejected';           // the request was rejected
    public const EVENT_PART_PURCHASED         = 'part_purchased';          // a part was bought (garage or supplier), price recorded
    public const EVENT_PART_DELIVERED         = 'part_delivered';          // the purchased part arrived at the workshop (delivered_at set)
    public const EVENT_PART_INSTALLED         = 'part_installed';          // the purchased part was fitted → cost bridged to the ticket
    public const EVENT_PART_COMPLETED         = 'part_completed';          // the request was closed out
    public const EVENT_PART_DUPLICATE_FLAGGED = 'part_duplicate_flagged';  // duplicate-purchase detected → investigation opened
    public const EVENT_PART_RECURRENCE_FLAGGED = 'part_recurrence_flagged'; // a previously-fixed fault came back → warning/investigation

    // ── Spare keys — the NEED half of the lifecycle. The buy half already has events above
    //    (part_requested/approved/rejected/purchased) and the key itself lands as component_installed,
    //    so only the three moments that belong to the requirement get their own verbs here. ───────
    public const EVENT_SPARE_KEY_REQUIRED           = 'spare_key_required';            // a car was recorded as needing a spare key
    public const EVENT_SPARE_KEY_PURCHASE_REQUESTED = 'spare_key_purchase_requested';  // the need was turned into a purchase request
    public const EVENT_SPARE_KEY_RECEIVED           = 'spare_key_received';            // physical key(s) arrived and became components
    public const EVENT_SPARE_KEY_CANCELLED          = 'spare_key_cancelled';           // the need was withdrawn (found / raised in error)

    // ── Warranty-aware operations — the trail of "could somebody else have paid for this?" ────────
    //
    // These are on the VEHICLE timeline rather than only on the case, because the question they
    // answer is asked about a CAR: "why did we pay for this gearbox?" is read on the car's history,
    // months later, by somebody who has never heard of the case. Each row carries the case id, the
    // verdict and its reason code in `meta`, so the timeline reads as a sentence and the audit reads
    // as data.
    public const EVENT_WARRANTY_RECORDED        = 'warranty_recorded';        // a promise was written down
    public const EVENT_WARRANTY_UPDATED         = 'warranty_updated';         // its terms were corrected
    public const EVENT_WARRANTY_VOIDED          = 'warranty_voided';          // destroyed before it ran out
    public const EVENT_WARRANTY_EXPIRING        = 'warranty_expiring';        // the pre-expiry warning fired
    public const EVENT_COVERAGE_REVIEW_OPENED   = 'coverage_review_opened';   // "we don't know" became somebody's job
    public const EVENT_COVERAGE_CONFIRMED       = 'coverage_confirmed';       // a human said: they owe us this
    public const EVENT_COVERAGE_REJECTED        = 'coverage_rejected';        // a human said: this one is ours
    public const EVENT_WARRANTY_CASE_OPENED     = 'warranty_case_opened';     // the claim path started
    public const EVENT_WARRANTY_AUTHORIZED      = 'warranty_authorized';      // the provider gave a go-ahead
    public const EVENT_WARRANTY_SENT_TO_PROVIDER = 'warranty_sent_to_provider'; // the car/part went to them
    public const EVENT_WARRANTY_CLAIM_SUBMITTED = 'warranty_claim_submitted'; // the paperwork went in
    public const EVENT_WARRANTY_CLAIM_APPROVED  = 'warranty_claim_approved';  // they accepted it
    public const EVENT_WARRANTY_CLAIM_REJECTED  = 'warranty_claim_rejected';  // they refused — and why
    public const EVENT_WARRANTY_RECOVERY        = 'warranty_recovery_recorded'; // what we got back / avoided
    public const EVENT_WARRANTY_CASE_CLOSED     = 'warranty_case_closed';
    /**
     * THE ONE THAT MATTERS MOST. Somebody bought something the manufacturer might have owed us, on
     * purpose, with a reason. Not a rule being broken — a rule being applied and then consciously
     * set aside, which is the only version of this that is safe to allow. Carries the actor, the
     * reason and the verdict that was overridden.
     */
    public const EVENT_WARRANTY_PROCUREMENT_OVERRIDE = 'warranty_procurement_override';

    // ── Asset Layer — display mirror of component_events (that table stays the source of truth;
    //    these rows make asset movements visible on the Vehicle Timeline with no new joins) ─────
    public const EVENT_COMPONENT_INSTALLED   = 'component_installed';   // a physical component was fitted to this car
    public const EVENT_COMPONENT_REMOVED     = 'component_removed';     // a component came off (reason + disposition in meta)
    public const EVENT_COMPONENT_TRANSFERRED = 'component_transferred'; // a component moved between this car and another
    public const EVENT_COMPONENT_DISPOSED    = 'component_disposed';    // a component's story ended (scrapped/returned/sold)

    // ── ACCIDENT CASES — the crash, and everything decided about it afterwards ─────────────────
    //
    // On the VEHICLE's timeline rather than only on the case, for the same reason the warranty
    // events are: the question these answer is asked about a CAR. "Why was this car off the road for
    // three weeks in March?" is read on the car's history, months later, by somebody who has never
    // opened the accident page. Each row carries the case id in `accident_case_id` (and its FK-free
    // twin `accident_ref`), so the CASE's own timeline is the same rows filtered one way and the
    // car's is the same rows filtered another. One store, two readings.
    //
    // The set is deliberately fine-grained. "Accident updated" would be useless: the six moments
    // anybody ever asks about — who had the car, what the police said, whose fault it was, what the
    // insurer agreed, what it cost, who waived a requirement — each need to be findable on their own.
    public const EVENT_ACCIDENT_REPORTED        = 'accident_reported';         // the case was opened
    public const EVENT_ACCIDENT_CONTEXT_CAPTURED = 'accident_context_captured'; // who had the car, frozen
    public const EVENT_ACCIDENT_DETAILS_UPDATED = 'accident_details_updated';  // what happened, corrected/expanded
    public const EVENT_ACCIDENT_DAMAGE_RECORDED = 'accident_damage_recorded';  // a damaged area was logged
    public const EVENT_ACCIDENT_ASSESSED        = 'accident_assessed';         // the damage assessment was completed
    public const EVENT_POLICE_REPORT_RECORDED   = 'police_report_recorded';    // number + date captured
    public const EVENT_POLICE_REPORT_VERIFIED   = 'police_report_verified';    // somebody checked it
    /** THE ONE THAT MATTERS MOST HERE: a required document was waived on purpose, by name, with a reason. */
    public const EVENT_POLICE_REPORT_BYPASSED   = 'police_report_bypassed';
    public const EVENT_ACCIDENT_LIABILITY_SET   = 'accident_liability_set';    // whose fault — a named human's call
    public const EVENT_ACCIDENT_CLAIM_UPDATED   = 'accident_claim_updated';    // the insurer's side moved
    public const EVENT_ACCIDENT_FINANCIAL_RECORDED = 'accident_financial_recorded'; // a figure was written down
    public const EVENT_ACCIDENT_REPAIR_LINKED   = 'accident_repair_linked';    // a maintenance ticket was parented here
    public const EVENT_ACCIDENT_DOCUMENT_ADDED  = 'accident_document_added';   // a file joined the dossier
    public const EVENT_ACCIDENT_STAGE_CHANGED   = 'accident_stage_changed';    // the case moved along the ladder
    public const EVENT_ACCIDENT_CLOSED          = 'accident_closed';
    public const EVENT_ACCIDENT_REOPENED        = 'accident_reopened';         // authorised, with a reason

    /**
     * Audit bucket per event. Reuses Maintenance::FINDING_SOURCES vocabulary so the workflow
     * log and the finding source on the visit speak the same language:
     *   - 'inspector' = Abu Maroof's side (diagnostic, report, re-inspection/close)
     *   - 'garage'    = the workshop side (dispatch, repair, ready, reopen)
     */
    public const SOURCE_BY_EVENT = [
        self::EVENT_INSPECTION_REQUESTED => Maintenance::FINDING_INSPECTOR,
        self::EVENT_REVIEW_APPROVED    => Maintenance::FINDING_INSPECTOR,
        self::EVENT_REVIEW_REJECTED    => Maintenance::FINDING_INSPECTOR,
        self::EVENT_DIAGNOSTIC_STARTED => Maintenance::FINDING_INSPECTOR,
        self::EVENT_REPORT_FILED       => Maintenance::FINDING_INSPECTOR,
        self::EVENT_DIAGNOSTIC_CLEARED => Maintenance::FINDING_INSPECTOR,
        // Both halves of a deferral are the inspector's/supervisor's own record of the car, not a garage's.
        self::EVENT_MAINTENANCE_DEFERRED => Maintenance::FINDING_INSPECTOR,
        self::EVENT_DEFERRED_ACTIVATED   => Maintenance::FINDING_INSPECTOR,
        self::EVENT_GARAGE_ASSIGNED    => Maintenance::FINDING_GARAGE,
        self::EVENT_DISPATCHED         => Maintenance::FINDING_GARAGE,
        self::EVENT_UNDER_REPAIR       => Maintenance::FINDING_GARAGE,
        self::EVENT_READY              => Maintenance::FINDING_GARAGE,
        self::EVENT_REOPENED           => Maintenance::FINDING_GARAGE,
        self::EVENT_CLOSED             => Maintenance::FINDING_INSPECTOR,
        self::EVENT_TYPE_CHANGED       => Maintenance::FINDING_INSPECTOR,
        self::EVENT_REASSIGNED         => Maintenance::FINDING_GARAGE,
        self::EVENT_STATUS_UPDATE      => Maintenance::FINDING_GARAGE,
        self::EVENT_PRIORITIZED        => Maintenance::FINDING_INSPECTOR,
        self::EVENT_DELEGATED          => Maintenance::FINDING_GARAGE,
        self::EVENT_COST_RECORDED      => Maintenance::FINDING_INSPECTOR,
        self::EVENT_SERVICE_LOGGED     => Maintenance::FINDING_GARAGE, // the service was physically performed at/by the workshop
        self::EVENT_INVOICE_REQUESTED  => Maintenance::FINDING_GARAGE, // a request aimed at the workshop
        // Destroying a ticket is an administrative act on our own records, not workshop work.
        self::EVENT_TICKET_DELETED      => Maintenance::FINDING_INSPECTOR,
        self::EVENT_READINESS_CONFIRMED => Maintenance::FINDING_INSPECTOR, // a readiness sign-off is an inspector-side act
        self::EVENT_READINESS_OVERRIDE  => Maintenance::FINDING_INSPECTOR, // a warning-override sign-off is likewise inspector-side
        self::EVENT_CONDITION_GRADED    => Maintenance::FINDING_INSPECTOR,
        self::EVENT_ODOMETER_CORRECTED  => Maintenance::FINDING_INSPECTOR, // an admin sign-off is likewise inspector-side
        // Opening/closing the visit's contract is our own bookkeeping, not workshop work.
        self::EVENT_CONTRACT_OPENED    => Maintenance::FINDING_INSPECTOR,
        self::EVENT_CONTRACT_CLOSED    => Maintenance::FINDING_INSPECTOR,
        self::EVENT_AWAITING_INVOICE   => Maintenance::FINDING_INSPECTOR, // sign-off with invoice deferred
        self::EVENT_INVOICE_RECEIVED   => Maintenance::FINDING_INSPECTOR,
        // Bookkeeping on our own records — the accounting bridge is our side, never the workshop's.
        self::EVENT_FINANCIAL_EVENT_RAISED => Maintenance::FINDING_INSPECTOR,
        self::EVENT_FINANCIAL_APPROVED     => Maintenance::FINDING_INSPECTOR,
        self::EVENT_FINANCIAL_SYNC_STARTED => Maintenance::FINDING_INSPECTOR,
        self::EVENT_FINANCIAL_SYNCED       => Maintenance::FINDING_INSPECTOR,
        self::EVENT_FINANCIAL_SYNC_FAILED  => Maintenance::FINDING_INSPECTOR,
        self::EVENT_FINANCIAL_CANCELLED    => Maintenance::FINDING_INSPECTOR,
        self::EVENT_GARAGE_INVOICE_SUBMITTED => Maintenance::FINDING_GARAGE,   // the garage sent the invoice
        self::EVENT_GARAGE_INVOICE_ACCEPTED  => Maintenance::FINDING_INSPECTOR, // our team's audit decision
        self::EVENT_GARAGE_INVOICE_REJECTED  => Maintenance::FINDING_INSPECTOR,
        self::EVENT_RETURNED_TO_SERVICE       => Maintenance::FINDING_INSPECTOR, // an operational decision to release the car
        self::EVENT_RESUMED                   => Maintenance::FINDING_GARAGE,    // the car re-enters the repair pipeline
        self::EVENT_VEHICLE_RETURNED          => Maintenance::FINDING_INSPECTOR, // an operational "it's back" checkpoint
        self::EVENT_HANDOVER_INCIDENT         => Maintenance::FINDING_INSPECTOR, // a management-facing discrepancy flag
        self::EVENT_INCIDENT_ACKNOWLEDGED     => Maintenance::FINDING_INSPECTOR, // a management sign-off clearing the gate
        self::EVENT_TEMP_RELEASED             => Maintenance::FINDING_INSPECTOR, // an operational decision to take the car out
        self::EVENT_TEMP_RETURNED             => Maintenance::FINDING_GARAGE,    // the car is back at the workshop
        self::EVENT_TEMP_MOVE                 => Maintenance::FINDING_INSPECTOR, // moving the car is coordination, not workshop work
        // Both are office-side coordination decisions, not workshop work.
        self::EVENT_OIL_RECALL_SALES_CONFIRMED => Maintenance::FINDING_INSPECTOR,
        self::EVENT_OIL_RECALL_INSTRUCTED      => Maintenance::FINDING_INSPECTOR,
        self::EVENT_OIL_RECALL_HANDED_TO_SUPERVISOR => Maintenance::FINDING_INSPECTOR,
        // …but the change itself is workshop work, physically performed on the car.
        self::EVENT_OIL_CHANGE_RECORDED        => Maintenance::FINDING_GARAGE,
        // Handing the car back is coordination, not work on the car.
        self::EVENT_OIL_RECALL_RETURNED        => Maintenance::FINDING_INSPECTOR,
        // Recommendation triage — a Supervisor's pre-garage management decision → inspector-side bucket.
        self::EVENT_RECOMMENDATION_APPROVED  => Maintenance::FINDING_INSPECTOR,
        self::EVENT_RECOMMENDATION_DISMISSED => Maintenance::FINDING_INSPECTOR,
        self::EVENT_RECOMMENDATION_SCHEDULED => Maintenance::FINDING_INSPECTOR,
        self::EVENT_PARTS_ORDERED            => Maintenance::FINDING_INSPECTOR,
        self::EVENT_PARTS_READY              => Maintenance::FINDING_INSPECTOR,
        // A required part is part of the inspector's diagnosis, not a procurement act → inspector-side.
        self::EVENT_PART_REQUIRED            => Maintenance::FINDING_INSPECTOR,
        // A required check is asked for, answered and decided on the inspection side of the house —
        // the work it may eventually produce is what lands in the garage bucket, via its own events.
        self::EVENT_CHECK_RAISED             => Maintenance::FINDING_INSPECTOR,
        self::EVENT_CHECK_INSPECTED          => Maintenance::FINDING_INSPECTOR,
        self::EVENT_CHECK_DECIDED            => Maintenance::FINDING_INSPECTOR,
        self::EVENT_CHECK_RESOLVED           => Maintenance::FINDING_INSPECTOR,
        // Severity Review is a supervisory grading decision → inspector-side audit bucket.
        self::EVENT_SEVERITY_UPGRADED        => Maintenance::FINDING_INSPECTOR,
        self::EVENT_SEVERITY_REVIEW_KEPT     => Maintenance::FINDING_INSPECTOR,
        // Holding, approving or refusing a finding is a management decision about whether work should
        // happen at all — it is taken before any spanner is lifted → inspector-side audit bucket.
        self::EVENT_FINDING_APPROVAL_REQUIRED => Maintenance::FINDING_INSPECTOR,
        self::EVENT_FINDING_APPROVED          => Maintenance::FINDING_INSPECTOR,
        self::EVENT_FINDING_REJECTED          => Maintenance::FINDING_INSPECTOR,
        // Asset Layer — fitting or stripping a part is hands-on-the-car work performed at the
        // workshop bench, so the whole component lifecycle sits in the garage audit bucket.
        self::EVENT_COMPONENT_INSTALLED      => Maintenance::FINDING_GARAGE,
        self::EVENT_COMPONENT_REMOVED        => Maintenance::FINDING_GARAGE,
        self::EVENT_COMPONENT_TRANSFERRED    => Maintenance::FINDING_GARAGE,
        self::EVENT_COMPONENT_DISPOSED       => Maintenance::FINDING_GARAGE,
        // A spare key is noticed missing by whoever holds the car — an observation about its
        // condition rather than workshop work — so the need sits in the inspector-side bucket. The
        // key physically arriving is custody of an asset and is logged as component_installed above.
        self::EVENT_SPARE_KEY_REQUIRED           => Maintenance::FINDING_INSPECTOR,
        self::EVENT_SPARE_KEY_PURCHASE_REQUESTED => Maintenance::FINDING_INSPECTOR,
        self::EVENT_SPARE_KEY_RECEIVED           => Maintenance::FINDING_GARAGE,
        self::EVENT_SPARE_KEY_CANCELLED          => Maintenance::FINDING_INSPECTOR,
        // An accident case is office/inspection-side throughout: reporting it, chasing the police
        // report, deciding liability and arguing with an insurer are all our own acts, not workshop
        // work. The REPAIR it causes is an ordinary ticket and carries the garage bucket on its own
        // events — which is the distinction that keeps "what did the workshop do?" answerable.
        self::EVENT_ACCIDENT_REPORTED            => Maintenance::FINDING_INSPECTOR,
        self::EVENT_ACCIDENT_CONTEXT_CAPTURED    => Maintenance::FINDING_INSPECTOR,
        self::EVENT_ACCIDENT_DETAILS_UPDATED     => Maintenance::FINDING_INSPECTOR,
        self::EVENT_ACCIDENT_DAMAGE_RECORDED     => Maintenance::FINDING_INSPECTOR,
        self::EVENT_ACCIDENT_ASSESSED            => Maintenance::FINDING_INSPECTOR,
        self::EVENT_POLICE_REPORT_RECORDED       => Maintenance::FINDING_INSPECTOR,
        self::EVENT_POLICE_REPORT_VERIFIED       => Maintenance::FINDING_INSPECTOR,
        self::EVENT_POLICE_REPORT_BYPASSED       => Maintenance::FINDING_INSPECTOR,
        self::EVENT_ACCIDENT_LIABILITY_SET       => Maintenance::FINDING_INSPECTOR,
        self::EVENT_ACCIDENT_CLAIM_UPDATED       => Maintenance::FINDING_INSPECTOR,
        self::EVENT_ACCIDENT_FINANCIAL_RECORDED  => Maintenance::FINDING_INSPECTOR,
        self::EVENT_ACCIDENT_REPAIR_LINKED       => Maintenance::FINDING_INSPECTOR,
        self::EVENT_ACCIDENT_DOCUMENT_ADDED      => Maintenance::FINDING_INSPECTOR,
        self::EVENT_ACCIDENT_STAGE_CHANGED       => Maintenance::FINDING_INSPECTOR,
        self::EVENT_ACCIDENT_CLOSED              => Maintenance::FINDING_INSPECTOR,
        self::EVENT_ACCIDENT_REOPENED            => Maintenance::FINDING_INSPECTOR,
    ];

    /** Every accident event, in ladder order — the CASE timeline's own vocabulary. */
    public const ACCIDENT_EVENTS = [
        self::EVENT_ACCIDENT_REPORTED, self::EVENT_ACCIDENT_CONTEXT_CAPTURED,
        self::EVENT_ACCIDENT_DETAILS_UPDATED, self::EVENT_ACCIDENT_DAMAGE_RECORDED,
        self::EVENT_ACCIDENT_ASSESSED, self::EVENT_POLICE_REPORT_RECORDED,
        self::EVENT_POLICE_REPORT_VERIFIED, self::EVENT_POLICE_REPORT_BYPASSED,
        self::EVENT_ACCIDENT_LIABILITY_SET, self::EVENT_ACCIDENT_CLAIM_UPDATED,
        self::EVENT_ACCIDENT_FINANCIAL_RECORDED, self::EVENT_ACCIDENT_REPAIR_LINKED,
        self::EVENT_ACCIDENT_DOCUMENT_ADDED, self::EVENT_ACCIDENT_STAGE_CHANGED,
        self::EVENT_ACCIDENT_CLOSED, self::EVENT_ACCIDENT_REOPENED,
    ];

    protected $fillable = [
        'vehicle_id',
        'maintenance_id',
        // The accident case this event belongs to, and its FK-free twin. Same arrangement, and same
        // reason, as maintenance_id / maintenance_ref directly below: the cascade nulls the live
        // link when a case is destroyed, and `accident_ref` is what keeps the row readable after.
        'accident_case_id',
        'accident_ref',
        // The archival twin of maintenance_id, carrying NO foreign key so no cascade can null it.
        // maintenance_id is the live link; maintenance_ref is the permanent record of what it was.
        // Read this one whenever you need the ticket a historical event belonged to.
        'maintenance_ref',
        'maintenance_task_id',
        'linked_contract_id',
        'event_type',
        'source_tag',
        'workflow_status',
        'description',
        'meta',
        'actor_id',
        'occurred_at',
    ];

    protected $casts = [
        'meta'        => 'array',
        'occurred_at' => 'datetime',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function maintenance(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class);
    }

    /** The specific fault this event concerns (null = a ticket-wide event). */
    public function task(): BelongsTo
    {
        return $this->belongsTo(MaintenanceTask::class, 'maintenance_task_id');
    }

    public function linkedContract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'linked_contract_id');
    }

    /** The accident case this event belongs to (null on everything that is not one). */
    public function accidentCase(): BelongsTo
    {
        return $this->belongsTo(AccidentCase::class, 'accident_case_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
