<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * One decision about whether a capability may reason from measured evidence instead of a proxy.
 *
 * Append-only, like every other claim the platform records. A promotion that is later reversed is a
 * new row saying so, not an edit — otherwise "why is this card speaking with full authority?" would
 * have no answer six months from now.
 */
class CapabilityPromotion extends Model
{
    public const BASIS_PROXY    = 'proxy';
    public const BASIS_MEASURED = 'measured';

    protected $fillable = [
        'capability_id', 'from_basis', 'to_basis', 'promoted', 'reason', 'decision_rule',
        'proxy_metrics', 'measured_metrics', 'operating_point',
        'evidence_count', 'evidence_threshold',
        'capability_version', 'query_layer_version',
        'proxy_model_version', 'measured_model_version', 'dataset_version', 'backtest_version',
        'decided_at',
    ];

    protected $casts = [
        'promoted'         => 'boolean',
        'proxy_metrics'    => 'array',
        'measured_metrics' => 'array',
        'operating_point'  => 'array',
        'decided_at'       => 'datetime',
    ];

    /**
     * The provenance every evaluation must carry to be reproducible later.
     *
     * Enforced rather than documented: a decision missing any of these cannot be reconstructed, and a
     * decision that cannot be reconstructed is an opinion with a timestamp. The guard is what makes
     * "by default" true — a future evaluation path that forgets one of these fails loudly at the
     * moment it is written, not silently years later when someone asks why.
     */
    public const REQUIRED_PROVENANCE = [
        'capability_version',
        'proxy_model_version',
        'measured_model_version',
        'dataset_version',
        'backtest_version',
        'query_layer_version',
        'decision_rule',
        'decided_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $promotion) {
            $missing = array_values(array_filter(
                self::REQUIRED_PROVENANCE,
                fn (string $field) => blank($promotion->{$field}),
            ));

            if ($missing !== []) {
                throw new RuntimeException(
                    'A promotion decision must record its full provenance. Missing: '.implode(', ', $missing).'. '
                    .'Without it the decision cannot be reproduced, and an irreproducible decision is an opinion with a timestamp.'
                );
            }
        });

        static::updating(function () {
            throw new RuntimeException('Capability promotions are append-only. Record a new decision instead of editing one.');
        });

        static::deleting(function () {
            throw new RuntimeException('Capability promotions are append-only — including the refusals, which are the most useful rows here.');
        });
    }

    /** The standing decision for a capability: the most recent one, whatever it said. */
    public static function current(string $capabilityId): ?self
    {
        return static::where('capability_id', $capabilityId)->latest('decided_at')->first();
    }

    /**
     * Everything needed to reconstruct why this decision was reached.
     *
     * @return array<string, string|null>
     */
    public function provenance(): array
    {
        return [
            'proxy_model'    => $this->proxy_model_version,
            'measured_model' => $this->measured_model_version,
            'dataset'        => $this->dataset_version,
            'backtest'       => $this->backtest_version,
            'capability'     => $this->capability_version,
            'query_layer'    => $this->query_layer_version,
            'evaluated_at'   => $this->decided_at?->toIso8601String(),
            'rule'           => $this->decision_rule,
        ];
    }

    /**
     * Has the ground moved under this decision?
     *
     * A standing promotion rests on a dataset and a methodology. When either changes the decision is
     * not wrong — it was correct for what it evaluated — but it is no longer evidence about the
     * present, and should be re-run rather than trusted.
     */
    public function isSupersededBy(array $current): bool
    {
        foreach (['dataset_version', 'backtest_version', 'proxy_model_version', 'measured_model_version'] as $key) {
            if (isset($current[$key]) && $this->{$key} !== null && $this->{$key} !== $current[$key]) {
                return true;
            }
        }

        return false;
    }
}
