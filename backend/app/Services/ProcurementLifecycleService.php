<?php

namespace App\Services;

use App\Models\Maintenance;
use App\Models\PartInvoice;
use App\Models\PartPurchase;
use App\Models\PartRequest;
use App\Models\PartReturn;
use App\Support\FinancialDocumentStatus as Status;

/**
 * The accounting lifecycle of a repair, read as one chain per part:
 *
 *     Purchase Request → Purchase Order → Supplier Invoice → Goods Received
 *       → Part Installed → Return / Credit Note → Supplier Payment → Ticket Closed
 *
 * The point is to answer more than "what did we spend". A ticket should be able to say what has been
 * ORDERED, what has been RECEIVED, what is FITTED, what went BACK and what has been PAID — because those
 * are different questions with different answers, and a single cost figure collapses all of them into one.
 *
 * Every stage reports one of four states, and nothing else:
 *
 *     done       — it happened, and there is a date and usually a document to prove it
 *     current    — it is where this part sits right now
 *     pending    — not reached yet
 *     skipped    — legitimately not applicable (no RFQ was raised; nothing was returned)
 *
 * `skipped` matters as much as `done`. A chain that showed a missing purchase order as an omission would
 * cry wolf on every direct buy, and a system that cries wolf gets ignored.
 *
 * Read-only and derived: it reads the rows the workflow already writes and invents no state of its own.
 *
 * Evidence class: D (derived) — Produces E-procurement-lifecycle. Consumes part_requests (F),
 * part_purchases (F), part_invoices (F), part_returns (F), maintenances (F).
 */
class ProcurementLifecycleService
{
    public const STATE_DONE    = 'done';
    public const STATE_CURRENT = 'current';
    public const STATE_PENDING = 'pending';
    public const STATE_SKIPPED = 'skipped';

    /** The stage keys, in order. The UI renders the chain from this, so order lives in one place. */
    public const STAGES = [
        'purchase_request',
        'purchase_order',
        'supplier_invoice',
        'goods_received',
        'part_installed',
        'return_credit',
        'supplier_payment',
        'ticket_closed',
    ];

    public const STAGE_LABELS = [
        'purchase_request' => 'Purchase request',
        'purchase_order'   => 'Purchase order',
        'supplier_invoice' => 'Supplier invoice',
        'goods_received'   => 'Goods received',
        'part_installed'   => 'Part installed',
        'return_credit'    => 'Return / credit note',
        'supplier_payment' => 'Supplier payment',
        'ticket_closed'    => 'Ticket closed',
    ];

    /**
     * The lifecycle of every part on a ticket, plus a roll-up of where the ticket as a whole stands.
     *
     * @return array<string,mixed>
     */
    public function forTicket(Maintenance $ticket): array
    {
        $requests = PartRequest::with([
            'purchases.invoice.vendor:id,name',
            'purchases.returns',
            'purchases.sourceVendor:id,name',
        ])->where('maintenance_id', $ticket->id)->orderBy('id')->get();

        // A purchase logged straight against the ticket, with no request behind it, still has a life —
        // it simply starts at the buy. Including it stops the chain from quietly omitting real spend.
        $looseBuys = PartPurchase::with(['invoice.vendor:id,name', 'returns', 'sourceVendor:id,name'])
            ->where('maintenance_id', $ticket->id)
            ->whereNull('part_request_id')
            ->orderBy('id')
            ->get();

        $chains = $requests->flatMap(fn (PartRequest $r) => $this->chainsForRequest($r, $ticket))
            ->concat($looseBuys->map(fn (PartPurchase $p) => $this->chain($p->part_name, null, $p, $ticket)))
            ->values()
            ->all();

        return [
            'ticket_id' => $ticket->id,
            'stages'    => collect(self::STAGES)->map(fn ($k) => ['key' => $k, 'label' => self::STAGE_LABELS[$k]])->all(),
            'chains'    => $chains,
            'summary'   => $this->summarise($chains, $ticket),
        ];
    }

