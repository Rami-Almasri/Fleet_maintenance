<?php

/**
 * THE DAMAGE CATALOG — the authoritative list of "something was done to this car".
 *
 * This file is the single source of truth for which wordings are DAMAGE. Everything else in the
 * platform — the sheet-label map, the ontology lane filter, the event resolver, the dashboards — reads
 * it through EventClassificationService rather than keeping its own opinion.
 *
 * ── WHY A LIST AND NOT A CATEGORY RULE ────────────────────────────────────────────────────────────
 * "bodywork and interior are damage" is the tempting shortcut and it is wrong. Those categories are
 * SYSTEMS (where on the car), not TYPES (what happened). `interior` holds Dashboard fault, Door lock
 * fault, Interior light fault, Seat adjustment fault, Infotainment / screen issue and Water leakage
 * into cabin — six genuine failures — alongside upholstery tears. `bodywork` holds Rust / corrosion,
 * which is the car deteriorating, not somebody hitting it. A category rule mislabels all seven.
 *
 * ── THE TEST FOR MEMBERSHIP ───────────────────────────────────────────────────────────────────────
 * A row belongs here when an EXTERNAL EVENT caused it: an impact, a kerb, a stone, a careless renter,
 * vandalism. If the car did it to itself (wear, corrosion, a component failing), it is a FAULT — it
 * belongs in the fault catalog, because it IS evidence about the vehicle's condition.
 *
 * `is_chargeable`          — a renter can be billed for it (the default for damage; a claim may waive it)
 * `is_insurable`           — it can open an insurance claim (accident/impact/glass, not a light scuff)
 * `affects_roadworthiness` — it grounds the car. Cosmetic damage does not; a cracked windscreen does.
 * `damage_type`            — impact | scratch | crack | tear | vandalism | unknown
 * `area_key`               — where on the car, when the wording says. Null = not localised.
 */

