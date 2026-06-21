<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Customer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Contract Exchange — detect and manage "car swaps", where one retail customer returns a
 * vehicle and drives off in a different one within a short window. Those two contracts are
 * really one continuous rental relationship fragmented across two rows; linking them
 * (child.parent_contract_id = parent.id) rebuilds the chain so deposit/credit can carry
 * over and history reads as one journey.
 *
 * Detection rule (see detectPairs()):
 *   • same retail customer_id (the MAINTENANCE / garage placeholder accounts are excluded)
 *   • the next contract is on a DIFFERENT vehicle
 *   • its pickup falls inside the window around the previous return:
 *        from  PREV.return − OVERLAP_TOLERANCE_HOURS   (same-day swaps logged slightly early)
 *        to    PREV.return + WINDOW_HOURS              (the "within 24–48h" rule)
 *
 * NOTE on "early return": the spec also wanted to require that the return beat the
 * contract's due date. The schema has no due/expected-return date for rentals — `days`
 * is the realised out→in duration, not a contracted term — so that sub-condition is not
 * computable today and is intentionally omitted. The gap rule above is the reliable
 * signal (it was validated against the full 33k-rental history). If a planned-return date
 * is added later, gate it in `isExchangeGap()`.
 */
class ContractExchangeService
{
    /** Forward window: how long after a return a new pickup still counts as a swap. */
    public const WINDOW_HOURS = 48;

    /** Backward tolerance: a swap occasionally has the new contract opened a touch before
     *  the old one is closed (same-day, times out of order). Allow a small overlap. */
    public const OVERLAP_TOLERANCE_HOURS = 24;

    /**
     * Pending (not-yet-linked) exchange pairs among recently active customers, newest first.
     * This is what the Anomalies / Return Reconciliation page lists under "Pending Exchange
     * Links". Scoped to the last $sinceDays so it stays fast and actionable (old swaps can be
     * backfilled separately in a one-off command).
     *
     * @return array{count:int, shown:int, pairs:array<int,array<string,mixed>>}
     */
    public function pendingLinks(int $sinceDays = 90, int $cap = 100): array
    {
        $cutoff = now()->subDays($sinceDays)->toDateString();
        $pseudoIds = $this->pseudoCustomerIds();

        // Customers with a recent rental — the only ones that can have a *new* pending swap.
        $activeCustomerIds = Contract::query()
            ->where('contract_type', 'C')
            ->whereNotNull('customer_id')
            ->whereNotIn('customer_id', $pseudoIds)
            ->whereDate('out_date', '>=', $cutoff)
            ->distinct()->pluck('customer_id');

        if ($activeCustomerIds->isEmpty()) {
            return ['count' => 0, 'shown' => 0, 'pairs' => []];
        }

        // All of those customers' rentals (a parent may sit just before the cutoff).
        $contracts = Contract::query()
            ->where('contract_type', 'C')
            ->whereIn('customer_id', $activeCustomerIds)
            ->whereNotNull('vehicle_id')
            ->whereNotNull('out_date')
            ->with(['vehicle:id,plate_no,make,model', 'customer:id,name_en,customer_no'])
            ->orderBy('customer_id')->orderBy('out_date')->orderBy('id')
            ->get();

        $pairs = $this->detectPairs($contracts)
            // only surface ones not already linked and where the *child* is recent/actionable
            ->filter(fn ($p) => $p['child']->parent_contract_id === null
                && $p['child']->out_date?->toDateString() >= $cutoff)
            ->sortByDesc(fn ($p) => $p['child']->out_date?->timestamp)
            ->values();

        $shown = $pairs->take($cap)->map(fn ($p) => $this->describePair($p))->all();

        return ['count' => $pairs->count(), 'shown' => count($shown), 'pairs' => $shown];
    }

    /**
     * The pending pairs shaped as an Anomalies "group" so they can ride on the
     * Return Reconciliation page alongside the other exceptional cases. Severity is
     * 'review' (not a conflict/gap — a suggested action) and `kind` flags it so the
     * frontend renders the interactive approve/carry UI instead of the generic table.
     */
    public function pendingGroup(int $sinceDays = 90, int $cap = 100): array
    {
        $res = $this->pendingLinks($sinceDays, $cap);

        return [
            'key'         => 'pending_exchange_links',
            'kind'        => 'exchange',
            'title'       => 'Pending Exchange Links',
            'severity'    => 'review',
            'description' => 'A retail customer returned one car and drove off in another within '
                . self::WINDOW_HOURS . ' hours — almost certainly a swap, not two separate rentals. '
                . 'Review each pair and link them so history reads as one journey; use "Link & Carry Balance" '
                . 'to record the deposit/credit to move across in OfficeManager. Workshop placeholder accounts are excluded.',
            'count'       => $res['count'],
            'shown'       => $res['shown'],
            'items'       => $res['pairs'],
        ];
    }

