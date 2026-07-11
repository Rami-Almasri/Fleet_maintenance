<?php

namespace App\Http\Resources;

use App\Models\Maintenance;
use App\Models\MaintenanceTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One routable FAULT on a ticket, shaped for the board card + the task-routing modal: what it is, its
 * independent status, the garage it's at now, its cached cost, and (when loaded) its full garage-stint
 * timeline — so the UI can show "Fault A → Garage 1, Fault B → Garage 2" on one ticket and replay how
 * a fault moved between garages.
 */
class MaintenanceTaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var MaintenanceTask $t */
        $t = $this->resource;

        $sevMeta = $t->severity ? (Maintenance::FAULT_SEVERITY_META[$t->severity] ?? null) : null;

        return [
            'id'             => $t->id,
            'maintenance_id' => $t->maintenance_id,
            'symptom'        => $t->symptom,
            'category_key'   => $t->category_key,
            // Scheduled routine service (oil_change / battery / …) vs an ordinary fault. When set, fixing
            // this fault rolls the car's matching Service Reminder forward from the odometer at change — so
            // the UI prompts for that reading. Null for a normal fault.
            'routine_service_type' => Maintenance::routineServiceTypeFor($t->symptom),
            'source'         => $t->source,
            'root_cause'     => $t->root_cause,
            'notes'          => $t->notes,
            'resolution_note' => $t->resolution_note,

            // Independent status — the heart of multi-stage routing.
            'status'         => $t->status,
            'is_terminal'    => $t->isTerminal(),
            // Split-dispatch: an OPEN fault not yet routed to any garage. The delegate assigns it from the
            // Dispatch Queue (In-Workshop view). Drives the "Pending Assignment" badge + the queue filter.
            'pending_assignment' => $t->isPendingAssignment(),

            // Delegate dispute — a supervisor overruled the inspector: this fault was a mis-diagnosis.
            // Distinct from a plain cancel (drives the red "Marked incorrect by X" badge).
            'is_incorrect'          => $t->isIncorrect(),
            'incorrect_reason'      => $t->incorrect_reason,
            'marked_incorrect_by'   => $t->markedIncorrectBy?->name,
            'marked_incorrect_at'   => optional($t->marked_incorrect_at)->toIso8601String(),

            // Per-fault severity (+ presentation), independent of the ticket headline.
            'severity'       => $t->severity,
            'severity_label' => $sevMeta['label'] ?? null,
            'severity_emoji' => $sevMeta['emoji'] ?? null,
            'severity_tone'  => $sevMeta['tone'] ?? null,

            // Where the fault is NOW.
            'current_vendor_id' => $t->current_vendor_id,
            'current_garage'    => $t->currentVendor?->name,

            // Quality-Control blame — how many times this fault has flunked a closing re-inspection, and
            // the garage that last handed it back unfixed. Drives the "Unresolved at Garage X (×N)" badge;
            // a rising count at the same garage is the signal to flag / blacklist that shop.
            'reinspection_failures' => (int) $t->reinspection_failures,
            'last_failed_vendor_id' => $t->last_failed_vendor_id,
            'last_failed_garage'    => $t->lastFailedVendor?->name,
            'last_failed_at'        => optional($t->last_failed_at)->toIso8601String(),

            // Per-fault timeline anchors.
            'identified_at'  => optional($t->identified_at)->toIso8601String(),
            'started_at'     => optional($t->started_at)->toIso8601String(),
            'resolved_at'    => optional($t->resolved_at)->toIso8601String(),

            // Cached per-fault cost (rolled up from its line items).
            'parts_cost'     => (float) $t->parts_cost,
            'labor_cost'     => (float) $t->labor_cost,
            'total_cost'     => round((float) $t->parts_cost + (float) $t->labor_cost, 2),
            'repair_hours'   => $t->repair_hours !== null ? (float) $t->repair_hours : null,

            // Fix-evidence videos attached when the fault was marked fixed (short-lived signed URLs).
            'media'          => $this->whenLoaded('media', fn () => $t->media->map(fn ($m) => [
                'id'               => $m->id,
                'kind'             => $m->kind,
                'note'             => $m->note,
                'original_name'    => $m->original_name,
                'uploaded_by_name' => $m->uploaded_by_name,
                'created_at'       => optional($m->created_at)->toIso8601String(),
                'url'              => $m->viewUrl(),
            ])->values()),

            // The garage "stints" — the transfer history / per-fault timeline (when eager-loaded).
            'assignments'    => $this->whenLoaded('assignments', fn () => $t->assignments->map(fn ($a) => [
                'id'          => $a->id,
                'vendor_id'   => $a->vendor_id,
                'garage'      => $a->vendor?->name,
                'assigned_at' => optional($a->assigned_at)->toIso8601String(),
                'released_at' => optional($a->released_at)->toIso8601String(),
                'is_open'     => $a->released_at === null,
                'outcome'     => $a->outcome,
                'reason'      => $a->reason,
            ])->values()),
        ];
    }
}
