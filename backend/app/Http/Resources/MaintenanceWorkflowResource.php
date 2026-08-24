<?php

namespace App\Http\Resources;

use App\Models\Maintenance;
use App\Models\PartRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// MaintenanceLineItemResource is in the same namespace — no import needed.

/**
 * One Fleet Maintenance Workflow ticket, shaped for the Controller dashboard / pipeline.
 *
 * Carries the lifecycle state (+ a human label and a 0-based stage index the pipeline columns key
 * off), the captured-at-handoff data, and the role-stamped audit trail (who advanced it, and when)
 * that replaces the WhatsApp history. A `maintenances` row that is NOT a workflow ticket (sheet log,
 * contract header) is never returned through here.
 */
class MaintenanceWorkflowResource extends JsonResource
{
    // workflow_status → human label.
    // PUBLIC on purpose: this is the one English name each workflow state answers to, and the plate
    // locator (MaintenanceWorkflowController::plateLocator) has to name the same states without
    // serializing a whole ticket. Two label maps would eventually disagree; one cannot.
    public const LABELS = [
        Maintenance::WF_PENDING_REVIEW         => 'Pending review',
        Maintenance::WF_REVIEW_REJECTED        => 'Review rejected',
        Maintenance::WF_INSPECTION_REQUESTED  => 'Inspection requested',
        Maintenance::WF_INSPECTION_DIAGNOSTIC => 'Diagnostic',
        Maintenance::WF_RECOMMENDATION_PENDING => 'Pending approval',
        Maintenance::WF_RECOMMENDATION_DISMISSED => 'Recommendation dismissed',
        Maintenance::WF_INSPECTION_PENDING => 'Pending dispatch',
        Maintenance::WF_ON_SITE_PENDING    => 'Pending on-site service',
        Maintenance::WF_AWAITING_DISPATCH  => 'Awaiting dispatch',
        Maintenance::WF_IN_TRANSIT         => 'In transit',
        Maintenance::WF_UNDER_REPAIR       => 'Under repair',
        Maintenance::WF_REPAIR_REVIEW      => 'Repair review',
        Maintenance::WF_READY_FOR_PICKUP   => 'Ready for pickup',
        Maintenance::WF_IN_OUR_PARK        => 'In our park',
        Maintenance::WF_READY_REINSPECTION => 'Final QA re-inspection',
        Maintenance::WF_REINSPECTION_FAILED => 'Re-inspection failed',
        Maintenance::WF_PAUSED_RETURNED_TO_SERVICE  => 'Paused — returned to service',
        Maintenance::WF_AWAITING_INVOICE   => 'Awaiting invoice',
        Maintenance::WF_CLOSED             => 'Closed',
        Maintenance::WF_DIAGNOSTIC_CLEARED => 'No maintenance needed',
        Maintenance::WF_COMPLAINT_TRIAGE   => 'Pending triage',
        Maintenance::WF_TRIAGE_APPROVAL_PENDING => 'Awaiting routing approval',
        Maintenance::WF_COMPLAINT_RESOLVED => 'Resolved on-site',
    ];

