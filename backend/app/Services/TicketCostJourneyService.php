<?php

namespace App\Services;

use App\Models\Maintenance;
use App\Models\MaintenanceLineItem;
use App\Models\MaintenanceRequiredPart;
use App\Models\MaintenanceTask;
use App\Models\PartPurchase;
use App\Models\PartReturn;

/**
 * The ticket's complete cost journey, read end to end:
 *
 *     Fault → Required Part → Purchase Source → Invoice → Installation Cost → Final Ticket Cost
 *
 * Every figure on a ticket should be traceable back to the document it came from, and the two sides of a
 * repair's cost genuinely come from different places:
 *
 *     SUPPLIER  → we buy the part      → PartInvoice        → part cost
 *     GARAGE    → they fit it (labour) → MaintenanceInvoice → labour cost
 *                 (or they supply the part too, and it is a line on THEIR invoice)
 *
 * Read-only. It derives, stores nothing, and holds no opinion the underlying rows don't already carry.
 *
 * ── How double-counting is avoided ──────────────────────────────────────────────────────────────────
 * The ticket total is, and remains, the sum of its maintenance_line_items — one ledger, not two. This
 * service does not add purchases on top of it; it walks the LINES and explains where each came from:
 *
 *     line has a PartPurchase behind it  → attribute it to that buy, and show the buy's supplier invoice
 *     line has no purchase behind it     → the garage supplied it; show the garage's invoice
 *     line was written by a return       → a credit, shown as a negative against its part
 *
 * A purchase that hasn't been fitted yet has no line, so it is NOT in the ticket total. It is reported
 * separately as `committed` — money already spent that the ticket will absorb on installation. Blending
 * the two would make the ticket disagree with the garage's paper.
 *
 * ── Incorrect faults ────────────────────────────────────────────────────────────────────────────────
 * A fault ruled a mis-diagnosis cannot take NEW cost ({@see IncorrectFaultCostGuard}), but money already
 * spent on it is real and stays in the total. It is reported under its own heading — `incorrect_fault_cost`
 * — so it can be read, and measured as diagnosis quality, without being hidden or double-subtracted.
 *
 * Evidence class: D (derived) — Produces E-cost-journey. Consumes maintenance_line_items (F),
 * part_purchases (F), part_invoices (F), part_returns (F), maintenance_tasks.marked_incorrect_at (J).
 */
class TicketCostJourneyService
{
    public function __construct(private CostSourceResolver $sources) {}

