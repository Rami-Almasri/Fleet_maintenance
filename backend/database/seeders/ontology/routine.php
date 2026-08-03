<?php

/**
 * Scheduled servicing — the planned work, not a breakdown.
 *
 * These four are the `routine` category from config/maintenance_findings.php, and they are the ONLY
 * concepts whose exact names are load-bearing elsewhere: `routine_service_types` in that same config
 * maps 'oil change', 'oil filter', 'air filter' and 'battery replacement' onto ServiceReminder types,
 * so closing one of these findings rolls the matching reminder forward. Rename a concept here and the
 * reminder loop silently stops closing. Coolant service already lives in cooling.php, next to the
 * system it services.
 *
 * SERVICE WORDING IS NOT FAULT WORDING, AND MUST NOT BLEED INTO IT. "Battery Replacement" is what the
 * fleet DOES on a schedule; "Battery / won't start" is a car that failed this morning. They share a
 * component and nothing else — the vocabulary here is deliberately built from what a technician
 * writes on a completed job ("changed the oil", "ركبنا بطارية جديدة"), never from symptoms. A single
 * symptom phrase leaking into this file would route real breakdowns into the routine lane.
 */

return [
    [
        'name' => 'Oil Change', 'name_ar' => 'تغيير زيت المحرك',
        'category' => 'routine', 'category_label' => 'Routine Maintenance', 'category_label_ar' => 'الصيانة الدورية',
        'system' => 'Engine', 'subsystem' => 'Lubrication', 'discipline' => 'mechanical', 'risk' => 'moderate',
        'description' => 'Scheduled engine oil service, normally with the filter.',
        'en' => [
            'syn'      => ['oil change', 'engine oil change', 'oil service', 'oil and filter change',
                           'periodic oil service', 'changed the oil'],
            'workshop' => ['drained and refilled', 'oil and filter done', 'service done and reset',
                           'oil changed at interval'],
            'customer' => ['time for an oil change', 'they changed the oil', 'oil service due'],
            'miss'     => ['oil chnage', 'oil chang', 'engin oil change', 'oil cahnge'],
        ],
        'ar' => [
            'formal'   => ['تغيير زيت المحرك'],
            'workshop' => ['تغيير الزيت', 'غيرنا الزيت', 'الزيت يبغى تغيير', 'صيانة الزيت'],
        ],
        'components' => ['Engine oil', 'Oil filter', 'Sump plug washer'],
        'causes' => ['Scheduled service interval', 'Odometer reached the service point',
                     'Oil condition poor at inspection'],
        'inspection' => ['Check odometer against the service interval', 'Check the drain plug and filter seal after refill',
                         'Reset the service indicator', 'Record the odometer so the next due date is right'],
        'actions' => ['replace_engine_oil', 'replace_oil_filter', 'replace_sump_plug',
                      'top_up_engine_oil', 'visual_inspection'],
    ],
    [
        'name' => 'Battery Replacement', 'name_ar' => 'تغيير البطارية',
        'category' => 'routine', 'category_label' => 'Routine Maintenance', 'category_label_ar' => 'الصيانة الدورية',
        'system' => 'Electrical', 'subsystem' => 'Battery', 'discipline' => 'electrical', 'risk' => 'moderate',
        'description' => 'Battery replaced on schedule or after failing a load test.',
        'en' => [
            'syn'      => ['battery replacement', 'battery change', 'new battery', 'replaced the battery',
                           'battery fitted', 'battery renewal'],
            'workshop' => ['fitted a new battery', 'battery swapped', 'battery renewed and terminals protected'],
            'customer' => ['they put a new battery', 'battery was changed', 'i need a new battery'],
            'miss'     => ['batery replacement', 'battry change', 'battery replacment', 'new batery'],
        ],
        'ar' => [
            'formal'   => ['تغيير البطارية'],
            'workshop' => ['ركبنا بطارية جديدة', 'بطارية جديدة', 'تبديل البطارية', 'غيرنا البطارية'],
        ],
        'components' => ['Battery', 'Battery terminals', 'Hold-down clamp'],
        'causes' => ['Scheduled replacement interval', 'Failed a load test', 'Age beyond service life'],
        'inspection' => ['Load-test BEFORE replacing — a flat battery is not always a dead one',
                         'Check charging voltage after fitting', 'Clean and protect the terminals'],
        'actions' => ['replace_battery', 'test_battery', 'clean_battery_terminals', 'visual_inspection'],
    ],
    [
        'name' => 'Oil Filter', 'name_ar' => 'فلتر الزيت',
        'category' => 'routine', 'category_label' => 'Routine Maintenance', 'category_label_ar' => 'الصيانة الدورية',
        'system' => 'Engine', 'subsystem' => 'Lubrication', 'discipline' => 'mechanical', 'risk' => 'moderate',
        'description' => 'Oil filter replacement — usually with an oil change, sometimes billed alone.',
        'en' => [
            'syn'      => ['oil filter', 'oil filter change', 'oil filter replacement', 'new oil filter'],
            'workshop' => ['filter changed with the oil', 'filter housing o ring renewed', 'cartridge filter fitted'],
            'customer' => ['they changed the oil filter', 'oil filter was replaced'],
            'miss'     => ['oil filtr', 'oil fillter', 'oil filter chnage', 'oil fliter'],
        ],
        'ar' => [
            'formal'   => ['فلتر الزيت'],
            'workshop' => ['تغيير فلتر الزيت', 'غيرنا فلتر الزيت', 'فلتر زيت جديد'],
        ],
        'components' => ['Oil filter', 'Filter housing', 'Sealing ring'],
        'causes' => ['Scheduled service interval', 'Replaced with every oil change'],
        'inspection' => ['Confirm the part number matches the engine', 'Check the housing for leaks after refill'],
        'actions' => ['replace_oil_filter', 'replace_engine_oil', 'visual_inspection'],
    ],
    [
        'name' => 'Air Filter', 'name_ar' => 'فلتر الهواء',
        'category' => 'routine', 'category_label' => 'Routine Maintenance', 'category_label_ar' => 'الصيانة الدورية',
        'system' => 'Engine', 'subsystem' => 'Air intake', 'discipline' => 'mechanical', 'risk' => 'moderate',
        'description' => 'Engine air filter replacement. Falls due early in dusty operating conditions.',
        'en' => [
            'syn'      => ['air filter', 'air filter change', 'air filter replacement', 'new air filter',
                           'engine air filter'],
            'workshop' => ['element was black', 'airbox cleaned and filter fitted', 'filter changed at service'],
            'customer' => ['they changed the air filter', 'air filter was dirty'],
            'miss'     => ['air filtr', 'air fillter', 'air filter chnage', 'air fliter'],
        ],
        'ar' => [
            'formal'   => ['فلتر الهواء'],
            'workshop' => ['تغيير فلتر الهواء', 'غيرنا فلتر الهوا', 'فلتر هواء جديد'],
        ],
        'components' => ['Air filter', 'Airbox', 'Intake ducting'],
        'causes' => ['Scheduled service interval', 'Element clogged with dust', 'Sandy operating conditions'],
        'inspection' => ['Hold the element up to the light', 'Check the airbox seals and clips',
                         'Shorten the interval for cars working in dust'],
        'actions' => ['replace_air_filter', 'visual_inspection'],
    ],
    [
        // The whole scheduled visit, as opposed to any one item on it. This wording is everywhere in the
        // corpus — `Periodic Maintenance` is a maintenance_reason, a sheet service label, and the
        // commonest thing written on a routine ticket — and the ontology matched NONE of it, so the one
        // phrase that most clearly means "planned work" was invisible to the matcher (audit M10).
        'name' => 'Periodic Maintenance', 'name_ar' => 'الصيانة الدورية',
        'category' => 'routine', 'category_label' => 'Routine Maintenance', 'category_label_ar' => 'الصيانة الدورية',
        'system' => 'Vehicle', 'subsystem' => 'Scheduled servicing', 'discipline' => 'mechanical', 'risk' => 'routine',
        'description' => 'The scheduled service visit itself — the mileage/time interval service, whatever items it turns out to include.',
        'en' => [
            'syn'      => ['periodic maintenance', 'periodic service', 'scheduled maintenance',
                           'scheduled service', 'routine maintenance', 'routine service',
                           'regular maintenance', 'service due', 'general service', 'full service',
                           'major service', 'minor service', 'interval service'],
            'workshop' => ['carried out periodic maintenance', 'service done and reset',
                           'full service completed', 'service light reset'],
            'customer' => ['the car is due for its service', 'time for the periodic maintenance',
                           'it needs its regular service'],
            'miss'     => ['periodic maintainance', 'periodic mantenance', 'peridoic maintenance',
                           'periodic maintenence', 'periodc maintenance', 'preiodic maintenance'],
        ],
        'ar' => [
            'formal'   => ['الصيانة الدورية'],
            'workshop' => ['صيانة دورية', 'سوينا الصيانة الدورية', 'صيانة عامة', 'الصيانة على الوقت'],
        ],
        'components' => ['Engine oil', 'Oil filter', 'Air filter'],
        'causes' => ['Scheduled service interval', 'Odometer reached the service point',
                     'Time since the last service'],
        'inspection' => ['Read the odometer against the service schedule',
                         'Check which items fall due at this interval',
                         'Reset the service indicator once complete'],
        'actions' => ['replace_engine_oil', 'replace_oil_filter', 'visual_inspection'],
    ],
];