    public function toArray(Request $request): array
    {
        /** @var Maintenance $t */
        $t = $this->resource;

        $status = $t->workflow_status;
        $stageIndex = array_search($status, Maintenance::WORKFLOW_STATUSES, true);

        return [
            'id'           => $t->id,
            'vehicle_id'   => $t->vehicle_id,
            'plate'        => $t->plate ?: $t->vehicle?->plate_no,
            'car'          => $t->car_label ?: trim(($t->vehicle?->make ?? '') . ' ' . ($t->vehicle?->model ?? '')) ?: null,
            // Richer vehicle identity for the review card (present when the vehicle is eager-loaded with
            // these columns — the Inspection Review Queue). make/model split out so the UI can weight them.
            'vehicle_make'  => $t->vehicle?->make,
            'vehicle_model' => $t->vehicle?->model,
            'vehicle_year'  => $t->vehicle?->year,
            'vehicle_code'  => $t->vehicle?->code,
            // The car's live movement status (available / rented / maintenance / …) — the "current
            // operational status" the Recommendations queue shows so a supervisor sees whether the car is
            // free before approving. Only present when the vehicle is eager-loaded with the column.
            'operational_status' => $t->vehicle?->operational_status,
            // The car's current authoritative odometer (present when the vehicle is eager-loaded with the
            // column). Used to pre-fill the "Odometer out" field on a Temporary Vehicle Release.
            'vehicle_odometer'   => $t->vehicle?->odometer,

            // Lifecycle
            'workflow_status' => $status,
            'status_label'    => self::LABELS[$status] ?? $status,
            'stage_index'     => $stageIndex === false ? null : $stageIndex,
            'is_open'         => $status !== null && ! in_array($status, Maintenance::WF_TERMINAL, true),
            // A committed ticket (has real repair state). A paused ticket IS a real ticket — it just isn't
            // counted as "in maintenance" operationally — so it reads true here for the UI's ticket surfaces.
            'is_ticket'       => in_array($status, Maintenance::WF_TICKET_STATES, true) || $status === Maintenance::WF_PAUSED_RETURNED_TO_SERVICE, // false while a pure diagnostic

            // Pause Maintenance & Return to Service — while paused, the stage the ticket will resume at,
            // plus when/why it was paused. Null on any non-paused ticket.
            'is_paused'                => $status === Maintenance::WF_PAUSED_RETURNED_TO_SERVICE,
            'paused_from_status'       => $t->paused_from_status,
            'paused_from_status_label' => $t->paused_from_status ? (self::LABELS[$t->paused_from_status] ?? $t->paused_from_status) : null,
            'paused_at'                => optional($t->paused_at)->toIso8601String(),
            'paused_reason'            => $t->paused_reason,

            // Enterprise Handover Workflow — once physically returned (but not yet resumed) the vehicle
            // is blocked from being rented out again; the open discrepancy incident (if any) gates the
            // resume until acknowledged.
            'vehicle_returned_at'          => optional($t->vehicle_returned_at)->toIso8601String(),
            'is_returned_pending_handover' => $t->isReturnedPendingHandover(),
            'active_incident'              => $this->whenLoaded('activeIncident', fn () => $t->activeIncident ? [
                'id'                    => $t->activeIncident->id,
                'type'                  => $t->activeIncident->type,
                'severity'              => $t->activeIncident->severity,
                'description'           => $t->activeIncident->description,
                'status'                => $t->activeIncident->status,
                'acknowledged_by_name'  => $t->activeIncident->acknowledgedBy?->name,
                'acknowledged_at'       => optional($t->activeIncident->acknowledged_at)->toIso8601String(),
                'acknowledgement_note'  => $t->activeIncident->acknowledgement_note,
            ] : null),
            'last_pause_handover' => $this->whenLoaded('lastPauseHandover', fn () => $t->lastPauseHandover ? [
                'id'                  => $t->lastPauseHandover->id,
                'odometer_reading'    => $t->lastPauseHandover->odometer_reading,
                'fuel_level'          => $t->lastPauseHandover->fuel_level,
                'exterior_condition'  => $t->lastPauseHandover->exterior_condition,
                'interior_condition'  => $t->lastPauseHandover->interior_condition,
                'damage_findings'     => $t->lastPauseHandover->damage_findings,
                'missing_accessories' => $t->lastPauseHandover->missing_accessories,
                'notes'               => $t->lastPauseHandover->notes,
                'occurred_at'         => optional($t->lastPauseHandover->occurred_at)->toIso8601String(),
            ] : null),
            'last_resume_handover' => $this->whenLoaded('lastResumeHandover', fn () => $t->lastResumeHandover ? [
                'id'                  => $t->lastResumeHandover->id,
                'odometer_reading'    => $t->lastResumeHandover->odometer_reading,
                'fuel_level'          => $t->lastResumeHandover->fuel_level,
                'exterior_condition'  => $t->lastResumeHandover->exterior_condition,
                'interior_condition'  => $t->lastResumeHandover->interior_condition,
                'damage_findings'     => $t->lastResumeHandover->damage_findings,
                'missing_accessories' => $t->lastResumeHandover->missing_accessories,
                'notes'               => $t->lastResumeHandover->notes,
                'occurred_at'         => optional($t->lastResumeHandover->occurred_at)->toIso8601String(),
            ] : null),
            'handover_comparisons' => $this->whenLoaded('handoverComparisons', fn () => $t->handoverComparisons->map(fn ($c) => [
                'id'                   => $c->id,
                'pause_handover_id'    => $c->pause_handover_id,
                'resume_handover_id'   => $c->resume_handover_id,
                'mileage_delta'        => $c->mileage_delta,
                'fuel_delta'           => $c->fuel_delta,
                'new_damages'          => $c->new_damages,
                'missing_accessories'  => $c->missing_accessories,
                'condition_changes'    => $c->condition_changes,
                'exceeds_threshold'    => (bool) $c->exceeds_threshold,
                'threshold_breaches'   => $c->threshold_breaches,
                'generated_at'         => optional($c->generated_at)->toIso8601String(),
            ])->values()),

            // Temporary Vehicle Release — the car is out of the workshop MID-REPAIR (road test / customer
            // test / external inspection / storage) while THIS ticket stays at its stage (workflow_status
            // is unchanged). `temporarily_released` is the quick flag the drawer/board read; the open
            // out-leg carries who/why/when + odometer OUT; the history lists every out→in round trip with
            // the distance driven while out. Present when eager-loaded (board/show).
            'temporarily_released'     => $t->isTemporarilyReleased(),
            // `release_stage` is deliberately a TOP-LEVEL field, not just a nested one: it is what every
            // ticket surface keys its action + lane off while the car is out, and those surfaces read the
            // slim board payload where the relation isn't always hydrated.
            'release_stage'            => $t->activeTemporaryRelease?->stage,
            'release_lane'             => $t->activeTemporaryRelease?->laneKey(),
            'active_temporary_release' => $this->whenLoaded('activeTemporaryRelease', fn () => $t->activeTemporaryRelease ? [
                'id'                 => $t->activeTemporaryRelease->id,
                'reason'             => $t->activeTemporaryRelease->reason,
                'reason_label'       => $t->activeTemporaryRelease->reasonLabel(),
                'reason_note'        => $t->activeTemporaryRelease->reason_note,
                'taken_by'           => $t->activeTemporaryRelease->taken_by,
                'released_at'        => optional($t->activeTemporaryRelease->released_at)->toIso8601String(),
                'odometer_out'       => $t->activeTemporaryRelease->odometer_out,
                // The round trip: where it stands, where it went, and which garage it belongs back at.
                'stage'              => $t->activeTemporaryRelease->stage,
                'stage_label'        => $t->activeTemporaryRelease->stageLabel(),
                'lane'               => $t->activeTemporaryRelease->laneKey(),
                'destination'        => $t->activeTemporaryRelease->destination,
                'garage_snapshot'    => $t->activeTemporaryRelease->garage_snapshot,
                'vendor_id_snapshot' => $t->activeTemporaryRelease->vendor_id_snapshot,
                'return_garage'      => $t->activeTemporaryRelease->returnGarageLabel(),
                'return_vendor_id'   => $t->activeTemporaryRelease->returnVendorId(),
                'out_driver_id'      => $t->activeTemporaryRelease->out_driver_id,
                'out_driver_name'    => $t->activeTemporaryRelease->outDriver?->name,
                'return_driver_id'   => $t->activeTemporaryRelease->return_driver_id,
                'return_driver_name' => $t->activeTemporaryRelease->returnDriver?->name,
                'arrived_at'         => optional($t->activeTemporaryRelease->arrived_at)->toIso8601String(),
            ] : null),
            'temporary_releases'       => $this->whenLoaded('temporaryReleases', fn () => $t->temporaryReleases->map(fn ($r) => [
                'id'           => $r->id,
                'reason'       => $r->reason,
                'reason_label' => $r->reasonLabel(),
                'reason_note'  => $r->reason_note,
                'taken_by'     => $r->taken_by,
                'released_at'  => optional($r->released_at)->toIso8601String(),
                'odometer_out' => $r->odometer_out,
                'destination'  => $r->destination,
                'returned_at'  => optional($r->returned_at)->toIso8601String(),
                'odometer_in'  => $r->odometer_in,
                'distance_km'  => $r->distance_km,
                'return_note'  => $r->return_note,
                'is_open'      => $r->isOpen(),
                'stage'        => $r->stage,
                'stage_label'  => $r->stageLabel(),
            ])->values()),

            // LIVE POSITION — the single, unified "where is the car / what's happening to it", fused from
            // the ticket status + its active garage stint + its active transit move. The board, command
            // view and vehicle profile all render THIS, so logistics is shown as a moving part of the
            // ticket (In Transit / In Workshop) rather than a separate board. See Maintenance::livePosition().
            'position'        => $t->livePosition(),

            // Contract link (materialises at dispatch)
            'linked_contract_id' => $t->linked_contract_id,
            'linked_contract_no' => $t->linkedContract?->contract_no,

            // THE MAINTENANCE CONTRACT THIS VISIT WAS OPENED UNDER — the office's own record of the same
            // visit (OfficeManager type-U, synced through the N-Maintenance sheet). The ticket is what the
            // workshop did; this is what the office wrote down. A reader asking "what happened on this
            // contract?" gets the header here and the events themselves from the car's activity feed,
            // bounded by the window below — so the two records are read side by side, never conflated.
            'contract_id'        => $t->contract_id,
            'contract'           => $this->whenLoaded('contract', fn () => $t->contract ? [
                'id'          => $t->contract->id,
                'contract_no' => $t->contract->contract_no,
                'type'        => $t->contract->contract_type,
                'state'       => $t->contract->state,
                'customer_id' => $t->contract->customer_id,
                'customer'    => $t->contract->customer?->name_en ?: $t->contract->customer?->name_ar,
                'out_date'    => optional($t->contract->out_date)->toDateString(),
                'in_date'     => optional($t->contract->in_date)->toDateString(),
                'out_milage'  => $t->contract->out_milage,
                'in_milage'   => $t->contract->in_milage,
                'debit'       => $t->contract->contract_debit,
                'credit'      => $t->contract->contract_credit,
                'balance'     => $t->contract->contract_balance,
            ] : null),

            // Why it exists + what the inspector found
            'trigger_reason'        => $t->trigger_reason,
            // WHERE the request came from — a separate axis from the reason above. "Who found this?"
            // vs. "what's wrong with it?"; the board renders both (see Maintenance::REQUEST_ORIGINS).
            // Null on rows created before the column existed and never backfilled.
            'request_origin'        => $t->request_origin,
            'request_origin_label'  => $t->requestOriginLabel(),
            // THE REQUESTER'S STATEMENT as data, beside the sentence it was rendered into. `reported_faults`
            // is what the person who sent the car in CLAIMS is wrong — a brief for the inspector, never a
            // diagnosis (except on the direct-dispatch door, where they are promoted to real faults at
            // birth). Null on every row filed before this existed, which reads as "we only had the note".
            'request_detail_mode'   => $t->request_detail_mode,
            'reported_faults'       => $t->reported_faults,
            // The PLANNED work they asked for, in its own field for the same reason it has its own column:
            // a service that is due is not a claim that anything failed, and a reader that mistakes one for
            // the other counts a working schedule as a breaking car.
            'requested_services'    => $t->requested_services,
            'request_reason_code'   => $t->request_reason_code,
            'request_reason_label'  => Maintenance::requestReasonLabel($t->request_reason_code),
            // The Driver Observation this ticket was escalated from, so the card can link back to the
            // original note instead of only echoing its text into customer_complaint.
            'driver_observation_id' => $t->relationLoaded('driverObservation') ? $t->driverObservation?->id : null,
            // Repair Location — 'in_shop' (workshop pipeline) | 'on_site' (mobile; car stays available).
            // `is_on_site` is the quick flag the board/drawer read to render the On-Site lane + tag.
            'repair_location'       => $t->repair_location,
            'repair_location_label' => $t->repair_location ? (Maintenance::REPAIR_LOCATION_LABELS[$t->repair_location] ?? null) : null,
            'is_on_site'            => $t->isOnSite(),
            // Which intake tab produced the ticket (routine_check | scheduled_dormancy | null).
            'test_kind'             => $t->test_kind,
            // Recovery (towing) — the winch/tow unit that recovered a broken-down car (in place of a
            // driver on the pickup leg). is_recovery is the quick flag the drawer/board read.
            'is_recovery'           => $t->isRecovery(),
            'recovery_unit_name'    => $t->recovery_unit_name,
            'recovery_unit_phone'   => $t->recovery_unit_phone,
            'maintenance_type'      => $t->maintenance_type,
            'maintenance_type_label' => Maintenance::MAINTENANCE_TYPES[$t->maintenance_type] ?? null,
            'customer_complaint'    => $t->customer_complaint,
            // Ready-entry-point chips (see DiagnosticGateService::dueChecks) — the exact Findings-catalog
            // keywords a system-raised ticket is due for, offered as one-tap suggestions at the Decide step.
            'suggested_findings'    => array_values($t->suggested_findings ?? []),
            // NOTE: Suggested Checks (this car's repeat faults + service forecast — the per-car
            // replacement for the old fixed checklist) is deliberately NOT on this resource. It is too
            // expensive to compute for every row of a 141-ticket queue, so it has its own endpoint,
            // Vehicle/{vehicle}/suggested-checks, which the panel calls per card as it scrolls into
            // view. See VehicleSuggestedChecksService.
            //
            // Trigger Detail — the "why" snapshot behind a SYSTEM-generated (periodic) request: the rule(s)
            // that fired, each rule's human reason + checklist, and the mileage/threshold/overdue/due values
            // at detection. Null for human-raised requests. The Inspection Review Queue renders this so a
            // machine request explains itself (see MaintenanceWorkflowService::buildTriggerDetail).
            'trigger_detail'        => $t->trigger_detail ?: null,
            // SYSTEM CHECKS — the obligations this inspection must answer, each with its exact result
            // and decision options so the Decide step renders radios and needs no round trip.
            //
            // trigger_detail above says WHY the request exists as one frozen blob; this is the same
            // information as addressable rows a human has to resolve one by one. Present only when
            // the relation was loaded — the 141-card board must not pay for option lists nobody
            // renders there (same rule as Suggested Checks; see the note above).
            'required_checks'       => $t->relationLoaded('checkRequirements')
                ? $t->checkRequirements->map(fn ($c) => app(\App\Services\VehicleCheckService::class)->present($c))->values()->all()
                : null,
            // Oil follow-up context for a request raised by an oil recall/defer decision. LIVE by
            // construction: resolved decision → contract → projection on every read, so a fresh
            // odometer reading changes these figures everywhere at once — the at-decision snapshot
            // stays inside trigger_detail for audit. Null for every other ticket.
            'oil_context'           => $this->oilContext($t),
            'test_drive_report'     => $t->test_drive_report,
            'severity'              => $t->severity,

            // Last real inspection/test-drive this car had BEFORE this request — attached by
            // MaintenanceWorkflowService::pendingReview() so a reviewer can see when it was last looked
            // at (and what was found) before approving another. Null if never inspected. {at, ago_days,
            // by, severity, summary}.
            'last_test'             => $t->last_test ?? null,

            // Last maintenance ACTIVITY (mostly sheet-sourced history) — attached by pendingReview() so the
            // card shows the real "last serviced" date + how long ago. Null if the car has no dated history.
            'last_maintenance'      => $t->last_maintenance ?? null,

            // Last oil-service anchor, straight off the vehicle (the "Oil Change" sheet baseline): the km at
            // last service + how far the car has driven since. Null when no anchor is set (or it's a zero
            // baseline). Present only when the vehicle is eager-loaded with the service columns.
            'last_service'          => (function () use ($t) {
                $v = $t->vehicle;
                if (! $v || $v->last_service_odometer === null || $v->last_service_odometer <= 0) {
                    return null;
                }
                $kmSince = ($v->odometer !== null && $v->odometer >= $v->last_service_odometer)
                    ? (int) ($v->odometer - $v->last_service_odometer)
                    : null;
                return [
                    'odometer'  => (int) $v->last_service_odometer,
                    'km_since'  => $kmSince,
                    'synced_at' => optional($v->service_synced_at)->toIso8601String(),
                    'due_date'  => optional($v->service_due_date)->toIso8601String(),
                ];
            })(),

            // Fault Severity — the inspector's mandatory diagnostic grade. Colour + symbol drive the
            // board chip + command view; it's the headline urgency a supervisor reads first.
            'fault_severity'        => $t->fault_severity,
            'fault_severity_label'  => $t->fault_severity ? (Maintenance::FAULT_SEVERITY_META[$t->fault_severity]['label'] ?? null) : null,
            'fault_severity_emoji'  => $t->fault_severity ? (Maintenance::FAULT_SEVERITY_META[$t->fault_severity]['emoji'] ?? null) : null,
            'fault_severity_tone'   => $t->fault_severity ? (Maintenance::FAULT_SEVERITY_META[$t->fault_severity]['tone'] ?? null) : null,

            // Driver-initiated request (Stage 0) + the Driver's follow-up log while the car is out.
            'requested_by_name'  => $t->requester?->name,
            'follow_ups'         => array_values($t->follow_ups ?? []),

            // "Why this garage?" — the durable data-driven garage-choice decision (recommended vs chosen,
            // reasons + confidence), so the ticket history answers the question months later.
            'garage_recommendation' => $this->garageRecommendation($t),

            // "Sent back" summary — was this car ever re-dispatched after failing re-inspection? Derived
            // from the tagged follow-up entries (kind = 'sent_back'), so the board can show an at-a-glance
            // badge without opening the ticket. Carries the count + the most recent from→to garages.
            'sent_back'          => $this->sentBackSummary($t),

            // Findings audit trail — each entry stamped with its source (inspector | garage), so the
            // UI can group "Inspector-Identified" vs "Garage-Identified" and prove what was diagnosed
            // before vs during the repair.
            'findings'           => array_values($t->findings ?? []),

            // Multi-garage routing — each fault as a first-class task with its own status + garage, plus
            // a rolled-up progress summary. Lets one ticket card show "Fault A → Garage 1, Fault B →
            // Garage 2" and gate the container on "all faults resolved". Only present when eager-loaded.
            'tasks'          => MaintenanceTaskResource::collection($this->whenLoaded('tasks')),
            'tasks_progress' => $this->when($t->relationLoaded('tasks'), fn () => $t->tasksProgress()),

            // Parts still owed on this ticket — the distinct names of every non-terminal part request across
            // its faults. `waiting_parts` is the quick flag a card reads; `parts_pending` names them. Only
            // meaningful when the board eager-loads tasks.partRequests (else empty / false). Drives the
            // "Waiting for parts" signal on the Car Status stage board.
            'waiting_parts'  => count($this->pendingPartNames($t)) > 0,
            'parts_pending'  => $this->pendingPartNames($t),

            // Every part request raised against the ticket, fault-linked or not. A request raised at the
            // ticket level (no maintenance_task_id — e.g. an ad-hoc procurement request) is invisible to
            // the per-fault `tasks[].parts` list, so the card reads THIS to show it. Present only when the
            // ticket's own partRequests relation is eager-loaded.
            // `delivered` / `outstanding` are DERIVED, never stored: delivery is not a request status (the
            // request stays `purchased` after markDelivered), it lives on part_purchases.delivered_at. The
            // board's parts badge reads `outstanding` so it clears the moment a part LANDS, without waiting
            // for someone to fit it and move the status. Both come from PartRequest::isOutstanding(), the
            // same rule WorkflowStateResolver uses, so board and state can't disagree.
            'parts' => $this->whenLoaded('partRequests', fn () => $t->partRequests->map(fn (PartRequest $p) => [
                'id'          => $p->id,
                'part_name'   => $p->part_name,
                'part_number' => $p->part_number,
                'quantity'    => $p->quantity,
                'status'      => $p->status,
                'task_id'     => $p->maintenance_task_id,
                'delivered'   => $p->relationLoaded('purchases') ? $p->isOnSite() : null,
                'outstanding' => $p->relationLoaded('purchases') ? $p->isOutstanding() : null,
            ])->values()),

            // OPERATIONS CARD — the "what is happening to this car right now" block the Car Status
            // Operations Dashboard renders: the primary maintenance reason, the real operational state,
            // the latest checkpoint, the blocker, the parts owed, the clock, who's accountable, the repair
            // spine and the escalation alerts. Built by MaintenanceOpsCardService from stored data only;
            // serialized only when the ticket's faults are eager-loaded (the board/show queries), so it
            // never fires a lazy query on a light listing.
            'ops' => $this->when(
                $t->relationLoaded('tasks'),
                fn () => app(\App\Services\MaintenanceOpsCardService::class)->build($t),
            ),

            // Post-Repair Inspection — the ticket's durable QC verdicts (Repair Quality Check panel). Only
            // present when eager-loaded; the drawer otherwise fetches them via /repair-inspections.
            'repair_inspections' => RepairInspectionResource::collection($this->whenLoaded('repairInspections')),

            // Garage + handoff data
            'garage'               => $t->vendor?->name ?: $t->garage,
            'vendor_id'            => $t->vendor_id,

            // Pending garage-to-garage transfer: while the car sits at its current garage awaiting a
            // driver, vendor_id KEEPS pointing at where the car physically is, and the destination the
            // supervisor picked is held here. The pickup screen shows this as the true "Destination garage"
            // so the driver drives to the NEW shop, not the one the car is leaving.
            'transfer_to_vendor_id' => $t->transfer_to_vendor_id,
            'transfer_to_garage'    => $t->transferToVendor?->name,
            // How this planned transfer's pickup leg will run — 'driver' | 'recovery' | null (legacy/no
            // transfer pending). Drives which pickup action (dispatch vs recovery) resolveAction() offers.
            'transfer_transport_method' => $t->transfer_transport_method,
            'dispatched_by_id'     => $t->dispatched_by,
            'dispatched_by_name'   => $t->driver,   // snapshot of who took the car

            // Current assigned driver (the delegation overlay below reads this too). Live location/
            // status now lives on the canonical Logistics Dispatch task, not per maintenance ticket.
            'assigned_driver_id'   => $t->assigned_driver_id,
            'assigned_driver_name' => $t->assignedDriver?->name,

            // Delegation overlay — a Supervisor assigned a driver to pickup/dropoff (→ "Driver Assigned").
            'delegation' => $t->delegation_status ? [
                'status'      => $t->delegation_status,                 // 'driver_assigned'
                'task'        => $t->delegation_task,                   // 'pickup' | 'dropoff'
                'driver_id'   => $t->assigned_driver_id,
                'driver_name' => $t->assignedDriver?->name,
                'by_name'     => $t->delegatedBy?->name,
                'at'          => optional($t->delegated_at)->toIso8601String(),
            ] : null,

            // Drivers watching this ticket (added on delegation).
            'watchers' => $t->relationLoaded('watchers')
                ? $t->watchers->map(fn ($w) => ['id' => $w->id, 'name' => $w->name])->values()->all()
                : [],
            // Mileage chain: test (start of drive) → dispatch (leaves) → return (back). The first
            // reading is the inspector's, captured before the test drive.
            'test_odometer'        => $t->test_odometer,
            'report_odometer'      => $t->report_odometer,
            'dispatch_odometer'    => $t->dispatch_odometer,
            'receive_odometer'     => $t->receive_odometer,
            'return_odometer'      => $t->return_odometer,
            'reinspect_odometer'   => $t->reinspect_odometer,
            'park_odometer'        => $t->park_odometer,
            // Odometer Continuity verdicts per capture stage (test_drive|dispatch|receive|return|reinspect) — the
            // board/drawer surface a "Discrepancy" badge from these. See OdometerContinuityService.
            'odometer_flags'       => $t->odometer_flags ?: null,
            // Test-drive distance — how far the car was actually driven during the inspection (a
            // thoroughness signal): the end-of-drive reading − the start-of-drive reading. Prefer the
            // Decide-step `report_odometer` (the true end of the test drive) when present, else fall back
            // to `dispatch_odometer` (pickup). Null until we have a forward end reading to compare.
            'test_drive_distance_km' => (function () use ($t) {
                $end = $t->report_odometer ?? $t->dispatch_odometer;
                return ($t->test_odometer !== null && $end !== null && $end >= $t->test_odometer)
                    ? $end - $t->test_odometer
                    : null;
            })(),
            'garage_feedback'      => $t->garage_feedback,
            'expected_return_date' => optional($t->expected_return_date)->toDateString(),
            'out_date'             => optional($t->out_date)->toDateString(),
            'actual_in_date'       => optional($t->actual_in_date)->toDateString(),
            'cost'                 => $t->cost,
            // Structured Parts + Labor breakdown — the split totals + the line set itself. `cost` above
            // stays the grand total; `cost_is_itemized` says it was built from these lines (vs a lump sum).
            'parts_total'          => $t->parts_total,
            'labor_total'          => $t->labor_total,
            'cost_is_itemized'     => (bool) $t->cost_is_itemized,
            'line_items'           => $t->relationLoaded('lineItems')
                ? MaintenanceLineItemResource::collection($t->lineItems)
                : [],
            // Garage Invoice Validation — the hand-keyed receipt total the lines were checked against,
            // the signed-off variance (itemised − receipt) and its explanation, plus the accounting-bridge
            // flag that hands the invoice to the finance/reconciliation engine.
            'receipt_total'          => $t->receipt_total,
            'invoice_variance'       => $t->receipt_total === null ? null : round((float) $t->cost - (float) $t->receipt_total, 2),
            'variance_explanation'   => $t->variance_explanation,
            'reconciliation_status'  => $t->reconciliation_status,
            'reconciliation_flagged_at' => optional($t->reconciliation_flagged_at)->toIso8601String(),

            // One Ticket → Many Invoices — the individual garage bills, each covering only its faults, with
            // its own total + reconciliation status. Present when eager-loaded (ticket show/board); the
            // ticket `cost` above stays the sum of them. `invoice_count` lets a card badge "3 invoices".
            'invoices'      => $t->relationLoaded('invoices')
                ? MaintenanceInvoiceResource::collection($t->invoices)
                : null,
            'invoice_count' => $t->relationLoaded('invoices') ? $t->invoices->count() : null,

            // Garage Invoice Portal — a garage-submitted invoice waiting for the team's audit. `awaiting_audit`
            // flags the board; `pending_garage_invoice` carries exactly what the garage sent for the review panel.
            'awaiting_audit'         => $t->relationLoaded('pendingGarageInvoice') ? (bool) $t->pendingGarageInvoice : null,
            'pending_garage_invoice' => $t->relationLoaded('pendingGarageInvoice') && $t->pendingGarageInvoice
                ? [
                    'id'                   => $t->pendingGarageInvoice->id,
                    'parts_total'          => $t->pendingGarageInvoice->parts_total,
                    'labor_total'          => $t->pendingGarageInvoice->labor_total,
                    'itemized_total'       => $t->pendingGarageInvoice->itemized_total,
                    'receipt_total'        => $t->pendingGarageInvoice->receipt_total,
                    'variance'             => $t->pendingGarageInvoice->variance,
                    'variance_explanation' => $t->pendingGarageInvoice->variance_explanation,
                    'garage_note'          => $t->pendingGarageInvoice->garage_note,
                    'line_items'           => $t->pendingGarageInvoice->line_items,
                    'receipt_photo_url'    => $t->pendingGarageInvoice->receiptPhotoUrl(),
                    'submitted_at'         => optional($t->pendingGarageInvoice->submitted_at)->toIso8601String(),
                ]
                : null,

            // Video Evidence — the garage's repair videos (the permanent repair record). Count only, so the
            // board card can badge "has video"; the full list (with signed URLs) is fetched via
            // GET /maintenance-tickets/{ticket}/media when the drawer opens. `video_count` is present when
            // the query eager-counted media (board/index/show), else null (unknown → the UI just refetches).
            'video_count'          => $t->media_count ?? ($t->relationLoaded('media') ? $t->media->count() : null),
            'has_video'            => isset($t->media_count) ? $t->media_count > 0 : ($t->relationLoaded('media') ? $t->media->isNotEmpty() : null),
            // Compact media list (id, kind, note, name, view URL) — serialised ONLY when the media relation
            // is eager-loaded (e.g. the Inspection Review Queue, so the driver's attached photo/video shows
            // inline). Every other surface still just reads the count above and fetches the full list on demand.
            'media'                => $this->whenLoaded('media', fn () => $t->media->map(fn ($m) => [
                'id'            => $m->id,
                'kind'         => $m->kind,
                'note'         => $m->note,
                'original_name' => $m->original_name,
                'url'          => $m->viewUrl(),
            ])->values()),
            // Financial Decoupling — cost may be filled in AFTER close. `cost_pending` lets the UI flag
            // a committed ticket still awaiting its invoice cost (without ever blocking the workflow).
            'cost_recorded_at'     => optional($t->cost_recorded_at)->toIso8601String(),
            'cost_pending'         => $t->cost === null && in_array($status, Maintenance::WF_TICKET_STATES, true),
            // Path A — set when we've asked the garage for an itemised invoice (awaiting manual entry).
            'invoice_requested_at' => optional($t->invoice_requested_at)->toIso8601String(),
            // Awaiting-Invoice — repair signed off, car back in service, invoice still outstanding. `since`
            // is the SLA anchor; `overdue` fires past INVOICE_SLA_DAYS (drives the tracker + dashboard flag).
            'awaiting_invoice_since' => optional($t->awaiting_invoice_since)->toIso8601String(),
            'invoice_days_waiting'   => $t->invoiceDaysWaiting(),
            'invoice_overdue'        => $t->invoiceIsOverdue(),

            // Stage-timing anchors + the durations they imply (seconds). The downtime model:
            //   Test Drive    = dispatched_at      − test_started_at
            //   At Garage     = returned_at        − repair_started_at   ← the CURRENT garage's stint
            //   Total downtime = returned_at       − test_started_at     ← the whole saga (all garages)
            // "At Garage" is anchored on repair_started_at (stamped at each garage's arrival check-in and
            // cleared on a transfer), so it measures time at the car's CURRENT garage and RESTARTS on every
            // transfer — it never blends the previous garage or the transit legs in. Total downtime still
            // spans the entire ordeal. Durations are pre-computed so every surface formats the SAME number.
            'stage_timing' => [
                'test_started_at'   => optional($t->test_started_at)->toIso8601String(),
                'dispatched_at'     => optional($t->dispatched_at)->toIso8601String(),
                'repair_started_at' => optional($t->repair_started_at)->toIso8601String(),
                'returned_at'       => optional($t->returned_at)->toIso8601String(),
                'durations'       => [
                    'test_drive'     => $this->seconds($t->test_started_at, $t->dispatched_at),
                    'at_garage'      => $this->seconds($t->repair_started_at, $t->returned_at),
                    'total_downtime' => $this->seconds($t->test_started_at, $t->returned_at),
                ],
            ],


            // Audit trail — who advanced the ticket, and when (the WhatsApp replacement).
            // 'dispatched' = picked up by the driver/recovery unit (still in transit); 'repair_started' =
            // arrival confirmed at the garage (repair clock starts). Both carry odometer + garage/driver
            // context so the timeline reads as the real pickup → transit → arrival sequence instead of
            // jumping straight from "Needs Dispatch" to a bare "Now at Garage" timestamp.
            // Legacy system requests raised before the review gate existed — sitting at inspection_requested
            // (already actionable on the board) but never reviewed. See MaintenanceWorkflowService::pendingReview().
            'is_legacy_unreviewed' => $status === Maintenance::WF_INSPECTION_REQUESTED
                && $t->requested_by === null && $t->reviewed_by === null,

            'handoffs' => [
                'requested'      => $this->stamp($t->requested_by, $t->requested_at, $t->requester?->name),
                'reviewed'       => $this->stamp($t->reviewed_by, $t->reviewed_at, $t->reviewer?->name),
                'inspected'      => $this->stamp($t->inspected_by, $t->inspected_at, $t->inspector?->name),
                'dispatched'     => $this->stamp($t->dispatched_by, $t->dispatched_at, $t->isRecovery() ? $t->recovery_unit_name : $t->driver, [
                    'odometer'    => $t->dispatch_odometer,
                    'destination' => $t->vendor?->name ?: $t->garage,
                    'is_recovery' => $t->isRecovery(),
                ]),
                'repair_started' => $this->stamp($t->repair_started_by, $t->repair_started_at, $t->isRecovery() ? $t->recovery_unit_name : $t->driver, [
                    'odometer' => $t->receive_odometer,
                    'garage'   => $t->vendor?->name ?: $t->garage,
                ]),
                'ready'          => $this->stamp($t->ready_by, $t->ready_at),
                'picked_up_from_garage' => $this->stamp($t->picked_up_from_garage_by, $t->picked_up_from_garage_at, $t->pickedUpFromGarageBy?->name),
                'park_arrived'   => $this->stamp($t->park_arrived_by, $t->park_arrived_at),
                'closed'         => $this->stamp($t->wf_closed_by, $t->wf_closed_at),
            ],

            // Inspection Request Review Gate — the Controller (Lin/Marwa) sign-off before the request
            // is sent to the Inspector, and its outcome.
            'review' => [
                'reviewer_name'     => $t->reviewer?->name,
                'reviewed_at'       => optional($t->reviewed_at)->toIso8601String(),
                'notes'             => $t->review_notes,
                'rejection_reason'  => $t->review_rejection_reason,
                // WHY it was rejected, as the stored code AND the sentence that code currently means. The
                // code is what anything counting rejections must read; the label is presentation and may
                // be reworded without rewriting history (see [[reason-code-contract]]). Both are null for
                // requests rejected before the code existed — an honest "not recorded", never a guess.
                'rejection_code'    => $t->review_rejection_code,
                'rejection_label'   => Maintenance::reviewRejectionLabel($t->review_rejection_code),
                'sent_at'           => optional($t->review_sent_at)->toIso8601String(),
                // The system withdrew this request rather than a Controller rejecting it — the car went
                // into the workshop under an OfficeManager maintenance contract while the request was
                // still waiting. `auto_context` is the evidence (contract no, opened-at, customer) so the
                // note on the card can be checked instead of believed. Both are null for every human
                // decision, which is exactly how the queue tells the two apart.
                'is_system_withdrawal' => Maintenance::isSystemWithdrawal($t->review_rejection_code),
                'auto_context'      => $t->review_auto_context ?: null,
            ],

            // The CALLER'S OWN "remind me later" on this request, if they set one — never anyone else's.
            // Two Controllers looking at the same card each see their own reminder or none, which is what
            // makes "remind ME" honest. Populated by the review queue (which pre-loads them in one query);
            // absent on every other endpoint, where the card is not rendered.
            // relationLoaded(), NOT isset(): "the queue looked and this reviewer has no reminder" must
            // serialise as an explicit null, and isset() on a null is false — which would drop the key
            // entirely and make "no reminder" indistinguishable from "nobody looked".
            'my_reminder' => $this->when(
                $t->relationLoaded('myReviewReminder'),
                fn () => ($r = $t->getRelation('myReviewReminder')) ? [
                    'id'        => $r->id,
                    'remind_at' => optional($r->remind_at)->toIso8601String(),
                    'note'      => $r->note,
                    'kind'      => $r->kind,
                ] : null,
            ),

            // Return-leg checkpoint (Ready for Pickup → In Our Park): whether the driver has already
            // collected the car from the garage — the frontend uses this to switch the single primary
            // action from "Collect from Garage" to "Arrived at Park" without a workflow_status change.
            'picked_up_from_garage_at' => optional($t->picked_up_from_garage_at)->toIso8601String(),
            // Custody of the return leg: the driver who collected the car from the garage is the ONLY one
            // who may complete "Arrive at our park" (enforced in MaintenanceWorkflowService::arriveAtPark).
            // The frontend uses this to hide the button for everyone else and show a custody note instead.
            'picked_up_from_garage_by'      => $t->picked_up_from_garage_by,
            'picked_up_from_garage_by_name' => $t->pickedUpFromGarageBy?->name,

            // "Time in Stage" — when the ticket entered its current workflow_status, and how long ago
            // (seconds, computed server-side so the SLA colour threshold is immune to client-clock skew).
            // Falls back to created_at for legacy rows written before the anchor existed. The board shows
            // this instead of the (misleading) creation date and reddens a stage that overstays its SLA.
            'last_state_change_at' => optional($t->last_state_change_at ?: $t->created_at)->toIso8601String(),
            'seconds_in_stage'     => $this->seconds($t->last_state_change_at ?: $t->created_at, now()),

            // Pre-Maintenance Recommendation queue triage (present for every ticket; only meaningful while
            // in / after the recommendation states). `is_recommendation` is the quick flag the Recommendations
            // page reads; the rest drive the queue card (schedule badge, parts note, who/when triaged, and
            // how a dismissed recommendation was closed).
            'is_recommendation'  => $t->isRecommendation(),
            // Triage Routing Approval — Abu Maroof's pending routing recommendation (destination + optional
            // replacement + note + who/when), shown on the Recommendations page for the Supervisor to
            // approve/reject. `is_triage_approval` is the quick flag; null payload on every other ticket.
            'is_triage_approval' => $t->isTriageApproval(),
            'triage_route'       => is_array($t->triage_route_request) ? [
                'destination'         => $t->triage_route_request['destination'] ?? null,
                'replacement_vehicle_id' => $t->triage_route_request['replacement_vehicle_id'] ?? null,
                'note'                => $t->triage_route_request['note'] ?? null,
                'recommended_by_name' => $t->triage_route_request['recommended_by_name'] ?? null,
                'recommended_at'      => $t->triage_route_request['recommended_at'] ?? null,
            ] : null,
            'recommendation'     => [
                'scheduled_for'    => optional($t->recommendation_scheduled_for)->toIso8601String(),
                'disposition'      => $t->recommendation_disposition,
                'note'             => $t->recommendation_note,
                'reviewed_by_name' => $t->recommendationReviewer?->name,
                'reviewed_at'      => optional($t->recommendation_reviewed_at)->toIso8601String(),
            ],

            'created_at' => optional($t->created_at)->toIso8601String(),
            'updated_at' => optional($t->updated_at)->toIso8601String(),
        ];
    }

