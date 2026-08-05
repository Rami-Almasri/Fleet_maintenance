<?php

namespace App\Services;

use App\Models\MaintenanceInvoice;
use App\Models\PartInvoice;
use App\Models\PartPurchase;
use App\Models\PartReturn;
use App\Models\SupplierPayment;
use App\Support\FinancialDocumentStatus as Status;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Procurement reporting — what we owe, to whom, for how long, and which suppliers are actually worth
 * buying from.
 *
 * Every figure here rests on the structured origin built earlier, which is what makes the reports
 * trustworthy rather than indicative: spend is grouped by the DOCUMENT that proves it, so a number can
 * always be opened and checked. Where a figure cannot be proved, the report says so beside it instead of
 * blending documented and undocumented money into one total.
 *
 * Evidence class: D (derived) — Produces E-procurement-report. Consumes part_invoices (F),
 * maintenance_invoices (F), payment_allocations (F), part_purchases (F), part_returns (F).
 */
class ProcurementReportService
{
    /** Aging buckets, in days. The last is open-ended. */
    public const AGING_BUCKETS = [30, 60, 90];

    public function __construct(private CostVerificationService $verification) {}

    /**
     * Accounts payable, aged from the invoice date — the report finance opens first.
     *
     * Only COMMITTED documents count: a draft is not an obligation, and including it would inflate what
     * we owe with money nobody has agreed to.
     */
    public function payables(): array
    {
        $rows = [];

        foreach ($this->committedInvoices() as $invoice) {
            $outstanding = $invoice->outstandingAmount();
            if ($outstanding <= 0.01) {
                continue;
            }

            $isSupplier = $invoice instanceof PartInvoice;
            $date = $invoice->documentDate();

            // Aged against what we AGREED to pay, not merely how old the paper is. A 45-day-old bill on
            // net-60 terms is not late; a 20-day-old bill due on receipt is. Ageing both from the invoice
            // date treats every supplier identically and sends people chasing the wrong ones.
            $ageDays  = $date ? (int) Carbon::parse($date)->startOfDay()->diffInDays(Carbon::now()->startOfDay(), false) : 0;
            $overdue  = $invoice->daysOverdue();
            $dueDate  = $invoice->dueDate();

            $rows[] = [
                'document_type' => $isSupplier ? 'supplier_invoice' : 'garage_invoice',
                'document_id'   => $invoice->id,
                'invoice_no'    => $invoice->invoice_no,
                'payee'         => $isSupplier
                    ? $invoice->supplierLabel()
                    : ($invoice->is_internal ? 'In-House' : ($invoice->vendor?->name ?: 'Unassigned garage')),
                'vendor_id'     => $invoice->vendor_id,
                'date'          => $date,
                'due_date'      => $dueDate?->toDateString(),
                'terms_days'    => $invoice->terms_days,
                'total'         => $invoice->documentTotal(),
                'paid'          => round((float) $invoice->paid_amount, 2),
                'outstanding'   => $outstanding,
                'age_days'      => max(0, $ageDays),
                'days_overdue'  => $overdue,
                'overdue'       => $invoice->isOverdue(),
                // The bucket is the OVERDUE age, so "0-30" means up to a month late — not a month old.
                'bucket'        => $this->bucketFor(max(0, $overdue ?? 0)),
                'status'        => $invoice->documentStatus(),
            ];
        }

        // Most overdue first — the order you actually work the list in.
        usort($rows, fn ($a, $b) => ($b['days_overdue'] ?? 0) <=> ($a['days_overdue'] ?? 0));

        // By payee, because you pay a supplier, not an invoice.
        $byPayee = collect($rows)->groupBy('payee')->map(fn ($g, $payee) => [
            'payee'         => $payee,
            'vendor_id'     => $g->first()['vendor_id'],
            'invoices'      => $g->count(),
            'outstanding'   => round((float) $g->sum('outstanding'), 2),
            'overdue'       => round((float) $g->where('overdue', true)->sum('outstanding'), 2),
            'oldest_days'   => (int) $g->max('age_days'),
            'most_overdue'  => (int) max(0, (int) $g->max('days_overdue')),
            'terms_days'    => $g->first()['terms_days'],
            'buckets'       => $this->bucketTotals($g->where('overdue', true)),
        ])->sortByDesc('overdue')->values()->all();

        $overdueRows = collect($rows)->where('overdue', true);

        return [
            'total_outstanding' => round((float) collect($rows)->sum('outstanding'), 2),
            'invoice_count'     => count($rows),
            // What is actually LATE, as distinct from what is merely owed. Only one of those is a problem.
            'overdue_total'     => round((float) $overdueRows->sum('outstanding'), 2),
            'overdue_count'     => $overdueRows->count(),
            'not_yet_due_total' => round((float) collect($rows)->where('overdue', false)->sum('outstanding'), 2),
            'buckets'           => $this->bucketTotals($overdueRows),
            'by_payee'          => $byPayee,
            'invoices'          => $rows,
        ];
    }

