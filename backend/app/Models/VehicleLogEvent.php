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

    // Deferred-invoice decoupling — repair signed off with the invoice still pending, then received.
    public const EVENT_AWAITING_INVOICE = 'awaiting_invoice';  // closed operationally, invoice deferred
    public const EVENT_INVOICE_RECEIVED = 'invoice_received';  // the outstanding invoice landed → fully closed

    // Garage Invoice Portal — the outside garage self-submits an itemised invoice, the team audits it.
    public const EVENT_GARAGE_INVOICE_SUBMITTED = 'garage_invoice_submitted'; // garage sent an invoice via the public link
    public const EVENT_GARAGE_INVOICE_ACCEPTED  = 'garage_invoice_accepted';  // team accepted it → applied to the ticket
    public const EVENT_GARAGE_INVOICE_REJECTED  = 'garage_invoice_rejected';  // team rejected it

    /**
     * Audit bucket per event. Reuses Maintenance::FINDING_SOURCES vocabulary so the workflow
     * log and the finding source on the visit speak the same language:
     *   - 'inspector' = Abu Maroof's side (diagnostic, report, re-inspection/close)
     *   - 'garage'    = the workshop side (dispatch, repair, ready, reopen)
     */
    public const SOURCE_BY_EVENT = [
        self::EVENT_INSPECTION_REQUESTED => Maintenance::FINDING_INSPECTOR,
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