    /**
     * The distinct names of every part this ticket is still WAITING on — PartRequest::isOutstanding(),
     * the same rule the board badge and WorkflowStateResolver use. The wait ends at DELIVERY, not at the
     * fitting, so a part that has landed drops off this list even though its request stays `purchased`.
     * Empty when the tasks / their part requests aren't eager-loaded (so it never fires a lazy query).
     *
     * @return array<int,string>
     */
    private function pendingPartNames(Maintenance $t): array
    {
        // Prefer the ticket's own requests — they cover BOTH the fault-linked ones and any raised against
        // the ticket with no fault (maintenance_task_id null), which the per-fault walk below misses.
        $requests = $t->relationLoaded('partRequests')
            ? $t->partRequests
            : ($t->relationLoaded('tasks')
                ? $t->tasks->flatMap(fn ($task) => $task->relationLoaded('partRequests') ? $task->partRequests : collect())
                : collect());

        return $requests
            ->filter(fn (PartRequest $r) => $r->isOutstanding())
            ->pluck('part_name')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Whole seconds between two stage anchors, or null when either end hasn't happened yet (so an
     * unreached stage reads as "—", never a bogus 0). Clamped at 0 against any clock skew.
     */
    private function seconds($from, $to): ?int
    {
        if (! $from || ! $to) {
            return null;
        }
        return max(0, $to->getTimestamp() - $from->getTimestamp());
    }

