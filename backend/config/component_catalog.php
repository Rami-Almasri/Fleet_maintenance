<?php

/**
 * The STARTING SEED for component_catalog — the fleet's parts vocabulary.
 *
 * READ THIS FIRST: this file is no longer the source of truth. The DATABASE is. These entries only
 * populate a fresh install and add part types that ship with a release; the moment someone edits a
 * row in the Parts Catalog page, `edited_in_app` is stamped and ComponentCatalogSeeder stops
 * touching that row for good. Correcting a name or an Arabic term is done in the app, by the people
 * who know the right word — not here. (See the make_component_catalog_editable migration.)
 *
 * Seeding stays idempotent and additive-only: upsert by `slug`, never delete, never rename a slug.
 * Retire an entry with is_active=false in the app; removing it from this file does nothing.
 *
 * FIELDS
 *
 *   name / name_ar   The part as it is written on a request. Arabic is the workshop word actually
 *                    used in the Gulf (دينمو, سلف, فحمات) rather than a literal translation of the
 *                    English, because the point is that a technician recognises it instantly.
 *
 *   identity_aliases OTHER NAMES FOR THE SAME PART — 'dynamo' for the alternator, 'self' for the
 *                    starter, 'فحمات' for brake pads. Every entry here answers "what else is this
 *                    exact part called?", and nothing else. These carry WEIGHT: two records whose
 *                    wording lands in the same row's identity set are treated as the SAME PART, so
 *                    buying "dynamo" for a car that already got an "Alternator" raises the repeat
 *                    warning. See {@see \App\Services\PartIdentityService}.
 *
 *                    WHAT MUST NEVER GO HERE, and why:
 *                      · a symptom  — 'brake noise' is as true of the discs and the caliper as of
 *                                     the pads, so it identifies nothing.
 *                      · an ambiguous name — 'fan motor' is the radiator fan AND the A/C blower;
 *                                     'bumper' is the front one AND the rear one; 'الكاتينة' is the
 *                                     timing belt AND the timing chain. A name that fits two rows
 *                                     cannot prove which one you meant.
 *                    Both kinds still belong in `aliases` below, where a human resolves them.
 *                    (PartIdentityService also refuses, at runtime, any identity surface that turns
 *                    out to appear under two rows — so a mistake here degrades to "no match" rather
 *                    than to a wrong match. Belt and braces, on purpose.)
 *
 *   aliases          SEARCH-ONLY wording: how people ASK for the part when they don't name it.
 *                    Symptom phrases ('ac not cooling', 'ما يبرد') and the ambiguous trade names
 *                    ruled out above. The picker searches these TOGETHER with identity_aliases, so
 *                    someone describing the problem still lands on the right row — and then a human
 *                    picks it. Generous where a person decides, strict where nobody does.
 *                    AN ALIAS IS NOT A FAULT. The findings catalog (config/maintenance_findings.php)
 *                    owns what a fault is, what it's called and what it counts as. Nothing here is
 *                    ever counted, grouped or reported as evidence of anything.
 *
 *   category_key     Same vocabulary as maintenance_findings.php, so a fault's category joins
 *                    straight to the component types that could be responsible. Valid keys:
 *                    routine, engine, brakes, tyres, suspension, transmission, electrical, ac,
 *                    bodywork, interior, fluids, lights, safety.
 *
 *   tracking_mode    serialized — individual identity (serial required), qty always 1.
 *                    batch      — quantity/position tracked, serial optional.
 *                    consumable — NEVER instantiates a vehicle_components row; the work is a
 *                                 service_records entry only. Standing rule.
 *
 *   position_scheme  'axle_corner' (FL/FR/RL/RR) | 'axle' (front/rear) | null (positionless).
 *                    Left null on body and lighting parts even though they have sides: the only
 *                    position vocabulary that exists is the AXLE one, and calling a headlight
 *                    'front_left' by borrowing a suspension term would let 'rear_left headlight'
 *                    validate. A wrong vocabulary is worse than none.
 *
 *   default_warranty_months / default_warranty_km
 *                    The cover a supplier TYPICALLY gives on this part, both legs, whichever comes
 *                    first. DEFAULTS ONLY — a real warranty copies them at install time and is then
 *                    independent. They are conventional trade figures, not measurements of our own
 *                    suppliers; edit them in the app as you learn what each supplier actually gives.
 *
 *   expected_life_km / expected_life_months
 *                    Service-life expectations feeding foresight. Same caveat: conventional
 *                    starting figures, tunable from fleet actuals later.
 */

