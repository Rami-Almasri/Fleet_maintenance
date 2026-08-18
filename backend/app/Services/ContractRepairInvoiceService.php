<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Maintenance;
use App\Models\MaintenanceInvoice;
use App\Models\PartInvoice;
use App\Models\PartPurchase;

/**
 * EVERY BILL RAISED AGAINST ONE CONTRACT, IN ONE PLACE.
 *
 * A workshop visit is billed by more than one party. The garage that did the work hands us its invoice;
 * the supplier that sold the parts hands us a different one; a second garage on the same visit hands us
 * a third. Each of those was already recorded — but each only ever answered to its own ticket, so the
 * contract that paid for the visit could show the dates, the faults and a total, and could not show the
 * paper behind any of it. Anyone asking the plain question "what were we billed for this contract?" had
 * to open every ticket in turn and add it up by hand.
 *
 * This reads both kinds of bill back to the contract:
 *
 *     garage   →  maintenance_invoices   (what the repair cost)
 *     supplier →  part_invoices          (what the parts cost)
 *
 * It is READ-ONLY and derives nothing new. Both sides already carry their own totals; this only finds
 * them and puts them side by side, so the number on the contract page is the sum of documents a person
 * can open rather than a figure with nothing behind it.
 *
 * HOW A BILL IS TIED TO A CONTRACT — declared, because it is the whole trick:
 *
 *   maintenance invoice → its ticket → the ticket's contract. A ticket names a contract two ways:
 *     `contract_id` (the type-U maintenance contract the visit opened) and `linked_contract_id` (the
 *     rental contract the visit happened under). Both count: a repair billed during a rental belongs
 *     to that rental as much as to the workshop contract.
 *
 *   part invoice → its purchases → their ticket → the ticket's contract. A supplier bill can cover
 *     parts for several cars at once, so it is included when ANY of its parts belong to this
 *     contract's tickets, and it reports how much of it does.
 *
 * A supplier invoice covering three cars is not three-quarters this contract's problem; showing its
 * full total on the contract would overstate what this contract was billed. So it carries BOTH figures
 * — the invoice's own total, and the share of it that is this contract's parts — and the contract total
 * sums the share. {@see PartInvoice::VARIANCE_TOLERANCE} for the money discipline on the document itself.
 *
 * Evidence class: D (Derived) — every figure traces to a stored invoice; nothing here is a judgement.
 * Consumes: maintenance_invoices, part_invoices, part_purchases, maintenances.
 * Produces: the contract page's Repair invoices panel.
 */
class ContractRepairInvoiceService
{
    /**
     * @return array{
     *   tickets: array<int>,
     *   garage_invoices: array<int,array>,
     *   supplier_invoices: array<int,array>,
     *   totals: array{garage: float, supplier: float, all: float, currency: string}
     * }
     */
    public function forContract(Contract $contract): array
    {
        $currency = strtoupper((string) config('parts_intelligence.base_currency', 'AED'));

        // The visits billed to this contract — as the workshop contract it opened, and as the rental
        // contract a repair happened under. A car can be both, so the ids are merged, not chosen between.
        $ticketIds = Maintenance::query()
            ->where('contract_id', $contract->id)
            ->orWhere('linked_contract_id', $contract->id)
            ->pluck('id')
            ->all();

        if (! $ticketIds) {
            return [
                'tickets'           => [],
                'garage_invoices'   => [],
                'supplier_invoices' => [],
                'totals'            => ['garage' => 0.0, 'supplier' => 0.0, 'all' => 0.0, 'currency' => $currency],
            ];
        }

        $garage   = $this->garageInvoices($ticketIds);
        $supplier = $this->supplierInvoices($ticketIds);

        $garageTotal   = round(array_sum(array_column($garage, 'amount')), 2);
        $supplierTotal = round(array_sum(array_column($supplier, 'contract_share')), 2);

        return [
            'tickets'           => $ticketIds,
            'garage_invoices'   => $garage,
            'supplier_invoices' => $supplier,
            'totals' => [
                'garage'   => $garageTotal,
                'supplier' => $supplierTotal,
                'all'      => round($garageTotal + $supplierTotal, 2),
                'currency' => $currency,
            ],
        ];
    }

    /** The garage bills for these visits — newest first, each naming its garage and what it covered. */
    private function garageInvoices(array $ticketIds): array
    {
        return MaintenanceInvoice::query()
            ->whereIn('maintenance_id', $ticketIds)
            ->with(['vendor:id,name', 'tasks:id,maintenance_invoice_id,symptom'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (MaintenanceInvoice $inv) => [
                'id'             => $inv->id,
                'kind'           => 'garage',
                'maintenance_id' => $inv->maintenance_id,
                'invoice_no'     => $inv->invoice_no,
                // An in-house bill has no garage by design — say so rather than showing a blank.
                'is_internal'    => (bool) $inv->is_internal,
                'vendor_id'      => $inv->vendor_id,
                'vendor_name'    => $inv->vendor?->name,
                'amount'         => (float) $inv->amount,
                'parts_total'    => (float) $inv->parts_total,
                'labor_total'    => (float) $inv->labor_total,
                'receipt_total'  => $inv->receipt_total !== null ? (float) $inv->receipt_total : null,
                'reconciliation_status' => $inv->reconciliation_status,
                'recorded_at'    => optional($inv->recorded_at)->toDateTimeString(),
                // What this bill was for, in the words of the faults it covered.
                'covers'         => $inv->tasks->pluck('symptom')->filter()->values()->all(),
            ])
            ->all();
    }

    /**
     * The supplier parts bills touching these visits. A supplier invoice is shared across cars, so each
     * one reports its own total AND the share of it that belongs to this contract — the share is what
     * the contract total counts, because billing this contract for another car's parts would be wrong.
     */
    private function supplierInvoices(array $ticketIds): array
    {
        $invoiceIds = PartPurchase::query()
            ->whereIn('maintenance_id', $ticketIds)
            ->whereNotNull('part_invoice_id')
            ->pluck('part_invoice_id')
            ->unique()
            ->values()
            ->all();

        if (! $invoiceIds) {
            return [];
        }

        return PartInvoice::query()
            ->whereIn('id', $invoiceIds)
            ->with(['vendor:id,name', 'purchases:id,part_invoice_id,maintenance_id,part_name,purchase_price,quantity'])
            ->orderByDesc('id')
            ->get()
            ->map(function (PartInvoice $inv) use ($ticketIds) {
                $mine = $inv->purchases->whereIn('maintenance_id', $ticketIds);

                return [
                    'id'            => $inv->id,
                    'kind'          => 'supplier',
                    'invoice_no'    => $inv->invoice_no,
                    'invoice_date'  => optional($inv->invoice_date)->toDateString(),
                    'vendor_id'     => $inv->vendor_id,
                    'vendor_name'   => $inv->supplierLabel(),
                    'currency'      => $inv->currency,
                    'total_amount'  => (float) $inv->total_amount,
                    // What of it is this contract's — and whether the rest belongs to other cars.
                    'contract_share'=> round((float) $mine->sum(fn (PartPurchase $p) => $p->grossCost()), 2),
                    'is_shared'     => $mine->count() !== $inv->purchases->count(),
                    'part_count'    => $mine->count(),
                    'parts'         => $mine->pluck('part_name')->filter()->values()->all(),
                    'recorded_at'   => optional($inv->recorded_at)->toDateTimeString(),
                ];
            })
            ->all();
    }
}