    /**
     * "Sent back" roll-up from the tagged follow-up entries (kind = 'sent_back'), each written when a
     * car that failed re-inspection was re-dispatched. Returns null when it never happened, else the
     * count + the most recent from→to garages + whether that last move changed garage — enough for the
     * board to render a badge + tooltip without opening the ticket.
     */
    /**
     * The "Why this garage?" record for the ticket — the latest garage-choice decision, resolved to garage
     * names so the history reads plainly ("Recommended: Nissan Service · Accepted: Yes · Confidence: High").
     * Null when the ticket was assigned before the engine existed / with no recommendation attached.
     */
    private function garageRecommendation($t): ?array
    {
        $d = $t->latestRecommendationDecision;
        if (! $d) {
            return null;
        }
        return [
            'recommended_garage' => $d->recommendedVendor?->name,
            'chosen_garage'      => $d->chosenVendor?->name,
            'accepted'           => (bool) $d->accepted,
            'followed'           => $d->followed,
            'rank'               => $d->rank,
            'score'              => $d->score,
            'confidence'         => $d->confidence,
            'reasons'            => $d->reasons ?? [],
            'criteria'           => $d->criteria,
            'decided_by'         => $d->actor?->name,
            'decided_at'         => optional($d->created_at)->toIso8601String(),
        ];
    }

