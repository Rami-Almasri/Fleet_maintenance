<?php

namespace App\Services\Knowledge;

/**
 * Turns the evidence behind a repair recommendation into a single, HONEST confidence score.
 *
 * PURE by design: `score()` takes the inputs + the config knobs and returns a ConfidenceScore, with no
 * DB, no config() facade and no clock — exactly like GarageRecommendationService::scoreRows(), so it is
 * locked by DB-free unit tests. `scoreFromConfig()` is the thin wrapper that feeds it config('knowledge').
 *
 * Guiding rule (from the brief): "the score should never hide uncertainty." So beyond the blended number,
 * the band is hard-GUARDED — a thin cohort can never read "high", a fleet-only match is capped, and
 * incomplete data is capped — and every downgrade is spelled out in `reasons`.
 */
class ConfidenceScorer
{
    /** Convenience wrapper: pull the tuning from config/knowledge.php. */
    public function scoreFromConfig(ConfidenceInputs $in): ConfidenceScore
    {
        return $this->score($in, (array) config('knowledge.confidence', []));
    }

    /**
     * The pure core. $cfg mirrors config('knowledge.confidence').
     */
    public function score(ConfidenceInputs $in, array $cfg): ConfidenceScore
    {
        $reasons = [];

        // ── No evidence at all ────────────────────────────────────────────────────────────────────
        if ($in->sampleSize <= 0) {
            return new ConfidenceScore(0, ConfidenceScore::LOW, ['No comparable repairs in fleet history']);
        }

        $credN   = (float) ($cfg['credibility_n'] ?? 12);
        $w       = (array) ($cfg['weights'] ?? []);
        $tierMap = (array) ($cfg['tier_factor'] ?? [1 => 1.0, 2 => 0.8, 3 => 0.6, 4 => 0.45]);

        // ── 1. Evidence (√-credibility ramp) ────────────────────────────────────────────────────────
        $evidence = min(1.0, sqrt(max($in->sampleSize, 0)) / sqrt(max($credN, 1)));

        // ── 2. Tier specificity ───────────────────────────────────────────────────────────────────
        $tierFactor = (float) ($tierMap[$in->bestTier] ?? 0.45);

        // ── 3. Success (how well the repairs held) ──────────────────────────────────────────────────
        $concluded = $in->verifiedFixed + $in->fixed + $in->failed;
        if ($concluded > 0) {
            $success = (1.0 * $in->verifiedFixed + 0.9 * $in->fixed + 0.0 * $in->failed) / $concluded;
        } else {
            $success = (float) ($cfg['success_neutral'] ?? 0.6);
            $reasons[] = 'Repair outcomes were not independently verified';
        }

        // ── 4. Recency ────────────────────────────────────────────────────────────────────────────
        $recency = $this->recencyFactor($in->daysSinceNewest, $cfg);

        // ── 5. Completeness (cost + duration known) ─────────────────────────────────────────────────
        $completeness = max(0.0, min(1.0, $in->completeness));

        // ── Blend (weights normalised by their sum) ─────────────────────────────────────────────────
        $terms = [
            'evidence'     => [$evidence,     (float) ($w['evidence'] ?? 0.30)],
            'tier'         => [$tierFactor,   (float) ($w['tier'] ?? 0.20)],
            'success'      => [$success,      (float) ($w['success'] ?? 0.25)],
            'recency'      => [$recency,      (float) ($w['recency'] ?? 0.10)],
            'completeness' => [$completeness, (float) ($w['completeness'] ?? 0.15)],
        ];
        $wsum = array_sum(array_map(fn ($t) => $t[1], $terms)) ?: 1.0;
        $raw  = array_sum(array_map(fn ($t) => $t[0] * $t[1], $terms)) / $wsum;

        // Recurrence drags it down.
        $raw -= (float) ($cfg['recurrence_penalty'] ?? 0.25) * max(0.0, min(1.0, $in->recurrenceRate));

        $score = (int) round(max(0.0, min(1.0, $raw)) * 100);

        // ── Banding + uncertainty guards ────────────────────────────────────────────────────────────
        $highScore = (int) ($cfg['high_score'] ?? 70);
        $highN     = (int) ($cfg['high_n'] ?? 12);
        $medScore  = (int) ($cfg['med_score'] ?? 45);
        $medN      = (int) ($cfg['med_n'] ?? 5);
        $minComp   = (float) ($cfg['min_completeness'] ?? 0.5);

        if ($score >= $highScore && $in->sampleSize >= $highN && $in->bestTier <= 2 && $completeness >= $minComp) {
            $band = ConfidenceScore::HIGH;
        } elseif ($score >= $medScore && $in->sampleSize >= $medN) {
            $band = ConfidenceScore::MEDIUM;
        } else {
            $band = ConfidenceScore::LOW;
        }

        // Guard A — a thin cohort can never be more than low, whatever the blend says.
        if ($in->sampleSize < $medN) {
            if ($band !== ConfidenceScore::LOW) {
                $band = ConfidenceScore::LOW;
            }
            $reasons[] = "Only {$in->sampleSize} comparable repair" . ($in->sampleSize === 1 ? '' : 's') . ' — treat as indicative';
        }
        // Guard B — evidence found only fleet-wide (not this vehicle/model) is capped at medium, and the
        // fleet-only caveat is surfaced whenever the cohort still lands above "low".
        if ($in->bestTier >= 4) {
            if ($band === ConfidenceScore::HIGH) {
                $band = ConfidenceScore::MEDIUM;
            }
            if ($band === ConfidenceScore::MEDIUM) {
                $reasons[] = 'All evidence is fleet-wide, not this model';
            }
        }
        // Guard C — sparse cost/duration data is capped at medium.
        if ($completeness < $minComp && $band === ConfidenceScore::HIGH) {
            $band = ConfidenceScore::MEDIUM;
        }
        if ($completeness < $minComp) {
            $reasons[] = 'Cost / duration known for less than half of the comparable repairs';
        }

        // Positive drivers (surfaced after the caveats).
        if ($in->bestTier === 1) {
            $reasons[] = 'Includes the same vehicle';
        }
        if ($in->recurrenceRate > 0) {
            $reasons[] = round($in->recurrenceRate * 100) . '% of these repairs later recurred';
        }

        return new ConfidenceScore($score, $band, array_values(array_unique($reasons)));
    }

    /** 1.0 when the newest match is fresh, decaying to 0 by `recency_stale_days`. Null age ⇒ neutral 0.5. */
    private function recencyFactor(?int $daysSinceNewest, array $cfg): float
    {
        if ($daysSinceNewest === null) {
            return 0.5;
        }
        $fresh = (int) ($cfg['recency_fresh_days'] ?? 90);
        $stale = (int) ($cfg['recency_stale_days'] ?? 540);
        if ($daysSinceNewest <= $fresh) {
            return 1.0;
        }
        if ($daysSinceNewest >= $stale) {
            return 0.0;
        }
        return 1.0 - ($daysSinceNewest - $fresh) / max(1, $stale - $fresh);
    }
}
