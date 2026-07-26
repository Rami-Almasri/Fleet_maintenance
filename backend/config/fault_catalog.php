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
 * Shape per row: { slug, name, category_key, default_severity?, on_site, sort_order }
 * default_severity is a PREFILL hint only (Maintenance::FAULT_SEVERITIES) — descriptive, editable.
 */

return [

    // ── Engine ──────────────────────────────────────────────────────────────────────────────────
    ['slug' => 'engine_noise',          'name' => 'Engine noise',              'category_key' => 'engine', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 10],
    ['slug' => 'engine_misfire',        'name' => 'Rough idle / misfire',      'category_key' => 'engine', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 20],
    ['slug' => 'engine_loss_of_power',  'name' => 'Loss of power',             'category_key' => 'engine', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 30],
    ['slug' => 'engine_exhaust_smoke',  'name' => 'Excessive exhaust smoke',   'category_key' => 'engine', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 40],
    ['slug' => 'engine_overheating',    'name' => 'Overheating',               'category_key' => 'engine', 'default_severity' => 'critical', 'on_site' => false, 'sort_order' => 50],
    ['slug' => 'engine_stalling',       'name' => 'Stalling',                  'category_key' => 'engine', 'default_severity' => 'critical', 'on_site' => false, 'sort_order' => 60],
    ['slug' => 'engine_hard_starting',  'name' => 'Hard starting',             'category_key' => 'engine', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 70],
    ['slug' => 'engine_check_light',    'name' => 'Check-engine light',        'category_key' => 'engine', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 80],

    // ── Brakes ──────────────────────────────────────────────────────────────────────────────────
    ['slug' => 'brake_noise',           'name' => 'Brake noise (squeal / grind)', 'category_key' => 'brakes', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 100],
    ['slug' => 'brake_soft_pedal',      'name' => 'Soft / spongy pedal',       'category_key' => 'brakes', 'default_severity' => 'critical', 'on_site' => false, 'sort_order' => 110],
    ['slug' => 'brake_vibration',       'name' => 'Vibration when braking',    'category_key' => 'brakes', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 120],
    ['slug' => 'brake_pulling',         'name' => 'Pulling to one side',       'category_key' => 'brakes', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 130],
    ['slug' => 'brake_worn_pads',       'name' => 'Worn pads / discs',         'category_key' => 'brakes', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 140],
    ['slug' => 'brake_handbrake_fault', 'name' => 'Handbrake fault',           'category_key' => 'brakes', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 150],
    ['slug' => 'brake_abs_light',       'name' => 'ABS warning light',         'category_key' => 'brakes', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 160],

    // ── Tyres & Wheels (faults only — rotation/alignment are services) ───────────────────────────
    ['slug' => 'tyre_worn',             'name' => 'Worn / bald tyre',          'category_key' => 'tyres', 'default_severity' => 'moderate', 'on_site' => true,  'sort_order' => 200],
    ['slug' => 'tyre_puncture',         'name' => 'Puncture / slow leak',      'category_key' => 'tyres', 'default_severity' => 'moderate', 'on_site' => true,  'sort_order' => 210],
    ['slug' => 'tyre_uneven_wear',      'name' => 'Uneven tyre wear',          'category_key' => 'tyres', 'default_severity' => 'routine',  'on_site' => true,  'sort_order' => 220],
    ['slug' => 'tyre_tpms_warning',     'name' => 'TPMS / tyre-pressure warning', 'category_key' => 'tyres', 'default_severity' => 'routine', 'on_site' => true, 'sort_order' => 230],

    // ── Suspension & Steering ────────────────────────────────────────────────────────────────────
    ['slug' => 'susp_knocking',         'name' => 'Knocking over bumps',       'category_key' => 'suspension', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 300],
    ['slug' => 'susp_steering_vibration', 'name' => 'Steering vibration',      'category_key' => 'suspension', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 310],
    ['slug' => 'susp_hard_steering',    'name' => 'Hard / heavy steering',     'category_key' => 'suspension', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 320],
    ['slug' => 'susp_drifting',         'name' => 'Pulling / drifting',        'category_key' => 'suspension', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 330],
    ['slug' => 'susp_worn_shock',       'name' => 'Worn shock / strut',        'category_key' => 'suspension', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 340],
    ['slug' => 'susp_wheel_bearing',    'name' => 'Wheel-bearing noise',       'category_key' => 'suspension', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 350],

    // ── Transmission & Drivetrain ────────────────────────────────────────────────────────────────
    ['slug' => 'trans_gear_slipping',   'name' => 'Gear slipping',             'category_key' => 'transmission', 'default_severity' => 'critical', 'on_site' => false, 'sort_order' => 400],
    ['slug' => 'trans_hard_shifting',   'name' => 'Hard / jerky shifting',     'category_key' => 'transmission', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 410],
    ['slug' => 'trans_clutch_issue',    'name' => 'Clutch issue',              'category_key' => 'transmission', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 420],
    ['slug' => 'trans_whining_noise',   'name' => 'Whining / grinding noise',  'category_key' => 'transmission', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 430],
    ['slug' => 'trans_delayed_engage',  'name' => 'Delayed engagement',        'category_key' => 'transmission', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 440],

    // ── Electrical ───────────────────────────────────────────────────────────────────────────────
    ['slug' => 'elec_battery_failure',  'name' => "Battery / won't start",     'category_key' => 'electrical', 'default_severity' => 'critical', 'on_site' => false, 'sort_order' => 500],
    ['slug' => 'elec_alternator',       'name' => 'Alternator / charging fault', 'category_key' => 'electrical', 'default_severity' => 'critical', 'on_site' => false, 'sort_order' => 510],
    ['slug' => 'elec_dash_warning',     'name' => 'Warning light on dash',     'category_key' => 'electrical', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 520],
    ['slug' => 'elec_window_lock',      'name' => 'Power window / lock fault',  'category_key' => 'electrical', 'default_severity' => 'routine',  'on_site' => false, 'sort_order' => 530],
    ['slug' => 'elec_central_locking',  'name' => 'Central locking / key fob',  'category_key' => 'electrical', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 540],
    ['slug' => 'elec_wiring_fuse',      'name' => 'Wiring / fuse issue',       'category_key' => 'electrical', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 550],

    // ── Climate / A/C ────────────────────────────────────────────────────────────────────────────
    ['slug' => 'ac_not_cooling',        'name' => 'A/C not cooling',           'category_key' => 'ac', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 600],
    ['slug' => 'ac_heater_fault',       'name' => 'Heater not working',        'category_key' => 'ac', 'default_severity' => 'routine',  'on_site' => false, 'sort_order' => 610],
    ['slug' => 'ac_weak_airflow',       'name' => 'Weak airflow',              'category_key' => 'ac', 'default_severity' => 'routine',  'on_site' => false, 'sort_order' => 620],
    ['slug' => 'ac_bad_smell',          'name' => 'Bad smell from vents',      'category_key' => 'ac', 'default_severity' => 'routine',  'on_site' => false, 'sort_order' => 630],
    ['slug' => 'ac_noisy_blower',       'name' => 'Noisy blower',              'category_key' => 'ac', 'default_severity' => 'routine',  'on_site' => false, 'sort_order' => 640],

    // ── Bodywork & Exterior ──────────────────────────────────────────────────────────────────────
    ['slug' => 'body_dent',             'name' => 'Dent',                      'category_key' => 'bodywork', 'default_severity' => 'routine', 'on_site' => false, 'sort_order' => 700],
    ['slug' => 'body_scratch',          'name' => 'Scratch',                   'category_key' => 'bodywork', 'default_severity' => 'routine', 'on_site' => false, 'sort_order' => 710],
    ['slug' => 'body_paint_damage',     'name' => 'Paint damage',              'category_key' => 'bodywork', 'default_severity' => 'routine', 'on_site' => false, 'sort_order' => 720],
    ['slug' => 'body_rust',             'name' => 'Rust / corrosion',          'category_key' => 'bodywork', 'default_severity' => 'routine', 'on_site' => false, 'sort_order' => 730],
    ['slug' => 'body_mirror',           'name' => 'Broken / loose mirror',     'category_key' => 'bodywork', 'default_severity' => 'routine', 'on_site' => true,  'sort_order' => 740],
    ['slug' => 'body_windscreen',       'name' => 'Windscreen crack / chip',   'category_key' => 'bodywork', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 750],
    ['slug' => 'body_panel_misalign',   'name' => 'Door / panel misalignment', 'category_key' => 'bodywork', 'default_severity' => 'routine', 'on_site' => false, 'sort_order' => 760],

    // ── Interior ─────────────────────────────────────────────────────────────────────────────────
    ['slug' => 'int_upholstery',        'name' => 'Seat / upholstery damage',  'category_key' => 'interior', 'default_severity' => 'routine', 'on_site' => true, 'sort_order' => 800],
    ['slug' => 'int_dashboard_fault',   'name' => 'Dashboard fault',           'category_key' => 'interior', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 810],
    ['slug' => 'int_infotainment',      'name' => 'Infotainment / screen issue', 'category_key' => 'interior', 'default_severity' => 'routine', 'on_site' => false, 'sort_order' => 820],
    ['slug' => 'int_broken_trim',       'name' => 'Broken trim',               'category_key' => 'interior', 'default_severity' => 'routine', 'on_site' => true, 'sort_order' => 830],
    ['slug' => 'int_bad_odour',         'name' => 'Bad odour',                 'category_key' => 'interior', 'default_severity' => 'routine', 'on_site' => true, 'sort_order' => 840],

    // ── Fluids & Leaks (leaks are faults; top-ups are services) ──────────────────────────────────
    ['slug' => 'fluid_oil_leak',        'name' => 'Oil leak',                  'category_key' => 'fluids', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 900],
    ['slug' => 'fluid_coolant_leak',    'name' => 'Coolant leak',              'category_key' => 'fluids', 'default_severity' => 'critical', 'on_site' => false, 'sort_order' => 910],
    ['slug' => 'fluid_brake_leak',      'name' => 'Brake-fluid leak',          'category_key' => 'fluids', 'default_severity' => 'critical', 'on_site' => false, 'sort_order' => 920],
    ['slug' => 'fluid_ps_leak',         'name' => 'Power-steering leak',       'category_key' => 'fluids', 'default_severity' => 'moderate', 'on_site' => false, 'sort_order' => 930],
    ['slug' => 'fluid_fuel_leak',       'name' => 'Fuel smell / leak',         'category_key' => 'fluids', 'default_severity' => 'critical', 'on_site' => false, 'sort_order' => 940],
    ['slug' => 'fluid_low_level',       'name' => 'Low fluid level',           'category_key' => 'fluids', 'default_severity' => 'routine',  'on_site' => true,  'sort_order' => 950],

    // ── Lights & Visibility ──────────────────────────────────────────────────────────────────────
    ['slug' => 'light_headlight_out',   'name' => 'Headlight out',             'category_key' => 'lights', 'default_severity' => 'routine', 'on_site' => true, 'sort_order' => 1000],
    ['slug' => 'light_taillight_out',   'name' => 'Tail / brake light out',    'category_key' => 'lights', 'default_severity' => 'moderate', 'on_site' => true, 'sort_order' => 1010],
    ['slug' => 'light_indicator',       'name' => 'Indicator fault',           'category_key' => 'lights', 'default_severity' => 'routine', 'on_site' => true, 'sort_order' => 1020],
    ['slug' => 'light_wiper_washer',    'name' => 'Wiper / washer fault',      'category_key' => 'lights', 'default_severity' => 'routine', 'on_site' => true, 'sort_order' => 1030],
    ['slug' => 'light_dim',             'name' => 'Foggy / dim lights',        'category_key' => 'lights', 'default_severity' => 'routine', 'on_site' => true, 'sort_order' => 1040],

];
