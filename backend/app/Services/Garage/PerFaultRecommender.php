<?php

namespace App\Services\Garage;

/**
 * A recommendation for EVERY fault, not one recommendation for the ticket.
 *
 * The ranked list answers "which garage is best overall?", but a supervisor looking at a car with an
 * engine knock, a dashboard fault and a scraped door does not think in overalls — they think fault by
 * fault, and they want to know whether the single garage they are about to pick is actually good at each
 * one. Rolling that up too early hides the trade-off; this layer keeps it visible.
 *
 * For each fault it names the strongest garage, the best genuine alternative, and — the part that makes
 * it useful — WHY the alternative did not win, in the terms that actually differ: faster but less
 * experience on this model, cheaper but weaker record, stronger on this fault but it would mean a second
 * vehicle move. Where the two are equivalent it says so rather than inventing a distinction.
 *
 * PURE: no DB, no config() calls, no facades — everything is injected, so it unit-tests standalone.
 * See [[garage-recommendation-engine]].
 */
class PerFaultRecommender
{
    /** Below this coverage a garage is not a credible answer for the fault at all. */
    private const MIN_CREDIBLE_COVERAGE = 0.10;

    /**
     * @param  array<int, array<string, mixed>>  $garages     every scored garage bucket
     * @param  array<int, string>  $faults                    the ticket's fault categories
     * @param  array<string, array<string, mixed>>  $crit      category => criticality
     * @param  array<string, string>  $catLabels
     * @param  array<int, array<string, mixed>>  $outcomes     vendor id => forecast row
     * @param  array<int, array<string, mixed>>  $faultsDetail the ticket's symptoms, for human labels
     * @param  string  $modelLabel  the vehicle model, so evidence lines can name it ("6 repairs on YUKON")
     * @return array<int, array<string, mixed>>
     */
    public function recommend(array $garages, array $faults, array $crit, array $catLabels, array $outcomes, array $faultsDetail = [], string $modelLabel = ''): array
    {
        if (empty($faults) || empty($garages)) {
            return [];
        }

        // The operator wrote "Engine noise", not "Engine" — prefer their words where we have them.
        $symptoms = [];
        foreach ($faultsDetail as $d) {
            $key = $d['category_key'] ?? null;
            if ($key && ! isset($symptoms[$key])) {
                $symptoms[$key] = $d['symptom'] ?? null;
            }
        }

        $out = [];
        foreach ($faults as $cat) {
            $label = $catLabels[$cat] ?? $cat;
            $ranked = $this->rankForFault($garages, $cat, $outcomes, $label, $modelLabel);
            if (empty($ranked)) {
                continue;
            }
            $winner = $ranked[0];
            $alternative = $this->pickAlternative($ranked, $winner);
            $c = $crit[$cat] ?? [];

            $out[] = [
                'category_key'      => $cat,
                'label'             => $label,
                'symptom'           => $symptoms[$cat] ?? $label,
                'criticality'       => $c['tier'] ?? null,
                'criticality_label' => $c['label'] ?? null,
                'weight'            => (float) ($c['weight'] ?? 1.0),
                'winner'            => $winner,
                'alternative'       => $alternative,
                // Prose, for the audit trail and for anyone who wants the sentence.
                'reason'            => $this->winnerReason($winner, $alternative, $label),
                'tradeoff'          => $alternative ? $this->alternativeReason($winner, $alternative, $label) : null,
                // The same reasoning as scannable bullets. A supervisor deciding in ten seconds reads
                // ticks and minuses, not sentences — so the card leads with these and keeps the prose
                // underneath for the cases where the nuance matters.
                'winner_points'     => $this->winnerPoints($winner, $alternative, $label, $modelLabel),
                'alt_pros'          => $alternative ? $this->pros($alternative, $winner) : [],
                'alt_cons'          => $alternative ? $this->cons($alternative, $winner, $label, $modelLabel) : [],
                // Money and operations, side by side for THIS fault — the comparison the supervisor is
                // actually making. Kept as data (not a sentence) so the UI can put the two prices next
                // to each other and the difference between them beneath.
                'cost_compare'      => $costCompare = $this->costCompare($winner, $alternative),
                // The one sentence that names both garages and both sides of the trade. Built here, not
                // in the UI, so the screen and the audit trail cannot tell two different stories.
                'verdict'           => $alternative ? $this->verdict($winner, $alternative, $label) : null,
                // DECISION FACTORS: the same trade as two positive lists rather than one sentence. A
                // supervisor with thirty seconds reads two columns of ticks; the sentence is for the
                // one who wants the nuance. Both sides are stated as strengths — a comparison where
                // only one side gets ticks is advocacy, and the alternative is a real option.
                'factors'           => [
                    'winner'      => $alternative ? $this->advantages($winner, $alternative, $label) : [],
                    'alternative' => $alternative ? $this->advantages($alternative, $winner, $label) : [],
                ],
                // Six words for the scan table — the whole reason, compressed to what fits in a row.
                'short_reason'      => $this->shortReason($winner, $alternative, $label),
                // How much the price comparison itself can be leaned on. Two garages compared at
                // garage-average level is a much weaker claim than two compared on this exact fault,
                // and presenting both as "the cheaper one" hides that difference.
                'cost_confidence'   => $this->costConfidence($costCompare),
            ];
        }

        return $out;
    }

