<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The shared vocabulary for reading the free-text workshop log (`maintenances.service_main` /
 * `service_sup`, sourced from the N-Maintenance sheet).
 *
 * Extracted so that every engine that reasons over "what fault was this visit about" — the fleet-wide
 * MaintenanceForesightService and the per-car VehicleFaultRecurrenceService — splits, normalises and
 * classifies issue labels EXACTLY the same way. One car's repeat-fault chain must never disagree with
 * the fleet view because the two kept their own keyword lists.
 *
 * See [[treat-data-as-source-of-truth]]: these are the sheet's own labels, split and matched — nothing
 * is inferred or scored.
 */
final class FaultVocabulary
{
    /** Rental-return cosmetics — never a predictive/mechanical signal. Cosmetic always wins. */
    private const COSMETIC_KEYWORDS = [
        'scratch', 'dent', 'body & exterior', 'exterior', 'interior', 'upholstery', 'trim',
        'panel', 'paint', 'sticker', 'rim', 'mirror', 'lip', 'diffuser', 'accessor',
        'cleaning', 'wash', 'glass chip', 'fading', 'peeling', 'cosmetic', 'misalign',
    ];

    /** Mechanical / safety systems — the faults that actually strand a car. */
    private const MECHANICAL_KEYWORDS = [
        'engine', 'brake', 'suspension', 'steer', 'transmission', 'gearbox', 'gear', 'clutch',
        'cooling', 'coolant', 'radiator', 'overheat', 'air condition', 'ac ', 'a/c', 'electric',
        'battery', 'alternator', 'starter', 'ignition', 'exhaust', 'fuel', 'airbag', 'abs',
        'tire', 'tyre', 'wheel', 'align', 'oil', 'fluid', 'dashboard', 'warning light',
        'headlight', 'taillight', 'tail light', 'sensor', 'camera', 'belt', 'leak', 'noise',
        'grind', 'squeak', 'vibrat', 'flat',
    ];

    /** Lowercase, punctuation-free comparison key for an issue label. */
    public static function normalise(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);

