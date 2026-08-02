<?php

namespace App\Services\Garage;

/**
 * A reason the interface can render in ANY language.
 *
 * The recommendation engine used to emit finished English sentences ("more experienced with Engine
 * (84% vs 59%)"). That made the Arabic UI half-English with no way to fix it from the frontend: the
 * sentence arrived already built, and a translator layer can only translate what it is given as parts.
 *
 * So every reason is now a triple:
 *
 *   code    a stable identifier the frontend resolves against its own label table
 *   params  the facts that fill the sentence — always numbers/labels, never prose
 *   text    the English rendering, kept for the AUDIT TRAIL
 *
 * `text` is not there for display. The garage_assigned event stores what was recommended so that
 * "why this garage?" stays answerable months later, and a stored reason code whose label table has
 * since been reworded would silently rewrite history. The text field freezes the sentence as it was
 * at the moment of the decision; the UI renders from code + params and ignores it.
 *
 * The two are produced together by one method per code, which is the only way to guarantee they cannot
 * drift — a code whose English lives somewhere else eventually describes something different.
 *
 * Evidence class: D (Derived) — every reason restates figures measured elsewhere; none is a new fact.
 * See [[garage-recommendation-engine]], [[evidence-class-tagging]].
 */
final class Reason
{
    /**
     * @param  array<string, mixed>  $params
     * @return array{code:string, params:array<string,mixed>, text:string}
     */
    private static function of(string $code, array $params, string $text): array
    {
        return ['code' => $code, 'params' => $params, 'text' => $text];
    }

    /** Pull the frozen English out of a reason list — for logs, audit rows and assertions. */
    public static function texts(array $reasons): array
    {
        return array_values(array_map(static fn ($r) => is_array($r) ? ($r['text'] ?? '') : (string) $r, $reasons));
    }

    /** Join reason texts the way English lists read: "a, b and c". */
    public static function join(array $reasons): string
    {
        $parts = self::texts($reasons);
        if (empty($parts)) {
            return '';
        }
        if (count($parts) === 1) {
            return $parts[0];
        }
        $last = array_pop($parts);

        return implode(', ', $parts) . ' and ' . $last;
    }

    // ── Comparative advantages — one garage measured against another ──────────────────────────────

    public static function cheaperThan(float $a, float $b): array
    {
        return self::of('cheaper_than', ['a' => $a, 'b' => $b],
            'cheaper (AED ' . number_format($a) . ' vs ' . number_format($b) . ')');
    }

    public static function fasterThan(string $a, string $b): array
    {
        return self::of('faster_than', ['a' => $a, 'b' => $b], "faster ({$a}d vs {$b}d)");
    }

    public static function holdsBetterThan(float $a, float $b): array
    {
        return self::of('holds_better_than', ['a' => $a, 'b' => $b],
            "better at making repairs hold ({$a}% vs {$b}%)");
    }

    public static function startsSooner(): array
    {
        return self::of('starts_sooner', [], 'able to start sooner');
    }

    public static function moreExperiencedThan(string $label, float $a, float $b): array
    {
        return self::of('more_experienced_than', ['label' => $label, 'a' => $a, 'b' => $b],
            "more experienced with {$label} ({$a}% vs {$b}%)");
    }

    public static function moreEvidenceThan(string $a, string $b): array
    {
        return self::of('more_evidence_than', ['a' => $a, 'b' => $b],
            "backed by more evidence ({$a} vs {$b} confidence)");
    }

    // ── Why the winner won, as checkable facts ────────────────────────────────────────────────────

    public static function repairedOnModel(string $label, string $model, int $n): array
    {
        return self::of('repaired_on_model', ['label' => $label, 'model' => $model, 'n' => $n],
            "Has repaired {$label} on {$model} before ({$n}×)");
    }

    public static function repairsHereNotThisModel(string $label, int $n): array
    {
        return self::of('repairs_here_not_this_model', ['label' => $label, 'n' => $n],
            "{$n} {$label} repair" . ($n === 1 ? '' : 's') . ' here, though none on this model');
    }

    public static function onlyGarageWithRecord(): array
    {
        return self::of('only_garage_with_record', [], 'The only garage with a usable record for this fault');
    }

    public static function mostExperience(float $a, float $b): array
    {
        return self::of('most_experience', ['a' => $a, 'b' => $b],
            "Most experience with this fault ({$a}% vs {$b}%)");
    }

    public static function canStartImmediately(): array
    {
        return self::of('can_start_immediately', [], 'Can start immediately');
    }

