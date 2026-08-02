<?php

namespace App\Services\Knowledge;

use App\Models\ConceptBridgeLabel;
use App\Models\ConceptBridgeSample;
use Illuminate\Support\Collection;

/**
 * Scores the Concept Bridge benchmark — and keeps the two tracks apart.
 *
 *   TRACK A  human labels ONLY. The number of record. Every decision rule reads this.
 *   TRACK B  agreement between the AI baseline and the human, on rows BOTH answered. Says how far
 *            the machine pass can be trusted; it is not an accuracy figure for the matcher.
 *   TRACK C  AI-only rows — indicative, never quoted.
 *
 * PURE-ish by design: `score()` takes already-loaded rows and returns arrays, with no queries and no
 * clock, so the maths is locked by DB-free unit tests — the same style as ConfidenceScorer::score
 * and GarageRecommendationService::scoreRows.
 *
 * WHY PRECISION IS NEVER ONE NUMBER. The sample is stratified and deliberately NOT proportional to
 * real traffic (65 of 210 rows sit in one score band on purpose). A blended figure would describe a
 * fleet that does not exist, so everything is reported per stratum.
 */
class ConceptBridgeBenchmark
{
    /** Verdict → rank, for deciding whether the AI was more generous than the human. */
    private const RANK = [
        ConceptBridgeLabel::VERDICT_WRONG    => 0,
        ConceptBridgeLabel::VERDICT_BROAD    => 1,
        ConceptBridgeLabel::VERDICT_SPECIFIC => 2,
    ];

    /** Below this many judged rows a stratum figure is reported but flagged as thin. */
    public const THIN_N = 20;

    /**
     * @param  Collection<int,ConceptBridgeSample>  $samples  with `labels` eager-loaded
     * @return array<string,mixed>
     */
    public function score(Collection $samples): array
    {
        [$human, $ai] = $this->split($samples);

        return [
            'coverage' => [
                'samples'      => $samples->count(),
                'human_done'   => count($human),
                'ai_done'      => count($ai),
                'overlap'      => count(array_intersect_key($human, $ai)),
                'human_only'   => count(array_diff_key($human, $ai)),
                'ai_only'      => count(array_diff_key($ai, $human)),
            ],
            'track_a'   => $this->trackA($human),
            'track_b'   => $this->trackB($human, $ai),
            'track_c'   => $this->trackC($human, $ai),
            'decisions' => $this->decisions($this->trackA($human)),
        ];
    }

    /**
     * Index both label sets by sample id. A sample carries at most one human label per reviewer;
     * when several people answered the same row we keep the FIRST and surface the disagreement
     * count separately rather than silently picking a winner.
     *
     * @return array{0:array<int,array>,1:array<int,array>}
     */
    private function split(Collection $samples): array
    {
        $human = $ai = [];

        foreach ($samples as $s) {
            foreach ($s->labels as $l) {
                $row = [
                    'stratum'  => $s->stratum,
                    'score'    => (int) $s->pred1_score,
                    'stage'    => $s->pred1_primary_stage,
                    'term'     => $s->pred1_matched_term,
                    'concept'  => $s->pred1_concept,
                    'text'     => $s->segment_text,
                    'type'     => $l->segment_type,
                    'verdict'  => $l->verdict,
                    'quality'  => $l->evidence_quality,
                    'valid'    => $l->valid_concepts,
                    'missing'  => $l->missing_concepts,
                ];
                if ($l->source === ConceptBridgeLabel::SOURCE_HUMAN) {
                    $human[$s->id] ??= $row;
                } else {
                    $ai[$s->id] ??= $row;
                }
            }
        }

        return [$human, $ai];
    }

    // ── TRACK A — the benchmark ──────────────────────────────────────────────────────────────────

    /** @param array<int,array> $rows */
    private function trackA(array $rows): array
    {
        if ($rows === []) {
            return ['available' => false];
        }

        $byStratum = [];
        foreach ($this->group($rows, 'stratum') as $k => $set) {
            $byStratum[$k] = $this->precision($set) + ['thin' => count($set) < self::THIN_N];
        }
        $byStage = [];
        foreach ($this->group($rows, 'stage') as $k => $set) {
            if ($k === '') { continue; }
            $byStage[$k] = $this->precision($set) + ['thin' => count($set) < self::THIN_N];
        }

        // Action-as-fault: does a segment describing WORK DONE get read as a FAULT?
        $nonFault = array_filter($rows, fn ($r) => in_array($r['type'], [
            ConceptBridgeLabel::TYPE_ACTION, ConceptBridgeLabel::TYPE_PART, ConceptBridgeLabel::TYPE_PROCEDURE,
        ], true) && $r['concept']);
        $accepted = array_filter($nonFault, fn ($r) => $r['verdict'] !== ConceptBridgeLabel::VERDICT_WRONG);

        $correct = array_filter($rows, fn ($r) => in_array($r['verdict'], ConceptBridgeLabel::CORRECT, true));
        $broad   = array_filter($rows, fn ($r) => $r['verdict'] === ConceptBridgeLabel::VERDICT_BROAD);

        return [
            'available'   => true,
            'overall'     => $this->precision($rows),
            'by_stratum'  => $byStratum,
            'by_stage'    => $byStage,
            'action_as_fault' => [
                'n'        => count($nonFault),
                'accepted' => count($accepted),
                'rate'     => $this->pct(count($accepted), count($nonFault)),
            ],
            'granularity' => [
                'correct' => count($correct),
                'broad'   => count($broad),
                'rate'    => $this->pct(count($broad), count($correct)),
            ],
            'calibration'      => $this->calibration($rows),
            'ontology_gaps'    => $this->gaps($rows),
            'false_positives'  => $this->falsePositives($rows),
        ];
    }

