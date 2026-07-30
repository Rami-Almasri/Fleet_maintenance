<?php

/**
 * Climate / HVAC fault concepts. AC is 1,506 recorded events — and in this climate an A/C fault
 * grounds a rental car as effectively as a mechanical one, which is why "not cooling" is graded
 * moderate rather than routine.
 */

return [
    [
        'name' => 'A/C not cooling', 'name_ar' => 'المكيف لا يبرد',
        'category' => 'ac', 'category_label' => 'Air conditioning', 'category_label_ar' => 'التكييف',
        'system' => 'HVAC', 'subsystem' => 'Refrigerant circuit', 'discipline' => 'mechanical', 'risk' => 'moderate',
        'description' => 'Air conditioning blows warm or barely cool air.',
        'en' => [
            'syn'      => ['ac not cooling', 'air conditioning not working', 'no cold air', 'ac blowing warm',
                           'aircon not cold', 'air con fault', 'weak cooling'],
            'workshop' => ['gas is low', 'compressor not engaging', 'no pressure on the low side', 'needs regas'],
            'customer' => ['ac not cold', 'air conditioning blowing warm', 'aircon not working', 'no cold air',
                           'ac weak', 'air is not cold enough'],
            'miss'     => ['ac not colling', 'air condition not working', 'aircon not colding'],
            'abbr'     => ['A/C', 'AC', 'aircon', 'HVAC'],
        ],
        'ar' => [
            'formal'   => ['المكيف لا يبرد', 'ضعف في التبريد'],
            'workshop' => ['المكيف ما يبرد', 'التكييف حار', 'المكيف ضعيف', 'ما يطلع برد', 'المكيف يطلع هوا حار'],
        ],
        'components' => ['Compressor', 'Condenser', 'Evaporator', 'Refrigerant', 'Expansion valve', 'Cabin filter'],
        'causes' => ['Low refrigerant / leak', 'Failed compressor', 'Blocked condenser',
                     'Faulty expansion valve', 'Clogged cabin filter'],
        'inspection' => ['Measure vent temperature', 'Check system pressures', 'Leak-test with dye or sniffer',
                         'Confirm compressor engages'],
        'actions' => ['recharge_ac_gas', 'repair_ac_leak', 'replace_ac_compressor', 'replace_ac_condenser',
                      'replace_cabin_filter', 'test_ac_performance', 'leak_test'],
    ],
    [
        'name' => 'Weak airflow', 'name_ar' => 'ضعف تدفق الهواء',
        'category' => 'ac', 'system' => 'HVAC', 'subsystem' => 'Air distribution', 'discipline' => 'mechanical', 'risk' => 'routine',
        'description' => 'Air comes out cold but at low volume.',
        'en' => [
            'syn'      => ['weak airflow', 'low air flow', 'poor blower output', 'fan speed low', 'air not blowing'],
            'workshop' => ['cabin filter blocked', 'blower on low only', 'resistor pack gone'],
            'customer' => ['air is weak', 'not much air coming out', 'fan only works on high',
                           'air barely comes out of the vents'],
            'miss'     => ['weak air flow', 'low airflo'],
        ],
        'ar' => [
            'formal'   => ['ضعف تدفق الهواء'],
            'workshop' => ['الهوا ضعيف', 'ما يطلع هوا', 'المروحة ضعيفة', 'الهوا خفيف'],
        ],
        'components' => ['Cabin filter', 'Blower motor', 'Blower resistor', 'Air ducts'],
        'causes' => ['Clogged cabin filter', 'Failing blower motor', 'Faulty blower resistor', 'Blocked duct'],
        'inspection' => ['Inspect cabin filter', 'Test all blower speeds', 'Check resistor pack'],
        'actions' => ['replace_cabin_filter', 'replace_blower_motor', 'clean_ac_evaporator', 'test_ac_performance'],
    ],
    [
        'name' => 'Bad smell from vents', 'name_ar' => 'رائحة كريهة من المكيف',
        'category' => 'ac', 'system' => 'HVAC', 'subsystem' => 'Evaporator', 'discipline' => 'mechanical', 'risk' => 'routine',
        'description' => 'Musty, damp or burning smell when the climate system runs.',
        'en' => [
            'syn'      => ['bad smell from ac', 'musty smell', 'damp smell', 'mould smell', 'smell from vents',
                           'burning smell from ac'],
            'workshop' => ['evaporator needs disinfecting', 'drain blocked', 'mould on the core'],
            'customer' => ['it smells like burning', 'bad smell from the ac', 'smells musty inside',
                           'burning smell in the cabin', 'car smells bad when i turn on the ac'],
            'miss'     => ['bad smel from ac', 'musty smel'],
        ],
        'ar' => [
            'formal'   => ['رائحة كريهة من فتحات التكييف'],
            'workshop' => ['ريحة حريق', 'ريحة كريهة من المكيف', 'ريحة عفن', 'المكيف ريحته وايد'],
        ],
        'components' => ['Evaporator', 'Cabin filter', 'Condensate drain'],
        'causes' => ['Mould on evaporator', 'Blocked condensate drain', 'Old cabin filter', 'Debris in air intake'],
        'inspection' => ['Inspect cabin filter', 'Check drain is clear', 'Smell test at vents'],
        'actions' => ['clean_ac_evaporator', 'replace_cabin_filter', 'clean_interior', 'test_ac_performance'],
    ],
    [
        'name' => 'Noisy blower', 'name_ar' => 'صوت من مروحة المكيف',
        'category' => 'ac', 'system' => 'HVAC', 'subsystem' => 'Blower', 'discipline' => 'mechanical', 'risk' => 'routine',
        'description' => 'Rattle, whine or ticking from the blower when the fan runs.',
        'en' => [
            'syn'      => ['blower noise', 'fan noise', 'noisy blower motor', 'rattling from the vents'],
            'workshop' => ['debris in the blower cage', 'blower bearing dry'],
            'customer' => ['noise when i turn on the ac', 'rattling sound from the dashboard when fan is on'],
            'miss'     => ['noisy blowr', 'blower nois'],
        ],
        'ar' => [
            'formal'   => ['صوت من مروحة المكيف'],
            'workshop' => ['صوت من مروحة المكيف', 'المروحة تطقطق', 'صوت لما أشغل المكيف'],
        ],
        'components' => ['Blower motor', 'Blower cage', 'Cabin filter housing'],
        'causes' => ['Debris in blower cage', 'Worn blower bearing', 'Loose filter housing'],
        'inspection' => ['Run all fan speeds and listen', 'Inspect blower cage for debris'],
        'actions' => ['replace_blower_motor', 'clean_ac_evaporator', 'replace_cabin_filter'],
    ],
    [
        'name' => 'A/C compressor fault', 'name_ar' => 'عطل في كمبروسر المكيف',
        'category' => 'ac', 'system' => 'HVAC', 'subsystem' => 'Compressor', 'discipline' => 'mechanical', 'risk' => 'moderate',
        'description' => 'Compressor seized, noisy, or clutch not engaging.',
        'en' => [
            // "clutch not engaging" was here and also on Clutch issue — one wording owned by two
            // concepts, so whichever won was arbitrary. Qualified to the A/C compressor clutch,
            // which is what is actually meant here. Found by ontology:duplicates.
            'syn'      => ['compressor failure', 'ac compressor fault', 'compressor seized',
                           'ac compressor clutch not engaging', 'compressor noise'],
            'workshop' => ['ac clutch not pulling in', 'compressor locked up', 'shredded the belt'],
            'customer' => ['loud noise when ac is on', 'ac makes a noise then stops cooling'],
            'miss'     => ['compresor fault', 'ac compresser'],
        ],
        'ar' => [
            'formal'   => ['عطل في ضاغط المكيف'],
            'workshop' => ['الكمبروسر خربان', 'الكمبروسر ما يشتغل', 'صوت من كمبروسر المكيف'],
        ],
        'components' => ['Compressor', 'Compressor clutch', 'Drive belt', 'Refrigerant'],
        'causes' => ['Seized compressor', 'Failed clutch coil', 'Low refrigerant causing lubrication loss',
                     'Broken drive belt'],
        'inspection' => ['Check clutch engages with power applied', 'Check belt', 'Measure system pressures'],
        'actions' => ['replace_ac_compressor', 'replace_accessory_belt', 'recharge_ac_gas', 'test_ac_performance'],
    ],
    [
        'name' => 'Refrigerant leak', 'name_ar' => 'تسريب غاز المكيف',
        'category' => 'ac', 'system' => 'HVAC', 'subsystem' => 'Refrigerant circuit', 'discipline' => 'mechanical', 'risk' => 'moderate',
        'description' => 'Refrigerant escaping — cooling fades over weeks and returns briefly after a regas.',
        'en' => [
            'syn'      => ['gas leak', 'refrigerant leak', 'ac leak', 'freon leak', 'losing gas'],
            'workshop' => ['dye showing at the condenser', 'o-ring leaking', 'system empty'],
            'customer' => ['ac works for a while then stops cooling', 'they filled the gas but it went again'],
            'miss'     => ['refrigerent leak', 'freon leek', 'gas leek'],
        ],
        'ar' => [
            'formal'   => ['تسريب غاز التبريد'],
            'workshop' => ['الفريون ناقص', 'يسرب غاز', 'عبينا غاز ورجع خلص', 'تسريب في المكيف'],
        ],
        'components' => ['Condenser', 'Hoses', 'O-rings', 'Compressor seal', 'Evaporator'],
        'causes' => ['Leaking o-ring', 'Corroded condenser', 'Damaged hose', 'Compressor shaft seal leak'],
        'inspection' => ['Add UV dye and re-inspect', 'Electronic leak detection', 'Pressure hold test'],
        'actions' => ['repair_ac_leak', 'replace_ac_condenser', 'recharge_ac_gas', 'leak_test', 'test_ac_performance'],
    ],
    [
        'name' => 'Heater not working', 'name_ar' => 'المدفأة لا تعمل',
        'category' => 'ac', 'system' => 'HVAC', 'subsystem' => 'Heater circuit', 'discipline' => 'mechanical', 'risk' => 'routine',
        'description' => 'No warm air from the heater.',
        'en' => [
            'syn'      => ['no heat', 'heater not working', 'heating fault', 'blows cold only'],
            'workshop' => ['heater core blocked', 'blend door stuck', 'thermostat stuck open'],
            'customer' => ['heater does not work', 'no warm air', 'only cold air comes out'],
            'miss'     => ['heater not workin', 'no heaters'],
        ],
        'ar' => [
            'formal'   => ['المدفأة لا تعمل'],
            'workshop' => ['الهيتر ما يشتغل', 'ما يطلع هوا حار', 'التدفئة خربانة'],
        ],
        'components' => ['Heater core', 'Blend door actuator', 'Thermostat', 'Coolant'],
        'causes' => ['Blocked heater core', 'Faulty blend door actuator', 'Thermostat stuck open', 'Low coolant'],
        'inspection' => ['Check coolant level', 'Feel heater hoses for flow', 'Test blend door operation'],
        'actions' => ['replace_thermostat', 'top_up_coolant', 'clean_radiator', 'scan_diagnostics'],
    ],
];
