<?php

/**
 * Central "Findings" library for the Fleet Maintenance Workflow.
 *
 * This is the single source of truth for the quick-pick issue keywords the Inspector (Abu Maroof)
 * taps on the test-drive report, and the workshop taps when logging garage-identified findings.
 * It used to be a hardcoded 10-item array buried in the React modal; centralising it here means the
 * library can grow without touching component code, stays versioned in git, and is served to every
 * client from one place (GET /maintenance-tickets/findings-catalog).
 *
 * Each finding still persists exactly as before — a tag string inside the `maintenances.findings`
 * JSON array — so the catalog is purely the *menu*; nothing about storage or analytics changes when
 * you add a keyword here. Adding a category or a keyword is a one-line edit, no migration.
 *
 * Shape: a list of categories, each `{ key, label, label_ar, keywords[], on_site }`. `key` is a stable
 * slug (don't rename it once data references it); `label` / `label_ar` are what the UI prints in each
 * language; `keywords` is the English menu that gets saved. Arabic keyword translations are seeded
 * into the `finding_keywords` table (keyword_ar) — see FindingKeywordSeeder.
 *
 * `on_site` (bool) is the Repair-Location eligibility flag: TRUE means this category's faults are minor
 * enough to fix where the car is parked (a mobile job), so it stays in the On-Site checklist; FALSE
 * means it needs the workshop and only shows once In-Shop is chosen. It drives the dynamic checklist
 * (the FindingsPicker filters by it) and the suggested default location at the Decide step. The split
 * is intentionally coarse (per category, not per keyword) so it's a one-line edit with no migration.
 * NOTE: "Battery / won't start" lives under `electrical` (in_shop) even though a battery swap is a
 * classic mobile job — flip `electrical` to on_site, or split battery into its own category, if the
 * team wants battery jobs to appear in the On-Site checklist by default.
 */

