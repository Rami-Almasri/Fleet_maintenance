<?php

namespace App\Support;

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

    /**
     * Category keys that are NOT a breakdown story — cosmetic damage and planned upkeep. A fault landing
     * in one of these is excluded from recurrence analysis.
     */
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

        // 2. Otherwise the most SPECIFIC alias contained in the wording wins (aliases are length-sorted).
        foreach ($index['aliases'] as $alias => $key) {
            if (str_contains(" $needle ", " $alias ") || str_contains($needle, $alias)) {
                return self::describe($key);
            }
        }

        return null;
    }

    /** The display name for a canonical category key. */
    public static function categoryLabel(string $key): string
    {
        return self::categoryIndex()['labels'][$key]
            ?? ucwords(str_replace(['_', '-'], ' ', $key));
    }

    /** True when a canonical category represents an actual FAILURE (not cosmetic damage or planned upkeep). */
    public static function isFailureCategory(string $key): bool
    {
        return ! in_array($key, self::NON_FAILURE_CATEGORIES, true);
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
