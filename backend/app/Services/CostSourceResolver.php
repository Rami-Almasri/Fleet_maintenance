<?php

namespace App\Services;

use App\Models\CostAdjustment;
use App\Models\Maintenance;
use App\Models\MaintenanceLineItem;
use App\Models\PartPurchase;
use App\Models\PartReturn;
use Illuminate\Support\Collection;

/**
 * "Which document does this dirham come from?" — asked of every line on a ticket, and answered or flagged.
 *
 * The rule this enforces is that no figure may appear on a ticket that cannot be audited. There are
 * exactly FOUR source documents, and a line is traceable only if it resolves to one of them:
 *
 *     supplier_invoice  part_invoices        — what a supplier charged for a part
 *     garage_invoice    maintenance_invoices — what a garage charged to fit it (and any part it supplied)
 *     credit_note       part_returns         — a part that went back, and the money that came with it
 *     adjustment        cost_adjustments     — a reasoned, approved correction (labour refund, goodwill…)
 *
 * Anything else is UNSOURCED, and this class says so out loud rather than letting the number pass. That
 * covers two real cases in the existing data: a hand-typed lump-sum ticket cost (`cost_is_itemized` is
 * false — a figure with no lines at all), and a manually keyed line that was never attached to an invoice.
 *
 * Read-only and side-effect free. It classifies; it never writes and never alters a total. A ticket with
 * untraceable money keeps showing that money — hiding it would be a second, worse error — but it is
 * reported as untraceable everywhere the figures are shown.
 *
 * Evidence class: D (derived) — Produces E-cost-trace. Consumes maintenance_line_items (F),
 * part_purchases (F), part_invoices (F), part_returns (F), cost_adjustments (F).
 */
class CostSourceResolver
{
    public const SOURCE_SUPPLIER_INVOICE = 'supplier_invoice';
    public const SOURCE_GARAGE_INVOICE   = 'garage_invoice';
    public const SOURCE_CREDIT_NOTE      = 'credit_note';
    public const SOURCE_ADJUSTMENT       = 'adjustment';
    public const SOURCE_UNSOURCED        = 'unsourced';

    public const DOCUMENTED = [
        self::SOURCE_SUPPLIER_INVOICE,
        self::SOURCE_GARAGE_INVOICE,
        self::SOURCE_CREDIT_NOTE,
        self::SOURCE_ADJUSTMENT,
    ];

    /** Human wording, used verbatim in the UI so the audit reads the same everywhere. */
    public const LABELS = [
        self::SOURCE_SUPPLIER_INVOICE => 'Supplier invoice',
        self::SOURCE_GARAGE_INVOICE   => 'Garage invoice',
        self::SOURCE_CREDIT_NOTE      => 'Credit note (return)',
        self::SOURCE_ADJUSTMENT       => 'Adjustment',
        self::SOURCE_UNSOURCED        => 'No source document',
    ];

    /**
     * Build the lookups a whole ticket needs, so classifying N lines costs 4 queries and not 4N.
     *
     * @return array{purchases:Collection, returns:Collection, adjustments:Collection}
     */
    public function contextFor(Maintenance $ticket): array
    {
        return [
            'purchases' => PartPurchase::with('invoice.vendor:id,name')
                ->where('maintenance_id', $ticket->id)
                ->whereNotNull('maintenance_line_item_id')
                ->get()
                ->keyBy('maintenance_line_item_id'),

            'returns' => PartReturn::with('purchase')
                ->where('maintenance_id', $ticket->id)
                ->whereNotNull('credit_line_item_id')
                ->get()
                ->keyBy('credit_line_item_id'),

            'adjustments' => CostAdjustment::with('vendor:id,name')
                ->where('maintenance_id', $ticket->id)
                ->whereNotNull('line_item_id')
                ->get()
                ->keyBy('line_item_id'),
        ];
    }

    /**
     * Classify ONE line: which document backs it, and can it be audited?
     *
     * Order matters. A return credit is checked before its purchase, because the credit line is a credit
     * note in its own right even though the purchase that produced the original charge also exists.
     *
     * @param array{purchases:Collection, returns:Collection, adjustments:Collection} $ctx from contextFor()
     * @return array{type:string, label:string, traceable:bool, reference:?string, document_id:?int,
     *               party:?string, photo_url:?string, detail:?string}
     */
    public function forLine(MaintenanceLineItem $line, array $ctx): array
    {
        // 1. A credit note — a part went back and the money came with it.
        if ($return = $ctx['returns']->get($line->id)) {
            return $this->doc(
                self::SOURCE_CREDIT_NOTE,
                reference: 'RET-' . $return->id,
                documentId: $return->id,
                party: $return->purchase?->sourceVendor?->name ?: $return->purchase?->source_name,
                detail: $return->reasonLabel(),
            );
        }

        // 2. An approved adjustment — the fourth document, and always explained.
        if ($adjustment = $ctx['adjustments']->get($line->id)) {
            return $this->doc(
                self::SOURCE_ADJUSTMENT,
                reference: $adjustment->reference ?: 'ADJ-' . $adjustment->id,
                documentId: $adjustment->id,
                party: $adjustment->vendor?->name,
                photoUrl: $adjustment->photoUrl(),
                detail: $adjustment->reasonLabel() . ' · approved by ' . $adjustment->approved_by_name,
            );
        }

        // 3. A purchased part — traceable only as far as its SUPPLIER INVOICE. A purchase on its own is
        //    not a document: it is our own record of having paid, with nothing to check it against. This
        //    is the distinction that makes the audit worth running.
        if ($purchase = $ctx['purchases']->get($line->id)) {
            if ($purchase->invoice) {
                return $this->doc(
                    self::SOURCE_SUPPLIER_INVOICE,
                    reference: $purchase->invoice->invoice_no ?: 'PINV-' . $purchase->invoice->id,
                    documentId: $purchase->invoice->id,
                    party: $purchase->invoice->supplierLabel(),
                    photoUrl: $purchase->invoice->photoUrl(),
                    detail: optional($purchase->invoice->invoice_date)->toDateString(),
                );
            }

            // A GARAGE-sourced part legitimately has no supplier invoice — it belongs to the garage's
            // bill. If it is on one, that bill is its document; if not, it is genuinely unsourced.
            if ($purchase->purchase_source === PartPurchase::SOURCE_GARAGE && $line->maintenance_invoice_id) {
                return $this->garageInvoice($line);
            }

            return $this->unsourced(
                $purchase->purchase_source === PartPurchase::SOURCE_SUPPLIER
                    ? 'Bought from a supplier, but no supplier invoice has been recorded against it.'
                    : 'Bought from the garage, but not attached to that garage’s invoice.',
            );
        }

        // 4. A line on a garage's bill.
        if ($line->maintenance_invoice_id) {
            return $this->garageInvoice($line);
        }

        // 5. Nothing backs it.
        return $this->unsourced('Keyed by hand and never attached to an invoice.');
    }

