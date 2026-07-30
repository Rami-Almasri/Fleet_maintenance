<?php

namespace App\Support;

/**
 * Vehicle scoping for the knowledge graph — "true for every car" vs "true for this BMW".
 *
 * The same fault means different things on different vehicles. Brake noise on a Toyota Camry is
 * usually worn pads; on a BMW with electronic parking brakes it can be the EPB actuator; on some
 * Mercedes models a low-metallic pad squeal is documented as normal. If the ontology stored one
 * universal answer, it would be wrong for most of the fleet most of the time.
 *
 * So every node, edge, profile and document carries a `scope_key`: a single denormalised string
 * that says how specific the claim is.
 *
 *      '*'                          universal — true of cars in general
 *      'bmw'                        true of BMWs
 *      'bmw|3-series'               true of the 3-series
 *      'bmw|3-series|g20'           true of that generation
 *      'bmw|3-series|g20|b48'       true of that engine
 *
 * WHY ONE STRING AND NOT SIX NULLABLE COLUMNS. MySQL treats NULLs as distinct in a unique index,
 * so `unique(from, to, relation, make, model, generation)` would happily accept the same universal
 * edge a hundred times over. Collapsing scope into one non-null string makes the uniqueness
 * constraint actually hold, and turns scope matching into one indexed `whereIn` instead of six
 * nullable comparisons per query.
 *
 * MATCHING IS BY PREFIX, WIDEST FIRST. Asking about a specific car reads every scope from '*' down
 * to its own — a BMW 3-series query sees universal knowledge AND BMW knowledge AND 3-series
 * knowledge, and `specificity()` is what lets the caller prefer the narrowest answer available
 * while still falling back to the general one. A Toyota query never sees any of the BMW rows.
 */
final class VehicleScope
{
    public const UNIVERSAL = '*';

    /** Build a scope key from its parts. Any blank part truncates the key — scope is a hierarchy. */
    public static function key(?string $make = null, ?string $model = null, ?string $generation = null, ?string $engine = null): string
    {
        $parts = [];

        foreach ([$make, $model, $generation, $engine] as $part) {
            $slug = self::slug($part);
            if ($slug === '') {
                break;      // "BMW, no model, but the B48 engine" is not a scope we can express
            }
            $parts[] = $slug;
        }

        return $parts === [] ? self::UNIVERSAL : implode('|', $parts);
    }

    /**
     * Every scope key that applies to a given vehicle, widest first: ['*', 'bmw', 'bmw|3-series', …].
     * This is the list a query filters on — it is what makes specific knowledge override general
     * knowledge without hiding the general knowledge when nothing specific exists.
     *
     * @return array<int,string>
     */
    public static function chain(?string $make = null, ?string $model = null, ?string $generation = null, ?string $engine = null): array
    {
        $chain = [self::UNIVERSAL];
        $parts = [];

        foreach ([$make, $model, $generation, $engine] as $part) {
            $slug = self::slug($part);
            if ($slug === '') {
                break;
            }
            $parts[] = $slug;
            $chain[] = implode('|', $parts);
        }

        return $chain;
    }

    /** How specific a scope key is: 0 for universal, 1 for a make, 4 for make+model+gen+engine. */
    public static function specificity(?string $scopeKey): int
    {
        if (blank($scopeKey) || $scopeKey === self::UNIVERSAL) {
            return 0;
        }

        return count(explode('|', $scopeKey));
    }

    /** Human-readable form for the UI: 'bmw|3-series' → 'BMW 3-Series'. */
    public static function label(?string $scopeKey): ?string
    {
        if (blank($scopeKey) || $scopeKey === self::UNIVERSAL) {
            return null;    // callers render "All vehicles" in their own language
        }

        return collect(explode('|', $scopeKey))
            ->map(fn ($p) => str_replace('-', ' ', $p))
            ->map(fn ($p) => mb_strlen($p) <= 3 ? mb_strtoupper($p) : ucwords($p))
            ->implode(' ');
    }

    /** The scope chain for a Vehicle model, tolerating the fields it may not have populated. */
    public static function forVehicle(?object $vehicle): array
    {
        if (! $vehicle) {
            return [self::UNIVERSAL];
        }

        return self::chain(
            $vehicle->make ?? $vehicle->brand ?? null,
            $vehicle->model ?? null,
            $vehicle->generation ?? null,
        );
    }

    /** Lower-case, hyphenated comparison slug. '3 Series' → '3-series'. */
    private static function slug(?string $value): string
    {
        $s = mb_strtolower(trim((string) $value));
        $s = preg_replace('/[^a-z0-9]+/u', '-', $s);

        return trim((string) $s, '-');
    }
}