    /**
     * Walk a customer-ordered contract collection and return every consecutive pair that
     * looks like a swap. Pure (no DB) so it is trivially testable and reusable by both the
     * pending-list and a historical backfill.
     *
     * @param  Collection<int,Contract>  $contracts  ordered by customer_id, out_date, id
     * @return Collection<int,array{parent:Contract, child:Contract, gap_hours:float, held_days:int}>
     */
    public function detectPairs(Collection $contracts): Collection
    {
        $pairs = collect();

        foreach ($contracts->groupBy('customer_id') as $list) {
            $list = $list->values();
            for ($i = 0; $i < $list->count() - 1; $i++) {
                $prev = $list[$i];
                $next = $list[$i + 1];

                if ($prev->vehicle_id === $next->vehicle_id) {
                    continue; // same car back-to-back = renewal, not a swap
                }
                if (! $prev->inAt || ! $next->outAt) {
                    continue; // need a return on the old and a pickup on the new
                }

                $gapHours = $prev->inAt->diffInHours($next->outAt, false);
                if (! $this->isExchangeGap($gapHours)) {
                    continue;
                }

                $pairs->push([
                    'parent'    => $prev,
                    'child'     => $next,
                    'gap_hours' => $gapHours,
                    'held_days' => (int) ($prev->outAt?->diffInDays($prev->inAt) ?? 0),
                ]);
            }
        }

        return $pairs;
    }

    /** Is this return→pickup gap (in hours) inside the swap window? */
    public function isExchangeGap(float $gapHours): bool
    {
        return $gapHours >= -self::OVERLAP_TOLERANCE_HOURS && $gapHours <= self::WINDOW_HOURS;
    }

    /**
     * Suggest the likely parent and likely children for one contract — used to offer a
     * "Link this exchange" prompt on the Contract view. Returns unlinked candidates only.
     *
     * @return array{parent:?array, children:array<int,array>}
     */
    public function candidatesFor(Contract $contract): array
    {
        if ($contract->contract_type !== 'C' || ! $contract->customer_id
            || in_array($contract->customer_id, $this->pseudoCustomerIds(), true)) {
            return ['parent' => null, 'children' => []];
        }

        $siblings = Contract::query()
            ->where('contract_type', 'C')
            ->where('customer_id', $contract->customer_id)
            ->whereNotNull('vehicle_id')->whereNotNull('out_date')
            ->with(['vehicle:id,plate_no,make,model'])
            ->orderBy('out_date')->orderBy('id')
            ->get();

        // include $contract in the sequence so detectPairs can pair around it
        $seq = $siblings->contains('id', $contract->id) ? $siblings : $siblings->push($contract)
            ->sortBy([['out_date', 'asc'], ['id', 'asc']])->values();

        $pairs = $this->detectPairs($seq);

        $parent = $pairs->first(fn ($p) => $p['child']->id === $contract->id
            && $contract->parent_contract_id === null);
        $children = $pairs->filter(fn ($p) => $p['parent']->id === $contract->id
            && $p['child']->parent_contract_id === null);

        return [
            'parent'   => $parent ? $this->describePair($parent) : null,
            'children' => $children->map(fn ($p) => $this->describePair($p))->values()->all(),
        ];
    }

    /**
     * Link a child contract to its parent (records the swap). Validates the core invariants
     * so we never build a nonsensical or cyclic chain. Returns the refreshed child.
     */
    public function link(Contract $parent, Contract $child, ?string $by = null): Contract
    {
        if ($parent->id === $child->id) {
            throw new \InvalidArgumentException('A contract cannot be its own parent.');
        }
        if ($parent->customer_id !== $child->customer_id) {
            throw new \InvalidArgumentException('Exchange links must stay within one customer.');
        }
        if ($this->wouldCycle($parent, $child)) {
            throw new \InvalidArgumentException('Linking these would create a loop in the chain.');
        }

        $child->forceFill([
            'parent_contract_id' => $parent->id,
            'exchange_linked_at' => now(),
            'exchange_linked_by' => $by,
        ])->save();

        return $child->refresh();
    }

    /** Detach a child from its chain (it becomes a standalone contract / new root). */
    public function unlink(Contract $child): Contract
    {
        $child->forceFill([
            'parent_contract_id' => null,
            'exchange_linked_at' => null,
            'exchange_linked_by' => null,
        ])->save();

        return $child->refresh();
    }

