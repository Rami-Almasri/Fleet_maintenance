<?php

/**
 * Fault Catalog — the canonical menu of unplanned FAILURES / DEFECTS (kind = fault).
 *
 * A fault is an unexpected problem (leaks, noises, overheating, electrical faults, breakage). Picking
 * one at intake stamps the maintenance_task with kind='fault' + fault_catalog_id, so it DOES count in
 * Top Faults, recurrence detection, health/reliability/risk and fault KPIs. See
 * docs/Service-vs-Fault-Domain-Separation.md.
 *
 * Evolves the non-routine categories of config/maintenance_findings.php into a first-class list, and is
 * the target `fault_causes.fault_catalog_id` will reference. Seeded by FaultCatalogSeeder (idempotent
 * upsert by `slug`; never rename a slug once referenced).
 *
 * `name_ar` is REQUIRED on every row, not a nicety. The intake picker searches name AND name_ar, so a
 * row without it is a fault an Arabic-speaking user cannot find by typing — and it renders in English on
 * an otherwise Arabic screen, because the label falls back. Adding a fault means adding both names.
 *
 * Shape per row: { slug, name, name_ar, category_key, default_severity?, on_site, sort_order }
 * default_severity is a PREFILL hint only (Maintenance::FAULT_SEVERITIES) — descriptive, editable.
 */