    /**
     * Classify a whole ticket's lines and total the traceable vs untraceable money.
     *
     * A ticket whose cost was typed as a lump sum has NO lines to classify, so it is reported separately:
     * the entire figure is untraceable, and saying "0 untraceable lines" about it would be a lie by
     * omission — precisely the black box this audit exists to prevent.
     *
     * @return array<string,mixed>
     */
    public function auditTicket(Maintenance $ticket): array
    {
        $ticket->loadMissing('lineItems');
        $ctx = $this->contextFor($ticket);

        $byType = [];
        $untraceable = [];
        $traceableTotal = 0.0;
        $untraceableTotal = 0.0;

        foreach ($ticket->lineItems as $line) {
            $source = $this->forLine($line, $ctx);
            $amount = (float) $line->line_total;

            $byType[$source['type']] = round(($byType[$source['type']] ?? 0) + $amount, 2);

            if ($source['traceable']) {
                $traceableTotal += $amount;
            } else {
                $untraceableTotal += $amount;
                $untraceable[] = [
                    'line_item_id' => $line->id,
                    'kind'         => $line->kind,
                    'description'  => $line->description,
                    'amount'       => $amount,
                    'why'          => $source['detail'],
                ];
            }
        }

        // The lump-sum case: a cost with no lines behind it at all.
        $lumpSum = (! $ticket->cost_is_itemized && (float) $ticket->cost != 0.0)
            ? round((float) $ticket->cost, 2)
            : 0.0;
        if ($lumpSum != 0.0) {
            $untraceableTotal += $lumpSum;
            $byType[self::SOURCE_UNSOURCED] = round(($byType[self::SOURCE_UNSOURCED] ?? 0) + $lumpSum, 2);
            $untraceable[] = [
                'line_item_id' => null,
                'kind'         => 'lump_sum',
                'description'  => 'Ticket cost entered as a single figure',
                'amount'       => $lumpSum,
                'why'          => 'Typed directly onto the ticket, with no lines and no document behind it.',
            ];
        }

        $total = round($traceableTotal + $untraceableTotal, 2);

        return [
            'traceable'          => round($traceableTotal, 2),
            'untraceable'        => round($untraceableTotal, 2),
            'total'              => $total,
            // The headline the UI shows: is every dirham on this ticket auditable?
            'fully_traceable'    => round($untraceableTotal, 2) == 0.0,
            'coverage_pct'       => $total != 0.0 ? round(($traceableTotal / $total) * 100, 1) : 100.0,
            'by_source'          => $byType,
            'untraceable_items'  => $untraceable,
        ];
    }

    // ── Builders ─────────────────────────────────────────────────────────────────────────────────────

    private function garageInvoice(MaintenanceLineItem $line): array
    {
        $invoice = $line->relationLoaded('invoice') ? $line->invoice : $line->invoice()->with('vendor:id,name')->first();

        return $this->doc(
            self::SOURCE_GARAGE_INVOICE,
            reference: $invoice?->invoice_no ?: 'INV-' . $line->maintenance_invoice_id,
            documentId: $line->maintenance_invoice_id,
            party: $invoice?->is_internal ? 'In-House' : $invoice?->vendor?->name,
            photoUrl: $invoice?->receiptPhotoUrl(),
        );
    }

    private function doc(
        string $type,
        ?string $reference = null,
        ?int $documentId = null,
        ?string $party = null,
        ?string $photoUrl = null,
        ?string $detail = null,
    ): array {
        return [
            'type'        => $type,
            'label'       => self::LABELS[$type],
            'traceable'   => true,
            'reference'   => $reference,
            'document_id' => $documentId,
            'party'       => $party,
            'photo_url'   => $photoUrl,
            'detail'      => $detail,
        ];
    }

    private function unsourced(string $why): array
    {
        return [
            'type'        => self::SOURCE_UNSOURCED,
            'label'       => self::LABELS[self::SOURCE_UNSOURCED],
            'traceable'   => false,
            'reference'   => null,
            'document_id' => null,
            'party'       => null,
            'photo_url'   => null,
            'detail'      => $why,
        ];
    }
}
