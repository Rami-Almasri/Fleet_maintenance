<?php

namespace App\Http\Resources;

use App\Models\MaintenanceInvoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One garage bill on a maintenance ticket — the read side of [[one Ticket → many Invoices]]. Carries the
 * garage, the faults it covers, its parts/labor split + grand total, the printed receipt + variance, its
 * own reconciliation status, and the receipt-photo URL. `line_items` / `task_ids` are present only when the
 * relations were eager-loaded (the ticket show/board loads them; a bare list may not).
 */
class MaintenanceInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var MaintenanceInvoice $inv */
        $inv = $this->resource;

        return [
            'id'                    => $inv->id,
            'maintenance_id'        => $inv->maintenance_id,
            'vendor_id'             => $inv->vendor_id,
            'is_internal'           => (bool) $inv->is_internal,
            'vendor_name'           => $this->whenLoaded('vendor', fn () => $inv->vendor?->name),
            'invoice_no'            => $inv->invoice_no,

            // Money.
            'parts_total'           => (float) $inv->parts_total,
            'labor_total'           => (float) $inv->labor_total,
            // The other two bands on the same bill. They were written as ledger lines and rolled into
            // `amount`, but were never published — so every reader could only print "parts + labor" for a
            // total that also carried VAT, and the editor had no field to re-key them from.
            // The discount is stored NEGATIVE (see MaintenanceInvoiceService::applyVatAndDiscount) and is
            // published as it is stored, so `parts + labor + vat + discount` is a plain sum.
            'vat_total'             => (float) $inv->vat_total,
            'discount_total'        => (float) $inv->discount_total,
            'amount'                => (float) $inv->amount,
            'receipt_total'         => $inv->receipt_total !== null ? (float) $inv->receipt_total : null,
            'variance'              => $inv->variance(),
            'variance_explanation'  => $inv->variance_explanation,

            // Accounting bridge — this invoice's own clock. Distinct from the lifecycle `status` below:
            // reconciliation answers "did finance match this against the accounting system", while the
            // lifecycle answers "where is this bill in its own life" (draft → approved → paid).
            'reconciliation_status' => $inv->reconciliation_status,
            'reconciled_at'         => optional($inv->reconciled_at)->toIso8601String(),

            // The shared financial-document lifecycle (see IsFinancialDocument): status, approval,
            // payment and what is still outstanding.
            'lifecycle'             => $inv->statusPayload(),

            // The faults this invoice covers + their line breakdown (eager-loaded on the ticket surface).
            'task_ids'              => $inv->relationLoaded('tasks') ? $inv->tasks->pluck('id')->all() : null,
            // The covered WORK. `kind` travels with each row because a ticket carries faults, planned
            // services, damage and checks — an oil change on a bill must not read as a fault anywhere.
            // (The key stays `faults` for compatibility with the clients already reading it.)
            'faults'                => $inv->relationLoaded('tasks')
                ? $inv->tasks->map(fn ($t) => [
                    'id'        => $t->id,
                    'symptom'   => $t->symptom,
                    'status'    => $t->status,
                    'kind'      => $t->kind,
                    'kind_meta' => $t->kindMeta(),
                ])->values()
                : null,
            'line_items'            => $inv->relationLoaded('lineItems')
                ? MaintenanceLineItemResource::collection($inv->lineItems)
                : null,

            'receipt_photo_url'     => $inv->receiptPhotoUrl(),
            'notes'                 => $inv->notes,
            'recorded_at'           => optional($inv->recorded_at)->toIso8601String(),
            'created_at'            => optional($inv->created_at)->toIso8601String(),
        ];
    }
}
