<?php

namespace App\Services;

use App\Models\PartPurchase;
use App\Models\PartRequest;
use App\Models\SpareKeyRequirement;
use App\Models\VehicleComponent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes a spare-key requirement's status from the procurement chain hanging off it.
 *
 * WHY THIS IS ITS OWN CLASS. The approve / reject / purchase / deliver steps of a spare-key buy are
 * the EXISTING ones — a supervisor works them on the parts board through PartWorkflowService, exactly
 * as for a brake pad. That service therefore has to tell the requirement that something moved. If it
 * did so by calling SpareKeyService (which in turn orchestrates PartWorkflowService to raise the buy)
 * the container would have a cycle. Splitting the read-only projection out breaks it: this class
 * depends on nothing, PartWorkflowService depends on this, and SpareKeyService depends on both.
 *
 * It is DERIVED, never authored. Nothing here decides anything — it reads what part_requests,
 * part_purchases and vehicle_components already say and writes the one-word summary the operations
 * board groups by. If the projection and the chain ever disagree, the chain is right and re-running
 * sync() fixes the row.
 */
class SpareKeyProjection
{
    /**
     * Refresh the requirement behind a part request, if that request belongs to one. A no-op for
     * every other part in the fleet, which is what lets PartWorkflowService call it unconditionally.
     */
    public function syncFromPurchaseRequest(?PartRequest $request): ?SpareKeyRequirement
    {
        if (! $request || ! $request->spare_key_requirement_id) {
            return null;
        }

        $requirement = SpareKeyRequirement::find($request->spare_key_requirement_id);

        return $requirement ? $this->sync($requirement) : null;
    }

    /**
     * Recompute status + received_quantity + the open lock for one requirement.
     *
     * Runs under a row lock so two concurrent receipts cannot both read "0 received" and both decide
     * the requirement is still outstanding.
     */
    public function sync(SpareKeyRequirement $requirement): SpareKeyRequirement
    {
        return DB::transaction(function () use ($requirement) {
            /** @var SpareKeyRequirement $row */
            $row = SpareKeyRequirement::whereKey($requirement->id)->lockForUpdate()->firstOrFail();

            // A cancelled requirement is a human decision and the projection must not overrule it:
            // a purchase that lands afterwards is a fact about the buy, not a reason to reopen a
            // need somebody deliberately closed.
            if ($row->status === SpareKeyRequirement::STATUS_CANCELLED) {
                return $row;
            }

            $received = $this->receivedKeyCount($row);
            $status   = $this->deriveStatus($row, $received);

            $row->received_quantity = $received;
            $row->status            = $status;
            // The open lock IS the status, expressed as a unique constraint. Setting it here rather
            // than in each caller is what guarantees the two can never drift.
            $row->open_vehicle_id   = in_array($status, SpareKeyRequirement::OPEN_STATUSES, true)
                ? $row->vehicle_id
                : null;

            if ($status === SpareKeyRequirement::STATUS_COMPLETED) {
                $row->completed_at ??= Carbon::now();
            } else {
                $row->completed_at = null;
            }

            $row->save();

            return $row->fresh();
        });
    }

    /**
     * HOW MANY PHYSICAL KEYS this requirement has actually produced.
     *
     * Counted from vehicle_components — the asset ledger — and not from the purchase quantity,
     * because a purchase is a promise and a component is a key. Retired rows count too: a key that
     * arrived and was later lost was still received, and the requirement that bought it was still
     * satisfied. "How many does the car have TODAY" is a different question, answered by the active
     * rows on the vehicle, and conflating the two is precisely the confusion this feature exists to
     * end.
     */
    private function receivedKeyCount(SpareKeyRequirement $requirement): int
    {
        $purchaseIds = PartPurchase::query()
            ->whereIn('part_request_id', $requirement->purchaseRequests()->select('id'))
            ->pluck('id');

        if ($purchaseIds->isEmpty()) {
            return 0;
        }

        return (int) VehicleComponent::query()
            ->trusted()
            ->whereIn('source_part_purchase_id', $purchaseIds)
            ->count();
    }

    /**
     * The lifecycle position, read backwards from the strongest fact available: keys in hand beats
     * a purchase, which beats an approval, which beats a request.
     */
    private function deriveStatus(SpareKeyRequirement $requirement, int $received): string
    {
        if ($received >= (int) $requirement->quantity && $received > 0) {
            return SpareKeyRequirement::STATUS_COMPLETED;
        }
        if ($received > 0) {
            return SpareKeyRequirement::STATUS_RECEIVED;
        }

        $requests = $requirement->purchaseRequests()->get();

        if ($requests->isEmpty()) {
            return SpareKeyRequirement::STATUS_REQUIRED;
        }

        $live = $requests->reject(
            fn (PartRequest $r) => in_array($r->status, [PartRequest::STATUS_REJECTED, PartRequest::STATUS_CANCELLED], true)
        )->sortByDesc('id')->first();

        // Every attempt so far was refused. The car still has no key, so the requirement stays open
        // and says so — `rejected`, not `completed`, and never silently back to `required` as if
        // nobody had ever tried.
        if (! $live) {
            return SpareKeyRequirement::STATUS_REJECTED;
        }

        return match ($live->status) {
            PartRequest::STATUS_PURCHASED,
            PartRequest::STATUS_INSTALLED,
            PartRequest::STATUS_COMPLETED    => SpareKeyRequirement::STATUS_ORDERED,
            PartRequest::STATUS_APPROVED     => SpareKeyRequirement::STATUS_APPROVED,
            default                          => SpareKeyRequirement::STATUS_PURCHASE_REQUESTED,
        };
    }
}