    /**
     * Garages ranked for ONE fault: coverage of that fault first, overall fit as the tie-break. Ranking
     * on the ticket-wide score here would defeat the purpose — a garage can be the best all-rounder and
     * still be the wrong shop for this particular fault.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rankForFault(array $garages, string $cat, array $outcomes, string $label, string $modelLabel): array
    {
        $rows = [];
        foreach ($garages as $g) {
            $points = (float) ($g['breakdown']['fault_points'][$cat]['points'] ?? 0);
            if ($points < self::MIN_CREDIBLE_COVERAGE) {
                continue;   // no meaningful history for this fault — not an answer, just a name
            }
            $ev = $g['breakdown']['fault_points'][$cat];
            $o = $outcomes[$g['vendor_id']] ?? [];

            $rows[] = [
                'vendor_id'    => $g['vendor_id'],
                'garage'       => $g['garage'],
                'coverage_pct' => (int) round($points * 100),
                'tier'         => $ev['tier'] ?? 'none',
                'same_model'   => (int) ($ev['same_model'] ?? 0),
                'at_garage'    => (int) ($ev['at_garage'] ?? 0),
                'match_score'  => (int) ($g['match_score'] ?? 0),
                // The records the coverage figure was computed from, in words. A percentage a supervisor
                // cannot trace back to a count of repairs is exactly the magic number this panel exists
                // to eliminate — so the basis travels WITH the number, not behind a click.
                'evidence'     => $this->evidenceLines($ev, $label, $modelLabel),
                // The forecasts a supervisor compares on, carried through with their basis intact.
                'duration_days' => $o['duration_days'] ?? null,
                'duration_p90'  => $o['duration_p90'] ?? null,
                'success_pct'   => $o['success_pct'] ?? null,
                'comeback_pct'  => $o['comeback_pct'] ?? null,
                'cost_aed'      => $o['cost_aed'] ?? null,
                // The price for THIS fault at this garage, when the ledger actually supports one.
                // Absent rather than borrowed: a category price nobody earned is worse than none.
                'fault_cost'    => $this->faultCost($o['cost_by_fault'] ?? [], $cat),
                'queue_open'    => $o['queue_open']['value'] ?? null,
                'start_in_days' => $o['start_in_days'] ?? null,
                'confidence'    => $this->confidenceFor($ev),
            ];
        }

        usort($rows, fn ($a, $b) => [$b['coverage_pct'], $b['match_score']] <=> [$a['coverage_pct'], $a['match_score']]);

        return $rows;
    }

    /**
     * The best genuine alternative — the runner-up, but only when it is a real option. A garage that is
     * far behind on this fault is not an alternative, it is filler, and offering it as a choice invites
     * a worse decision.
     *
     * @return array<string, mixed>|null
     */
    private function pickAlternative(array $ranked, array $winner): ?array
    {
        foreach (array_slice($ranked, 1) as $candidate) {
            $behind = $winner['coverage_pct'] - $candidate['coverage_pct'];
            $betterSomewhere = $this->isFaster($candidate, $winner)
                || $this->isCheaper($candidate, $winner)
                || $this->isMoreAvailable($candidate, $winner)
                || $this->isSafer($candidate, $winner);

            // Either close enough on evidence to be a fair swap, or clearly better on something the
            // supervisor cares about. Anything else is noise.
            if ($behind <= 25 || $betterSomewhere) {
                return $candidate;
            }
        }
        return null;
    }

