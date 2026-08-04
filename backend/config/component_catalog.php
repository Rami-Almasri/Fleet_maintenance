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
 *   aliases          How people ASK for the part when they don't use its name. Two kinds live here
 *                    together, on purpose:
 *                      · other names   — 'dynamo' for the alternator, 'self' for the starter
 *                      · symptom words — 'ac not cooling', 'car not starting', 'ما يبرد'
 *                    Both exist for ONE reason: the picker's search box. Someone describing the
 *                    problem should still land on the right part.
 *                    AN ALIAS IS NOT A FAULT. The findings catalog (config/maintenance_findings.php)
 *                    owns what a fault is, what it's called and what it counts as. Nothing here is
 *                    ever counted, grouped, reported or treated as evidence of anything — matching
 *                    an alias only decides which row appears in a dropdown.
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
        'aliases' => ['motor', 'ماكينة', 'مكينة', 'engine knock', 'engine seized', 'خبطة مكينة'],
        'category_key' => 'engine', 'tracking_mode' => 'serialized', 'expected_life_km' => 300000, 'position_scheme' => null],

    ['slug' => 'gearbox', 'name' => 'Gearbox', 'name_ar' => 'ناقل الحركة',
        'aliases' => ['transmission', 'جير', 'قير', 'gear slipping', 'not shifting', 'الجير ما يسحب'],
        'category_key' => 'transmission', 'tracking_mode' => 'serialized', 'expected_life_km' => 250000, 'position_scheme' => null],

    ['slug' => 'battery-12v', 'name' => 'Battery 12V', 'name_ar' => 'بطارية',
        'aliases' => ['battery', 'بطاريه', 'car not starting', 'battery dead', 'البطارية فاضية', 'ما تشتغل'],
        'category_key' => 'electrical', 'action_target' => 'battery', 'tracking_mode' => 'serialized',
        'default_warranty_months' => 12, 'expected_life_months' => 30, 'position_scheme' => null],

    ['slug' => 'ac-compressor', 'name' => 'AC Compressor', 'name_ar' => 'كمبروسر مكيف',
        'aliases' => ['compressor', 'كمبريسر', 'ac not cooling', 'no cold air', 'المكيف ما يبرد', 'ac weak'],
        'category_key' => 'ac', 'action_target' => 'ac_compressor', 'tracking_mode' => 'serialized',
        'default_warranty_months' => 12, 'default_warranty_km' => 20000, 'position_scheme' => null],

    ['slug' => 'alternator', 'name' => 'Alternator', 'name_ar' => 'دينمو',
        'aliases' => ['dynamo', 'generator', 'دينامو', 'battery not charging', 'charging light', 'ما يشحن'],
        'category_key' => 'electrical', 'action_target' => 'alternator', 'tracking_mode' => 'serialized',
        'default_warranty_months' => 12, 'default_warranty_km' => 20000, 'position_scheme' => null],

    ['slug' => 'starter-motor', 'name' => 'Starter Motor', 'name_ar' => 'سلف',
        'aliases' => ['starter', 'self', 'مارش', 'السلف', 'car not cranking', 'ما تدور'],
        'category_key' => 'electrical', 'action_target' => 'starter_motor', 'tracking_mode' => 'serialized',
        'default_warranty_months' => 12, 'default_warranty_km' => 20000, 'position_scheme' => null],

    ['slug' => 'radiator', 'name' => 'Radiator', 'name_ar' => 'ردياتير',
        'aliases' => ['رادييتر', 'overheating', 'coolant leak', 'حرارة عالية', 'تسريب ماء'],
        'category_key' => 'engine', 'action_target' => 'radiator', 'tracking_mode' => 'serialized',
        'default_warranty_months' => 6, 'position_scheme' => null],

    ['slug' => 'ecu', 'name' => 'ECU / Control Unit', 'name_ar' => 'كمبيوتر السيارة',
        'aliases' => ['ecm', 'computer', 'الكمبيوتر', 'check engine light', 'لمبة الفحص'],
        'category_key' => 'electrical', 'tracking_mode' => 'serialized', 'position_scheme' => null],

    ['slug' => 'gps-tracker', 'name' => 'GPS Tracker', 'name_ar' => 'جهاز تتبع',
        'aliases' => ['tracker', 'gps', 'تراكر', 'تتبع', 'not reporting location'],
        'category_key' => 'electrical', 'tracking_mode' => 'serialized', 'position_scheme' => null],

    ['slug' => 'water-pump', 'name' => 'Water Pump', 'name_ar' => 'طرمبة ماء',
        'aliases' => ['coolant pump', 'مضخة ماء', 'overheating', 'حرارة', 'تسريب من الطرمبة'],
        'category_key' => 'engine', 'action_target' => 'water_pump', 'tracking_mode' => 'serialized',
        'default_warranty_months' => 12, 'default_warranty_km' => 20000, 'expected_life_km' => 120000, 'position_scheme' => null],

    ['slug' => 'fuel-pump', 'name' => 'Fuel Pump', 'name_ar' => 'طرمبة بنزين',
        'aliases' => ['petrol pump', 'مضخة وقود', 'car cuts off', 'no fuel pressure', 'تفصل وهي ماشية'],
        'category_key' => 'engine', 'tracking_mode' => 'serialized',
        'default_warranty_months' => 12, 'default_warranty_km' => 20000, 'expected_life_km' => 150000, 'position_scheme' => null],

    ['slug' => 'turbocharger', 'name' => 'Turbocharger', 'name_ar' => 'تربو',
        'aliases' => ['turbo', 'التربو', 'loss of power', 'white smoke', 'ضعف عزم'],
        'category_key' => 'engine', 'tracking_mode' => 'serialized',
        'default_warranty_months' => 12, 'default_warranty_km' => 20000, 'expected_life_km' => 200000, 'position_scheme' => null],

    ['slug' => 'ac-condenser', 'name' => 'AC Condenser', 'name_ar' => 'مكثف المكيف',
        'aliases' => ['condenser', 'كندنسر', 'ac not cooling', 'gas leak', 'المكيف ضعيف'],
        'category_key' => 'ac', 'action_target' => 'ac_condenser', 'tracking_mode' => 'serialized',
        'default_warranty_months' => 12, 'expected_life_km' => 150000, 'position_scheme' => null],

    ['slug' => 'radiator-fan', 'name' => 'Radiator Fan', 'name_ar' => 'مروحة الردياتير',
        'aliases' => ['cooling fan', 'fan motor', 'مروحة التبريد', 'overheating in traffic', 'المروحة ما تدور'],
        'category_key' => 'engine', 'action_target' => 'cooling_fan', 'tracking_mode' => 'serialized',
        'default_warranty_months' => 12, 'expected_life_km' => 150000, 'position_scheme' => null],

    ['slug' => 'steering-rack', 'name' => 'Steering Rack', 'name_ar' => 'علبة دركسون',
        'aliases' => ['rack and pinion', 'علبة مقود', 'steering heavy', 'الدركسون ثقيل', 'تسريب زيت باور'],
        'category_key' => 'suspension', 'tracking_mode' => 'serialized',
        'default_warranty_months' => 12, 'default_warranty_km' => 20000, 'expected_life_km' => 180000, 'position_scheme' => null],

    ['slug' => 'clutch-kit', 'name' => 'Clutch Kit', 'name_ar' => 'طقم كلتش',
        'aliases' => ['clutch', 'دبرياج', 'الكلتش', 'clutch slipping', 'الكلتش يزحلق'],
        'category_key' => 'transmission', 'action_target' => 'clutch', 'tracking_mode' => 'serialized',
        'default_warranty_months' => 12, 'expected_life_km' => 120000, 'position_scheme' => null],

    ['slug' => 'catalytic-converter', 'name' => 'Catalytic Converter', 'name_ar' => 'محول حفاز',
        'aliases' => ['catalyst', 'كتاليك', 'كتلست', 'check engine', 'rotten egg smell'],
        'category_key' => 'engine', 'tracking_mode' => 'serialized',
        'default_warranty_months' => 12, 'expected_life_km' => 200000, 'position_scheme' => null],

    ['slug' => 'abs-module', 'name' => 'ABS Module', 'name_ar' => 'وحدة ABS',
        'aliases' => ['abs pump', 'abs unit', 'يونت ABS', 'abs light on', 'لمبة ABS'],
        'category_key' => 'brakes', 'tracking_mode' => 'serialized',
        'default_warranty_months' => 12, 'position_scheme' => null],

    ['slug' => 'power-steering-pump', 'name' => 'Power Steering Pump', 'name_ar' => 'طرمبة باور',
        'aliases' => ['steering pump', 'مضخة الباور', 'steering heavy', 'noise when turning', 'صوت عند اللف'],
        'category_key' => 'suspension', 'tracking_mode' => 'serialized',
        'default_warranty_months' => 12, 'expected_life_km' => 150000, 'position_scheme' => null],

    ['slug' => 'differential', 'name' => 'Differential', 'name_ar' => 'دفرنس',
        'aliases' => ['diff', 'الدفرنس', 'whining noise', 'صوت من الخلف'],
        'category_key' => 'transmission', 'tracking_mode' => 'serialized', 'expected_life_km' => 250000, 'position_scheme' => null],

    ['slug' => 'infotainment-unit', 'name' => 'Radio / Infotainment Unit', 'name_ar' => 'مسجل / شاشة',
        'aliases' => ['head unit', 'radio', 'screen', 'الشاشة', 'المسجل', 'screen not working'],
        'category_key' => 'interior', 'tracking_mode' => 'serialized', 'position_scheme' => null],

    ['slug' => 'airbag', 'name' => 'Airbag', 'name_ar' => 'وسادة هوائية',
        'aliases' => ['air bag', 'الإيرباق', 'srs light', 'لمبة الإيرباق', 'airbag light on'],
        'category_key' => 'safety', 'tracking_mode' => 'serialized', 'position_scheme' => null,
        'notes' => 'Deployed airbags are a safety item — never re-used, never transferred between cars.'],

    ['slug' => 'reverse-camera', 'name' => 'Reverse Camera', 'name_ar' => 'كاميرا خلفية',
        'aliases' => ['back camera', 'rear camera', 'كاميرا الرجوع', 'camera not showing'],
        'category_key' => 'safety', 'tracking_mode' => 'serialized', 'position_scheme' => null],

    // ══ BATCH — quantity/position tracked ═══════════════════════════════════════════════════════

    // ── Tyres & wheels ──────────────────────────────────────────────────────────────────────────
    ['slug' => 'tyre', 'name' => 'Tyre', 'name_ar' => 'إطار',
        'aliases' => ['tire', 'تاير', 'كفر', 'الكفرات', 'puncture', 'worn tyre', 'بنشر'],
        'category_key' => 'tyres', 'action_target' => 'tyre', 'tracking_mode' => 'batch',
        'expected_life_km' => 50000, 'position_scheme' => 'axle_corner',
        'notes' => 'One row per tyre (qty=1); DOT code in serial_no when known.'],

    ['slug' => 'wheel-rim', 'name' => 'Wheel Rim', 'name_ar' => 'جنط',
        'aliases' => ['rim', 'alloy', 'الجنوط', 'bent rim', 'جنط معوج'],
        'category_key' => 'tyres', 'tracking_mode' => 'batch', 'position_scheme' => 'axle_corner'],

    ['slug' => 'tpms-sensor', 'name' => 'TPMS Sensor', 'name_ar' => 'حساس ضغط الإطارات',
        'aliases' => ['tyre pressure sensor', 'حساس الهواء', 'tpms light', 'لمبة ضغط الكفرات'],
        'category_key' => 'tyres', 'tracking_mode' => 'batch', 'position_scheme' => 'axle_corner'],

    ['slug' => 'spare-tyre', 'name' => 'Spare Tyre', 'name_ar' => 'إطار احتياطي',
        'aliases' => ['spare wheel', 'استبن', 'الاستبنة', 'spare missing'],
        'category_key' => 'tyres', 'tracking_mode' => 'batch', 'position_scheme' => null],

    // ── Brakes ──────────────────────────────────────────────────────────────────────────────────
    ['slug' => 'brake-pads', 'name' => 'Brake Pads (set)', 'name_ar' => 'فحمات فرامل',
        'aliases' => ['pads', 'الفحمات', 'بريك', 'brake noise', 'squealing brakes', 'صوت فرامل'],
        'category_key' => 'brakes', 'action_target' => 'brake_pads', 'tracking_mode' => 'batch',
        'expected_life_km' => 40000, 'position_scheme' => 'axle',
        'notes' => 'CONVENTION: one set per axle, qty=1 (never per-pad).'],

    ['slug' => 'brake-discs', 'name' => 'Brake Discs (set)', 'name_ar' => 'هوبات فرامل',
        'aliases' => ['rotors', 'discs', 'الديسكات', 'الهوب', 'brake vibration', 'رجة عند الفرملة'],
        'category_key' => 'brakes', 'action_target' => 'brake_discs', 'tracking_mode' => 'batch',
        'expected_life_km' => 80000, 'position_scheme' => 'axle',
        'notes' => 'CONVENTION: one set per axle, qty=1.'],

    ['slug' => 'brake-caliper', 'name' => 'Brake Caliper', 'name_ar' => 'كاليبر فرامل',
        'aliases' => ['caliper', 'الكاليبر', 'brake dragging', 'الفرامل ماسكة'],
        'category_key' => 'brakes', 'action_target' => 'brake_caliper', 'tracking_mode' => 'batch',
        'expected_life_km' => 150000, 'position_scheme' => 'axle_corner'],

    ['slug' => 'brake-master-cylinder', 'name' => 'Brake Master Cylinder', 'name_ar' => 'علبة فرامل رئيسية',
        'aliases' => ['master cylinder', 'الماستر', 'soft brake pedal', 'البدال ينزل'],
        'category_key' => 'brakes', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'brake-booster', 'name' => 'Brake Booster', 'name_ar' => 'بوستر فرامل',
        'aliases' => ['servo', 'البوستر', 'hard brake pedal', 'البدال قاسي'],
        'category_key' => 'brakes', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'brake-hose', 'name' => 'Brake Hose', 'name_ar' => 'خرطوم فرامل',
        'aliases' => ['brake line', 'ماسورة فرامل', 'brake fluid leak', 'تسريب زيت فرامل'],
        'category_key' => 'brakes', 'tracking_mode' => 'batch', 'position_scheme' => 'axle_corner'],

    ['slug' => 'handbrake-cable', 'name' => 'Handbrake Cable', 'name_ar' => 'كيبل فرامل اليد',
        'aliases' => ['parking brake cable', 'كبل الهاند', 'handbrake not holding', 'فرامل اليد ما تمسك'],
        'category_key' => 'brakes', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'abs-sensor', 'name' => 'ABS Sensor', 'name_ar' => 'حساس ABS',
        'aliases' => ['wheel speed sensor', 'حساس السرعة', 'abs light on', 'لمبة ABS'],
        'category_key' => 'brakes', 'tracking_mode' => 'batch', 'position_scheme' => 'axle_corner'],

    // ── Suspension & steering ───────────────────────────────────────────────────────────────────
    ['slug' => 'shock-absorber', 'name' => 'Shock Absorber', 'name_ar' => 'مساعد',
        'aliases' => ['shocks', 'strut', 'المساعدات', 'bouncy ride', 'صوت من المساعد'],
        'category_key' => 'suspension', 'action_target' => 'shock_absorber', 'tracking_mode' => 'batch',
        'expected_life_km' => 80000, 'position_scheme' => 'axle_corner'],

    ['slug' => 'control-arm', 'name' => 'Control Arm', 'name_ar' => 'مقص',
        'aliases' => ['wishbone', 'المقصات', 'knocking over bumps', 'صوت خبط في المطبات'],
        'category_key' => 'suspension', 'action_target' => 'control_arm', 'tracking_mode' => 'batch',
        'expected_life_km' => 100000, 'position_scheme' => 'axle_corner'],

    ['slug' => 'ball-joint', 'name' => 'Ball Joint', 'name_ar' => 'جوزة مقص',
        'aliases' => ['الجوزة', 'كرة المقص', 'play in steering', 'خلخلة'],
        'category_key' => 'suspension', 'tracking_mode' => 'batch',
        'expected_life_km' => 100000, 'position_scheme' => 'axle_corner'],

    ['slug' => 'wheel-bearing', 'name' => 'Wheel Bearing', 'name_ar' => 'رمان بلي',
        'aliases' => ['bearing', 'الرمان', 'humming noise', 'صوت هدير مع السرعة'],
        'category_key' => 'suspension', 'action_target' => 'wheel_bearing', 'tracking_mode' => 'batch',
        'expected_life_km' => 120000, 'position_scheme' => 'axle_corner'],

    ['slug' => 'suspension-spring', 'name' => 'Suspension Spring', 'name_ar' => 'ياي',
        'aliases' => ['coil spring', 'سوستة', 'اليايات', 'car sitting low', 'السيارة مايلة'],
        'category_key' => 'suspension', 'tracking_mode' => 'batch',
        'expected_life_km' => 150000, 'position_scheme' => 'axle_corner'],

    ['slug' => 'stabilizer-link', 'name' => 'Stabilizer Link', 'name_ar' => 'جوزة موازنة',
        'aliases' => ['link rod', 'sway bar link', 'اللينك', 'rattle over bumps', 'صوت طقطقة'],
        'category_key' => 'suspension', 'action_target' => 'link_rod', 'tracking_mode' => 'batch',
        'expected_life_km' => 60000, 'position_scheme' => 'axle_corner'],

    ['slug' => 'stabilizer-bar', 'name' => 'Stabilizer Bar', 'name_ar' => 'عمود موازنة',
        'aliases' => ['sway bar', 'anti-roll bar', 'عامود التوازن'],
        'category_key' => 'suspension', 'tracking_mode' => 'batch', 'position_scheme' => 'axle'],

    ['slug' => 'tie-rod-end', 'name' => 'Tie Rod End', 'name_ar' => 'طرف عرقة',
        'aliases' => ['track rod end', 'بيادة', 'العرقة', 'steering play', 'السيارة تسحب'],
        'category_key' => 'suspension', 'action_target' => 'tie_rod', 'tracking_mode' => 'batch',
        'expected_life_km' => 100000, 'position_scheme' => 'axle_corner'],

    ['slug' => 'strut-mount', 'name' => 'Strut Mount', 'name_ar' => 'كرسي مساعد',
        'aliases' => ['top mount', 'كرسي المساعد', 'noise when turning', 'صوت عند اللف'],
        'category_key' => 'suspension', 'tracking_mode' => 'batch', 'position_scheme' => 'axle_corner'],

    ['slug' => 'suspension-bush', 'name' => 'Suspension Bush', 'name_ar' => 'جلبة',
        'aliases' => ['bushing', 'الجلب', 'كوشوك المقص', 'clunking noise'],
        'category_key' => 'suspension', 'tracking_mode' => 'batch', 'position_scheme' => 'axle_corner'],

    ['slug' => 'steering-column', 'name' => 'Steering Column', 'name_ar' => 'عمود الدركسون',
        'aliases' => ['steering shaft', 'عامود المقود', 'noise in steering'],
        'category_key' => 'suspension', 'tracking_mode' => 'batch', 'position_scheme' => null],

    // ── Engine ──────────────────────────────────────────────────────────────────────────────────
    ['slug' => 'air-filter', 'name' => 'Air Filter', 'name_ar' => 'فلتر هواء',
        'aliases' => ['فلتر الهوا', 'الفلتر', 'dirty filter'],
        'category_key' => 'engine', 'action_target' => 'air_filter', 'tracking_mode' => 'batch',
        'expected_life_km' => 20000, 'position_scheme' => null],

    ['slug' => 'fuel-filter', 'name' => 'Fuel Filter', 'name_ar' => 'فلتر بنزين',
        'aliases' => ['فلتر الوقود', 'hesitation', 'السيارة تتقطع'],
        'category_key' => 'engine', 'action_target' => 'fuel_filter', 'tracking_mode' => 'batch',
        'expected_life_km' => 40000, 'position_scheme' => null],

    ['slug' => 'spark-plugs', 'name' => 'Spark Plugs (set)', 'name_ar' => 'بواجي',
        'aliases' => ['plugs', 'البواجي', 'شمعات', 'misfire', 'رجة في المكينة'],
        'category_key' => 'engine', 'action_target' => 'spark_plugs', 'tracking_mode' => 'batch',
        'expected_life_km' => 60000, 'position_scheme' => null,
        'notes' => 'CONVENTION: one set per engine, qty=1 (never per-plug).'],

    ['slug' => 'ignition-coil', 'name' => 'Ignition Coil', 'name_ar' => 'كويل',
        'aliases' => ['coil pack', 'الكويلات', 'misfire', 'engine shaking', 'المكينة ترجف'],
        'category_key' => 'electrical', 'action_target' => 'ignition_coil', 'tracking_mode' => 'batch',
        'expected_life_km' => 100000, 'position_scheme' => null],

    ['slug' => 'fuel-injector', 'name' => 'Fuel Injector', 'name_ar' => 'بخاخ',
        'aliases' => ['injector', 'البخاخات', 'rough idle', 'صرفية بنزين عالية'],
        'category_key' => 'engine', 'tracking_mode' => 'batch', 'expected_life_km' => 150000, 'position_scheme' => null],

    ['slug' => 'timing-belt', 'name' => 'Timing Belt', 'name_ar' => 'سير كاتينة',
        'aliases' => ['cam belt', 'سير التايمن', 'الكاتينة'],
        'category_key' => 'engine', 'tracking_mode' => 'batch', 'expected_life_km' => 100000, 'position_scheme' => null,
        'notes' => 'Belt engines only — a chain engine uses the timing-chain entry.'],

    ['slug' => 'timing-chain', 'name' => 'Timing Chain', 'name_ar' => 'جنزير كاتينة',
        'aliases' => ['cam chain', 'الجنزير', 'rattle on cold start', 'صوت عند التشغيل'],
        'category_key' => 'engine', 'action_target' => 'timing_chain', 'tracking_mode' => 'batch',
        'expected_life_km' => 200000, 'position_scheme' => null],

    ['slug' => 'drive-belt', 'name' => 'Drive / Serpentine Belt', 'name_ar' => 'سير مكاين',
        'aliases' => ['fan belt', 'alternator belt', 'السير', 'squealing on start', 'صرير سير'],
        'category_key' => 'engine', 'action_target' => 'accessory_belt', 'tracking_mode' => 'batch',
        'expected_life_km' => 60000, 'position_scheme' => null],

    ['slug' => 'belt-tensioner', 'name' => 'Belt Tensioner', 'name_ar' => 'شداد سير',
        'aliases' => ['tensioner', 'pulley', 'الشداد', 'belt noise'],
        'category_key' => 'engine', 'tracking_mode' => 'batch', 'expected_life_km' => 100000, 'position_scheme' => null],

    ['slug' => 'thermostat', 'name' => 'Thermostat', 'name_ar' => 'ثرموستات',
        'aliases' => ['الثرموستات', 'overheating', 'temperature high', 'الحرارة ترتفع'],
        'category_key' => 'engine', 'action_target' => 'thermostat', 'tracking_mode' => 'batch',
        'expected_life_km' => 120000, 'position_scheme' => null],

    ['slug' => 'radiator-hose', 'name' => 'Radiator Hose', 'name_ar' => 'خرطوم ردياتير',
        'aliases' => ['coolant hose', 'ماسورة ماء', 'coolant leak', 'تسريب ماء'],
        'category_key' => 'engine', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'coolant-reservoir', 'name' => 'Coolant Reservoir', 'name_ar' => 'خزان ماء',
        'aliases' => ['expansion tank', 'علبة الماء', 'coolant leak'],
        'category_key' => 'engine', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'oxygen-sensor', 'name' => 'Oxygen Sensor', 'name_ar' => 'حساس أوكسجين',
        'aliases' => ['o2 sensor', 'lambda sensor', 'حساس الأكسجين', 'check engine light'],
        'category_key' => 'engine', 'action_target' => 'oxygen_sensor', 'tracking_mode' => 'batch',
        'expected_life_km' => 100000, 'position_scheme' => null],

    ['slug' => 'maf-sensor', 'name' => 'Air Flow Sensor (MAF)', 'name_ar' => 'حساس هواء',
        'aliases' => ['maf', 'mass air flow', 'حساس الهواء', 'poor acceleration', 'ضعف في السحب'],
        'category_key' => 'engine', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'crankshaft-sensor', 'name' => 'Crankshaft Sensor', 'name_ar' => 'حساس كرنك',
        'aliases' => ['crank sensor', 'حساس الكرنك', 'engine cuts out', 'تفصل فجأة'],
        'category_key' => 'engine', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'camshaft-sensor', 'name' => 'Camshaft Sensor', 'name_ar' => 'حساس كامة',
        'aliases' => ['cam sensor', 'حساس الكام', 'hard starting'],
        'category_key' => 'engine', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'knock-sensor', 'name' => 'Knock Sensor', 'name_ar' => 'حساس دقدقة',
        'aliases' => ['حساس الطرق', 'engine knocking', 'check engine'],
        'category_key' => 'engine', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'throttle-body', 'name' => 'Throttle Body', 'name_ar' => 'بوابة هواء',
        'aliases' => ['throttle', 'الثروتل', 'rough idle', 'الرلنتي غير ثابت'],
        'category_key' => 'engine', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'intake-manifold', 'name' => 'Intake Manifold', 'name_ar' => 'مانيفول سحب',
        'aliases' => ['inlet manifold', 'المانيفول', 'vacuum leak'],
        'category_key' => 'engine', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'engine-mount', 'name' => 'Engine Mount', 'name_ar' => 'كرسي مكينة',
        'aliases' => ['motor mount', 'كراسي المكينة', 'engine vibration', 'اهتزاز المكينة'],
        'category_key' => 'engine', 'action_target' => 'engine_mount', 'tracking_mode' => 'batch',
        'expected_life_km' => 120000, 'position_scheme' => null],

    ['slug' => 'cylinder-head-gasket', 'name' => 'Cylinder Head Gasket', 'name_ar' => 'وجه مكينة',
        'aliases' => ['head gasket', 'الوجه', 'الجاكيت', 'white smoke', 'water in oil', 'ماء بالزيت'],
        'category_key' => 'engine', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'valve-cover-gasket', 'name' => 'Valve Cover Gasket', 'name_ar' => 'جوان غطاء البلوف',
        'aliases' => ['rocker cover gasket', 'جوان الكفر', 'oil leak', 'تسريب زيت'],
        'category_key' => 'engine', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'exhaust-muffler', 'name' => 'Exhaust / Muffler', 'name_ar' => 'شكمان',
        'aliases' => ['silencer', 'الشكمان', 'العادم', 'loud exhaust', 'صوت الشكمان عالي'],
        'category_key' => 'engine', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'egr-valve', 'name' => 'EGR Valve', 'name_ar' => 'صمام EGR',
        'aliases' => ['egr', 'بلف EGR', 'check engine light'],
        'category_key' => 'engine', 'tracking_mode' => 'batch', 'position_scheme' => null],

    // ── Transmission & drivetrain ───────────────────────────────────────────────────────────────
    ['slug' => 'cv-axle', 'name' => 'CV Axle / Drive Shaft', 'name_ar' => 'عكس',
        'aliases' => ['driveshaft', 'half shaft', 'العكوس', 'clicking when turning', 'صوت عند اللف'],
        'category_key' => 'transmission', 'tracking_mode' => 'batch',
        'expected_life_km' => 150000, 'position_scheme' => 'axle_corner'],

    ['slug' => 'cv-joint-boot', 'name' => 'CV Joint Boot', 'name_ar' => 'جلدة عكس',
        'aliases' => ['cv boot', 'الجلدة', 'grease leak', 'الجلدة مقطوعة'],
        'category_key' => 'transmission', 'tracking_mode' => 'batch', 'position_scheme' => 'axle_corner'],

    ['slug' => 'transmission-mount', 'name' => 'Transmission Mount', 'name_ar' => 'كرسي جير',
        'aliases' => ['gearbox mount', 'كرسي القير', 'vibration when shifting'],
        'category_key' => 'transmission', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'propeller-shaft', 'name' => 'Propeller Shaft', 'name_ar' => 'عمود الكردان',
        'aliases' => ['prop shaft', 'الكردان', 'vibration at speed'],
        'category_key' => 'transmission', 'tracking_mode' => 'batch', 'position_scheme' => null],

    // ── Electrical ──────────────────────────────────────────────────────────────────────────────
    ['slug' => 'ignition-switch', 'name' => 'Ignition Switch', 'name_ar' => 'سويتش كونتاكت',
        'aliases' => ['key switch', 'الكونتاكت', 'key not turning', 'المفتاح ما يلف'],
        'category_key' => 'electrical', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'wiper-motor', 'name' => 'Wiper Motor', 'name_ar' => 'موتور مساحات',
        'aliases' => ['موتور المساحات', 'wipers not working', 'المساحات ما تشتغل'],
        'category_key' => 'electrical', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'window-regulator', 'name' => 'Window Regulator', 'name_ar' => 'مكينة زجاج',
        'aliases' => ['window motor', 'مكينة الشباك', 'window not going up', 'الشباك ما يطلع'],
        'category_key' => 'electrical', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'central-lock-actuator', 'name' => 'Central Lock Actuator', 'name_ar' => 'مكينة قفل مركزي',
        'aliases' => ['door lock motor', 'القفل المركزي', 'door not locking', 'الباب ما يقفل'],
        'category_key' => 'electrical', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'horn', 'name' => 'Horn', 'name_ar' => 'بوري',
        'aliases' => ['هرن', 'الزمور', 'horn not working', 'البوري ما يشتغل'],
        'category_key' => 'electrical', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'wiring-harness', 'name' => 'Wiring Harness', 'name_ar' => 'ضفيرة أسلاك',
        'aliases' => ['loom', 'wiring', 'الضفيرة', 'الأسلاك', 'short circuit', 'تماس كهربائي'],
        'category_key' => 'electrical', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'fuse-relay', 'name' => 'Fuse / Relay', 'name_ar' => 'فيوز / ريلاي',
        'aliases' => ['fuse', 'relay', 'الفيوزات', 'الريليه', 'blown fuse', 'فيوز محروق'],
        'category_key' => 'electrical', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'washer-pump', 'name' => 'Washer Pump', 'name_ar' => 'طرمبة ماء مساحات',
        'aliases' => ['screen wash pump', 'مضخة الغسيل', 'no washer spray', 'ما ينزل ماء'],
        'category_key' => 'electrical', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'fuel-level-sensor', 'name' => 'Fuel Level Sensor', 'name_ar' => 'عوامة بنزين',
        'aliases' => ['fuel gauge sender', 'العوامة', 'fuel gauge wrong', 'مؤشر البنزين غلط'],
        'category_key' => 'electrical', 'tracking_mode' => 'batch', 'position_scheme' => null],

    // ── Climate / A/C ───────────────────────────────────────────────────────────────────────────
    ['slug' => 'cabin-filter', 'name' => 'Cabin Filter', 'name_ar' => 'فلتر مكيف',
        'aliases' => ['pollen filter', 'فلتر المكيف', 'bad smell from ac', 'ريحة من المكيف'],
        'category_key' => 'ac', 'action_target' => 'cabin_filter', 'tracking_mode' => 'batch',
        'expected_life_km' => 20000, 'expected_life_months' => 12, 'position_scheme' => null],

    ['slug' => 'ac-evaporator', 'name' => 'AC Evaporator', 'name_ar' => 'مبخر المكيف',
        'aliases' => ['evaporator', 'الإيفاريتر', 'ac not cooling', 'المكيف ما يبرد'],
        'category_key' => 'ac', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'ac-blower-motor', 'name' => 'AC Blower Motor', 'name_ar' => 'موتور مروحة المكيف',
        'aliases' => ['blower', 'fan motor', 'البلور', 'no air from vents', 'ما يطلع هوا'],
        'category_key' => 'ac', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'ac-expansion-valve', 'name' => 'AC Expansion Valve', 'name_ar' => 'صمام تمدد المكيف',
        'aliases' => ['expansion valve', 'بلف المكيف', 'weak cooling'],
        'category_key' => 'ac', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'ac-hose', 'name' => 'AC Hose / Pipe', 'name_ar' => 'خرطوم مكيف',
        'aliases' => ['ac pipe', 'ماسورة المكيف', 'gas leak', 'تسريب فريون'],
        'category_key' => 'ac', 'tracking_mode' => 'batch', 'position_scheme' => null],

    // ── Lights & visibility ─────────────────────────────────────────────────────────────────────
    ['slug' => 'headlight', 'name' => 'Headlight Assembly', 'name_ar' => 'كشاف أمامي',
        'aliases' => ['head lamp', 'الكشاف', 'الشمعة', 'headlight broken', 'الكشاف مكسور'],
        'category_key' => 'lights', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'tail-light', 'name' => 'Tail Light Assembly', 'name_ar' => 'استوب خلفي',
        'aliases' => ['rear lamp', 'الاستوب', 'الأسطبات', 'tail light broken'],
        'category_key' => 'lights', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'fog-light', 'name' => 'Fog Light', 'name_ar' => 'كشاف ضباب',
        'aliases' => ['fog lamp', 'كشافات الضباب', 'fog light not working'],
        'category_key' => 'lights', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'indicator-light', 'name' => 'Indicator / Signal Light', 'name_ar' => 'إشارة',
        'aliases' => ['turn signal', 'الغماز', 'الإشارة', 'indicator not working', 'الغماز ما يشتغل'],
        'category_key' => 'lights', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'brake-light-switch', 'name' => 'Brake Light Switch', 'name_ar' => 'سويتش استوب',
        'aliases' => ['stop light switch', 'سويتش الفرامل', 'brake lights stuck on'],
        'category_key' => 'lights', 'tracking_mode' => 'batch', 'position_scheme' => null],

    // ── Bodywork & exterior ─────────────────────────────────────────────────────────────────────
    ['slug' => 'front-bumper', 'name' => 'Front Bumper', 'name_ar' => 'صدام أمامي',
        'aliases' => ['bumper', 'الصدام الأمامي', 'cracked bumper', 'الصدام مكسور'],
        'category_key' => 'bodywork', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'rear-bumper', 'name' => 'Rear Bumper', 'name_ar' => 'صدام خلفي',
        'aliases' => ['الصدام الخلفي', 'rear damage'],
        'category_key' => 'bodywork', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'bonnet', 'name' => 'Bonnet / Hood', 'name_ar' => 'كبوت',
        'aliases' => ['hood', 'الكبوت', 'غطاء المحرك', 'dented bonnet'],
        'category_key' => 'bodywork', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'boot-lid', 'name' => 'Boot Lid / Tailgate', 'name_ar' => 'غطاء الشنطة',
        'aliases' => ['trunk lid', 'tailgate', 'الشنطة', 'boot not closing'],
        'category_key' => 'bodywork', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'door-panel', 'name' => 'Door', 'name_ar' => 'باب',
        'aliases' => ['الأبواب', 'door dented', 'باب مصدوم'],
        'category_key' => 'bodywork', 'tracking_mode' => 'batch', 'position_scheme' => 'axle_corner',
        'notes' => 'Uses the corner vocabulary because a car door genuinely is front/rear × left/right.'],

    ['slug' => 'fender', 'name' => 'Fender / Wing', 'name_ar' => 'رفرف',
        'aliases' => ['wing', 'الرفرف', 'الجناح', 'scratched fender'],
        'category_key' => 'bodywork', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'side-mirror', 'name' => 'Side Mirror', 'name_ar' => 'مراية جانبية',
        'aliases' => ['wing mirror', 'المراية', 'mirror broken', 'المراية مكسورة'],
        'category_key' => 'bodywork', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'windshield', 'name' => 'Windshield', 'name_ar' => 'زجاج أمامي',
        'aliases' => ['windscreen', 'front glass', 'القزاز الأمامي', 'cracked windscreen', 'الزجاج مكسور'],
        'category_key' => 'bodywork', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'rear-windscreen', 'name' => 'Rear Windscreen', 'name_ar' => 'زجاج خلفي',
        'aliases' => ['back glass', 'القزاز الخلفي', 'rear glass broken'],
        'category_key' => 'bodywork', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'door-glass', 'name' => 'Door Glass', 'name_ar' => 'زجاج باب',
        'aliases' => ['window glass', 'قزاز الباب', 'window broken'],
        'category_key' => 'bodywork', 'tracking_mode' => 'batch', 'position_scheme' => 'axle_corner'],

    ['slug' => 'door-handle', 'name' => 'Door Handle', 'name_ar' => 'مقبض باب',
        'aliases' => ['handle', 'يد الباب', 'handle broken', 'يد الباب مكسورة'],
        'category_key' => 'bodywork', 'tracking_mode' => 'batch', 'position_scheme' => 'axle_corner'],

    ['slug' => 'front-grille', 'name' => 'Front Grille', 'name_ar' => 'شبك أمامي',
        'aliases' => ['grill', 'الشبك', 'grille broken'],
        'category_key' => 'bodywork', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'wheel-arch-liner', 'name' => 'Wheel Arch Liner', 'name_ar' => 'بطانة رفرف',
        'aliases' => ['inner fender', 'splash guard', 'الدرع', 'liner hanging'],
        'category_key' => 'bodywork', 'tracking_mode' => 'batch', 'position_scheme' => 'axle_corner'],

    // ── Interior ────────────────────────────────────────────────────────────────────────────────
    ['slug' => 'seat', 'name' => 'Seat', 'name_ar' => 'كرسي',
        'aliases' => ['المقعد', 'الكراسي', 'seat torn', 'الكرسي مقطوع'],
        'category_key' => 'interior', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'seat-cover', 'name' => 'Seat Cover', 'name_ar' => 'تلبيسة كرسي',
        'aliases' => ['upholstery', 'التنجيد', 'التلبيسة', 'stained seats'],
        'category_key' => 'interior', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'dashboard-trim', 'name' => 'Dashboard / Trim', 'name_ar' => 'طبلون',
        'aliases' => ['dash', 'الطبلون', 'cracked dashboard'],
        'category_key' => 'interior', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'steering-wheel', 'name' => 'Steering Wheel', 'name_ar' => 'دركسون',
        'aliases' => ['المقود', 'الدركسون', 'worn steering wheel'],
        'category_key' => 'interior', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'gear-knob', 'name' => 'Gear Knob / Lever', 'name_ar' => 'يد الجير',
        'aliases' => ['shifter', 'عصا الجير', 'gear knob broken'],
        'category_key' => 'interior', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'floor-mat', 'name' => 'Floor Mat', 'name_ar' => 'دواسة أرضية',
        'aliases' => ['mats', 'الفرش', 'الدعاسات', 'mats missing'],
        'category_key' => 'interior', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'interior-mirror', 'name' => 'Rear View Mirror', 'name_ar' => 'مراية داخلية',
        'aliases' => ['inside mirror', 'المراية الداخلية', 'mirror fell off'],
        'category_key' => 'interior', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'door-trim', 'name' => 'Door Trim Panel', 'name_ar' => 'تلبيسة باب',
        'aliases' => ['door card', 'فرش الباب', 'trim loose'],
        'category_key' => 'interior', 'tracking_mode' => 'batch', 'position_scheme' => 'axle_corner'],

    ['slug' => 'speaker', 'name' => 'Speaker', 'name_ar' => 'سماعة',
        'aliases' => ['السماعات', 'no sound', 'السماعة ما تشتغل'],
        'category_key' => 'interior', 'tracking_mode' => 'batch', 'position_scheme' => null],

    // ── Safety ──────────────────────────────────────────────────────────────────────────────────
    ['slug' => 'seat-belt', 'name' => 'Seat Belt', 'name_ar' => 'حزام أمان',
        'aliases' => ['safety belt', 'الحزام', 'belt not retracting', 'الحزام ما يرجع'],
        'category_key' => 'safety', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'parking-sensor', 'name' => 'Parking Sensor', 'name_ar' => 'حساس ركن',
        'aliases' => ['pdc sensor', 'حساسات الركن', 'sensor beeping', 'الحساس يصفر'],
        'category_key' => 'safety', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'fire-extinguisher', 'name' => 'Fire Extinguisher', 'name_ar' => 'طفاية حريق',
        'aliases' => ['الطفاية', 'extinguisher expired', 'الطفاية منتهية'],
        'category_key' => 'safety', 'tracking_mode' => 'batch', 'expected_life_months' => 12, 'position_scheme' => null],

    ['slug' => 'first-aid-kit', 'name' => 'First Aid Kit', 'name_ar' => 'حقيبة إسعافات',
        'aliases' => ['medical kit', 'الإسعافات الأولية', 'kit missing'],
        'category_key' => 'safety', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'warning-triangle', 'name' => 'Warning Triangle', 'name_ar' => 'مثلث تحذيري',
        'aliases' => ['المثلث', 'triangle missing'],
        'category_key' => 'safety', 'tracking_mode' => 'batch', 'position_scheme' => null],

    ['slug' => 'jack-tool-kit', 'name' => 'Jack & Tool Kit', 'name_ar' => 'جك وعدة',
        'aliases' => ['jack', 'wheel spanner', 'الجك', 'العدة', 'jack missing', 'الجك مفقود'],
        'category_key' => 'safety', 'tracking_mode' => 'batch', 'position_scheme' => null],

    // ══ CONSUMABLES — NEVER become components; service_records only ══════════════════════════════
    // Standing rule (VehicleComponent::booted + ComponentService::guardNotConsumable): a fluid or a
    // fit-and-forget item is WORK PERFORMED, not an asset with a serial and a resale value. They are
    // still part of the vehicle's configuration in the user's eyes ("when was the oil last changed?"),
    // so ComponentReadModel MERGES the latest service_record per consumable type into the Installed
    // Components read — one table, two write paths, one invariant preserved.

    ['slug' => 'engine-oil', 'name' => 'Engine Oil', 'name_ar' => 'زيت محرك',
        'aliases' => ['motor oil', 'الزيت', 'oil change', 'تغيير زيت'],
        'category_key' => 'fluids', 'action_target' => 'engine_oil', 'tracking_mode' => 'consumable'],

    ['slug' => 'oil-filter', 'name' => 'Oil Filter', 'name_ar' => 'فلتر زيت',
        'aliases' => ['فلتر الزيت', 'oil change'],
        'category_key' => 'fluids', 'action_target' => 'oil_filter', 'tracking_mode' => 'consumable',
        'notes' => 'Changed with the oil — recorded on the oil-change service record.'],

    ['slug' => 'coolant', 'name' => 'Coolant', 'name_ar' => 'ماء تبريد',
        'aliases' => ['antifreeze', 'radiator water', 'ماء الردياتير', 'الكولنت', 'low coolant'],
        'category_key' => 'fluids', 'action_target' => 'coolant', 'tracking_mode' => 'consumable'],

    ['slug' => 'brake-fluid', 'name' => 'Brake Fluid', 'name_ar' => 'زيت فرامل',
        'aliases' => ['زيت البريك', 'brake fluid low', 'الفرامل ضعيفة'],
        'category_key' => 'fluids', 'action_target' => 'brake_fluid', 'tracking_mode' => 'consumable'],

    ['slug' => 'transmission-oil', 'name' => 'Transmission Oil', 'name_ar' => 'زيت جير',
        'aliases' => ['gearbox oil', 'atf', 'زيت القير', 'gear oil change'],
        'category_key' => 'fluids', 'tracking_mode' => 'consumable'],

    ['slug' => 'power-steering-fluid', 'name' => 'Power Steering Fluid', 'name_ar' => 'زيت باور',
        'aliases' => ['steering oil', 'زيت الدركسون', 'steering noise'],
        'category_key' => 'fluids', 'tracking_mode' => 'consumable'],

    ['slug' => 'ac-refrigerant', 'name' => 'A/C Refrigerant Gas', 'name_ar' => 'غاز مكيف',
        'aliases' => ['freon', 'ac gas', 'الفريون', 'غاز الفريون', 'ac gas refill', 'تعبئة غاز'],
        'category_key' => 'fluids', 'tracking_mode' => 'consumable'],

    ['slug' => 'washer-fluid', 'name' => 'Washer Fluid', 'name_ar' => 'ماء مساحات',
        'aliases' => ['screen wash', 'ماء الغسيل', 'washer empty'],
        'category_key' => 'fluids', 'tracking_mode' => 'consumable'],

    ['slug' => 'wiper-blades', 'name' => 'Wiper Blades', 'name_ar' => 'مساحات',
        'aliases' => ['wipers', 'المساحات', 'wipers smearing', 'المساحات تخربش'],
        'category_key' => 'electrical', 'action_target' => 'wiper_blades', 'tracking_mode' => 'consumable'],

    ['slug' => 'bulbs', 'name' => 'Bulbs / Lights', 'name_ar' => 'لمبات',
        'aliases' => ['bulb', 'lamp', 'اللمبات', 'الشمعات', 'light not working', 'اللمبة محروقة'],
        'category_key' => 'electrical', 'tracking_mode' => 'consumable'],

    ['slug' => 'adblue', 'name' => 'AdBlue', 'name_ar' => 'أدبلو',
        'aliases' => ['def', 'urea', 'اليوريا', 'adblue low'],
        'category_key' => 'fluids', 'tracking_mode' => 'consumable'],

];
