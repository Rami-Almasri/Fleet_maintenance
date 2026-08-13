<?php

/**
 * THE VEHICLE LOCATION VOCABULARY — the one answer to "WHERE on the car is it?".
 *
 * ── WHY THIS FILE EXISTS ──────────────────────────────────────────────────────────────────────────
 * Until now an event knew WHAT it was (`fault_catalog` / `damage_catalog` / `service_catalog` /
 * `inspection_types` → `maintenance_tasks.kind`) and HOW MANY was never asked at all. "Where" was
 * either absent, or smuggled into the wording of the type itself — `door_dent`, `rim_scratch`,
 * `front_lip_damage` — which is why the catalog needed 3 separate rows to say "dent" about 3 panels.
 * That does not scale: the moment a fourth panel is dented somebody types free text, and the fleet
 * loses the ability to ask "how much damage do we take on rear bumpers?".
 *
 * A location is therefore a SEPARATE axis from the type, exactly like severity is:
 *
 *      WHAT (catalog row)  ×  HOW MANY (quantity)  ×  WHERE (0..n locations)
 *
 * Scratch is a fault type. Front Bumper is a place. "2 scratches — front bumper and hood" is those
 * three facts, stored as three facts, and rendered into one sentence by {@see App\Support\FaultPhrase}.
 * Nothing here is scratch-specific and nothing ever should be: a dent, a crack, a leak, a broken
 * light and a fault type invented next year all use the same axis with no new code.
 *
 * ── WHERE THIS VOCABULARY CAME FROM (it is not a new invention) ───────────────────────────────────
 * Three location vocabularies already existed in the platform, each partial, none reusable:
 *   1. `VEHICLE_ZONES` (frontend/src/components/inspection/VehicleDiagram.js) — the 11 clickable
 *      exterior panels of the inspection hotspot diagram, persisted as `inspection_records.body_part`.
 *      Every one of those ids is reproduced below VERBATIM in `inspection_zone`, so a location picked
 *      on a fault and a photo taken on the diagram point at the same panel and can be joined.
 *   2. `damage_catalog.area_key` — a coarse, catalog-level hint (wheel / door / bumper / windscreen …)
 *      that says where a damage TYPE usually is. It stays where it is; the `area_key` column of each
 *      row below is what maps those hints onto real places.
 *   3. `component_catalog.position_scheme` (axle_corner: front_left … rear_right) — the corner
 *      vocabulary for physical parts. The four wheel/tyre locations use exactly those four keys.
 * This file UNIFIES them. It does not replace any of them, and it adds no second diagram.
 *
 * ── GRANULARITY IS DELIBERATE, AND MIXED ─────────────────────────────────────────────────────────
 * `body` (the whole car) and `front_bumper` (one panel) are BOTH valid answers, and both are kept.
 * The operational reality this models is an inspector reporting "2 scratches — rims and body":
 * he genuinely means "one on a rim, one somewhere on the paint". Forcing him to pick a
 * panel he did not look at would manufacture precision that does not exist — see
 * [[treat-data-as-source-of-truth]]. `precision` records which kind of answer a location is, so a
 * report can separate "we know the panel" from "we know it's the body somewhere" without guessing.
 *
 * ── SHAPE OF A ROW ────────────────────────────────────────────────────────────────────────────────
 *   slug            stable key; NEVER rename one once tasks reference it (retire with is_active=false)
 *   name / name_ar  what the UI prints, in each language
 *   group_key       which section of the picker it lives in (see `groups` below)
 *   precision       'panel' | 'corner' | 'zone' | 'component' | 'whole'  — how specific it is
 *   inspection_zone the matching VehicleDiagram zone id, or null when the diagram has no such panel
 *   area_key        the damage_catalog.area_key this location satisfies (many locations → one area)
 *   aliases         search synonyms (EN + AR), matched by LIKE exactly as ComponentCatalog does
 *
 * Seeded into the `vehicle_locations` table by VehicleLocationSeeder (idempotent upsert by slug).
 */

