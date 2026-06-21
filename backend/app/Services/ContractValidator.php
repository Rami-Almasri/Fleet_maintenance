<?php

namespace App\Services;

use App\Models\Contract;

/**
 * Read-only data-quality checks on contracts. Nothing here mutates the database —
 * it surfaces records for manual review (e.g. in the post-import reconciliation report).
 */
class ContractValidator
{
    /**
     * "Zombie" contracts: still open with no recorded return (in_date) long after the car
     * went out. These are almost always data gaps — the car came back but the close was
     * never recorded — so they need a human to reconcile.
     *
     * @return array{days:int, cutoff:string, count:int, samples:array<int,array<string,mixed>>}
     */
    public function zombies(int $days = 90, int $sampleLimit = 50): array
    {
        $cutoff = now()->subDays(max(1, $days))->toDateString();

        $base = Contract::query()
            ->where('state', 'open')
            ->whereNull('in_date')
            ->whereNotNull('out_date')
            ->whereDate('out_date', '<', $cutoff);

        $count = (clone $base)->count();

        $samples = (clone $base)
            ->orderBy('out_date')
            ->limit($sampleLimit)
            ->get(['id', 'contract_no', 'contract_serial', 'vehicle_id', 'customer_id', 'out_date'])
            ->map(fn (Contract $c) => [
                'contract_no'     => (string) $c->contract_no,
                'contract_serial' => (string) $c->contract_serial,
                'out_date'        => optional($c->out_date)->toDateString(),
                'days_out'        => $c->out_date ? (int) $c->out_date->diffInDays(now()) : null,
                'vehicle_id'      => $c->vehicle_id,
            ])
            ->all();

        return [
            'days'    => $days,
            'cutoff'  => $cutoff,
            'count'   => $count,
            'samples' => $samples,
        ];
    }
}
