<?php

namespace App\Http\Resources;

use App\Models\Maintenance;
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
    /** workflow_status → human label. */
    private const LABELS = [
        Maintenance::WF_INSPECTION_REQUESTED  => 'Inspection requested',
        Maintenance::WF_INSPECTION_DIAGNOSTIC => 'Diagnostic',
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
        Maintenance::WF_AWAITING_INVOICE   => 'Awaiting invoice',
        Maintenance::WF_CLOSED             => 'Closed',
        Maintenance::WF_DIAGNOSTIC_CLEARED => 'No maintenance needed',
        Maintenance::WF_COMPLAINT_TRIAGE   => 'Pending triage',
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

            // Lifecycle
            'workflow_status' => $status,
            'status_label'    => self::LABELS[$status] ?? $status,
            'stage_index'     => $stageIndex === false ? null : $stageIndex,
            'is_open'         => $status !== null && ! in_array($status, Maintenance::WF_TERMINAL, true),
            'is_ticket'       => in_array($status, Maintenance::WF_TICKET_STATES, true), // false while a pure diagnostic

            // LIVE POSITION — the single, unified "where is the car / what's happening to it", fused from
            // the ticket status + its active garage stint + its active transit move. The board, command
            // view and vehicle profile all render THIS, so logistics is shown as a moving part of the
            // ticket (In Transit / In Workshop) rather than a separate board. See Maintenance::livePosition().
            'position'        => $t->livePosition(),

            // Contract link (materialises at dispatch)
            'linked_contract_id' => $t->linked_contract_id,
            'linked_contract_no' => $t->linkedContract?->contract_no,

            // Why it exists + what the inspector found
            'trigger_reason'        => $t->trigger_reason,
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
            'test_drive_report'     => $t->test_drive_report,
            'severity'              => $t->severity,

            // Fault Severity — the inspector's mandatory diagnostic grade. Colour + symbol drive the
            // board chip + command view; it's the headline urgency a supervisor reads first.
            'fault_severity'        => $t->fault_severity,
            'fault_severity_label'  => $t->fault_severity ? (Maintenance::FAULT_SEVERITY_META[$t->fault_severity]['label'] ?? null) : null,
            'fault_severity_emoji'  => $t->fault_severity ? (Maintenance::FAULT_SEVERITY_META[$t->fault_severity]['emoji'] ?? null) : null,
            'fault_severity_tone'   => $t->fault_severity ? (Maintenance::FAULT_SEVERITY_META[$t->fault_severity]['tone'] ?? null) : null,

            // Driver-initiated request (Stage 0) + the Driver's follow-up log while the car is out.
            'requested_by_name'  => $t->requester?->name,
            'follow_ups'         => array_values($t->follow_ups ?? []),

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

            // Garage + handoff data
            'garage'               => $t->vendor?->name ?: $t->garage,
            'vendor_id'            => $t->vendor_id,

            // Pending garage-to-garage transfer: while the car sits at its current garage awaiting a
            // driver, vendor_id KEEPS pointing at where the car physically is, and the destination the
            // supervisor picked is held here. The pickup screen shows this as the true "Destination garage"
            // so the driver drives to the NEW shop, not the one the car is leaving.
            'transfer_to_vendor_id' => $t->transfer_to_vendor_id,
            'transfer_to_garage'    => $t->transferToVendor?->name,
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
            'handoffs' => [
                'requested'      => $this->stamp($t->requested_by, $t->requested_at, $t->requester?->name),
                'inspected'      => $this->stamp($t->inspected_by, $t->inspected_at, $t->inspector?->name),
                'dispatched'     => $this->stamp($t->dispatched_by, $t->dispatched_at),
                'repair_started' => $this->stamp($t->repair_started_by, $t->repair_started_at),
                'ready'          => $this->stamp($t->ready_by, $t->ready_at),
                'picked_up_from_garage' => $this->stamp($t->picked_up_from_garage_by, $t->picked_up_from_garage_at),
                'park_arrived'   => $this->stamp($t->park_arrived_by, $t->park_arrived_at),
                'closed'         => $this->stamp($t->wf_closed_by, $t->wf_closed_at),
            ],

            // Return-leg checkpoint (Ready for Pickup → In Our Park): whether the driver has already
            // collected the car from the garage — the frontend uses this to switch the single primary
            // action from "Collect from Garage" to "Arrived at Park" without a workflow_status change.
            'picked_up_from_garage_at' => optional($t->picked_up_from_garage_at)->toIso8601String(),

            // "Time in Stage" — when the ticket entered its current workflow_status, and how long ago
            // (seconds, computed server-side so the SLA colour threshold is immune to client-clock skew).
            // Falls back to created_at for legacy rows written before the anchor existed. The board shows
            // this instead of the (misleading) creation date and reddens a stage that overstays its SLA.
            'last_state_change_at' => optional($t->last_state_change_at ?: $t->created_at)->toIso8601String(),
            'seconds_in_stage'     => $this->seconds($t->last_state_change_at ?: $t->created_at, now()),

            'created_at' => optional($t->created_at)->toIso8601String(),
            'updated_at' => optional($t->updated_at)->toIso8601String(),
        ];
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
    private function stamp($userId, $at, ?string $name = null): ?array
    {
        if (! $userId && ! $at) {
            return null;
        }
        return [
            'user_id' => $userId,
            'name'    => $name,
            'at'      => $at ? $at->toIso8601String() : null,
        ];
    }
}
