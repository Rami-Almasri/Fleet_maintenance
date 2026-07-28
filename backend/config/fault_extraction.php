<?php

/**
 * Conservative free-text → fault-category extractor map, used by GarageRecommendationService when it
 * builds the history dataset. Most historical maintenances predate the structured findings/tasks layer:
 * their fault lives in the free-text `service_main` / `service_sup` columns (imported from the
 * N-Maintenance sheet — "Body Damage", "Oil & Fillter Change", "Suspension Troubles", "AC Not Cooling"…).
 * This map turns that text into the SAME catalog category keys the workflow uses
 * (config/maintenance_findings.php), so a 25k-row backlog contributes real fault signal to the
 * recommendation engine instead of only model/brand signal.
 *
 * Design — CONSERVATIVE by construction (precision over recall):
 *   - Each category lists lowercase substrings. The extractor lowercases + space-pads each comma-phrase
 *     and assigns a category only when one of its substrings appears. A phrase can match several
 *     categories ("periodic m & damage" → routine + bodywork); the union is kept.
 *   - Tokens are chosen to be specific: "brak" (brake/braking), " ac " (padded, so it never fires on
 *     "accident"/"accessories"), "warning light"/"dashboard" route to electrical NOT lights, etc.
 *     Anything unrecognised (Testing / New Car / Ready / Customer / Accessories / bare "Maintenance")
 *     yields NO category — better a blank than a wrong one.
 *   - Only these canonical keys are emitted; the engine filters to valid catalog keys anyway.
 *
 * Tune here with no migration (same pattern as config/maintenance_findings.php and garage_routing.php).
 * `php artisan maintenance:fault-extraction-audit` prints coverage + raw→category samples to sanity-check
 * any change on live data.
 */

return [

    // category_key => [lowercase substrings that imply it]. Order within a category is irrelevant; the
    // extractor tests every substring and unions the matches across all phrases + columns.
    'map' => [

        'engine' => [
            'engine', 'mechanical', 'overheat', 'coolant', 'cooling system', 'radiator',
            'misfire', 'timing belt', 'timing chain', 'piston', 'cylinder', 'turbo', 'spark plug',
        ],

        'transmission' => [
            'transmission', 'gearbox', 'gear box', 'clutch', 'gear not', 'gearshift', 'differential',
        ],

        'brakes' => [
            'brak', 'brake pad', 'brake disc', 'handbrake',
        ],

        // NOTE: 'rim'/'scratch' are BODYWORK (cosmetic), not tyres — kept out of here on purpose.
        'tyres' => [
            'tyre', 'tire', 'wheel align', 'alignment', 'puncture', 'tread', 'flat tire', 'flat tyre',
        ],

        'suspension' => [
            'suspension', 'steering', 'shock', 'strut', 'control arm', 'ball joint', 'bushing',
            'lower arm', 'wishbone',
        ],

        'electrical' => [
            'electrical', 'electric', 'battery', 'airbag', 'acc programming', 'sensor', 'wiring',
            'warning light', 'dashboard', 'infotainment', 'lcd', 'fuse', 'alternator', 'starter',
            'ecu', 'immobilizer', 'central lock',
        ],

        // Space-padded so it never fires on accident/accessories/back; 'a/c' catches the slash form.
        'ac' => [
            ' ac ', 'a/c', 'air condition', 'aircon', 'not cooling', 'a.c', 'climate control',
        ],

        'bodywork' => [
            'body', 'exterior', 'scratch', 'dent', 'bumper', 'paint', 'panel', 'fender', 'accident',
            'diffuser', ' lip', 'rim', 'wrap', 'polish', 'collision', 'windshield', 'windscreen',
            'glass', 'mirror', 'door damage', 'peeling',
        ],

        'interior' => [
            'interior', 'upholstery', 'seat', 'chair', 'cabin', 'deep clean', 'carpet', 'trim',
            'headliner', 'odor', 'smell',
        ],

        // Leaks only — "oil & filter change" is routine (below), not a leak.
        'fluids' => [
            'fluid', 'leak', 'oil seep', 'coolant leak',
        ],

        // Exterior lights only — "check engine light" / "warning light" / "dashboard light" are engine/
        // electrical (handled above), so we use specific lamp terms + "light issue".
        'lights' => [
            'headlight', 'taillight', 'head lamp', 'tail lamp', 'fog light', 'fog lamp',
            'indicator light', 'brake light', 'light issue', 'bulb',
        ],

        // Scheduled service. Avoids bare "maintenance"/"service" (too generic → noise).
        'routine' => [
            'oil & filter', 'oil and filter', 'oil filter', 'oil & fillter', 'oil fillter',
            'oil change', 'filter change', 'periodic', 'scheduled service', 'pms ', 'regular service',
        ],
    ],
];
