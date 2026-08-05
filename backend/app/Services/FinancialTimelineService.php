<?php

namespace App\Services;

use App\Models\CostAdjustment;
use App\Models\Maintenance;
use App\Models\MaintenanceInvoice;
use App\Models\MaintenanceRequiredPart;
use App\Models\MaintenanceTask;
use App\Models\PartInvoice;
use App\Models\PartPurchase;
use App\Models\PartRequest;
use App\Models\PartReturn;

/**
 * The financial story of a repair, in the order it happened: diagnosis → required part → request →
 * purchase → invoice → approval → delivery → installation → return → refund → payment → closure.
 *
 * Deliberately built from the TYPED rows rather than from vehicle_log_events. The log is a stream of
 * sentences written for a human reader; this is a structured ledger of events, each carrying its kind,
 * its money, and the document it belongs to — so the UI can group, filter and link them, and so a figure
 * in the timeline is the same figure the audit checks. Reading money out of prose would be exactly the
 * black box the rest of this design removes.
 *
 * Read-only and derived. Every entry already exists as a row somewhere; this only orders and types them.
 *
 * Evidence class: D (derived) — Produces E-financial-timeline. Consumes maintenance_tasks (F/J),
 * maintenance_required_parts (F), part_requests (F), part_purchases (F), part_invoices (F),
 * part_returns (F), cost_adjustments (F), maintenance_invoices (F), maintenances (F).
 */
class FinancialTimelineService
{
    /** Event kinds, grouped by the phase they belong to — the UI colours and filters on these. */
    public const PHASE_DIAGNOSIS   = 'diagnosis';
    public const PHASE_PROCUREMENT = 'procurement';
    public const PHASE_DOCUMENT    = 'document';
    public const PHASE_MONEY       = 'money';
    public const PHASE_CLOSURE     = 'closure';

    /**
     * @return array{events:array, totals:array}
     */
    public function forTicket(Maintenance $ticket): array
    {
        $events = collect()
            ->concat($this->diagnosisEvents($ticket))
            ->concat($this->requirementEvents($ticket))
            ->concat($this->procurementEvents($ticket))
            ->concat($this->supplierInvoiceEvents($ticket))
            ->concat($this->garageInvoiceEvents($ticket))
            ->concat($this->returnEvents($ticket))
            ->concat($this->adjustmentEvents($ticket))
            ->concat($this->closureEvents($ticket))
            ->filter(fn ($e) => $e['at'] !== null)
            // Oldest first: this is a story, and a story is read forwards.
            ->sortBy('at')
            ->values();

        return [
            'events' => $events->all(),
            'totals' => [
                'events'    => $events->count(),
                'documents' => $events->where('phase', self::PHASE_DOCUMENT)->count(),
                'money_out' => round((float) $events->where('signed_amount', '>', 0)->sum('signed_amount'), 2),
                'money_back' => round((float) $events->where('signed_amount', '<', 0)->sum('signed_amount'), 2),
            ],
        ];
    }

    // ── Phases ───────────────────────────────────────────────────────────────────────────────────────

    /** Where every repair starts: somebody said the car had a problem. */
    private function diagnosisEvents(Maintenance $ticket)
    {
        return $ticket->loadMissing('tasks')->tasks->map(fn (MaintenanceTask $t) => $this->event(
            phase: self::PHASE_DIAGNOSIS,
            kind: $t->isIncorrect() ? 'fault_ruled_incorrect' : 'fault_diagnosed',
            at: $t->isIncorrect() ? $t->marked_incorrect_at : $t->created_at,
            title: $t->isIncorrect() ? 'Diagnosis ruled incorrect' : 'Fault diagnosed',
            detail: $t->symptom . ($t->isIncorrect() && $t->incorrect_reason ? ' — ' . $t->incorrect_reason : ''),
            reference: 'FAULT-' . $t->id,
        ));
    }