    public static function repairsHoldPct(float $pct): array
    {
        return self::of('repairs_hold_pct', ['pct' => $pct], "Repairs hold {$pct}% of the time");
    }

    public static function strongestAvailable(): array
    {
        return self::of('strongest_available', [], 'The strongest available record for this fault');
    }

    // ── What the alternative is genuinely better at ───────────────────────────────────────────────

    public static function daysFaster(string $n): array
    {
        return self::of('days_faster', ['n' => $n], "About {$n} day(s) faster");
    }

    public static function aedCheaper(float $n): array
    {
        return self::of('aed_cheaper', ['n' => $n], 'Roughly AED ' . number_format($n) . ' cheaper');
    }

    public static function canStartSooner(): array
    {
        return self::of('can_start_sooner', [], 'Can start sooner');
    }

    public static function holdsMoreOften(float $a, float $b): array
    {
        return self::of('holds_more_often', ['a' => $a, 'b' => $b],
            "Repairs hold more often ({$a}% vs {$b}%)");
    }

    // ── Where the alternative falls short ─────────────────────────────────────────────────────────

    public static function noRepairsOnModel(string $label, string $model, int $n): array
    {
        return self::of('no_repairs_on_model', ['label' => $label, 'model' => $model, 'n' => $n],
            "No {$label} repairs on {$model} (recommended garage has {$n})");
    }

    public static function lessExperience(string $label, float $a, float $b): array
    {
        return self::of('less_experience', ['label' => $label, 'a' => $a, 'b' => $b],
            "Less {$label} experience ({$a}% vs {$b}%)");
    }

    public static function slowerTurnaround(): array
    {
        return self::of('slower_turnaround', [], 'Slower turnaround');
    }

    public static function moreExpensive(): array
    {
        return self::of('more_expensive', [], 'More expensive');
    }

    // ── The scan-table one-liner ──────────────────────────────────────────────────────────────────

    public static function shortOnlyRecord(string $label): array
    {
        return self::of('short_only_record', ['label' => $label], "only garage with a {$label} record");
    }

    public static function shortStrongestHistory(string $label): array
    {
        return self::of('short_strongest_history', ['label' => $label], "strongest {$label} history");
    }

    public static function shortCheaper(): array
    {
        return self::of('short_cheaper', [], 'cheaper');
    }

    public static function shortFaster(): array
    {
        return self::of('short_faster', [], 'faster');
    }

    public static function shortHolds(): array
    {
        return self::of('short_holds', [], 'repairs hold better');
    }

    public static function shortNarrowlyAhead(string $label): array
    {
        return self::of('short_narrowly_ahead', ['label' => $label], "narrowly ahead on {$label} history");
    }

    // ── The counts a coverage figure rests on ─────────────────────────────────────────────────────

    public static function evidenceSameModel(int $n, string $model, string $label): array
    {
        return self::of('evidence_same_model', ['n' => $n, 'model' => $model, 'label' => $label],
            "{$n} previous {$model} {$label} repair" . ($n === 1 ? '' : 's') . ' here');
    }

    public static function evidenceOtherModels(int $n, string $label): array
    {
        return self::of('evidence_other_models', ['n' => $n, 'label' => $label],
            "{$n} similar {$label} repair" . ($n === 1 ? '' : 's') . ' here on other models');
    }

    public static function evidenceNone(string $label): array
    {
        return self::of('evidence_none', ['label' => $label],
            "No {$label} repairs recorded here — scored on general capability only");
    }

    // ── How far the PRICE COMPARISON can be leaned on ─────────────────────────────────────────────

    public static function costIncomparable(): array
    {
        return self::of('cost_incomparable', [],
            'Cost cannot be compared here — one of the two garages has no price of its own, only the fleet average.');
    }

    public static function costFaultDeep(int $n): array
    {
        return self::of('cost_fault_deep', ['n' => $n],
            "Both garages have priced history for this specific fault ({$n}+ repairs each).");
    }

    public static function costFaultThin(int $n): array
    {
        return self::of('cost_fault_thin', ['n' => $n],
            "Both prices are for this specific fault, but one rests on only {$n} repairs.");
    }

    public static function costGarageLevel(): array
    {
        return self::of('cost_garage_level', [],
            'Both prices are comparable at garage level, but neither garage has enough priced history for this specific fault.');
    }

    public static function costGarageThin(int $n): array
    {
        return self::of('cost_garage_thin', ['n' => $n],
            "Compared at garage level only, and one side rests on just {$n} priced repairs. Treat the difference as indicative.");
    }
}
