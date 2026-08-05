<?php

namespace App\Services;

use App\Models\CostAdjustment;
use App\Models\Maintenance;
use App\Models\PartInvoice;
use App\Models\PartPurchase;
use App\Models\PartReturn;
use App\Support\FinancialDocumentStatus as Status;

/**
 * "Is this ticket's money finished, and if not, what exactly is missing?"
 *
 * This is the service that turns the audit from a report into a WORKFLOW. Knowing that 100% of ticket cost
 * is undocumented is only useful if the system then refuses to let the next ticket end that way — so this
 * names, in order, the specific actions still owed on a ticket, and {@see MaintenanceWorkflowService} uses
 * it to gate closure.
 *
 * Two categories, and the difference matters:
 *
 *   BLOCKERS — money that cannot be explained. A ticket may not close on one. Each carries the exact next
 *              action and where to do it, because "incomplete" without "do this" is just an obstacle.
 *   WARNINGS — money that is explained but not finished (an approved bill nobody has paid yet). Worth
 *              showing, never worth blocking on: payment terms are the supplier's business, not the
 *              repair's, and holding a car's ticket open over them would teach people to work around it.
 *
 * There is always a legitimate way past a blocker, and it is never "ignore it": record the invoice, or
 * record a {@see CostAdjustment} that explains the figure and puts a name against it. Both leave a
 * document behind, which is the whole point.
 *
 * Evidence class: D (derived) — Produces E-financial-completeness. Consumes part_purchases (F),
 * part_invoices (F), part_returns (F), cost_adjustments (F), maintenance_line_items (F).
 */
class FinancialCompletenessService
{
    public function __construct(private CostSourceResolver $sources) {}

    /**
     * Everything still owed on this ticket's money.
     *
     * @return array{complete:bool, blockers:array, warnings:array, audit:array}
     */
    public function check(Maintenance $ticket): array
    {
        $blockers = [];
        $warnings = [];

        $purchases = PartPurchase::with(['invoice', 'returns', 'sourceVendor:id,name'])
            ->where('maintenance_id', $ticket->id)->get();

        // ── 1. A supplier part bought with no invoice: spend with nothing behind it. ─────────────────
        foreach ($purchases as $p) {
            if ($p->purchase_source === PartPurchase::SOURCE_SUPPLIER && ! $p->part_invoice_id) {
                $blockers[] = $this->item(
                    code: 'supplier_invoice_missing',
                    subject: $p->part_name,
                    amount: $p->grossCost(),
                    message: "“{$p->part_name}” was bought from "
                        . ($p->sourceVendor?->name ?: $p->source_name ?: 'a supplier')
                        . ' but no supplier invoice has been recorded.',
                    action: 'Record the supplier invoice and attach this part to it.',
                    route: '/part-invoices',
                    context: ['purchase_id' => $p->id],
                );
            }
        }

        // ── 2. A supplier bill that nobody has accepted yet. ────────────────────────────────────────
        $invoices = PartInvoice::whereHas('purchases', fn ($q) => $q->where('maintenance_id', $ticket->id))
            ->with('purchases.returns')->get()->unique('id');

        foreach ($invoices as $inv) {
            $status = $inv->documentStatus();

            if (in_array($status, [Status::DRAFT, Status::PENDING], true)) {
                $blockers[] = $this->item(
                    code: 'supplier_invoice_unapproved',
                    subject: $inv->invoice_no ?: 'Supplier invoice #' . $inv->id,
                    amount: $inv->documentTotal(),
                    message: 'Supplier invoice ' . ($inv->invoice_no ?: '#' . $inv->id) . ' from '
                        . $inv->supplierLabel() . ' is still ' . strtolower(Status::label($status)) . '.',
                    action: 'Approve the invoice so it becomes an accepted obligation.',
                    route: '/part-invoices',
                    context: ['document_type' => 'supplier-invoice', 'document_id' => $inv->id],
                );
            } elseif ($inv->outstandingAmount() > 0.01) {
                // Explained, just not settled. Payment terms are not the repair's business.
                $warnings[] = $this->item(
                    code: 'supplier_invoice_unpaid',
                    subject: $inv->invoice_no ?: 'Supplier invoice #' . $inv->id,
                    amount: $inv->outstandingAmount(),
                    message: $inv->supplierLabel() . ' is still owed AED '
                        . number_format($inv->outstandingAmount(), 2) . '.',
                    action: 'Record the payment when it is made.',
                    route: '/part-invoices',
                    context: ['document_type' => 'supplier-invoice', 'document_id' => $inv->id],
                );
            }
        }

        // ── 3. A garage bill nobody has accepted. ───────────────────────────────────────────────────
        foreach ($ticket->loadMissing('invoices.vendor')->invoices as $inv) {
            $status = $inv->documentStatus();
            $garage = $inv->is_internal ? 'In-House' : ($inv->vendor?->name ?: 'the garage');

            if (in_array($status, [Status::DRAFT, Status::PENDING], true)) {
                $blockers[] = $this->item(
                    code: 'garage_invoice_unapproved',
                    subject: $inv->invoice_no ?: 'Garage invoice #' . $inv->id,
                    amount: $inv->documentTotal(),
                    message: "The bill from {$garage} is still " . strtolower(Status::label($status)) . '.',
                    action: 'Approve the garage invoice.',
                    route: null,
                    context: ['document_type' => 'garage-invoice', 'document_id' => $inv->id],
                );
            } elseif ($inv->outstandingAmount() > 0.01) {
                $warnings[] = $this->item(
                    code: 'garage_invoice_unpaid',
                    subject: $inv->invoice_no ?: 'Garage invoice #' . $inv->id,
                    amount: $inv->outstandingAmount(),
                    message: "{$garage} is still owed AED " . number_format($inv->outstandingAmount(), 2) . '.',
                    action: 'Record the payment when it is made.',
                    route: null,
                    context: ['document_type' => 'garage-invoice', 'document_id' => $inv->id],
                );
            }
        }

        // ── 4. A return in limbo: the part went back but the money never resolved. ──────────────────
        $openReturns = PartReturn::where('maintenance_id', $ticket->id)
            ->whereIn('status', [PartReturn::STATUS_REQUESTED, PartReturn::STATUS_SENT])
            ->with('purchase')->get();

        foreach ($openReturns as $r) {
            $blockers[] = $this->item(
                code: 'return_unsettled',
                subject: $r->purchase?->part_name ?: 'Returned part',
                amount: (float) $r->refund_amount,
                message: '“' . ($r->purchase?->part_name ?: 'A part') . '” was returned ('
                    . $r->reasonLabel() . ') but the refund has not been settled — the ticket is still '
                    . 'carrying its full cost.',
                action: 'Record the refund, or mark the return rejected if the supplier refused it.',
                route: '/parts',
                context: ['part_return_id' => $r->id],
            );
        }

        // ── 5. Anything else the audit cannot explain (a hand-typed lump sum, an orphan line). ──────
        $audit = $this->sources->auditTicket($ticket);

        foreach ($audit['untraceable_items'] as $u) {
            // Cases 1 and 4 already name their own cause with a better action — don't say it twice.
            if ($u['line_item_id'] !== null && $this->alreadyNamed($u, $blockers)) {
                continue;
            }

            $blockers[] = $this->item(
                code: $u['kind'] === 'lump_sum' ? 'lump_sum_cost' : 'line_unsourced',
                subject: $u['description'],
                amount: $u['amount'],
                message: $u['description'] . ' (AED ' . number_format($u['amount'], 2) . ') — ' . $u['why'],
                action: $u['kind'] === 'lump_sum'
                    ? 'Itemise this ticket against its invoices, or record an adjustment that explains the figure.'
                    : 'Attach this line to the invoice it came from, or record an adjustment explaining it.',
                route: null,
                context: ['line_item_id' => $u['line_item_id']],
            );
        }

        return [
            'complete' => empty($blockers),
            'blockers' => $blockers,
            'warnings' => $warnings,
            'audit'    => $audit,
        ];
    }

