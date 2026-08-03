<?php

/**
 * Parts Purchase + Repair Intelligence — tuning knobs for the classifier and the duplicate / recurrence
 * detection engine ({@see \App\Services\PartIntelligenceService}). Kept in config (not hard-coded) so the
 * windows and keyword lists can be tuned per fleet without a code change, and later promoted to an admin
 * screen if needed.
 *
 * CLASSIFICATION drives ALERT PRIORITY:
 *   consumable → a repeat buy is normal (oil/air filters, brake pads…) → alerts suppressed.
 *   major      → a high-value component (engine, transmission, ECU, turbo…) → a repeat within a long
 *                window is CRITICAL/HIGH.
 *   standard   → everything else → a repeat within a medium window is MEDIUM.
 *
 * The SHORT window is class-independent: ANY non-consumable part re-bought within `short_days` is HIGH,
 * because buying the same real part twice within a few days almost always signals a failed fix / wrong
 * diagnosis regardless of the part's value.
 */
return [

    // A part matches "major" / "consumable" if its name, part_number or category_key contains one of
    // these substrings (case-insensitive). category_key is matched against the findings catalog too.
    'major_keywords' => [
        'engine', 'transmission', 'gearbox', 'ecu', 'ecm', 'tcu', 'turbo', 'turbocharger',
        'cylinder head', 'timing chain', 'compressor', 'differential', 'catalytic',
        'clutch assembly', 'radiator', 'alternator', 'starter motor', 'fuel pump',
    ],

    'consumable_keywords' => [
        'oil filter', 'air filter', 'cabin filter', 'fuel filter', 'brake pad', 'brake pads',
        'wiper', 'wiper blade', 'bulb', 'fuse', 'spark plug', 'coolant', 'engine oil',
        'washer fluid', 'ac filter', 'pollen filter',
    ],

    // category_key → class shortcut (checked before keyword matching). Keys come from
    // config/maintenance_findings.php. 'routine' consumables never alert; the heavy systems are major.
    'category_class' => [
        'routine'      => 'consumable',
        'fluids'       => 'consumable',
        'engine'       => 'major',
        'transmission' => 'major',
        'electrical'   => 'standard',
    ],

    // Detection windows (days). A repeat OUTSIDE every window raises no alert (e.g. same part a year later).
    'windows' => [
        'short_days'    => 7,    // any non-consumable repeat here → HIGH  (edge case 1)
        'standard_days' => 60,   // a 'standard' part repeat here → MEDIUM (Part 7 medium)
        'major_days'    => 90,   // a 'major' part repeat here → HIGH/CRITICAL (edge case 4: ECU twice/month)
    ],

    // Fault-recurrence: a previously-completed fault of the same category/symptom reappearing within this
    // many days surfaces a repair-history WARNING at diagnosis…
    'recurrence' => [
        'window_days'          => 90,  // show the "repaired before" warning within this window (edge case 5)
        'investigation_days'   => 30,  // …and additionally open a fault_recurrence investigation if within this
    ],

    // The full purchase record shown at buy time. The alert WINDOWS above decide whether to warn; this
    // decides how much of the part's life story is handed to the buyer, and it is deliberately unwindowed —
    // a part bought two years ago is still the same part on the same car, and the buyer should see it.
    // The cap only guards the payload size for a pathological history; the API reports when it truncates.
    'history' => [
        'max_records' => 50,
    ],

    // Only this currency feeds the maintenance cost roll-up on install; others are recorded + flagged.
    'base_currency' => 'AED',
];