return [

    // ── Wheels & rims ─────────────────────────────────────────────────────────────────────────────
    // The single biggest damage population in the corpus (~1,100 rows across the spellings) and the
    // one that started this: a kerbed rim is not a fault, it is a bill.
    [
        'slug' => 'rim_scratch', 'name' => 'Rim Scratch', 'name_ar' => 'خدش في الجنط',
        'category_key' => 'tyres', 'area_key' => 'wheel', 'damage_type' => 'scratch',
        'is_chargeable' => true, 'is_insurable' => false, 'affects_roadworthiness' => false,
    ],
    [
        'slug' => 'rim_dent_bend', 'name' => 'Rim Dent / Bend', 'name_ar' => 'انبعاج في الجنط',
        'category_key' => 'tyres', 'area_key' => 'wheel', 'damage_type' => 'impact',
        'is_chargeable' => true, 'is_insurable' => true, 'affects_roadworthiness' => true,
    ],
    [
        'slug' => 'wheel_cover_damage', 'name' => 'Wheel Cover Damage', 'name_ar' => 'تلف في غطاء الجنط',
        'category_key' => 'tyres', 'area_key' => 'wheel', 'damage_type' => 'impact',
        'is_chargeable' => true, 'is_insurable' => false, 'affects_roadworthiness' => false,
    ],

    // ── Paint & panels ────────────────────────────────────────────────────────────────────────────
    [
        'slug' => 'surface_scratch', 'name' => 'Minor Surface Scratch', 'name_ar' => 'خدش سطحي',
        'category_key' => 'bodywork', 'area_key' => null, 'damage_type' => 'scratch',
        'is_chargeable' => true, 'is_insurable' => false, 'affects_roadworthiness' => false,
    ],
    [
        'slug' => 'paint_scratch', 'name' => 'Paint Scratch', 'name_ar' => 'خدش في الطلاء',
        'category_key' => 'bodywork', 'area_key' => null, 'damage_type' => 'scratch',
        'is_chargeable' => true, 'is_insurable' => false, 'affects_roadworthiness' => false,
    ],
    [
        'slug' => 'deep_dent', 'name' => 'Deep Dent', 'name_ar' => 'انبعاج عميق',
        'category_key' => 'bodywork', 'area_key' => null, 'damage_type' => 'impact',
        'is_chargeable' => true, 'is_insurable' => true, 'affects_roadworthiness' => false,
    ],
    [
        'slug' => 'door_dent', 'name' => 'Door Dent', 'name_ar' => 'انبعاج في الباب',
        'category_key' => 'bodywork', 'area_key' => 'door', 'damage_type' => 'impact',
        'is_chargeable' => true, 'is_insurable' => true, 'affects_roadworthiness' => false,
    ],
    [
        'slug' => 'fender_damage', 'name' => 'Fender Damage', 'name_ar' => 'تلف في الرفرف',
        'category_key' => 'bodywork', 'area_key' => 'fender', 'damage_type' => 'impact',
        'is_chargeable' => true, 'is_insurable' => true, 'affects_roadworthiness' => false,
    ],
    [
        'slug' => 'bumper_damage', 'name' => 'Bumper Damage', 'name_ar' => 'تلف في الصدام',
        'category_key' => 'bodywork', 'area_key' => 'bumper', 'damage_type' => 'impact',
        'is_chargeable' => true, 'is_insurable' => true, 'affects_roadworthiness' => false,
    ],
    [
        'slug' => 'front_lip_damage', 'name' => 'Front Lip / Diffuser Damage', 'name_ar' => 'تلف الليب الأمامي',
        'category_key' => 'bodywork', 'area_key' => 'front_bumper', 'damage_type' => 'impact',
        'is_chargeable' => true, 'is_insurable' => false, 'affects_roadworthiness' => false,
    ],
    [
        'slug' => 'panel_misalignment', 'name' => 'Panel Misalignment', 'name_ar' => 'عدم محاذاة الأجزاء',
        'category_key' => 'bodywork', 'area_key' => null, 'damage_type' => 'impact',
        'is_chargeable' => true, 'is_insurable' => true, 'affects_roadworthiness' => false,
    ],
    [
        'slug' => 'body_damage', 'name' => 'Body Damage', 'name_ar' => 'تلف في الهيكل',
        'category_key' => 'bodywork', 'area_key' => null, 'damage_type' => 'impact',
        'is_chargeable' => true, 'is_insurable' => true, 'affects_roadworthiness' => false,
    ],
    [
        'slug' => 'accident_damage', 'name' => 'Accident Damage', 'name_ar' => 'أضرار حادث',
        'category_key' => 'bodywork', 'area_key' => null, 'damage_type' => 'impact',
        'is_chargeable' => true, 'is_insurable' => true, 'affects_roadworthiness' => true,
    ],
    [
        // Peeling paint and wraps: caused by handling/washing/sun, not by a component failing.
        'slug' => 'paint_peeling', 'name' => 'Paint Peeling / Fading', 'name_ar' => 'تقشر الطلاء',
        'category_key' => 'bodywork', 'area_key' => null, 'damage_type' => 'unknown',
        'is_chargeable' => false, 'is_insurable' => false, 'affects_roadworthiness' => false,
    ],
    [
        'slug' => 'wrap_sticker_damage', 'name' => 'Sticker / Wrap Damage', 'name_ar' => 'تلف الاستيكر',
        'category_key' => 'bodywork', 'area_key' => null, 'damage_type' => 'unknown',
        'is_chargeable' => false, 'is_insurable' => false, 'affects_roadworthiness' => false,
    ],

    // ── Glass & mirrors ───────────────────────────────────────────────────────────────────────────
    [
        'slug' => 'windscreen_chip', 'name' => 'Windscreen Chip', 'name_ar' => 'نقرة في الزجاج الأمامي',
        'category_key' => 'bodywork', 'area_key' => 'windscreen', 'damage_type' => 'impact',
        'is_chargeable' => true, 'is_insurable' => true, 'affects_roadworthiness' => false,
    ],
    [
        'slug' => 'glass_crack', 'name' => 'Glass Chip / Crack', 'name_ar' => 'شرخ في الزجاج',
        'category_key' => 'bodywork', 'area_key' => 'windscreen', 'damage_type' => 'crack',
        'is_chargeable' => true, 'is_insurable' => true, 'affects_roadworthiness' => true,
    ],
    [
        'slug' => 'broken_window', 'name' => 'Broken Glass / Window', 'name_ar' => 'زجاج مكسور',
        'category_key' => 'bodywork', 'area_key' => 'window', 'damage_type' => 'impact',
        'is_chargeable' => true, 'is_insurable' => true, 'affects_roadworthiness' => true,
    ],
    [
        'slug' => 'broken_mirror', 'name' => 'Broken / Loose Mirror', 'name_ar' => 'مرآة مكسورة',
        'category_key' => 'bodywork', 'area_key' => 'mirror', 'damage_type' => 'impact',
        'is_chargeable' => true, 'is_insurable' => true, 'affects_roadworthiness' => true,
    ],
    [
        'slug' => 'mirror_cover_damage', 'name' => 'Mirror Cover Damage', 'name_ar' => 'تلف غطاء المرآة',
        'category_key' => 'bodywork', 'area_key' => 'mirror', 'damage_type' => 'impact',
        'is_chargeable' => true, 'is_insurable' => false, 'affects_roadworthiness' => false,
    ],

    // ── Interior ──────────────────────────────────────────────────────────────────────────────────
    // ONLY the wordings that mean somebody damaged it. Dashboard fault, Door lock fault, Interior light
    // fault, Seat adjustment fault, Infotainment issue and Water leakage into cabin are FAULTS and are
    // deliberately absent — they belong to the fault catalog and must keep feeding reliability.
    [
        'slug' => 'upholstery_damage', 'name' => 'Upholstery Damage', 'name_ar' => 'تلف في التنجيد',
        'category_key' => 'interior', 'area_key' => 'seat', 'damage_type' => 'tear',
        'is_chargeable' => true, 'is_insurable' => false, 'affects_roadworthiness' => false,
    ],
    [
        'slug' => 'upholstery_tear', 'name' => 'Seat / Upholstery Damage', 'name_ar' => 'تمزق المقعد',
        'category_key' => 'interior', 'area_key' => 'seat', 'damage_type' => 'tear',
        'is_chargeable' => true, 'is_insurable' => false, 'affects_roadworthiness' => false,
    ],
    [
        'slug' => 'trim_damage', 'name' => 'Trim/Panel Damage', 'name_ar' => 'تلف في الفرش الداخلي',
        'category_key' => 'interior', 'area_key' => null, 'damage_type' => 'impact',
        'is_chargeable' => true, 'is_insurable' => false, 'affects_roadworthiness' => false,
    ],
    [
        'slug' => 'interior_trim_damage', 'name' => 'Interior Trim Damage', 'name_ar' => 'تلف الزينة الداخلية',
        'category_key' => 'interior', 'area_key' => null, 'damage_type' => 'impact',
        'is_chargeable' => true, 'is_insurable' => false, 'affects_roadworthiness' => false,
    ],
    [
        'slug' => 'broken_trim', 'name' => 'Broken Trim', 'name_ar' => 'كسر في الزينة',
        'category_key' => 'interior', 'area_key' => null, 'damage_type' => 'impact',
        'is_chargeable' => true, 'is_insurable' => false, 'affects_roadworthiness' => false,
    ],
    [
        'slug' => 'interior_damage', 'name' => 'Interior Damage', 'name_ar' => 'تلف داخلي',
        'category_key' => 'interior', 'area_key' => null, 'damage_type' => 'unknown',
        'is_chargeable' => true, 'is_insurable' => false, 'affects_roadworthiness' => false,
    ],
    [
        'slug' => 'vandalism', 'name' => 'Vandalism', 'name_ar' => 'تخريب متعمد',
        'category_key' => 'bodywork', 'area_key' => null, 'damage_type' => 'vandalism',
        'is_chargeable' => true, 'is_insurable' => true, 'affects_roadworthiness' => false,
    ],
];
