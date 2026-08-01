<?php

namespace App\Services;

use App\Models\MaintenanceReason;

/**
 * Cross-references a maintenance record to the controlled "Maintenance Reason"
 * (سبب الصيانة) vocabulary — deterministically, with NO heuristics or guessing.
 *
 * Rules (locked by the owner):
 *   PRIMARY  — the sheet's MAIN column. Each MAIN area maps 1:1 to a reason via the
 *              fixed table below. When MAIN lists several areas, the most severe
 *              reason wins (critical > minor > routine > special).
 *   REFINE   — the sheet's SUP column is used ONLY as a tie-breaker, and ONLY when a
 *              SUP token is an EXACT (case-insensitive, trimmed) match to a reason
 *              name (English or Arabic). An exact SUP match refines/overrides the
 *              coarse MAIN category. If SUP doesn't match perfectly it is ignored —
 *              its free text is never parsed, interpreted or guessed.
 *
 * Returns null only when MAIN is blank/unmapped and SUP has no exact match.
 */
class MaintenanceReasonMatcher
{
    /** MAIN area (lowercased) => exact reason_en in the vocabulary. The 14 controlled MAIN values. */
    private const AREA_MAP = [
        'body & exterior'    => 'Body Damage',
        'engine'             => 'Mechanical Issues',
        'electrical'         => 'Electrical Problems',
        'interior'           => 'Interior problem / Chairs',
        'tires'              => 'Tire Issues',
        'suspension'         => 'Suspension Troubles',
        'brakes'             => 'Braking Problems',
        'air conditioning'   => 'AC issues',
        'cooling system'     => 'Cooling System Issues',
        'transmission'       => 'Transmission Issues',
        'exhaust'            => 'Exhaust System Problems',
        'fluids'             => 'Fluid Leaks',
        'accessories & mods' => 'Accessories',
        'fuel system'        => 'Fuel System Issues',
    ];

    /** Severity order for picking a winner when several areas/reasons apply. */
    private const LEVEL_RANK = ['critical' => 0, 'minor' => 1, 'routine' => 2, 'special' => 3];

    /** reason name (lowercased en + ar) => MaintenanceReason; cached for the request. */
    private ?array $byName = null;
    /** lowercased reason_en => MaintenanceReason; cached. */
    private ?array $byEn = null;

    /**
     * Resolve the reason for one record from its MAIN (and, exactly, SUP) columns.
     */
    public function resolve(?string $serviceMain, ?string $serviceSup = null): ?MaintenanceReason
    {
        $this->load();

        // PRIMARY: MAIN → reason (most severe of the listed areas).
        $main = $this->mostSevere($this->reasonsFromMain($serviceMain));

        // REFINE (tie-breaker only): an EXACT SUP match wins ONLY when it is equally or
        // MORE severe than MAIN — so SUP sharpens a category to a more specific reason
        // (e.g. Body Damage → Rims scratch) but can never DOWNGRADE a critical MAIN to a
        // routine reason just because a routine item also appears in SUP.
        $sup = $this->mostSevere($this->reasonsFromSupExact($serviceSup));
        if ($sup && (! $main || $this->rank($sup) <= $this->rank($main))) {
            return $sup;
        }

        return $main;
    }

    private function rank(MaintenanceReason $r): int
    {
        return self::LEVEL_RANK[$r->level] ?? 9;
    }

    /**
     * @return array<int,MaintenanceReason> reasons mapped from each MAIN token
     *
     * MAIN carries TWO vocabularies, because the sheet has been filled both ways over the years:
     *   - an AREA  ('Body & Exterior', 'Engine', 'Tires', …) → mapped via AREA_MAP, and
     *   - a reason NAME verbatim ('Body Damage', 'Rims scratch', 'Check Engine Light', …).
     *
     * Area first (it's the controlled form), then an EXACT name match — the identical
     * case-insensitive, trimmed, whole-token rule SUP already uses. Still no heuristics: a token
     * either IS a reason name or it is ignored. Without the second pass ~23k rows whose MAIN holds
     * a name that exists verbatim in the vocabulary could never link, and the nightly
     * maintenance:link-reasons was a no-op on all of them.
     */
    private function reasonsFromMain(?string $serviceMain): array
    {
        $out = [];
        foreach ($this->tokens($serviceMain) as $tok) {
            $key = mb_strtolower($tok);

            $reasonEn = self::AREA_MAP[$key] ?? null;
            if ($reasonEn !== null && isset($this->byEn[mb_strtolower($reasonEn)])) {
                $out[] = $this->byEn[mb_strtolower($reasonEn)];
                continue;
            }

            // Not an area — accept it only if it is EXACTLY a reason name (en or ar).
            if (isset($this->byName[$key])) {
                $out[] = $this->byName[$key];
            }
        }
        return $out;
    }

    /** @return array<int,MaintenanceReason> reasons from SUP tokens that EXACTLY match a reason name */
    private function reasonsFromSupExact(?string $serviceSup): array
    {
        $out = [];
        foreach ($this->tokens($serviceSup) as $tok) {
            $hit = $this->byName[mb_strtolower($tok)] ?? null;
            if ($hit !== null) {
                $out[] = $hit;   // exact string match only — no fuzzy logic
            }
        }
        return $out;
    }

    /** Split a comma-separated cell into trimmed, non-empty tokens. */
    private function tokens(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', preg_split('/\s*,\s*/', $value, -1, PREG_SPLIT_NO_EMPTY))));
    }

    /** @param array<int,MaintenanceReason> $reasons */
    private function mostSevere(array $reasons): ?MaintenanceReason
    {
        $best = null;
        $bestRank = PHP_INT_MAX;
        foreach ($reasons as $r) {
            $rank = self::LEVEL_RANK[$r->level] ?? 9;
            if ($rank < $bestRank) {
                $best = $r;
                $bestRank = $rank;
            }
        }
        return $best;
    }

    /** Load (once) the reason lookups keyed by lowercased name. */
    private function load(): void
    {
        if ($this->byName !== null) {
            return;
        }
        $this->byName = [];
        $this->byEn = [];
        foreach (MaintenanceReason::all() as $r) {
            $en = mb_strtolower(trim((string) $r->reason_en));
            $ar = mb_strtolower(trim((string) $r->reason_ar));
            if ($en !== '') {
                $this->byName[$en] = $r;
                $this->byEn[$en] = $r;
            }
            if ($ar !== '') {
                $this->byName[$ar] = $r;
            }
        }
    }
}