    /** One chain per purchase; a request with no purchase yet is still a chain, stopped at its first stage. */
    private function chainsForRequest(PartRequest $request, Maintenance $ticket)
    {
        if ($request->purchases->isEmpty()) {
            return collect([$this->chain($request->part_name, $request, null, $ticket)]);
        }

        return $request->purchases->map(fn (PartPurchase $p) => $this->chain($request->part_name, $request, $p, $ticket));
    }

    /**
     * Build one part's chain. Each stage is resolved independently from the rows that prove it, then the
     * FIRST unfinished stage is marked `current` so the chain has exactly one "you are here".
     */
    private function chain(?string $partName, ?PartRequest $request, ?PartPurchase $purchase, Maintenance $ticket): array
    {
        $invoice = $purchase?->invoice;
        $returns = $purchase ? ($purchase->relationLoaded('returns') ? $purchase->returns : $purchase->returns()->get()) : collect();
        $refunded = $returns->where('status', PartReturn::STATUS_REFUNDED);

        $stages = [
            'purchase_request' => $this->stage(
                done: (bool) $request,
                at: $request?->requested_at,
                by: $request?->requested_by_name,
                document: $request ? 'REQ-' . $request->id : null,
                detail: $request ? ucfirst(str_replace('_', ' ', $request->status)) : 'Bought without a request',
                // A direct buy never had a request, and that is a legitimate path, not a gap.
                skipped: ! $request && $purchase,
            ),

            'purchase_order' => $this->stage(
                done: (bool) ($purchase?->po_number || $purchase?->rfq_line_id),
                at: $purchase?->purchased_at,
                document: $purchase?->po_number,
                detail: $purchase?->rfq_line_id ? 'Awarded from an RFQ' : ($purchase?->po_number ? 'PO issued' : null),
                // Most buys are direct and never raise a PO. Optional by design.
                skipped: $purchase && ! $purchase->po_number && ! $purchase->rfq_line_id,
            ),

            'supplier_invoice' => $this->stage(
                done: (bool) $invoice,
                at: $invoice?->invoice_date,
                document: $invoice?->invoice_no,
                detail: $invoice
                    ? $invoice->supplierLabel() . ' · ' . Status::label($invoice->documentStatus())
                    : ($purchase && $purchase->purchase_source === PartPurchase::SOURCE_GARAGE
                        ? "Billed on the garage's invoice"
                        : null),
                // A garage-supplied part is on the garage's bill and never gets a supplier invoice.
                skipped: $purchase && $purchase->purchase_source === PartPurchase::SOURCE_GARAGE,
                blocking: $purchase && $purchase->purchase_source === PartPurchase::SOURCE_SUPPLIER && ! $invoice
                    ? 'Bought, but no supplier invoice has been recorded — this spend has no document.'
                    : null,
            ),

            'goods_received' => $this->stage(
                done: (bool) ($purchase?->delivered_at || $purchase?->installed_at),
                at: $purchase?->delivered_at ?: $purchase?->installed_at,
                detail: $purchase?->delivered_at ? 'Delivered to the workshop' : ($purchase?->installed_at ? 'Implied by installation' : null),
            ),

            'part_installed' => $this->stage(
                done: (bool) $purchase?->installed_at,
                at: $purchase?->installed_at,
                by: $purchase?->installed_by_name,
                detail: $purchase?->installed_at ? 'Fitted to the car' : null,
            ),

            'return_credit' => $this->stage(
                done: $refunded->isNotEmpty(),
                at: $refunded->first()?->settled_at,
                document: $refunded->first() ? 'RET-' . $refunded->first()->id : null,
                detail: $returns->isNotEmpty()
                    ? $returns->first()->reasonLabel() . ' · ' . ucfirst($returns->first()->status)
                    : null,
                // Nothing came back, which is the normal and desirable outcome.
                skipped: $returns->isEmpty(),
            ),

            'supplier_payment' => $this->stage(
                done: $invoice && in_array($invoice->documentStatus(), [Status::PAID, Status::REFUNDED], true),
                at: $invoice?->paid_at,
                document: $invoice?->payment_reference,
                detail: $invoice
                    ? Status::label($invoice->documentStatus())
                        . ($invoice->outstandingAmount() > 0 ? ' · AED ' . number_format($invoice->outstandingAmount(), 2) . ' outstanding' : '')
                    : null,
                // No supplier invoice → nothing to pay a supplier for (the garage bill is settled elsewhere).
                skipped: ! $invoice,
            ),

            'ticket_closed' => $this->stage(
                done: in_array($ticket->workflow_status, ['completed', 'closed'], true) || $ticket->status === 'Completed',
                at: $ticket->actual_out_date,
                detail: $ticket->workflow_status ? ucfirst(str_replace('_', ' ', $ticket->workflow_status)) : $ticket->status,
            ),
        ];

        $stages = $this->markCurrent($stages);

        return [
            'part_name'    => $partName ?: $purchase?->part_name,
            'request_id'   => $request?->id,
            'purchase_id'  => $purchase?->id,
            'supplier'     => $purchase?->sourceVendor?->name ?: $purchase?->source_name,
            'source'       => $purchase?->purchase_source,
            'gross'        => $purchase?->grossCost() ?? 0.0,
            'net'          => $purchase?->netCost() ?? 0.0,
            'stages'       => $stages,
            // The single sentence a reader actually wants: what is holding this part up?
            'blocking'     => collect($stages)->firstWhere('blocking', '!=', null)['blocking'] ?? null,
            'current_stage' => collect($stages)->search(fn ($s) => $s['state'] === self::STATE_CURRENT) ?: null,
        ];
    }

