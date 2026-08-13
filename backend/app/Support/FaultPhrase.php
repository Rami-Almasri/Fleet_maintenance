<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * THE ONE PLACE A FAULT BECOMES A SENTENCE.
 *
 *      type + quantity + locations   →   "2 scratches — rims and body"
 *
 * ── WHY THIS IS A CLASS AND NOT A LINE OF CONCATENATION ──────────────────────────────────────────
 * The sentence is a PRESENTATION of three stored facts, not a stored fact itself. The moment it is
 * built inline — in a notification body here, a board card there, a report row somewhere else — the
 * copies drift, and then the fleet has several different ways of saying the same damage and no way
 * to search any of them. Every surface (UI, notifications, API, reports, audit trail) renders through
 * here, so they cannot disagree.
 *
 * The structured data is the source of truth. This class never parses a sentence back; it only ever
 * writes one. If a caller needs the parts, it reads the parts.
 *
 * ── PLURALISATION IS DELIBERATELY CONSERVATIVE ───────────────────────────────────────────────────
 * Fault type names are curated human phrases, not nouns: "Scratch" pluralises cleanly, "Rough idle /
 * misfire" and "Headlight out" do not. Rather than emit "2 Rough idle / misfires" — which reads as a
 * bug and undermines trust in every other line on the page — anything the simple rule cannot handle
 * falls back to the unambiguous `2 × Headlight out`. Correct-or-obvious beats confidently wrong; see
 * [[operational-language-over-engine-vocabulary]].
 *
 * ── ARABIC ───────────────────────────────────────────────────────────────────────────────────────
 * Arabic has no productive plural rule that can be applied to an arbitrary noun, and inventing one
 * would produce nonsense words. Arabic therefore renders `count + singular` ("2 خدش") and joins with
 * "و" — the form a native speaker writing a quick operational note actually uses.
 */
class FaultPhrase
{
    /** The dash between the type and its places. */
    public const SEPARATOR = ' — ';

    /**
     * Type names that must never be pluralised — mass nouns where "2 rusts" is not English. Kept
     * short and explicit; anything not listed goes through the rule below or falls back to `×`.
     */
    private const UNCOUNTABLE = ['rust', 'corrosion', 'wear', 'paint', 'oil', 'smoke', 'odour', 'odor'];

    /**
     * Render the sentence.
     *
     * @param  string        $type       the fault/damage type label, already in the caller's language
     * @param  int           $quantity   physical occurrences this record covers (≥ 1)
     * @param  array<string> $locations  place labels, already in the caller's language, in the order picked
     * @param  string        $locale     'en' | 'ar'
     */
    public static function render(string $type, int $quantity = 1, array $locations = [], string $locale = 'en'): string
    {
        $type      = trim($type);
        $quantity  = max(1, $quantity);
        $locations = array_values(array_filter(array_map(
            fn ($l) => trim((string) $l),
            $locations
        ), fn ($l) => $l !== ''));

        if ($type === '') {
            // No type is not a sentence we can write. Hand back the places alone rather than a
            // leading dash — a caller with only locations still gets something readable.
            return self::joinList($locations, $locale);
        }

        $head = $quantity > 1
            ? self::countedType($type, $quantity, $locale)
            : $type;

        if (! $locations) {
            return $head;
        }

        return $head . self::SEPARATOR . self::joinList($locations, $locale);
    }

    /**
     * Convenience for the common shape: the parts already resolved into an array.
     *
     * @param array{type?:?string, quantity?:?int, locations?:?array} $parts
     */
    public static function fromParts(array $parts, string $locale = 'en'): string
    {
        return self::render(
            (string) ($parts['type'] ?? ''),
            (int) ($parts['quantity'] ?? 1),
            (array) ($parts['locations'] ?? []),
            $locale,
        );
    }

    /**
     * "2 scratches" / "2 × Headlight out" / "٢ خدش".
     *
     * English pluralises the LAST word only, and only when the label is short and unambiguous. A
     * label carrying a slash ("Wiper / washer fault") or running longer than three words is a
     * described condition rather than a countable noun, so it takes the `×` form instead.
     */
    private static function countedType(string $type, int $quantity, string $locale): string
    {
        if ($locale === 'ar') {
            return $quantity . ' ' . $type; // see the Arabic note in the class docblock
        }

        $words = preg_split('/\s+/', $type) ?: [];

        $inflectable = ! str_contains($type, '/')
            && count($words) > 0
            && count($words) <= 3
            && ! in_array(mb_strtolower((string) end($words)), self::UNCOUNTABLE, true)
            // A last word that is not plain letters (a code, a size, "12V") has no reliable plural.
            && preg_match('/^[\p{L}]+$/u', (string) end($words)) === 1;

        if (! $inflectable) {
            return $quantity . ' × ' . $type;
        }

        $last = array_pop($words);
        $words[] = Str::plural($last, $quantity);

        // Lower-cased because the sentence reads as prose ("2 scratches — rims"), not as a heading.
        // A label with an internal capital (a brand, an acronym like "ABS") keeps it: only the very
        // first character is folded, and only when the rest of that word is lower-case already.
        $out = implode(' ', $words);
        $first = mb_substr($out, 0, 1);
        $restOfFirstWord = preg_split('/\s+/', $out)[0] ?? '';
        if (mb_strtolower($restOfFirstWord) === $restOfFirstWord || $restOfFirstWord === ucfirst(mb_strtolower($restOfFirstWord))) {
            $out = mb_strtolower($first) . mb_substr($out, 1);
        }

        return $quantity . ' ' . $out;
    }

    /**
     * "a", "a and b", "a, b and c" — and the Arabic equivalents.
     *
     * @param array<string> $items
     */
    public static function joinList(array $items, string $locale = 'en'): string
    {
        $items = array_values(array_filter($items, fn ($i) => trim((string) $i) !== ''));
        $count = count($items);

        if ($count === 0) {
            return '';
        }
        if ($count === 1) {
            return $items[0];
        }

        $and = $locale === 'ar' ? ' و' : ' and ';
        $sep = $locale === 'ar' ? '، ' : ', ';

        $last = array_pop($items);

        return implode($sep, $items) . $and . $last;
    }
}
