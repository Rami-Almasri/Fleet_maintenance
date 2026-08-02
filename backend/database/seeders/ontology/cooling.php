<?php

/**
 * Cooling-system fault concepts. COOLING is 1,719 recorded events.
 *
 * "cooling liquid change" appeared unmatched in the coverage report — the fleet's own wording for a
 * coolant service, and exactly the kind of phrase that has to be in here rather than assumed.
 */

return [
    [
        'name' => 'Coolant leak', 'name_ar' => 'تسريب مياه التبريد',
        'category' => 'fluids', 'category_label' => 'Fluids & leaks', 'category_label_ar' => 'السوائل والتسريبات',
        'system' => 'Cooling system', 'subsystem' => 'Coolant circuit', 'discipline' => 'mechanical', 'risk' => 'critical',
        'description' => 'Coolant escaping — leads directly to overheating and engine damage.',
        'en' => [
            'syn'      => ['coolant leak', 'water leak', 'antifreeze leak', 'radiator leak', 'losing coolant',
                           'coolant loss'],
            'workshop' => ['weeping from the hose', 'radiator seeping', 'pressure test fails', 'losing water'],
            'customer' => ['water under the car', 'green liquid leaking', 'coolant going down',
                           'have to add water often', 'puddle under the engine'],
            'miss'     => ['radiater leak', 'coolent leak', 'colant leak', 'anti freeze leak'],
        ],
        'ar' => [
            'formal'   => ['تسريب سائل التبريد'],
            'workshop' => ['ماء تحت الموتر', 'تسريب ماء الرديتر', 'الماي ينقص', 'الرديتر يسرب', 'يشرب ماء'],
        ],
        'components' => ['Radiator', 'Hoses', 'Water pump', 'Expansion tank', 'Head gasket'],
        'causes' => ['Cracked / split hose', 'Leaking radiator', 'Failed water-pump seal',
                     'Blown head gasket', 'Cracked expansion tank'],
        'inspection' => ['Pressure-test cooling system', 'Inspect hoses and clamps', 'Check for coolant in oil'],
        'actions' => ['replace_coolant_hose', 'replace_radiator', 'replace_water_pump',
                      'replace_expansion_tank', 'replace_coolant', 'pressure_test', 'leak_test'],
    ],
    [
        'name' => 'Coolant service', 'name_ar' => 'تغيير سائل التبريد',
        // Label matches config/maintenance_findings.php — one category key must not show two names.
        'category' => 'routine', 'category_label' => 'Routine Maintenance', 'category_label_ar' => 'الصيانة الدورية',
        'system' => 'Cooling system', 'subsystem' => 'Coolant circuit', 'discipline' => 'mechanical', 'risk' => 'routine',
        'description' => 'Scheduled replacement or top-up of engine coolant.',
        'en' => [
            'syn'      => ['coolant change', 'coolant flush', 'cooling liquid change', 'antifreeze change',
                           'radiator flush', 'water change', 'coolant top up'],
            'workshop' => ['flushed the system', 'changed the coolant', 'topped up the water'],
            'customer' => ['they changed the water', 'radiator water changed'],
            'miss'     => ['coolent change', 'cooling liquid chnage', 'colant service'],
        ],
        'ar' => [
            'formal'   => ['تغيير سائل التبريد'],
            'workshop' => ['تغيير ماء الرديتر', 'غيرنا الماي', 'تعبئة ماء التبريد'],
        ],
        'components' => ['Coolant', 'Radiator', 'Expansion tank'],
        'causes' => ['Scheduled service interval', 'After cooling-system repair'],
        'inspection' => ['Check coolant condition and concentration', 'Bleed air after refill'],
        'actions' => ['replace_coolant', 'top_up_coolant', 'clean_radiator', 'pressure_test'],
    ],
    [
        'name' => 'Cooling fan fault', 'name_ar' => 'عطل في مروحة التبريد',
        'category' => 'engine', 'system' => 'Cooling system', 'subsystem' => 'Fan', 'discipline' => 'electrical', 'risk' => 'critical',
        'description' => 'Radiator fan not running when it should — the car overheats in traffic but is fine moving.',
        'en' => [
            'syn'      => ['fan not working', 'radiator fan fault', 'cooling fan failure', 'fan not running'],
            'workshop' => ['fan not cutting in', 'fan relay gone', 'fan motor seized'],
            'customer' => ['car overheats in traffic but not on the road', 'fan does not turn on',
                           'gets hot when standing still'],
            'miss'     => ['cooling fan fualt', 'radiater fan'],
        ],
        'ar' => [
            'formal'   => ['عطل في مروحة التبريد'],
            'workshop' => ['المروحة ما تشتغل', 'مروحة الرديتر خربانة', 'يسخن في الزحمة'],
        ],
        'components' => ['Cooling fan', 'Fan relay', 'Temperature sensor', 'Fan motor'],
        'causes' => ['Failed fan motor', 'Faulty fan relay', 'Faulty temperature sensor', 'Wiring fault'],
        'inspection' => ['Run engine to temperature and observe fan', 'Test relay and fuse', 'Check sensor signal'],
        'actions' => ['replace_cooling_fan', 'replace_fuse', 'repair_wiring', 'scan_diagnostics'],
    ],
    [
        'name' => 'Water pump failure', 'name_ar' => 'عطل في طرمبة الماء',
        'category' => 'engine', 'system' => 'Cooling system', 'subsystem' => 'Water pump', 'discipline' => 'mechanical', 'risk' => 'critical',
        'description' => 'Water pump leaking, noisy or not circulating coolant.',
        'en' => [
            'syn'      => ['water pump leak', 'water pump failure', 'pump bearing noise', 'coolant pump fault'],
            'workshop' => ['weep hole dripping', 'pump bearing rumble', 'impeller gone'],
            'customer' => ['garage says water pump needs changing', 'noise from the front and losing water'],
            'miss'     => ['water pmup', 'waterpump failure'],
        ],
        'ar' => [
            'formal'   => ['عطل في مضخة الماء'],
            'workshop' => ['طرمبة الماء خربانة', 'الطرمبة تسرب', 'صوت من طرمبة الماء'],
        ],
        'components' => ['Water pump', 'Timing belt', 'Coolant'],
        'causes' => ['Worn pump bearing', 'Failed pump seal', 'Corrosion damage to impeller'],
        'inspection' => ['Check weep hole', 'Check pulley play', 'Pressure-test system'],
        'actions' => ['replace_water_pump', 'replace_coolant', 'replace_accessory_belt', 'pressure_test'],
    ],
    [
        'name' => 'Radiator damage', 'name_ar' => 'تلف الرديتر',
        'category' => 'engine', 'system' => 'Cooling system', 'subsystem' => 'Radiator', 'discipline' => 'mechanical', 'risk' => 'critical',
        'description' => 'Radiator blocked, corroded or physically damaged.',
        'en' => [
            'syn'      => ['radiator damage', 'blocked radiator', 'radiator blockage', 'radiator corroded',
                           'radiator core damaged'],
            'workshop' => ['core is blocked', 'fins collapsed', 'scaled up inside'],
            'customer' => ['radiator is damaged', 'they say the radiator needs replacing'],
            'miss'     => ['radiater damage', 'radiater blocked'],
        ],
        'ar' => [
            'formal'   => ['تلف المشع الحراري'],
            'workshop' => ['الرديتر مسدود', 'الرديتر خربان', 'الرديتر يحتاج تغيير'],
        ],
        'components' => ['Radiator', 'Coolant', 'Fan'],
        'causes' => ['Internal scaling / blockage', 'Impact damage', 'Corrosion', 'Contaminated coolant'],
        'inspection' => ['Check inlet/outlet temperature difference', 'Inspect core and fins', 'Pressure-test'],
        'actions' => ['replace_radiator', 'clean_radiator', 'replace_coolant', 'pressure_test'],
    ],
];
