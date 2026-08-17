<?php

/**
 * Service Catalog — the canonical menu of PLANNED / PREVENTIVE work (kind = service).
 *
 * These are expected, recurring jobs (oil, filters, fluids, tyres, scheduled maintenance). Picking one
 * of these at intake stamps the maintenance_task with kind='service' + service_catalog_id, so it is
 * NEVER counted as a fault (no Top Faults, no recurrence, no health-score penalty) yet is still counted
 * in cost / history / profitability. See docs/Service-vs-Fault-Domain-Separation.md.
 *
 * Seeded by ServiceCatalogSeeder (idempotent upsert by `slug`). `slug` is the stable machine key —
 * never rename once data references it. This file is the single place the tyre/tire and
 * battery/battery_check slug drift is reconciled: `service_reminder_type` maps a catalog row back to a
 * key in ServiceReminder::TYPE_LABELS so completing the service still rolls its reminder forward.
 *
 * `name_ar` is REQUIRED on every row, not a nicety. The intake picker searches name AND name_ar, so a row
 * without it is a service an Arabic-speaking user cannot find by typing — and it renders in English on an
 * otherwise Arabic screen, because the label falls back. Adding a service means adding both names.
 *
 * Shape per row: { slug, name, name_ar, category_key, interval_km?, interval_months?, service_reminder_type?, sort_order }
 */

return [

    ['slug' => 'oil_change',       'name' => 'Oil Change', 'name_ar' => 'تغيير زيت',           'category_key' => 'fluids',       'interval_km' => 10000, 'interval_months' => 6,  'service_reminder_type' => 'oil_change',    'sort_order' => 10],
    ['slug' => 'oil_filter',       'name' => 'Oil Filter', 'name_ar' => 'فلتر زيت',           'category_key' => 'fluids',       'interval_km' => 10000, 'interval_months' => 6,  'service_reminder_type' => 'oil_filter',    'sort_order' => 20],
    ['slug' => 'air_filter',       'name' => 'Air Filter', 'name_ar' => 'فلتر هواء',           'category_key' => 'engine',       'interval_km' => 20000, 'interval_months' => 12, 'service_reminder_type' => 'air_filter',    'sort_order' => 30],
    ['slug' => 'cabin_filter',     'name' => 'Cabin Filter', 'name_ar' => 'فلتر مكيف',         'category_key' => 'ac',           'interval_km' => 20000, 'interval_months' => 12, 'service_reminder_type' => null,            'sort_order' => 40],
    ['slug' => 'fuel_filter',      'name' => 'Fuel Filter', 'name_ar' => 'فلتر بنزين',          'category_key' => 'engine',       'interval_km' => 40000, 'interval_months' => 24, 'service_reminder_type' => null,            'sort_order' => 50],
    ['slug' => 'spark_plugs',      'name' => 'Spark Plugs', 'name_ar' => 'بوجيهات',          'category_key' => 'engine',       'interval_km' => 40000, 'interval_months' => 24, 'service_reminder_type' => null,            'sort_order' => 60],
    ['slug' => 'brake_fluid',      'name' => 'Brake Fluid', 'name_ar' => 'زيت فرامل',          'category_key' => 'brakes',       'interval_km' => null,  'interval_months' => 24, 'service_reminder_type' => null,            'sort_order' => 70],
    ['slug' => 'brake_pads',       'name' => 'Brake Pads (service)', 'name_ar' => 'فحمات فرامل (صيانة)', 'category_key' => 'brakes',       'interval_km' => 40000, 'interval_months' => null, 'service_reminder_type' => 'brake_pads',  'sort_order' => 80],
    ['slug' => 'coolant',          'name' => 'Coolant', 'name_ar' => 'ماء ردياتير (كولنت)',              'category_key' => 'fluids',       'interval_km' => 60000, 'interval_months' => 48, 'service_reminder_type' => null,            'sort_order' => 90],
    ['slug' => 'transmission_oil', 'name' => 'Transmission Oil', 'name_ar' => 'زيت الجير',     'category_key' => 'transmission', 'interval_km' => 60000, 'interval_months' => 48, 'service_reminder_type' => 'transmission',  'sort_order' => 100],
    ['slug' => 'differential_oil', 'name' => 'Differential Oil', 'name_ar' => 'زيت الدفرنس',     'category_key' => 'transmission', 'interval_km' => 60000, 'interval_months' => 48, 'service_reminder_type' => null,            'sort_order' => 110],
    ['slug' => 'tyre_rotation',    'name' => 'Tyre Rotation', 'name_ar' => 'تدوير الإطارات',        'category_key' => 'tyres',        'interval_km' => 10000, 'interval_months' => null, 'service_reminder_type' => 'tire_rotation', 'sort_order' => 120],
    ['slug' => 'tyre_change',      'name' => 'Tyre Change', 'name_ar' => 'تغيير إطارات',          'category_key' => 'tyres',        'interval_km' => null,  'interval_months' => null, 'service_reminder_type' => 'tire_change', 'sort_order' => 130],
    ['slug' => 'wheel_alignment',  'name' => 'Wheel Alignment', 'name_ar' => 'ضبط زوايا العجلات',      'category_key' => 'tyres',        'interval_km' => 20000, 'interval_months' => null, 'service_reminder_type' => null,          'sort_order' => 140],
    ['slug' => 'wheel_balancing',  'name' => 'Wheel Balancing', 'name_ar' => 'موازنة العجلات (بلنس)',      'category_key' => 'tyres',        'interval_km' => 20000, 'interval_months' => null, 'service_reminder_type' => null,          'sort_order' => 150],
    ['slug' => 'battery_check',    'name' => 'Battery Check', 'name_ar' => 'فحص البطارية',        'category_key' => 'electrical',   'interval_km' => null,  'interval_months' => 6,  'service_reminder_type' => 'battery',       'sort_order' => 160],
    ['slug' => 'battery_replacement', 'name' => 'Battery Replacement', 'name_ar' => 'تغيير البطارية', 'category_key' => 'electrical', 'interval_km' => null,  'interval_months' => 36, 'service_reminder_type' => 'battery',       'sort_order' => 170],
    ['slug' => 'ac_service',       'name' => 'A/C Service', 'name_ar' => 'صيانة التكييف',          'category_key' => 'ac',           'interval_km' => null,  'interval_months' => 12, 'service_reminder_type' => 'ac_service',    'sort_order' => 180],
    ['slug' => 'general_service',  'name' => 'General Service', 'name_ar' => 'صيانة عامة',      'category_key' => 'routine',      'interval_km' => null,  'interval_months' => 12, 'service_reminder_type' => 'general',       'sort_order' => 190],

];