    /**
     * The full chain a contract belongs to, oldest → newest, each entry annotated with the
     * gap to the previous link. Drives the "Chain of Contracts" panel on the Contract view.
     */
    public function chain(Contract $contract): array
    {
        $chain = \Illuminate\Database\Eloquent\Collection::make($contract->exchangeChain()->all())
            ->load(['vehicle:id,plate_no,make,model']);
        $prev = null;
        $out = [];
        foreach ($chain as $c) {
            $gap = ($prev && $prev->inAt && $c->outAt) ? round($prev->inAt->diffInHours($c->outAt, false), 1) : null;
            $out[] = [
                'id'           => $c->id,
                'contract_no'  => $c->contract_no,
                'is_current'   => $c->id === $contract->id,
                'vehicle'      => $c->vehicle ? trim(($c->vehicle->plate_no ?: '?') . ' ' . $c->vehicle->make . ' ' . $c->vehicle->model) : null,
                'out_date'     => optional($c->out_date)->toDateString(),
                'in_date'      => optional($c->in_date)->toDateString(),
                'state'        => $c->state,
                'gap_hours'    => $gap,
                'carried_balance' => $c->carried_balance,
            ];
            $prev = $c;
        }

        return $out;
    }

    /**
     * Financial Bridge — "Link & Carry Balance".
     *
     * Carries the parent's leftover money (deposit and/or remaining credit balance) onto the
     * child contract and links the two in one transaction. We record the carried amount in
     * OUR `carried_balance` column rather than overwriting `contract_balance`/`contract_deposit`,
     * because those are owned by the OfficeManager sync and would be clobbered on the next
     * import. The recorded amount drives the UI badge and feeds the operator the figure to
     * post in OfficeManager (the financial source of truth).
     *
     * @param  string  $what  'deposit' | 'credit' | 'both' — which pot to carry
     */
    public function linkAndCarry(Contract $parent, Contract $child, string $what = 'both', ?string $by = null): Contract
    {
        return DB::transaction(function () use ($parent, $child, $what, $by) {
            $this->link($parent, $child, $by);

            $deposit = (float) ($parent->contract_deposit ?? 0);
            // remaining credit = what the customer is in credit by (negative balance owed to them)
            $credit = max(0, -(float) ($parent->contract_balance ?? 0));

            $amount = match ($what) {
                'deposit' => $deposit,
                'credit'  => $credit,
                default   => $deposit + $credit,
            };

            $child->forceFill(['carried_balance' => round($amount, 2)])->save();

            return $child->refresh();
        });
    }

    // ---- internals -----------------------------------------------------------

    /** Build a flat, UI-friendly description of a detected pair. */
    private function describePair(array $p): array
    {
        $parent = $p['parent'];
        $child = $p['child'];

        return [
            'customer_id'   => $parent->customer_id,
            'customer'      => $parent->customer?->name_en
                ?: ($parent->customer?->customer_no ? '#' . $parent->customer->customer_no : null),
            'parent_id'      => $parent->id,
            'parent_no'      => $parent->contract_no,
            'parent_vehicle' => $this->vehicleLabel($parent),
            'returned_on'    => optional($parent->in_date)->toDateString(),
            'held_days'      => $p['held_days'],
            'child_id'       => $child->id,
            'child_no'       => $child->contract_no,
            'child_vehicle'  => $this->vehicleLabel($child),
            'picked_up_on'   => optional($child->out_date)->toDateString(),
            'gap_hours'      => round($p['gap_hours'], 1),
            'parent_deposit' => (float) ($parent->contract_deposit ?? 0),
            'parent_credit'  => max(0, -(float) ($parent->contract_balance ?? 0)),
            // the figure staff would carry across (deposit + any credit owed back) — drives the badge
            'carried_total'  => round((float) ($parent->contract_deposit ?? 0)
                + max(0, -(float) ($parent->contract_balance ?? 0)), 2),
            'already_linked' => $child->parent_contract_id !== null,
        ];
    }

    private function vehicleLabel(Contract $c): ?string
    {
        return $c->vehicle ? trim(($c->vehicle->plate_no ?: '?') . ' ' . $c->vehicle->make . ' ' . $c->vehicle->model) : null;
    }

    /** Would linking child under parent create a loop (parent already downstream of child)? */
    private function wouldCycle(Contract $parent, Contract $child): bool
    {
        $cursor = $parent;
        $guard = 0;
        while ($cursor && $guard++ < 50) {
            if ($cursor->id === $child->id) {
                return true;
            }
            $cursor = $cursor->parent_contract_id ? $cursor->parentContract()->first() : null;
        }

        return false;
    }

    /** @var array<int,int>|null */
    private ?array $pseudoIds = null;

    /** Resolve the placeholder accounts' contract customer_ids once per request. */
    private function pseudoCustomerIds(): array
    {
        return $this->pseudoIds ??= Customer::whereIn('customer_no', Customer::PSEUDO_CUSTOMER_NOS)
            ->pluck('id')->all();
    }
}
