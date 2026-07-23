<?php

/**
 * Asset Layer — the seed definitions for component_catalog (the dictionary of component TYPES).
 *
 * ComponentCatalogSeeder upserts these by `slug` (idempotent, additive-only: it never deletes or
 * renames an existing entry — retire one by setting is_active=false in the DB, not by removing
 * it here). `category_key` uses the SAME vocabulary as config/maintenance_findings.php so a
 * fault's category joins straight to the component types that could be responsible.
 *
 * tracking_mode:
 *   serialized — individual identity (serial required at creation), qty always 1.
 *   batch      — quantity/position tracked, serial optional (tyres: DOT code when known).
 *   consumable — NEVER instantiates a vehicle_components row; the work is a service_records
 *                entry only (materials_cost carries the fluid/part cost). Standing rule.
 *
 * position_scheme: 'axle_corner' (FL/FR/RL/RR) | 'axle' (front/rear) | null (positionless).
 */

return [

    // ── Serialized — individual identity, individual history ────────────────────────────────────
    ['slug' => 'engine',        'name' => 'Engine',           'category_key' => 'engine',       'tracking_mode' => 'serialized', 'expected_life_km' => 300000, 'position_scheme' => null],
    ['slug' => 'gearbox',       'name' => 'Gearbox',          'category_key' => 'transmission', 'tracking_mode' => 'serialized', 'expected_life_km' => 250000, 'position_scheme' => null],
    ['slug' => 'battery-12v',   'name' => 'Battery 12V',      'category_key' => 'electrical',   'tracking_mode' => 'serialized', 'default_warranty_months' => 12, 'expected_life_months' => 30, 'position_scheme' => null],
    ['slug' => 'ac-compressor', 'name' => 'AC Compressor',    'category_key' => 'ac',           'tracking_mode' => 'serialized', 'default_warranty_months' => 12, 'position_scheme' => null],
    ['slug' => 'alternator',    'name' => 'Alternator',       'category_key' => 'electrical',   'tracking_mode' => 'serialized', 'default_warranty_months' => 12, 'position_scheme' => null],
    ['slug' => 'starter-motor', 'name' => 'Starter Motor',    'category_key' => 'electrical',   'tracking_mode' => 'serialized', 'default_warranty_months' => 12, 'position_scheme' => null],
    ['slug' => 'radiator',      'name' => 'Radiator',         'category_key' => 'engine',       'tracking_mode' => 'serialized', 'default_warranty_months' => 6,  'position_scheme' => null],
    ['slug' => 'ecu',           'name' => 'ECU / Control Unit', 'category_key' => 'electrical', 'tracking_mode' => 'serialized', 'position_scheme' => null],
    ['slug' => 'gps-tracker',   'name' => 'GPS Tracker',      'category_key' => 'electrical',   'tracking_mode' => 'serialized', 'position_scheme' => null],

    // ── Batch — quantity/position tracked ───────────────────────────────────────────────────────
    ['slug' => 'tyre',          'name' => 'Tyre',             'category_key' => 'tyres',        'tracking_mode' => 'batch', 'expected_life_km' => 50000, 'position_scheme' => 'axle_corner',
        'notes' => 'One row per tyre (qty=1); DOT code in serial_no when known.'],
    ['slug' => 'brake-pads',    'name' => 'Brake Pads (set)', 'category_key' => 'brakes',       'tracking_mode' => 'batch', 'expected_life_km' => 40000, 'position_scheme' => 'axle',
        'notes' => 'CONVENTION: one set per axle, qty=1 (never per-pad).'],
    ['slug' => 'brake-discs',   'name' => 'Brake Discs (set)', 'category_key' => 'brakes',      'tracking_mode' => 'batch', 'expected_life_km' => 80000, 'position_scheme' => 'axle',
        'notes' => 'CONVENTION: one set per axle, qty=1.'],
    ['slug' => 'shock-absorber', 'name' => 'Shock Absorber',  'category_key' => 'suspension',   'tracking_mode' => 'batch', 'expected_life_km' => 80000, 'position_scheme' => 'axle_corner'],
    ['slug' => 'air-filter',    'name' => 'Air Filter',       'category_key' => 'engine',       'tracking_mode' => 'batch', 'expected_life_km' => 20000, 'position_scheme' => null],

    // ── Consumables — NEVER become components; service_records only ─────────────────────────────
    ['slug' => 'engine-oil',    'name' => 'Engine Oil',       'category_key' => 'fluids',       'tracking_mode' => 'consumable'],
    ['slug' => 'oil-filter',    'name' => 'Oil Filter',       'category_key' => 'fluids',       'tracking_mode' => 'consumable',
        'notes' => 'Changed with the oil — recorded on the oil-change service record.'],
    ['slug' => 'coolant',       'name' => 'Coolant',          'category_key' => 'fluids',       'tracking_mode' => 'consumable'],
    ['slug' => 'brake-fluid',   'name' => 'Brake Fluid',      'category_key' => 'fluids',       'tracking_mode' => 'consumable'],
    ['slug' => 'wiper-blades',  'name' => 'Wiper Blades',     'category_key' => 'electrical',   'tracking_mode' => 'consumable'],
    ['slug' => 'bulbs',         'name' => 'Bulbs / Lights',   'category_key' => 'electrical',   'tracking_mode' => 'consumable'],
    ['slug' => 'adblue',        'name' => 'AdBlue',           'category_key' => 'fluids',       'tracking_mode' => 'consumable'],

];
