<?php

/**
 * THE SPEC DICTIONARY — what a part IS, as opposed to which part it is.
 *
 * The catalog answers "what kind of thing is this?" (a battery). The component answers "which one?"
 * (serial 4471, fitted 12 March). Neither has ever been able to answer the question a technician
 * actually asks at the counter: WHICH battery — 12V 60Ah or 12V 70Ah? Which oil — 5W-30 or 10W-40,
 * and how many litres does this car take? That third question is what this file exists for.
 *
 * ── The shape ────────────────────────────────────────────────────────────────────────────────────
 *
 * This file defines FIELDS, not parts. A field ('voltage', 'viscosity', 'capacity_l') is defined
 * exactly once here, and part types opt into the fields that apply to them via `spec_fields` in
 * config/component_catalog.php. So "capacity_l" means the same thing, renders with the same unit and
 * validates the same way whether it appears on engine oil, coolant or brake fluid.
 *
 * That indirection is the whole point. The alternative — letting each part type declare its own
 * fields inline — produces 'litres' on one row and 'capacity_liters' on the next, and then the oil
 * change screen and the timeline disagree about what to show, which is the state we are leaving.
 *
 * ── Why values are stored as codes ───────────────────────────────────────────────────────────────
 *
 * Every `enum` field stores the OPTION KEY, never the label. '5w-30' is stored; "5W-30" and
 * "٥W-٣٠" are rendered. This is the same discipline the reason codes follow, and for the same
 * reason: the moment English display text is stored, the Arabic surface can only guess, and any
 * grouping ("how many cars take 5W-30?") starts depending on how someone typed it.
 *
 * ── FIELD KEYS ───────────────────────────────────────────────────────────────────────────────────
 *
 *   label / label_ar   How the field is named to a human. The Arabic is the workshop word, not a
 *                      translation — same rule as the component catalog.
 *
 *   kind               enum   — one of a fixed list; stores the option key.
 *                      number — a numeric measurement; stores a float, renders with `unit`.
 *                      text   — free text, for facts nobody can enumerate (a manufacturer's own
 *                               spec string). Used sparingly: free text cannot be grouped.
 *
 *   unit / unit_ar     Rendered after a number, never stored with it. '60' + 'Ah' → "60 Ah".
 *
 *   options            enum only. Ordered — the picker shows them in this order, which is the order
 *                      a person expects (thinnest oil first, smallest battery first).
 *
 *   summary            Whether this field belongs in the ONE-LINE summary that appears next to the
 *                      part everywhere (timeline rows, invoice lines, the components panel).
 *                      Keep this list SHORT. A summary that carries eight facts is read as none.
 *                      Fields not in the summary are still stored, still shown on the detail panel.
 *
 *   summary_order      Position within that one line. Lower first. The order is chosen so the line
 *                      reads the way the part is spoken about: "12V 60Ah", not "60Ah 12V".
 *
 *   critical           Getting this wrong damages the car (oil viscosity, refrigerant type,
 *                      coolant chemistry) as opposed to merely costing money (a battery one size
 *                      small). Critical fields are the ones a mismatch warning is raised about.
 *
 * ── WHAT DOES NOT BELONG HERE ────────────────────────────────────────────────────────────────────
 *
 * Not the part number, brand or serial — those identify the individual part and already have
 * columns on vehicle_components. Not price, warranty or expected life — those are commercial terms,
 * not physical properties, and they live on the catalog entry. Not condition or tread depth: a
 * measurement that CHANGES while the part is fitted is an observation, and observations belong to
 * the inspection layer, which has a date on every reading. A spec is what the part is when it is
 * new, and it never changes for as long as that part exists.
 *
 * @see \App\Support\PartSpecs           the single reader — validation, normalisation, rendering
 * @see config/component_catalog.php     where part types opt into these fields
 */

