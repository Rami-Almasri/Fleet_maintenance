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
                'Coolant service',
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
                'Poor fuel economy',
                'Exhaust fault',
                'Fuel system fault',
                // Promoted from understanding-only 2026-09-08: the yard wants these pickable. They
                // are what a garage concludes behind an Overheating report, but they are also things
                // a driver or inspector can put a name to (a fan that never spins, a puddle under
                // the radiator), and refusing the chip only pushed the report into a free-text note.
                'Cooling fan fault',
                'Water pump failure',
                'Radiator damage',
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
                // MOVED HERE FROM `fluids` — a correctness fix, not tidying. `fluids` is on_site:true
                // (a top-up is a mobile job), so while this lived there a brake-fluid leak was offered
                // in the On-Site checklist as something to handle where the car is parked. It is a
                // hydraulic failure on a safety-critical system and belongs in the workshop, which is
                // what `brakes` (on_site:false) says. The ontology always filed it under brakes; only
                // the catalog disagreed, and nothing compared the two until now.
                'Brake-fluid leak',
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
                'Damaged rim',
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
                'Loose steering',
                'Steering noise',
                'Vibration at speed',
                'Steering warning light',
                // Promoted from understanding-only 2026-09-08. Found on a lift rather than on a
                // drive, but the workshop files findings through this same picker, so "not what an
                // inspector observes" was never a reason the WORD could not be selected.
                'Broken spring',
                'Control arm / ball joint',
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
                'Cannot select gear',
                // Filed under Transmission, NOT under "Fluids & Leaks" with the other leaks, and the
                // category is doing real work: `fluids` is on_site (a top-up is a mobile job) while a
                // gearbox seal is not. It must also match the ontology's own category for this concept —
                // the two seeders key on that field and collide when they disagree. See
                // `findings:vocabulary-check`.
                'Transmission fluid leak',
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
                'Starter problem',
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
                // Promoted from understanding-only 2026-09-08 — the diagnosis behind "A/C not
                // cooling", now offerable in its own right.
                'A/C compressor fault',
                'Refrigerant leak',
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
                'Accident damage',
                'Bumper damage',
                'Broken glass / window',
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
                'Interior trim damage',
                'Seat adjustment fault',
                'Water leakage into cabin',
                // A dome / map light is cabin hardware, not driving visibility — "Lights & Visibility"
                // is headlights, indicators and wipers. Matches the ontology's category for it.
                'Interior light fault',
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
        [
            // NEW CATEGORY. These four faults were describable by the ontology but not reportable by the
            // inspector — they had no category to live in, so the matcher could name a fault ("Airbag
            // warning") that the picker could not offer. They are grouped rather than scattered into
            // `electrical` because what they share is that they are the car's OCCUPANT-PROTECTION and
            // driver-assist systems: a warning here is never cosmetic, and a supervisor scanning a ticket
            // should see them together.
            'key'      => 'safety',
            'label'    => 'Safety & Driver Assist',
            'label_ar' => 'أنظمة السلامة والمساعدة',
            'on_site'  => false, // airbag / belt / sensor work needs diagnostic equipment
            'keywords' => [
                'Airbag warning',
                'Seat belt fault',
                'Parking sensor fault',
                'Camera / ADAS fault',
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
     * UNDERSTANDING-ONLY fault concepts — the ontology may name them, the inspector may not select them.
     *
     * The fault ontology (database/seeders/ontology/*.php) is deliberately WIDER than this catalog: it
     * has to recognise the words people actually write, and people write about causes as readily as
     * symptoms. Seeding a concept creates its `finding_keywords` row, so without this list every
     * ontology concept silently became a fault the matcher could propose and the picker could not
     * offer — a dead end for the inspector and an unanswerable suggestion on screen.
     *
     * The line is WHO OBSERVES IT. An inspector on a test drive reports what the car DID ("Overheating",
     * "Vibration at speed"). A water pump failure is what a garage CONCLUDES after opening it up — it
     * arrives through the repair-capture path, not the findings picker. Listing it here says "we know
     * about this fault, we understand text that mentions it, and it is not a menu item" — which is a
     * different and much more honest statement than the row simply being absent.
     *
     * Entries are matched normalised (TextNormalizer::key), like everything else in the vocabulary.
     * `findings:vocabulary-check` fails if a `finding_keywords` row is neither selectable above nor
     * declared here, so a new ontology concept cannot quietly reintroduce the dead end.
     */
    'understanding_only' => [
        // ── NARROWED 2026-09-08 ──────────────────────────────────────────────────────────────────
        // Seven concepts moved OUT of this list and into the categories above, on the instruction
        // that the picker should offer them: Cooling fan fault, Water pump failure, Radiator damage,
        // A/C compressor fault, Refrigerant leak, Broken spring, Control arm / ball joint.
        //
        // The original line was WHO OBSERVES IT — symptoms are reported, causes are concluded. That
        // held while the picker belonged to the test-drive report alone. It does not any more: the
        // workshop files its findings through the same picker, so a garage's conclusion has a filer,
        // and withholding the chip only pushed the same fact into a free-text note where nothing
        // counts it. The four below stay, because each would break the picker in a way that has
        // nothing to do with who is holding it.
        // ─────────────────────────────────────────────────────────────────────────────────────────

        // The scheduled visit AS A WHOLE. The matcher must recognise "periodic maintenance" — it is the
        // commonest planned-work phrase in the corpus and the one the Event Type resolver most needs to
        // read as SERVICE — but it is not a finding: an inspector records the ITEMS performed (Oil
        // Change, Air Filter), never the visit itself. Offering it as a chip would let a whole visit be
        // filed as one finding with nothing said about what was actually done.
        'Periodic Maintenance',

        // Electrical — too broad to action as a finding on its own. "Sensor failure" names no system,
        // so as a menu item it would collect the reports that belong on a specific fault. Not a
        // question of who observes it: it is a bucket, and buckets fill.
        'Sensor failure',

        // Already selectable under a different name — kept as vocabulary so the wording still resolves,
        // but not offered twice. Door locks → "Power window / lock fault"; immobiliser → "Central
        // locking / key fob". Adding these would put two chips on screen for one fault and split its
        // history between them.
        'Door lock fault',
        'Key / immobiliser fault',
    ],

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

        // ARABIC. The fleet reports faults in Arabic daily and this list was English-only, so every
        // Arabic symptom fell through to "the ticket looks routine → it's a service" and was stored as
        // planned work with needs_review=false. See docs/Service-Fault-Separation-Audit.md C1.
        // This is the cheap first pass; the ontology (289 Arabic terms) is the real check behind it.
        'عطل', 'خربان', 'خربانة', 'ما يشتغل', 'لا يعمل', 'مايشتغل', 'صوت', 'ضجيج', 'طقطقة',
        'تسريب', 'تسرب', 'حرارة', 'يحما', 'دخان', 'اهتزاز', 'رجة', 'يرجف', 'مكسور', 'كسر',
        'لمبة', 'تحذير', 'ما يبرد', 'مايبرد', 'ضعف', 'يخبط', 'بطارية فاضية', 'ما يشحن',
    ],

    /**
     * Ontology categories that are PLANNED WORK, not failures. The Event Type resolver asks the fault
     * ontology which lane a symptom belongs to (KeywordOntologyService), and a confident match in one of
     * these categories is service evidence; a confident match in any other category is fault evidence.
     * Kept in config rather than hard-coded so the ontology can grow a second service category without a
     * code change. Mirrors FaultVocabulary::NON_FAILURE_CATEGORIES minus the cosmetic ones (a scratch is
     * still an unplanned defect — cosmetic is a severity question, not a type question).
     */
    'service_ontology_categories' => ['routine'],

];