return [

    // ── Picker sections ───────────────────────────────────────────────────────────────────────────
    'groups' => [
        ['key' => 'exterior',   'label' => 'Exterior',            'label_ar' => 'الهيكل الخارجي', 'sort_order' => 10],
        ['key' => 'wheels',     'label' => 'Wheels & Tyres',      'label_ar' => 'العجلات والإطارات', 'sort_order' => 20],
        ['key' => 'glass',      'label' => 'Glass & Mirrors',     'label_ar' => 'الزجاج والمرايا', 'sort_order' => 30],
        ['key' => 'lights',     'label' => 'Lights',              'label_ar' => 'الإضاءة', 'sort_order' => 40],
        ['key' => 'interior',   'label' => 'Interior',            'label_ar' => 'المقصورة الداخلية', 'sort_order' => 50],
        ['key' => 'mechanical', 'label' => 'Mechanical & Under',  'label_ar' => 'الميكانيك وأسفل السيارة', 'sort_order' => 60],
    ],

    'locations' => [

        // ── Exterior panels ───────────────────────────────────────────────────────────────────────
        // Every `inspection_zone` below is a real id from VEHICLE_ZONES. Do not invent one.
        ['slug' => 'front_bumper', 'name' => 'Front Bumper', 'name_ar' => 'الصدام الأمامي', 'group_key' => 'exterior', 'precision' => 'panel', 'inspection_zone' => 'front_bumper', 'area_key' => 'front_bumper', 'aliases' => ['bumper', 'front lip', 'صدام امامي'], 'sort_order' => 100],
        ['slug' => 'rear_bumper',  'name' => 'Rear Bumper',  'name_ar' => 'الصدام الخلفي',  'group_key' => 'exterior', 'precision' => 'panel', 'inspection_zone' => 'rear_bumper',  'area_key' => 'bumper',       'aliases' => ['صدام خلفي'], 'sort_order' => 110],
        ['slug' => 'hood',         'name' => 'Hood / Bonnet', 'name_ar' => 'غطاء المحرك',   'group_key' => 'exterior', 'precision' => 'panel', 'inspection_zone' => 'hood',         'area_key' => null,           'aliases' => ['bonnet', 'كبوت'], 'sort_order' => 120],
        ['slug' => 'roof',         'name' => 'Roof',          'name_ar' => 'السقف',         'group_key' => 'exterior', 'precision' => 'panel', 'inspection_zone' => 'roof',         'area_key' => null,           'aliases' => ['سقف'], 'sort_order' => 130],
        ['slug' => 'trunk',        'name' => 'Trunk / Boot',  'name_ar' => 'الشنطة',        'group_key' => 'exterior', 'precision' => 'panel', 'inspection_zone' => 'trunk',        'area_key' => null,           'aliases' => ['boot', 'tailgate', 'شنطة'], 'sort_order' => 140],

        ['slug' => 'door_front_left',  'name' => 'Front-Left Door',  'name_ar' => 'الباب الأمامي الأيسر', 'group_key' => 'exterior', 'precision' => 'panel', 'inspection_zone' => 'door_front_left',  'area_key' => 'door', 'aliases' => ['left front door', 'باب امامي يسار'], 'sort_order' => 200],
        ['slug' => 'door_front_right', 'name' => 'Front-Right Door', 'name_ar' => 'الباب الأمامي الأيمن', 'group_key' => 'exterior', 'precision' => 'panel', 'inspection_zone' => 'door_front_right', 'area_key' => 'door', 'aliases' => ['right front door', 'باب امامي يمين'], 'sort_order' => 210],
        ['slug' => 'door_rear_left',   'name' => 'Rear-Left Door',   'name_ar' => 'الباب الخلفي الأيسر',  'group_key' => 'exterior', 'precision' => 'panel', 'inspection_zone' => 'door_rear_left',   'area_key' => 'door', 'aliases' => ['left rear door', 'باب خلفي يسار'], 'sort_order' => 220],
        ['slug' => 'door_rear_right',  'name' => 'Rear-Right Door',  'name_ar' => 'الباب الخلفي الأيمن',  'group_key' => 'exterior', 'precision' => 'panel', 'inspection_zone' => 'door_rear_right',  'area_key' => 'door', 'aliases' => ['right rear door', 'باب خلفي يمين'], 'sort_order' => 230],

        ['slug' => 'fender_front_left',  'name' => 'Front-Left Fender',  'name_ar' => 'الرفرف الأمامي الأيسر', 'group_key' => 'exterior', 'precision' => 'panel', 'inspection_zone' => null, 'area_key' => 'fender', 'aliases' => ['wing', 'رفرف'], 'sort_order' => 240],
        ['slug' => 'fender_front_right', 'name' => 'Front-Right Fender', 'name_ar' => 'الرفرف الأمامي الأيمن', 'group_key' => 'exterior', 'precision' => 'panel', 'inspection_zone' => null, 'area_key' => 'fender', 'aliases' => ['wing', 'رفرف'], 'sort_order' => 250],
        ['slug' => 'quarter_rear_left',  'name' => 'Rear-Left Quarter',  'name_ar' => 'الجناح الخلفي الأيسر',  'group_key' => 'exterior', 'precision' => 'panel', 'inspection_zone' => null, 'area_key' => 'fender', 'aliases' => ['quarter panel'], 'sort_order' => 260],
        ['slug' => 'quarter_rear_right', 'name' => 'Rear-Right Quarter', 'name_ar' => 'الجناح الخلفي الأيمن',  'group_key' => 'exterior', 'precision' => 'panel', 'inspection_zone' => null, 'area_key' => 'fender', 'aliases' => ['quarter panel'], 'sort_order' => 270],

        ['slug' => 'grille',     'name' => 'Grille',        'name_ar' => 'الشبك',      'group_key' => 'exterior', 'precision' => 'panel', 'inspection_zone' => null, 'area_key' => null, 'aliases' => ['شبك'], 'sort_order' => 280],
        ['slug' => 'side_skirt', 'name' => 'Side Skirt',    'name_ar' => 'العتبة الجانبية', 'group_key' => 'exterior', 'precision' => 'panel', 'inspection_zone' => null, 'area_key' => null, 'aliases' => ['sill', 'rocker'], 'sort_order' => 290],

        // The honest "somewhere on the paint" answer. See the granularity note in the header.
        ['slug' => 'body', 'name' => 'Body (general)', 'name_ar' => 'الهيكل (بشكل عام)', 'group_key' => 'exterior', 'precision' => 'whole', 'inspection_zone' => null, 'area_key' => null, 'aliases' => ['bodywork', 'paint', 'هيكل', 'بودي'], 'sort_order' => 390],

        // ── Wheels & tyres ────────────────────────────────────────────────────────────────────────
        // The four corner keys are `component_catalog`'s axle_corner scheme, unchanged, so a tyre
        // fault and a fitted tyre component describe the same corner with the same word.
        ['slug' => 'wheel_front_left',  'name' => 'Front-Left Wheel',  'name_ar' => 'العجلة الأمامية اليسرى', 'group_key' => 'wheels', 'precision' => 'corner', 'inspection_zone' => null, 'area_key' => 'wheel', 'aliases' => ['front left tyre', 'front left tire', 'front_left', 'كفر امامي يسار'], 'sort_order' => 400],
        ['slug' => 'wheel_front_right', 'name' => 'Front-Right Wheel', 'name_ar' => 'العجلة الأمامية اليمنى', 'group_key' => 'wheels', 'precision' => 'corner', 'inspection_zone' => null, 'area_key' => 'wheel', 'aliases' => ['front right tyre', 'front right tire', 'front_right', 'كفر امامي يمين'], 'sort_order' => 410],
        ['slug' => 'wheel_rear_left',   'name' => 'Rear-Left Wheel',   'name_ar' => 'العجلة الخلفية اليسرى',  'group_key' => 'wheels', 'precision' => 'corner', 'inspection_zone' => null, 'area_key' => 'wheel', 'aliases' => ['rear left tyre', 'rear left tire', 'rear_left', 'كفر خلفي يسار'], 'sort_order' => 420],
        ['slug' => 'wheel_rear_right',  'name' => 'Rear-Right Wheel',  'name_ar' => 'العجلة الخلفية اليمنى',  'group_key' => 'wheels', 'precision' => 'corner', 'inspection_zone' => null, 'area_key' => 'wheel', 'aliases' => ['rear right tyre', 'rear right tire', 'rear_right', 'كفر خلفي يمين'], 'sort_order' => 430],
        ['slug' => 'spare_wheel',       'name' => 'Spare Wheel',       'name_ar' => 'الإطار الاحتياطي',       'group_key' => 'wheels', 'precision' => 'component', 'inspection_zone' => null, 'area_key' => 'wheel', 'aliases' => ['استبن', 'spare tyre'], 'sort_order' => 440],
        // "Rims" as a set — the wording inspectors actually use when they have not gone corner by corner.
        ['slug' => 'rims',              'name' => 'Rims',              'name_ar' => 'الجنوط',                 'group_key' => 'wheels', 'precision' => 'whole', 'inspection_zone' => null, 'area_key' => 'wheel', 'aliases' => ['rim', 'alloy', 'جنط', 'جنوط'], 'sort_order' => 450],

        // ── Glass & mirrors ───────────────────────────────────────────────────────────────────────
        ['slug' => 'windshield_front', 'name' => 'Front Windshield', 'name_ar' => 'الزجاج الأمامي', 'group_key' => 'glass', 'precision' => 'panel', 'inspection_zone' => 'windshield_front', 'area_key' => 'windscreen', 'aliases' => ['windscreen', 'زجاج امامي'], 'sort_order' => 500],
        ['slug' => 'windshield_rear',  'name' => 'Rear Windshield',  'name_ar' => 'الزجاج الخلفي',  'group_key' => 'glass', 'precision' => 'panel', 'inspection_zone' => 'windshield_rear',  'area_key' => 'glass',      'aliases' => ['rear screen', 'زجاج خلفي'], 'sort_order' => 510],
        ['slug' => 'window_front_left',  'name' => 'Front-Left Window',  'name_ar' => 'النافذة الأمامية اليسرى', 'group_key' => 'glass', 'precision' => 'panel', 'inspection_zone' => null, 'area_key' => 'glass', 'aliases' => ['side window'], 'sort_order' => 520],
        ['slug' => 'window_front_right', 'name' => 'Front-Right Window', 'name_ar' => 'النافذة الأمامية اليمنى', 'group_key' => 'glass', 'precision' => 'panel', 'inspection_zone' => null, 'area_key' => 'glass', 'aliases' => ['side window'], 'sort_order' => 530],
        ['slug' => 'window_rear_left',   'name' => 'Rear-Left Window',   'name_ar' => 'النافذة الخلفية اليسرى',  'group_key' => 'glass', 'precision' => 'panel', 'inspection_zone' => null, 'area_key' => 'glass', 'aliases' => ['side window'], 'sort_order' => 540],
        ['slug' => 'window_rear_right',  'name' => 'Rear-Right Window',  'name_ar' => 'النافذة الخلفية اليمنى',  'group_key' => 'glass', 'precision' => 'panel', 'inspection_zone' => null, 'area_key' => 'glass', 'aliases' => ['side window'], 'sort_order' => 550],
        ['slug' => 'mirror_left',  'name' => 'Left Mirror',  'name_ar' => 'المرآة اليسرى', 'group_key' => 'glass', 'precision' => 'component', 'inspection_zone' => null, 'area_key' => 'mirror', 'aliases' => ['wing mirror', 'مراية'], 'sort_order' => 560],
        ['slug' => 'mirror_right', 'name' => 'Right Mirror', 'name_ar' => 'المرآة اليمنى', 'group_key' => 'glass', 'precision' => 'component', 'inspection_zone' => null, 'area_key' => 'mirror', 'aliases' => ['wing mirror', 'مراية'], 'sort_order' => 570],
        // Wipers ARE a place a fault can sit — but see `policy.by_catalog_slug` below: a wiper FAULT
        // does not ask for one, because "the wipers don't work" is already the whole answer.
        ['slug' => 'wipers', 'name' => 'Wipers', 'name_ar' => 'المساحات', 'group_key' => 'glass', 'precision' => 'component', 'inspection_zone' => null, 'area_key' => null, 'aliases' => ['wiper', 'washer', 'مساحات'], 'sort_order' => 580],

        // ── Lights ────────────────────────────────────────────────────────────────────────────────
        ['slug' => 'headlight_left',  'name' => 'Left Headlight',  'name_ar' => 'الكشاف الأيسر', 'group_key' => 'lights', 'precision' => 'component', 'inspection_zone' => null, 'area_key' => 'light', 'aliases' => ['كشاف'], 'sort_order' => 600],
        ['slug' => 'headlight_right', 'name' => 'Right Headlight', 'name_ar' => 'الكشاف الأيمن', 'group_key' => 'lights', 'precision' => 'component', 'inspection_zone' => null, 'area_key' => 'light', 'aliases' => ['كشاف'], 'sort_order' => 610],
        ['slug' => 'taillight_left',  'name' => 'Left Tail Light',  'name_ar' => 'الستوب الأيسر', 'group_key' => 'lights', 'precision' => 'component', 'inspection_zone' => null, 'area_key' => 'light', 'aliases' => ['rear light', 'ستوب'], 'sort_order' => 620],
        ['slug' => 'taillight_right', 'name' => 'Right Tail Light', 'name_ar' => 'الستوب الأيمن', 'group_key' => 'lights', 'precision' => 'component', 'inspection_zone' => null, 'area_key' => 'light', 'aliases' => ['rear light', 'ستوب'], 'sort_order' => 630],
        ['slug' => 'fog_lights',      'name' => 'Fog Lights',       'name_ar' => 'أضواء الضباب', 'group_key' => 'lights', 'precision' => 'component', 'inspection_zone' => null, 'area_key' => 'light', 'aliases' => [], 'sort_order' => 640],
        ['slug' => 'indicators',      'name' => 'Indicators',       'name_ar' => 'الغمازات',     'group_key' => 'lights', 'precision' => 'component', 'inspection_zone' => null, 'area_key' => 'light', 'aliases' => ['turn signal', 'غماز'], 'sort_order' => 650],

        // ── Interior ──────────────────────────────────────────────────────────────────────────────
        ['slug' => 'dashboard',    'name' => 'Dashboard',      'name_ar' => 'الطبلون',        'group_key' => 'interior', 'precision' => 'zone',      'inspection_zone' => null, 'area_key' => 'interior', 'aliases' => ['dash', 'طبلون'], 'sort_order' => 700],
        ['slug' => 'seat_front_left',  'name' => 'Front-Left Seat',  'name_ar' => 'المقعد الأمامي الأيسر', 'group_key' => 'interior', 'precision' => 'component', 'inspection_zone' => null, 'area_key' => 'interior', 'aliases' => ['driver seat'], 'sort_order' => 710],
        ['slug' => 'seat_front_right', 'name' => 'Front-Right Seat', 'name_ar' => 'المقعد الأمامي الأيمن', 'group_key' => 'interior', 'precision' => 'component', 'inspection_zone' => null, 'area_key' => 'interior', 'aliases' => ['passenger seat'], 'sort_order' => 720],
        ['slug' => 'seats_rear',       'name' => 'Rear Seats',       'name_ar' => 'المقاعد الخلفية',       'group_key' => 'interior', 'precision' => 'component', 'inspection_zone' => null, 'area_key' => 'interior', 'aliases' => [], 'sort_order' => 730],
        ['slug' => 'steering_wheel',   'name' => 'Steering Wheel',   'name_ar' => 'المقود',                'group_key' => 'interior', 'precision' => 'component', 'inspection_zone' => null, 'area_key' => 'interior', 'aliases' => ['دركسون'], 'sort_order' => 740],
        ['slug' => 'interior_trim',    'name' => 'Interior Trim',    'name_ar' => 'التجليد الداخلي',       'group_key' => 'interior', 'precision' => 'zone',      'inspection_zone' => null, 'area_key' => 'interior', 'aliases' => ['upholstery', 'تجليد'], 'sort_order' => 750],
        ['slug' => 'cabin',            'name' => 'Cabin (general)',  'name_ar' => 'المقصورة (بشكل عام)',   'group_key' => 'interior', 'precision' => 'whole',     'inspection_zone' => null, 'area_key' => 'interior', 'aliases' => ['interior'], 'sort_order' => 790],

        // ── Mechanical / under the car ────────────────────────────────────────────────────────────
        // These are places a leak, a noise or a broken part is FOUND. They deliberately overlap with
        // component_catalog names — a location says where you looked, a component says what is fitted.
        ['slug' => 'engine_bay',   'name' => 'Engine Bay',      'name_ar' => 'حجرة المحرك',   'group_key' => 'mechanical', 'precision' => 'zone', 'inspection_zone' => null, 'area_key' => null, 'aliases' => ['engine', 'مكينة'], 'sort_order' => 800],
        ['slug' => 'underbody',    'name' => 'Underbody',       'name_ar' => 'أسفل السيارة',  'group_key' => 'mechanical', 'precision' => 'zone', 'inspection_zone' => null, 'area_key' => null, 'aliases' => ['chassis', 'under'], 'sort_order' => 810],
        ['slug' => 'front_axle',   'name' => 'Front Axle',      'name_ar' => 'المحور الأمامي', 'group_key' => 'mechanical', 'precision' => 'zone', 'inspection_zone' => null, 'area_key' => null, 'aliases' => ['front'], 'sort_order' => 820],
        ['slug' => 'rear_axle',    'name' => 'Rear Axle',       'name_ar' => 'المحور الخلفي',  'group_key' => 'mechanical', 'precision' => 'zone', 'inspection_zone' => null, 'area_key' => null, 'aliases' => ['rear'], 'sort_order' => 830],
        ['slug' => 'exhaust',      'name' => 'Exhaust',         'name_ar' => 'العادم',        'group_key' => 'mechanical', 'precision' => 'component', 'inspection_zone' => null, 'area_key' => null, 'aliases' => ['شكمان'], 'sort_order' => 840],
        ['slug' => 'fuel_tank',    'name' => 'Fuel Tank',       'name_ar' => 'خزان الوقود',   'group_key' => 'mechanical', 'precision' => 'component', 'inspection_zone' => null, 'area_key' => null, 'aliases' => ['tank', 'تانك'], 'sort_order' => 850],
    ],

    /**
     * ── DOES THIS FAULT TYPE EVEN HAVE A "WHERE"? ────────────────────────────────────────────────
     *
     * Not every fault does, and pretending otherwise is how a good picker becomes a nuisance that
     * people click through without reading. "Overheating" has no panel. "The wipers don't work" is
     * already the whole answer — asking WHERE the wiper fault is adds nothing.
     *
     * Three modes, resolved by {@see App\Services\FaultLocationService::policyFor()}:
     *   required — the report is refused without at least one location (a scratch nobody located is
     *              a scratch nobody can find again)
     *   optional — the picker is offered and may be left empty
     *   none     — no picker at all; a location sent anyway is ignored, not an error
     *
     * Resolution order, most specific first: by_catalog_slug → by_category → default. A fault type
     * added to fault_catalog tomorrow inherits its category's answer with no code change, which is
     * the whole point — see [[evidence-layer-governance]].
     *
     * The DB mirrors this: `fault_catalog.location_mode` / `damage_catalog.location_mode` are
     * seeded FROM this table, so a curator can override one row in the app without editing config.
     */
    'policy' => [
        'default' => 'optional',

        'by_category' => [
            // Where the damage is IS the report. Refuse a body finding with no place.
            'bodywork'     => 'required',
            'tyres'        => 'required',
            'lights'       => 'required',
            'interior'     => 'optional',
            'fluids'       => 'optional',   // a leak has a place, but the inspector may not have found it
            'brakes'       => 'optional',   // per-corner when known
            'suspension'   => 'optional',   // per-corner when known
            'electrical'   => 'optional',
            'engine'       => 'none',       // "misfire", "overheating" — no place to point at
            'transmission' => 'none',
            'ac'           => 'none',
            'routine'      => 'none',       // planned service; the car is the location
        ],

        /**
         * Per-row overrides. `light_wiper_washer` is the case that named this section: wipers sit in
         * the Lights & Visibility category (which is `required`), but "wiper / washer fault" needs no
         * location — the part IS the location. One line, no special-casing anywhere in the code.
         */
        'by_catalog_slug' => [
            'light_wiper_washer' => 'none',
            'elec_dash_warning'  => 'none',
            'int_bad_odour'      => 'none',
            'fluid_low_level'    => 'none',
        ],
    ],

    /**
     * Upper bound on `maintenance_tasks.quantity`. A sanity rail, not a business rule: 40 scratches
     * on one car is a total loss, not a data-entry event, and a typed "200" is a slipped keypress.
     */
    'max_quantity' => 40,
];