    /**
     * Supplier performance — spend, documentation coverage, delivery speed and return rate, per supplier.
     *
     * Return rate and lead time are the two figures that tell you whether a cheap supplier is actually
     * cheap. A supplier whose parts come back is not a bargain, and neither is one that takes three weeks
     * while a car sits off the road.
     */
    public function supplierPerformance(?string $from = null, ?string $to = null): array
    {
        $purchases = PartPurchase::with(['sourceVendor:id,name', 'returns', 'invoice'])
            ->where('purchase_source', PartPurchase::SOURCE_SUPPLIER)
            ->when($from, fn ($q) => $q->whereDate('purchased_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('purchased_at', '<=', $to))
            ->get();

        return $purchases
            ->groupBy(fn (PartPurchase $p) => $p->sourceVendor?->name ?: ($p->source_name ?: 'Unnamed supplier'))
            ->map(function ($group, $supplier) {
                $spend = round((float) $group->sum(fn (PartPurchase $p) => $p->grossCost()), 2);
                $documented = round((float) $group->filter(fn (PartPurchase $p) => $p->part_invoice_id)
                    ->sum(fn (PartPurchase $p) => $p->grossCost()), 2);

                // Lead time only means anything where both ends were actually stamped.
                $delivered = $group->filter(fn (PartPurchase $p) => $p->purchased_at && $p->delivered_at);
                $leadDays = $delivered->map(fn (PartPurchase $p) => $p->purchased_at->diffInDays($p->delivered_at));

                $returned = $group->filter(fn (PartPurchase $p) => $p->returns
                    ->where('status', '!=', PartReturn::STATUS_REJECTED)->isNotEmpty());

                return [
                    'supplier'          => $supplier,
                    'purchases'         => $group->count(),
                    'spend'             => $spend,
                    'refunded'          => round((float) $group->sum(fn (PartPurchase $p) => $p->refundedTotal()), 2),
                    'net_spend'         => round((float) $group->sum(fn (PartPurchase $p) => $p->netCost()), 2),
                    'documented'        => $documented,
                    'undocumented'      => round($spend - $documented, 2),
                    'documentation_pct' => $spend > 0 ? round(($documented / $spend) * 100, 1) : 100.0,
                    // Null rather than 0 when nothing was ever stamped — an unmeasured figure must not
                    // read as an excellent one.
                    'avg_lead_days'     => $leadDays->isNotEmpty() ? round($leadDays->avg(), 1) : null,
                    'measured_deliveries' => $delivered->count(),
                    'returned_count'    => $returned->count(),
                    'return_rate_pct'   => $group->count() > 0
                        ? round(($returned->count() / $group->count()) * 100, 1) : 0.0,
                ];
            })
            ->sortByDesc('spend')
            ->values()
            ->all();
    }

    /**
     * Payments made in a period, grouped by payee and method — the reconciliation view.
     */
    public function paymentsMade(?string $from = null, ?string $to = null): array
    {
        $payments = SupplierPayment::with(['vendor:id,name', 'allocations'])
            ->where('status', SupplierPayment::STATUS_RECORDED)
            ->when($from, fn ($q) => $q->whereDate('payment_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('payment_date', '<=', $to))
            ->orderByDesc('payment_date')
            ->get();

        return [
            'total_paid'   => round((float) $payments->sum('amount'), 2),
            'payment_count' => $payments->count(),
            // Money sent but not yet pointed at a bill — a credit sitting with the supplier.
            'unallocated'  => round((float) $payments->sum(fn (SupplierPayment $p) => $p->unallocatedTotal()), 2),
            'by_method'    => $payments->groupBy('method')->map(fn ($g, $m) => [
                'method' => $m,
                'label'  => SupplierPayment::METHOD_LABELS[$m] ?? $m,
                'count'  => $g->count(),
                'amount' => round((float) $g->sum('amount'), 2),
            ])->sortByDesc('amount')->values()->all(),
            'by_payee'     => $payments->groupBy(fn (SupplierPayment $p) => $p->payeeLabel())
                ->map(fn ($g, $payee) => [
                    'payee'  => $payee,
                    'count'  => $g->count(),
                    'amount' => round((float) $g->sum('amount'), 2),
                ])->sortByDesc('amount')->values()->all(),
            'payments'     => $payments->map(fn (SupplierPayment $p) => [
                'id'          => $p->id,
                'date'        => optional($p->payment_date)->toDateString(),
                'payee'       => $p->payeeLabel(),
                'amount'      => (float) $p->amount,
                'method'      => $p->method,
                'method_label' => $p->methodLabel(),
                'reference'   => $p->reference,
                'allocated'   => $p->allocatedTotal(),
                'unallocated' => $p->unallocatedTotal(),
                'photo_url'   => $p->photoUrl(),
                'recorded_by' => $p->recorded_by_name,
            ])->all(),
        ];
    }

    /**
     * The headline procurement dashboard: committed, paid, owed, and how much of it we can prove.
     */
    public function overview(?string $from = null, ?string $to = null): array
    {
        $payables = $this->payables();
        $payments = $this->paymentsMade($from, $to);
        $coverage = $this->verification->fleetSummary();

        $committed = round((float) $this->committedInvoices()
            ->sum(fn ($i) => $i->documentTotal()), 2);

        return [
            'committed'         => $committed,
            'paid'              => $payments['total_paid'],
            'outstanding'       => $payables['total_outstanding'],
            'unallocated_payments' => $payments['unallocated'],
            'aging'             => $payables['buckets'],
            'top_payees'        => array_slice($payables['by_payee'], 0, 5),
            // The honesty figure travels WITH the money figures, so nobody reads a spend report
            // without knowing how much of it is provable.
            'traceability'      => [
                'verified_cost'   => $coverage['verified_cost'],
                'legacy_cost'     => $coverage['legacy_cost'],
                'unverified_cost' => $coverage['unverified_cost'],
                'coverage_pct'    => $coverage['coverage_pct'],
            ],
        ];
    }

    // ── Internals ────────────────────────────────────────────────────────────────────────────────────

    /** Every bill the business has actually committed to — both kinds, as one list. */
    private function committedInvoices()
    {
        $supplier = PartInvoice::with(['vendor:id,name', 'purchases.returns'])->get()
            ->filter(fn (PartInvoice $i) => Status::isCommitted($i->documentStatus()));

        $garage = MaintenanceInvoice::with('vendor:id,name')->get()
            ->filter(fn (MaintenanceInvoice $i) => Status::isCommitted($i->documentStatus()));

        return $supplier->concat($garage);
    }

    /** The open-ended bucket's key ("90+"). A constant can't be passed to end() by reference. */
    private function overflowBucket(): string
    {
        $buckets = self::AGING_BUCKETS;

        return end($buckets) . '+';
    }

    private function bucketFor(int $ageDays): string
    {
        foreach (self::AGING_BUCKETS as $limit) {
            if ($ageDays <= $limit) {
                return '0-' . $limit;
            }
        }

        return $this->overflowBucket();
    }

    private function bucketTotals($rows): array
    {
        $totals = [];
        foreach (self::AGING_BUCKETS as $limit) {
            $totals['0-' . $limit] = 0.0;
        }
        $totals[$this->overflowBucket()] = 0.0;

        foreach ($rows as $row) {
            $totals[$row['bucket']] = round($totals[$row['bucket']] + $row['outstanding'], 2);
        }

        return $totals;
    }
}
