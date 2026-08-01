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
    public const EVENT_REPORT_FILED       = 'report_filed';       // UC-2 submitReport(requires=true): ticket born
    public const EVENT_DIAGNOSTIC_CLEARED = 'diagnostic_cleared'; // UC-2 submitReport(requires=false): no work needed
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
    // ── Readiness-gate events (not part of the maintenance workflow, but on the same vehicle trail) ──
    public const EVENT_READINESS_CONFIRMED = 'readiness_confirmed'; // setReady(): a clean car returned to service (no advisories were open)
    public const EVENT_READINESS_OVERRIDE  = 'readiness_override';  // setReady(): a user proceeded PAST open readiness advisories — logged with the reason
    public const EVENT_CONDITION_GRADED    = 'condition_graded';    // updateCondition(): the Visual Condition Grade was set/changed
    public const EVENT_CLEANING_UPDATED    = 'cleaning_updated';    // ReadinessController::setChecklistField(): a car's cleaning status changed (e.g. dirty → clean)
    public const EVENT_ODOMETER_CORRECTED  = 'odometer_corrected';  // OdometerChangeRequestController::approve(): a significant manual odometer edit was approved
    // ── Per-fault (maintenance_task) events — each scoped to one task via maintenance_task_id ─────
    public const EVENT_TASK_IDENTIFIED   = 'task_identified';   // a fault was logged on the ticket
    public const EVENT_TASK_ASSIGNED     = 'task_assigned';     // the fault was routed to a garage (stint opened)
    public const EVENT_TASK_TRANSFERRED  = 'task_transferred';  // the fault was moved to a different garage
    public const EVENT_TASK_RESOLVED     = 'task_resolved';     // the fault was fixed (or cancelled)
    public const EVENT_SERVICE_LOGGED    = 'service_logged';    // a routine service fault (oil/battery) was completed → its Service Reminder rolled forward
    public const EVENT_TASK_REINSPECTION_FAILED = 'task_reinspection_failed'; // QC: the garage returned it unfixed, failed re-inspection
    public const EVENT_TASK_MARKED_INCORRECT    = 'task_marked_incorrect';    // delegate overruled the inspector — the fault was a mis-diagnosis

    // ── Severity Review (Diagnostic QC) — a supervisor's decision on an under-graded ticket ─────────
    public const EVENT_SEVERITY_UPGRADED    = 'severity_upgraded';    // QC upgrade applied: fault_severity raised to the recommendation
    public const EVENT_SEVERITY_REVIEW_KEPT = 'severity_review_kept'; // QC "keep current": the recommendation was reviewed and dismissed as a false alarm

    // Deferred-invoice decoupling — repair signed off with the invoice still pending, then received.
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

    // ── Asset Layer — display mirror of component_events (that table stays the source of truth;
    //    these rows make asset movements visible on the Vehicle Timeline with no new joins) ─────
    public const EVENT_COMPONENT_INSTALLED   = 'component_installed';   // a physical component was fitted to this car
    public const EVENT_COMPONENT_REMOVED     = 'component_removed';     // a component came off (reason + disposition in meta)
    public const EVENT_COMPONENT_TRANSFERRED = 'component_transferred'; // a component moved between this car and another
    public const EVENT_COMPONENT_DISPOSED    = 'component_disposed';    // a component's story ended (scrapped/returned/sold)

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
        self::EVENT_READINESS_CONFIRMED => Maintenance::FINDING_INSPECTOR, // a readiness sign-off is an inspector-side act
        self::EVENT_READINESS_OVERRIDE  => Maintenance::FINDING_INSPECTOR, // a warning-override sign-off is likewise inspector-side
        self::EVENT_CONDITION_GRADED    => Maintenance::FINDING_INSPECTOR,
        self::EVENT_ODOMETER_CORRECTED  => Maintenance::FINDING_INSPECTOR, // an admin sign-off is likewise inspector-side
        self::EVENT_AWAITING_INVOICE   => Maintenance::FINDING_INSPECTOR, // sign-off with invoice deferred
        self::EVENT_INVOICE_RECEIVED   => Maintenance::FINDING_INSPECTOR,
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
        // Recommendation triage — a Supervisor's pre-garage management decision → inspector-side bucket.
        self::EVENT_RECOMMENDATION_APPROVED  => Maintenance::FINDING_INSPECTOR,
        self::EVENT_RECOMMENDATION_DISMISSED => Maintenance::FINDING_INSPECTOR,
        self::EVENT_RECOMMENDATION_SCHEDULED => Maintenance::FINDING_INSPECTOR,
        self::EVENT_PARTS_ORDERED            => Maintenance::FINDING_INSPECTOR,
        self::EVENT_PARTS_READY              => Maintenance::FINDING_INSPECTOR,
        // A required part is part of the inspector's diagnosis, not a procurement act → inspector-side.
        self::EVENT_PART_REQUIRED            => Maintenance::FINDING_INSPECTOR,
        // Severity Review is a supervisory grading decision → inspector-side audit bucket.
        self::EVENT_SEVERITY_UPGRADED        => Maintenance::FINDING_INSPECTOR,
        self::EVENT_SEVERITY_REVIEW_KEPT     => Maintenance::FINDING_INSPECTOR,
        // Asset Layer — fitting or stripping a part is hands-on-the-car work performed at the
        // workshop bench, so the whole component lifecycle sits in the garage audit bucket.
        self::EVENT_COMPONENT_INSTALLED      => Maintenance::FINDING_GARAGE,
        self::EVENT_COMPONENT_REMOVED        => Maintenance::FINDING_GARAGE,
        self::EVENT_COMPONENT_TRANSFERRED    => Maintenance::FINDING_GARAGE,
        self::EVENT_COMPONENT_DISPOSED       => Maintenance::FINDING_GARAGE,
    ];

    protected $fillable = [
        'vehicle_id',
        'maintenance_id',
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

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
