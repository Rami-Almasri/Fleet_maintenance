<?php

namespace App\Support;

use App\Models\ComponentCatalog;
use Illuminate\Validation\ValidationException;

/**
 * THE ONE ANSWER to "what specs does this part have, is this value legal, and how does it read?"
 *
 * Every surface that stores or displays a part specification goes through this class. That is not a
 * style preference — it is the reason the feature is worth building. A spec layer whose rendering
 * lives in three places produces "12V 60Ah" on the components panel, "12v/60" in the timeline and
 * "60 amp battery" on the invoice, and then nobody trusts any of them. One formatter, one
 * validator, one vocabulary.
 *
 * ── The storage shape ────────────────────────────────────────────────────────────────────────────
 *
 * A `specs` column is a flat JSON map of field key → value:
 *
 *     {"voltage": "12v", "capacity_ah": "60", "terminal_layout": "right_positive"}
 *
 * Flat and shallow on purpose. Nested shapes invite per-part-type structure, which is exactly the
 * divergence the shared dictionary exists to prevent. Enum values store the OPTION KEY; numbers
 * store a JSON number; text stores normalised text. A field that was not answered is ABSENT, never
 * null and never '' — "we did not record the terminal side" and "the terminal side is empty" are
 * different facts, and only absence can represent the first honestly.
 *
 * ── What this class refuses to do ────────────────────────────────────────────────────────────────
 *
 * It does not guess. If a spec is unknown it stays unknown: no defaulting a battery to 12V because
 * most are, no inferring viscosity from the vehicle's make. This codebase treats data as the source
 * of truth and does not manufacture confidence — an empty spec is a prompt to go and look at the
 * part, and a filled-in guess is a lie that reads exactly like a fact.
 *
 * @see config/part_specs.php          the field dictionary
 * @see \App\Models\VehiclePartSpec    the per-vehicle "what this car takes" record
 */
class PartSpecs
{
    public const KIND_ENUM   = 'enum';
    public const KIND_NUMBER = 'number';
    public const KIND_TEXT   = 'text';

    /** Separator between facts in the one-line summary. Narrow, quiet, and not a comma. */
    private const SUMMARY_GLUE = ' · ';

    /** @var array<string,array>|null Memoised so a list of 200 components reads config once. */
    private static ?array $dictionary = null;

    // ── The dictionary ──────────────────────────────────────────────────────────────────────────

    /** @return array<string,array> every defined field, keyed by field key. */
    public static function fields(): array
    {
        return self::$dictionary ??= config('part_specs', []);
    }

    /** One field definition, or null when the key is not in the dictionary. */
    public static function field(string $key): ?array
    {
        return self::fields()[$key] ?? null;
    }

    /** Test seam — drop the memo after a config change. */
    public static function flush(): void
    {
        self::$dictionary = null;
    }

    /**
     * The fields that apply to one part type, in the dictionary's own order.
     *
     * Unknown keys in a catalog row's `spec_fields` are SKIPPED rather than thrown on. A part type
     * naming a field that was later renamed is a seeding mistake, and it must not take down the
     * screen a technician is standing at — `part-specs:verify` is where that gets reported.
     *
     * @return array<string,array> field key → definition
     */
    public static function fieldsFor(ComponentCatalog|array|null $catalog): array
    {
        $keys = is_array($catalog)
            ? ($catalog['spec_fields'] ?? [])
            : ($catalog?->spec_fields ?? []);

        if (! is_array($keys) || $keys === []) {
            return [];
        }

        $wanted = array_flip($keys);

        return array_intersect_key(self::fields(), $wanted);
    }

    public static function hasSpecs(ComponentCatalog|array|null $catalog): bool
    {
        return self::fieldsFor($catalog) !== [];
    }

    // ── Writing ─────────────────────────────────────────────────────────────────────────────────