    /**
     * Build the whole journey for one ticket.
     *
     * @return array<string,mixed>
     */
    public function build(Maintenance $ticket): array
    {
        $ticket->loadMissing([
            'vehicle:id,plate_no',
            'tasks',
            'lineItems',
            'invoices.vendor:id,name',
        ]);

        $purchases = PartPurchase::with(['invoice.vendor:id,name', 'sourceVendor:id,name', 'returns'])
            ->where('maintenance_id', $ticket->id)
            ->orderBy('id')
            ->get();

        $required = MaintenanceRequiredPart::with('requests:id,status,part_name')
            ->where('maintenance_id', $ticket->id)
            ->orderBy('id')
            ->get();

        // A line is explained by the purchase that generated it (install) or credited it (return).
        $purchaseByLine = $purchases->whereNotNull('maintenance_line_item_id')->keyBy('maintenance_line_item_id');
        $returns        = PartReturn::with('purchase')->where('maintenance_id', $ticket->id)->get();
        $returnByLine   = $returns->whereNotNull('credit_line_item_id')->keyBy('credit_line_item_id');

        $invoiceById = $ticket->invoices->keyBy('id');

        // The four-document lookup, built once for the whole ticket so classifying N lines stays 4 queries.
        $sourceCtx = $this->sources->contextFor($ticket);

        // Group the ticket's real money — its line items — by the fault each was charged to.
        $linesByTask = $ticket->lineItems->groupBy(fn (MaintenanceLineItem $l) => $l->maintenance_task_id ?: 0);

        $faults = $ticket->tasks->map(fn (MaintenanceTask $task) => $this->buildFault(
            $task,
            $linesByTask->get($task->id, collect()),
            $required->where('maintenance_task_id', $task->id),
            $purchases->where('maintenance_task_id', $task->id),
            $purchaseByLine,
            $returnByLine,
            $invoiceById,
            $sourceCtx,
        ))->values()->all();

        // Cost charged to the ticket but to no particular fault (a general or unmatched charge). VAT,
        // discounts and ticket-wide adjustments live here — they belong to a document, not to one fault.
        $general = $this->buildLines($linesByTask->get(0, collect()), $purchaseByLine, $returnByLine, $invoiceById, $sourceCtx);

        // Parts bought against the ticket but not tied to any fault — still money, still shown.
        $unassignedPurchases = $purchases->whereNull('maintenance_task_id')
            ->map(fn (PartPurchase $p) => $this->purchaseNode($p))->values()->all();

        return [
            'ticket' => [
                'id'         => $ticket->id,
                'vehicle_id' => $ticket->vehicle_id,
                'plate_no'   => $ticket->vehicle?->plate_no,
                'cost'       => round((float) $ticket->cost, 2),
            ],
            'faults'     => $faults,
            'general'    => [
                'lines'   => $general['lines'],
                'charges' => $general['charges'],
                'totals'  => $general['totals'],
            ],
            'unassigned_purchases' => $unassignedPurchases,
            'invoices'             => $this->invoiceSummaries($ticket, $purchases),
            'totals'               => $this->ticketTotals($ticket, $faults, $general, $purchases),

            // ── The audit ────────────────────────────────────────────────────────────────────────────
            // Can every dirham on this ticket be traced to a supplier invoice, a garage invoice, a credit
            // note or an approved adjustment? This block answers that with a number, names whatever fails,
            // and is shown on the ticket rather than kept for a report nobody opens.
            'audit' => $this->sources->auditTicket($ticket),

            // Traceability: every number above names the table it was read from (no black boxes).
            'sources' => [
                'ticket_cost'   => 'maintenance_line_items (sum of line_total) → maintenances.cost',
                'part_cost'     => 'part_purchases → part_invoices (supplier) · maintenance_line_items (garage)',
                'labour_cost'   => 'maintenance_line_items kind=labor → maintenance_invoices',
                'vat_discount'  => 'maintenance_line_items kind=vat|discount → maintenance_invoices',
                'refunds'       => 'part_returns (status=refunded) → negative part lines · cost_adjustments (labour)',
                'adjustments'   => 'cost_adjustments → maintenance_line_items (reason + approver required)',
                'allocation'    => 'part_purchases.maintenance_id — a supplier invoice is split across the tickets it covers',
                'committed'     => 'part_purchases with no maintenance_line_item (bought, not yet fitted)',
                'incorrect'     => 'maintenance_tasks.marked_incorrect_at',
            ],
        ];
    }

    // ── Fault-level assembly ─────────────────────────────────────────────────────────────────────────

    /**
     * One fault's whole story: what the inspector said it would need, what was bought for it, what the
     * paper says, what it cost to fit, and what it nets out at.
     */
    private function buildFault(
        MaintenanceTask $task,
        $lines,
        $required,
        $faultPurchases,
        $purchaseByLine,
        $returnByLine,
        $invoiceById,
        array $sourceCtx,
    ): array {
        $built = $this->buildLines($lines, $purchaseByLine, $returnByLine, $invoiceById, $sourceCtx);

        // Parts bought for this fault that have NOT been fitted yet own no line, so they are not in the
        // ticket total. They are still spend, so they are reported — separately, as committed.
        $notFitted = $faultPurchases->filter(fn (PartPurchase $p) => ! $p->maintenance_line_item_id);
        $committed = round((float) $notFitted->sum(fn (PartPurchase $p) => $p->netCost()), 2);

        $incorrect = $task->isIncorrect();

        return [
            'id'       => $task->id,
            'symptom'  => $task->symptom,
            'status'   => $task->status,
            'severity' => $task->severity,

            // The Incorrect ruling — and the money that was already spent before it was made.
            'is_incorrect'      => $incorrect,
            'incorrect_reason'  => $task->incorrect_reason,
            'incorrect_at'      => optional($task->marked_incorrect_at)->toIso8601String(),
            // A fault ruled incorrect accepts no further cost; the UI disables its money actions on this.
            'accepts_new_cost'  => ! $incorrect,

            'required_parts' => $required->map(fn (MaintenanceRequiredPart $rp) => [
                'id'         => $rp->id,
                'part_name'  => $rp->part_name,
                'quantity'   => (float) $rp->quantity,
                'priority'   => $rp->priority,
                'status'     => $rp->status,
                'sourced_by' => $rp->requests->map(fn ($r) => ['id' => $r->id, 'status' => $r->status])->values()->all(),
            ])->values()->all(),

            'parts'   => $built['parts'],
            'labour'  => $built['labour'],
            'charges' => $built['charges'],

            // Bought for this fault, not yet fitted → not in the ticket total (see `committed` in totals).
            'awaiting_installation' => $notFitted->map(fn (PartPurchase $p) => $this->purchaseNode($p))->values()->all(),

            'totals' => $built['totals'] + ['committed' => $committed],
        ];
    }

