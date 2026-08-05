<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\CostAdjustment;
use App\Models\Maintenance;
use App\Services\CostAdjustmentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cost adjustments — money that moved without a supplier's or garage's paper, recorded as a document.
 *
 * `reason_code` and `reason_note` are both REQUIRED, and the approver is taken from the authenticated
 * user, never the request body. That is the whole contract: an adjustment you cannot explain, or that
 * nobody signed, cannot be created. Money actions → maintenance.manage on the routes.
 */
class CostAdjustmentController extends Controller
{
    public function __construct(private CostAdjustmentService $adjustments) {}

    /** Every adjustment on a ticket, newest first. */
    public function index(Maintenance $ticket)
    {
        return $this->run(fn () => ResponseHelper::SuccessResponse(
            CostAdjustment::with(['vendor:id,name', 'task:id,symptom'])
                ->where('maintenance_id', $ticket->id)
                ->latest('id')
                ->get()
                ->map(fn (CostAdjustment $a) => $this->present($a))
                ->all(),
            'Adjustments retrieved',
        ));
    }

    /** Record an adjustment (multipart: it may carry a photo of whatever backs it). */
    public function store(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $data = $request->validate([
                'applies_to'          => ['required', Rule::in(CostAdjustment::APPLIES_TO)],
                'direction'           => ['required', Rule::in(CostAdjustment::DIRECTIONS)],
                'amount'              => ['required', 'numeric', 'gt:0'],
                'reason_code'         => ['required', Rule::in(CostAdjustment::REASON_CODES)],
                // Not nullable and not merely present — an adjustment with an empty explanation is the
                // unauditable number this whole feature exists to abolish.
                'reason_note'         => ['required', 'string', 'min:3', 'max:2000'],
                'maintenance_task_id' => ['nullable', 'integer'],
                'vendor_id'           => ['nullable', 'integer', Rule::exists('vendors', 'id')],
                'reference'           => ['nullable', 'string', 'max:120'],
                'photo'               => ['nullable', 'image', 'max:8192'],
            ]);

            $adjustment = $this->adjustments->create(
                $ticket,
                collect($data)->except('photo')->all(),
                $request->user(),
                $request->file('photo'),
            );

            return ResponseHelper::SuccessResponse($this->present($adjustment), 'Adjustment recorded', 201);
        });
    }

    /** Reverse an adjustment — the record stays, its money comes off the ticket. */
    public function reverse(Request $request, CostAdjustment $costAdjustment)
    {
        return $this->run(function () use ($request, $costAdjustment) {
            $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

            return ResponseHelper::SuccessResponse(
                $this->present($this->adjustments->reverse($costAdjustment, $request->user(), $data['reason'])),
                'Adjustment reversed',
            );
        });
    }

    private function present(CostAdjustment $a): array
    {
        return [
            'id'             => $a->id,
            'maintenance_id' => $a->maintenance_id,
            'fault'          => $a->task?->symptom,
            'applies_to'     => $a->applies_to,
            'direction'      => $a->direction,
            'amount'         => (float) $a->amount,
            'signed_amount'  => $a->signedAmount(),
            'currency'       => $a->currency,
            'reason_code'    => $a->reason_code,
            'reason_label'   => $a->reasonLabel(),
            'reason_note'    => $a->reason_note,
            'reference'      => $a->reference,
            'vendor'         => $a->vendor?->name,
            'photo_url'      => $a->photoUrl(),
            'approved_by'    => $a->approved_by_name,
            'approved_at'    => optional($a->approved_at)->toIso8601String(),
            // Null once reversed — the record survives, the money does not.
            'line_item_id'   => $a->line_item_id,
            'reversed'       => $a->line_item_id === null,
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
