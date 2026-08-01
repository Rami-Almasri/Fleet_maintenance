<?php

namespace App\Services;

use App\Services\Garage\FaultCriticality;

/**
 * The multi-fault DECISION layer that sits on top of GarageRecommendationService's ranking.
 *
 * A ranked list answers "which garage is best overall?" — but a car with an engine knock AND accident
 * body damage has no single best garage, and averaging the two just hides the weak half. An expert fleet
 * manager doesn't read the average; they ask "can one shop actually do all of this, and if not, is
 * splitting worth the extra vehicle move?". This class asks exactly that.
 *
 * It scores TWO competing plans and picks one:
 *
 *   SINGLE — send the whole ticket to the top-ranked garage. Preferred by default: one pickup, one
 *            drop-off, one invoice, one point of accountability.
 *   SPLIT  — send each fault to the garage strongest on it. Only recommended when EVERY gate in
 *            config('garage_recommendation.strategy') passes: the faults are independent trades, the
 *            single plan is genuinely weak somewhere, each leg rests on real evidence, and the gain
 *            clears `min_gain`.
 *
 * A plan's confidence is its WEAKEST fault, never the mean — a plan is as strong as its weakest link,
 * and it is precisely the averaging of a strong fault with a hopeless one that this class exists to stop.
 *
 * The output is a recommendation with a stated tradeoff, not a verdict: it always reports both plans and
 * why the loser lost, so the supervisor can overrule with the same facts in front of them. The split plan
 * is directly actionable — assign-dispatch takes `fault_ids` for the first leg and assign-pending routes
 * the rest (see [[split-dispatch-feature]]).
 *
 * PURE: no DB, no config() calls, no facades. Everything is injected, so it unit-tests standalone.
 * See [[garage-recommendation-engine]].
 */
