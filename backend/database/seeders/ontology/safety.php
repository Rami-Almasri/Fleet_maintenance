<?php

/**
 * Safety systems, driver assistance, keys and exhaust.
 *
 * Small categories individually (KEY 402, EXHAUST 445) but every one appeared in the coverage miss
 * list — "key battery changed", "exhuast fix", "checking eelectrrical cameras", "radar issue" — and
 * a technician hitting a blank on a common quick job is exactly the failure the ontology exists to
 * prevent.
 */

return [
    [
        'name' => 'Airbag warning', 'name_ar' => 'إنذار الوسادة الهوائية',
        'category' => 'electrical', 'category_label' => 'Electrical', 'category_label_ar' => 'الكهرباء',
        'system' => 'Safety', 'subsystem' => 'SRS', 'discipline' => 'electrical', 'risk' => 'critical',
        'description' => 'SRS warning lamp lit — airbags may not deploy in a collision.',
        'en' => [
            'syn'      => ['airbag light', 'SRS warning', 'airbag fault', 'srs light on', 'airbag warning light'],
            'workshop' => ['SRS code stored', 'seat sensor fault', 'clock spring open circuit'],
            'customer' => ['airbag light is on', 'red airbag symbol on dash', 'srs light came on'],
            'miss'     => ['air bag light', 'srs ligth', 'airbagg'],
            'abbr'     => ['SRS', 'airbag'],
        ],
        'ar' => [
            'formal'   => ['إنذار نظام الوسائد الهوائية'],
            'workshop' => ['لمبة الإيرباق', 'إشارة الوسادة الهوائية', 'لمبة الاير باق طالعة'],
        ],
        'components' => ['Airbag modules', 'Clock spring', 'Seat occupancy sensor', 'Crash sensors', 'SRS module'],
        'causes' => ['Faulty clock spring', 'Seat occupancy sensor fault', 'Connector under the seat disturbed',
                     'Previously deployed airbag not reset'],
        'inspection' => ['Scan SRS codes', 'Check connectors under seats', 'Confirm no prior deployment'],
        'actions' => ['scan_diagnostics', 'repair_wiring', 'reset_warning_light'],
    ],
    [
        'name' => 'Parking sensor fault', 'name_ar' => 'عطل في حساسات الركن',
        'category' => 'electrical', 'system' => 'ADAS', 'subsystem' => 'Parking aids', 'discipline' => 'electrical', 'risk' => 'moderate',
        'description' => 'Parking sensors beeping constantly, silent, or reporting false obstacles.',
        'en' => [
            'syn'      => ['parking sensor fault', 'sensors not working', 'pdc fault', 'beeping constantly',
                           'bumper sensors', 'reverse sensor not working', 'false beeping'],
            'workshop' => ['sensor faulty after bumper repaint', 'sensor not ringing', 'ring tone dead'],
            'customer' => ['parking sensors beep all the time', 'sensors do not work', 'beeps when nothing is there'],
            'miss'     => ['parking sensr', 'bumper sensores', 'parking senser'],
            'abbr'     => ['PDC'],
        ],
        'ar' => [
            'formal'   => ['عطل في حساسات المساعدة على الركن'],
            'workshop' => ['الحساسات ما تشتغل', 'السنسور يصفر دايم', 'حساس الصدام خربان', 'الرادار يصفر'],
        ],
        'components' => ['Parking sensors', 'PDC module', 'Bumper wiring'],
        'causes' => ['Sensor damaged in a bumper impact', 'Sensor painted over during repair',
                     'Water ingress into connector', 'Module fault'],
        'inspection' => ['Scan for the failing sensor position', 'Tap each sensor and listen', 'Inspect after bodywork'],
        'actions' => ['scan_diagnostics', 'repair_wiring', 'visual_inspection'],
    ],
    [
        'name' => 'Camera / ADAS fault', 'name_ar' => 'عطل في الكاميرا أو أنظمة المساعدة',
        'category' => 'electrical', 'system' => 'ADAS', 'subsystem' => 'Cameras & radar', 'discipline' => 'electrical', 'risk' => 'moderate',
        'description' => 'Reversing camera, 360 view, radar or driver-assist system not working.',
        'en' => [
            'syn'      => ['camera not working', 'reverse camera fault', 'radar issue', 'adas fault',
                           'lane assist not working', 'cruise control fault', '360 camera fault',
                           'blind spot warning fault'],
            'workshop' => ['camera needs calibration', 'radar out of alignment after bumper work',
                           'calibration after windscreen replacement'],
            'customer' => ['reverse camera is black', 'camera does not show', 'cruise control stopped working',
                           'lane assist light is on'],
            'miss'     => ['camara not working', 'reverce camera', 'adas fualt', 'radar isue'],
            'abbr'     => ['ADAS', '360 camera'],
        ],
        'ar' => [
            'formal'   => ['عطل في الكاميرا أو أنظمة مساعدة السائق'],
            'workshop' => ['الكاميرا ما تشتغل', 'كاميرا الرجوع سودا', 'الرادار خربان', 'الكاميرا تحتاج برمجة'],
        ],
        'components' => ['Reversing camera', 'Front camera', 'Radar sensor', 'ADAS module', 'Windscreen mount'],
        'causes' => ['Camera failure', 'Calibration lost after windscreen or bumper work',
                     'Water ingress', 'Wiring damage'],
        'inspection' => ['Scan ADAS codes', 'Check camera lens and mounting',
                         'Confirm calibration status after glass or body repair'],
        'actions' => ['scan_diagnostics', 'repair_wiring', 'replace_windscreen', 'visual_inspection'],
    ],
    [
        'name' => 'Key / immobiliser fault', 'name_ar' => 'عطل في المفتاح أو الإيموبيليزر',
        'category' => 'electrical', 'system' => 'Security', 'subsystem' => 'Key & immobiliser', 'discipline' => 'electrical', 'risk' => 'moderate',
        'description' => 'Key not recognised, key battery flat, or a replacement key needing coding.',
        'en' => [
            'syn'      => ['key battery', 'key battery changed', 'key not recognised', 'immobiliser fault',
                           'spare key coding', 'new key programming', 'key lost', 'remote battery'],
            'workshop' => ['coded the new key', 'transponder not read', 'key battery flat'],
            'customer' => ['key does not work', 'had to change the key battery', 'car does not detect the key',
                           'need a spare key'],
            'miss'     => ['key batery', 'imobiliser', 'key programing'],
        ],
        'ar' => [
            'formal'   => ['عطل في المفتاح أو نظام منع الحركة'],
            'workshop' => ['بطارية المفتاح', 'غيرنا بطارية الريموت', 'المفتاح ما ينقرا', 'برمجة مفتاح جديد'],
        ],
        'components' => ['Key fob', 'Fob battery', 'Immobiliser module', 'Transponder', 'Antenna ring'],
        'causes' => ['Flat key battery', 'Key needs coding', 'Faulty antenna ring', 'Damaged transponder'],
        'inspection' => ['Replace fob battery and retest', 'Test the spare key', 'Scan immobiliser codes'],
        'actions' => ['program_key_fob', 'scan_diagnostics', 'repair_wiring'],
    ],
    [
        'name' => 'Exhaust fault', 'name_ar' => 'عطل في العادم',
        'category' => 'engine', 'category_label' => 'Engine', 'category_label_ar' => 'المحرك',
        'system' => 'Exhaust', 'subsystem' => 'Exhaust system', 'discipline' => 'mechanical', 'risk' => 'moderate',
        'description' => 'Exhaust blowing, rattling, corroded or detached.',
        'en' => [
            'syn'      => ['exhaust fix', 'exhaust leak', 'exhaust blowing', 'silencer damaged', 'muffler fault',
                           'exhaust rattle', 'catalytic converter fault', 'exhaust hanging'],
            'workshop' => ['blowing at the joint', 'silencer rotted', 'welded the exhaust', 'cat is blocked'],
            'customer' => ['car is very loud', 'exhaust is hanging down', 'loud noise from the back',
                           'rattling under the car'],
            'miss'     => ['exhuast fix', 'exaust leak', 'exhast', 'muffler brocken'],
        ],
        'ar' => [
            'formal'   => ['عطل في نظام العادم'],
            'workshop' => ['الشكمان خربان', 'الشكمان يصوت', 'الشكمان طايح', 'صوت من الشكمان', 'الشكمان مثقوب'],
        ],
        'components' => ['Silencer', 'Exhaust pipe', 'Catalytic converter', 'Mountings', 'Gaskets'],
        'causes' => ['Corroded silencer or pipe', 'Failed exhaust mounting', 'Blown gasket at a joint',
                     'Blocked catalytic converter'],
        'inspection' => ['Listen along the system for leaks', 'Inspect mountings', 'Check for blockage / back pressure'],
        'actions' => ['visual_inspection', 'scan_diagnostics', 'road_test'],
    ],
    [
        'name' => 'Fuel system fault', 'name_ar' => 'عطل في نظام الوقود',
        'category' => 'engine', 'system' => 'Fuel system', 'subsystem' => 'Fuel delivery', 'discipline' => 'mechanical', 'risk' => 'critical',
        'description' => 'Fuel pump, filter, injector or tank fault — including fuel smell or leak.',
        'en' => [
            'syn'      => ['fuel pump fault', 'fuel leak', 'fuel smell', 'petrol smell', 'fuel filter blocked',
                           'fuel gauge not working', 'fuel system fault'],
            'workshop' => ['low fuel pressure', 'fuel pump whining', 'injector leaking', 'sender unit faulty'],
            'customer' => ['smell of petrol', 'fuel gauge is not correct', 'car cuts out when the tank is low',
                           'petrol leaking under the car'],
            'miss'     => ['fuel pmup', 'petrol smel', 'feul leak'],
        ],
        'ar' => [
            'formal'   => ['عطل في نظام الوقود'],
            'workshop' => ['ريحة بنزين', 'طرمبة البنزين خربانة', 'عداد البنزين ما يشتغل', 'تسريب بنزين'],
        ],
        'components' => ['Fuel pump', 'Fuel filter', 'Injectors', 'Fuel tank', 'Sender unit', 'Fuel lines'],
        'causes' => ['Failing fuel pump', 'Clogged fuel filter', 'Leaking injector seal',
                     'Faulty tank sender unit', 'Corroded fuel line'],
        'inspection' => ['Measure fuel pressure', 'Inspect lines for leaks', 'Scan for fuel-trim codes',
                         'Check gauge against actual level'],
        'actions' => ['replace_fuel_filter', 'repair_fuel_leak', 'clean_fuel_injector',
                      'replace_fuel_injector', 'scan_diagnostics', 'leak_test'],
    ],
];