    /**
     * The one-line refusal used when a ticket tries to close with money it cannot explain. Names the first
     * blocker and how many others there are, so the message is actionable rather than merely negative.
     */
    public function refusalMessage(array $check): string
    {
        $first = $check['blockers'][0] ?? null;
        if (! $first) {
            return 'This ticket has money that cannot be traced to a document.';
        }

        $more = count($check['blockers']) - 1;

        return $first['message'] . ' ' . $first['action']
            . ($more > 0 ? " ({$more} other financial " . ($more === 1 ? 'item' : 'items') . ' still open.)' : '');
    }

    // ── Internals ────────────────────────────────────────────────────────────────────────────────────

    /**
     * Has a purchase-level blocker already accounted for this untraceable line? A part bought without an
     * invoice produces BOTH a purchase blocker and an unsourced line; reporting both would double-count
     * the money and give the reader two tasks where there is one.
     */
    private function alreadyNamed(array $untraceable, array $blockers): bool
    {
        return collect($blockers)
            ->whereIn('code', ['supplier_invoice_missing', 'return_unsettled'])
            ->contains(fn ($b) => abs($b['amount'] - $untraceable['amount']) < 0.01
                && str_contains(mb_strtolower($b['subject']), mb_strtolower(trim((string) $untraceable['description']))));
    }

    private function item(
        string $code,
        string $subject,
        float $amount,
        string $message,
        string $action,
        ?string $route,
        array $context = [],
    ): array {
        return [
            'code'    => $code,
            'subject' => $subject,
            'amount'  => round($amount, 2),
            'message' => $message,
            'action'  => $action,
            'route'   => $route,
            'context' => $context,
        ];
    }
}