    private function sentBackSummary($t): ?array
    {
        // Match the tagged entries (kind = 'sent_back'); also recognise notes written before the tag
        // existed by their text prefix, so already-re-dispatched tickets still light up the badge.
        $events = collect($t->follow_ups ?? [])
            ->filter(fn ($f) => is_array($f) && (
                ($f['kind'] ?? null) === 'sent_back'
                || str_starts_with((string) ($f['text'] ?? ''), 'Sent back after failed re-inspection')
            ))
            ->values();

        if ($events->isEmpty()) {
            return null;
        }

        $last = $events->last();

        return [
            'count'       => $events->count(),
            'from_garage' => $last['from_garage'] ?? null,
            'to_garage'   => $last['to_garage'] ?? null,
            'changed'     => (bool) ($last['changed'] ?? false),
            'at'          => $last['at'] ?? null,
        ];
    }

    /** One handoff stamp: who (id + optional snapshot name) and when. Null when not yet reached. */
    private function stamp($userId, $at, ?string $name = null, array $meta = []): ?array
    {
        if (! $userId && ! $at) {
            return null;
        }
        return array_merge([
            'user_id' => $userId,
            'name'    => $name,
            'at'      => $at ? $at->toIso8601String() : null,
        ], $meta);
    }

    /**
     * The oil-decision story behind an oil-projection follow-up request — recomputed LIVE from the
     * referenced decision's contract so a new reading is authoritative over any stale figure. The
     * frozen `at_decision` block answers "what did the Controller see when they decided".
     */
    private function oilContext(Maintenance $t): ?array
    {
        $detail = $t->trigger_detail;
        if (! is_array($detail)) {
            return null;
        }

        // Two shapes, one meaning. A request the oil lifecycle RAISED is entirely an oil follow-up,
        // so its whole trigger_detail is the oil payload. A request it ADOPTED — the system's own
        // routine check, with the oil change added to it — keeps its own detail and carries the oil
        // layer under `oil_projection`, so the card can still explain why the system flagged the car.
        if (($detail['source'] ?? null) !== 'oil_projection') {
            $detail = is_array($detail['oil_projection'] ?? null) ? $detail['oil_projection'] : null;
            if (! $detail) {
                return null;
            }
        }

        try {
            $decision = \App\Models\ContractOilDecision::with('contract.vehicle')
                ->find($detail['contract_oil_decision_id'] ?? 0);
            if (! $decision || ! $decision->contract) {
                return [
                    'decision'    => $detail['decision'] ?? null,
                    'decided_by'  => $detail['decided_by'] ?? null,
                    'contract_no' => $detail['contract_no'] ?? null,
                    'live'        => null,
                    'at_decision' => $detail['figures_at_decision'] ?? null,
                ];
            }

            $p = app(\App\Services\OilChangeProjectionService::class)->project($decision->contract);

            // What is required NOW — derived from the freshest truth available, in priority order:
            // a raised execution ticket beats everything (done or in progress); a settled decision
            // with NO ticket means the actual return mileage proved oil was not due; while the car
            // is still out, the live projection speaks.
            $service = $decision->settled_ticket_id ? Maintenance::find($decision->settled_ticket_id) : null;
            $recall  = app(\App\Services\OilChangeProjectionService::class)->recallState($decision);

            if ($decision->isOilChanged()) {
                // The car has HAD its oil changed and the reading is on the decision. Nothing this
                // card can say outranks that — not an open ticket, not the projection.
                $action = 'oil_service_completed';
            } elseif ($service) {
                $action = in_array($service->workflow_status, Maintenance::WF_TERMINAL, true)
                    ? 'oil_service_completed'
                    : 'oil_change_required';
            } elseif ($decision->settled_at) {
                $action = 'inspection_only';
            } elseif (($p['over_tolerance_km'] ?? 0) > 0) {
                // A recall says what it is actually DOING, not a blanket "collection in progress" —
                // a car nobody has agreed to collect yet is waiting on Sales, and the card must not
                // claim a driver is on the way when the driver pool has not been told anything.
                $action = $decision->decision === 'recall'
                    ? match ($recall['stage'] ?? null) {
                        \App\Models\ContractOilDecision::STAGE_WAITING_SALES    => 'awaiting_sales',
                        \App\Models\ContractOilDecision::STAGE_READY_FOR_DRIVER => 'awaiting_driver',
                        \App\Models\ContractOilDecision::STAGE_VEHICLE_COLLECTED,
                        \App\Models\ContractOilDecision::STAGE_AT_WORKSHOP      => 'vehicle_collected',
                        default                                                 => 'collection_in_progress',
                    }
                    : 'service_on_return';
            } else {
                $action = 'inspection_only';
            }

            return [
                'decision'    => $decision->decision,
                'decided_by'  => $decision->decided_by_name,
                'decided_at'  => $decision->created_at?->toIso8601String(),
                'contract_no' => $decision->contract->contract_no,
                // The card's own write target: recording the completed change posts against this
                // contract, so the id travels with the context rather than being looked up again.
                'contract_id' => $decision->contract_id,
                // The completed change, when there is one — the reading it was done at, and what
                // the car's profile now says about the next one.
                'oil_changed' => $decision->isOilChanged() ? [
                    'at'        => $decision->oil_changed_at?->toIso8601String(),
                    'odometer'  => $decision->oil_changed_odometer,
                    'by'        => $decision->oil_changed_by_name,
                    'note'      => $decision->oil_change_note,
                    'next_due'  => $p['oil_limit'],
                ] : null,
                'settled'     => $decision->settled_at !== null,
                'current_action'    => $action,
                'service_ticket_id' => $decision->settled_ticket_id,
                // The recall relay — where Sales, the driver and the car have actually got to, plus
                // the required actions the workshop must honour (oil change locked). Null for a defer.
                'recall'            => $recall,
                // CAN this request be reviewed yet? The rental contract stays open until
                // OfficeManager closes it, so `operational_status` reads "rented" for hours after a
                // driver has taken the keys. Custody is the honest answer, and it lives on the
                // collection, not the contract.
                'in_our_custody'    => (bool) ($recall['in_our_custody'] ?? false),
                'live'        => [
                    'anchor_odometer'   => $p['anchor_odometer'],
                    'anchor_source'     => $p['anchor_source'],
                    'anchor_on'         => $p['anchor_on'],
                    'expected'          => $p['expected'],
                    'expected_return'   => $p['expected_return'],
                    'oil_limit'         => $p['oil_limit'],
                    'allowed_max'       => $p['allowed_max'],
                    // The car's own interval, so the review side can show what the next service
                    // point becomes before anyone commits a reading.
                    'service_interval_km' => $decision->contract->vehicle?->service_interval_km,
                    'over_allowance_km' => $p['over_tolerance_km'],
                    'oil_status'        => $p['oil_status'],
                    'lane'              => $p['lane'],
                    'remaining_days'    => $p['remaining_days'],
                ],
                'at_decision' => $detail['figures_at_decision'] ?? null,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }
}
