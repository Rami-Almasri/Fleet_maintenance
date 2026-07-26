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
 * Shape per row: { slug, name, category_key, interval_km?, interval_months?, service_reminder_type?, sort_order }
 */

return [

    ['slug' => 'oil_change',       'name' => 'Oil Change',           'category_key' => 'fluids',       'interval_km' => 10000, 'interval_months' => 6,  'service_reminder_type' => 'oil_change',    'sort_order' => 10],
    ['slug' => 'oil_filter',       'name' => 'Oil Filter',           'category_key' => 'fluids',       'interval_km' => 10000, 'interval_months' => 6,  'service_reminder_type' => 'oil_filter',    'sort_order' => 20],
    ['slug' => 'air_filter',       'name' => 'Air Filter',           'category_key' => 'engine',       'interval_km' => 20000, 'interval_months' => 12, 'service_reminder_type' => 'air_filter',    'sort_order' => 30],
    ['slug' => 'cabin_filter',     'name' => 'Cabin Filter',         'category_key' => 'ac',           'interval_km' => 20000, 'interval_months' => 12, 'service_reminder_type' => null,            'sort_order' => 40],
    ['slug' => 'fuel_filter',      'name' => 'Fuel Filter',          'category_key' => 'engine',       'interval_km' => 40000, 'interval_months' => 24, 'service_reminder_type' => null,            'sort_order' => 50],
    ['slug' => 'spark_plugs',      'name' => 'Spark Plugs',          'category_key' => 'engine',       'interval_km' => 40000, 'interval_months' => 24, 'service_reminder_type' => null,            'sort_order' => 60],
    ['slug' => 'brake_fluid',      'name' => 'Brake Fluid',          'category_key' => 'brakes',       'interval_km' => null,  'interval_months' => 24, 'service_reminder_type' => null,            'sort_order' => 70],
    ['slug' => 'brake_pads',       'name' => 'Brake Pads (service)', 'category_key' => 'brakes',       'interval_km' => 40000, 'interval_months' => null, 'service_reminder_type' => 'brake_pads',  'sort_order' => 80],
    ['slug' => 'coolant',          'name' => 'Coolant',              'category_key' => 'fluids',       'interval_km' => 60000, 'interval_months' => 48, 'service_reminder_type' => null,            'sort_order' => 90],
    ['slug' => 'transmission_oil', 'name' => 'Transmission Oil',     'category_key' => 'transmission', 'interval_km' => 60000, 'interval_months' => 48, 'service_reminder_type' => 'transmission',  'sort_order' => 100],
    ['slug' => 'differential_oil', 'name' => 'Differential Oil',     'category_key' => 'transmission', 'interval_km' => 60000, 'interval_months' => 48, 'service_reminder_type' => null,            'sort_order' => 110],
    ['slug' => 'tyre_rotation',    'name' => 'Tyre Rotation',        'category_key' => 'tyres',        'interval_km' => 10000, 'interval_months' => null, 'service_reminder_type' => 'tire_rotation', 'sort_order' => 120],
    ['slug' => 'tyre_change',      'name' => 'Tyre Change',          'category_key' => 'tyres',        'interval_km' => null,  'interval_months' => null, 'service_reminder_type' => 'tire_change', 'sort_order' => 130],
    ['slug' => 'wheel_alignment',  'name' => 'Wheel Alignment',      'category_key' => 'tyres',        'interval_km' => 20000, 'interval_months' => null, 'service_reminder_type' => null,          'sort_order' => 140],
    ['slug' => 'wheel_balancing',  'name' => 'Wheel Balancing',      'category_key' => 'tyres',        'interval_km' => 20000, 'interval_months' => null, 'service_reminder_type' => null,          'sort_order' => 150],
    ['slug' => 'battery_check',    'name' => 'Battery Check',        'category_key' => 'electrical',   'interval_km' => null,  'interval_months' => 6,  'service_reminder_type' => 'battery',       'sort_order' => 160],
    ['slug' => 'battery_replacement', 'name' => 'Battery Replacement', 'category_key' => 'electrical', 'interval_km' => null,  'interval_months' => 36, 'service_reminder_type' => 'battery',       'sort_order' => 170],
    ['slug' => 'ac_service',       'name' => 'A/C Service',          'category_key' => 'ac',           'interval_km' => null,  'interval_months' => 12, 'service_reminder_type' => 'ac_service',    'sort_order' => 180],
    ['slug' => 'general_service',  'name' => 'General Service',      'category_key' => 'routine',      'interval_km' => null,  'interval_months' => 12, 'service_reminder_type' => 'general',       'sort_order' => 190],

];
