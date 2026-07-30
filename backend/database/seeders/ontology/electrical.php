<?php

/**
 * Electrical fault concepts.
 *
 * ELECTRICAL is the HIGHEST-frequency non-exposure category in this fleet at 4,124 events, ahead of
 * steering, tyres and brakes. Vocabulary depth here pays back faster than anywhere else.
 */

return [
    [
        'name' => 'Battery / won\'t start', 'name_ar' => 'بطارية ضعيفة / لا تعمل',
        'category' => 'electrical', 'category_label' => 'Electrical', 'category_label_ar' => 'الكهرباء',
        'system' => 'Electrical', 'subsystem' => 'Starting / charging', 'discipline' => 'electrical', 'risk' => 'critical',
        'description' => 'Vehicle will not crank or start — usually battery, terminals or starter.',
        'en' => [
            'syn'      => ['flat battery', 'dead battery', 'weak battery', 'no start', 'battery discharged', 'car will not crank'],
            'workshop' => ['battery is down', 'needs a jump', 'terminals corroded', 'battery not holding charge'],
            'customer' => ["car won't start", 'nothing happens when i turn the key', 'battery died',
                           'clicking sound when starting', 'needed a jump start', 'car is completely dead'],
            'miss'     => ['battrey dead', 'wont start', 'battery ded'],
        ],
        'ar' => [
            'formal'   => ['بطارية ضعيفة', 'تعذر تشغيل المركبة'],
            'workshop' => ['البطارية فاضية', 'ما يشتغل نهائي', 'يحتاج شحن', 'البطارية ماتت', 'ما يدور'],
        ],
        'components' => ['Battery', 'Terminals', 'Starter motor', 'Alternator', 'Earth straps'],
        'causes' => ['Battery at end of life', 'Corroded battery terminals', 'Parasitic drain',
                     'Failing alternator', 'Faulty starter motor'],
        'inspection' => ['Test battery state of health', 'Check terminals and earths',
                         'Measure charging voltage', 'Check for parasitic drain'],
        'actions' => ['replace_battery', 'clean_battery_terminals', 'replace_alternator',
                      'replace_starter_motor', 'repair_wiring'],
    ],
    [
        'name' => 'Alternator / charging fault', 'name_ar' => 'عطل في الدينمو',
        'category' => 'electrical', 'system' => 'Electrical', 'subsystem' => 'Charging', 'discipline' => 'electrical', 'risk' => 'critical',
        'description' => 'Charging system not maintaining battery voltage — the vehicle will eventually stop.',
        'en' => [
            'syn'      => ['charging fault', 'alternator failure', 'not charging', 'battery light on', 'dynamo fault'],
            'workshop' => ['alternator is dead', 'no output', 'belt slipping on the alternator'],
            'customer' => ['battery light came on while driving', 'red battery symbol on dash',
                           'car keeps losing power', 'lights get dim while driving'],
            'miss'     => ['alternater fault', 'altenator'],
        ],
        'ar' => [
            'formal'   => ['عطل في مولد الشحن'],
            'workshop' => ['الدينمو خربان', 'ما يشحن', 'لمبة البطارية طالعة', 'الدينمو ضعيف'],
        ],
        'components' => ['Alternator', 'Drive belt', 'Voltage regulator', 'Wiring'],
        'causes' => ['Failed alternator', 'Slipping / broken drive belt', 'Faulty voltage regulator', 'Wiring fault'],
        'inspection' => ['Measure charging voltage at idle and load', 'Inspect belt', 'Check alternator connections'],
        'actions' => ['replace_alternator', 'replace_accessory_belt', 'replace_battery', 'repair_wiring'],
    ],
    [
        'name' => 'Warning light on dash', 'name_ar' => 'إضاءة لمبة تحذير',
        'category' => 'electrical', 'system' => 'Electrical', 'subsystem' => 'Instrumentation', 'discipline' => 'electrical', 'risk' => 'moderate',
        'description' => 'A dashboard warning lamp is lit — the specific lamp determines urgency.',
        'en' => [
            'syn'      => ['dashboard warning light', 'warning lamp', 'dash light on', 'indicator light on'],
            'workshop' => ['light on the cluster', 'code stored'],
            'customer' => ['light on my dashboard', 'red light came on', 'orange light on the dash',
                           'symbol i do not recognise', 'warning sign appeared'],
            'miss'     => ['warning ligth', 'dash boad light'],
        ],
        'ar' => [
            'formal'   => ['إضاءة مؤشر تحذير'],
            'workshop' => ['لمبة طالعة في الطبلون', 'إشارة حمراء', 'فيه لمبة ما أعرفها', 'علامة طالعة'],
        ],
        'components' => ['Instrument cluster', 'Sensors', 'ECU', 'Wiring'],
        'causes' => ['Sensor fault', 'Low fluid level', 'Module fault', 'Wiring fault'],
        'inspection' => ['Identify which lamp', 'Scan all modules', 'Check related fluid levels'],
        'actions' => ['scan_diagnostics', 'reset_warning_light', 'repair_wiring'],
    ],
    [
        'name' => 'Wiring / fuse issue', 'name_ar' => 'عطل في الأسلاك أو الفيوز',
        'category' => 'electrical', 'system' => 'Electrical', 'subsystem' => 'Distribution', 'discipline' => 'electrical', 'risk' => 'moderate',
        'description' => 'Blown fuse, short circuit or damaged wiring causing loss of an electrical function.',
        'en' => [
            'syn'      => ['blown fuse', 'electrical short', 'wiring fault', 'fuse keeps blowing', 'short circuit'],
            'workshop' => ['fuse popped', 'chafed loom', 'short to earth'],
            'customer' => ['something electrical stopped working', 'fuse keeps blowing', 'smell of burning plastic'],
            'miss'     => ['blown fuze', 'wireing fault', 'electical short'],
        ],
        'ar' => [
            'formal'   => ['عطل في الأسلاك أو المصهر'],
            'workshop' => ['الفيوز محروق', 'فيه شورت', 'الأسلاك مقطوعة', 'ريحة حريق كهرباء'],
        ],
        'components' => ['Fuses', 'Relays', 'Wiring harness', 'Connectors'],
        'causes' => ['Short circuit', 'Chafed wiring', 'Corroded connector', 'Overloaded circuit'],
        'inspection' => ['Identify affected circuit', 'Check fuse and relay', 'Inspect loom for damage'],
        'actions' => ['replace_fuse', 'repair_wiring', 'visual_inspection'],
    ],
    [
        'name' => 'Power window / lock fault', 'name_ar' => 'عطل في الزجاج أو القفل الكهربائي',
        'category' => 'electrical', 'system' => 'Electrical', 'subsystem' => 'Body electrics', 'discipline' => 'electrical', 'risk' => 'routine',
        'description' => 'Electric window or central locking not operating.',
        'en' => [
            'syn'      => ['power window fault', 'window not working', 'central locking fault', 'door lock not working'],
            'workshop' => ['regulator failed', 'motor is dead', 'actuator clicking'],
            'customer' => ['window will not go up', 'window stuck down', 'door will not lock',
                           'window goes slow', 'central locking stopped working'],
            'miss'     => ['power windo', 'centeral locking'],
        ],
        'ar' => [
            'formal'   => ['عطل في نظام الزجاج الكهربائي'],
            'workshop' => ['الزجاج ما يطلع', 'الدريشة ما تشتغل', 'القفل ما يشتغل', 'الريموت ما يفتح'],
        ],
        'components' => ['Window regulator', 'Window motor', 'Door lock actuator', 'Switches'],
        'causes' => ['Failed window regulator', 'Failed lock actuator', 'Faulty switch', 'Broken door wiring'],
        'inspection' => ['Test switch operation', 'Check door loom continuity', 'Listen for motor'],
        'actions' => ['replace_window_regulator', 'repair_wiring', 'replace_fuse', 'visual_inspection'],
    ],
    [
        'name' => 'Central locking / key fob', 'name_ar' => 'عطل في الريموت',
        'category' => 'electrical', 'system' => 'Electrical', 'subsystem' => 'Security', 'discipline' => 'electrical', 'risk' => 'routine',
        'description' => 'Remote key does not lock, unlock or start the vehicle.',
        'en' => [
            'syn'      => ['key fob not working', 'remote not working', 'key not recognised', 'immobiliser fault'],
            'workshop' => ['fob battery flat', 'needs re-coding', 'no transponder read'],
            'customer' => ['remote does not work', 'key does not open the car', 'have to use the key manually',
                           'car does not recognise the key'],
            'miss'     => ['key fobb', 'remot not working'],
        ],
        'ar' => [
            'formal'   => ['عطل في المفتاح الذكي'],
            'workshop' => ['الريموت ما يشتغل', 'المفتاح ما يفتح', 'بطارية الريموت فاضية', 'ما يقرا المفتاح'],
        ],
        'components' => ['Key fob', 'Fob battery', 'Immobiliser', 'Receiver module'],
        'causes' => ['Flat fob battery', 'Fob needs re-coding', 'Faulty receiver', 'Damaged transponder'],
        'inspection' => ['Replace fob battery and retest', 'Test spare key', 'Scan immobiliser codes'],
        'actions' => ['program_key_fob', 'scan_diagnostics', 'repair_wiring'],
    ],
    [
        'name' => 'Sensor failure', 'name_ar' => 'عطل في حساس',
        'category' => 'electrical', 'system' => 'Electrical', 'subsystem' => 'Sensors', 'discipline' => 'electrical', 'risk' => 'moderate',
        'description' => 'A sensor is reporting implausible values or has failed outright.',
        'en' => [
            'syn'      => ['faulty sensor', 'sensor fault', 'sensor not reading', 'bad sensor signal'],
            'workshop' => ['sensor out of range', 'implausible signal', 'sensor code stored'],
            'customer' => ['garage said a sensor is broken', 'gauge is not reading correctly'],
            'miss'     => ['sensor failur', 'senser fault'],
        ],
        'ar' => [
            'formal'   => ['عطل في حساس'],
            'workshop' => ['الحساس خربان', 'الحساس ما يقرا', 'فيه حساس معطل'],
        ],
        'components' => ['Oxygen sensor', 'MAF sensor', 'Wheel speed sensor', 'Temperature sensor', 'ABS sensor'],
        'causes' => ['Sensor at end of life', 'Contaminated sensor', 'Damaged wiring', 'Connector corrosion'],
        'inspection' => ['Scan for codes', 'Compare live data to expected range', 'Inspect connector'],
        'actions' => ['scan_diagnostics', 'replace_oxygen_sensor', 'replace_abs_sensor', 'repair_wiring'],
    ],
    [
        'name' => 'Starter problem', 'name_ar' => 'عطل في السلف',
        'category' => 'electrical', 'system' => 'Electrical', 'subsystem' => 'Starting', 'discipline' => 'electrical', 'risk' => 'critical',
        'description' => 'Starter motor does not crank the engine, or cranks intermittently.',
        'en' => [
            'syn'      => ['starter motor fault', 'starter failure', 'will not crank', 'starter clicking'],
            'workshop' => ['solenoid clicking', 'starter dragging', 'no crank but battery good'],
            'customer' => ['just clicks when i turn the key', 'sometimes it starts sometimes not',
                           'engine does not turn over'],
            'miss'     => ['starter moter', 'stater problem'],
        ],
        'ar' => [
            'formal'   => ['عطل في محرك بدء التشغيل'],
            'workshop' => ['السلف ما يشتغل', 'السلف يطقطق', 'السلف ضعيف', 'ما يلف'],
        ],
        'components' => ['Starter motor', 'Solenoid', 'Battery', 'Earth strap'],
        'causes' => ['Failed starter motor', 'Faulty solenoid', 'Poor earth connection', 'Weak battery'],
        'inspection' => ['Confirm battery good first', 'Check voltage at starter while cranking', 'Check earths'],
        'actions' => ['replace_starter_motor', 'clean_battery_terminals', 'replace_battery', 'repair_wiring'],
    ],
];