    /**
     * Take what a form submitted and return what may be stored.
     *
     * Values are normalised, unknown fields dropped, blanks removed. A value that is present but not
     * legal for its field is a 422 naming the field — never a silent drop, because a silently
     * dropped spec looks identical to one nobody filled in.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>  ready for the `specs` column ([] when nothing was answered)
     *
     * @throws ValidationException
     */
    public static function validate(ComponentCatalog|array|null $catalog, ?array $input): array
    {
        $applicable = self::fieldsFor($catalog);
        $clean      = [];
        $errors     = [];

        foreach ($input ?? [] as $key => $raw) {
            // Not a field this part type carries — a stale form, not something to fail the save on.
            if (! isset($applicable[$key])) {
                continue;
            }

            if ($raw === null || $raw === '' || (is_string($raw) && trim($raw) === '')) {
                continue; // unanswered — absent, per the storage rule above
            }

            $field  = $applicable[$key];
            $result = self::normalize($field, $raw);

            if ($result['error'] !== null) {
                $errors["specs.$key"] = [$result['error']];
                continue;
            }

            $clean[$key] = $result['value'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        // Stored in dictionary order so two identical spec sets serialise byte-identically —
        // which is what lets a plain JSON comparison answer "did this change?" in the audit log.
        return array_intersect_key($clean, $applicable) + [];
    }

    /**
     * Coerce one raw value to its stored form.
     *
     * Enums accept the option key OR any label, in either language, case- and separator-insensitive:
     * a garage portal posting "5W-30" and an internal form posting "5w-30" must not become two
     * different fleets of cars. Anything that still does not resolve is an error, not a passthrough.
     *
     * @return array{value: mixed, error: ?string}
     */
    public static function normalize(array $field, mixed $raw): array
    {
        $kind = $field['kind'] ?? self::KIND_TEXT;

        if ($kind === self::KIND_NUMBER) {
            // Strip any unit a person typed alongside the number ("4.5 L", "60Ah").
            $stripped = is_string($raw) ? preg_replace('/[^\d.\-]/u', '', $raw) : $raw;

            if (! is_numeric($stripped)) {
                return ['value' => null, 'error' => self::label($field) . ' must be a number.'];
            }

            $value = round((float) $stripped, 2);

            if (isset($field['min']) && $value < $field['min']) {
                return ['value' => null, 'error' => self::label($field) . " cannot be below {$field['min']}."];
            }
            if (isset($field['max']) && $value > $field['max']) {
                return ['value' => null, 'error' => self::label($field) . " cannot be above {$field['max']}."];
            }

            return ['value' => $value, 'error' => null];
        }

        if ($kind === self::KIND_ENUM) {
            $needle = self::fold((string) $raw);

            foreach ($field['options'] ?? [] as $option) {
                $surfaces = array_filter([
                    $option['key'] ?? null,
                    $option['label'] ?? null,
                    $option['label_ar'] ?? null,
                ]);

                foreach ($surfaces as $surface) {
                    if (self::fold((string) $surface) === $needle) {
                        return ['value' => $option['key'], 'error' => null];
                    }
                }
            }

            $offered = implode(', ', array_column($field['options'] ?? [], 'label'));

            return ['value' => null, 'error' => self::label($field) . " must be one of: {$offered}."];
        }

        $text = trim(preg_replace('/\s+/u', ' ', (string) $raw));

        if (($field['pattern'] ?? null) === 'tyre_size') {
            $text = self::normalizeTyreSize($text);
        }

        if (mb_strlen($text) > 120) {
            return ['value' => null, 'error' => self::label($field) . ' is too long (120 characters max).'];
        }

        return ['value' => $text, 'error' => null];
    }

    /**
     * '225 65 r 17' · '225/65 R17' · '225-65-17' → '225/65R17'.
     *
     * Three digits, two digits, two digits is the sidewall on every tyre the fleet runs, and people
     * type the separators between them a dozen ways. Anything that does not match that shape is left
     * exactly as typed — a 33x12.50R15 off-road size is real, and mangling it would be worse than
     * storing it unevenly.
     */
    private static function normalizeTyreSize(string $text): string
    {
        if (preg_match('/^(\d{3})\s*[\/\-\s]?\s*(\d{2})\s*[\/\-\s]?\s*R?\s*(\d{2})$/i', $text, $m)) {
            return "{$m[1]}/{$m[2]}R{$m[3]}";
        }

        return strtoupper($text);
    }

    /** Case, spaces, dashes and Arabic-Indic digits folded away for comparison only. */
    private static function fold(string $value): string
    {
        $value = strtr($value, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);

        return preg_replace('/[\s\-_\/]+/u', '', mb_strtolower(trim($value)));
    }

    // ── Reading ─────────────────────────────────────────────────────────────────────────────────

    /**
     * THE ONE LINE. "12V · 60Ah · Right positive" — what sits next to the part name on the timeline,
     * the components panel, the invoice line and the purchase history.
     *
     * Empty string when nothing summary-worthy is recorded, so a caller can write
     * `{summary && <span>{summary}</span>}` and get no stray separator on a part nobody specced.
     *
     * @param  array<string,mixed>|null  $specs
     */
    public static function summary(ComponentCatalog|array|null $catalog, ?array $specs, string $locale = 'en'): string
    {
        if (! $specs) {
            return '';
        }

        $applicable = self::fieldsFor($catalog);
        $parts      = [];

        foreach ($applicable as $key => $field) {
            if (! ($field['summary'] ?? false) || ! array_key_exists($key, $specs)) {
                continue;
            }

            $rendered = self::renderValue($field, $specs[$key], $locale);

            if ($rendered !== '') {
                $parts[] = ['order' => $field['summary_order'] ?? 999, 'text' => $rendered];
            }
        }

        usort($parts, fn ($a, $b) => $a['order'] <=> $b['order']);

        return implode(self::SUMMARY_GLUE, array_column($parts, 'text'));
    }

    /**
     * Every recorded spec as label/value pairs — the detail panel, and the printable dossier.
     *
     * Fields the part type carries but nobody answered are returned too, with a null value, because
     * "terminal side: not recorded" is the sentence that gets someone to go and record it. Callers
     * showing a compact view filter on `value !== null`.
     *
     * @return array<int,array{key:string,label:string,value:?string,critical:bool,recorded:bool}>
     */
    public static function describe(ComponentCatalog|array|null $catalog, ?array $specs, string $locale = 'en'): array
    {
        $out = [];

        foreach (self::fieldsFor($catalog) as $key => $field) {
            $recorded = $specs && array_key_exists($key, $specs);

            $out[] = [
                'key'      => $key,
                'label'    => self::label($field, $locale),
                'value'    => $recorded ? (self::renderValue($field, $specs[$key], $locale) ?: null) : null,
                'critical' => (bool) ($field['critical'] ?? false),
                'recorded' => $recorded,
            ];
        }

        return $out;
    }

    /** One stored value as a human reads it — '5w-30' → '5W-30', 4.5 → '4.5 L'. */
    public static function renderValue(array $field, mixed $value, string $locale = 'en'): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $kind = $field['kind'] ?? self::KIND_TEXT;

        if ($kind === self::KIND_ENUM) {
            foreach ($field['options'] ?? [] as $option) {
                if (($option['key'] ?? null) === $value) {
                    return $locale === 'ar'
                        ? ($option['label_ar'] ?? $option['label'] ?? (string) $value)
                        : ($option['label'] ?? (string) $value);
                }
            }

            // An option retired from the dictionary after it was stored. Show the raw key rather
            // than nothing: a reader can still act on it, and a blank cell hides that it exists.
            return (string) $value;
        }

        if ($kind === self::KIND_NUMBER) {
            // 4.50 → '4.5', 60.00 → '60' — trailing zeros read as false precision.
            $number = rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
            $unit   = $locale === 'ar' ? ($field['unit_ar'] ?? $field['unit'] ?? '') : ($field['unit'] ?? '');

            return trim($number . ($unit !== '' ? " $unit" : ''));
        }

        return (string) $value;
    }

    public static function label(array $field, string $locale = 'en'): string
    {
        return $locale === 'ar'
            ? ($field['label_ar'] ?? $field['label'] ?? '')
            : ($field['label'] ?? '');
    }

    // ── Variants — the unit "which one lasts longer?" is answered about ─────────────────────────

    /**
     * The VARIANT this part is: a stable key for "a 12V 60Ah battery", as distinct from "a battery"
     * and from "serial 4471".
     *
     * This is the grouping key behind the whole point of typing a spec in. Buying a battery is not a
     * repeatable decision — buying a 60Ah battery at 380 rather than a 100Ah at 700 is, and it can
     * only be judged by putting every 60Ah we ever fitted in one bucket and every 100Ah in another.
     *
     * BUILT FROM THE SUMMARY FIELDS ONLY, and that is a deliberate narrowing. The summary fields are
     * the ones that identify the part to a person ("12V 60Ah"); the rest are detail. If CCA were in
     * the key, two identical batteries whose datasheets quote 640 and 660 would become two variants
     * with one observation each, and a bucket of one measures nothing. Wide buckets with real counts
     * beat exact buckets that are all singletons.
     *
     * Deterministic: fields in dictionary order, so the same part always produces the same key.
     * Returns '' for a part nobody specced — a real bucket, labelled honestly, and usually the
     * biggest one until people start typing.
     */
    public static function variantKey(ComponentCatalog|array|null $catalog, ?array $specs): string
    {
        if (! $specs) {
            return '';
        }

        $parts = [];

        foreach (self::fieldsFor($catalog) as $key => $field) {
            if (! ($field['summary'] ?? false) || ! array_key_exists($key, $specs)) {
                continue;
            }

            $value = $specs[$key];

            if ($value === null || $value === '') {
                continue;
            }

            $parts[] = $key . '=' . self::fold((string) $value);
        }

        return implode('|', $parts);
    }

    /**
     * How that variant reads — "12V · 60 Ah", or a plain sentence for the unspecced bucket.
     *
     * The empty bucket is NAMED rather than hidden, because it is the finding: "47 batteries, no
     * spec recorded, average life 7 months" is the row that explains why nobody can tell which
     * battery to buy, and deleting it would hide the reason the report is thin.
     */
    public static function variantLabel(
        ComponentCatalog|array|null $catalog,
        ?array $specs,
        string $locale = 'en'
    ): string {
        $summary = self::summary($catalog, $specs, $locale);

        return $summary !== '' ? $summary : ($locale === 'ar' ? 'بدون مواصفة مسجّلة' : 'No spec recorded');
    }

    // ── Comparing ───────────────────────────────────────────────────────────────────────────────

    /**
     * Where what was fitted disagrees with what the car takes — the wrong-part warning.
     *
     * ONLY critical fields are compared, and only where BOTH sides recorded a value. A battery one
     * size larger than the last one is a purchasing choice and nobody needs a banner about it;
     * 10W-40 in an engine that takes 0W-20 is an engine being damaged, and the difference between
     * those two is precisely the `critical` flag in the dictionary.
     *
     * Absence never raises a conflict. "We don't know what this car takes" is not evidence that the
     * part is wrong, and a warning raised on missing data trains people to dismiss warnings.
     *
     * @param  array<string,mixed>|null  $expected  what the vehicle takes
     * @param  array<string,mixed>|null  $actual    what is going on / went on
     * @return array<int,array{key:string,label:string,expected:string,actual:string}>
     */
    public static function conflicts(
        ComponentCatalog|array|null $catalog,
        ?array $expected,
        ?array $actual,
        string $locale = 'en'
    ): array {
        if (! $expected || ! $actual) {
            return [];
        }

        $out = [];

        foreach (self::fieldsFor($catalog) as $key => $field) {
            if (! ($field['critical'] ?? false)) {
                continue;
            }
            if (! array_key_exists($key, $expected) || ! array_key_exists($key, $actual)) {
                continue;
            }
            if (self::fold((string) $expected[$key]) === self::fold((string) $actual[$key])) {
                continue;
            }

            $out[] = [
                'key'      => $key,
                'label'    => self::label($field, $locale),
                'expected' => self::renderValue($field, $expected[$key], $locale),
                'actual'   => self::renderValue($field, $actual[$key], $locale),
            ];
        }

        return $out;
    }

    // ── The API payload ─────────────────────────────────────────────────────────────────────────

    /**
     * The dictionary as the frontend needs it — so the spec inputs are BUILT from the same
     * definitions the backend validates against, and adding a field to config/part_specs.php makes
     * it appear in the form without a matching frontend edit.
     */
    public static function payload(string $locale = 'en'): array
    {
        $out = [];

        foreach (self::fields() as $key => $field) {
            $out[$key] = [
                'key'         => $key,
                'label'       => self::label($field, $locale),
                'kind'        => $field['kind'] ?? self::KIND_TEXT,
                'unit'        => $locale === 'ar' ? ($field['unit_ar'] ?? $field['unit'] ?? null) : ($field['unit'] ?? null),
                'critical'    => (bool) ($field['critical'] ?? false),
                'summary'     => (bool) ($field['summary'] ?? false),
                'placeholder' => $field['placeholder'] ?? null,
                'min'         => $field['min'] ?? null,
                'max'         => $field['max'] ?? null,
                'step'        => $field['step'] ?? null,
                'options'     => array_map(fn ($o) => [
                    'key'   => $o['key'],
                    'label' => $locale === 'ar' ? ($o['label_ar'] ?? $o['label']) : $o['label'],
                ], $field['options'] ?? []),
            ];
        }

        return $out;
    }
}
