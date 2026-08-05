<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One measurement of how much of the fleet's cost can be proved, taken on a date.
 *
 * Coverage as a single number says nothing about whether anyone is fixing it. Stored over time it becomes
 * the metric the legacy cleanup is actually managed by: verified money rising, legacy money falling.
 *
 * @see \App\Services\CostVerificationService::snapshot()
 */
class TraceabilitySnapshot extends Model
{
    protected $table = 'traceability_snapshots';

    protected $fillable = [
        'taken_on', 'taken_at',
        'tickets_total', 'tickets_verified', 'tickets_legacy',
        'total_cost', 'verified_cost', 'legacy_cost', 'unverified_cost',
        'coverage_pct', 'by_source',
    ];

    protected $casts = [
        'taken_on'        => 'date',
        'taken_at'        => 'datetime',
        'total_cost'      => 'decimal:2',
        'verified_cost'   => 'decimal:2',
        'legacy_cost'     => 'decimal:2',
        'unverified_cost' => 'decimal:2',
        'coverage_pct'    => 'decimal:2',
        'by_source'       => 'array',
    ];

    /** Movement since the previous measurement — the only figure that says whether progress is happening. */
    public function deltaFrom(?self $previous): ?array
    {
        if (! $previous) {
            return null;
        }

        return [
            'verified_cost' => round((float) $this->verified_cost - (float) $previous->verified_cost, 2),
            'legacy_cost'   => round((float) $this->legacy_cost - (float) $previous->legacy_cost, 2),
            'coverage_pct'  => round((float) $this->coverage_pct - (float) $previous->coverage_pct, 2),
            'since'         => $previous->taken_on->toDateString(),
        ];
    }
}