class GarageAssignmentStrategy
{
    /**
     * Decide between one garage and a split, and explain the call.
     *
     * @param  array<int, array{vendor_id:int, garage:string, match_score:int, fault_points:array<string,float>, fault_evidence:array<string,int>}>  $garages  every scored garage (leg candidates)
     * @param  array<int, array{vendor_id:int, garage:string, match_score:int}>  $primary  the ranked list; [0] is the single-garage plan
     * @param  array<int, string>  $faults  the ticket's fault category keys
     * @param  array<string, string>  $catLabels
     * @param  array<string, mixed>  $cfg  config('garage_recommendation.strategy')
     * @return array<string, mixed>|null  null when there is nothing to decide (0–1 faults, or no candidates)
     */
    public function decide(array $garages, array $primary, array $faults, array $catLabels, array $cfg, array $ctx = []): ?array
    {
        $faults = array_values(array_unique($faults));
        $crit = (array) ($ctx['criticality'] ?? []);
        $critCfg = (array) ($ctx['criticality_cfg'] ?? []);

        if (empty($cfg['enabled']) || empty($primary) || empty($garages)
            || count($faults) < (int) ($cfg['min_faults_to_split'] ?? 2)) {
            // Even with nothing to split, a shortlist still deserves a business trade-off verdict — and
            // the same executive summary, so the UI never has to handle a decision without one.
            $only = $this->businessOnly($primary, (array) ($ctx['business_cfg'] ?? []));
            if ($only !== null) {
                $only['summary'] = $this->summarise($only, $primary, $crit, $catLabels);
            }
            return $only;
        }

        $byVendor = [];
        foreach ($garages as $g) {
            $byVendor[$g['vendor_id']] = $g;
        }

        // ── Plan A: everything to the top-ranked garage ────────────────────────────────────────────
        $top = $primary[0];
        $single = $byVendor[$top['vendor_id']] ?? null;
        if ($single === null) {
            return null;
        }
        $singleLeg = $this->leg($single, $faults, $catLabels, $crit);
        $singleConf = $singleLeg['confidence'];

        // ── Plan B: each fault to whoever is strongest on it ───────────────────────────────────────
        $pick = [];              // category_key => vendor_id
        foreach ($faults as $cat) {
            $best = null;
            foreach ($garages as $g) {
                $p = (float) ($g['fault_points'][$cat] ?? 0);
                // Strongest on THIS fault; ties fall to the better all-rounder so we don't split needlessly.
                if ($best === null || $p > $best['p'] || ($p === $best['p'] && $g['match_score'] > $best['m'])) {
                    $best = ['p' => $p, 'm' => $g['match_score'], 'vendor_id' => $g['vendor_id']];
                }
            }
            $pick[$cat] = $best['vendor_id'];
        }

        // Group the picks into legs (one garage, the faults it takes), best leg first.
        $grouped = [];
        foreach ($pick as $cat => $vid) {
            $grouped[$vid][] = $cat;
        }
        $splitLegs = [];
        foreach ($grouped as $vid => $cats) {
            $splitLegs[] = $this->leg($byVendor[$vid], $cats, $catLabels, $crit);
        }
        usort($splitLegs, fn ($a, $b) => $b['confidence'] <=> $a['confidence']);
        $splitConf = empty($splitLegs) ? 0 : min(array_column($splitLegs, 'confidence'));

        $splitOption = [
            'legs'       => $splitLegs,
            'confidence' => $splitConf,
            'garages'    => count($splitLegs),
        ];
        $singleOption = [
            'leg'        => $singleLeg,
            'confidence' => $singleConf,
            'weakest'    => $singleLeg['weakest'],
        ];

        // ── The gates. Each is a named, reportable reason NOT to split. ────────────────────────────
        // The raw confidence gain is CHARGED for the extra day off the road a second move costs, so a
        // marginal quality gain can never win by pretending downtime is free.
        $rawGain = $splitConf - $singleConf;
        $downtime = (int) round((float) ($cfg['split_downtime_days'] ?? 1.0) * (float) ($cfg['downtime_points_per_day'] ?? 8));
        $gain = $rawGain - $downtime;
        $reject = $this->rejectReason($splitLegs, $singleLeg, $gain, $faults, $pick, $cfg, $crit, $critCfg);

        $decision = $reject === null
            ? $this->splitDecision($splitOption, $singleOption, $gain, $rawGain, $downtime, $cfg)
            : $this->singleDecision($singleOption, $splitOption, $gain, $rawGain, $downtime, $reject, $cfg);

        // The business trade-off rides alongside the technical verdict: even when we keep the ticket in
        // one place, the supervisor should see whether a near-equal garage is materially cheaper/faster.
        $decision['business_tradeoff'] = $this->businessTradeoff($primary, (array) ($ctx['business_cfg'] ?? []));
        $decision['summary'] = $this->summarise($decision, $primary, $crit, $catLabels);

        return $decision;
    }

    /**
     * The executive summary — the whole decision in four lines, for someone who has ten seconds.
     *
     * Built HERE rather than in the UI on purpose: it is the sentence the supervisor acts on and the one
     * that gets stored in the audit trail, so it has to be identical in both places and generated from
     * the same facts as the decision itself. A summary the frontend assembles is a second, unversioned
     * implementation of the reasoning.
     *
     * @return array<string, mixed>
     */
    private function summarise(array $decision, array $primary, array $crit, array $catLabels): array
    {
        $top = $primary[0] ?? null;
        $biz = $decision['business_tradeoff'] ?? null;
        $split = $decision['mode'] === 'split';

        // Which fault is actually driving this? The heaviest one — that is the tie-breaker the whole
        // criticality layer exists to apply, so it should be the reason we quote.
        $driver = null;
        foreach ($crit as $cat => $c) {
            if ($driver === null || $c['weight'] > $driver['weight']) {
                $driver = $c + ['category_key' => $cat];
            }
        }

        $final = $split
            ? implode(' + ', array_column($decision['legs'], 'garage'))
            : ($decision['legs'][0]['garage'] ?? $top['garage'] ?? null);

        // The one-line justification, in priority order: a split, then a declined business trade-off,
        // then the plain technical verdict.
        if ($split) {
            $reason = 'Each fault goes to the garage with the proven record for it; the gain outweighs the extra vehicle move.';
        } elseif ($biz) {
            // Name the fault that actually carried the decision — "the critical engine fault outweighs
            // the saving" is a reason; "the technical score was higher" is a restatement.
            $faultLabel = $driver ? ($catLabels[$driver['category_key']] ?? $driver['category_key']) : null;
            $reason = ($driver && ($driver['tier'] ?? null) !== 'cosmetic' && $faultLabel)
                ? "The {$driver['label']} {$faultLabel} fault outweighs the potential saving at {$biz['candidate']}."
                : "The repair record at {$final} outweighs the potential saving at {$biz['candidate']}.";
        } else {
            $reason = $decision['reason'] ?? 'Best available repair record for these faults.';
        }

        return [
            'technical' => $top ? [
                'garage'      => $top['garage'],
                'vendor_id'   => $top['vendor_id'],
                'match_score' => $top['match_score'],
            ] : null,
            'business' => $biz ? [
                'garage'    => $biz['candidate'],
                'vendor_id' => $biz['candidate_vendor_id'],
                'advantages'=> $biz['advantages'],
                'summary'   => $biz['summary'],
            ] : null,
            'final'        => $final,
            'final_mode'   => $decision['mode'],
            'reason'       => $reason,
            // Named so the UI can badge it: the decision either agreed with both axes or chose between them.
            'axes_agree'   => $biz === null,
        ];
    }