    /** What the inspector said the repair would need — the first financial commitment in embryo. */
    private function requirementEvents(Maintenance $ticket)
    {
        return MaintenanceRequiredPart::where('maintenance_id', $ticket->id)->get()
            ->map(fn (MaintenanceRequiredPart $rp) => $this->event(
                phase: self::PHASE_DIAGNOSIS,
                kind: 'part_required',
                at: $rp->recorded_at,
                title: 'Part required',
                detail: $rp->part_name . ($rp->quantity > 1 ? ' × ' . (float) $rp->quantity : '')
                    . ($rp->recorded_by_name ? ' · ' . $rp->recorded_by_name : ''),
                reference: 'REQP-' . $rp->id,
            ));
    }

    /** Request → purchase → delivery → installation: the part's physical and contractual journey. */
    private function procurementEvents(Maintenance $ticket)
    {
        $events = collect();

        foreach (PartRequest::where('maintenance_id', $ticket->id)->get() as $r) {
            $events->push($this->event(
                phase: self::PHASE_PROCUREMENT,
                kind: 'part_requested',
                at: $r->requested_at,
                title: 'Part requested',
                detail: $r->part_name . ($r->requested_by_name ? ' · ' . $r->requested_by_name : ''),
                reference: 'REQ-' . $r->id,
            ));
        }

        foreach (PartPurchase::with('sourceVendor:id,name')->where('maintenance_id', $ticket->id)->get() as $p) {
            $from = $p->sourceVendor?->name ?: $p->source_name ?: ($p->purchase_source === PartPurchase::SOURCE_GARAGE ? 'the garage' : 'a supplier');

            $events->push($this->event(
                phase: self::PHASE_PROCUREMENT,
                kind: 'part_purchased',
                at: $p->purchased_at,
                title: 'Part purchased',
                detail: $p->part_name . ' from ' . $from,
                // The buy is a commitment, but it does not reach the ticket's cost until it is FITTED —
                // so it carries no signed amount here and cannot double-count against the install event.
                amount: $p->grossCost(),
                reference: 'BUY-' . $p->id,
            ));

            if ($p->delivered_at) {
                $events->push($this->event(
                    phase: self::PHASE_PROCUREMENT,
                    kind: 'goods_received',
                    at: $p->delivered_at,
                    title: 'Goods received',
                    detail: $p->part_name . ' delivered to the workshop',
                    reference: 'BUY-' . $p->id,
                ));
            }

            if ($p->installed_at) {
                $events->push($this->event(
                    phase: self::PHASE_MONEY,
                    kind: 'part_installed',
                    at: $p->installed_at,
                    title: 'Part installed',
                    detail: $p->part_name . ($p->installed_by_name ? ' · ' . $p->installed_by_name : ''),
                    // Fitting is the moment the part becomes ticket cost.
                    amount: $p->grossCost(),
                    signedAmount: $p->grossCost(),
                    reference: 'BUY-' . $p->id,
                ));
            }
        }

        return $events;
    }