return [

    'categories' => [
        [
            // Routine, scheduled servicing — NOT a breakdown. These are the planned jobs (oil, battery)
            // the team does on a cadence, often IN-HOUSE / on-site. Kept first so they're the quickest to
            // tap when logging a routine visit. `on_site` = true: they need no workshop by default (and the
            // oil/battery tokens are already in `on_site_keywords` below). Completing one of these faults
            // rolls its odometer forward into the matching Service Reminder — see `routine_service_types`.
            'key'      => 'routine',
            'label'    => 'Routine Maintenance',
            'label_ar' => 'الصيانة الدورية',
            'on_site'  => true,
            'keywords' => [
                'Oil Change',
                'Battery Replacement',
                'Oil Filter',
                'Air Filter',
            ],
        ],
        [
            'key'      => 'engine',
            'label'    => 'Engine',
            'label_ar' => 'المحرك',
            'on_site'  => false, // major — needs the workshop
            'keywords' => [
                'Engine noise',
                'Rough idle / misfire',
                'Loss of power',
                'Excessive exhaust smoke',
                'Overheating',
                'Stalling',
                'Hard starting',
                'Check-engine light',
            ],
        ],
        [
            'key'      => 'brakes',
            'label'    => 'Brakes',
            'label_ar' => 'الفرامل',
            'on_site'  => false, // safety-critical — workshop only
            'keywords' => [
                'Brake noise (squeal / grind)',
                'Soft / spongy pedal',
                'Vibration when braking',
                'Pulling to one side',
                'Worn pads / discs',
                'Handbrake fault',
                'ABS warning light',
            ],
        ],
        [
            'key'      => 'tyres',
            'label'    => 'Tyres & Wheels',
            'label_ar' => 'الإطارات والعجلات',
            'on_site'  => true, // minor — pressure / check / swap can be done on the spot
            'keywords' => [
                'Tire Rotation',
                'Tire Change',
                'Worn / bald tyre',
                'Puncture / slow leak',
                'Uneven tyre wear',
                'Wheel alignment',
                'Wheel balancing',
                'TPMS / tyre-pressure warning',
            ],
        ],
        [
            'key'      => 'suspension',
            'label'    => 'Suspension & Steering',
            'label_ar' => 'التعليق والتوجيه',
            'on_site'  => false, // major — needs the workshop
            'keywords' => [
                'Knocking over bumps',
                'Steering vibration',
                'Hard / heavy steering',
                'Pulling / drifting',
                'Worn shock / strut',
                'Wheel-bearing noise',
            ],
        ],
        [
            'key'      => 'transmission',
            'label'    => 'Transmission & Drivetrain',
            'label_ar' => 'ناقل الحركة (الجير)',
            'on_site'  => false, // major — needs the workshop
            'keywords' => [
                'Gear slipping',
                'Hard / jerky shifting',
                'Clutch issue',
                'Whining / grinding noise',
                'Delayed engagement',
            ],
        ],
        [
            'key'      => 'electrical',
            'label'    => 'Electrical',
            'label_ar' => 'الكهرباء',
            'on_site'  => false, // alternator / wiring / fuse work needs the workshop (see battery note above)
            'keywords' => [
                'Battery / won\'t start',
                'Alternator / charging fault',
                'Warning light on dash',
                'Power window / lock fault',
                'Central locking / key fob',
                'Wiring / fuse issue',
            ],
        ],
        [
            'key'      => 'ac',
            'label'    => 'Climate / A/C',
            'label_ar' => 'التكييف',
            'on_site'  => false, // A/C work needs shop equipment (gas recharge / compressor)
            'keywords' => [
                'A/C not cooling',
                'Heater not working',
                'Weak airflow',
                'Bad smell from vents',
                'Noisy blower',
            ],
        ],
        [
            'key'      => 'bodywork',
            'label'    => 'Bodywork & Exterior',
            'label_ar' => 'الهيكل والمظهر الخارجي',
            'on_site'  => false, // panel / paint / glass work needs the workshop
            'keywords' => [
                'Dent',
                'Scratch',
                'Paint damage',
                'Rust / corrosion',
                'Broken / loose mirror',
                'Windscreen crack / chip',
                'Door / panel misalignment',
            ],
        ],
        [
            'key'      => 'interior',
            'label'    => 'Interior',
            'label_ar' => 'المقصورة الداخلية',
            'on_site'  => true, // minor trim / odour / small fixes can be handled on the spot
            'keywords' => [
                'Seat / upholstery damage',
                'Dashboard fault',
                'Infotainment / screen issue',
                'Broken trim',
                'Bad odour',
            ],
        ],
        [
            'key'      => 'fluids',
            'label'    => 'Fluids & Leaks',
            'label_ar' => 'السوائل والتسريبات',
            'on_site'  => true, // top-ups / checks are mobile (a full oil change is still better in-shop)
            'keywords' => [
                'Oil leak',
                'Coolant leak',
                'Brake-fluid leak',
                'Power-steering leak',
                'Fuel smell / leak',
                'Low fluid level',
            ],
        ],
        [
            'key'      => 'lights',
            'label'    => 'Lights & Visibility',
            'label_ar' => 'الإضاءة والرؤية',
            'on_site'  => true, // bulb / wiper swaps are quick mobile jobs
            'keywords' => [
                'Headlight out',
                'Tail / brake light out',
                'Indicator fault',
                'Wiper / washer fault',
                'Foggy / dim lights',
            ],
        ],
    ],

    /**
     * "Auto-On-Site" keyword rule. Some quick mobile jobs live INSIDE a category that is otherwise
     * In-Shop — the classic examples being a BATTERY swap (under `electrical`) and an OIL top-up/change
     * (under `fluids`). Whenever a finding's text contains one of these tokens (case-insensitive
     * substring), the Decide step SUGGESTS On-Site by default regardless of the finding's category, and
     * the token is surfaced in the On-Site checklist even though its parent category is In-Shop. It is
     * only a suggested default — the Supervisor can always switch the ticket to In-Shop. One-line editable.
     */
    'on_site_keywords' => ['battery', 'oil'],

    /**
     * Routine-service → Service-Reminder bridge. A finding whose text (normalised: trimmed + lower-cased)
     * exactly matches one of these keys is a SCHEDULED service, not a one-off fault: when its
     * maintenance_task is marked fixed, the odometer at that moment rolls the matching recurring
     * ServiceReminder forward (Vehicle::recordServiceDone → next-due recomputed) so the next reminder
     * fires on schedule. `oil_change` additionally re-anchors the car's own serviceStatus() baseline.
     * The service_type slugs mirror ServiceReminder::TYPE_LABELS. Add a routine keyword above AND here to
     * make it close its reminder loop; a keyword not listed here is just a normal fault. One-line editable.
     */
    'routine_service_types' => [
        'oil change'          => 'oil_change',
        'oil filter'          => 'oil_filter',
        'air filter'          => 'air_filter',
        'battery replacement' => 'battery',
        'tire rotation'       => 'tire_rotation',
        'tire change'         => 'tire_change',
    ],

    /**
     * CLEAR fault evidence (Event Type layer). A symptom containing one of these substrings is an
     * unplanned FAILURE, so it must be classified `fault` even when the ticket it sits on is a
     * Routine/Periodic visit — a "Brake Failure" found during a routine inspection is still a fault.
     * The rule "a clear fault symptom beats ticket context" (EventClassificationService::resolveLegacyKind)
     * reads this list. Deliberately unambiguous failure words only — routine service names ("oil change",
     * "brake pads", "wheel alignment") contain none of them, so they stay `service`. One-line editable.
     */
    'fault_evidence_keywords' => [
        'failure', 'failed', 'fault', 'broken', 'not working', 'no start', 'won\'t start', 'wont start',
        'noise', 'knocking', 'grinding', 'rattling', 'leak', 'overheat', 'smoke', 'warning light',
        'check engine', 'misfire', 'vibration', 'shaking', 'stall', 'dead', 'malfunction', 'damage',
        'cracked', 'worn out', 'stuck', 'loss of power', 'rough idle', 'burning', 'won\'t turn',
    ],

];
