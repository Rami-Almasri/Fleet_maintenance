<?php

namespace App\Services;

use App\Exceptions\WorkflowTransitionException;
use App\Models\Maintenance;
use App\Models\MaintenanceLineItem;
use App\Models\PartPurchase;
use App\Models\PartReturn;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleLogEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sending a part back — recorded as an event, never by erasing the buy.
 *
 * The purchase row is history and stays untouched. A return is written beside it and, when the money
 * actually comes back, credits the ticket through a NEGATIVE maintenance_line_items row against the same
 * fault. The ticket then reads honestly:
 *
 *     Brake Pad Set          purchase   +400.00
 *     Returned (wrong part)  credit     -400.00
 *     Net                                  0.00
 *
 * Using the existing line-item path (rather than a returns ledger of its own) means every downstream
 * consumer — fault cost, ticket cost, vehicle TCO, spend reports, the Odoo bridge — picks the credit up
 * with no changes and no risk of one of them missing it.
 *
 * Timing of the money matters and is modelled: `requested` and `sent` are NOT money yet, so they write no
 * credit line and the ticket still carries the full cost. Only `refunded` credits, because only then did
 * cash actually return. `rejected` (the supplier refused it) leaves the cost with us permanently.
 *
 * A restocking fee is never credited — it is money we did not get back, so it belongs in the ticket cost.
 */
class PartReturnService
{
    public function __construct(private VehicleLogService $log) {}

    /**
     * Log a return against a purchase. Optionally settles it immediately (`status = refunded`), for the
     * common case where the counter refunds on the spot.
     *
     * @param array{quantity?:float, reason_code:string, reason_note?:?string, refund_amount?:?float,
     *              restocking_fee?:?float, status?:?string} $data
     */
    public function create(PartPurchase $purchase, array $data, User $actor): PartReturn
    {
        return DB::transaction(function () use ($purchase, $data, $actor) {
            $quantity = $this->assertReturnableQuantity($purchase, (float) ($data['quantity'] ?? $purchase->quantity ?? 1));

            // Default the refund to what that quantity actually cost — the overwhelmingly common case —
            // then subtract whatever the supplier keeps back.
            $unit   = (float) $purchase->purchase_price;
            $fee    = round((float) ($data['restocking_fee'] ?? 0), 2);
            $refund = array_key_exists('refund_amount', $data) && $data['refund_amount'] !== null
                ? round((float) $data['refund_amount'], 2)
                : round(($unit * $quantity) - $fee, 2);

            $this->assertRefundWithinValue($purchase, $refund, $quantity);

            $status = in_array($data['status'] ?? null, PartReturn::STATUSES, true)
                ? $data['status'] : PartReturn::STATUS_REQUESTED;

            $return = PartReturn::create([
                'part_purchase_id'    => $purchase->id,
                'vehicle_id'          => $purchase->vehicle_id,
                'maintenance_id'      => $purchase->maintenance_id,
                'maintenance_task_id' => $purchase->maintenance_task_id,
                'quantity'            => $quantity,
                'reason_code'         => $data['reason_code'],
                'reason_note'         => $this->clean($data['reason_note'] ?? null),
                'refund_amount'       => $refund,
                'restocking_fee'      => $fee,
                'currency'            => $purchase->currency ?: 'AED',
                'status'              => PartReturn::STATUS_REQUESTED,
                'returned_by'         => $actor->id,
                'returned_by_name'    => $actor->name ?: $actor->email,
                'returned_at'         => Carbon::now(),
            ]);

            $this->logToTimeline($return, $purchase, $actor, 'logged');

            // Caller asked for it to land already settled — run the real transition so the credit line and
            // its audit trail are produced by exactly one code path.
            if ($status === PartReturn::STATUS_REFUNDED) {
                return $this->refund($return->fresh(), $actor, ['refund_amount' => $refund]);
            }
            if ($status === PartReturn::STATUS_SENT) {
                return $this->markSent($return->fresh(), $actor);
            }

            return $return->fresh();
        });
    }

    /** The part physically went back; the money hasn't arrived. No credit yet — nothing has been refunded. */
    public function markSent(PartReturn $return, User $actor): PartReturn
    {
        $this->guard($return, [PartReturn::STATUS_REQUESTED], 'send');

        $return->forceFill(['status' => PartReturn::STATUS_SENT])->save();
        $this->logToTimeline($return, $return->purchase, $actor, 'sent back');

        return $return->fresh();
    }