    /**
     * Split a set of line items into parts and labour, attaching to each the document it came from — the
     * supplier invoice behind its purchase, or the garage invoice it was billed on.
     *
     * @return array{parts:array, labour:array, lines:array, totals:array}
     */
    private function buildLines($lines, $purchaseByLine, $returnByLine, $invoiceById, array $sourceCtx): array
    {
        $parts = [];
        $labour = [];
        $charges = [];   // VAT · discount · ticket-wide adjustment
        $all = [];

        foreach ($lines as $line) {
            /** @var MaintenanceLineItem $line */
            $garageInvoice = $line->maintenance_invoice_id ? $invoiceById->get($line->maintenance_invoice_id) : null;
            $node = [
                'line_item_id' => $line->id,
                'kind_raw'     => $line->kind,
                'description'  => $line->description,
                'finding_text' => $line->finding_text,
                'quantity'     => (float) $line->quantity,
                'unit_price'   => (float) $line->unit_price,
                'total'        => (float) $line->line_total,
                'garage_invoice' => $garageInvoice ? [
                    'id'         => $garageInvoice->id,
                    'invoice_no' => $garageInvoice->invoice_no,
                    'garage'     => $garageInvoice->is_internal ? 'In-House' : $garageInvoice->vendor?->name,
                ] : null,
                // THE audit field: which of the four documents backs this exact figure, or that none does.
                // Every amount the UI prints carries this, so nothing on screen is un-checkable.
                'source' => $this->sources->forLine($line, $sourceCtx),
            ];

            // VAT, discounts and ticket-wide adjustments are document arithmetic, not work done. They get
            // their own bucket so they never masquerade as a part or a labour charge on screen.
            if (in_array($line->kind, [
                MaintenanceLineItem::KIND_VAT,
                MaintenanceLineItem::KIND_DISCOUNT,
                MaintenanceLineItem::KIND_ADJUSTMENT,
            ], true)) {
                $charges[] = $node + ['kind' => $line->kind];
                $all[] = $node + ['kind' => $line->kind];
                continue;
            }

            if ($line->kind === MaintenanceLineItem::KIND_LABOR) {
                $labour[] = $node + [
                    'hours' => (float) $line->quantity,
                    'rate'  => (float) $line->unit_price,
                    // A negative labour line is a refund, and only an approved adjustment can make one.
                    'is_refund' => (float) $line->line_total < 0,
                ];
                $all[] = $node + ['kind' => 'labour'];
                continue;
            }

            // A part line is one of three things, and the ticket should say which.
            if ($return = $returnByLine->get($line->id)) {
                $parts[] = $node + [
                    'kind'          => 'credit',
                    'origin'        => 'return',
                    'part_return_id'=> $return->id,
                    'reason_code'   => $return->reason_code,
                    'reason_label'  => $return->reasonLabel(),
                    'part_name'     => $return->purchase?->part_name,
                ];
                $all[] = $node + ['kind' => 'credit'];
                continue;
            }

            $purchase = $purchaseByLine->get($line->id);
            $parts[] = $node + ($purchase
                ? ['kind' => 'part', 'origin' => $purchase->purchase_source] + $this->purchaseNode($purchase)
                : [
                    'kind'      => 'part',
                    // No purchase behind it → the garage supplied this part on its own invoice.
                    'origin'    => 'garage_invoice',
                    'part_name' => $line->description,
                    'supplier'  => $garageInvoice?->vendor?->name,
                ]);
            $all[] = $node + ['kind' => 'part'];
        }

        return [
            'parts'   => $parts,
            'labour'  => $labour,
            'charges' => $charges,
            'lines'   => $all,
            'totals'  => $this->lineTotals($lines),
        ];
    }

