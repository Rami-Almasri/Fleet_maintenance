<?php

namespace App\Http\Resources;

use App\Services\MaintenanceAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One workshop event for the dashboard. Exposes the raw event fields plus the two
 * SYSTEM-MANAGED derivations the owner cares about:
 *   - priority/level: from the keyword → controlled reason (authoritative if linked).
 *   - sla_status: traffic-light timeliness for this stage (returned when stage = IN).
 */
class WorkshopEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var MaintenanceAnalyticsService $analytics */
        $analytics = app(MaintenanceAnalyticsService::class);

        $issues   = $analytics->sheetIssueTags($this->resource);
        // The sheet's one free-text column holds faults, planned services and bookkeeping words together.
        // `issues` stays the raw union every existing reader expects; the typed lists are the honest ones
        // and are what anything counting faults must read (ADR §6 / audit H3, M8).
        $byKind   = $analytics->sheetIssueTagsByKind($this->resource);
        // The car is back if the stage says IN *or* an actual return date is recorded.
        // A row can carry an in-date while its latest stage is still OUT (the sheet logs
        // both on one row); either way it's closed and must not raise an Overdue SLA.
        $returned = $this->event_status === 'IN' || $this->actual_in_date !== null;

        // Keyword-driven priority: the linked reason is authoritative; else classify the issues.
        // Only the FAULT labels are scored — a visit's priority is set by what went wrong on it, not by
        // the oil change performed alongside or by a bookkeeping word like "Customer" (audit M8).
        $priority = ($this->maintenance_reason_id && $this->reason)
            ? ['level' => $this->reason->level, 'matched' => $this->reason->reason_en]
            : $analytics->classifyPriority($issues, $this->maintenance_notes);

        $sla = $analytics->slaStatus($this->out_date, $this->expected_return_date, [
            'linked'   => true,
            'returned' => $returned,
        ]);

        return [
            'id'                   => $this->id,
            'origin'               => $this->origin,
            'editable'             => $this->isManual(), // only hand-entered events can be EDITED here
            'tombstoned'           => false,             // a live event (deleted sheet events come back as ghosts)
            'vehicle_id'           => $this->vehicle_id,
            'plate'                => $this->vehicle?->plate_no ?: $this->plate,
            'car'                  => $this->vehicle ? trim($this->vehicle->make . ' ' . $this->vehicle->model) : $this->car_label,

            'stage'                => $this->event_status,            // OUT / IN / Follow up / …
            'vendor_id'            => $this->vendor_id,
            'garage'               => $this->vendor?->name ?: $this->garage,
            'issues'               => $issues,                        // keyword tags (raw union — legacy shape)
            'fault_tags'           => $byKind['fault'],               // the faults only
            'service_tags'         => $byKind['service'],             // planned work performed on the visit
            'damage_tags'          => $byKind['damage'],              // externally-caused damage — never a fault
            'context_tags'         => $byKind['context'],             // bookkeeping words (Customer, Ready, …)
            'service_main'         => $this->service_main,
            'service_sup'          => $this->service_sup,
            'maintenance_type'     => $this->maintenance_type,
            'visit_context'        => $this->visit_context,        // routine | accident_rental | standard | null
            'damage_location'      => $this->damage_location,
            'severity'             => $this->severity,

            'out_date'             => optional($this->out_date)->toDateString(),
            'expected_return_date' => optional($this->expected_return_date)->toDateString(),
            'follow_date'          => optional($this->follow_date)->toDateString(),
            'actual_in_date'       => optional($this->actual_in_date)->toDateString(),

            'responsible'          => $this->responsible,
            'approved_by'          => $this->approved_by,
            'liable_party'         => $this->liable_party,
            'charge_to'            => $this->charge_to,
            'driver'               => $this->driver,
            'base_on'              => $this->base_on,
            'spare_part'           => $this->spare_part,
            'invoice_no'           => $this->invoice_no,
            'cost'                 => $this->cost !== null ? (float) $this->cost : null,
            'cost_notes'           => $this->cost_notes,
            'notes'                => $this->maintenance_notes,

            // system-managed derivations
            'priority'             => $priority['level'],   // critical | special | minor | routine
            'priority_matched'     => $priority['matched'], // the keyword/reason that set it
            'reason'               => $this->reason?->reason_en,
            'sla_status'           => $sla['status'],       // on_track | at_risk | breached | returned | unknown
            'due'                  => $sla['due'],
            'overdue_days'         => $sla['overdue_days'],
            'days_out'             => $sla['days_out'],
        ];
    }
}
