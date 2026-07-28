<?php

namespace App\Services;

use App\Models\PartRequest;
use App\Models\PartRfq;
use App\Models\RfqLine;
use App\Models\SupplierQuote;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Owns the RFQ sourcing lifecycle (Phase 2). It is the SINGLE writer of the three procurement tables —
 * part_rfqs, rfq_lines, supplier_quotes — and nothing else.
 *
 * SCOPE (P2-2): the RFQ → Quotes half only. It does NOT create purchase orders, does NOT touch
 * part_purchases, does NOT call PartWorkflowService, and does NOT change part_request status or the
 * maintenance workflow. Award + PO issuance (which delegate the part_purchases write back to
 * PartWorkflowService, the sole owner) are a later step (P2-3). Reading a part_request's status here is
 * a precondition check only — never a write.
 */
class ProcurementService
{
    /**
     * Open an RFQ over one or more APPROVED part requests: a header + one line per requirement. Writes
     * only part_rfqs + rfq_lines; the sourced part_requests are referenced, never modified.
     *
     * @param int[] $partRequestIds
     * @param array{needed_by_date?:?string, notes?:?string} $opts
     */
    public function openRfq(array $partRequestIds, User $actor, array $opts = []): PartRfq
    {
        $requests = PartRequest::whereIn('id', array_values(array_unique($partRequestIds)))->get();

        if ($requests->isEmpty()) {
            abort(422, 'Select at least one part request to source.');
        }
        foreach ($requests as $request) {
            if ($request->status !== PartRequest::STATUS_APPROVED) {
                abort(422, "Part request #{$request->id} must be approved before it can be sourced.");
            }
        }

        return DB::transaction(function () use ($requests, $actor, $opts) {
            $rfq = PartRfq::create([
                'status'         => PartRfq::STATUS_OPEN,
                'needed_by_date' => $opts['needed_by_date'] ?? null,
                'notes'          => $opts['notes'] ?? null,
                'opened_by'      => $actor->id,
                'opened_by_name' => $actor->name ?: $actor->email,
                'opened_at'      => Carbon::now(),
            ]);

            foreach ($requests as $request) {
                RfqLine::create([
                    'part_rfq_id'     => $rfq->id,
                    'part_request_id' => $request->id,
                    'quantity'        => $request->quantity ?: 1,
                ]);
            }

            return $rfq->fresh('lines');
        });
    }

    /**
     * Record a supplier's bid on one RFQ line — only while the RFQ is still open. Writes one
     * supplier_quotes row (status = submitted). No award, no selection here.
     *
     * @param array{unit_price:float|int|string, quantity?:mixed, currency?:string, lead_time_days?:?int, expected_delivery_date?:?string, notes?:?string} $data
     */
    public function recordQuote(RfqLine $line, Vendor $supplier, array $data, User $actor): SupplierQuote
    {
        $rfq = $line->rfq;
        if (! in_array($rfq->status, [PartRfq::STATUS_OPEN, PartRfq::STATUS_PARTIALLY_AWARDED], true)) {
            abort(409, 'This RFQ is no longer open for quotes.');
        }

        return SupplierQuote::create([
            'rfq_line_id'            => $line->id,
            'vendor_id'              => $supplier->id,
            'unit_price'             => $data['unit_price'],
            'quantity'               => $data['quantity'] ?? $line->quantity ?? 1,
            'currency'               => $data['currency'] ?? 'AED',
            'lead_time_days'         => $data['lead_time_days'] ?? null,
            'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
            'status'                 => SupplierQuote::STATUS_SUBMITTED,
            'notes'                  => $data['notes'] ?? null,
            'submitted_by'           => $actor->id,
            'submitted_by_name'      => $actor->name ?: $actor->email,
            'submitted_at'           => Carbon::now(),
        ]);
    }
}