return [

    // ══ ELECTRICAL ══════════════════════════════════════════════════════════════════════════════

    'voltage' => [
        'label' => 'Voltage', 'label_ar' => 'الفولت',
        'kind' => 'enum', 'summary' => true, 'summary_order' => 10, 'critical' => true,
        'options' => [
            ['key' => '12v', 'label' => '12V', 'label_ar' => '١٢ فولت'],
            ['key' => '24v', 'label' => '24V', 'label_ar' => '٢٤ فولت'],
            ['key' => '48v', 'label' => '48V', 'label_ar' => '٤٨ فولت'],
        ],
    ],

    // Ampere-hours: how much charge it holds. The number people mean when they say "a 60 battery" —
    // which is exactly why it is offered as a list and not as a free number. Everyone in the trade
    // buys one of these eight sizes, and a free field would fill up with 60, 60.0, "60A" and "sitteen".
    'capacity_ah' => [
        'label' => 'Capacity', 'label_ar' => 'السعة',
        'kind' => 'enum', 'unit' => 'Ah', 'unit_ar' => 'أمبير/ساعة',
        'summary' => true, 'summary_order' => 20, 'critical' => false,
        'options' => [
            ['key' => '35', 'label' => '35 Ah'], ['key' => '40', 'label' => '40 Ah'],
            ['key' => '45', 'label' => '45 Ah'], ['key' => '50', 'label' => '50 Ah'],
            ['key' => '55', 'label' => '55 Ah'], ['key' => '60', 'label' => '60 Ah'],
            ['key' => '65', 'label' => '65 Ah'], ['key' => '70', 'label' => '70 Ah'],
            ['key' => '74', 'label' => '74 Ah'], ['key' => '80', 'label' => '80 Ah'],
            ['key' => '90', 'label' => '90 Ah'], ['key' => '100', 'label' => '100 Ah'],
            ['key' => '120', 'label' => '120 Ah'], ['key' => '150', 'label' => '150 Ah'],
            ['key' => '200', 'label' => '200 Ah'],
        ],
    ],

    // Cold-cranking amps. Free number on purpose: unlike Ah there is no settled ladder of sizes,
    // every manufacturer prints its own figure, and nobody groups cars by it.
    'cca' => [
        'label' => 'Cold cranking amps', 'label_ar' => 'أمبير التشغيل البارد',
        'kind' => 'number', 'unit' => 'CCA', 'unit_ar' => 'أمبير',
        'summary' => false, 'critical' => false,
        'min' => 100, 'max' => 2000,
    ],

    'battery_chemistry' => [
        'label' => 'Battery type', 'label_ar' => 'نوع البطارية',
        'kind' => 'enum', 'summary' => false, 'critical' => true,
        'options' => [
            ['key' => 'lead_acid', 'label' => 'Lead acid (standard)', 'label_ar' => 'حمض الرصاص (عادية)'],
            ['key' => 'efb', 'label' => 'EFB (start-stop)', 'label_ar' => 'EFB (تشغيل/إيقاف)'],
            ['key' => 'agm', 'label' => 'AGM (start-stop)', 'label_ar' => 'AGM (تشغيل/إيقاف)'],
            ['key' => 'gel', 'label' => 'Gel', 'label_ar' => 'جل'],
            ['key' => 'lithium', 'label' => 'Lithium', 'label_ar' => 'ليثيوم'],
        ],
    ],

    // Which side the positive post is on, looking at the battery with the terminals nearest you.
    // A right-hand-positive battery in a left-hand-positive car does not reach the cable — the
    // single most common wrong-battery return, and invisible on any paperwork that omits it.
    'terminal_layout' => [
        'label' => 'Terminal side', 'label_ar' => 'اتجاه الأقطاب',
        'kind' => 'enum', 'summary' => false, 'critical' => true,
        'options' => [
            ['key' => 'right_positive', 'label' => 'Positive on the right', 'label_ar' => 'الموجب على اليمين'],
            ['key' => 'left_positive', 'label' => 'Positive on the left', 'label_ar' => 'الموجب على اليسار'],
        ],
    ],

    'bulb_fitting' => [
        'label' => 'Bulb fitting', 'label_ar' => 'نوع اللمبة',
        'kind' => 'enum', 'summary' => true, 'summary_order' => 10, 'critical' => true,
        'options' => [
            ['key' => 'h1', 'label' => 'H1'], ['key' => 'h3', 'label' => 'H3'],
            ['key' => 'h4', 'label' => 'H4'], ['key' => 'h7', 'label' => 'H7'],
            ['key' => 'h11', 'label' => 'H11'], ['key' => 'h15', 'label' => 'H15'],
            ['key' => 'hb3', 'label' => 'HB3 / 9005'], ['key' => 'hb4', 'label' => 'HB4 / 9006'],
            ['key' => 'd1s', 'label' => 'D1S (xenon)', 'label_ar' => 'D1S (زينون)'],
            ['key' => 'd2s', 'label' => 'D2S (xenon)', 'label_ar' => 'D2S (زينون)'],
            ['key' => 'd3s', 'label' => 'D3S (xenon)', 'label_ar' => 'D3S (زينون)'],
            ['key' => 'd4s', 'label' => 'D4S (xenon)', 'label_ar' => 'D4S (زينون)'],
            ['key' => 'w5w', 'label' => 'W5W'], ['key' => 'p21w', 'label' => 'P21W'],
            ['key' => 'led_module', 'label' => 'LED module (not replaceable)', 'label_ar' => 'وحدة LED (غير قابلة للاستبدال)'],
        ],
    ],

    'bulb_technology' => [
        'label' => 'Bulb technology', 'label_ar' => 'تقنية الإضاءة',
        'kind' => 'enum', 'summary' => false, 'critical' => false,
        'options' => [
            ['key' => 'halogen', 'label' => 'Halogen', 'label_ar' => 'هالوجين'],
            ['key' => 'xenon', 'label' => 'Xenon / HID', 'label_ar' => 'زينون'],
            ['key' => 'led', 'label' => 'LED', 'label_ar' => 'ليد'],
        ],
    ],

    // ══ FLUIDS ══════════════════════════════════════════════════════════════════════════════════

    // The number on the bottle, and the one fact about an oil change that is worth recording after
    // the odometer. Stored as a key ('5w-30') so that "how many of our cars run 5W-30" is a GROUP BY
    // and not a text-matching exercise across '5W30', '5w-30' and '5/30'.
    'viscosity' => [
        'label' => 'Viscosity', 'label_ar' => 'اللزوجة',
        'kind' => 'enum', 'summary' => true, 'summary_order' => 10, 'critical' => true,
        'options' => [
            ['key' => '0w-16', 'label' => '0W-16'], ['key' => '0w-20', 'label' => '0W-20'],
            ['key' => '0w-30', 'label' => '0W-30'], ['key' => '0w-40', 'label' => '0W-40'],
            ['key' => '5w-20', 'label' => '5W-20'], ['key' => '5w-30', 'label' => '5W-30'],
            ['key' => '5w-40', 'label' => '5W-40'], ['key' => '5w-50', 'label' => '5W-50'],
            ['key' => '10w-30', 'label' => '10W-30'], ['key' => '10w-40', 'label' => '10W-40'],
            ['key' => '10w-60', 'label' => '10W-60'], ['key' => '15w-40', 'label' => '15W-40'],
            ['key' => '20w-50', 'label' => '20W-50'],
        ],
    ],

    'oil_base' => [
        'label' => 'Oil base', 'label_ar' => 'نوع الزيت',
        'kind' => 'enum', 'summary' => true, 'summary_order' => 20, 'critical' => false,
        'options' => [
            ['key' => 'mineral', 'label' => 'Mineral', 'label_ar' => 'معدني'],
            ['key' => 'semi_synthetic', 'label' => 'Semi-synthetic', 'label_ar' => 'نصف تخليقي'],
            ['key' => 'full_synthetic', 'label' => 'Full synthetic', 'label_ar' => 'تخليقي بالكامل'],
        ],
    ],

    // How much went in. A number, not a list: a fill is 4.2 litres as readily as 4.5, and the figure
    // is a measurement rather than a choice. This is what turns an oil change from "it was done" into
    // "it took 4.5 L", which is the number a top-up-versus-change question is settled with.
    'capacity_l' => [
        'label' => 'Quantity', 'label_ar' => 'الكمية',
        'kind' => 'number', 'unit' => 'L', 'unit_ar' => 'لتر',
        'summary' => true, 'summary_order' => 30, 'critical' => false,
        'min' => 0.1, 'max' => 60, 'step' => 0.1,
    ],

    // The manufacturer approval printed on the bottle. Free text because the list is endless and
    // vendor-specific (MB 229.5, VW 504 00, Dexos2, API SP) — enumerating it would go stale and
    // then people would stop filling it in.
    'oil_standard' => [
        'label' => 'Manufacturer spec', 'label_ar' => 'مواصفة الشركة',
        'kind' => 'text', 'summary' => false, 'critical' => false,
        'placeholder' => 'API SP · ACEA C3 · MB 229.51',
    ],

    'atf_spec' => [
        'label' => 'Gear oil spec', 'label_ar' => 'مواصفة زيت الجير',
        'kind' => 'enum', 'summary' => true, 'summary_order' => 10, 'critical' => true,
        'options' => [
            ['key' => 'atf_dexron_iii', 'label' => 'ATF Dexron III'],
            ['key' => 'atf_dexron_vi', 'label' => 'ATF Dexron VI'],
            ['key' => 'atf_ws', 'label' => 'Toyota ATF WS'],
            ['key' => 'atf_sp_iv', 'label' => 'Hyundai/Kia SP-IV'],
            ['key' => 'atf_mercon_lv', 'label' => 'Ford Mercon LV'],
            ['key' => 'cvt_fluid', 'label' => 'CVT fluid', 'label_ar' => 'زيت CVT'],
            ['key' => 'dct_fluid', 'label' => 'DCT / DSG fluid', 'label_ar' => 'زيت DCT'],
            ['key' => 'manual_75w90', 'label' => 'Manual gear oil 75W-90', 'label_ar' => 'زيت جير عادي 75W-90'],
            ['key' => 'manual_80w90', 'label' => 'Manual gear oil 80W-90', 'label_ar' => 'زيت جير عادي 80W-90'],
        ],
    ],

    'coolant_type' => [
        'label' => 'Coolant type', 'label_ar' => 'نوع سائل التبريد',
        'kind' => 'enum', 'summary' => true, 'summary_order' => 10, 'critical' => true,
        'options' => [
            ['key' => 'iat_green', 'label' => 'IAT — green', 'label_ar' => 'IAT — أخضر'],
            ['key' => 'oat_red', 'label' => 'OAT — red / pink', 'label_ar' => 'OAT — أحمر / وردي'],
            ['key' => 'hoat_blue', 'label' => 'HOAT — blue', 'label_ar' => 'HOAT — أزرق'],
            ['key' => 'hoat_yellow', 'label' => 'HOAT — yellow', 'label_ar' => 'HOAT — أصفر'],
            ['key' => 'premix', 'label' => 'Ready-mixed (any colour)', 'label_ar' => 'جاهز الخلط'],
        ],
    ],

    'brake_fluid_grade' => [
        'label' => 'Brake fluid grade', 'label_ar' => 'درجة زيت الفرامل',
        'kind' => 'enum', 'summary' => true, 'summary_order' => 10, 'critical' => true,
        'options' => [
            ['key' => 'dot3', 'label' => 'DOT 3'], ['key' => 'dot4', 'label' => 'DOT 4'],
            ['key' => 'dot4_plus', 'label' => 'DOT 4+ / LV'], ['key' => 'dot5_1', 'label' => 'DOT 5.1'],
        ],
    ],

    'refrigerant_type' => [
        'label' => 'Refrigerant', 'label_ar' => 'غاز المكيف',
        'kind' => 'enum', 'summary' => true, 'summary_order' => 10, 'critical' => true,
        'options' => [
            ['key' => 'r134a', 'label' => 'R134a'],
            ['key' => 'r1234yf', 'label' => 'R1234yf'],
            ['key' => 'r12', 'label' => 'R12 (legacy)', 'label_ar' => 'R12 (قديم)'],
        ],
    ],

    'charge_g' => [
        'label' => 'Charge weight', 'label_ar' => 'وزن الشحنة',
        'kind' => 'number', 'unit' => 'g', 'unit_ar' => 'جرام',
        'summary' => true, 'summary_order' => 20, 'critical' => false,
        'min' => 50, 'max' => 3000, 'step' => 5,
    ],

    // ══ TYRES ═══════════════════════════════════════════════════════════════════════════════════

    // The whole sidewall size as one string — 225/65R17. Free text rather than three fields because
    // that is how it is written on the tyre, how it is asked for on the phone and how it is printed
    // on the invoice; splitting it would mean reassembling it on every surface that shows it.
    // PartSpecs normalises the punctuation so '225 65 R17' and '225/65R17' land on the same value.
    'tyre_size' => [
        'label' => 'Tyre size', 'label_ar' => 'مقاس الإطار',
        'kind' => 'text', 'summary' => true, 'summary_order' => 10, 'critical' => true,
        'placeholder' => '225/65R17',
        'pattern' => 'tyre_size',
    ],

    'load_speed_rating' => [
        'label' => 'Load & speed rating', 'label_ar' => 'مؤشر الحمل والسرعة',
        'kind' => 'text', 'summary' => false, 'critical' => false,
        'placeholder' => '102H',
    ],

    'tyre_construction' => [
        'label' => 'Tyre type', 'label_ar' => 'نوع الإطار',
        'kind' => 'enum', 'summary' => false, 'critical' => false,
        'options' => [
            ['key' => 'standard', 'label' => 'Standard', 'label_ar' => 'عادي'],
            ['key' => 'run_flat', 'label' => 'Run-flat', 'label_ar' => 'رن فلات'],
            ['key' => 'all_terrain', 'label' => 'All-terrain', 'label_ar' => 'أوف رود'],
            ['key' => 'commercial', 'label' => 'Commercial / LT', 'label_ar' => 'تجاري'],
        ],
    ],

    // ══ BRAKES & FILTERS ════════════════════════════════════════════════════════════════════════

    'pad_material' => [
        'label' => 'Pad material', 'label_ar' => 'خامة الفحمات',
        'kind' => 'enum', 'summary' => true, 'summary_order' => 10, 'critical' => false,
        'options' => [
            ['key' => 'ceramic', 'label' => 'Ceramic', 'label_ar' => 'سيراميك'],
            ['key' => 'semi_metallic', 'label' => 'Semi-metallic', 'label_ar' => 'نصف معدني'],
            ['key' => 'organic', 'label' => 'Organic', 'label_ar' => 'عضوي'],
            ['key' => 'low_metallic', 'label' => 'Low-metallic', 'label_ar' => 'قليل المعدن'],
        ],
    ],

    'disc_diameter_mm' => [
        'label' => 'Disc diameter', 'label_ar' => 'قطر الهوب',
        'kind' => 'number', 'unit' => 'mm', 'unit_ar' => 'مم',
        'summary' => true, 'summary_order' => 10, 'critical' => true,
        'min' => 150, 'max' => 450, 'step' => 1,
    ],

    'filter_media' => [
        'label' => 'Filter type', 'label_ar' => 'نوع الفلتر',
        'kind' => 'enum', 'summary' => true, 'summary_order' => 10, 'critical' => false,
        'options' => [
            ['key' => 'paper', 'label' => 'Paper', 'label_ar' => 'ورقي'],
            ['key' => 'carbon', 'label' => 'Activated carbon', 'label_ar' => 'كربون منشط'],
            ['key' => 'hepa', 'label' => 'HEPA', 'label_ar' => 'هيبا'],
        ],
    ],

    // ══ WIPERS ══════════════════════════════════════════════════════════════════════════════════

    'blade_length_in' => [
        'label' => 'Blade length', 'label_ar' => 'طول المساحة',
        'kind' => 'number', 'unit' => '"', 'unit_ar' => 'إنش',
        'summary' => true, 'summary_order' => 10, 'critical' => true,
        'min' => 10, 'max' => 32, 'step' => 1,
    ],

    // ══ BELTS ═══════════════════════════════════════════════════════════════════════════════════

    'belt_profile' => [
        'label' => 'Belt profile', 'label_ar' => 'مقاس السير',
        'kind' => 'text', 'summary' => true, 'summary_order' => 10, 'critical' => true,
        'placeholder' => '6PK1750',
    ],
];