return [

    // ── Engine ──────────────────────────────────────────────────────────────────────────────────
    ['slug' => 'engine_noise',          'name' => 'Engine noise', 'name_ar' => 'صوت في المحرك',              'category_key' => 'engine', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 10],
    ['slug' => 'engine_misfire',        'name' => 'Rough idle / misfire', 'name_ar' => 'تقطيع / عدم انتظام الدوران',      'category_key' => 'engine', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 20],
    ['slug' => 'engine_loss_of_power',  'name' => 'Loss of power', 'name_ar' => 'ضعف في السحب',             'category_key' => 'engine', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 30],
    ['slug' => 'engine_exhaust_smoke',  'name' => 'Excessive exhaust smoke', 'name_ar' => 'دخان كثيف من الشكمان',   'category_key' => 'engine', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 40],
    ['slug' => 'engine_overheating',    'name' => 'Overheating', 'name_ar' => 'ارتفاع حرارة المحرك',               'category_key' => 'engine', 'default_severity' => 'critical', 'on_site' => false, 'sort_order' => 50],
    ['slug' => 'engine_stalling',       'name' => 'Stalling', 'name_ar' => 'انطفاء المحرك',                  'category_key' => 'engine', 'default_severity' => 'critical', 'on_site' => false, 'sort_order' => 60],
    ['slug' => 'engine_hard_starting',  'name' => 'Hard starting', 'name_ar' => 'صعوبة في التشغيل',             'category_key' => 'engine', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 70],
    ['slug' => 'engine_check_light',    'name' => 'Check-engine light', 'name_ar' => 'لمبة فحص المحرك',        'category_key' => 'engine', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 80],

    // ── Brakes ──────────────────────────────────────────────────────────────────────────────────
    ['slug' => 'brake_noise',           'name' => 'Brake noise (squeal / grind)', 'name_ar' => 'صوت في الفرامل (صرير / حكّ)', 'category_key' => 'brakes', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 100],
    ['slug' => 'brake_soft_pedal',      'name' => 'Soft / spongy pedal', 'name_ar' => 'دعسة فرامل ضعيفة / إسفنجية',       'category_key' => 'brakes', 'default_severity' => 'critical', 'on_site' => false, 'sort_order' => 110],
    ['slug' => 'brake_vibration',       'name' => 'Vibration when braking', 'name_ar' => 'رجّة عند الفرملة',    'category_key' => 'brakes', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 120],
    ['slug' => 'brake_pulling',         'name' => 'Pulling to one side', 'name_ar' => 'انحراف عند الفرملة',       'category_key' => 'brakes', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 130],
    ['slug' => 'brake_worn_pads',       'name' => 'Worn pads / discs', 'name_ar' => 'تآكل الفحمات / الديسكات',         'category_key' => 'brakes', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 140],
    ['slug' => 'brake_handbrake_fault', 'name' => 'Handbrake fault', 'name_ar' => 'خلل في فرامل اليد',           'category_key' => 'brakes', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 150],
    ['slug' => 'brake_abs_light',       'name' => 'ABS warning light', 'name_ar' => 'لمبة تحذير ABS',         'category_key' => 'brakes', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 160],

    // ── Tyres & Wheels (faults only — rotation/alignment are services) ───────────────────────────
    ['slug' => 'tyre_worn',             'name' => 'Worn / bald tyre', 'name_ar' => 'إطار مستهلك / أصلع',          'category_key' => 'tyres', 'default_severity' => 'moderate', 'on_site' => true,  'sort_order' => 200],
    ['slug' => 'tyre_puncture',         'name' => 'Puncture / slow leak', 'name_ar' => 'بنشر / تسريب هواء بطيء',      'category_key' => 'tyres', 'default_severity' => 'moderate', 'on_site' => true,  'sort_order' => 210],
    ['slug' => 'tyre_uneven_wear',      'name' => 'Uneven tyre wear', 'name_ar' => 'تآكل غير متساوٍ في الإطار',          'category_key' => 'tyres', 'default_severity' => 'routine',  'on_site' => true,  'sort_order' => 220],
    ['slug' => 'tyre_tpms_warning',     'name' => 'TPMS / tyre-pressure warning', 'name_ar' => 'تحذير ضغط الإطارات (TPMS)', 'category_key' => 'tyres', 'default_severity' => 'routine', 'on_site' => true, 'sort_order' => 230],

    // ── Suspension & Steering ────────────────────────────────────────────────────────────────────
    ['slug' => 'susp_knocking',         'name' => 'Knocking over bumps', 'name_ar' => 'أصوات طقطقة على المطبّات',       'category_key' => 'suspension', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 300],
    ['slug' => 'susp_steering_vibration', 'name' => 'Steering vibration', 'name_ar' => 'رجّة في الستيرنج',      'category_key' => 'suspension', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 310],
    ['slug' => 'susp_hard_steering',    'name' => 'Hard / heavy steering', 'name_ar' => 'ثقل في الستيرنج',     'category_key' => 'suspension', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 320],
    ['slug' => 'susp_drifting',         'name' => 'Pulling / drifting', 'name_ar' => 'سحب / انحراف السيارة',        'category_key' => 'suspension', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 330],
    ['slug' => 'susp_worn_shock',       'name' => 'Worn shock / strut', 'name_ar' => 'مساعد تالف',        'category_key' => 'suspension', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 340],
    ['slug' => 'susp_wheel_bearing',    'name' => 'Wheel-bearing noise', 'name_ar' => 'صوت رمان بلي',       'category_key' => 'suspension', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 350],

    // ── Transmission & Drivetrain ────────────────────────────────────────────────────────────────
    ['slug' => 'trans_gear_slipping',   'name' => 'Gear slipping', 'name_ar' => 'تزحلق الجير',             'category_key' => 'transmission', 'default_severity' => 'critical', 'on_site' => false, 'sort_order' => 400],
    ['slug' => 'trans_hard_shifting',   'name' => 'Hard / jerky shifting', 'name_ar' => 'تنقيل قاسي / خبطات في الجير',     'category_key' => 'transmission', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 410],
    ['slug' => 'trans_clutch_issue',    'name' => 'Clutch issue', 'name_ar' => 'مشكلة في الكلتش',              'category_key' => 'transmission', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 420],
    ['slug' => 'trans_whining_noise',   'name' => 'Whining / grinding noise', 'name_ar' => 'صوت أنين / حكّ في الجير',  'category_key' => 'transmission', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 430],
    ['slug' => 'trans_delayed_engage',  'name' => 'Delayed engagement', 'name_ar' => 'تأخر استجابة الجير',        'category_key' => 'transmission', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 440],

    // ── Electrical ───────────────────────────────────────────────────────────────────────────────
    ['slug' => 'elec_battery_failure',  'name' => "Battery / won't start", 'name_ar' => 'البطارية / لا تشتغل',     'category_key' => 'electrical', 'default_severity' => 'critical', 'on_site' => false, 'sort_order' => 500],
    ['slug' => 'elec_alternator',       'name' => 'Alternator / charging fault', 'name_ar' => 'خلل في الدينامو / الشحن', 'category_key' => 'electrical', 'default_severity' => 'critical', 'on_site' => false, 'sort_order' => 510],
    ['slug' => 'elec_dash_warning',     'name' => 'Warning light on dash', 'name_ar' => 'لمبة تحذير في التابلوه',     'category_key' => 'electrical', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 520],
    ['slug' => 'elec_window_lock',      'name' => 'Power window / lock fault', 'name_ar' => 'خلل في زجاج / قفل الأبواب الكهربائي',  'category_key' => 'electrical', 'default_severity' => 'routine',  'on_site' => false, 'sort_order' => 530],
    ['slug' => 'elec_central_locking',  'name' => 'Central locking / key fob', 'name_ar' => 'القفل المركزي / الريموت',  'category_key' => 'electrical', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 540],
    ['slug' => 'elec_wiring_fuse',      'name' => 'Wiring / fuse issue', 'name_ar' => 'مشكلة في الأسلاك / الفيوز',       'category_key' => 'electrical', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 550],

    // ── Climate / A/C ────────────────────────────────────────────────────────────────────────────
    ['slug' => 'ac_not_cooling',        'name' => 'A/C not cooling', 'name_ar' => 'التكييف لا يبرّد',           'category_key' => 'ac', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 600],
    ['slug' => 'ac_heater_fault',       'name' => 'Heater not working', 'name_ar' => 'المدفأة لا تعمل',        'category_key' => 'ac', 'default_severity' => 'routine',  'on_site' => false, 'sort_order' => 610],
    ['slug' => 'ac_weak_airflow',       'name' => 'Weak airflow', 'name_ar' => 'ضعف تدفق الهواء',              'category_key' => 'ac', 'default_severity' => 'routine',  'on_site' => false, 'sort_order' => 620],
    ['slug' => 'ac_bad_smell',          'name' => 'Bad smell from vents', 'name_ar' => 'ريحة كريهة من فتحات التكييف',      'category_key' => 'ac', 'default_severity' => 'routine',  'on_site' => false, 'sort_order' => 630],
    ['slug' => 'ac_noisy_blower',       'name' => 'Noisy blower', 'name_ar' => 'صوت في مروحة التكييف',              'category_key' => 'ac', 'default_severity' => 'routine',  'on_site' => false, 'sort_order' => 640],

    // ── Bodywork & Exterior ──────────────────────────────────────────────────────────────────────
    ['slug' => 'body_dent',             'name' => 'Dent', 'name_ar' => 'انبعاج / دعبوس',                      'category_key' => 'bodywork', 'default_severity' => 'routine', 'on_site' => false, 'sort_order' => 700],
    ['slug' => 'body_scratch',          'name' => 'Scratch', 'name_ar' => 'خربشة',                   'category_key' => 'bodywork', 'default_severity' => 'routine', 'on_site' => false, 'sort_order' => 710],
    ['slug' => 'body_paint_damage',     'name' => 'Paint damage', 'name_ar' => 'تلف في الدهان',              'category_key' => 'bodywork', 'default_severity' => 'routine', 'on_site' => false, 'sort_order' => 720],
    ['slug' => 'body_rust',             'name' => 'Rust / corrosion', 'name_ar' => 'صدأ / تآكل',          'category_key' => 'bodywork', 'default_severity' => 'routine', 'on_site' => false, 'sort_order' => 730],
    ['slug' => 'body_mirror',           'name' => 'Broken / loose mirror', 'name_ar' => 'مراية مكسورة / مرتخية',     'category_key' => 'bodywork', 'default_severity' => 'routine', 'on_site' => true,  'sort_order' => 740],
    ['slug' => 'body_windscreen',       'name' => 'Windscreen crack / chip', 'name_ar' => 'تشقق / كسر في الزجاج الأمامي',   'category_key' => 'bodywork', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 750],
    ['slug' => 'body_panel_misalign',   'name' => 'Door / panel misalignment', 'name_ar' => 'عدم استقامة الباب / الصاج', 'category_key' => 'bodywork', 'default_severity' => 'routine', 'on_site' => false, 'sort_order' => 760],

    // ── Interior ─────────────────────────────────────────────────────────────────────────────────
    ['slug' => 'int_upholstery',        'name' => 'Seat / upholstery damage', 'name_ar' => 'تلف في الكرسي / التنجيد',  'category_key' => 'interior', 'default_severity' => 'routine', 'on_site' => true, 'sort_order' => 800],
    ['slug' => 'int_dashboard_fault',   'name' => 'Dashboard fault', 'name_ar' => 'خلل في التابلوه',           'category_key' => 'interior', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 810],
    ['slug' => 'int_infotainment',      'name' => 'Infotainment / screen issue', 'name_ar' => 'مشكلة في الشاشة / نظام الترفيه', 'category_key' => 'interior', 'default_severity' => 'routine', 'on_site' => false, 'sort_order' => 820],
    ['slug' => 'int_broken_trim',       'name' => 'Broken trim', 'name_ar' => 'تلف في الأطقم الداخلية',               'category_key' => 'interior', 'default_severity' => 'routine', 'on_site' => true, 'sort_order' => 830],
    ['slug' => 'int_bad_odour',         'name' => 'Bad odour', 'name_ar' => 'ريحة كريهة داخل السيارة',                 'category_key' => 'interior', 'default_severity' => 'routine', 'on_site' => true, 'sort_order' => 840],

    // ── Fluids & Leaks (leaks are faults; top-ups are services) ──────────────────────────────────
    ['slug' => 'fluid_oil_leak',        'name' => 'Oil leak', 'name_ar' => 'تسريب زيت',                  'category_key' => 'fluids', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 900],
    ['slug' => 'fluid_coolant_leak',    'name' => 'Coolant leak', 'name_ar' => 'تسريب ماء الردياتير',              'category_key' => 'fluids', 'default_severity' => 'critical', 'on_site' => false, 'sort_order' => 910],
    ['slug' => 'fluid_brake_leak',      'name' => 'Brake-fluid leak', 'name_ar' => 'تسريب زيت فرامل',          'category_key' => 'fluids', 'default_severity' => 'critical', 'on_site' => false, 'sort_order' => 920],
    ['slug' => 'fluid_ps_leak',         'name' => 'Power-steering leak', 'name_ar' => 'تسريب زيت الستيرنج',       'category_key' => 'fluids', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 930],
    ['slug' => 'fluid_fuel_leak',       'name' => 'Fuel smell / leak', 'name_ar' => 'ريحة / تسريب بنزين',         'category_key' => 'fluids', 'default_severity' => 'critical', 'on_site' => false, 'sort_order' => 940],
    ['slug' => 'fluid_low_level',       'name' => 'Low fluid level', 'name_ar' => 'نقص في مستوى السوائل',           'category_key' => 'fluids', 'default_severity' => 'routine',  'on_site' => true,  'sort_order' => 950],

    // ── Lights & Visibility ──────────────────────────────────────────────────────────────────────
    ['slug' => 'light_headlight_out',   'name' => 'Headlight out', 'name_ar' => 'كشاف أمامي لا يعمل',             'category_key' => 'lights', 'default_severity' => 'routine', 'on_site' => true, 'sort_order' => 1000],
    ['slug' => 'light_taillight_out',   'name' => 'Tail / brake light out', 'name_ar' => 'لمبة خلفية / فرامل لا تعمل',    'category_key' => 'lights', 'default_severity' => 'moderate', 'on_site' => true, 'sort_order' => 1010],
    ['slug' => 'light_indicator',       'name' => 'Indicator fault', 'name_ar' => 'خلل في الإشارة (الغماز)',           'category_key' => 'lights', 'default_severity' => 'routine', 'on_site' => true, 'sort_order' => 1020],
    ['slug' => 'light_wiper_washer',    'name' => 'Wiper / washer fault', 'name_ar' => 'خلل في المساحات / الرشاش',      'category_key' => 'lights', 'default_severity' => 'routine', 'on_site' => true, 'sort_order' => 1030],
    ['slug' => 'light_dim',             'name' => 'Foggy / dim lights', 'name_ar' => 'إضاءة ضعيفة / معتمة',        'category_key' => 'lights', 'default_severity' => 'routine', 'on_site' => true, 'sort_order' => 1040],

];