    /** The supplier's paper, and every decision taken on it. */
    private function supplierInvoiceEvents(Maintenance $ticket)
    {
        $events = collect();

        $invoices = PartInvoice::whereHas('purchases', fn ($q) => $q->where('maintenance_id', $ticket->id))
            ->with('purchases')->get()->unique('id');

        foreach ($invoices as $inv) {
            // Only this ticket's share — the document may cover several cars.
            $allocated = round((float) $inv->purchases->where('maintenance_id', $ticket->id)
                ->sum(fn (PartPurchase $p) => $p->grossCost()), 2);
            $ref = $inv->invoice_no ?: 'PINV-' . $inv->id;

            $events->push($this->event(
                phase: self::PHASE_DOCUMENT,
                kind: 'supplier_invoice_recorded',
                at: $inv->recorded_at ?: $inv->created_at,
                title: 'Supplier invoice recorded',
                detail: $inv->supplierLabel() . ' · ' . $ref
                    . ($inv->purchases->count() > $inv->purchases->where('maintenance_id', $ticket->id)->count()
                        ? ' (shared with other tickets — AED ' . number_format($allocated, 2) . ' allocated here)'
                        : ''),
                amount: $allocated,
                reference: $ref,
                documentType: 'supplier-invoice',
                documentId: $inv->id,
            ));

            if ($inv->approved_at) {
                $events->push($this->event(
                    phase: self::PHASE_DOCUMENT,
                    kind: 'invoice_approved',
                    at: $inv->approved_at,
                    title: 'Supplier invoice approved',
                    detail: $ref . ($inv->approved_by_name ? ' · ' . $inv->approved_by_name : ''),
                    reference: $ref,
                    documentType: 'supplier-invoice',
                    documentId: $inv->id,
                ));
            }
            if ($inv->paid_at) {
                $events->push($this->event(
                    phase: self::PHASE_MONEY,
                    kind: 'supplier_paid',
                    at: $inv->paid_at,
                    title: 'Supplier paid',
                    detail: $inv->supplierLabel() . ' · AED ' . number_format((float) $inv->paid_amount, 2)
                        . ($inv->payment_reference ? ' · ' . $inv->payment_reference : ''),
                    amount: (float) $inv->paid_amount,
                    reference: $ref,
                    documentType: 'supplier-invoice',
                    documentId: $inv->id,
                ));
            }
            if ($inv->cancelled_at) {
                $events->push($this->event(
                    phase: self::PHASE_DOCUMENT,
                    kind: 'invoice_cancelled',
                    at: $inv->cancelled_at,
                    title: 'Supplier invoice cancelled',
                    detail: $ref . ' — ' . $inv->cancellation_reason,
                    reference: $ref,
                ));
            }
        }

        return $events;
    }

    /** The garage's paper — labour, and any part the garage itself supplied. */
    private function garageInvoiceEvents(Maintenance $ticket)
    {
        $events = collect();

        foreach ($ticket->loadMissing('invoices.vendor')->invoices as $inv) {
            /** @var MaintenanceInvoice $inv */
            $garage = $inv->is_internal ? 'In-House' : ($inv->vendor?->name ?: 'garage');
            $ref = $inv->invoice_no ?: 'INV-' . $inv->id;

            $events->push($this->event(
                phase: self::PHASE_DOCUMENT,
                kind: 'garage_invoice_recorded',
                at: $inv->recorded_at ?: $inv->created_at,
                title: 'Garage invoice recorded',
                detail: $garage . ' · ' . $ref . ' · parts AED ' . number_format((float) $inv->parts_total, 2)
                    . ' + labour AED ' . number_format((float) $inv->labor_total, 2),
                amount: (float) $inv->amount,
                signedAmount: (float) $inv->labor_total + (float) $inv->parts_total,
                reference: $ref,
                documentType: 'garage-invoice',
                documentId: $inv->id,
            ));

            if ($inv->approved_at) {
                $events->push($this->event(
                    phase: self::PHASE_DOCUMENT,
                    kind: 'invoice_approved',
                    at: $inv->approved_at,
                    title: 'Garage invoice approved',
                    detail: $ref . ($inv->approved_by_name ? ' · ' . $inv->approved_by_name : ''),
                    reference: $ref,
                    documentType: 'garage-invoice',
                    documentId: $inv->id,
                ));
            }
            if ($inv->paid_at) {
                $events->push($this->event(
                    phase: self::PHASE_MONEY,
                    kind: 'garage_paid',
                    at: $inv->paid_at,
                    title: 'Garage paid',
                    detail: $garage . ' · AED ' . number_format((float) $inv->paid_amount, 2),
                    amount: (float) $inv->paid_amount,
                    reference: $ref,
                    documentType: 'garage-invoice',
                    documentId: $inv->id,
                ));
            }
        }

        return $events;
    }

