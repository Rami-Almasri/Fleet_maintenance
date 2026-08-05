<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\PartPurchase;
use App\Models\PartReturn;
use App\Services\PartReturnService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sending a part back. A purchase is never deleted — a return is logged beside it and, once the money
 * actually comes back, credits the ticket through a negative line item ({@see PartReturnService}).
 *
 * The lifecycle is deliberately explicit about WHEN the money moves: requested → sent are not money yet
 * and change no total; only `refund` credits, and `reject` leaves the cost with us for good.
 */
class PartReturnController extends Controller
{
    public function __construct(private PartReturnService $returns) {}

    /** The returns ledger — by vehicle, ticket, status or reason (what a supplier keeps getting wrong). */
    public function index(Request $request)
    {
        return $this->run(function () use ($request) {
            $q = PartReturn::query()
                ->with(['purchase:id,part_name,part_number,purchase_source,source_vendor_id,purchase_price,quantity', 'purchase.sourceVendor:id,name'])
                ->latest('id');

            foreach (['vehicle_id', 'maintenance_id', 'part_purchase_id', 'status', 'reason_code'] as $filter) {
                if ($value = $request->query($filter)) {
                    $q->where($filter, $value);
                }
            }

            $rows = $q->paginate(min((int) $request->query('per_page', 50), 200));

            return ResponseHelper::SuccessResponse([
                'returns' => $rows->getCollection()->map(fn (PartReturn $r) => $this->present($r))->all(),
                'meta'    => ['total' => $rows->total(), 'per_page' => $rows->perPage(), 'current_page' => $rows->currentPage()],
            ], 'Part returns retrieved');
        });
    }

    /**
     * Log a return against a purchase. `status` lets the counter record a same-day refund in one step;
     * left out, the return starts as `requested` and the ticket keeps the full cost until it settles.
     */
    public function store(Request $request, PartPurchase $partPurchase)
    {
        return $this->run(function () use ($request, $partPurchase) {
            $data = $request->validate([
                'quantity'       => ['nullable', 'numeric', 'min:0.01'],
                'reason_code'    => ['required', Rule::in(PartReturn::REASON_CODES)],
                'reason_note'    => ['nullable', 'string', 'max:2000'],
                'refund_amount'  => ['nullable', 'numeric', 'min:0'],
                'restocking_fee' => ['nullable', 'numeric', 'min:0'],
                'status'         => ['nullable', Rule::in([PartReturn::STATUS_REQUESTED, PartReturn::STATUS_SENT, PartReturn::STATUS_REFUNDED])],
            ]);

            $return = $this->returns->create($partPurchase, $data, $request->user());

            return ResponseHelper::SuccessResponse($this->present($return), 'Part return recorded', 201);
        });
    }

    /** The part physically went back. Still no money — no credit is written at this step. */
    public function markSent(Request $request, PartReturn $partReturn)
    {
        return $this->run(fn () => ResponseHelper::SuccessResponse(
            $this->present($this->returns->markSent($partReturn, $request->user())),
            'Part return sent',
        ));
    }

    /** The money came back — writes the credit line and drops the ticket cost. */
    public function refund(Request $request, PartReturn $partReturn)
    {
        return $this->run(function () use ($request, $partReturn) {
            $data = $request->validate(['refund_amount' => ['nullable', 'numeric', 'min:0']]);

            return ResponseHelper::SuccessResponse(
                $this->present($this->returns->refund($partReturn, $request->user(), $data)),
                'Refund recorded',
            );
        });
    }

    /** The supplier refused it — the cost stays with us, and any credit already written is reversed. */
    public function reject(Request $request, PartReturn $partReturn)
    {
        return $this->run(function () use ($request, $partReturn) {
            $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

            return ResponseHelper::SuccessResponse(
                $this->present($this->returns->reject($partReturn, $request->user(), $data['reason'])),
                'Return rejected',
            );
        });
    }

    private function present(PartReturn $return): array
    {
        $return->loadMissing(['purchase.sourceVendor:id,name']);
        $purchase = $return->purchase;

        return [
            'id'               => $return->id,
            'part_purchase_id' => $return->part_purchase_id,
            'part_name'        => $purchase?->part_name,
            'supplier'         => $purchase?->sourceVendor?->name ?: $purchase?->source_name,
            'source'           => $purchase?->purchase_source,
            'vehicle_id'       => $return->vehicle_id,
            'maintenance_id'   => $return->maintenance_id,
            'maintenance_task_id' => $return->maintenance_task_id,
            'quantity'         => (float) $return->quantity,
            'reason_code'      => $return->reason_code,
            'reason_label'     => $return->reasonLabel(),
            'reason_note'      => $return->reason_note,
            'refund_amount'    => (float) $return->refund_amount,
            'restocking_fee'   => (float) $return->restocking_fee,
            'currency'         => $return->currency,
            'status'           => $return->status,
            'credited'         => $return->isRefunded(),
            'credit_line_item_id' => $return->credit_line_item_id,
            'rejection_reason' => $return->rejection_reason,
            'returned_by'      => $return->returned_by_name,
            'returned_at'      => optional($return->returned_at)->toIso8601String(),
            'settled_by'       => $return->settled_by_name,
            'settled_at'       => optional($return->settled_at)->toIso8601String(),
            // What the buy nets out at once this return is counted — the number the ticket shows.
            'purchase_gross'   => $purchase?->grossCost(),
            'purchase_net'     => $purchase?->fresh()->netCost(),
        ];
    }

    private function run(callable $fn)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }
}
