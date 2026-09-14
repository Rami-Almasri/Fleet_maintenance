<?php

namespace App\Http\Resources;

use App\Models\AccidentCase;
use App\Models\Maintenance;
use App\Models\VehicleDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One accident case, as the UI reads it.
 *
 * ── THE `context` BLOCK IS SERVED AS IT WAS FROZEN ─────────────────────────────────────────────
 *
 * The customer name, the contract number, the rental window and the contract's state are the
 * SNAPSHOT columns, never a live join. That is the whole point of the table: a case reported while
 * the car was on hire to Mr Khan must still say "Mr Khan, contract 41207, out 3 Mar" two years
 * later, after that contract closed, after the car was let forty more times, and even if the
 * customer row is one day merged away. `contract_id` / `customer_id` ride alongside purely so the UI
 * can offer a working link when the record still exists — they are the convenience, not the record.
 *
 * `gates` is computed rather than stored: what is still outstanding on this case, in the words the
 * page shows. It exists so the frontend never re-derives workflow rules from raw fields — the server
 * owns "the police report is still missing" and the banner just renders it.
 */
class AccidentCaseResource extends JsonResource
{
    /**
     * Whether to compute and ship this case's full ladder.
     *
     * OFF by default because `progressFor()` costs a query and evaluates every rung's gate — fine on
     * a detail page, ruinous on a board of eighty rows where nothing draws it. Turned on explicitly
     * by the endpoints that need it rather than guessed from the request, so adding a route can
     * never silently make the list page eighty times slower.
     */
    public bool $includeWorkflow = false;