    /**
     * The six bands a ticket must separate, derived from a set of lines:
     *
     *     parts · labour · VAT · discounts · refunds/returns · net total
     *
     * The split is by SIGN as well as by kind, because a band answers a different question from a line
     * kind. "Parts cost" means what the parts were charged at — so a return's credit belongs in refunds,
     * not netted silently into parts, and a labour refund belongs there too rather than quietly shrinking
     * the labour figure. What each band contains stays visible; only the net total blends them.
     *
     * The bands are exhaustive by construction: every line falls into exactly one, so their sum is the
     * ticket's cost and no money can hide between them.
     */
    private function lineTotals($lines): array
    {
        $partLines   = $lines->where('kind', MaintenanceLineItem::KIND_PART);
        $labourLines = $lines->where('kind', MaintenanceLineItem::KIND_LABOR);

        $parts  = round((float) $partLines->where('line_total', '>', 0)->sum('line_total'), 2);
        $labour = round((float) $labourLines->where('line_total', '>', 0)->sum('line_total'), 2);

        // Money coming back, kept apart by WHAT it reverses. A part return may only ever credit parts;
        // labour only comes down through an explicit, approved adjustment (see CostAdjustmentService).
        $partsReturned  = round((float) $partLines->where('line_total', '<', 0)->sum('line_total'), 2);
        $labourRefunded = round((float) $labourLines->where('line_total', '<', 0)->sum('line_total'), 2);

        $vat        = round((float) $lines->where('kind', MaintenanceLineItem::KIND_VAT)->sum('line_total'), 2);
        $discounts  = round((float) $lines->where('kind', MaintenanceLineItem::KIND_DISCOUNT)->sum('line_total'), 2);
        $adjustments = round((float) $lines->where('kind', MaintenanceLineItem::KIND_ADJUSTMENT)->sum('line_total'), 2);

        $refunds = round($partsReturned + $labourRefunded, 2);

        return [
            'parts'            => $parts,
            'labour'           => $labour,
            'vat'              => $vat,
            'discounts'        => $discounts,      // negative
            'refunds'          => $refunds,        // negative
            'parts_returned'   => $partsReturned,  // negative — the part-return half of refunds
            'labour_refunded'  => $labourRefunded, // negative — only ever an approved adjustment
            'adjustments'      => $adjustments,    // signed; ticket-level corrections
            'net'              => round($parts + $labour + $vat + $discounts + $refunds + $adjustments, 2),
        ];
    }

