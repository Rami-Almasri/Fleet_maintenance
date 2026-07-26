<?php

/**
 * Inspection Types — the canonical menu of CHECKS (kind = inspection).
 *
 * An inspection is a check that may or may not discover a fault; it is never itself a fault or a
 * service. Picking one stamps the maintenance_task with kind='inspection' + inspection_type_id. If the
 * inspection FINDS something, a SEPARATE kind='fault' task is created (linked via derived_from_task_id);
 * the inspection row itself never mutates into a fault. See docs/Service-vs-Fault-Domain-Separation.md.
 *
 * Mirrors the intake kinds the workflow already runs (pre-rental readiness, post-repair QC, periodic /
 * dormancy diagnostic, damage walk-around). Seeded by InspectionTypeSeeder (idempotent by `slug`).
 *
 * Shape per row: { slug, name, checklist_key?, expects_measurements, may_spawn_fault, sort_order }
 */

return [

    ['slug' => 'pre_rental',        'name' => 'Pre-Rental Inspection',   'checklist_key' => 'pre_rental',   'expects_measurements' => false, 'may_spawn_fault' => true, 'sort_order' => 10],
    ['slug' => 'post_repair_qc',    'name' => 'Post-Repair QC',          'checklist_key' => 'post_repair',  'expects_measurements' => false, 'may_spawn_fault' => true, 'sort_order' => 20],
    ['slug' => 'periodic',          'name' => 'Periodic Inspection',     'checklist_key' => 'periodic',     'expects_measurements' => true,  'may_spawn_fault' => true, 'sort_order' => 30],
    ['slug' => 'dormancy_check',    'name' => 'Post-Downtime Check',     'checklist_key' => 'dormancy',     'expects_measurements' => false, 'may_spawn_fault' => true, 'sort_order' => 40],
    ['slug' => 'damage_walkaround', 'name' => 'Damage Walk-Around',      'checklist_key' => 'damage',       'expects_measurements' => false, 'may_spawn_fault' => true, 'sort_order' => 50],

];
