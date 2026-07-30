<?php

/**
 * Body, glass and accident-damage concepts.
 *
 * BODY is 10,706 recorded events and RIM 4,907 — by far the largest categories in this fleet, and
 * both are EXPOSURE damage (customer-caused) rather than repair-quality failures. They are excluded
 * from comeback and first-time-fix metrics for exactly that reason, but they dominate what people
 * actually write, so vocabulary coverage here is the single biggest win available.
 *
 * "accident", "accident right side damaged (doors)", "bonnet and fenders with frotn bumper painting"
 * and "4 rims painted" all came from the coverage report as unmatched real notes.
 */

return [
    [
        'name' => 'Accident damage', 'name_ar' => 'أضرار حادث',
        'category' => 'bodywork', 'category_label' => 'Body & exterior', 'category_label_ar' => 'الهيكل والمظهر الخارجي',
        'system' => 'Body', 'subsystem' => 'Structure', 'discipline' => 'bodywork', 'risk' => 'critical',
        'description' => 'Collision damage — may involve structure, panels, glass and safety systems together.',
        'en' => [
            'syn'      => ['accident', 'collision damage', 'crash damage', 'accident damage', 'hit',
                           'accident repair', 'damaged in accident'],
            'workshop' => ['front end damage', 'rear end hit', 'side impact', 'chassis pulled', 'on the jig'],
            'customer' => ['had an accident', 'car was hit', 'someone crashed into the car',
                           'accident on the right side', 'front of the car is damaged'],
            'miss'     => ['accidnet', 'acident', 'accedent damage'],
        ],
        'ar' => [
            'formal'   => ['أضرار ناتجة عن حادث'],
            'workshop' => ['حادث', 'السيارة عليها حادث', 'ضرب من الجنب', 'حادث قدامي', 'مصدومة'],
        ],
        'components' => ['Bumpers', 'Panels', 'Chassis', 'Lights', 'Glass', 'Airbags'],
        'causes' => ['Collision', 'Kerb or barrier impact'],
        'inspection' => ['Photograph all damage', 'Check structural alignment', 'Check airbag status', 'Check for fluid leaks'],
        'actions' => ['repair_body_panel', 'replace_body_panel', 'paint_panel', 'replace_windscreen',
                      'replace_headlight', 'align_wheels', 'visual_inspection'],
    ],
    [
        'name' => 'Dent', 'name_ar' => 'انبعاج',
        'category' => 'bodywork', 'system' => 'Body', 'subsystem' => 'Panel', 'discipline' => 'bodywork', 'risk' => 'routine',
        'description' => 'Panel deformation without paint breach, or with.',
        'en' => [
            'syn'      => ['dent', 'dents', 'dented panel', 'ding', 'body dent', 'denting'],
            'workshop' => ['pushed the dent out', 'paintless dent removal', 'denting and painting'],
            'customer' => ['there is a dent', 'car has a dent on the door', 'someone dented my car'],
            'miss'     => ['dennt', 'dentt', 'denting work'],
        ],
        'ar' => [
            'formal'   => ['انبعاج في الهيكل'],
            'workshop' => ['صدمة', 'دعبلة', 'فيه انبعاج', 'سمكرة', 'يبغى سمكرة'],
        ],
        'components' => ['Body panels', 'Doors', 'Bumpers', 'Bonnet'],
        'causes' => ['Impact', 'Parking damage', 'Hail'],
        'inspection' => ['Photograph and measure damage', 'Check paint integrity'],
        'actions' => ['repair_body_panel', 'paint_panel', 'replace_body_panel', 'visual_inspection'],
    ],
    [
        'name' => 'Scratch', 'name_ar' => 'خدش',
        'category' => 'bodywork', 'system' => 'Body', 'subsystem' => 'Paint', 'discipline' => 'bodywork', 'risk' => 'routine',
        'description' => 'Surface scratch to paint or clear coat.',
        'en' => [
            'syn'      => ['scratch', 'scratches', 'scratched paint', 'surface scratch', 'minor surface scratch',
                           'scuff', 'paint scratch'],
            'workshop' => ['polished it out', 'through to the primer', 'light scratches'],
            'customer' => ['car is scratched', 'there are scratches on the door', 'someone scratched the car'],
            'miss'     => ['scratchs', 'scrach', 'scrached'],
        ],
        'ar' => [
            'formal'   => ['خدوش في الطلاء'],
            'workshop' => ['خدوش', 'مخربش', 'فيه خدش', 'خربشة', 'خدوش خفيفة'],
        ],
        'components' => ['Paint', 'Clear coat', 'Body panels'],
        'causes' => ['Contact damage', 'Vandalism', 'Car-wash damage'],
        'inspection' => ['Photograph', 'Assess depth against primer'],
        'actions' => ['paint_panel', 'repair_body_panel', 'visual_inspection'],
    ],
    [
        'name' => 'Paint damage', 'name_ar' => 'تلف الطلاء',
        'category' => 'bodywork', 'system' => 'Body', 'subsystem' => 'Paint', 'discipline' => 'bodywork', 'risk' => 'routine',
        'description' => 'Paint peeling, faded, chipped or requiring refinish.',
        'en' => [
            'syn'      => ['paint damage', 'paint peeling', 'faded paint', 'paint chip', 'repainting',
                           'painting', 'respray', 'painting in oven', 'refinish'],
            'workshop' => ['in the oven', 'blending the panel', 'clear coat peeling', 'painting the bumper'],
            'customer' => ['paint is coming off', 'colour has faded', 'needs painting'],
            'miss'     => ['paiting', 'painting damge', 'paint pealing'],
        ],
        'ar' => [
            'formal'   => ['تلف في الطلاء'],
            'workshop' => ['صبغ', 'يبغى صبغ', 'الصبغ مقشر', 'دهان', 'صبغ الرفرف'],
        ],
        'components' => ['Paint', 'Primer', 'Body panels', 'Bumpers'],
        'causes' => ['UV / heat degradation', 'Impact chip', 'Poor previous repair'],
        'inspection' => ['Assess affected panels', 'Check for underlying corrosion'],
        'actions' => ['paint_panel', 'repair_body_panel', 'visual_inspection'],
    ],
    [
        'name' => 'Bumper damage', 'name_ar' => 'تلف الصدام',
        'category' => 'bodywork', 'system' => 'Body', 'subsystem' => 'Bumper', 'discipline' => 'bodywork', 'risk' => 'routine',
        'description' => 'Front or rear bumper cracked, detached or scuffed.',
        'en' => [
            'syn'      => ['bumper damage', 'broken bumper', 'cracked bumper', 'bumper scuffed',
                           'front bumper', 'rear bumper', 'bumper loose'],
            'workshop' => ['bumper clips broken', 'refitted the bumper', 'bumper needs painting'],
            'customer' => ['bumper is damaged', 'bumper is hanging', 'back bumper is cracked'],
            'miss'     => ['bumber damage', 'frotn bumper', 'bumpper'],
        ],
        'ar' => [
            'formal'   => ['تلف الصدام'],
            'workshop' => ['الصدام مكسور', 'الدعامية مكسورة', 'الصدام طايح', 'صدام خلفي مكسور'],
        ],
        'components' => ['Bumper cover', 'Bumper reinforcement', 'Clips', 'Parking sensors'],
        'causes' => ['Parking impact', 'Collision', 'Broken mounting clips'],
        'inspection' => ['Check mounting points', 'Check parking sensors still function'],
        'actions' => ['replace_body_panel', 'repair_body_panel', 'paint_panel', 'visual_inspection'],
    ],
    [
        'name' => 'Windscreen crack / chip', 'name_ar' => 'شرخ في الزجاج الأمامي',
        'category' => 'bodywork', 'system' => 'Glass', 'subsystem' => 'Windscreen', 'discipline' => 'bodywork', 'risk' => 'critical',
        'description' => 'Windscreen chipped or cracked — a crack in the driver’s view fails inspection.',
        'en' => [
            'syn'      => ['windscreen crack', 'windshield crack', 'cracked windscreen', 'windscreen chip',
                           'stone chip', 'glass crack', 'windshield issue', 'broken windscreen'],
            'workshop' => ['crack spreading', 'chip repair', 'replaced the screen'],
            'customer' => ['crack in the windscreen', 'stone hit the windshield', 'glass is cracked',
                           'windshield has a chip'],
            'miss'     => ['windshiled crack', 'windscren crack', 'wind shield crack'],
        ],
        'ar' => [
            'formal'   => ['شرخ في الزجاج الأمامي'],
            'workshop' => ['الزجاج مشروخ', 'شرخ في القزاز', 'القزاز الأمامي مكسور', 'فيه كسر بالزجاج'],
        ],
        'components' => ['Windscreen', 'Glass seal', 'Rain sensor', 'ADAS camera'],
        'causes' => ['Stone impact', 'Thermal stress', 'Collision'],
        'inspection' => ['Locate damage relative to driver view', 'Check ADAS camera mounting',
                         'Assess repair vs replace'],
        'actions' => ['replace_windscreen', 'visual_inspection'],
    ],
    [
        'name' => 'Broken glass / window', 'name_ar' => 'كسر في الزجاج',
        'category' => 'bodywork', 'system' => 'Glass', 'subsystem' => 'Side / rear glass', 'discipline' => 'bodywork', 'risk' => 'moderate',
        'description' => 'Side or rear glass broken or shattered.',
        'en' => [
            'syn'      => ['broken glass', 'broken window', 'shattered window', 'smashed glass',
                           'rear glass broken', 'side window broken'],
            'workshop' => ['glass everywhere', 'vacuumed the door', 'replaced the door glass'],
            'customer' => ['window is broken', 'someone smashed the window', 'back glass is broken'],
            'miss'     => ['broken glas', 'shatterd window'],
        ],
        'ar' => [
            'formal'   => ['كسر في زجاج المركبة'],
            'workshop' => ['الزجاج مكسور', 'القزاز مكسور', 'زجاج الباب مكسور', 'كسروا الزجاج'],
        ],
        'components' => ['Door glass', 'Rear screen', 'Quarter glass', 'Window regulator'],
        'causes' => ['Break-in', 'Impact', 'Vandalism'],
        'inspection' => ['Check for glass debris in door', 'Test window operation after replacement'],
        'actions' => ['replace_windscreen', 'replace_window_regulator', 'clean_interior', 'visual_inspection'],
    ],
    [
        'name' => 'Broken / loose mirror', 'name_ar' => 'مرآة مكسورة',
        'category' => 'bodywork', 'system' => 'Body', 'subsystem' => 'Mirror', 'discipline' => 'bodywork', 'risk' => 'moderate',
        'description' => 'Side mirror broken, loose, or its glass or motor damaged.',
        'en' => [
            'syn'      => ['broken mirror', 'side mirror damage', 'mirror hanging', 'mirror glass broken',
                           'wing mirror', 'mirror not folding'],
            'workshop' => ['mirror motor gone', 'housing cracked', 'refitted the mirror'],
            'customer' => ['side mirror is broken', 'mirror is hanging off', 'mirror does not fold'],
            'miss'     => ['broken miror', 'side miror'],
        ],
        'ar' => [
            'formal'   => ['تلف المرآة الجانبية'],
            'workshop' => ['المراية مكسورة', 'المراية طايحة', 'مراية الجنب مكسورة'],
        ],
        'components' => ['Mirror housing', 'Mirror glass', 'Mirror motor', 'Indicator lens'],
        'causes' => ['Impact', 'Failed fold motor', 'Vandalism'],
        'inspection' => ['Test fold and adjust functions', 'Check indicator operation'],
        'actions' => ['replace_mirror', 'repair_wiring', 'paint_panel', 'visual_inspection'],
    ],
    [
        'name' => 'Rust / corrosion', 'name_ar' => 'صدأ',
        'category' => 'bodywork', 'system' => 'Body', 'subsystem' => 'Panel', 'discipline' => 'bodywork', 'risk' => 'moderate',
        'description' => 'Corrosion on panels, arches or underbody.',
        'en' => [
            'syn'      => ['rust', 'corrosion', 'rusty panel', 'rust spots', 'rusted through'],
            'workshop' => ['cut and welded', 'surface rust', 'rot in the arch'],
            'customer' => ['there is rust on the car', 'paint is bubbling'],
            'miss'     => ['russt', 'corosion', 'rusted'],
        ],
        'ar' => [
            'formal'   => ['تآكل وصدأ في الهيكل'],
            'workshop' => ['صدأ', 'فيه صدأ', 'الحديد صدي', 'صدأ في الرفرف'],
        ],
        'components' => ['Body panels', 'Wheel arches', 'Underbody', 'Door bottoms'],
        'causes' => ['Paint breach then moisture', 'Salt exposure', 'Poor previous repair'],
        'inspection' => ['Probe affected area', 'Check underbody and arches'],
        'actions' => ['repair_body_panel', 'paint_panel', 'replace_body_panel', 'visual_inspection'],
    ],
    [
        'name' => 'Door / panel misalignment', 'name_ar' => 'عدم استواء الأبواب',
        'category' => 'bodywork', 'system' => 'Body', 'subsystem' => 'Door', 'discipline' => 'bodywork', 'risk' => 'routine',
        'description' => 'Door, bonnet or boot not aligned, not closing flush, or dropping on its hinge.',
        'en' => [
            'syn'      => ['door misalignment', 'door not closing', 'panel gap', 'door dropped',
                           'boot not closing', 'bonnet misaligned', 'trunk door'],
            'workshop' => ['adjusted the striker', 'hinge is worn', 'gaps are out'],
            'customer' => ['door does not close properly', 'have to slam the door', 'boot will not shut'],
            'miss'     => ['door misalignmnet', 'door not closeing'],
        ],
        'ar' => [
            'formal'   => ['عدم استواء الأبواب أو الألواح'],
            'workshop' => ['الباب ما يسكر', 'الباب نازل', 'الشنطة ما تسكر', 'الباب مو مظبوط'],
        ],
        'components' => ['Door hinges', 'Striker', 'Latch', 'Boot lid', 'Bonnet'],
        'causes' => ['Worn hinge', 'Accident damage', 'Striker out of adjustment'],
        'inspection' => ['Check panel gaps', 'Test latch operation', 'Inspect hinges for wear'],
        'actions' => ['repair_body_panel', 'lubricate_hinges', 'replace_body_panel', 'visual_inspection'],
    ],
];