    /** A purchase as the ticket should read it: what, from whom, for how much, on which paper, net of returns. */
    private function purchaseNode(PartPurchase $purchase): array
    {
        $invoice = $purchase->invoice;

        return [
            'purchase_id'  => $purchase->id,
            'part_name'    => $purchase->part_name,
            'part_number'  => $purchase->part_number,
            'quantity'     => (float) ($purchase->quantity ?: 1),
            'unit_price'   => (float) $purchase->purchase_price,
            'currency'     => $purchase->currency,
            'gross'        => $purchase->grossCost(),
            'refunded'     => $purchase->refundedTotal(),
            'net'          => $purchase->netCost(),
            'source'       => $purchase->purchase_source,          // 'supplier' | 'garage'
            'supplier'     => $purchase->sourceVendor?->name ?: $purchase->source_name,
            'purchased_at' => optional($purchase->purchased_at)->toIso8601String(),
            'delivered_at' => optional($purchase->delivered_at)->toIso8601String(),
            'installed_at' => optional($purchase->installed_at)->toIso8601String(),

            // The paper. Supplier buys carry their own invoice; a garage buy is on the garage's bill and
            // deliberately has none — that is what keeps the ticket from counting the part twice.
            'invoice' => $invoice ? [
                'id'         => $invoice->id,
                'invoice_no' => $invoice->invoice_no,
                'date'       => optional($invoice->invoice_date)->toDateString(),
                'supplier'   => $invoice->supplierLabel(),
                'total'      => (float) $invoice->total_amount,
                'currency'   => $invoice->currency,
                'photo_url'  => $invoice->photoUrl(),
                'variance'   => $invoice->variance(),
            ] : null,
            // Supplier buy with no invoice keyed yet — the evidence gap the ticket should be nagging about.
            'invoice_missing' => $purchase->needsPartInvoice() && ! $invoice,

            'returns' => $purchase->returns->map(fn (PartReturn $r) => [
                'id'             => $r->id,
                'status'         => $r->status,
                'quantity'       => (float) $r->quantity,
                'reason_code'    => $r->reason_code,
                'reason_label'   => $r->reasonLabel(),
                'reason_note'    => $r->reason_note,
                'refund_amount'  => (float) $r->refund_amount,
                'restocking_fee' => (float) $r->restocking_fee,
                'credited'       => $r->isRefunded(),
                'returned_at'    => optional($r->returned_at)->toIso8601String(),
            ])->values()->all(),
        ];
    }

    // ── Ticket-level assembly ────────────────────────────────────────────────────────────────────────

    /**
     * Both kinds of paper on this ticket, side by side: the garages' bills and the suppliers' bills. A
     * repair can carry several of each — brake pads from supplier A, sensors from supplier B, labour from
     * garage C — and they still roll into one summary.
     *
     * ── Allocation ──────────────────────────────────────────────────────────────────────────────────
     * A supplier invoice is NOT owned by a ticket. One trip to the counter buys parts for three different
     * cars, and the invoice is a single document covering all of them. So the invoice is reported here
     * with TWO figures that must never be confused:
     *
     *     allocated  — the part of it that belongs to THIS ticket   ← the only figure that is ticket money
     *     total      — what the whole document says                 ← context, shown as such
     *
     * Showing the document total as the ticket's part cost would over-state every shared invoice, which is
     * exactly the sort of unauditable number this whole design exists to prevent.
     */
    private function invoiceSummaries(Maintenance $ticket, $purchases): array
    {
        return [
            'garage' => $ticket->invoices->map(fn ($inv) => [
                'id'             => $inv->id,
                'invoice_no'     => $inv->invoice_no,
                'garage'         => $inv->is_internal ? 'In-House' : $inv->vendor?->name,
                'parts_total'    => (float) $inv->parts_total,
                'labor_total'    => (float) $inv->labor_total,
                'vat_total'      => (float) $inv->vat_total,
                'discount_total' => (float) $inv->discount_total,
                'amount'         => (float) $inv->amount,
                'reconciliation_status' => $inv->reconciliation_status,
                'variance'       => $inv->variance(),
                'photo_url'      => $inv->receiptPhotoUrl(),
                // A garage invoice belongs to exactly one ticket, so its amount IS its allocation.
                'allocated'      => (float) $inv->amount,
                'shared'         => false,
            ])->values()->all(),

            'supplier' => $purchases->pluck('invoice')->filter()->unique('id')->map(function ($inv) use ($ticket, $purchases) {
                // What this ticket actually took off the document: its own parts, net of anything returned.
                $mine = $purchases->where('part_invoice_id', $inv->id);
                $allocated = round((float) $mine->sum(fn (PartPurchase $p) => $p->netCost()), 2);

                // Does the document also cover other tickets or cars? Counted from the invoice itself, so
                // the answer holds even for purchases this ticket cannot see.
                $lines = $inv->relationLoaded('purchases') ? $inv->purchases : $inv->purchases()->get();
                $otherTickets = $lines->pluck('maintenance_id')->filter()
                    ->reject(fn ($id) => (int) $id === $ticket->id)->unique()->values();

                return [
                    'id'         => $inv->id,
                    'invoice_no' => $inv->invoice_no,
                    'supplier'   => $inv->supplierLabel(),
                    'date'       => optional($inv->invoice_date)->toDateString(),
                    'total'      => (float) $inv->total_amount,
                    'tax_amount' => (float) $inv->tax_amount,
                    'discount_amount' => (float) $inv->discount_amount,
                    'currency'   => $inv->currency,
                    'photo_url'  => $inv->photoUrl(),
                    'variance'   => $inv->variance(),

                    // The ticket's share — the ONLY figure from this document that is this ticket's money.
                    'allocated'  => $allocated,
                    'part_count' => $mine->count(),
                    'shared'     => $otherTickets->isNotEmpty(),
                    'shared_with_ticket_ids' => $otherTickets->all(),
                ];
            })->values()->all(),
        ];
    }