    /** Why the winner won, in the strongest fact available. */
    private function winnerReason(array $w, ?array $alt, string $label): string
    {
        if ($w['same_model'] > 0) {
            $base = "{$w['same_model']} previous {$label} repair" . ($w['same_model'] === 1 ? '' : 's') . ' on this exact model';
        } elseif ($w['at_garage'] > 0) {
            $base = "{$w['at_garage']} previous {$label} repair" . ($w['at_garage'] === 1 ? '' : 's') . ', though none on this model';
        } else {
            $base = 'The strongest available record for this fault';
        }

        if ($alt === null) {
            return $base . ' — no other garage has a comparable record here.';
        }
        return $base . '.';
    }

    /**
     * Why the alternative did not win — stated in the dimension that actually differs. "Slightly faster,
     * but less experience with this model" is a decision; "lower score" is not.
     */
    private function alternativeReason(array $w, array $alt, string $label): string
    {
        $pros = [];
        $cons = [];

        if ($this->isFaster($alt, $w)) {
            $pros[] = 'about ' . $this->round($w['duration_days']['value'] - $alt['duration_days']['value']) . ' day(s) faster';
        }
        if ($this->isCheaper($alt, $w)) {
            $pros[] = 'roughly AED ' . (int) round($w['cost_aed']['value'] - $alt['cost_aed']['value']) . ' cheaper';
        }
        if ($this->isMoreAvailable($alt, $w)) {
            $pros[] = 'able to start sooner';
        }
        if ($this->isSafer($alt, $w)) {
            $pros[] = 'a better track record on repairs holding';
        }

        $gap = $w['coverage_pct'] - $alt['coverage_pct'];
        if ($gap > 0) {
            $cons[] = $alt['same_model'] === 0 && $w['same_model'] > 0
                ? "no {$label} repairs on this model (the recommended garage has {$w['same_model']})"
                : "less {$label} experience ({$alt['coverage_pct']}% coverage vs {$w['coverage_pct']}%)";
        }

        if (empty($pros) && empty($cons)) {
            return 'Closely matched on the evidence and on cost, speed and availability.';
        }
        if (empty($pros)) {
            return 'Behind on ' . implode(' and ', $cons) . ', with no offsetting advantage.';
        }
        if (empty($cons)) {
            return ucfirst(implode(', ', $pros)) . ', and just as well proven on this fault.';
        }
        return ucfirst(implode(', ', $pros)) . ', but ' . implode(' and ', $cons) . '.';
    }