    /**
     * The money came back. This is the only transition that writes the credit line, so it is the only place
     * the ticket cost can fall — and it can only happen once per return.
     *
     * @param array{refund_amount?:?float} $data
     */
    public function refund(PartReturn $return, User $actor, array $data = []): PartReturn
    {
        $this->guard($return, [PartReturn::STATUS_REQUESTED, PartReturn::STATUS_SENT], 'refund');

        return DB::transaction(function () use ($return, $actor, $data) {
            $purchase = $return->purchase;

            if (array_key_exists('refund_amount', $data) && $data['refund_amount'] !== null) {
                $refund = round((float) $data['refund_amount'], 2);
                $this->assertRefundWithinValue($purchase, $refund, (float) $return->quantity, $return->id);
                $return->refund_amount = $refund;
            }

            $return->forceFill([
                'status'           => PartReturn::STATUS_REFUNDED,
                'refund_amount'    => $return->refund_amount,
                'settled_by'       => $actor->id,
                'settled_by_name'  => $actor->name ?: $actor->email,
                'settled_at'       => Carbon::now(),
            ])->save();

            if ($line = $this->writeCreditLine($return, $purchase, $actor)) {
                $return->forceFill(['credit_line_item_id' => $line->id])->save();
            }

            $this->logToTimeline($return, $purchase, $actor, 'refunded');

            return $return->fresh(['creditLine']);
        });
    }

    /**
     * The supplier refused the return. The part and its cost stay with us — no credit is written, and any
     * credit written by a previous state is removed so the ticket goes back up to what we really paid.
     */
    public function reject(PartReturn $return, User $actor, string $reason): PartReturn
    {
        $this->guard($return, [PartReturn::STATUS_REQUESTED, PartReturn::STATUS_SENT, PartReturn::STATUS_REFUNDED], 'reject');

        return DB::transaction(function () use ($return, $actor, $reason) {
            if ($return->credit_line_item_id) {
                // Delete the MODEL, not the query — a query-builder delete fires no `deleted` event, so
                // the cost would never roll back up and the ticket would keep a credit it no longer has.
                optional(MaintenanceLineItem::find($return->credit_line_item_id))->delete();
            }

            $return->forceFill([
                'status'              => PartReturn::STATUS_REJECTED,
                'rejection_reason'    => trim($reason),
                'credit_line_item_id' => null,
                'settled_by'          => $actor->id,
                'settled_by_name'     => $actor->name ?: $actor->email,
                'settled_at'          => Carbon::now(),
            ])->save();

            $this->logToTimeline($return, $return->purchase, $actor, 'refused by the supplier');

            return $return->fresh();
        });
    }

    // ── Internals ────────────────────────────────────────────────────────────────────────────────────

    /**
     * Write the negative part line that carries the credit onto the ticket. Tagged to the same fault and
     * the same invoice as the purchase's own line, so the credit lands in exactly the place the charge did
     * — including inside a garage invoice's parts total, which would otherwise stop reconciling.
     *
     * Returns null for a purchase with no ticket (a pure customer buy has no ticket cost to credit).
     */
    private function writeCreditLine(PartReturn $return, PartPurchase $purchase, User $actor): ?MaintenanceLineItem
    {
        if (! $purchase->maintenance_id || (float) $return->refund_amount <= 0) {
            return null;
        }

        $quantity = (float) $return->quantity ?: 1;
        $original = $purchase->lineItem; // the charge this credit reverses (null if never installed)

        return MaintenanceLineItem::create([
            'maintenance_id'         => $purchase->maintenance_id,
            'maintenance_invoice_id' => $original?->maintenance_invoice_id,
            'maintenance_task_id'    => $purchase->maintenance_task_id,
            'vehicle_id'             => $purchase->vehicle_id,
            'kind'                   => MaintenanceLineItem::KIND_PART,
            // Diagnosis-First applies to a credit exactly as it does to a charge.
            'finding_text'           => $original?->finding_text ?: $purchase->task?->symptom,
            'category_key'           => $purchase->category_key,
            'description'            => "Returned: {$purchase->part_name} ({$return->reasonLabel()})",
            'part_number'            => $purchase->part_number,
            'quantity'               => $quantity,
            // Negative unit price → negative line_total (computed on save) → the ticket total falls.
            'unit_price'             => round(-((float) $return->refund_amount) / $quantity, 2),
            'created_by'             => $actor->id,
            'entry_source'           => 'return', // origin tag; entry_source is varchar(12)
            // Structured origin: this credit IS a credit note, and names the return that issued it.
            'source_type'            => CostSourceResolver::SOURCE_CREDIT_NOTE,
            'source_id'              => $return->id,
        ]);
    }