    /**
     * The ticket's headline money. `net_total` is the ticket cost itself (the sum of its lines) — this
     * function explains that number, it does not compute a rival one.
     */
    private function ticketTotals(Maintenance $ticket, array $faults, array $general, $purchases): array
    {
        $lines = $ticket->lineItems;
        $bands = $this->lineTotals($lines);

        $supplierLineIds = $purchases->where('purchase_source', PartPurchase::SOURCE_SUPPLIER)
            ->pluck('maintenance_line_item_id')->filter()->flip();

        $partLines = $lines->where('kind', MaintenanceLineItem::KIND_PART);
        $charged   = $partLines->where('line_total', '>', 0);

        // Within the parts band, WHO sold it — the split that makes "where did every dirham go" answerable.
        $supplierParts = round((float) $charged->filter(fn ($l) => $supplierLineIds->has($l->id))->sum('line_total'), 2);
        $garageParts   = round((float) $charged->reject(fn ($l) => $supplierLineIds->has($l->id))->sum('line_total'), 2);
        $credits       = $bands['refunds'];
        $labour        = $bands['labour'];

        // Bought, not yet fitted → real spend that is not in the ticket total yet. Kept OUT of net_total on
        // purpose: adding it would make the ticket disagree with the garage's invoice.
        $committed = round((float) $purchases
            ->filter(fn (PartPurchase $p) => ! $p->maintenance_line_item_id)
            ->sum(fn (PartPurchase $p) => $p->netCost()), 2);

        // Money spent on faults later ruled a mis-diagnosis. Reported, never removed.
        $incorrectFaults = collect($faults)->where('is_incorrect', true);
        $incorrectCost   = round((float) $incorrectFaults->sum(fn ($f) => $f['totals']['net']), 2);

        return [
            // ── The six bands the ticket must separate ──────────────────────────────────────────────
            'parts'          => $bands['parts'],
            'labour'         => $labour,
            'vat'            => $bands['vat'],
            'discounts'      => $bands['discounts'],    // negative
            'refunds'        => $credits,               // negative
            'adjustments'    => $bands['adjustments'],  // signed
            'net_total'      => $bands['net'],

            // Where the parts money went, inside the parts band.
            'supplier_parts' => $supplierParts,
            'garage_parts'   => $garageParts,

            // Refunds, split by what they reversed — a part return can NEVER reduce labour; only an
            // approved adjustment can, and this is where that shows.
            'parts_returned'  => $bands['parts_returned'],
            'labour_refunded' => $bands['labour_refunded'],

            'committed'      => $committed,

            'incorrect_fault_cost' => [
                'amount' => $incorrectCost,
                'count'  => $incorrectFaults->count(),
                'faults' => $incorrectFaults->map(fn ($f) => [
                    'id'      => $f['id'],
                    'symptom' => $f['symptom'],
                    'reason'  => $f['incorrect_reason'],
                    'spent'   => $f['totals']['net'],
                ])->values()->all(),
                'note' => 'Spent before the diagnosis was ruled incorrect. It stays in the ticket total — '
                    . 'the money left the company — and is reported here to measure diagnosis quality.',
            ],
        ];
    }
}