    /** A part going back, and the money coming back with it. */
    private function returnEvents(Maintenance $ticket)
    {
        $events = collect();

        foreach (PartReturn::with('purchase')->where('maintenance_id', $ticket->id)->get() as $r) {
            $part = $r->purchase?->part_name ?: 'Part';

            $events->push($this->event(
                phase: self::PHASE_PROCUREMENT,
                kind: 'part_returned',
                at: $r->returned_at,
                title: 'Part returned',
                detail: $part . ' — ' . $r->reasonLabel()
                    . ($r->reason_note ? ' · ' . $r->reason_note : ''),
                reference: 'RET-' . $r->id,
            ));

            if ($r->isRefunded()) {
                $events->push($this->event(
                    phase: self::PHASE_MONEY,
                    kind: 'refund_received',
                    at: $r->settled_at,
                    title: 'Refund received',
                    detail: $part . ' · AED ' . number_format((float) $r->refund_amount, 2) . ' credited'
                        . ((float) $r->restocking_fee > 0
                            ? ' (AED ' . number_format((float) $r->restocking_fee, 2) . ' restocking fee kept)'
                            : ''),
                    amount: (float) $r->refund_amount,
                    // Money coming BACK reduces the ticket.
                    signedAmount: -1 * (float) $r->refund_amount,
                    reference: 'RET-' . $r->id,
                ));
            } elseif ($r->status === PartReturn::STATUS_REJECTED) {
                $events->push($this->event(
                    phase: self::PHASE_MONEY,
                    kind: 'return_rejected',
                    at: $r->settled_at,
                    title: 'Return refused by the supplier',
                    detail: $part . ' — ' . $r->rejection_reason . ' · the cost stays with us',
                    reference: 'RET-' . $r->id,
                ));
            }
        }

        return $events;
    }

    /** Corrections — always explained, always signed for. */
    private function adjustmentEvents(Maintenance $ticket)
    {
        return CostAdjustment::where('maintenance_id', $ticket->id)->get()
            ->map(fn (CostAdjustment $a) => $this->event(
                phase: self::PHASE_MONEY,
                kind: 'adjustment',
                at: $a->approved_at,
                title: ($a->signedAmount() < 0 ? 'Credit adjustment' : 'Charge adjustment') . ' · ' . $a->applies_to,
                detail: $a->reasonLabel() . ' — ' . $a->reason_note . ' · approved by ' . $a->approved_by_name,
                amount: abs($a->signedAmount()),
                signedAmount: $a->line_item_id ? $a->signedAmount() : 0.0, // reversed → no longer money
                reference: $a->reference ?: 'ADJ-' . $a->id,
            ));
    }

    /** The end of the story. */
    private function closureEvents(Maintenance $ticket)
    {
        return collect([
            $this->event(
                phase: self::PHASE_CLOSURE,
                kind: 'ticket_closed',
                at: $ticket->wf_closed_at,
                title: 'Ticket closed',
                detail: 'Final cost AED ' . number_format((float) $ticket->cost, 2),
                amount: (float) $ticket->cost,
                reference: 'TICKET-' . $ticket->id,
            ),
        ]);
    }

    // ── Builder ──────────────────────────────────────────────────────────────────────────────────────

    /**
     * `amount` is what the event was worth; `signed_amount` is what it did to the ticket's total, and is
     * zero for events that record a fact without moving money (a purchase before it is fitted, an
     * approval, a delivery). Keeping them apart is what lets the timeline show the size of an event
     * without its total pretending to be the ticket cost.
     */
    private function event(
        string $phase,
        string $kind,
        $at,
        string $title,
        ?string $detail = null,
        float $amount = 0.0,
        float $signedAmount = 0.0,
        ?string $reference = null,
        ?string $documentType = null,
        ?int $documentId = null,
    ): array {
        return [
            'phase'         => $phase,
            'kind'          => $kind,
            'at'            => $at ? (is_string($at) ? $at : $at->toIso8601String()) : null,
            'title'         => $title,
            'detail'        => $detail,
            'amount'        => round($amount, 2),
            'signed_amount' => round($signedAmount, 2),
            'reference'     => $reference,
            'document_type' => $documentType,
            'document_id'   => $documentId,
        ];
    }
}