    /**
     * The single-fault case: nothing to split, but the shortlist can still hide a better operational
     * call. Returns a `single` decision whose only content is the business comparison.
     */
    private function businessOnly(array $primary, array $bCfg): ?array
    {
        if (empty($primary)) {
            return null;
        }
        $tradeoff = $this->businessTradeoff($primary, $bCfg);
        $top = $primary[0];

        // A decision is returned even when there is no trade to weigh. The summary is the block the
        // supervisor reads first, and a recommendation that silently drops it on the commonest case —
        // one fault, one obvious garage — would be missing precisely when it is easiest to trust.
        return [
            'mode'              => 'single',
            'headline'          => "Send the ticket to {$top['garage']} ({$top['match_score']}/100).",
            'reason'            => 'Only one fault on this ticket, so there is nothing to split.',
            'tradeoff'          => $tradeoff['summary'] ?? 'No cheaper or faster alternative is worth the trade.',
            'why_not'           => $tradeoff['detail'] ?? null,
            'legs'              => [],
            'confidence'        => null,
            'confidence_single' => null,
            'confidence_gain'   => null,
            'single_option'     => null,
            'split_option'      => null,
            'rejected_reason'   => 'single_fault',
            'actionable_note'   => null,
            'business_tradeoff' => $tradeoff,
        ];
    }

    /**
     * "Garage A scores 95, Garage B scores 93 — but B is cheaper, free now and a day faster." This is
     * the comparison the brief asked for, and it deliberately does NOT auto-switch the pick unless the
     * technical gap is small enough to be noise (`max_technical_gap`) AND the business advantage is
     * material (`min_business_gain`). Otherwise it is surfaced as a trade-off for a human to make.
     *
     * @return array<string, mixed>|null
     */
    private function businessTradeoff(array $primary, array $bCfg): ?array
    {
        if (empty($bCfg['enabled']) || count($primary) < 2) {
            return null;
        }
        $top = $primary[0];
        $topBiz = $top['business']['score'] ?? null;
        if ($topBiz === null) {
            return null;
        }

        $maxGap = (int) ($bCfg['max_technical_gap'] ?? 5);
        $minGain = (int) ($bCfg['min_business_gain'] ?? 15);

        $best = null;
        foreach (array_slice($primary, 1) as $c) {
            $biz = $c['business']['score'] ?? null;
            if ($biz === null || empty($c['business']['badges'])) {
                continue;   // no measured advantage worth naming
            }
            $techGap = $top['match_score'] - $c['match_score'];
            $bizGain = $biz - $topBiz;
            if ($techGap > $maxGap || $bizGain < $minGain) {
                continue;
            }
            if ($best === null || $bizGain > $best['business_gain']) {
                $best = ['candidate' => $c, 'technical_gap' => $techGap, 'business_gain' => $bizGain];
            }
        }
        if ($best === null) {
            return null;
        }

        $c = $best['candidate'];
        $advantages = array_map(fn ($b) => $this->badgePhrase($b, $c), $c['business']['badges']);

        return [
            'candidate_vendor_id' => $c['vendor_id'],
            'candidate'           => $c['garage'],
            'technical_gap'       => $best['technical_gap'],
            'business_gain'       => $best['business_gain'],
            'advantages'          => $advantages,
            'summary'             => "{$c['garage']} scores {$best['technical_gap']} points lower technically ({$c['match_score']} vs {$top['match_score']}) but is "
                . $this->joinAnd($advantages) . '.',
            'detail'              => "Technical evidence favours {$top['garage']}; operationally {$c['garage']} is the cheaper/faster call. "
                . 'Worth overriding only if the saving matters more than the repair record on these faults.',
            'auto_switched'       => false,   // the pick is never silently changed — the supervisor decides
        ];
    }

