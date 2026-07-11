<?php

/**
 * Reference data + tuning surface for the Smart Routing Engine.
 *
 * The engine automatically suggests which garage a maintenance ticket should go to, based on the
 * fault type(s), the vehicle's class, the garage's specialisation, and its quality track record.
 * This file is the single place to tune that behaviour WITHOUT a migration — same "config is the
 * source for reference data, DB is the runtime source of truth for the rules" pattern as
 * config/maintenance_findings.php (findings) and config/fault_causes.php (root causes).
 *
 * What lives here vs. in the DB:
 *   - Reference lists (vehicle classes, the routing dimensions, scoring weights) live HERE. They're
 *     app-wide constants the admin doesn't edit per-garage, and validation reads them directly
 *     (Rule::in), exactly like FindingKeywordController validates category_key against
 *     config('maintenance_findings.categories').
 *   - The actual "Garage X is a specialist in Electrical" rows live in the `garage_routing_rules`
 *     TABLE, edited from the admin dashboard. They reference a vendor_id, which is specific to each
 *     deployment's own garages — so we can't hardcode them. The `default_rules` array below is an
 *     OPTIONAL seed keyed by garage NAME; GarageRoutingRuleSeeder resolves the name to a vendor and
 *     upserts it, skipping any name that doesn't match a garage. It ships empty on purpose (see the
 *     worked example in the comment) so seeding is a safe no-op until you either add entries here or
 *     create rules in the UI.
 *
 * NOTE: this file only DEFINES the data. The scoring service that consumes it comes next; the weights
 * below are the knobs it will read, surfaced early so the whole engine has one tuning surface.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Vehicle classes  (hand-editable)
    |--------------------------------------------------------------------------
    | The manager classifies each car into one of these buckets (a `vehicle_class` column on the
    | `vehicles` table, set manually — "I know my cars best"). A car with no class set falls back to
    | `default_vehicle_class`, so nothing is ever unroutable. `key` is a stable slug — don't rename it
    | once rules reference it; `label` is what the UI prints. Add / remove buckets freely.
    */
    'vehicle_classes' => [
        ['key' => 'standard',     'label' => 'Standard Fleet'],
        ['key' => 'luxury_suv',   'label' => 'Luxury SUV'],
        ['key' => 'luxury_sedan', 'label' => 'Luxury Sedan'],
        ['key' => 'commercial',   'label' => 'Commercial / Van'],
        ['key' => 'electric',     'label' => 'Electric / Hybrid'],
    ],

    // Applied when a car's `vehicle_class` is blank, so the router always has a class to match on.
    'default_vehicle_class' => 'standard',

    /*
    |--------------------------------------------------------------------------
    | Routing dimensions
    |--------------------------------------------------------------------------
    | The axes a rule can score on. A rule row is (vendor, dimension, match_key, weight):
    |   - fault_category → match_key is a findings category slug (engine / ac / electrical …),
    |     validated against config('maintenance_findings.categories').
    |   - vehicle_class  → match_key is one of the `vehicle_classes` keys above.
    | Kept as a list so a new axis (e.g. 'region') is a one-line addition here + a Rule::in pass.
    */
    'dimensions' => [
        'fault_category',
        'vehicle_class',
    ],

    /*
    |--------------------------------------------------------------------------
    | Scoring weights  (the tuning knobs the engine will read)
    |--------------------------------------------------------------------------
    | Higher score = stronger suggestion. Per-rule `weight` columns override `default_weight`; these
    | are the app-wide defaults and the SOFT-PENALTY dial. Per the agreed strategy the quality history
    | never HARD-BLOCKS a garage — a poor record only subtracts points and raises a warning badge, so
    | a struggling garage can still be picked in an emergency.
    */
    'scoring' => [
        // Default points a positive rule contributes when its own `weight` isn't customised.
        'default_weight'          => 10,
        // A rule flagged `is_specialist` is the headline reason; it outranks a plain preference.
        'specialist_weight'       => 20,
        // Small nudge from the garage's own `vendors.rating` (0–5) — multiplied by this per star.
        'rating_weight_per_star'  => 2,

        // --- Soft penalty (quality history) — never disqualifies, only warns. ---
        // Points subtracted per failed re-inspection this garage has on the same fault category.
        'failure_penalty_each'    => 8,
        // Ceiling on that penalty so one bad garage can't score to negative infinity.
        'failure_penalty_max'     => 24,
        // A garage whose failure_rate on the fault exceeds this gets a ⚠️ warning badge on the
        // suggestion (still selectable). This is the "warn us, don't block" threshold.
        'warn_failure_rate'       => 0.40,
    ],

    /*
    |--------------------------------------------------------------------------
    | Default rules  (optional seed, keyed by garage NAME)
    |--------------------------------------------------------------------------
    | Ships EMPTY: real garage→fault/class mappings are deployment-specific and are normally created
    | from the admin dashboard. To pre-seed, add entries keyed by the exact `vendors.name`; the seeder
    | resolves the name to a vendor_id and upserts idempotently, silently skipping unknown names.
    |
    | Worked example (uncomment and rename to your own garages):
    |
    | 'Al Manar Auto Care' => [
    |     ['dimension' => 'fault_category', 'match_key' => 'electrical',   'weight' => 20, 'is_specialist' => true,  'note' => 'German-marque electrical specialist'],
    |     ['dimension' => 'vehicle_class',  'match_key' => 'luxury_suv',   'weight' => 15, 'is_specialist' => true],
    | ],
    | 'Speed Fix Garage' => [
    |     ['dimension' => 'fault_category', 'match_key' => 'tyres',        'weight' => 12, 'is_specialist' => false],
    |     ['dimension' => 'vehicle_class',  'match_key' => 'standard',     'weight' => 10, 'is_specialist' => false],
    | ],
    */
    'default_rules' => [
        // (empty — manage from the admin dashboard, or add named entries above)
    ],

];