    // ── TRACK B — how far the machine baseline can be trusted ────────────────────────────────────

    private function trackB(array $human, array $ai): array
    {
        $both = array_intersect_key($human, $ai);
        if ($both === []) {
            return ['available' => false, 'overlap' => 0];
        }

        $fields = [];
        foreach (['verdict' => 'verdict', 'segment_type' => 'type', 'evidence_quality' => 'quality'] as $key => $field) {
            $agree = $n = 0;
            $confusion = [];
            foreach ($both as $id => $_) {
                $h = $human[$id][$field] ?? '';
                $a = $ai[$id][$field] ?? '';
                if ($h === '' && $a === '') { continue; }
                $n++;
                if ($h === $a) { $agree++; }
                else {
                    $c = "human={$h} · ai={$a}";
                    $confusion[$c] = ($confusion[$c] ?? 0) + 1;
                }
            }
            arsort($confusion);
            $fields[$key] = [
                'n' => $n, 'agree' => $agree, 'rate' => $this->pct($agree, $n),
                'confusion' => array_slice($confusion, 0, 6, true),
            ];
        }

        // Directional bias — an optimistic machine baseline means Track C must be discounted.
        $up = $down = 0;
        foreach ($both as $id => $_) {
            $h = self::RANK[$human[$id]['verdict']] ?? null;
            $a = self::RANK[$ai[$id]['verdict']] ?? null;
            if ($h === null || $a === null) { continue; }
            if ($a > $h) { $up++; } elseif ($a < $h) { $down++; }
        }

        return [
            'available' => true,
            'overlap'   => count($both),
            'fields'    => $fields,
            'bias'      => [
                'ai_more_generous' => $up,
                'ai_stricter'      => $down,
                'optimistic'       => $up > $down * 1.5,
            ],
            'same_rows' => [
                'human' => $this->precision(array_intersect_key($human, $both)),
                'ai'    => $this->precision(array_intersect_key($ai, $both)),
            ],
        ];
    }

    private function trackC(array $human, array $ai): array
    {
        $only = array_diff_key($ai, $human);
        return [
            'available'  => $only !== [],
            'n'          => count($only),
            'precision'  => $only ? $this->precision($only) : null,
            'indicative' => true,   // never a benchmark figure
        ];
    }

    // ── Pre-registered decision rules ────────────────────────────────────────────────────────────

    /**
     * Agreed BEFORE the results existed, so a disappointing number cannot be rationalised after the
     * fact. Each returns [verdict, message].
     */
    private function decisions(array $a): array
    {
        if (! ($a['available'] ?? false)) {
            return [];
        }

        // "Core" = the strata that stand in for normal traffic: the high-confidence control group
        // plus the five per-system samples. The deliberately-hard strata (band 60–79, action-like,
        // bare nouns) are diagnostic and would drag a go/no-go figure below what production sees.
        $sys = [];
        foreach ($a['by_stratum'] as $k => $v) {
            if (str_starts_with($k, 'system_') || $k === 'control_80_plus') { $sys[] = $v; }
        }
        $coreN  = array_sum(array_column($sys, 'n'));
        $coreOk = array_sum(array_column($sys, 'correct'));
        $corePrecision = $coreN > 0 ? round(100 * $coreOk / $coreN, 1) : null;

        $band  = $a['by_stratum']['band_60_79']['precision'] ?? null;
        $broad = $a['granularity']['rate'] ?? null;
        $act   = $a['action_as_fault']['rate'] ?? null;

        return [
            'core_precision' => $this->rule('Core precision (control + systems)', $corePrecision, [
                [85, 'proceed', 'PROCEED to full backfill'],
                [70, 'blocked', 'BLOCKED — expand ontology + phrase-first rule, then re-score'],
                [0,  'blocked', 'BLOCKED — fix segmentation/matching first'],
            ], $coreN),
            'band_60_79' => $this->rule('Band 60–79 precision', $band, [
                [75, 'act',          'LOWER the threshold toward 60 (~20 pts coverage)'],
                [60, 'inconclusive', 'Inconclusive — keep 70, needs more labelled rows'],
                [0,  'act',          'KEEP threshold at 70 — that band is noise'],
            ], (int) ($a['by_stratum']['band_60_79']['n'] ?? 0)),
            'granularity' => $this->ruleAbove('correct_broad share', $broad, 30,
                (int) ($a['granularity']['correct'] ?? 0),
                'Ontology needs COMPONENT-LEVEL concepts before knowledge ingestion',
                'Granularity acceptable for knowledge fusion'),
            'action_as_fault' => $this->ruleAbove('Action-as-fault rate', $act, 50,
                (int) ($a['action_as_fault']['n'] ?? 0),
                'BUILD the fault/action/part/procedure split before backfill',
                'Action confusion is not systemic'),
        ];
    }