    private function badgePhrase(string $badge, array $row): string
    {
        $o = (array) ($row['outcomes'] ?? []);
        return match ($badge) {
            'cheapest'          => 'cheaper (AED ' . (int) ($o['cost_aed']['value'] ?? 0) . ')',
            'fastest'           => 'faster (' . ($o['duration_days']['value'] ?? '?') . ' days)',
            'available_soonest' => 'available sooner (starts in ' . ($o['start_in_days'] ?? '?') . ' days)',
            'no_queue'          => 'free right now',
            default             => $badge,
        };
    }

    /**
     * First failed gate, or null if a split is warranted. Ordered so the reported reason is the most
     * informative one ("they're the same trade" beats "the gain was small").
     */
    private function rejectReason(array $splitLegs, array $singleLeg, int $gain, array $faults, array $pick, array $cfg, array $crit, array $critCfg): ?string
    {
        if (count($splitLegs) < 2) {
            // Distinct from `single_covers_all`: this is "no rival is better on ANY fault", which is a
            // different claim from "our pick is strong enough on all of them". Conflating the two
            // would have us tell the supervisor the coverage is fine when it may well not be.
            return 'no_better_specialist';
        }
        if (count($splitLegs) > (int) ($cfg['max_legs'] ?? 2)) {
            return 'too_many_legs';          // never send one car on a tour
        }
        if (! $this->legsAreIndependent($splitLegs, $cfg)) {
            return 'same_domain';            // same trade / same bay — one visit does both
        }

        // CRITICALITY: a leg carrying only cosmetic work never justifies moving the car. The paint can
        // wait for the next visit; that is a scheduling decision, not a routing one.
        $minTier = (string) ($cfg['leg_min_tier'] ?? 'operational');
        $fc = new FaultCriticality();
        foreach ($splitLegs as $leg) {
            $tiers = array_column($leg['faults'], 'criticality');
            $topTier = $fc->highest(array_filter($tiers), $critCfg);
            if ($topTier !== null && ! $fc->atLeast($topTier, $minTier, $critCfg)) {
                return 'cosmetic_leg';
            }
        }

        // How weak the single plan must be to justify splitting depends on WHAT is weak: a safety-critical
        // fault is worth moving the car over far sooner than a cosmetic one.
        $weak = $singleLeg['weakest'];
        $byTier = (array) ($cfg['weak_fault_max_by_tier'] ?? []);
        $threshold = (int) ($byTier[$weak['criticality'] ?? ''] ?? ($cfg['weak_fault_max'] ?? 55));
        if ($weak['pct'] > $threshold) {
            return 'single_covers_all';      // no gap that matters at this fault's criticality
        }

        $minEvidence = (int) ($cfg['min_leg_evidence'] ?? 3);
        foreach ($splitLegs as $leg) {
            if ($leg['evidence'] < $minEvidence) {
                return 'thin_evidence';      // a leg resting on one or two jobs is not a specialist
            }
        }
        if ($gain < (int) ($cfg['min_gain'] ?? 15)) {
            return 'gain_below_threshold';   // not worth the extra vehicle move, downtime included
        }
        return null;
    }