    /**
     * The two prices for this fault, side by side, each carrying the grain it came from.
     *
     * The per-fault price is preferred over the garage-wide one wherever it exists — comparing "engine
     * work here runs 700" against "that garage bills 550 on average" is comparing two different
     * questions, so the grain of each side travels with it and the UI can show when they differ.
     *
     * @return array{winner:?array<string,mixed>, alternative:?array<string,mixed>, delta:?float, cheaper:?string, comparable:bool}
     */
    private function costCompare(array $w, ?array $alt): array
    {
        // Each side DISPLAYS the most specific price it holds — that is the number worth knowing.
        $show = ['winner' => $this->costFor($w), 'alternative' => $alt ? $this->costFor($alt) : null];

        // ...but the DIFFERENCE is only meaningful at a grain both sides share. A fault-level price and
        // a garage-wide average answer different questions, and subtracting them manufactures a saving:
        // "engine work here runs 700" against "that garage bills 300 on average" is not a 400 saving,
        // it is a category compared with a fleet-of-work average.
        $comparison = null;
        if ($alt) {
            foreach (['fault', 'garage'] as $grain) {
                $a = $this->costAt($w, $grain);
                $b = $this->costAt($alt, $grain);
                if ($a === null || $b === null) {
                    continue;
                }
                $delta = round($b['value'] - $a['value'], 0);
                $comparison = [
                    'grain'       => $grain,
                    'winner'      => $a,
                    'alternative' => $b,
                    'delta'       => $delta,
                    // Below 15% the two are "about the same"; naming a winner there is noise.
                    'cheaper'     => abs($delta) / max($a['value'], 1) < 0.15
                        ? 'same' : ($delta < 0 ? 'alternative' : 'winner'),
                ];
                break;   // deepest shared grain wins
            }
        }

        return [
            'winner'      => $show['winner'] ? $show['winner'] + ['garage' => $w['garage']] : null,
            'alternative' => $show['alternative'] && $alt ? $show['alternative'] + ['garage' => $alt['garage']] : null,
            'comparison'  => $comparison,
            // True when the two displayed prices are the same KIND of figure. When false the UI must not
            // let them read as a like-for-like price difference.
            'same_grain'  => $show['winner'] && $show['alternative']
                && $this->grainOf($show['winner']['basis']) === $this->grainOf($show['alternative']['basis']),
        ];
    }

    /**
     * The most specific price we hold for this fault at this garage: the fault-level figure when the
     * ledger earned one, otherwise the garage's overall bill.
     *
     * @return array{value:float, basis:string, sample:int}|null
     */
    private function costFor(array $g): ?array
    {
        return $this->costAt($g, 'fault') ?? $this->costAt($g, 'garage');
    }

    /**
     * This garage's price at ONE specific grain, or null if it holds none there. Used to force both
     * sides of a comparison onto the same footing before any difference is taken.
     *
     * @return array{value:float, basis:string, sample:int}|null
     */
    private function costAt(array $g, string $grain): ?array
    {
        if ($grain === 'fault') {
            $fc = $g['fault_cost'] ?? null;
            return $fc && ($fc['value'] ?? null) !== null
                ? ['value' => (float) $fc['value'], 'basis' => (string) $fc['basis'], 'sample' => (int) $fc['sample']]
                : null;
        }

        $c = $g['cost_aed'] ?? null;
        // A fleet median is the same number for every garage — it can display, but it can never ground
        // a comparison, because a "saving" measured against it is an artefact of the fallback.
        return $c && ($c['value'] ?? null) !== null && ! in_array($c['basis'], ['fleet', 'unavailable'], true)
            ? ['value' => (float) $c['value'], 'basis' => (string) $c['basis'], 'sample' => (int) $c['sample']]
            : null;
    }

    /** Which rung of the ladder a basis belongs to — model and fault detail are the same KIND of claim. */
    private function grainOf(string $basis): string
    {
        return in_array($basis, ['garage_fault', 'garage_fault_model'], true) ? 'fault' : $basis;
    }

    /**
     * The trade-off in one sentence, naming both garages and both sides.
     *
     * Deliberately never resolves to "so pick the cheaper one". Cost is one axis among four, and the
     * decision belongs to the supervisor — the engine's job is to make the exchange rate visible, not to
     * spend the money. Where a garage leads on everything measurable, the sentence says that plainly
     * rather than manufacturing a trade that does not exist.
     */
    private function verdict(array $w, array $alt, string $label): string
    {
        $altSide = $this->advantages($alt, $w, $label);
        $winSide = $this->advantages($w, $alt, $label);

        if (empty($altSide)) {
            return "{$w['garage']} leads on every measure we can compare for this fault.";
        }
        if (empty($winSide)) {
            return "{$alt['garage']} is " . $this->join($altSide) . " — and nothing measurable favours {$w['garage']} here beyond its ranking.";
        }
        return "{$alt['garage']} is " . $this->join($altSide) . ", but {$w['garage']} is " . $this->join($winSide) . '.';
    }