    /** `AccidentCaseResource::make($case)->withWorkflow()` — for the detail surfaces. */
    public function withWorkflow(bool $include = true): static
    {
        $this->includeWorkflow = $include;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $breakdown = $this->resource->financialBreakdown();

        return [
            'id'         => $this->id,
            'reference'  => $this->reference,
            'vehicle_id' => $this->vehicle_id,

            // ── where the case is ──────────────────────────────────────────────────────────────
            //
            // `workflow` is THIS case's own ladder with every rung's state — done, current, blocked,
            // pending or skipped — computed against the version the case is pinned to. The page never
            // re-derives it: two cases can legitimately be running different arrangements at once, so
            // a client holding one global list would draw the wrong ladder for one of them.
            //
            // Loaded lazily because it costs a query and the board (eighty rows) does not need it;
            // the detail page asks for it explicitly. @see AccidentWorkflowService::progressFor()
            'stage'             => $this->stage,
            'stage_index'       => $this->resource->stageIndex(),
            'workflow'          => $this->when(
                $this->includeWorkflow,
                fn () => app(\App\Services\Accident\AccidentWorkflowService::class)->progressFor($this->resource),
            ),
            'is_open'           => $this->resource->isOpen(),
            'is_closed'         => $this->resource->isClosed(),
            'restricts_rental'  => $this->resource->restrictsRental(),

            // ── the report ─────────────────────────────────────────────────────────────────────
            'occurred_at'      => $this->occurred_at?->toIso8601String(),
            'reported_at'      => $this->reported_at?->toIso8601String(),
            'reported_by_name' => $this->reported_by_name,
            'location'         => $this->location,
            'description'      => $this->description,
            'odometer'         => $this->odometer,

            // ── WHO HAD THE CAR — frozen at the moment of the accident. See the docblock. ──────
            'context' => [
                'responsible_party_type' => $this->responsible_party_type,
                'detected'               => (bool) $this->context_detected,
                'was_with_customer'      => $this->resource->wasWithCustomer(),
                'customer' => [
                    // The live id, for the link — null once the customer row is gone. The NAME is the
                    // snapshot and survives regardless, which is the difference that matters.
                    'id'    => $this->customer_id,
                    'ref'   => $this->customer_ref,
                    'name'  => $this->customer_name_snapshot,
                    'phone' => $this->customer_phone_snapshot,
                ],
                'contract' => [
                    'id'          => $this->contract_id,
                    'ref'         => $this->contract_ref,
                    'contract_no' => $this->contract_no_snapshot,
                    // The contract's state AT THE TIME. The live state is a different question and is
                    // served under `contract_now` below, precisely so the two are never confused.
                    'state_at_accident' => $this->contract_state_snapshot,
                    'out_date'    => $this->contract_out_date_snapshot?->toDateString(),
                    'in_date'     => $this->contract_in_date_snapshot?->toDateString(),
                ],
                // What the rental looks like TODAY, when the row still exists — so the page can say
                // "still open" or "closed on the 4th" without pretending either is what was true then.
                'contract_now' => $this->whenLoaded('contract', fn () => $this->contract ? [
                    'id'          => $this->contract->id,
                    'contract_no' => $this->contract->contract_no,
                    'state'       => $this->contract->state,
                    'in_date'     => optional($this->contract->in_date)->toDateString(),
                    'balance'     => $this->contract->contract_balance,
                ] : null),
                'driver_name'  => $this->driver_name,
                'driver_phone' => $this->driver_phone,
                'note'         => $this->responsible_party_note,
                'snapshot'     => $this->context_snapshot,
            ],

            // ── what happened ──────────────────────────────────────────────────────────────────
            'accident_type'   => $this->accident_type,
            'drivable'        => $this->drivable,
            'towing_required' => $this->towing_required,
            'safety_concerns' => $this->safety_concerns,
            'assessed_at'     => $this->assessed_at?->toIso8601String(),
            'assessed_by_name' => $this->assessed_by_name,
            'other_party' => [
                'involved'  => (bool) $this->other_party_involved,
                'name'      => $this->other_party_name,
                'phone'     => $this->other_party_phone,
                'plate'     => $this->other_party_plate,
                'insurer'   => $this->other_party_insurer,
                'policy_no' => $this->other_party_policy_no,
                'note'      => $this->other_party_note,
            ],

            // ── the police report — a STATUS, not an attachment ───────────────────────────────
            'police' => [
                'status'            => $this->police_status,
                'satisfied'         => $this->resource->policeSatisfied(),
                'report_no'         => $this->police_report_no,
                'report_date'       => $this->police_report_date?->toDateString(),
                'authority'         => $this->police_authority,
                'note'              => $this->police_note,
                'recorded_at'       => $this->police_recorded_at?->toIso8601String(),
                'verified_at'       => $this->police_verified_at?->toIso8601String(),
                'verified_by_name'  => $this->police_verified_by_name,
                // The exception, served with its name and reason attached — never as a bare boolean.
                'bypass_reason'     => $this->police_bypass_reason,
                'bypassed_at'       => $this->police_bypassed_at?->toIso8601String(),
                'bypassed_by_name'  => $this->police_bypassed_by_name,
            ],

            // ── liability — a judgement, so never served without its author and source ─────────
            'liability' => [
                'status'          => $this->liability_status,
                'decided'         => $this->resource->liabilityDecided(),
                'share_pct'       => $this->liability_share_pct,
                'source'          => $this->liability_source,
                'note'            => $this->liability_note,
                'decided_at'      => $this->liability_decided_at?->toIso8601String(),
                'decided_by_name' => $this->liability_decided_by_name,
            ],

            // ── insurance ──────────────────────────────────────────────────────────────────────
            'insurance' => [
                'insurer_vendor_id' => $this->insurer_vendor_id,
                'insurer_name'      => $this->insurer_name ?: $this->whenLoaded('insurerVendor', fn () => $this->insurerVendor?->name),
                'policy_no'         => $this->policy_no,
                'claim_no'          => $this->claim_no,
                'claim_status'      => $this->claim_status,
                'submitted_at'      => $this->claim_submitted_at?->toIso8601String(),
                'response_due_on'   => $this->claim_response_due_on?->toDateString(),
                'overdue'           => $this->resource->insurerOverdue(),
                'contact_name'      => $this->insurance_contact_name,
                'contact_phone'     => $this->insurance_contact_phone,
                'contact_email'     => $this->insurance_contact_email,
                'note'              => $this->insurance_note,
                'updated_at'        => $this->insurance_updated_at?->toIso8601String(),
            ],

            // ── the money: phases kept apart, `unresolved` carried as its own figure ───────────
            'financials' => $breakdown,
            'financial_entries' => $this->whenLoaded('financialEntries',
                fn () => AccidentFinancialEntryResource::collection($this->financialEntries)),

            // Counts for the list views, present only when the caller asked for them (withCount).
            // Served beside the full collections rather than instead of them: a board needs "3 damage
            // areas" and a case page needs the three areas, and one query shape cannot serve both.
            'damage_items_count' => $this->whenCounted('damageItems'),
            'repairs_count'      => $this->whenCounted('repairs'),
            'documents_count'    => $this->whenCounted('documents'),

            'damage_items' => $this->whenLoaded('damageItems',
                fn () => AccidentDamageItemResource::collection($this->damageItems)),

            // THE CAR'S PLACE ON THE MAINTENANCE BOARD while the accident is being worked — the fenced
            // ticket, which is not a repair and must not be listed as one. Its own key so the case page
            // can link to the board without the Repairs tab claiming work that has not been authorised.
            // @see \App\Models\Maintenance::WF_ACCIDENT_CYCLE
            'cycle_ticket' => $this->whenLoaded('repairs', function () {
                $t = $this->repairs->firstWhere('workflow_status', Maintenance::WF_ACCIDENT_CYCLE);

                return $t ? ['id' => $t->id, 'url' => '/maintenance-workflow/' . $t->id] : null;
            }),

            // The repairs this accident caused — ordinary tickets, linked back. The fenced cycle ticket
            // is filtered out: nobody has authorised any work on it, so calling it a repair would put a
            // job on this list that no garage has been asked to do.
            'repairs' => $this->whenLoaded('repairs', fn () => $this->repairs
                ->where('workflow_status', '!=', Maintenance::WF_ACCIDENT_CYCLE)
                ->map(fn ($m) => [
                'id'              => $m->id,
                'workflow_status' => $m->workflow_status,
                'vendor_id'       => $m->vendor_id,
                'vendor_name'     => $m->vendor?->name,
                // `maintenances.cost` — the visit's own recorded cost. Named `cost` on the ticket and
                // surfaced as `cost` here rather than renamed, so the two never drift apart.
                'cost'            => $m->cost,
                'created_at'      => $m->created_at?->toIso8601String(),
                'url'             => '/maintenance-workflow/' . $m->id,
            ])->values()),

            'documents' => $this->whenLoaded('documents', fn () => $this->documents->map(fn ($d) => [
                'id'            => $d->id,
                'kind'          => $d->kind,
                'kind_label'    => VehicleDocument::KINDS[$d->kind] ?? $d->kind,
                'url'           => $d->viewUrl(),
                'is_pdf'        => $d->mime_type === 'application/pdf',
                'original_name' => $d->original_name,
                'file_size'     => $d->file_size,
                'note'          => $d->note,
                'uploaded_at'   => $d->uploaded_at?->toIso8601String(),
                'uploaded_by'   => $d->uploaded_by_name,
            ])->values()),

            'vehicle' => $this->whenLoaded('vehicle', fn () => [
                'id'       => $this->vehicle->id,
                'plate_no' => $this->vehicle->plate_no,
                'make'     => $this->vehicle->make,
                'model'    => $this->vehicle->model,
                'year'     => $this->vehicle->year,
                'odometer' => $this->vehicle->odometer,
                'operational_status' => $this->vehicle->operational_status,
            ]),

            // ── WHAT IS STILL OUTSTANDING, computed server-side ────────────────────────────────
            // The frontend renders these; it never re-derives them. One authority for "the police
            // report is missing", and it is the same one the ladder gate enforces.
            'gates' => array_values(array_filter([
                ! $this->resource->policeSatisfied() ? [
                    'key'   => 'police',
                    'level' => $this->police_status === AccidentCase::POLICE_MISSING ? 'critical' : 'warning',
                    'label' => $this->police_status === AccidentCase::POLICE_MISSING
                        ? 'Police report required'
                        : 'Police report recorded — not yet verified',
                ] : null,
                ! $this->resource->liabilityDecided() ? [
                    'key' => 'liability', 'level' => 'warning', 'label' => 'Liability not yet decided',
                ] : null,
                $this->resource->insurerOverdue() ? [
                    'key' => 'insurer', 'level' => 'warning', 'label' => 'Insurer has not answered by the expected date',
                ] : null,
                ($breakdown['unresolved'] > 0) ? [
                    'key' => 'unresolved', 'level' => 'warning',
                    'label' => 'Unresolved amount: ' . $breakdown['currency'] . ' ' . number_format($breakdown['unresolved'], 2),
                ] : null,
                ($breakdown['actual'] > $breakdown['paid']) ? [
                    'key' => 'outstanding', 'level' => 'info',
                    'label' => 'Outstanding balance: ' . $breakdown['currency'] . ' ' . number_format($breakdown['actual'] - $breakdown['paid'], 2),
                ] : null,
            ])),

            'closed_at'      => $this->closed_at?->toIso8601String(),
            'closed_by_name' => $this->closed_by_name,
            'closure_note'   => $this->closure_note,
            'reopened_at'    => $this->reopened_at?->toIso8601String(),
            'reopen_reason'  => $this->reopen_reason,
            'currency'       => $this->currency,
            'created_at'     => $this->created_at?->toIso8601String(),
            'updated_at'     => $this->updated_at?->toIso8601String(),
        ];
    }
}