    /**
     * Do the legs sit in genuinely different trades? Each leg is mapped to the domain groups its faults
     * belong to; if two legs share a group we'd be splitting work one shop could do in a single visit.
     * A fault outside every configured group is treated as its own domain (unknown ≠ shared).
     */
    private function legsAreIndependent(array $legs, array $cfg): bool
    {
        $groups = (array) ($cfg['independent_domains'] ?? []);
        $seen = [];
        foreach ($legs as $i => $leg) {
            foreach ($leg['faults'] as $f) {
                $domain = "_ungrouped:{$f['category_key']}";
                foreach ($groups as $name => $keys) {
                    if (in_array($f['category_key'], (array) $keys, true)) {
                        $domain = $name;
                        break;
                    }
                }
                if (isset($seen[$domain]) && $seen[$domain] !== $i) {
                    return false; // this trade is spread across two legs
                }
                $seen[$domain] = $i;
            }
        }
        return true;
    }

    // ── Decisions ─────────────────────────────────────────────────────────────────────────────────

    private function splitDecision(array $split, array $single, int $gain, int $rawGain, int $downtime, array $cfg): array
    {
        $legs = $split['legs'];
        $parts = array_map(
            fn ($l) => "{$l['garage']} for " . $this->joinLabels($l) . " ({$l['match_score']}/100)",
            $legs
        );
        $singleLeg = $single['leg'];
        $weak = $singleLeg['weakest'];
        $tier = $weak['criticality_label'] ? " ({$weak['criticality_label']})" : '';
        $days = (float) ($cfg['split_downtime_days'] ?? 1.0);

        return [
            'mode'      => 'split',
            'headline'  => 'Send to ' . $this->joinAnd($parts) . '.',
            'reason'    => "No single garage covers all faults strongly — {$singleLeg['garage']} is the best all-rounder but has only "
                . "{$weak['pct']}% coverage on {$weak['label']}{$tier}. Splitting raises confidence from {$single['confidence']}% to {$split['confidence']}%"
                . " (+{$rawGain}), still +{$gain} after charging {$downtime} points for the extra {$days} day off the road.",
            'tradeoff'  => "Costs a second vehicle move, roughly {$days} extra day of downtime and a second invoice; in exchange each fault goes to the shop with the proven record for it.",
            'why_not'   => "Sending everything to {$singleLeg['garage']} would leave {$weak['label']}{$tier} with its weakest coverage ({$weak['pct']}%) — the fault most likely to come back.",
            'legs'      => $legs,
            'confidence'         => $split['confidence'],
            'confidence_single'  => $single['confidence'],
            'confidence_gain'    => $gain,
            'confidence_gain_raw'=> $rawGain,
            'downtime_penalty'   => $downtime,
            'single_option'      => $single,
            'split_option'       => $split,
            'rejected_reason'    => null,
            'actionable_note'    => 'Dispatch the first leg with its faults selected; the rest stay in Pending Assignment for the second garage.',
        ];
    }