    /**
     * A decision rule must never fire off a handful of rows. Below MIN_DECISION_N every rule stays
     * `pending` — a confident-looking verdict computed from n=1 is worse than no verdict, because
     * someone will act on it.
     */
    public const MIN_DECISION_N = 15;

    private function rule(string $label, ?float $v, array $bands, ?int $n = null): array
    {
        if ($v === null) {
            return ['label' => $label, 'value' => null, 'verdict' => 'pending', 'message' => 'Not enough human labels yet'];
        }
        if ($n !== null && $n < self::MIN_DECISION_N) {
            return ['label' => $label, 'value' => $v, 'verdict' => 'pending', 'n' => $n,
                    'message' => "Only {$n} labelled rows — need at least " . self::MIN_DECISION_N];
        }
        foreach ($bands as [$min, $verdict, $msg]) {
            if ($v >= $min) {
                return ['label' => $label, 'value' => $v, 'verdict' => $verdict, 'message' => $msg];
            }
        }
        return ['label' => $label, 'value' => $v, 'verdict' => 'pending', 'message' => ''];
    }

    private function ruleAbove(string $label, ?float $v, float $threshold, int $n, string $over, string $under): array
    {
        if ($v === null) {
            return ['label' => $label, 'value' => null, 'verdict' => 'pending', 'message' => 'Not enough human labels yet'];
        }
        if ($n < self::MIN_DECISION_N) {
            return ['label' => $label, 'value' => $v, 'verdict' => 'pending', 'n' => $n,
                    'message' => "Only {$n} labelled rows — need at least " . self::MIN_DECISION_N];
        }
        return ['label' => $label, 'value' => $v, 'n' => $n,
                'verdict' => $v > $threshold ? 'act' : 'proceed',
                'message' => $v > $threshold ? $over : $under];
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────────

    /** @param array<int,array> $rows */
    public function precision(array $rows): array
    {
        $specific = $broad = $wrong = 0;
        foreach ($rows as $r) {
            match ($r['verdict']) {
                ConceptBridgeLabel::VERDICT_SPECIFIC => $specific++,
                ConceptBridgeLabel::VERDICT_BROAD    => $broad++,
                ConceptBridgeLabel::VERDICT_WRONG    => $wrong++,
                default                              => null,   // none_predicted is not a judgement
            };
        }
        $n = $specific + $broad + $wrong;

        return [
            'n' => $n, 'specific' => $specific, 'broad' => $broad, 'wrong' => $wrong,
            'correct'   => $specific + $broad,
            'precision' => $this->pct($specific + $broad, $n),
            'strict'    => $this->pct($specific, $n),
        ];
    }

    private function calibration(array $rows): array
    {
        $out = [];
        foreach (ConceptBridgeLabel::QUALITIES as $q) {
            $set = array_filter($rows, fn ($r) => $r['quality'] === $q);
            $sc  = array_column($set, 'score');
            $out[$q] = [
                'n'   => count($set),
                'avg' => $sc ? round(array_sum($sc) / count($sc), 1) : null,
                'min' => $sc ? min($sc) : null,
                'max' => $sc ? max($sc) : null,
            ];
        }
        return $out;
    }

    /** The ontology expansion backlog, ranked — measured from real fleet text, never guessed. */
    private function gaps(array $rows): array
    {
        $g = [];
        foreach ($rows as $r) {
            foreach (preg_split('/[;,]/', (string) $r['missing']) as $m) {
                $m = trim(mb_strtolower($m));
                if ($m === '' || $m === 'none' || $m === 'n/a') { continue; }
                $g[$m] = ($g[$m] ?? 0) + 1;
            }
        }
        arsort($g);
        return array_slice($g, 0, 40, true);
    }

    private function falsePositives(array $rows): array
    {
        $fp = [];
        foreach ($rows as $r) {
            if ($r['verdict'] !== ConceptBridgeLabel::VERDICT_WRONG) { continue; }
            $k = '"' . ($r['term'] ?: mb_substr((string) $r['text'], 0, 24)) . '" → ' . $r['concept'];
            $fp[$k] = ($fp[$k] ?? 0) + 1;
        }
        arsort($fp);
        return array_slice($fp, 0, 20, true);
    }

    private function group(array $rows, string $key): array
    {
        $out = [];
        foreach ($rows as $r) { $out[(string) ($r[$key] ?? '')][] = $r; }
        ksort($out);
        return $out;
    }

    private function pct(int $n, int $d): ?float
    {
        return $d > 0 ? round(100 * $n / $d, 1) : null;
    }
}
