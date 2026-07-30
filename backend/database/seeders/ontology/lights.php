<?php

/**
 * Lighting, wipers and washers. LIGHTS is 2,350 recorded events — third-highest non-exposure
 * category, and almost entirely quick, high-volume work.
 */

return [
    [
        'name' => 'Headlight out', 'name_ar' => 'عطل في المصابيح الأمامية',
        'category' => 'lights', 'category_label' => 'Lights & visibility', 'category_label_ar' => 'الإضاءة والرؤية',
        'system' => 'Lighting', 'subsystem' => 'Front lighting', 'discipline' => 'electrical', 'risk' => 'critical',
        'description' => 'Headlamp not working, dim, or with water ingress.',
        'en' => [
            'syn'      => ['headlight not working', 'headlamp out', 'bulb blown', 'low beam not working',
                           'high beam fault', 'headlight dim', 'lights not working'],
            'workshop' => ['bulb gone', 'ballast failed', 'moisture in the lamp', 'replaced the headlight'],
            'customer' => ['one headlight is not working', 'light is very dim', 'headlight has water inside'],
            'miss'     => ['head light out', 'headlite', 'headlamp nout working'],
        ],
        'ar' => [
            'formal'   => ['عطل في المصابيح الأمامية'],
            'workshop' => ['الشمعة ما تشتغل', 'اللمبة محروقة', 'الكشاف ما يشتغل', 'فيه ماي في الشمعة'],
        ],
        'components' => ['Headlamp unit', 'Bulb', 'Ballast', 'Wiring', 'Fuse'],
        'causes' => ['Blown bulb', 'Failed ballast / LED driver', 'Water ingress into the lamp', 'Blown fuse'],
        'inspection' => ['Test low and high beam', 'Check fuse and connector', 'Inspect lens for moisture'],
        'actions' => ['replace_headlight', 'replace_fuse', 'repair_wiring', 'visual_inspection'],
    ],
    [
        'name' => 'Tail / brake light out', 'name_ar' => 'عطل في الأضواء الخلفية',
        'category' => 'lights', 'system' => 'Lighting', 'subsystem' => 'Rear lighting', 'discipline' => 'electrical', 'risk' => 'critical',
        'description' => 'Rear, brake or reverse lamp not working.',
        'en' => [
            'syn'      => ['brake light not working', 'tail light out', 'rear light fault', 'reverse light out',
                           'stop light not working', 'third brake light'],
            'workshop' => ['brake switch faulty', 'bulb holder corroded', 'cluster needs replacing'],
            'customer' => ['brake light does not work', 'back light is out', 'reverse light not working'],
            'miss'     => ['tail ligth', 'break light out', 'rear ligth'],
        ],
        'ar' => [
            'formal'   => ['عطل في المصابيح الخلفية'],
            'workshop' => ['الستوب ما يشتغل', 'اللمبة الخلفية محروقة', 'لمبة الرجوع ما تشتغل'],
        ],
        'components' => ['Tail lamp', 'Brake light switch', 'Bulb holder', 'Wiring'],
        'causes' => ['Blown bulb', 'Faulty brake light switch', 'Corroded bulb holder', 'Wiring fault'],
        'inspection' => ['Test with assistant on the pedal', 'Check brake switch', 'Inspect holder for corrosion'],
        'actions' => ['replace_tail_light', 'replace_fuse', 'repair_wiring', 'visual_inspection'],
    ],
    [
        'name' => 'Indicator fault', 'name_ar' => 'عطل في الإشارات',
        'category' => 'lights', 'system' => 'Lighting', 'subsystem' => 'Signalling', 'discipline' => 'electrical', 'risk' => 'critical',
        'description' => 'Turn signal not working or flashing fast (a blown bulb indication).',
        'en' => [
            'syn'      => ['indicator not working', 'turn signal fault', 'hazard lights not working',
                           'indicator flashing fast', 'blinker out', 'signal light fault'],
            'workshop' => ['hyper flash', 'relay clicking fast', 'bulb gone one side'],
            'customer' => ['indicator does not work', 'signal blinks very fast', 'hazards do not work'],
            'miss'     => ['indicater fault', 'turn signel', 'blinkr'],
        ],
        'ar' => [
            'formal'   => ['عطل في إشارات الانعطاف'],
            'workshop' => ['الإشارة ما تشتغل', 'الإشارة تلمع بسرعة', 'الغماز خربان', 'الفلاشر ما يشتغل'],
        ],
        'components' => ['Indicator bulbs', 'Flasher relay', 'Stalk switch', 'Wiring'],
        'causes' => ['Blown indicator bulb', 'Faulty flasher relay', 'Faulty stalk switch', 'Bad earth'],
        'inspection' => ['Test all four corners plus hazards', 'Note flash rate', 'Check earths'],
        'actions' => ['replace_tail_light', 'replace_headlight', 'replace_fuse', 'repair_wiring'],
    ],
    [
        'name' => 'Foggy / dim lights', 'name_ar' => 'ضبابية العاكسات',
        'category' => 'lights', 'system' => 'Lighting', 'subsystem' => 'Lens condition', 'discipline' => 'bodywork', 'risk' => 'moderate',
        'description' => 'Headlamp lenses yellowed, hazed or fogged, cutting light output.',
        'en' => [
            'syn'      => ['foggy headlights', 'yellowed lenses', 'hazy headlights', 'cloudy headlight',
                           'headlight polishing', 'dim output'],
            'workshop' => ['polished the lenses', 'lens is oxidised', 'restored the headlights'],
            'customer' => ['headlights look cloudy', 'lights are yellow', 'not enough light at night'],
            'miss'     => ['foggy head lights', 'hazey lights'],
        ],
        'ar' => [
            'formal'   => ['ضبابية عدسات المصابيح'],
            'workshop' => ['الشمعات مصفرة', 'الشمعة معتمة', 'تلميع الشمعات', 'الإضاءة ضعيفة بالليل'],
        ],
        'components' => ['Headlamp lens', 'Bulbs'],
        'causes' => ['UV oxidation of the lens', 'Internal moisture', 'Aged bulbs'],
        'inspection' => ['Assess lens clarity', 'Compare output side to side'],
        'actions' => ['replace_headlight', 'visual_inspection'],
    ],
    [
        'name' => 'Wiper / washer fault', 'name_ar' => 'عطل في المساحات',
        'category' => 'lights', 'system' => 'Visibility', 'subsystem' => 'Wipers & washers', 'discipline' => 'electrical', 'risk' => 'critical',
        'description' => 'Wipers not working, smearing, or washers not spraying.',
        'en' => [
            'syn'      => ['wiper not working', 'wipers smearing', 'washer not working', 'wiper blades worn',
                           'no washer fluid', 'wiper motor fault', 'front wiper', 'rear wiper not working'],
            'workshop' => ['blades are perished', 'washer pump dead', 'jets blocked', 'linkage seized'],
            'customer' => ['wipers do not clean properly', 'water does not spray', 'wipers leave marks',
                           'wiper stopped working'],
            'miss'     => ['wipper', 'wiperblades', 'washer not workin'],
        ],
        'ar' => [
            'formal'   => ['عطل في المساحات أو رشاش الزجاج'],
            'workshop' => ['المساحات ما تشتغل', 'المساحة تخربش', 'ما يطلع ماي', 'الرشاش مسدود', 'المساحات مستهلكة'],
        ],
        'components' => ['Wiper blades', 'Wiper motor', 'Linkage', 'Washer pump', 'Washer jets'],
        'causes' => ['Perished wiper blades', 'Failed washer pump', 'Blocked washer jet', 'Seized wiper linkage',
                     'Empty washer bottle'],
        'inspection' => ['Test all wiper speeds', 'Test washer spray pattern', 'Inspect blade rubber'],
        'actions' => ['replace_wiper_blades', 'replace_fuse', 'repair_wiring', 'visual_inspection'],
    ],
];