return [

    // ══ SERIALIZED — individual identity, individual history ════════════════════════════════════

    ['slug' => 'engine', 'name' => 'Engine', 'name_ar' => 'محرك',
        'identity_aliases' => ['motor', 'ماكينة', 'مكينة'],
        'aliases' => ['engine knock', 'engine seized', 'خبطة مكينة'],
        'category_key' => 'engine', 'tracking_mode' => 'serialized', 'expected_life_km' => 300000, 'position_scheme' => null],

    ['slug' => 'gearbox', 'name' => 'Gearbox', 'name_ar' => 'ناقل الحركة',
        'identity_aliases' => ['transmission', 'جير', 'قير'],
        'aliases' => ['gear slipping', 'not shifting', 'الجير ما يسحب'],
        'category_key' => 'transmission', 'tracking_mode' => 'serialized', 'expected_life_km' => 250000, 'position_scheme' => null],

    ['slug' => 'battery-12v', 'name' => 'Battery 12V', 'name_ar' => 'بطارية',
        'spec_fields' => ['voltage', 'capacity_ah', 'battery_chemistry', 'terminal_layout', 'cca'],
        'identity_aliases' => ['battery', 'بطاريه'],
        'aliases' => ['car not starting', 'battery dead', 'البطارية فاضية', 'ما تشتغل'],
        'category_key' => 'electrical', 'action_target' => 'battery', 'tracking_mode' => 'serialized',
        'default_warranty_months' => 12, 'expected_life_months' => 30, 'position_scheme' => null],

    ['slug' => 'ac-compressor', 'name' => 'AC Compressor', 'name_ar' => 'كمبروسر مكيف',
        'identity_aliases' => ['compressor', 'كمبريسر'],
        'aliases' => ['ac not cooling', 'no cold air', 'المكيف ما يبرد', 'ac weak'],
        'category_key' => 'ac', 'action_target' => 'ac_compressor', 'tracking_mode' => 'serialized',
        'default_warranty_months' => 12, 'default_warranty_km' => 20000, 'position_scheme' => null],

    ['slug' => 'alternator', 'name' => 'Alternator', 'name_ar' => 'دينمو',
        'identity_aliases' => ['dynamo', 'generator', 'دينامو'],
        'aliases' => ['battery not charging', 'charging light', 'ما يشحن'],
        'category_key' => 'electrical', 'action_target' => 'alternator', 'tracking_mode' => 'serialized',
        'default_warranty_months' => 12, 'default_warranty_km' => 20000, 'position_scheme' => null],

    ['slug' => 'starter-motor', 'name' => 'Starter Motor', 'name_ar' => 'سلف',
        'identity_aliases' => ['starter', 'self', 'مارش', 'السلف'],
        'aliases' => ['car not cranking', 'ما تدور'],
        'category_key' => 'electrical', 'action_target' => 'starter_motor', 'tracking_mode' => 'serialized',
        'default_warranty_months' => 12, 'default_warranty_km' => 20000, 'position_scheme' => null],

    ['slug' => 'radiator', 'name' => 'Radiator', 'name_ar' => 'ردياتير',
        'identity_aliases' => ['رادييتر'],
        'aliases' => ['overheating', 'coolant leak', 'حرارة عالية', 'تسريب ماء'],
        'category_key' => 'engine', 'action_target' => 'radiator', 'tracking_mode' => 'serialized',
        'default_warranty_months' => 6, 'position_scheme' => null],

    ['slug' => 'ecu', 'name' => 'ECU / Control Unit', 'name_ar' => 'كمبيوتر السيارة',
        'identity_aliases' => ['ecm', 'computer', 'الكمبيوتر'],
        'aliases' => ['check engine light', 'لمبة الفحص'],
        'category_key' => 'electrical', 'tracking_mode' => 'serialized', 'position_scheme' => null],

    ['slug' => 'gps-tracker', 'name' => 'GPS Tracker', 'name_ar' => 'جهاز تتبع',
        'identity_aliases' => ['tracker', 'gps', 'تراكر', 'تتبع'],
        'aliases' => ['not reporting location'],
        'category_key' => 'electrical', 'tracking_mode' => 'serialized', 'position_scheme' => null],

    ['slug' => 'water-pump', 'name' => 'Water Pump', 'name_ar' => 'طرمبة ماء',
        'identity_aliases' => ['coolant pump', 'مضخة ماء'],
        'aliases' => ['overheating', 'حرارة', 'تسريب من الطرمبة'],
        'category_key' => 'engine', 'action_target' => 'water_pump', 'tracking_mode' => 'serialized',
        'default_warranty_months' => 12, 'default_warranty_km' => 20000, 'expected_life_km' => 120000, 'position_scheme' => null],

    ['slug' => 'fuel-pump', 'name' => 'Fuel Pump', 'name_ar' => 'طرمبة بنزين',
        'identity_aliases' => ['petrol pump', 'مضخة وقود'],
        'aliases' => ['car cuts off', 'no fuel pressure', 'تفصل وهي ماشية'],
        'category_key' => 'engine', 'tracking_mode' => 'serialized',
        'default_warranty_months' => 12, 'default_warranty_km' => 20000, 'expected_life_km' => 150000, 'position_scheme' => null],

    ['slug' => 'turbocharger', 'name' => 'Turbocharger', 'name_ar' => 'تربو',
        'identity_aliases' => ['turbo', 'التربو'],
        'aliases' => ['loss of power', 'white smoke', 'ضعف عزم'],
        'category_key' => 'engine', 'tracking_mode' => 'serialized',
        'default_warranty_months' => 12, 'default_warranty_km' => 20000, 'expected_life_km' => 200000, 'position_scheme' => null],

    ['slug' => 'ac-condenser', 'name' => 'AC Condenser', 'name_ar' => 'مكثف المكيف',
        'identity_aliases' => ['condenser', 'كندنسر'],
        'aliases' => ['ac not cooling', 'gas leak', 'المكيف ضعيف'],
        'category_key' => 'ac', 'action_target' => 'ac_condenser', 'tracking_mode' => 'serialized',
        'default_warranty_months' => 12, 'expected_life_km' => 150000, 'position_scheme' => null],

    // 'fan motor' is deliberately search-only on this row AND on ac-blower-motor: the trade calls
    // both of them that, so it cannot decide between them.
    ['slug' => 'radiator-fan', 'name' => 'Radiator Fan', 'name_ar' => 'مروحة الردياتير',
        'identity_aliases' => ['cooling fan', 'مروحة التبريد'],
        'aliases' => ['fan motor', 'overheating in traffic', 'المروحة ما تدور'],
        'category_key' => 'engine', 'action_target' => 'cooling_fan', 'tracking_mode' => 'serialized',
        'default_warranty_months' => 12, 'expected_life_km' => 150000, 'position_scheme' => null],

    ['slug' => 'steering-rack', 'name' => 'Steering Rack', 'name_ar' => 'علبة دركسون',
        'identity_aliases' => ['rack and pinion', 'علبة مقود'],
        'aliases' => ['steering heavy', 'الدركسون ثقيل', 'تسريب زيت باور'],
        'category_key' => 'suspension', 'tracking_mode' => 'serialized',
        'default_warranty_months' => 12, 'default_warranty_km' => 20000, 'expected_life_km' => 180000, 'position_scheme' => null],

    ['slug' => 'clutch-kit', 'name' => 'Clutch Kit', 'name_ar' => 'طقم كلتش',
        'identity_aliases' => ['clutch', 'دبرياج', 'الكلتش'],
        'aliases' => ['clutch slipping', 'الكلتش يزحلق'],
        'category_key' => 'transmission', 'action_target' => 'clutch', 'tracking_mode' => 'serialized',
        'default_warranty_months' => 12, 'expected_life_km' => 120000, 'position_scheme' => null],

    ['slug' => 'catalytic-converter', 'name' => 'Catalytic Converter', 'name_ar' => 'محول حفاز',
        'identity_aliases' => ['catalyst', 'كتاليك', 'كتلست'],
        'aliases' => ['check engine', 'rotten egg smell'],
        'category_key' => 'engine', 'tracking_mode' => 'serialized',
        'default_warranty_months' => 12, 'expected_life_km' => 200000, 'position_scheme' => null],

    ['slug' => 'abs-module', 'name' => 'ABS Module', 'name_ar' => 'وحدة ABS',
        'identity_aliases' => ['abs pump', 'abs unit', 'يونت ABS'],
        'aliases' => ['abs light on', 'لمبة ABS'],
        'category_key' => 'brakes', 'tracking_mode' => 'serialized',
        'default_warranty_months' => 12, 'position_scheme' => null],

    ['slug' => 'power-steering-pump', 'name' => 'Power Steering Pump', 'name_ar' => 'طرمبة باور',
        'identity_aliases' => ['steering pump', 'مضخة الباور'],
        'aliases' => ['steering heavy', 'noise when turning', 'صوت عند اللف'],
        'category_key' => 'suspension', 'tracking_mode' => 'serialized',
        'default_warranty_months' => 12, 'expected_life_km' => 150000, 'position_scheme' => null],

    ['slug' => 'differential', 'name' => 'Differential', 'name_ar' => 'دفرنس',
        'identity_aliases' => ['diff', 'الدفرنس'],
        'aliases' => ['whining noise', 'صوت من الخلف'],
        'category_key' => 'transmission', 'tracking_mode' => 'serialized', 'expected_life_km' => 250000, 'position_scheme' => null],

    ['slug' => 'infotainment-unit', 'name' => 'Radio / Infotainment Unit', 'name_ar' => 'مسجل / شاشة',
        'identity_aliases' => ['head unit', 'radio', 'screen', 'الشاشة', 'المسجل'],
        'aliases' => ['screen not working'],
        'category_key' => 'interior', 'tracking_mode' => 'serialized', 'position_scheme' => null],

    ['slug' => 'airbag', 'name' => 'Airbag', 'name_ar' => 'وسادة هوائية',
        'identity_aliases' => ['air bag', 'الإيرباق'],
        'aliases' => ['srs light', 'لمبة الإيرباق', 'airbag light on'],
        'category_key' => 'safety', 'tracking_mode' => 'serialized', 'position_scheme' => null,
        'notes' => 'Deployed airbags are a safety item — never re-used, never transferred between cars.'],

    ['slug' => 'reverse-camera', 'name' => 'Reverse Camera', 'name_ar' => 'كاميرا خلفية',
        'identity_aliases' => ['back camera', 'rear camera', 'كاميرا الرجوع'],
        'aliases' => ['camera not showing'],
        'category_key' => 'safety', 'tracking_mode' => 'serialized', 'position_scheme' => null],

    // ══ BATCH — quantity/position tracked ═══════════════════════════════════════════════════════

    // ── Keys ────────────────────────────────────────────────────────────────────────────────────
    // A car legitimately holds SEVERAL of these at once, which is why the scheme is 'set' (unit_1…
    // unit_4) rather than null: the asset layer allows one active component per (car, type, slot),
    // so without numbered slots key #2 would close key #1 out as if it had replaced it.
    //
    // BATCH rather than serialized on purpose. Serialized types refuse to be created without a
    // serial number, and a spare key usually arrives with nothing stamped on it worth recording. A
    // key that IS marked (a dealer's key code) still goes in serial_no — batch makes it optional,
    // not unwelcome.
    ['slug' => 'spare-key', 'name' => 'Spare Key', 'name_ar' => 'مفتاح احتياطي',
        'identity_aliases' => ['duplicate key', 'second key', 'extra key', 'مفتاح احتياطي', 'مفتاح إضافي'],
        'aliases' => ['key', 'remote', 'key fob', 'lost key', 'no spare key', 'مفتاح', 'ريموت', 'مفتاح ضايع'],
        'category_key' => 'interior', 'tracking_mode' => 'batch', 'position_scheme' => 'set',
        'notes' => 'One row per physical key (qty=1), numbered unit_1…unit_4. Raised through a Spare Key Requirement, '
            . 'bought through the normal part request → purchase chain, and created at receipt.'],

    // ── Tyres & wheels ──────────────────────────────────────────────────────────────────────────
    ['slug' => 'tyre', 'name' => 'Tyre', 'name_ar' => 'إطار',
        'spec_fields' => ['tyre_size', 'load_speed_rating', 'tyre_construction'],
        'identity_aliases' => ['tire', 'تاير', 'كفر', 'الكفرات'],
        'aliases' => ['puncture', 'worn tyre', 'بنشر'],
        'category_key' => 'tyres', 'action_target' => 'tyre', 'tracking_mode' => 'batch',
        'expected_life_km' => 50000, 'position_scheme' => 'axle_corner',
        'notes' => 'One row per tyre (qty=1); DOT code in serial_no when known.'],

    ['slug' => 'wheel-rim', 'name' => 'Wheel Rim', 'name_ar' => 'جنط',
        'identity_aliases' => ['rim', 'alloy', 'الجنوط'],
        'aliases' => ['bent rim', 'جنط معوج'],
        'category_key' => 'tyres', 'tracking_mode' => 'batch', 'position_scheme' => 'axle_corner'],

    // 'حساس الهواء' stays search-only here and on maf-sensor — it is said of both.
    ['slug' => 'tpms-sensor', 'name' => 'TPMS Sensor', 'name_ar' => 'حساس ضغط الإطارات',
        'identity_aliases' => ['tyre pressure sensor'],
        'aliases' => ['حساس الهواء', 'tpms light', 'لمبة ضغط الكفرات'],
        'category_key' => 'tyres', 'tracking_mode' => 'batch', 'position_scheme' => 'axle_corner'],

    ['slug' => 'spare-tyre', 'name' => 'Spare Tyre', 'name_ar' => 'إطار احتياطي',
        'spec_fields' => ['tyre_size', 'load_speed_rating', 'tyre_construction'],
        'identity_aliases' => ['spare wheel', 'استبن', 'الاستبنة'],
        'aliases' => ['spare missing'],
        'category_key' => 'tyres', 'tracking_mode' => 'batch', 'position_scheme' => null],

    // ── Brakes ──────────────────────────────────────────────────────────────────────────────────
    // 'بريك' means the braking system, not the pads — search-only.
    ['slug' => 'brake-pads', 'name' => 'Brake Pads (set)', 'name_ar' => 'فحمات فرامل',
        'spec_fields' => ['pad_material'],
        'identity_aliases' => ['pads', 'الفحمات', 'فحمات'],
        'aliases' => ['بريك', 'brake noise', 'squealing brakes', 'صوت فرامل'],
        'category_key' => 'brakes', 'action_target' => 'brake_pads', 'tracking_mode' => 'batch',
        'expected_life_km' => 40000, 'position_scheme' => 'axle',
        'notes' => 'CONVENTION: one set per axle, qty=1 (never per-pad).'],

    ['slug' => 'brake-discs', 'name' => 'Brake Discs (set)', 'name_ar' => 'هوبات فرامل',
        'spec_fields' => ['disc_diameter_mm'],
        'identity_aliases' => ['rotors', 'discs', 'الديسكات', 'الهوب'],
        'aliases' => ['brake vibration', 'رجة عند الفرملة'],
        'category_key' => 'brakes', 'action_target' => 'brake_discs', 'tracking_mode' => 'batch',
        'expected_life_km' => 80000, 'position_scheme' => 'axle',
        'notes' => 'CONVENTION: one set per axle, qty=1.'],

    ['slug' => 'brake-caliper', 'name' => 'Brake Caliper', 'name_ar' => 'كاليبر فرامل',
        'identity_aliases' => ['caliper', 'الكاليبر'],
        'aliases' => ['brake dragging', 'الفرامل ماسكة'],
        'category_key' => 'brakes', 'action_target' => 'brake_caliper', 'tracking_mode' => 'batch',
        'expected_life_km' => 150000, 'position_scheme' => 'axle_corner'],

    ['slug' => 'brake-master-cylinder', 'name' => 'Brake Master Cylinder', 'name_ar' => 'علبة فرامل رئيسية',
        'identity_aliases' => ['master cylinder', 'الماستر'],
        'aliases' => ['soft brake pedal', 'البدال ينزل'],
        'category_key' => 'brakes', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'brake-booster', 'name' => 'Brake Booster', 'name_ar' => 'بوستر فرامل',
        'identity_aliases' => ['servo', 'البوستر'],
        'aliases' => ['hard brake pedal', 'البدال قاسي'],
        'category_key' => 'brakes', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'brake-hose', 'name' => 'Brake Hose', 'name_ar' => 'خرطوم فرامل',
        'identity_aliases' => ['brake line', 'ماسورة فرامل'],
        'aliases' => ['brake fluid leak', 'تسريب زيت فرامل'],
        'category_key' => 'brakes', 'tracking_mode' => 'batch', 'position_scheme' => 'axle_corner'],

    ['slug' => 'handbrake-cable', 'name' => 'Handbrake Cable', 'name_ar' => 'كيبل فرامل اليد',
        'identity_aliases' => ['parking brake cable', 'كبل الهاند'],
        'aliases' => ['handbrake not holding', 'فرامل اليد ما تمسك'],
        'category_key' => 'brakes', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'abs-sensor', 'name' => 'ABS Sensor', 'name_ar' => 'حساس ABS',
        'identity_aliases' => ['wheel speed sensor', 'حساس السرعة'],
        'aliases' => ['abs light on', 'لمبة ABS'],
        'category_key' => 'brakes', 'tracking_mode' => 'batch', 'position_scheme' => 'axle_corner'],

    // ── Suspension & steering ───────────────────────────────────────────────────────────────────
    ['slug' => 'shock-absorber', 'name' => 'Shock Absorber', 'name_ar' => 'مساعد',
        'identity_aliases' => ['shocks', 'shock', 'strut', 'المساعدات'],
        'aliases' => ['bouncy ride', 'صوت من المساعد'],
        'category_key' => 'suspension', 'action_target' => 'shock_absorber', 'tracking_mode' => 'batch',
        'expected_life_km' => 80000, 'position_scheme' => 'axle_corner'],

    ['slug' => 'control-arm', 'name' => 'Control Arm', 'name_ar' => 'مقص',
        'identity_aliases' => ['wishbone', 'المقصات'],
        'aliases' => ['knocking over bumps', 'صوت خبط في المطبات'],
        'category_key' => 'suspension', 'action_target' => 'control_arm', 'tracking_mode' => 'batch',
        'expected_life_km' => 100000, 'position_scheme' => 'axle_corner'],

    ['slug' => 'ball-joint', 'name' => 'Ball Joint', 'name_ar' => 'جوزة مقص',
        'identity_aliases' => ['الجوزة', 'كرة المقص'],
        'aliases' => ['play in steering', 'خلخلة'],
        'category_key' => 'suspension', 'tracking_mode' => 'batch',
        'expected_life_km' => 100000, 'position_scheme' => 'axle_corner'],

    ['slug' => 'wheel-bearing', 'name' => 'Wheel Bearing', 'name_ar' => 'رمان بلي',
        'identity_aliases' => ['bearing', 'الرمان'],
        'aliases' => ['humming noise', 'صوت هدير مع السرعة'],
        'category_key' => 'suspension', 'action_target' => 'wheel_bearing', 'tracking_mode' => 'batch',
        'expected_life_km' => 120000, 'position_scheme' => 'axle_corner'],

    ['slug' => 'suspension-spring', 'name' => 'Suspension Spring', 'name_ar' => 'ياي',
        'identity_aliases' => ['coil spring', 'سوستة', 'اليايات'],
        'aliases' => ['car sitting low', 'السيارة مايلة'],
        'category_key' => 'suspension', 'tracking_mode' => 'batch',
        'expected_life_km' => 150000, 'position_scheme' => 'axle_corner'],

    ['slug' => 'stabilizer-link', 'name' => 'Stabilizer Link', 'name_ar' => 'جوزة موازنة',
        'identity_aliases' => ['link rod', 'sway bar link', 'اللينك'],
        'aliases' => ['rattle over bumps', 'صوت طقطقة'],
        'category_key' => 'suspension', 'action_target' => 'link_rod', 'tracking_mode' => 'batch',
        'expected_life_km' => 60000, 'position_scheme' => 'axle_corner'],

    ['slug' => 'stabilizer-bar', 'name' => 'Stabilizer Bar', 'name_ar' => 'عمود موازنة',
        'identity_aliases' => ['sway bar', 'anti-roll bar', 'عامود التوازن'],
        'aliases' => [],
        'category_key' => 'suspension', 'tracking_mode' => 'batch', 'position_scheme' => 'axle'],

    ['slug' => 'tie-rod-end', 'name' => 'Tie Rod End', 'name_ar' => 'طرف عرقة',
        'identity_aliases' => ['track rod end', 'بيادة', 'العرقة'],
        'aliases' => ['steering play', 'السيارة تسحب'],
        'category_key' => 'suspension', 'action_target' => 'tie_rod', 'tracking_mode' => 'batch',
        'expected_life_km' => 100000, 'position_scheme' => 'axle_corner'],

    ['slug' => 'strut-mount', 'name' => 'Strut Mount', 'name_ar' => 'كرسي مساعد',
        'identity_aliases' => ['top mount', 'كرسي المساعد'],
        'aliases' => ['noise when turning', 'صوت عند اللف'],
        'category_key' => 'suspension', 'tracking_mode' => 'batch', 'position_scheme' => 'axle_corner'],

    ['slug' => 'suspension-bush', 'name' => 'Suspension Bush', 'name_ar' => 'جلبة',
        'identity_aliases' => ['bushing', 'الجلب', 'كوشوك المقص'],
        'aliases' => ['clunking noise'],
        'category_key' => 'suspension', 'tracking_mode' => 'batch', 'position_scheme' => 'axle_corner'],

    ['slug' => 'steering-column', 'name' => 'Steering Column', 'name_ar' => 'عمود الدركسون',
        'identity_aliases' => ['steering shaft', 'عامود المقود'],
        'aliases' => ['noise in steering'],
        'category_key' => 'suspension', 'tracking_mode' => 'batch', 'position_scheme' => null],

    // ── Engine ──────────────────────────────────────────────────────────────────────────────────
    // 'الفلتر' on its own is any filter — search-only.
    ['slug' => 'air-filter', 'name' => 'Air Filter', 'name_ar' => 'فلتر هواء',
        'identity_aliases' => ['فلتر الهوا'],
        'aliases' => ['الفلتر', 'dirty filter'],
        'category_key' => 'engine', 'action_target' => 'air_filter', 'tracking_mode' => 'batch',
        'expected_life_km' => 20000, 'position_scheme' => null],

    ['slug' => 'fuel-filter', 'name' => 'Fuel Filter', 'name_ar' => 'فلتر بنزين',
        'identity_aliases' => ['فلتر الوقود'],
        'aliases' => ['hesitation', 'السيارة تتقطع'],
        'category_key' => 'engine', 'action_target' => 'fuel_filter', 'tracking_mode' => 'batch',
        'expected_life_km' => 40000, 'position_scheme' => null],

    ['slug' => 'spark-plugs', 'name' => 'Spark Plugs (set)', 'name_ar' => 'بواجي',
        'identity_aliases' => ['plugs', 'البواجي', 'شمعات'],
        'aliases' => ['misfire', 'رجة في المكينة'],
        'category_key' => 'engine', 'action_target' => 'spark_plugs', 'tracking_mode' => 'batch',
        'expected_life_km' => 60000, 'position_scheme' => null,
        'notes' => 'CONVENTION: one set per engine, qty=1 (never per-plug).'],

    ['slug' => 'ignition-coil', 'name' => 'Ignition Coil', 'name_ar' => 'كويل',
        'identity_aliases' => ['coil pack', 'الكويلات'],
        'aliases' => ['misfire', 'engine shaking', 'المكينة ترجف'],
        'category_key' => 'electrical', 'action_target' => 'ignition_coil', 'tracking_mode' => 'batch',
        'expected_life_km' => 100000, 'position_scheme' => null],

    ['slug' => 'fuel-injector', 'name' => 'Fuel Injector', 'name_ar' => 'بخاخ',
        'identity_aliases' => ['injector', 'البخاخات'],
        'aliases' => ['rough idle', 'صرفية بنزين عالية'],
        'category_key' => 'engine', 'tracking_mode' => 'batch', 'expected_life_km' => 150000, 'position_scheme' => null],

    // 'الكاتينة' is said of the belt AND the chain — search-only on both rows.
    ['slug' => 'timing-belt', 'name' => 'Timing Belt', 'name_ar' => 'سير كاتينة',
        'spec_fields' => ['belt_profile'],
        'identity_aliases' => ['cam belt', 'سير التايمن'],
        'aliases' => ['الكاتينة'],
        'category_key' => 'engine', 'tracking_mode' => 'batch', 'expected_life_km' => 100000, 'position_scheme' => null,
        'notes' => 'Belt engines only — a chain engine uses the timing-chain entry.'],

    ['slug' => 'timing-chain', 'name' => 'Timing Chain', 'name_ar' => 'جنزير كاتينة',
        'identity_aliases' => ['cam chain', 'الجنزير'],
        'aliases' => ['الكاتينة', 'rattle on cold start', 'صوت عند التشغيل'],
        'category_key' => 'engine', 'action_target' => 'timing_chain', 'tracking_mode' => 'batch',
        'expected_life_km' => 200000, 'position_scheme' => null],

    ['slug' => 'drive-belt', 'name' => 'Drive / Serpentine Belt', 'name_ar' => 'سير مكاين',
        'spec_fields' => ['belt_profile'],
        'identity_aliases' => ['fan belt', 'alternator belt', 'serpentine belt', 'السير'],
        'aliases' => ['squealing on start', 'صرير سير'],
        'category_key' => 'engine', 'action_target' => 'accessory_belt', 'tracking_mode' => 'batch',
        'expected_life_km' => 60000, 'position_scheme' => null],

    // 'pulley' is a different component that merely sits next to this one — search-only.
    ['slug' => 'belt-tensioner', 'name' => 'Belt Tensioner', 'name_ar' => 'شداد سير',
        'identity_aliases' => ['tensioner', 'الشداد'],
        'aliases' => ['pulley', 'belt noise'],
        'category_key' => 'engine', 'tracking_mode' => 'batch', 'expected_life_km' => 100000, 'position_scheme' => null],

    ['slug' => 'thermostat', 'name' => 'Thermostat', 'name_ar' => 'ثرموستات',
        'identity_aliases' => ['الثرموستات'],
        'aliases' => ['overheating', 'temperature high', 'الحرارة ترتفع'],
        'category_key' => 'engine', 'action_target' => 'thermostat', 'tracking_mode' => 'batch',
        'expected_life_km' => 120000, 'position_scheme' => null],

    ['slug' => 'radiator-hose', 'name' => 'Radiator Hose', 'name_ar' => 'خرطوم ردياتير',
        'identity_aliases' => ['coolant hose', 'ماسورة ماء'],
        'aliases' => ['coolant leak', 'تسريب ماء'],
        'category_key' => 'engine', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'coolant-reservoir', 'name' => 'Coolant Reservoir', 'name_ar' => 'خزان ماء',
        'identity_aliases' => ['expansion tank', 'علبة الماء'],
        'aliases' => ['coolant leak'],
        'category_key' => 'engine', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'oxygen-sensor', 'name' => 'Oxygen Sensor', 'name_ar' => 'حساس أوكسجين',
        'identity_aliases' => ['o2 sensor', 'lambda sensor', 'حساس الأكسجين'],
        'aliases' => ['check engine light'],
        'category_key' => 'engine', 'action_target' => 'oxygen_sensor', 'tracking_mode' => 'batch',
        'expected_life_km' => 100000, 'position_scheme' => null],

    ['slug' => 'maf-sensor', 'name' => 'Air Flow Sensor (MAF)', 'name_ar' => 'حساس هواء',
        'identity_aliases' => ['maf', 'mass air flow'],
        'aliases' => ['حساس الهواء', 'poor acceleration', 'ضعف في السحب'],
        'category_key' => 'engine', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'crankshaft-sensor', 'name' => 'Crankshaft Sensor', 'name_ar' => 'حساس كرنك',
        'identity_aliases' => ['crank sensor', 'حساس الكرنك'],
        'aliases' => ['engine cuts out', 'تفصل فجأة'],
        'category_key' => 'engine', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'camshaft-sensor', 'name' => 'Camshaft Sensor', 'name_ar' => 'حساس كامة',
        'identity_aliases' => ['cam sensor', 'حساس الكام'],
        'aliases' => ['hard starting'],
        'category_key' => 'engine', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'knock-sensor', 'name' => 'Knock Sensor', 'name_ar' => 'حساس دقدقة',
        'identity_aliases' => ['حساس الطرق'],
        'aliases' => ['engine knocking', 'check engine'],
        'category_key' => 'engine', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'throttle-body', 'name' => 'Throttle Body', 'name_ar' => 'بوابة هواء',
        'identity_aliases' => ['throttle', 'الثروتل'],
        'aliases' => ['rough idle', 'الرلنتي غير ثابت'],
        'category_key' => 'engine', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'intake-manifold', 'name' => 'Intake Manifold', 'name_ar' => 'مانيفول سحب',
        'identity_aliases' => ['inlet manifold', 'المانيفول'],
        'aliases' => ['vacuum leak'],
        'category_key' => 'engine', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'engine-mount', 'name' => 'Engine Mount', 'name_ar' => 'كرسي مكينة',
        'identity_aliases' => ['motor mount', 'كراسي المكينة'],
        'aliases' => ['engine vibration', 'اهتزاز المكينة'],
        'category_key' => 'engine', 'action_target' => 'engine_mount', 'tracking_mode' => 'batch',
        'expected_life_km' => 120000, 'position_scheme' => null],

    ['slug' => 'cylinder-head-gasket', 'name' => 'Cylinder Head Gasket', 'name_ar' => 'وجه مكينة',
        'identity_aliases' => ['head gasket', 'الوجه', 'الجاكيت'],
        'aliases' => ['white smoke', 'water in oil', 'ماء بالزيت'],
        'category_key' => 'engine', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'valve-cover-gasket', 'name' => 'Valve Cover Gasket', 'name_ar' => 'جوان غطاء البلوف',
        'identity_aliases' => ['rocker cover gasket', 'جوان الكفر'],
        'aliases' => ['oil leak', 'تسريب زيت'],
        'category_key' => 'engine', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'exhaust-muffler', 'name' => 'Exhaust / Muffler', 'name_ar' => 'شكمان',
        'identity_aliases' => ['silencer', 'muffler', 'الشكمان', 'العادم'],
        'aliases' => ['loud exhaust', 'صوت الشكمان عالي'],
        'category_key' => 'engine', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'egr-valve', 'name' => 'EGR Valve', 'name_ar' => 'صمام EGR',
        'identity_aliases' => ['egr', 'بلف EGR'],
        'aliases' => ['check engine light'],
        'category_key' => 'engine', 'tracking_mode' => 'batch', 'position_scheme' => null],

    // ── Transmission & drivetrain ───────────────────────────────────────────────────────────────
    ['slug' => 'cv-axle', 'name' => 'CV Axle / Drive Shaft', 'name_ar' => 'عكس',
        'identity_aliases' => ['driveshaft', 'half shaft', 'cv axle', 'العكوس'],
        'aliases' => ['clicking when turning', 'صوت عند اللف'],
        'category_key' => 'transmission', 'tracking_mode' => 'batch',
        'expected_life_km' => 150000, 'position_scheme' => 'axle_corner'],

    ['slug' => 'cv-joint-boot', 'name' => 'CV Joint Boot', 'name_ar' => 'جلدة عكس',
        'identity_aliases' => ['cv boot', 'الجلدة'],
        'aliases' => ['grease leak', 'الجلدة مقطوعة'],
        'category_key' => 'transmission', 'tracking_mode' => 'batch', 'position_scheme' => 'axle_corner'],

    ['slug' => 'transmission-mount', 'name' => 'Transmission Mount', 'name_ar' => 'كرسي جير',
        'identity_aliases' => ['gearbox mount', 'كرسي القير'],
        'aliases' => ['vibration when shifting'],
        'category_key' => 'transmission', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'propeller-shaft', 'name' => 'Propeller Shaft', 'name_ar' => 'عمود الكردان',
        'identity_aliases' => ['prop shaft', 'الكردان'],
        'aliases' => ['vibration at speed'],
        'category_key' => 'transmission', 'tracking_mode' => 'batch', 'position_scheme' => null],

    // ── Electrical ──────────────────────────────────────────────────────────────────────────────
    ['slug' => 'ignition-switch', 'name' => 'Ignition Switch', 'name_ar' => 'سويتش كونتاكت',
        'identity_aliases' => ['key switch', 'الكونتاكت'],
        'aliases' => ['key not turning', 'المفتاح ما يلف'],
        'category_key' => 'electrical', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'wiper-motor', 'name' => 'Wiper Motor', 'name_ar' => 'موتور مساحات',
        'identity_aliases' => ['موتور المساحات'],
        'aliases' => ['wipers not working', 'المساحات ما تشتغل'],
        'category_key' => 'electrical', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'window-regulator', 'name' => 'Window Regulator', 'name_ar' => 'مكينة زجاج',
        'identity_aliases' => ['window motor', 'مكينة الشباك'],
        'aliases' => ['window not going up', 'الشباك ما يطلع'],
        'category_key' => 'electrical', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'central-lock-actuator', 'name' => 'Central Lock Actuator', 'name_ar' => 'مكينة قفل مركزي',
        'identity_aliases' => ['door lock motor', 'القفل المركزي'],
        'aliases' => ['door not locking', 'الباب ما يقفل'],
        'category_key' => 'electrical', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'horn', 'name' => 'Horn', 'name_ar' => 'بوري',
        'identity_aliases' => ['هرن', 'الزمور'],
        'aliases' => ['horn not working', 'البوري ما يشتغل'],
        'category_key' => 'electrical', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'wiring-harness', 'name' => 'Wiring Harness', 'name_ar' => 'ضفيرة أسلاك',
        'identity_aliases' => ['loom', 'wiring', 'الضفيرة', 'الأسلاك'],
        'aliases' => ['short circuit', 'تماس كهربائي'],
        'category_key' => 'electrical', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'fuse-relay', 'name' => 'Fuse / Relay', 'name_ar' => 'فيوز / ريلاي',
        'identity_aliases' => ['fuse', 'relay', 'الفيوزات', 'الريليه'],
        'aliases' => ['blown fuse', 'فيوز محروق'],
        'category_key' => 'electrical', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'washer-pump', 'name' => 'Washer Pump', 'name_ar' => 'طرمبة ماء مساحات',
        'identity_aliases' => ['screen wash pump', 'مضخة الغسيل'],
        'aliases' => ['no washer spray', 'ما ينزل ماء'],
        'category_key' => 'electrical', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'fuel-level-sensor', 'name' => 'Fuel Level Sensor', 'name_ar' => 'عوامة بنزين',
        'identity_aliases' => ['fuel gauge sender', 'العوامة'],
        'aliases' => ['fuel gauge wrong', 'مؤشر البنزين غلط'],
        'category_key' => 'electrical', 'tracking_mode' => 'batch', 'position_scheme' => null],

    // ── Climate / A/C ───────────────────────────────────────────────────────────────────────────
    ['slug' => 'cabin-filter', 'name' => 'Cabin Filter', 'name_ar' => 'فلتر مكيف',
        'spec_fields' => ['filter_media'],
        'identity_aliases' => ['pollen filter', 'ac filter', 'فلتر المكيف'],
        'aliases' => ['bad smell from ac', 'ريحة من المكيف'],
        'category_key' => 'ac', 'action_target' => 'cabin_filter', 'tracking_mode' => 'batch',
        'expected_life_km' => 20000, 'expected_life_months' => 12, 'position_scheme' => null],

    ['slug' => 'ac-evaporator', 'name' => 'AC Evaporator', 'name_ar' => 'مبخر المكيف',
        'identity_aliases' => ['evaporator', 'الإيفاريتر'],
        'aliases' => ['ac not cooling', 'المكيف ما يبرد'],
        'category_key' => 'ac', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'ac-blower-motor', 'name' => 'AC Blower Motor', 'name_ar' => 'موتور مروحة المكيف',
        'identity_aliases' => ['blower', 'blower motor', 'البلور'],
        'aliases' => ['fan motor', 'no air from vents', 'ما يطلع هوا'],
        'category_key' => 'ac', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'ac-expansion-valve', 'name' => 'AC Expansion Valve', 'name_ar' => 'صمام تمدد المكيف',
        'identity_aliases' => ['expansion valve', 'بلف المكيف'],
        'aliases' => ['weak cooling'],
        'category_key' => 'ac', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'ac-hose', 'name' => 'AC Hose / Pipe', 'name_ar' => 'خرطوم مكيف',
        'identity_aliases' => ['ac pipe', 'ماسورة المكيف'],
        'aliases' => ['gas leak', 'تسريب فريون'],
        'category_key' => 'ac', 'tracking_mode' => 'batch', 'position_scheme' => null],

    // ── Lights & visibility ─────────────────────────────────────────────────────────────────────
    // 'الشمعة' is said of a headlight AND of a bulb — search-only on both rows.
    ['slug' => 'headlight', 'name' => 'Headlight Assembly', 'name_ar' => 'كشاف أمامي',
        'spec_fields' => ['bulb_fitting', 'bulb_technology'],
        'identity_aliases' => ['head lamp', 'headlight', 'الكشاف'],
        'aliases' => ['الشمعة', 'headlight broken', 'الكشاف مكسور'],
        'category_key' => 'lights', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'tail-light', 'name' => 'Tail Light Assembly', 'name_ar' => 'استوب خلفي',
        'identity_aliases' => ['rear lamp', 'tail light', 'الاستوب', 'الأسطبات'],
        'aliases' => ['tail light broken'],
        'category_key' => 'lights', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'fog-light', 'name' => 'Fog Light', 'name_ar' => 'كشاف ضباب',
        'spec_fields' => ['bulb_fitting', 'bulb_technology'],
        'identity_aliases' => ['fog lamp', 'كشافات الضباب'],
        'aliases' => ['fog light not working'],
        'category_key' => 'lights', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'indicator-light', 'name' => 'Indicator / Signal Light', 'name_ar' => 'إشارة',
        'identity_aliases' => ['turn signal', 'indicator', 'الغماز', 'الإشارة'],
        'aliases' => ['indicator not working', 'الغماز ما يشتغل'],
        'category_key' => 'lights', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'brake-light-switch', 'name' => 'Brake Light Switch', 'name_ar' => 'سويتش استوب',
        'identity_aliases' => ['stop light switch', 'سويتش الفرامل'],
        'aliases' => ['brake lights stuck on'],
        'category_key' => 'lights', 'tracking_mode' => 'batch', 'position_scheme' => null],

    // ── Bodywork & exterior ─────────────────────────────────────────────────────────────────────
    // 'bumper' alone does not say which end of the car — search-only on both bumper rows.
    ['slug' => 'front-bumper', 'name' => 'Front Bumper', 'name_ar' => 'صدام أمامي',
        'identity_aliases' => ['الصدام الأمامي'],
        'aliases' => ['bumper', 'cracked bumper', 'الصدام مكسور'],
        'category_key' => 'bodywork', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'rear-bumper', 'name' => 'Rear Bumper', 'name_ar' => 'صدام خلفي',
        'identity_aliases' => ['الصدام الخلفي'],
        'aliases' => ['bumper', 'rear damage'],
        'category_key' => 'bodywork', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'bonnet', 'name' => 'Bonnet / Hood', 'name_ar' => 'كبوت',
        'identity_aliases' => ['hood', 'bonnet', 'الكبوت', 'غطاء المحرك'],
        'aliases' => ['dented bonnet'],
        'category_key' => 'bodywork', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'boot-lid', 'name' => 'Boot Lid / Tailgate', 'name_ar' => 'غطاء الشنطة',
        'identity_aliases' => ['trunk lid', 'tailgate', 'boot lid', 'الشنطة'],
        'aliases' => ['boot not closing'],
        'category_key' => 'bodywork', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'door-panel', 'name' => 'Door', 'name_ar' => 'باب',
        'identity_aliases' => ['الأبواب'],
        'aliases' => ['door dented', 'باب مصدوم'],
        'category_key' => 'bodywork', 'tracking_mode' => 'batch', 'position_scheme' => 'axle_corner',
        'notes' => 'Uses the corner vocabulary because a car door genuinely is front/rear × left/right.'],

    ['slug' => 'fender', 'name' => 'Fender / Wing', 'name_ar' => 'رفرف',
        'identity_aliases' => ['wing', 'fender', 'الرفرف', 'الجناح'],
        'aliases' => ['scratched fender'],
        'category_key' => 'bodywork', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'side-mirror', 'name' => 'Side Mirror', 'name_ar' => 'مراية جانبية',
        'identity_aliases' => ['wing mirror', 'side mirror', 'المراية'],
        'aliases' => ['mirror broken', 'المراية مكسورة'],
        'category_key' => 'bodywork', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'windshield', 'name' => 'Windshield', 'name_ar' => 'زجاج أمامي',
        'identity_aliases' => ['windscreen', 'front glass', 'القزاز الأمامي'],
        'aliases' => ['cracked windscreen', 'الزجاج مكسور'],
        'category_key' => 'bodywork', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'rear-windscreen', 'name' => 'Rear Windscreen', 'name_ar' => 'زجاج خلفي',
        'identity_aliases' => ['back glass', 'rear glass', 'القزاز الخلفي'],
        'aliases' => ['rear glass broken'],
        'category_key' => 'bodywork', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'door-glass', 'name' => 'Door Glass', 'name_ar' => 'زجاج باب',
        'identity_aliases' => ['window glass', 'قزاز الباب'],
        'aliases' => ['window broken'],
        'category_key' => 'bodywork', 'tracking_mode' => 'batch', 'position_scheme' => 'axle_corner'],

    // 'handle' alone is also the boot and bonnet handle — search-only.
    ['slug' => 'door-handle', 'name' => 'Door Handle', 'name_ar' => 'مقبض باب',
        'identity_aliases' => ['door handle', 'يد الباب'],
        'aliases' => ['handle', 'handle broken', 'يد الباب مكسورة'],
        'category_key' => 'bodywork', 'tracking_mode' => 'batch', 'position_scheme' => 'axle_corner'],

    ['slug' => 'front-grille', 'name' => 'Front Grille', 'name_ar' => 'شبك أمامي',
        'identity_aliases' => ['grill', 'grille', 'الشبك'],
        'aliases' => ['grille broken'],
        'category_key' => 'bodywork', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'wheel-arch-liner', 'name' => 'Wheel Arch Liner', 'name_ar' => 'بطانة رفرف',
        'identity_aliases' => ['inner fender', 'splash guard', 'الدرع'],
        'aliases' => ['liner hanging'],
        'category_key' => 'bodywork', 'tracking_mode' => 'batch', 'position_scheme' => 'axle_corner'],

    // ── Interior ────────────────────────────────────────────────────────────────────────────────
    ['slug' => 'seat', 'name' => 'Seat', 'name_ar' => 'كرسي',
        'identity_aliases' => ['المقعد', 'الكراسي'],
        'aliases' => ['seat torn', 'الكرسي مقطوع'],
        'category_key' => 'interior', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'seat-cover', 'name' => 'Seat Cover', 'name_ar' => 'تلبيسة كرسي',
        'identity_aliases' => ['upholstery', 'التنجيد', 'التلبيسة'],
        'aliases' => ['stained seats'],
        'category_key' => 'interior', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'dashboard-trim', 'name' => 'Dashboard / Trim', 'name_ar' => 'طبلون',
        'identity_aliases' => ['dash', 'dashboard', 'الطبلون'],
        'aliases' => ['cracked dashboard'],
        'category_key' => 'interior', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'steering-wheel', 'name' => 'Steering Wheel', 'name_ar' => 'دركسون',
        'identity_aliases' => ['المقود', 'الدركسون'],
        'aliases' => ['worn steering wheel'],
        'category_key' => 'interior', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'gear-knob', 'name' => 'Gear Knob / Lever', 'name_ar' => 'يد الجير',
        'identity_aliases' => ['shifter', 'gear knob', 'عصا الجير'],
        'aliases' => ['gear knob broken'],
        'category_key' => 'interior', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'floor-mat', 'name' => 'Floor Mat', 'name_ar' => 'دواسة أرضية',
        'identity_aliases' => ['mats', 'floor mats', 'الفرش', 'الدعاسات'],
        'aliases' => ['mats missing'],
        'category_key' => 'interior', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'interior-mirror', 'name' => 'Rear View Mirror', 'name_ar' => 'مراية داخلية',
        'identity_aliases' => ['inside mirror', 'rear view mirror', 'المراية الداخلية'],
        'aliases' => ['mirror fell off'],
        'category_key' => 'interior', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'door-trim', 'name' => 'Door Trim Panel', 'name_ar' => 'تلبيسة باب',
        'identity_aliases' => ['door card', 'فرش الباب'],
        'aliases' => ['trim loose'],
        'category_key' => 'interior', 'tracking_mode' => 'batch', 'position_scheme' => 'axle_corner'],

    ['slug' => 'speaker', 'name' => 'Speaker', 'name_ar' => 'سماعة',
        'identity_aliases' => ['السماعات'],
        'aliases' => ['no sound', 'السماعة ما تشتغل'],
        'category_key' => 'interior', 'tracking_mode' => 'batch', 'position_scheme' => null],

    // ── Safety ──────────────────────────────────────────────────────────────────────────────────
    ['slug' => 'seat-belt', 'name' => 'Seat Belt', 'name_ar' => 'حزام أمان',
        'identity_aliases' => ['safety belt', 'seat belt', 'الحزام'],
        'aliases' => ['belt not retracting', 'الحزام ما يرجع'],
        'category_key' => 'safety', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'parking-sensor', 'name' => 'Parking Sensor', 'name_ar' => 'حساس ركن',
        'identity_aliases' => ['pdc sensor', 'حساسات الركن'],
        'aliases' => ['sensor beeping', 'الحساس يصفر'],
        'category_key' => 'safety', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'fire-extinguisher', 'name' => 'Fire Extinguisher', 'name_ar' => 'طفاية حريق',
        'identity_aliases' => ['extinguisher', 'الطفاية'],
        'aliases' => ['extinguisher expired', 'الطفاية منتهية'],
        'category_key' => 'safety', 'tracking_mode' => 'batch', 'expected_life_months' => 12, 'position_scheme' => null],

    ['slug' => 'first-aid-kit', 'name' => 'First Aid Kit', 'name_ar' => 'حقيبة إسعافات',
        'identity_aliases' => ['medical kit', 'الإسعافات الأولية'],
        'aliases' => ['kit missing'],
        'category_key' => 'safety', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'warning-triangle', 'name' => 'Warning Triangle', 'name_ar' => 'مثلث تحذيري',
        'identity_aliases' => ['المثلث'],
        'aliases' => ['triangle missing'],
        'category_key' => 'safety', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'jack-tool-kit', 'name' => 'Jack & Tool Kit', 'name_ar' => 'جك وعدة',
        'identity_aliases' => ['jack', 'wheel spanner', 'الجك', 'العدة'],
        'aliases' => ['jack missing', 'الجك مفقود'],
        'category_key' => 'safety', 'tracking_mode' => 'batch', 'position_scheme' => null],

    // ══ CONSUMABLES — NEVER become components; service_records only ══════════════════════════════
    // Standing rule (VehicleComponent::booted + ComponentService::guardNotConsumable): a fluid or a
    // fit-and-forget item is WORK PERFORMED, not an asset with a serial and a resale value. They are
    // still part of the vehicle's configuration in the user's eyes ("when was the oil last changed?"),
    // so ComponentReadModel MERGES the latest service_record per consumable type into the Installed
    // Components read — one table, two write paths, one invariant preserved.

    ['slug' => 'engine-oil', 'name' => 'Engine Oil', 'name_ar' => 'زيت محرك',
        'spec_fields' => ['viscosity', 'oil_base', 'capacity_l', 'oil_standard'],
        'identity_aliases' => ['motor oil', 'engine oil', 'الزيت'],
        'aliases' => ['oil change', 'تغيير زيت'],
        'category_key' => 'fluids', 'action_target' => 'engine_oil', 'tracking_mode' => 'consumable'],

    ['slug' => 'oil-filter', 'name' => 'Oil Filter', 'name_ar' => 'فلتر زيت',
        'identity_aliases' => ['فلتر الزيت'],
        'aliases' => ['oil change'],
        'category_key' => 'fluids', 'action_target' => 'oil_filter', 'tracking_mode' => 'consumable',
        'notes' => 'Changed with the oil — recorded on the oil-change service record.'],

    ['slug' => 'coolant', 'name' => 'Coolant', 'name_ar' => 'ماء تبريد',
        'spec_fields' => ['coolant_type', 'capacity_l'],
        'identity_aliases' => ['antifreeze', 'radiator water', 'ماء الردياتير', 'الكولنت'],
        'aliases' => ['low coolant'],
        'category_key' => 'fluids', 'action_target' => 'coolant', 'tracking_mode' => 'consumable'],

    ['slug' => 'brake-fluid', 'name' => 'Brake Fluid', 'name_ar' => 'زيت فرامل',
        'spec_fields' => ['brake_fluid_grade', 'capacity_l'],
        'identity_aliases' => ['زيت البريك'],
        'aliases' => ['brake fluid low', 'الفرامل ضعيفة'],
        'category_key' => 'fluids', 'action_target' => 'brake_fluid', 'tracking_mode' => 'consumable'],

    ['slug' => 'transmission-oil', 'name' => 'Transmission Oil', 'name_ar' => 'زيت جير',
        'spec_fields' => ['atf_spec', 'capacity_l'],
        'identity_aliases' => ['gearbox oil', 'atf', 'زيت القير'],
        'aliases' => ['gear oil change'],
        'category_key' => 'fluids', 'tracking_mode' => 'consumable'],

    ['slug' => 'power-steering-fluid', 'name' => 'Power Steering Fluid', 'name_ar' => 'زيت باور',
        'spec_fields' => ['capacity_l'],
        'identity_aliases' => ['steering oil', 'زيت الدركسون'],
        'aliases' => ['steering noise'],
        'category_key' => 'fluids', 'tracking_mode' => 'consumable'],

    ['slug' => 'ac-refrigerant', 'name' => 'A/C Refrigerant Gas', 'name_ar' => 'غاز مكيف',
        'spec_fields' => ['refrigerant_type', 'charge_g'],
        'identity_aliases' => ['freon', 'ac gas', 'الفريون', 'غاز الفريون'],
        'aliases' => ['ac gas refill', 'تعبئة غاز'],
        'category_key' => 'fluids', 'tracking_mode' => 'consumable'],

    ['slug' => 'washer-fluid', 'name' => 'Washer Fluid', 'name_ar' => 'ماء مساحات',
        'identity_aliases' => ['screen wash', 'ماء الغسيل'],
        'aliases' => ['washer empty'],
        'category_key' => 'fluids', 'tracking_mode' => 'consumable'],

    ['slug' => 'wiper-blades', 'name' => 'Wiper Blades', 'name_ar' => 'مساحات',
        'spec_fields' => ['blade_length_in'],
        'identity_aliases' => ['wipers', 'wiper blades', 'المساحات'],
        'aliases' => ['wipers smearing', 'المساحات تخربش'],
        'category_key' => 'electrical', 'action_target' => 'wiper_blades', 'tracking_mode' => 'consumable'],

    ['slug' => 'bulbs', 'name' => 'Bulbs / Lights', 'name_ar' => 'لمبات',
        'spec_fields' => ['bulb_fitting', 'bulb_technology'],
        'identity_aliases' => ['bulb', 'bulbs', 'lamp', 'اللمبات'],
        'aliases' => ['الشمعات', 'light not working', 'اللمبة محروقة'],
        'category_key' => 'electrical', 'tracking_mode' => 'consumable'],

    ['slug' => 'adblue', 'name' => 'AdBlue', 'name_ar' => 'أدبلو',
        'identity_aliases' => ['def', 'urea', 'اليوريا'],
        'aliases' => ['adblue low'],
        'category_key' => 'fluids', 'tracking_mode' => 'consumable'],

];
