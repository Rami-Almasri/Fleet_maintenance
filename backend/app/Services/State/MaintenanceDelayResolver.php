<?php

namespace App\Services\State;

use App\Models\Maintenance;
use App\Models\PartPurchase;
use App\Models\PartRequest;
use Illuminate\Support\Carbon;

/**
 * Derives WHY a maintenance ticket is delayed — the single owner of the delay reason (blueprint §5,
 * Invariant 1). The reason is INFERRED, never stored: first from the parts block ({@see
 * WorkflowStateResolver}), otherwise from the latest checkpoint's manual reason.
 *
 * PURE FUNCTION: input a Maintenance, output a {@see MaintenanceDelay}. No writes, no events, no
 * logging, no notifications, no timeline/workflow changes, no touch of `vehicles.operational_status`.
 * It only READS the ticket's loaded relations (`tasks.partRequests.purchases`, `checkpoints`) and the
 * clock; callers eager-load (CarStatusService, Step 4). It composes another pure resolver — that is
 * derivation-over-derivation, not an external side-effecting service.
 *
 * SCOPE (Phase 1, Step 3): derives `waiting_for_parts` + the manual-checkpoint fallback. It does NOT
 * derive `additional_damage` (blueprint §5 leaves "repair start" undefined — recorded as a Reality
 * Check gap, no column/heuristic added) and does NOT compute overdue/escalation (that stays with
 * MaintenanceCheckpointService::monitorState → the future EscalationPolicy). Advances Scenarios 2, 9.
 */
final class MaintenanceDelayResolver
{
    public function __construct(
        private readonly WorkflowStateResolver $repair,
    ) {}

    public function resolve(Maintenance $ticket): MaintenanceDelay
    {
        $repairState      = $this->repair->resolve($ticket);
        $latestCheckpoint = $ticket->checkpoints->first(); // checkpoints() is ->latest(): newest first
        $checkpointStatus = $latestCheckpoint?->status;

        // 1) Parts-derived delay — the primary, fully-inferred reason.
        if ($repairState->isWaitingForParts()) {
            return $this->fromParts($ticket, $repairState, $checkpointStatus);
        }

        // 2) Manual checkpoint fallback — not parts-blocked, but the ticket is past the completion date the
        //    workshop last promised. "Delayed" is DERIVED from the date (today > promised ETA), never from a
        //    manual verdict; the reason comes from the latest update's recorded ETA-change reason, if any.
        $eta = $ticket->effectiveExpectedCompletion();
        if ($eta !== null && $eta->copy()->startOfDay()->lt(Carbon::today())) {
            return new MaintenanceDelay(
                isDelayed: true,
                delayReason: $latestCheckpoint?->delay_reason,
                delaySource: MaintenanceDelay::SOURCE_CHECKPOINT_MANUAL,
                expectedResolutionDate: $eta->toDateString(),
                checkpointStatus: $checkpointStatus,
            );
        }

        // 3) Not delayed by anything this resolver owns.
        return MaintenanceDelay::none($checkpointStatus);
    }

    private function fromParts(Maintenance $ticket, RepairState $state, ?string $checkpointStatus): MaintenanceDelay
    {
        $blocking = $this->blockingRequests($ticket, $state->blockingPartRequestIds);
        $primary  = $blocking->sortBy('id')->first();
        $purchase = $primary ? $this->activePurchase($primary) : null;

        // Expected resolution = when the LAST blocking part is due (all must arrive); null if none known.
        $eta = $blocking
            ->map(fn (PartRequest $r) => $this->activePurchase($r)?->expected_delivery_date)
            ->filter()
            ->max();

        return new MaintenanceDelay(
            isDelayed: true,
            delayReason: MaintenanceDelay::REASON_WAITING_FOR_PARTS,
            delaySource: MaintenanceDelay::SOURCE_DERIVED_PARTS,
            daysWaiting: $primary ? $this->daysWaiting($primary, $purchase) : null,
            expectedResolutionDate: $eta?->toDateString(),
            supplierName: $this->supplierName($purchase),
            checkpointStatus: $checkpointStatus,
            headline: $this->headline($primary, $blocking->count()),
        );
    }

    /** The blocking part_requests, resolved from ids against the ticket's loaded fault→request graph. */
    private function blockingRequests(Maintenance $ticket, array $ids): \Illuminate\Support\Collection
    {
        return $ticket->tasks
            ->flatMap(fn ($fault) => $fault->partRequests)
            ->filter(fn (PartRequest $r) => in_array($r->id, $ids, true))
            ->values();
    }

    /** The most recent purchase attempt against a request (null if not ordered yet). */
    private function activePurchase(PartRequest $request): ?PartPurchase
    {
        return $request->purchases->sortByDesc('id')->first();
    }

    private function daysWaiting(PartRequest $request, ?PartPurchase $purchase): int
    {
        $anchor = $purchase?->purchased_at
            ?? $request->approved_at
            ?? $request->requested_at
            ?? $request->created_at;

        if ($anchor === null) {
            return 0;
        }

        return (int) $anchor->diffInDays(Carbon::now());
    }

    private function supplierName(?PartPurchase $purchase): ?string
    {
        if ($purchase === null) {
            return null;
        }

        if (! empty($purchase->source_name)) {
            return $purchase->source_name;
        }

        // Only read the vendor if the caller already loaded it — never lazy-query from a pure resolver.
        return $purchase->relationLoaded('sourceVendor') ? $purchase->sourceVendor?->name : null;
    }

    private function headline(?PartRequest $primary, int $count): ?string
    {
        if ($primary === null) {
            return null;
        }

        return $count > 1
            ? "Waiting for {$primary->part_name} (+" . ($count - 1) . ' more)'
            : "Waiting for {$primary->part_name}";
    }
}