    /**
     * The whole reason this garage won, compressed to something that fits in a table row.
     *
     * The scan table exists so a three-fault car can be read in one glance; a full sentence per row
     * defeats that. Two clauses maximum, and where nothing distinguishes the winner it says so honestly
     * rather than padding with "best match".
     */
    private function shortReason(array $w, ?array $alt, string $label): string
    {
        $parts = [];
        if ($alt === null) {
            return "only garage with a {$label} record";
        }
        if ($w['coverage_pct'] - $alt['coverage_pct'] >= 10) {
            $parts[] = "strongest {$label} history";
        }
        if ($this->isCheaper($w, $alt)) {
            $parts[] = 'cheaper';
        }
        if ($this->isFaster($w, $alt)) {
            $parts[] = 'faster';
        }
        if ($this->isSafer($w, $alt)) {
            $parts[] = 'repairs hold better';
        }
        if (empty($parts)) {
            return "narrowly ahead on {$label} history";
        }
        return implode(' + ', array_slice($parts, 0, 2));
    }

    /**
     * How much weight the PRICE COMPARISON can carry — which is a different question from how much
     * either price can.
     *
     * Two garages compared on this exact fault, each with real depth behind them, is a strong claim.
     * The same two compared on their all-work averages is a much weaker one, and the difference is
     * invisible in the number itself: "AED 250 cheaper" looks identical either way. Stating the level
     * stops every comparison being treated as equally solid.
     *
     * @return array{level:string, reason:string}
     */
    private function costConfidence(array $compare): array
    {
        $c = $compare['comparison'] ?? null;
        if ($c === null) {
            return [
                'level'  => 'none',
                'reason' => 'Cost cannot be compared here — one of the two garages has no price of its own, only the fleet average.',
            ];
        }

        $n = min((int) $c['winner']['sample'], (int) $c['alternative']['sample']);
        if ($c['grain'] === 'fault') {
            return $n >= 10
                ? ['level' => 'high', 'reason' => "Both garages have priced history for this specific fault ({$n}+ repairs each)."]
                : ['level' => 'medium', 'reason' => "Both prices are for this specific fault, but one rests on only {$n} repairs."];
        }

        return $n >= 30
            ? ['level' => 'medium', 'reason' => 'Both prices are comparable at garage level, but neither garage has enough priced history for this specific fault.']
            : ['level' => 'low', 'reason' => "Compared at garage level only, and one side rests on just {$n} priced repairs. Treat the difference as indicative."];
    }

    /**
     * What garage $a can honestly claim over $b, in the operator's terms. Every clause must rest on a
     * material, garage-specific difference — see the comparison helpers below.
     *
     * @return array<int, string>
     */
    private function advantages(array $a, array $b, string $label): array
    {
        $out = [];

        // Priced at the deepest grain BOTH sides hold, so the claim is like-for-like.
        [$ca, $cb] = $this->sharedGrain($a, $b);
        if ($ca && $cb && $cb['value'] > 0 && (($cb['value'] - $ca['value']) / $cb['value']) >= 0.15) {
            $out[] = 'cheaper (AED ' . number_format($ca['value']) . ' vs ' . number_format($cb['value']) . ')';
        }
        if ($this->isFaster($a, $b)) {
            $out[] = 'faster (' . $this->round($a['duration_days']['value']) . 'd vs ' . $this->round($b['duration_days']['value']) . 'd)';
        }
        if ($this->isSafer($a, $b)) {
            $out[] = 'better at making repairs hold (' . $a['success_pct']['value'] . '% vs ' . $b['success_pct']['value'] . '%)';
        }
        if ($this->isMoreAvailable($a, $b)) {
            $out[] = 'able to start sooner';
        }
        if ($a['coverage_pct'] - $b['coverage_pct'] >= 10) {
            $out[] = "more experienced with {$label} ({$a['coverage_pct']}% vs {$b['coverage_pct']}%)";
        }
        // Confidence is its own axis: two garages can show the same figure with very different amounts
        // of evidence behind it, and that difference is exactly what a supervisor is entitled to weigh.
        $rank = ['low' => 0, 'medium' => 1, 'high' => 2];
        if (($rank[$a['confidence']] ?? 0) > ($rank[$b['confidence']] ?? 0)) {
            $out[] = "backed by more evidence ({$a['confidence']} vs {$b['confidence']} confidence)";
        }

        return $out;
    }

