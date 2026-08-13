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

        $locations = app(\App\Services\FaultLocationService::class);

        return [
            'id'             => $t->id,
            'maintenance_id' => $t->maintenance_id,
            'symptom'        => $t->symptom,
            'category_key'   => $t->category_key,

            // ── WHAT IS WRONG, HOW MANY, AND WHERE ────────────────────────────────────────────────────
            // The three facts a consumer needs to answer the question without parsing prose. `symptom`
            // above stays exactly what it always was (the fault's identity, and what every existing
            // client matches on); these are additive.
            //
            //   quantity  — physical occurrences this ONE routable record covers. Always ≥ 1; a fault
            //               recorded before this existed reads 1, which is what it always meant.
            //               NOT a row count — nothing that counts faults should start summing it.
            //   locations — [{ key, label, label_ar, group, precision, inspection_zone }], in the order
            //               they were picked. Empty means "we do not know where", never "nowhere".
            //               `inspection_zone` is the matching hotspot-diagram panel, so a consumer can
            //               join a fault to the inspection photos of the same panel.
            //   location_mode — whether this TYPE takes a place at all (required | optional | none), so
            //               a client can render the right editor without re-deriving the policy.
            //   display   — the one rendered sentence ("2 scratches — rims and body"), from the single
            //               formatter every surface uses. A convenience for read-only consumers;
            //               anything that filters or reports should read the parts above, not this.
            'quantity'       => (int) ($t->quantity ?: 1),
            'locations'      => $locations->locationRefs($t),
            'location_mode'  => $locations->policyForTask($t),
            'display'        => $locations->describe($t),

            // ── EVENT TYPE (the primary domain classification) ────────────────────────────────────────
            // What this event IS: fault | service | inspection. Read from the stored discriminator, never
            // re-derived from the symptom. `kind_meta` carries the emoji/label/tone from the single
            // backend source (MaintenanceTask::KIND_META) so every surface renders a type identically,
            // and `catalog` names the row the type came from. See ADR §6 "API/Resources".
            'kind'       => $t->kind,
            'kind_meta'  => $t->kindMeta(),
            'catalog'    => $this->catalogRef($t),
            'needs_review'          => (bool) $t->needs_review,
            'classification_source' => $t->classification_source,

            // Scheduled routine service (oil_change / battery / …) vs an ordinary fault. When set, fixing
            // this fault rolls the car's matching Service Reminder forward from the odometer at change — so
            // the UI prompts for that reading. Null for a normal fault.
            //
            // Resolved from `kind` + the service catalog (MaintenanceTask::serviceReminderType), NOT by
            // re-matching the symptom text at serialisation time — the API used to answer this question
            // with a different classifier than the database, so the two could disagree about the same row
            // (audit H4).
            'routine_service_type' => $t->serviceReminderType(),
            // Vehicle-sync confirmation state for a routine/reminder service: once the technician has
            // PERFORMED it (status=completed) the vehicle record is NOT updated yet — it is
            // 'pending_confirmation' until the ticket is CLOSED, then 'confirmed'. Null for a fault with
            // no service loop, or one not performed yet (the normal status pill covers those).
            'service_confirmation' => $this->serviceConfirmation($t),
            'source'         => $t->source,
            'root_cause'     => $t->root_cause,
            'notes'          => $t->notes,
            'resolution_note' => $t->resolution_note,

            // Independent status — the heart of multi-stage routing.
            'status'         => $t->status,
            'is_terminal'    => $t->isTerminal(),

            // Workshop CONFIRMATION verdict — recorded In Workshop: confirmed / not_found / different_cause
            // / needs_diagnosis (null = not reviewed yet). Only `confirmed` triggers recurring-fault review.
            'confirmation_status' => $t->confirmation_status,
            'confirmation_note'   => $t->confirmation_note,
            'confirmed_by'        => $t->confirmedBy?->name,
            'confirmed_at'        => optional($t->confirmed_at)->toIso8601String(),
            // Report-time "possible recurring fault" background flag (the same fault was FIXED before). A
            // soft hint for the workshop — informational, never blocking.
            'recurrence_flagged'  => (bool) $t->recurrence_flagged,
            // A snapshot of that previous repair, for the "Previous repair found" card. Only present when
            // the fault is flagged and the prior fault still resolves.
            'recurrence'          => $this->when((bool) $t->recurrence_flagged && $t->recurrence_previous_task_id, function () use ($t) {
                $prev = $t->recurrencePreviousTask;
                if (! $prev) {
                    return null;
                }
                $repairedOn = $prev->resolved_at;
                $days = ($prev->started_at && $repairedOn)
                    ? max(0, (int) $prev->started_at->copy()->startOfDay()->diffInDays($repairedOn->copy()->startOfDay()))
                    : null;
                return [
                    'garage'      => $prev->currentVendor?->name,
                    'repaired_on' => optional($repairedOn)->toIso8601String(),
                    'repair_days' => $days,
                ];
            }),
            // Recurring-fault REPAIR GATE — when a confirmed fault recurred, the repair is frozen
            // (repair_gate=pending) until a manager approves it. Drives the "Waiting for approval" panel.
            'repair_gate'      => $t->repair_gate, // null | pending | approved | rejected
            'repair_gate_by'   => $t->repairGateBy?->name,
            'repair_gate_at'   => optional($t->repair_gate_at)->toIso8601String(),
            'repair_gate_note' => $t->repair_gate_note,
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
            // DERIVED CACHE of Σ(per-attempt stint labor_hours) — see `repair_time` for the real
            // per-attempt breakdown. (On a stint-less on-site fault it holds the single manual entry.)
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

            // Parts raised against THIS fault (Parts Purchase workflow) — so opening a fault shows what was
            // ordered/fitted for it, without going to the Parts board. Present only when eager-loaded.
            'parts'          => $this->whenLoaded('partRequests', fn () => $t->partRequests->map(fn ($p) => [
                'id'         => $p->id,
                'part_name'  => $p->part_name,
                'part_number' => $p->part_number,
                'quantity'   => $p->quantity,
                'status'     => $p->status,
                // Delivery is not a status — it's part_purchases.delivered_at. Surfaced so a part that has
                // LANDED but isn't fitted yet reads "Delivered" instead of a stale "Purchased".
                'delivered'  => $p->relationLoaded('purchases') ? $p->isOnSite() : null,
                'outstanding' => $p->relationLoaded('purchases') ? $p->isOutstanding() : null,
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
                // Per-attempt manual labor (mechanic's actual hours) — carried by the stint that ends
                // the attempt; immutable history, never overwritten by a later attempt.
                'labor_hours' => $a->labor_hours !== null ? (float) $a->labor_hours : null,
            ])->values()),

            // Per-fault REPAIR TIME (Derived) — attempt segmentation + cumulative elapsed/labor from the
            // stint ledger. `elapsed` is wall-clock garage custody INCLUDING transit (stints open at
            // dispatch) — deliberately not called "actual repair time"; the manual labor entries are.
            // Cumulative covers THIS occurrence only: a recurrence after a passed re-inspection is a new
            // task row and never sums into this one. Present only when stints are eager-loaded.
            'repair_time'    => $this->whenLoaded('assignments',
                fn () => app(\App\Services\FaultRepairTimeService::class)->forTask($t)),
        ];
    }

    /**
     * The catalog row this event's type came from — {id, slug, name, kind} — or null for a legacy row
     * classified by the resolver with no catalog match. Query-free: only serialised when the matching
     * relation was eager-loaded, so a board listing never turns into N+1.
     *
     * @return array{id:int, slug:?string, name:?string, kind:string}|null
     */
    private function catalogRef(MaintenanceTask $t): ?array
    {
        $relation = MaintenanceTask::KIND_CATALOG_RELATIONS[$t->kind] ?? null;
        if (! $relation || ! $t->relationLoaded($relation)) {
            return null;
        }

        $row = $t->getRelation($relation);

        return $row ? ['id' => $row->id, 'slug' => $row->slug ?? null, 'name' => $row->name ?? null, 'kind' => $t->kind] : null;
    }

    /**
     * The vehicle-sync confirmation state for a routine/reminder service fault. A performed
     * (status=completed) service is 'pending_confirmation' until its ticket is CLOSED — the single point
     * the vehicle record is updated (MaintenanceWorkflowService::confirmRoutineServices) — then 'confirmed'.
     * Null for an ordinary fault (no service loop) or a service not performed yet.
     */
    private function serviceConfirmation(MaintenanceTask $t): ?string
    {
        // Same resolver the workflow uses to decide whether closing this rolls a reminder, so the badge
        // the UI shows and the write that actually happens can never disagree (audit H4).
        if (! $t->serviceReminderType()) {
            return null;
        }
        if ($t->status !== MaintenanceTask::STATUS_COMPLETED) {
            return null;
        }
        return optional($t->maintenance)->workflow_status === Maintenance::WF_CLOSED
            ? 'confirmed'
            : 'pending_confirmation';
    }
}