    /**
     * A part cannot be returned more than once over. Counts every return that still stands (a rejected one
     * released its quantity back) so partial returns add up correctly.
     */
    private function assertReturnableQuantity(PartPurchase $purchase, float $requested): float
    {
        $quantity = round(max(0.0, $requested), 2);

        if ($quantity <= 0) {
            throw new WorkflowTransitionException(
                'Say how many were returned.',
                ['field' => 'quantity'],
            );
        }

        $purchased = (float) ($purchase->quantity ?: 1);
        $already   = round((float) $purchase->returns()
            ->whereNotIn('status', [PartReturn::STATUS_REJECTED])
            ->sum('quantity'), 2);

        if (round($already + $quantity, 2) > $purchased) {
            $left = round($purchased - $already, 2);

            throw new WorkflowTransitionException(
                $already > 0
                    ? "Only {$left} of the {$purchased} bought are still returnable — {$already} already went back."
                    : "Only {$purchased} were bought, so {$quantity} cannot be returned.",
                ['field' => 'quantity', 'returnable' => $left],
            );
        }

        return $quantity;
    }

    /**
     * We can never be refunded more than we paid for what went back. Without this a credit line could drive
     * a ticket's cost negative, which no downstream report would treat as an error.
     */
    private function assertRefundWithinValue(PartPurchase $purchase, float $refund, float $quantity, ?int $ignoreReturnId = null): void
    {
        if ($refund < 0) {
            throw new WorkflowTransitionException('A refund cannot be negative.', ['field' => 'refund_amount']);
        }

        $valueReturned = round((float) $purchase->purchase_price * $quantity, 2);
        if ($refund > $valueReturned + 0.01) {
            throw new WorkflowTransitionException(
                'The refund (' . number_format($refund, 2) . ') is more than the '
                . number_format($valueReturned, 2) . ' paid for the parts going back.',
                ['field' => 'refund_amount', 'max' => $valueReturned],
            );
        }

        // And the returns together can never refund more than the whole purchase.
        $otherRefunds = (float) $purchase->returns()
            ->where('status', PartReturn::STATUS_REFUNDED)
            ->when($ignoreReturnId, fn ($q) => $q->where('id', '!=', $ignoreReturnId))
            ->sum('refund_amount');

        if (round($otherRefunds + $refund, 2) > $purchase->grossCost() + 0.01) {
            throw new WorkflowTransitionException(
                'That would refund more than the ' . number_format($purchase->grossCost(), 2)
                . ' this purchase cost in total.',
                ['field' => 'refund_amount'],
            );
        }
    }

    /** @param array<int,string> $allowed */
    private function guard(PartReturn $return, array $allowed, string $action): void
    {
        if (! in_array($return->status, $allowed, true)) {
            abort(409, "Cannot {$action} a return that is already '{$return->status}'.");
        }
    }

    private function logToTimeline(PartReturn $return, ?PartPurchase $purchase, User $actor, string $verb): void
    {
        if (! $purchase) {
            return;
        }

        $money = $return->status === PartReturn::STATUS_REFUNDED
            ? ' · ' . $return->currency . ' ' . number_format((float) $return->refund_amount, 2) . ' credited'
            : '';

        $opts = [
            'source_tag'  => 'parts',
            'description' => "Part return {$verb}: {$purchase->part_name} — {$return->reasonLabel()}{$money} (by {$actor->name})",
            'meta'        => [
                'part_return_id'   => $return->id,
                'part_purchase_id' => $purchase->id,
                'status'           => $return->status,
                'reason_code'      => $return->reason_code,
                'quantity'         => (float) $return->quantity,
                'refund_amount'    => (float) $return->refund_amount,
                'restocking_fee'   => (float) $return->restocking_fee,
            ],
        ];

        if ($purchase->maintenance_id && ($ticket = Maintenance::find($purchase->maintenance_id))) {
            $this->log->record($ticket, VehicleLogEvent::EVENT_COST_RECORDED, $actor, $opts);
        } elseif ($vehicle = Vehicle::find($purchase->vehicle_id)) {
            $this->log->recordVehicle($vehicle, VehicleLogEvent::EVENT_COST_RECORDED, $actor, $opts);
        }
    }

    private function clean($value): ?string
    {
        $v = is_string($value) ? trim($value) : '';

        return $v === '' ? null : $v;
    }
}
