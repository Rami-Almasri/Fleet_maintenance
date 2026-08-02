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
    ['slug' => 'battery-12v',   'name' => 'Battery 12V',      'category_key' => 'electrical', 'action_target' => 'battery',   'tracking_mode' => 'serialized', 'default_warranty_months' => 12, 'expected_life_months' => 30, 'position_scheme' => null],
    ['slug' => 'ac-compressor', 'name' => 'AC Compressor',    'category_key' => 'ac', 'action_target' => 'ac_compressor',           'tracking_mode' => 'serialized', 'default_warranty_months' => 12, 'position_scheme' => null],
    ['slug' => 'alternator',    'name' => 'Alternator',       'category_key' => 'electrical', 'action_target' => 'alternator',   'tracking_mode' => 'serialized', 'default_warranty_months' => 12, 'position_scheme' => null],
    ['slug' => 'starter-motor', 'name' => 'Starter Motor',    'category_key' => 'electrical', 'action_target' => 'starter_motor',   'tracking_mode' => 'serialized', 'default_warranty_months' => 12, 'position_scheme' => null],
    ['slug' => 'radiator',      'name' => 'Radiator',         'category_key' => 'engine', 'action_target' => 'radiator',       'tracking_mode' => 'serialized', 'default_warranty_months' => 6,  'position_scheme' => null],
    ['slug' => 'ecu',           'name' => 'ECU / Control Unit', 'category_key' => 'electrical', 'tracking_mode' => 'serialized', 'position_scheme' => null],
    ['slug' => 'gps-tracker',   'name' => 'GPS Tracker',      'category_key' => 'electrical',   'tracking_mode' => 'serialized', 'position_scheme' => null],
    ['slug' => 'water-pump',    'name' => 'Water Pump',       'category_key' => 'engine', 'action_target' => 'water_pump',       'tracking_mode' => 'serialized', 'default_warranty_months' => 12, 'expected_life_km' => 120000, 'position_scheme' => null],
    ['slug' => 'fuel-pump',     'name' => 'Fuel Pump',        'category_key' => 'engine',       'tracking_mode' => 'serialized', 'default_warranty_months' => 12, 'expected_life_km' => 150000, 'position_scheme' => null],
    ['slug' => 'turbocharger',  'name' => 'Turbocharger',     'category_key' => 'engine',       'tracking_mode' => 'serialized', 'default_warranty_months' => 12, 'expected_life_km' => 200000, 'position_scheme' => null],
    ['slug' => 'ac-condenser',  'name' => 'AC Condenser',     'category_key' => 'ac', 'action_target' => 'ac_condenser',           'tracking_mode' => 'serialized', 'default_warranty_months' => 12, 'expected_life_km' => 150000, 'position_scheme' => null],
    ['slug' => 'radiator-fan',  'name' => 'Radiator Fan',     'category_key' => 'engine', 'action_target' => 'cooling_fan',       'tracking_mode' => 'serialized', 'default_warranty_months' => 12, 'expected_life_km' => 150000, 'position_scheme' => null],
    ['slug' => 'steering-rack', 'name' => 'Steering Rack',    'category_key' => 'suspension',   'tracking_mode' => 'serialized', 'default_warranty_months' => 12, 'expected_life_km' => 180000, 'position_scheme' => null],
    ['slug' => 'clutch-kit',    'name' => 'Clutch Kit',       'category_key' => 'transmission', 'action_target' => 'clutch', 'tracking_mode' => 'serialized', 'default_warranty_months' => 12, 'expected_life_km' => 120000, 'position_scheme' => null],
    ['slug' => 'catalytic-converter', 'name' => 'Catalytic Converter', 'category_key' => 'engine', 'tracking_mode' => 'serialized', 'default_warranty_months' => 12, 'expected_life_km' => 200000, 'position_scheme' => null],

    // ── Batch — quantity/position tracked ───────────────────────────────────────────────────────
    ['slug' => 'tyre',          'name' => 'Tyre',             'category_key' => 'tyres', 'action_target' => 'tyre',        'tracking_mode' => 'batch', 'expected_life_km' => 50000, 'position_scheme' => 'axle_corner',
        'notes' => 'One row per tyre (qty=1); DOT code in serial_no when known.'],
    ['slug' => 'brake-pads',    'name' => 'Brake Pads (set)', 'category_key' => 'brakes', 'action_target' => 'brake_pads',       'tracking_mode' => 'batch', 'expected_life_km' => 40000, 'position_scheme' => 'axle',
        'notes' => 'CONVENTION: one set per axle, qty=1 (never per-pad).'],
    ['slug' => 'brake-discs',   'name' => 'Brake Discs (set)', 'category_key' => 'brakes', 'action_target' => 'brake_discs',      'tracking_mode' => 'batch', 'expected_life_km' => 80000, 'position_scheme' => 'axle',
        'notes' => 'CONVENTION: one set per axle, qty=1.'],
    ['slug' => 'shock-absorber', 'name' => 'Shock Absorber',  'category_key' => 'suspension', 'action_target' => 'shock_absorber',   'tracking_mode' => 'batch', 'expected_life_km' => 80000, 'position_scheme' => 'axle_corner'],
    ['slug' => 'air-filter',    'name' => 'Air Filter',       'category_key' => 'engine', 'action_target' => 'air_filter',       'tracking_mode' => 'batch', 'expected_life_km' => 20000, 'position_scheme' => null],
    ['slug' => 'cabin-filter',  'name' => 'Cabin Filter',     'category_key' => 'ac', 'action_target' => 'cabin_filter',           'tracking_mode' => 'batch', 'expected_life_km' => 20000, 'expected_life_months' => 12, 'position_scheme' => null],
    ['slug' => 'fuel-filter',   'name' => 'Fuel Filter',      'category_key' => 'engine', 'action_target' => 'fuel_filter',       'tracking_mode' => 'batch', 'expected_life_km' => 40000, 'position_scheme' => null],
    ['slug' => 'spark-plugs',   'name' => 'Spark Plugs (set)', 'category_key' => 'engine', 'action_target' => 'spark_plugs',      'tracking_mode' => 'batch', 'expected_life_km' => 60000, 'position_scheme' => null,
        'notes' => 'CONVENTION: one set per engine, qty=1 (never per-plug).'],
    ['slug' => 'ignition-coil', 'name' => 'Ignition Coil',    'category_key' => 'electrical', 'action_target' => 'ignition_coil',   'tracking_mode' => 'batch', 'expected_life_km' => 100000, 'position_scheme' => null],
    ['slug' => 'timing-belt',   'name' => 'Timing Belt',      'category_key' => 'engine',       'tracking_mode' => 'batch', 'expected_life_km' => 100000, 'position_scheme' => null,
        'notes' => 'Belt engines only — a chain engine uses the timing-chain entry.'],
    ['slug' => 'timing-chain',  'name' => 'Timing Chain',     'category_key' => 'engine', 'action_target' => 'timing_chain',       'tracking_mode' => 'batch', 'expected_life_km' => 200000, 'position_scheme' => null],
    ['slug' => 'drive-belt',    'name' => 'Drive / Serpentine Belt', 'category_key' => 'engine', 'action_target' => 'accessory_belt', 'tracking_mode' => 'batch', 'expected_life_km' => 60000, 'position_scheme' => null],
    ['slug' => 'thermostat',    'name' => 'Thermostat',       'category_key' => 'engine', 'action_target' => 'thermostat',       'tracking_mode' => 'batch', 'expected_life_km' => 120000, 'position_scheme' => null],
    ['slug' => 'oxygen-sensor', 'name' => 'Oxygen Sensor',    'category_key' => 'engine', 'action_target' => 'oxygen_sensor',       'tracking_mode' => 'batch', 'expected_life_km' => 100000, 'position_scheme' => null],
    ['slug' => 'engine-mount',  'name' => 'Engine Mount',     'category_key' => 'engine', 'action_target' => 'engine_mount',       'tracking_mode' => 'batch', 'expected_life_km' => 120000, 'position_scheme' => null],
    ['slug' => 'brake-caliper', 'name' => 'Brake Caliper',    'category_key' => 'brakes', 'action_target' => 'brake_caliper',       'tracking_mode' => 'batch', 'expected_life_km' => 150000, 'position_scheme' => 'axle_corner'],
    ['slug' => 'control-arm',   'name' => 'Control Arm',      'category_key' => 'suspension', 'action_target' => 'control_arm',   'tracking_mode' => 'batch', 'expected_life_km' => 100000, 'position_scheme' => 'axle_corner'],
    ['slug' => 'wheel-bearing', 'name' => 'Wheel Bearing',    'category_key' => 'suspension', 'action_target' => 'wheel_bearing',   'tracking_mode' => 'batch', 'expected_life_km' => 120000, 'position_scheme' => 'axle_corner'],
    ['slug' => 'suspension-spring', 'name' => 'Suspension Spring', 'category_key' => 'suspension', 'tracking_mode' => 'batch', 'expected_life_km' => 150000, 'position_scheme' => 'axle_corner'],
    ['slug' => 'stabilizer-link', 'name' => 'Stabilizer Link', 'category_key' => 'suspension', 'action_target' => 'link_rod',  'tracking_mode' => 'batch', 'expected_life_km' => 60000, 'position_scheme' => 'axle_corner'],
    ['slug' => 'tie-rod-end',   'name' => 'Tie Rod End',      'category_key' => 'suspension', 'action_target' => 'tie_rod',   'tracking_mode' => 'batch', 'expected_life_km' => 100000, 'position_scheme' => 'axle_corner'],

    // ── Consumables — NEVER become components; service_records only ─────────────────────────────
    // Standing rule (VehicleComponent::booted + ComponentService::guardNotConsumable): a fluid or a
    // fit-and-forget item is WORK PERFORMED, not an asset with a serial and a resale value. They are
    // still part of the vehicle's configuration in the user's eyes ("when was the oil last changed?"),
    // so ComponentReadModel MERGES the latest service_record per consumable type into the Installed
    // Components read — one table, two write paths, one invariant preserved.
    ['slug' => 'engine-oil',    'name' => 'Engine Oil',       'category_key' => 'fluids', 'action_target' => 'engine_oil',       'tracking_mode' => 'consumable'],
    ['slug' => 'oil-filter',    'name' => 'Oil Filter',       'category_key' => 'fluids', 'action_target' => 'oil_filter',       'tracking_mode' => 'consumable',
        'notes' => 'Changed with the oil — recorded on the oil-change service record.'],
    ['slug' => 'coolant',       'name' => 'Coolant',          'category_key' => 'fluids', 'action_target' => 'coolant',       'tracking_mode' => 'consumable'],
    ['slug' => 'brake-fluid',   'name' => 'Brake Fluid',      'category_key' => 'fluids', 'action_target' => 'brake_fluid',       'tracking_mode' => 'consumable'],
    ['slug' => 'wiper-blades',  'name' => 'Wiper Blades',     'category_key' => 'electrical', 'action_target' => 'wiper_blades',   'tracking_mode' => 'consumable'],
    ['slug' => 'bulbs',         'name' => 'Bulbs / Lights',   'category_key' => 'electrical',   'tracking_mode' => 'consumable'],
    ['slug' => 'adblue',        'name' => 'AdBlue',           'category_key' => 'fluids',       'tracking_mode' => 'consumable'],

];