    /** @param  array<int, string>  $parts */
    private function join(array $parts): string
    {
        if (count($parts) === 1) {
            return $parts[0];
        }
        $last = array_pop($parts);
        return implode(', ', $parts) . ' and ' . $last;
    }

    /**
     * The repair counts the coverage figure rests on, phrased for someone who has never read the formula.
     * Shown UNDER the percentage, not behind a tooltip: "84%" invites the question "84% of what?", and the
     * answer has to arrive before the question does.
     *
     * @return array<int, string>
     */
    private function evidenceLines(array $ev, string $label, string $modelLabel): array
    {
        $sm = (int) ($ev['same_model'] ?? 0);
        $at = (int) ($ev['at_garage'] ?? 0);
        $model = $modelLabel !== '' ? $modelLabel : 'this model';
        $lines = [];

        if ($sm > 0) {
            $lines[] = "{$sm} previous {$model} {$label} repair" . ($sm === 1 ? '' : 's') . ' here';
        }
        // Only worth stating separately when it adds records the same-model line did not already cover.
        if ($at > $sm) {
            $n = $at - $sm;
            $lines[] = "{$n} similar {$label} repair" . ($n === 1 ? '' : 's') . ' here on other models';
        }
        if ($at === 0) {
            $lines[] = "No {$label} repairs recorded here — scored on general capability only";
        }

        return $lines;
    }

    /**
     * Why the winner won, as ticks. Each one is a checkable fact, never a restatement of the score:
     * "highest score" explains nothing, "6 previous repairs on this model" does.
     *
     * @return array<int, string>
     */
    private function winnerPoints(array $w, ?array $alt, string $label, string $modelLabel): array
    {
        $model = $modelLabel !== '' ? $modelLabel : 'this model';
        $points = [];

        if ($w['same_model'] > 0) {
            $points[] = "Has repaired {$label} on {$model} before ({$w['same_model']}×)";
        } elseif ($w['at_garage'] > 0) {
            $points[] = "{$w['at_garage']} {$label} repair" . ($w['at_garage'] === 1 ? '' : 's') . ' here, though none on this model';
        }

        if ($alt === null) {
            $points[] = 'The only garage with a usable record for this fault';
        } elseif ($w['coverage_pct'] > $alt['coverage_pct']) {
            $points[] = "Most experience with this fault ({$w['coverage_pct']}% vs {$alt['coverage_pct']}%)";
        }

        if (($w['start_in_days'] ?? null) !== null && $w['start_in_days'] <= 0) {
            $points[] = 'Can start immediately';
        }
        if ($this->own($w['success_pct']) && $w['success_pct']['value'] >= 60) {
            $points[] = "Repairs hold {$w['success_pct']['value']}% of the time";
        }

        return $points ?: ['The strongest available record for this fault'];
    }

    /**
     * What the alternative is genuinely better at. Listing nothing here is a legitimate answer — inventing
     * an advantage to balance the card would be advocacy dressed as analysis.
     *
     * @return array<int, string>
     */
    private function pros(array $alt, array $w): array
    {
        $out = [];
        if ($this->isFaster($alt, $w)) {
            $out[] = 'About ' . $this->round($w['duration_days']['value'] - $alt['duration_days']['value']) . ' day(s) faster';
        }
        if ($this->isCheaper($alt, $w)) {
            $out[] = 'Roughly AED ' . number_format($this->costFor($w)['value'] - $this->costFor($alt)['value']) . ' cheaper';
        }
        if ($this->isMoreAvailable($alt, $w)) {
            $out[] = 'Can start sooner';
        }
        if ($this->isSafer($alt, $w)) {
            $out[] = 'Repairs hold more often (' . $alt['success_pct']['value'] . '% vs ' . $w['success_pct']['value'] . '%)';
        }
        return $out;
    }