        return trim(preg_replace('/\s+/', ' ', $s));
    }

    /**
     * Split the comma-separated main + supplementary service fields into distinct issue labels,
     * preserving the sheet's own wording (de-duplicated).
     *
     * @return array<int,string>
     */
    public static function splitIssues(?string $main, ?string $sup): array
    {
        $tags = [];
        foreach ([$main, $sup] as $field) {
            foreach (preg_split('/\s*,\s*/', (string) $field, -1, PREG_SPLIT_NO_EMPTY) as $t) {
                $t = trim($t);
                if ($t !== '') {
                    $tags[$t] = true;
                }
            }
        }

        return array_keys($tags);
    }

    /** Where a fault label came from, and therefore how specific it is. */
    public const GRAIN_SPECIFIC = 'specific';   // a SUP finding — "Battery Weak or Dead"
    public const GRAIN_CATEGORY = 'category';   // a MAIN system word — "Electrical"

    /**
     * THE SHEET'S FAULT LABELS, READ AT THE RIGHT GRAIN. This is the recurrence-IDENTITY reader for a
     * workshop-log row — the counterpart to FleetUtilizationService::workLabel(), which is the DISPLAY
     * reader. Never use one for the other's job.
     *
     * The N-Maintenance log writes one job across two columns of very different specificity, and the
     * live vocabulary proves it: `service_main` is a CLOSED set of 19 tokens, 14 of them bare system
     * words ("Engine" ×1,316, "Electrical" ×1,094, "Tires" ×691); `service_sup` carries 92 actual
     * findings ("Battery Weak or Dead", "Wheel Alignment Issue", "Engine Oil leak"). Grain therefore
     * follows the COLUMN — no keyword list required, and no keyword list can drift out of date.
     *
     * Reading both columns as one flat list (splitIssues) is right for display and for "what was this
     * visit about", but it is WRONG for recurrence, because the bare category word survives on its own:
     *
     *     MAIN="Engine, Body & Exterior"  SUP="Oil & Fillter Change, Minor Surface Scratch"
     *
     * "Oil & Fillter Change" is correctly typed as a service and dropped — but "Engine" is typed as a
     * fault, resolves to the `engine` category, and is counted as an engine failure. Two oil changes on
     * one car then read as "Engine broke again 2 separate times". Measured on live data: 4,452 MAIN
     * tokens are bare category words and 2,640 of them have NO fault in that category anywhere in their
     * own SUP text. That is the false-recurrence engine this method exists to switch off.
     *
     * The rule, per row:
     *   1. Every reliability-affecting SUP finding counts, at `specific` grain.
     *   2. A MAIN category word counts ONLY when the row recorded no SUP text at all — i.e. nothing more
     *      specific was written down. It comes back at `category` grain so a consumer can weight it,
     *      show it as weaker evidence, or refuse it outright.
     *   3. A MAIN category already covered by a specific SUP finding is dropped as redundant, so one
     *      visit is never counted at two grains.
     *
     * Damage and planned service are excluded by EventClassificationService, the single owner of that
     * boundary — see [[sheet-label-kind-map]].
     *
     * @return array<int,array{label:string, key:string, category_key:string, category_label:string, grain:string}>
     */
    public static function sheetFaultLabels(?string $main, ?string $sup): array
    {
        $classifier = app(\App\Services\EventClassificationService::class);
        $isFault = fn (string $label) => $classifier->kindAffectsReliability($classifier->labelKind($label));

        // Raw SUP tokens, BEFORE the fault filter: their mere presence is what proves the row recorded
        // something specific. "Oil & Fillter Change" is not a fault, but it IS the detail behind MAIN
        // "Engine" — so it must still suppress the bare category word.
        $supTokens = self::splitIssues(null, $sup);

        $out = [];
        $coveredCategories = [];

        foreach ($supTokens as $token) {
            if (! $isFault($token)) {
                continue;
            }
            $category = self::resolveCategory($token);
            if ($category && ! self::isFailureCategory($category['key'])) {
                continue;   // resolved to planned upkeep ("Battery Replacement" → routine)
            }
            // IDENTITY is the finding itself, never its category. "Battery Weak or Dead" and "Alternator
            // Failure" are both `electrical`, and keying them by category would make one the recurrence
            // of the other. The category rides alongside as the BROADER bucket, so a consumer can offer
            // "same fault" and "same system" as two separate, honestly-labelled claims.
            //
            // The catalog slug is preferred as the key when the bridge places the label, because that is
            // the ONE identity both ledgers can share — sheet "Brake Pad Wear" and ticket "Worn pads /
            // discs" are one fault under `brake_worn_pads` and one fault under nothing else.
            $slug        = self::catalogSlugOf($token);
            $categoryKey = $category['key'] ?? self::normalise($token);
            $coveredCategories[$categoryKey] = true;
            $out[] = [
                'label'          => $token,
                'key'            => $slug ?: self::normalise($token),
                'catalog_slug'   => $slug,
                'category_key'   => $categoryKey,
                'category_label' => $category['label'] ?? $token,
                'grain'          => self::GRAIN_SPECIFIC,
            ];
        }

        // The row named something specific — the MAIN words are just its headers. Nothing to add.
        if ($supTokens !== []) {
            return $out;
        }

        foreach (self::splitIssues($main, null) as $token) {
            if (! $isFault($token)) {
                continue;
            }
            $category = self::resolveCategory($token);
            if (! $category || ! self::isFailureCategory($category['key'])) {
                continue;
            }
            if (isset($coveredCategories[$category['key']])) {
                continue;   // already represented at the finer grain
            }
            $coveredCategories[$category['key']] = true;
            // Keyed by the CATEGORY on purpose: a bare system word is not a finding, so it must never be
            // able to equal one. This is what stops "Engine" reading as a recurrence of "Engine Oil leak".
            $out[] = [
                'label'          => $token,
                'key'            => $category['key'],
                'catalog_slug'   => null,
                'category_key'   => $category['key'],
                'category_label' => $category['label'],
                'grain'          => self::GRAIN_CATEGORY,
            ];
        }

        return $out;
    }

    /**
     * @deprecated RETIRED as a rule, kept only so any lingering caller still compiles.
     *
     * These two constants encoded "bodywork and interior are not real failures", which was the platform's
     * stand-in for a Damage concept it did not have. It was too coarse in both directions:
     *
     *   • it EXCLUDED real faults — `interior` holds Dashboard fault, Door lock fault, Interior light
     *     fault, Seat adjustment fault, Infotainment issue and Water leakage into cabin; `bodywork` holds
     *     Rust / corrosion. 132 rows of genuine interior faults were being dropped from recurrence.
     *   • it INCLUDED damage that lives elsewhere — a kerbed rim files under `tyres`, not `bodywork`.
     *
     * Damage is now its own kind, typed per CONCEPT from `damage_catalog`, so exclusion happens on the
     * type where it belongs and these lists decide nothing.
     *
     * @see config/damage_catalog.php
     * @see \App\Services\EventClassificationService::labelKind()
     */
    public const COSMETIC_CATEGORIES = ['bodywork', 'interior'];
    public const NON_FAILURE_CATEGORIES = ['bodywork', 'interior', 'routine'];

    /**
     * The sheet writes its OWN shorthand ("Tires", "Air Conditioning", "Engine mechanical issue") while the
     * ticket workflow writes catalog wording ("Puncture / slow leak", "A/C not cooling"). These aliases are
     * the bridge, so both sides resolve to the same canonical category and merge into one chain.
     *
     * Order matters: the resolver tries the LONGEST alias first, so "engine mechanical issue" is not
     * swallowed by "engine", and "battery replacement" (routine) beats "battery" (electrical).
     */
    private const ALIASES = [
        'oil change' => 'routine', 'oil filter' => 'routine', 'air filter' => 'routine',
        'battery replacement' => 'routine', 'periodic service' => 'routine', 'periodic' => 'routine',
        'engine mechanical issue' => 'engine', 'check engine light' => 'engine', 'engine' => 'engine',
        'overheat' => 'engine', 'exhaust' => 'engine', 'fuel system' => 'engine', 'ignition' => 'engine',
        'brake' => 'brakes', 'braking' => 'brakes', 'abs' => 'brakes',
        'flat tire' => 'tyres', 'tire aging' => 'tyres', 'wheel alignment' => 'tyres',
        'tires' => 'tyres', 'tyres' => 'tyres', 'tire' => 'tyres', 'tyre' => 'tyres', 'wheel' => 'tyres',
        'steering box issue' => 'suspension', 'suspension' => 'suspension', 'steering' => 'suspension',
        'transmission' => 'transmission', 'gearbox' => 'transmission', 'gear' => 'transmission',
        'clutch' => 'transmission',
        'electrical' => 'electrical', 'electric' => 'electrical', 'alternator' => 'electrical',
        'starter' => 'electrical', 'battery' => 'electrical', 'wiring' => 'electrical',
        'air conditioning' => 'ac', 'a c' => 'ac', 'ac' => 'ac', 'climate' => 'ac',
        'body exterior' => 'bodywork', 'bodywork' => 'bodywork', 'exterior' => 'bodywork',
        'paint' => 'bodywork', 'scratch' => 'bodywork', 'dent' => 'bodywork',
        'interior' => 'interior', 'upholstery' => 'interior',
        'coolant' => 'fluids', 'radiator' => 'fluids', 'cooling' => 'fluids', 'leak' => 'fluids',
        'headlight' => 'lights', 'taillight' => 'lights', 'tail light' => 'lights',
        'indicator' => 'lights', 'wiper' => 'lights',
    ];

    /**
     * Resolve any fault wording — a sheet label, a ticket symptom, or a task's category_key — to the ONE
     * canonical category it belongs to. This is what lets "Tires" from the sheet and "Puncture / slow leak"
     * from a ticket count as the same recurring fault instead of two unrelated ones.
     *
     * @return array{key:string, label:string}|null  null when the wording can't be placed
     */
    public static function categoryOf(?string $label): ?array
    {
        $needle = self::normalise((string) $label);
        if ($needle === '') {
            return null;
        }

        $index = self::categoryIndex();

        // 1. The wording IS a known category key, label or catalog keyword — an exact, unambiguous hit.
        if (isset($index['exact'][$needle])) {
            return self::describe($index['exact'][$needle]);
        }

        // 2. Otherwise match aliases on WHOLE WORDS, and let the EARLIEST one win (ties → the longer,
        //    more specific alias).
        //
        //    This used to be a bare `str_contains($needle, $alias)`, which let the two-letter alias `ac`
        //    match inside unrelated words: "Accessories" (186 rows), "Glass Chip / Crack", "Mirror Glass
        //    Crack" and "LED / Light Accessory Issue" all resolved to the A/C category, so accessory and
        //    glass damage joined A/C fault chains in the recurrence engine and the foresight view.
        //    Earliest-wins additionally fixes the head-noun: "AC Not Cooling" is an A/C fault, but
        //    length-ordering matched `cooling` first and filed it under fluids, while "Cooling System
        //    Issues" (which genuinely leads with cooling) still resolves to fluids.
        $best = null;
        foreach ($index['aliases'] as $alias => $key) {
            if (! preg_match('/(?<![a-z0-9])' . preg_quote($alias, '/') . '(?![a-z0-9])/u', $needle, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $pos = $m[0][1];
            if ($best === null || $pos < $best['pos'] || ($pos === $best['pos'] && strlen($alias) > $best['len'])) {
                $best = ['key' => $key, 'pos' => $pos, 'len' => strlen($alias)];
            }
        }

        return $best ? self::describe($best['key']) : null;
    }

    /**
     * The `fault_catalog` slug a label denotes, or null when no single catalog row honestly fits.
     *
     * THE CROSS-LEDGER IDENTITY. Wording cannot carry this on its own: only 4 of the sheet's 92 SUP
     * labels match a catalog name, so "Battery Weak or Dead" (sheet) and "Battery / won't start"
     * (ticket) are the same fault and share not one word. Resolution order:
     *
     *   1. config/sheet_fault_bridge.php — the curated sheet→slug rulings.
     *   2. The catalog's own name or slug, which is how ticket symptoms resolve (17 of 28 live ones).
     *
     *   3. `keyword_terms` — the Keyword Risk Library's 2,327 curated search terms (synonyms, workshop
     *      wording, customer phrasing, misspellings, Arabic) hanging off 106 `finding_keywords`. Human-
     *      and AI-curated, and it GROWS: every term added on /keyword-risk widens this automatically.
     *
     * Null is a real answer, not a failure: it means "recur this against its own wording and its
     * category, but never claim it is a catalog fault it might not be".
     *
     * ── WHY THE FUZZY MATCHER IS NOT CONSULTED ─────────────────────────────────────────────────────
     * `KeywordOntologyService::resolve()` scores free text against the same library and powers the "type
     * what a technician would write" search. It is right for a HUMAN CHOOSING from a ranked list and
     * wrong for machine identity, because its near misses are confident and absurd. Run over the sheet's
     * own labels it returned: "Headlights / Taillights Fault" → Handbrake fault (58), "Engine mechanical
     * issue" → Coolant leak (68), "Washer Fluid Empty / Leak" → Transmission fluid leak (67), "ECU
     * Reprogramming Side Effect" → Broken / loose mirror (53). Each of those would mint a false
     * recurrence — the exact failure this vocabulary exists to prevent. Only EXACT normalised terms are
     * used here; a person picking from a list can reject a bad suggestion, a detector cannot.
     */
    public static function catalogSlugOf(?string $label): ?string
    {
        $needle = self::normalise((string) $label);
        if ($needle === '') {
            return null;
        }

        $index = self::bridgeIndex();

        // Curated rulings first — a hand-made decision outranks a term that merely collides.
        return $index['catalog'][$needle] ?? $index['terms'][$needle] ?? null;
    }

    /**
     * The category a label belongs to, consulting the curated bridge before the alias resolver.
     *
     * categoryOf() places wording by keyword and cannot place 26 of the sheet's fault labels at all
     * ("Thermostat Failure", "Compressor Failure", "Headlights / Taillights Fault"). Each of those
     * becomes its own isolated bucket that can never merge with a ticket fault in the same system. The
     * bridge answers first so a curated ruling always beats a keyword guess.
     *
     * @return array{key:string, label:string}|null
     */
    public static function resolveCategory(?string $label): ?array
    {
        $needle = self::normalise((string) $label);
        if ($needle !== '' && isset(self::bridgeIndex()['category'][$needle])) {
            return self::describe(self::bridgeIndex()['category'][$needle]);
        }

        return self::categoryOf($label);
    }

    /**
     * Lookup tables for the sheet→catalog bridge, keyed on normalised wording. Slugs are validated
     * against config/fault_catalog.php at build time, so a typo in the bridge is dropped rather than
     * silently creating an identity that matches nothing.
     *
     * @return array{catalog:array<string,string>, category:array<string,string>}
     */
    private static function bridgeIndex(): array
    {
        static $index = null;
        if ($index !== null) {
            return $index;
        }

        $slugs = [];
        $byName = [];
        foreach (config('fault_catalog', []) as $row) {
            $slug = $row['slug'] ?? null;
            if (! $slug) {
                continue;
            }
            $slugs[$slug] = true;
            $byName[self::normalise((string) ($row['name'] ?? ''))] = $slug;
            $byName[self::normalise($slug)] = $slug;
        }

        $catalog = $byName;
        foreach (config('sheet_fault_bridge.catalog', []) as $label => $slug) {
            if (isset($slugs[$slug])) {
                $catalog[self::normalise((string) $label)] = $slug;   // a curated ruling outranks a name collision
            }
        }

        $category = [];
        foreach (config('sheet_fault_bridge.category', []) as $label => $key) {
            $category[self::normalise((string) $label)] = $key;
        }

        // THE KEYWORD RISK LIBRARY as an identity source. 106 finding_keywords carry 2,327 curated
        // search terms between them, and 81 of those keywords name a fault_catalog row — so a term
        // resolves through its keyword to a slug. The 25 keywords with no catalog row ("A/C compressor
        // fault", "Water pump failure") key on `fk:<id>` instead: they are still ONE fault however many
        // ways the workshop writes them, which is all identity needs.
        //
        // Guarded: a database without these tables must fall back to the curated bridge rather than
        // taking down every reader of this class ([[migrations-cannot-run-from-empty]]).
        $terms = [];
        try {
            $keywordSlug = [];
            foreach (DB::table('finding_keywords')->where('is_active', 1)->get(['id', 'keyword']) as $k) {
                $keywordSlug[$k->id] = $byName[self::normalise((string) $k->keyword)] ?? ('fk:' . $k->id);
            }
            foreach (DB::table('keyword_terms')->where('is_active', 1)->get(['finding_keyword_id', 'normalized']) as $t) {
                $n = self::normalise((string) $t->normalized);
                if ($n === '' || isset($terms[$n]) || ! isset($keywordSlug[$t->finding_keyword_id])) {
                    continue;   // first term wins; a term shared by two keywords identifies neither
                }
                $terms[$n] = $keywordSlug[$t->finding_keyword_id];
            }
        } catch (\Throwable $e) {
            $terms = [];
        }

        return $index = ['catalog' => $catalog, 'category' => $category, 'terms' => $terms];
    }

    /** The display name for a canonical category key. */
    public static function categoryLabel(string $key): string
    {
        return self::categoryIndex()['labels'][$key]
            ?? ucwords(str_replace(['_', '-'], ' ', $key));
    }

    /**
     * True when a canonical category represents an actual FAILURE — neither cosmetic damage nor planned
     * upkeep.
     *
     * Two independent rules, deliberately kept separate:
     *   • PLANNED WORK is decided by EventClassificationService — the ONE owner of the Service/Fault
     *     boundary in this codebase. This class used to keep its own 'routine' entry and could therefore
     *     disagree with the sheet-label map and the service catalog about the same word (audit H7).
     *   • COSMETIC is this class's own call, and a different question: a scratch is an unplanned defect
     *     (a fault) that simply carries no mechanical-failure signal.
     */
    public static function isFailureCategory(string $key): bool
    {
        return ! app(\App\Services\EventClassificationService::class)->isServiceCategory($key);
    }

    /** @return array{key:string,label:string} */
    private static function describe(string $key): array
    {
        return ['key' => $key, 'label' => self::categoryLabel($key)];
    }

    /**
     * Lookup tables built once from config/maintenance_findings.php (the SAME catalog the fault picker
     * offers) plus the sheet aliases above.
     *
     * @return array{exact:array<string,string>, aliases:array<string,string>, labels:array<string,string>}
     */
    private static function categoryIndex(): array
    {
        static $index = null;
        if ($index !== null) {
            return $index;
        }

        $exact = [];
        $labels = [];
        foreach (config('maintenance_findings.categories', []) as $c) {
            $key = $c['key'] ?? null;
            if (! $key) {
                continue;
            }
            $labels[$key] = $c['label'] ?? $key;
            foreach (array_merge([$key, $c['label'] ?? ''], $c['keywords'] ?? []) as $term) {
                $n = self::normalise((string) $term);
                if ($n !== '' && ! isset($exact[$n])) {
                    $exact[$n] = $key;
                }
            }
        }

        // Longest alias first so the specific wording beats the generic one.
        $aliases = self::ALIASES;
        uksort($aliases, fn ($a, $b) => strlen($b) <=> strlen($a));

        return $index = ['exact' => $exact, 'aliases' => $aliases, 'labels' => $labels];
    }

    /** True when an issue label is a mechanical / safety fault (not cosmetic). Unknown → false. */
    public static function isMechanical(string $issue): bool
    {
        $k = ' ' . self::normalise($issue) . ' ';
        foreach (self::COSMETIC_KEYWORDS as $w) {
            if (str_contains($k, $w)) {
                return false;   // cosmetic wins
            }
        }
        foreach (self::MECHANICAL_KEYWORDS as $w) {
            if (str_contains($k, trim($w))) {
                return true;
            }
        }

        return false;           // unknown → don't reason on it
    }
}