    private function singleDecision(array $single, array $split, int $gain, int $rawGain, int $downtime, string $reject, array $cfg): array
    {
        $leg = $single['leg'];
        $weak = $leg['weakest'];
        $minGain = (int) ($cfg['min_gain'] ?? 15);
        $tier = $weak['criticality_label'] ? " ({$weak['criticality_label']})" : '';

        // Why we are NOT splitting — the specific gate that failed, in the operator's language.
        $why = match ($reject) {
            'single_covers_all'    => "{$leg['garage']} has a good enough record on every fault that matters here (weakest: {$weak['label']}{$tier} at {$weak['pct']}%), so one visit does the whole job.",
            'no_better_specialist' => "No other garage has a stronger record on any fault on this ticket, so there is nothing to split — {$leg['garage']} is the best available option for all of them"
                . ($weak['pct'] < 60 ? ", though its {$weak['label']}{$tier} coverage is only {$weak['pct']}%. Watch that fault at re-inspection." : '.'),
            'same_domain'          => 'The faults are the same kind of work — one workshop handles them in a single visit; splitting would move the car for nothing.',
            'thin_evidence'        => 'The alternative specialists have too little history on these faults to justify a second vehicle move.',
            'too_many_legs'        => 'Covering every fault by its best specialist would mean more than two garages — too much vehicle movement for the gain.',
            'cosmetic_leg'         => 'The only work a second garage would do better is cosmetic — not worth taking the car off the road again. Schedule it with the next visit instead.',
            'gain_below_threshold' => "Splitting would raise confidence from {$single['confidence']}% to {$split['confidence']}% (+{$rawGain}), but the extra vehicle move costs {$downtime} points of downtime, leaving +{$gain} — under the {$minGain}-point bar.",
            default                => 'One garage is the better operational call here.',
        };

        return [
            'mode'      => 'single',
            'headline'  => "Send the whole ticket to {$leg['garage']} ({$leg['match_score']}/100).",
            'reason'    => $why,
            'tradeoff'  => $reject === 'single_covers_all'
                ? 'One pickup, one invoice, one point of accountability.'
                : "Accepts weaker coverage on {$weak['label']}{$tier} ({$weak['pct']}%) in exchange for a single visit — watch that fault at re-inspection.",
            'why_not'   => count($split['legs']) > 1
                ? 'Splitting across ' . implode(' + ', array_column($split['legs'], 'garage')) . " would score {$split['confidence']}% vs {$single['confidence']}% — not enough to pay for the move."
                : 'No alternative garage is stronger on any individual fault.',
            'legs'      => [$leg],
            'confidence'         => $single['confidence'],
            'confidence_single'  => $single['confidence'],
            'confidence_gain'    => $gain,
            'confidence_gain_raw'=> $rawGain,
            'downtime_penalty'   => $downtime,
            'single_option'      => $single,
            'split_option'       => $split,
            'rejected_reason'    => $reject,
            'actionable_note'    => null,
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────────────────────────

    /**
     * One leg of a plan: a garage and the faults it would take, with the per-fault coverage that
     * justifies it. `confidence` is the weakest fault in the leg, `evidence` the thinnest fault's job
     * count — both deliberately worst-case.
     *
     * @param  array<int, string>  $cats
     */
    private function leg(array $g, array $cats, array $catLabels, array $crit = []): array
    {
        $faults = [];
        foreach ($cats as $cat) {
            $c = $crit[$cat] ?? [];
            $faults[] = [
                'category_key' => $cat,
                'label'        => $catLabels[$cat] ?? $cat,
                'pct'          => (int) round(((float) ($g['fault_points'][$cat] ?? 0)) * 100),
                'jobs'         => (int) ($g['fault_evidence'][$cat] ?? 0),
                'criticality'  => $c['tier'] ?? null,
                'criticality_label' => $c['label'] ?? null,
                'weight'       => (float) ($c['weight'] ?? 1.0),
            ];
        }
        // The "weakest link" is criticality-adjusted: a 50%-covered brake fault is a bigger problem than
        // a 50%-covered scratch, so the heavier fault wins ties and pulls the plan's confidence down.
        usort($faults, fn ($a, $b) => [$a['pct'], -$a['weight']] <=> [$b['pct'], -$b['weight']]);
        $weakest = $faults[0] ?? ['label' => '—', 'pct' => 0, 'criticality' => null, 'criticality_label' => null];
        $pcts = array_column($faults, 'pct') ?: [0];

        return [
            'vendor_id'   => $g['vendor_id'],
            'garage'      => $g['garage'],
            'match_score' => $g['match_score'],
            'outcomes'    => $g['outcomes'] ?? null,
            'faults'      => $faults,
            'confidence'  => min($pcts),
            'evidence'    => min(array_column($faults, 'jobs') ?: [0]),
            'weakest'     => [
                'label'             => $weakest['label'],
                'pct'               => $weakest['pct'],
                'criticality'       => $weakest['criticality'] ?? null,
                'criticality_label' => $weakest['criticality_label'] ?? null,
            ],
        ];
    }

    private function joinLabels(array $leg): string
    {
        return $this->joinAnd(array_map(fn ($f) => $f['label'], $leg['faults']));
    }

    /** @param array<int, string> $parts */
    private function joinAnd(array $parts): string
    {
        if (count($parts) <= 1) {
            return $parts[0] ?? '';
        }
        $last = array_pop($parts);
        return implode(', ', $parts) . ' and ' . $last;
    }
}