    /** Where the alternative falls short — the reason it did not win. @return array<int, string> */
    private function cons(array $alt, array $w, string $label, string $modelLabel): array
    {
        $model = $modelLabel !== '' ? $modelLabel : 'this model';
        $out = [];

        if ($alt['same_model'] === 0 && $w['same_model'] > 0) {
            $out[] = "No {$label} repairs on {$model} (recommended garage has {$w['same_model']})";
        } elseif ($w['coverage_pct'] > $alt['coverage_pct']) {
            $out[] = "Less {$label} experience ({$alt['coverage_pct']}% vs {$w['coverage_pct']}%)";
        }

        if ($this->isFaster($w, $alt)) {
            $out[] = 'Slower turnaround';
        }
        if ($this->isCheaper($w, $alt)) {
            $out[] = 'More expensive';
        }
        return $out;
    }

    // ── Comparisons. Each requires a MATERIAL, garage-specific difference. ─────────────────────────

    private function isFaster(array $a, array $b): bool
    {
        return $this->own($a['duration_days']) && $this->own($b['duration_days'])
            && ($b['duration_days']['value'] - $a['duration_days']['value']) >= 0.5;
    }

    private function isCheaper(array $a, array $b): bool
    {
        [$ca, $cb] = $this->sharedGrain($a, $b);
        return $ca !== null && $cb !== null && $cb['value'] > 0
            && (($cb['value'] - $ca['value']) / $cb['value']) >= 0.15;
    }

    /**
     * Both garages' prices at the deepest grain they SHARE, or [null, null] when they share none.
     *
     * This is the guard against the most seductive error in the whole cost layer: one garage has an
     * earned fault-level price and the other only a garage-wide average, and subtracting them produces
     * a large, confident, meaningless number.
     *
     * @return array{0:?array{value:float,basis:string,sample:int}, 1:?array{value:float,basis:string,sample:int}}
     */
    private function sharedGrain(array $a, array $b): array
    {
        foreach (['fault', 'garage'] as $grain) {
            $x = $this->costAt($a, $grain);
            $y = $this->costAt($b, $grain);
            if ($x !== null && $y !== null) {
                return [$x, $y];
            }
        }
        return [null, null];
    }

    private function isMoreAvailable(array $a, array $b): bool
    {
        return $a['start_in_days'] !== null && $b['start_in_days'] !== null
            && ($b['start_in_days'] - $a['start_in_days']) >= 1.0;
    }

    private function isSafer(array $a, array $b): bool
    {
        return $this->own($a['success_pct']) && $this->own($b['success_pct'])
            && ($a['success_pct']['value'] - $b['success_pct']['value']) >= 5.0;
    }

    /** A figure only supports a comparison when it is about THIS garage, not a fleet stand-in. */
    private function own(?array $stat): bool
    {
        return $stat !== null && ($stat['value'] ?? null) !== null
            && in_array($stat['basis'] ?? null, ['garage', 'garage_fault', 'garage_fault_model'], true);
    }

    /**
     * This garage's price for THIS fault, if the ledger earned one.
     *
     * @param  array<int, array{fault:string, value:float, basis:string, sample:int}>  $byFault
     * @return array{value:float, basis:string, sample:int}|null
     */
    private function faultCost(array $byFault, string $cat): ?array
    {
        foreach ($byFault as $row) {
            if (($row['fault'] ?? null) === $cat) {
                return ['value' => $row['value'], 'basis' => $row['basis'], 'sample' => $row['sample']];
            }
        }
        return null;
    }

    private function round(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1), '0'), '.');
    }

    /** Evidence confidence for one fault at one garage — how much history the coverage rests on. */
    private function confidenceFor(array $ev): string
    {
        $n = (int) ($ev['same_model'] ?? 0) ?: (int) ($ev['at_garage'] ?? 0);
        return $n >= 10 ? 'high' : ($n >= 3 ? 'medium' : 'low');
    }
}
