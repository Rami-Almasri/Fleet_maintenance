<?php

namespace App\Services\Garage;

/**
 * How much should this fault influence the decision?
 *
 * Treating every fault equally is operationally wrong: it lets a paint scratch pull a car away from the
 * garage that has to get the brakes right. Each fault category maps to a business-impact tier, and the
 * tier's weight multiplies its vote in BOTH the Fault Matching score and the single-vs-split decision.
 *
 *   Safety critical   ×1.5   brakes, suspension, tyres — the car is dangerous
 *   Major mechanical  ×1.3   engine, transmission — the car is undriveable or about to be
 *   Operational       ×1.1   electrical, A/C, lights, fluids, routine — degrades the rental
 *   Cosmetic          ×0.6   bodywork, interior — costs money, not safety
 *
 * The inspector's severity can ESCALATE a fault (a "high" electrical fault is a dead headlight at
 * night), but never demote one: a brake fault logged as routine is still a brake fault. That asymmetry
 * is deliberate — it makes the worst case the default and puts the burden of proof on leniency.
 *
 * PURE: every threshold is injected from config('garage_recommendation.criticality').
 * See [[garage-recommendation-engine]] and [[fault-severity-feature]].
 */
class FaultCriticality
{
    /**
     * The tier, weight and label for one fault.
     *
     * @param  array<string, mixed>  $cfg  config('garage_recommendation.criticality')
     * @return array{tier:string, weight:float, label:string, escalated:bool}
     */
    public function resolve(string $category, ?string $severity, array $cfg): array
    {
        $order = (array) ($cfg['order'] ?? ['safety_critical', 'major_mechanical', 'operational', 'cosmetic']);
        $tiers = (array) ($cfg['tiers'] ?? []);

        $base = (string) (($cfg['categories'] ?? [])[$category] ?? ($cfg['default_tier'] ?? 'operational'));
        $idx = array_search($base, $order, true);
        $idx = $idx === false ? count($order) - 1 : $idx;

        // Escalation moves the fault UP the order (towards index 0). Never down.
        $steps = (int) (($cfg['severity_escalation'] ?? [])[$severity] ?? 0);
        $finalIdx = max(0, $idx - max($steps, 0));
        $tier = $order[$finalIdx] ?? $base;

        return [
            'tier'      => $tier,
            'weight'    => (float) ($tiers[$tier]['weight'] ?? 1.0),
            'label'     => (string) ($tiers[$tier]['label'] ?? $tier),
            'escalated' => $finalIdx < $idx,
        ];
    }

    /**
     * Resolve a whole ticket's faults at once.
     *
     * @param  array<int, string>  $faults
     * @param  array<string, ?string>  $severities  category_key => inspector severity
     * @return array<string, array{tier:string, weight:float, label:string, escalated:bool}>
     */
    public function resolveAll(array $faults, array $severities, array $cfg): array
    {
        $out = [];
        foreach ($faults as $f) {
            $out[$f] = $this->resolve($f, $severities[$f] ?? null, $cfg);
        }
        return $out;
    }

    /** Is `$tier` at least as critical as `$min`? Used to stop cosmetic-only work justifying a car move. */
    public function atLeast(string $tier, string $min, array $cfg): bool
    {
        $order = (array) ($cfg['order'] ?? []);
        $a = array_search($tier, $order, true);
        $b = array_search($min, $order, true);
        return $a !== false && $b !== false && $a <= $b;
    }

    /** The most critical tier present, or null. */
    public function highest(array $tiers, array $cfg): ?string
    {
        foreach ((array) ($cfg['order'] ?? []) as $t) {
            if (in_array($t, $tiers, true)) {
                return $t;
            }
        }
        return null;
    }
}