    /**
     * Mark the first stage that is neither done nor skipped as `current`; everything after it is pending.
     * Exactly one "you are here" per chain, so the UI never has to decide.
     */
    private function markCurrent(array $stages): array
    {
        $found = false;

        foreach ($stages as $key => $stage) {
            if ($stage['state'] === self::STATE_DONE || $stage['state'] === self::STATE_SKIPPED) {
                continue;
            }
            if (! $found) {
                $stages[$key]['state'] = self::STATE_CURRENT;
                $found = true;
            }
        }

        return $stages;
    }

    /** One stage of one chain. `skipped` beats `done` only when nothing actually happened. */
    private function stage(
        bool $done,
        $at = null,
        ?string $by = null,
        ?string $document = null,
        ?string $detail = null,
        bool $skipped = false,
        ?string $blocking = null,
    ): array {
        return [
            'state'    => $done ? self::STATE_DONE : ($skipped ? self::STATE_SKIPPED : self::STATE_PENDING),
            'at'       => $at ? (is_string($at) ? $at : $at->toIso8601String()) : null,
            'by'       => $by,
            'document' => $document,
            'detail'   => $detail,
            'blocking' => $blocking,
        ];
    }

    /**
     * The ticket-level roll-up: what is ordered, received, fitted, returned and still owed — the numbers
     * that a cost figure alone cannot give.
     */
    private function summarise(array $chains, Maintenance $ticket): array
    {
        $rows = collect($chains);

        $awaitingInvoice = $rows->filter(fn ($c) => $c['stages']['supplier_invoice']['blocking'] !== null);

        // Money still owed to suppliers, counted once per invoice even when it covers several parts.
        $outstanding = PartInvoice::whereHas('purchases', fn ($q) => $q->where('maintenance_id', $ticket->id))
            ->get()
            ->unique('id')
            ->sum(fn (PartInvoice $i) => $i->outstandingAmount());

        return [
            'parts_total'       => $rows->count(),
            'ordered'           => $rows->filter(fn ($c) => $c['purchase_id'] !== null)->count(),
            'received'          => $rows->filter(fn ($c) => $c['stages']['goods_received']['state'] === self::STATE_DONE)->count(),
            'installed'         => $rows->filter(fn ($c) => $c['stages']['part_installed']['state'] === self::STATE_DONE)->count(),
            'returned'          => $rows->filter(fn ($c) => $c['stages']['return_credit']['state'] === self::STATE_DONE)->count(),
            'awaiting_invoice'  => $awaitingInvoice->count(),
            'awaiting_invoice_value' => round((float) $awaitingInvoice->sum('gross'), 2),
            'outstanding_to_suppliers' => round((float) $outstanding, 2),
            'blocked'           => $rows->filter(fn ($c) => $c['blocking'] !== null)->pluck('part_name')->values()->all(),
        ];
    }
}
