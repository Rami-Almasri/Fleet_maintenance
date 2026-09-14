<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * A spreadsheet cell is not a value until it has been proved to be one.
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────────────────────────────
 *
 * The fleet's Daily Warranty Report carries **37 `#VALUE!` cells across 22 cars** — broken formulas
 * in the `Warranty finish Date` and `Service Date` columns. Read naively, `#VALUE!` is a perfectly
 * ordinary non-empty string: it passes `!empty()`, it survives `trim()`, and
 * `Carbon::parse('#VALUE!')` does not throw — it returns TODAY. An importer written without this
 * class would silently stamp today's date onto every car whose formula is broken, and the result
 * would look like clean data.
 *
 * That is the failure mode this guards: not a crash, but a plausible wrong answer.
 *
 * ── THE RULE ────────────────────────────────────────────────────────────────────────────────────
 *
 * An error cell is MISSING DATA, never zero and never today. Every method here returns null for
 * anything it cannot prove, so a caller writing `?? 0` or `?: now()` has to make that decision
 * visibly rather than inheriting it.
 *
 * Also caught: the human error values the same sheet contains — `"NEW CONTRA ONLY"` typed into a
 * date column. Not a spreadsheet error, but equally not a date, and equally fatal to parse.
 */
final class SheetValue
{
    /**
     * Every error literal Google Sheets and Excel can put in a cell.
     *
     * Matched case-insensitively and after trimming, because they arrive exactly as printed but the
     * surrounding whitespace varies. `#GETTING_DATA` is transient rather than broken — a cell still
     * calculating — and is treated as missing for the same reason: it is not a value yet.
     */
    public const ERRORS = [
        '#VALUE!', '#REF!', '#DIV/0!', '#N/A', '#NAME?', '#NULL!', '#NUM!',
        '#ERROR!', '#GETTING_DATA', '#SPILL!', '#CALC!', '#UNKNOWN!',
    ];

    /** Is this cell a spreadsheet error rather than data? */
    public static function isError($value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        return in_array(strtoupper(trim($value)), self::ERRORS, true);
    }

    /**
     * The cell as a clean string, or null when it holds nothing usable.
     *
     * Empty string and whitespace collapse to null alongside the errors, because a blank cell and a
     * broken one mean the same thing to every caller: we were not told.
     */
    public static function string($value): ?string
    {
        if ($value === null || is_array($value) || self::isError($value)) {
            return null;
        }

        $clean = trim((string) $value);

        return $clean === '' ? null : $clean;
    }

    /**
     * The cell as an integer, or null.
     *
     * Thousands separators are stripped because the report writes `47,408` and `50,000` — a value a
     * human formatted, not a different kind of number. Anything left that is not numeric is refused
     * rather than coerced: `(int) "NEW CONTRA ONLY"` is 0, and a 0 km limit would read as "cover
     * ended at zero kilometres", which is worse than no limit at all.
     */
    public static function int($value): ?int
    {
        $clean = self::string($value);

        if ($clean === null) {
            return null;
        }

        $clean = str_replace([',', ' ', "\u{00A0}"], '', $clean);

        // A leading minus is kept: the report's "finish" columns go negative, and a caller may
        // legitimately want to read one.
        return preg_match('/^-?\d+$/', $clean) ? (int) $clean : null;
    }

    /** Positive integers only — the shape of an odometer reading or a kilometre limit. */
    public static function positiveInt($value): ?int
    {
        $n = self::int($value);

        return ($n !== null && $n > 0) ? $n : null;
    }

    /**
     * The cell as a date, or null.
     *
     * `d/m/Y` FIRST and explicitly, because the sheet writes `18/02/2028` and Carbon's loose parser
     * reads an ambiguous `03/10/2026` as March 10th — American order — which is wrong by seven
     * months on every row where the day is 12 or under. Formats are tried in order and the first
     * exact match wins; nothing falls through to a guess.
     *
     * Returns a Y-m-d string rather than a Carbon so a caller cannot accidentally keep a time
     * component on a date-only fact.
     */
    public static function date($value): ?string
    {
        $clean = self::string($value);

        if ($clean === null) {
            return null;
        }

        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y', 'Y/m/d'] as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $clean);
            } catch (\Throwable $e) {
                // Carbon THROWS on a mismatch rather than returning false, so a cell that is simply
                // not this format arrives here as an exception. That is the ordinary case while
                // walking the format list — not an error worth reporting.
                continue;
            }

            // createFromFormat also accepts input that merely STARTS like the format, so the round
            // trip back through the same format must reproduce the input exactly.
            if ($parsed && $parsed->format($format) === $clean) {
                return $parsed->toDateString();
            }
        }

        return null;
    }

    /**
     * "5 Lube Service/5Yrs" → 5.
     *
     * A convenience for pre-filling a form, never a replacement for the label: the words are kept
     * verbatim on the contract and shown verbatim, because a parse that is wrong on one supplier's
     * wording must not become the number anybody plans against. Returns null unless the string
     * clearly opens with a count.
     */
    public static function serviceCount($value): ?int
    {
        $clean = self::string($value);

        if ($clean === null) {
            return null;
        }

        return preg_match('/^\s*(\d{1,2})\s*(?:x|×)?\s*(?:lube|service|svc)/i', $clean, $m)
            ? (int) $m[1]
            : null;
    }

    /**
     * "Service at every 10,000 kms and includes…" → 10000.
     *
     * Same discipline as above: a convenience read of the note, refused unless the wording is
     * unambiguous about an interval.
     */
    public static function intervalKm($value): ?int
    {
        $clean = self::string($value);

        if ($clean === null) {
            return null;
        }

        return preg_match('/every\s+([\d,]+)\s*k?ms?\b/i', $clean, $m)
            ? (int) str_replace(',', '', $m[1])
            : null;
    }
}
