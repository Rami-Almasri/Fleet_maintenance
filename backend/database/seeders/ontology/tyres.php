<?php

/**
 * Tyre and wheel fault concepts. TYRE is 2,521 recorded events plus RIM 4,907 (exposure).
 *
 * The coverage report drove several of these directly: "alignment check", "wheel aligned",
 * "tires balance", "all tires change, alignment" and "4 rims" were all unmatched in real
 * maintenance notes, and every one of them is routine tyre-shop language.
 */

return [
    [
        'name' => 'Puncture / slow leak', 'name_ar' => 'ثقب في الإطار',
        'category' => 'tyres', 'category_label' => 'Tyres & wheels', 'category_label_ar' => 'الإطارات والجنوط',
        'system' => 'Wheels & tyres', 'subsystem' => 'Tyre', 'discipline' => 'mechanical', 'risk' => 'critical',
        'description' => 'Tyre losing pressure — from a puncture, valve or rim seal.',
        'en' => [
            'syn'      => ['puncture', 'flat tyre', 'flat tire', 'slow puncture', 'tyre losing air', 'deflated tyre'],
            'workshop' => ['nail in the tread', 'bead leak', 'valve leaking', 'puncture repair'],
            'customer' => ['tyre keeps going flat', 'tire losing air', 'flat tyre', 'wheel is flat',
                           'have to pump the tyre every day'],
            'miss'     => ['punctre', 'flat tyer', 'tire puncture', 'tyre puncher'],
        ],
        'ar' => [
            'formal'   => ['ثقب في الإطار', 'تسريب هواء من الإطار'],
            'workshop' => ['البنشر', 'الكفر ينقص هوا', 'الإطار فاضي', 'كفر بنشر', 'الكفر نايم'],
        ],
        'components' => ['Tyre', 'Valve', 'Rim', 'TPMS sensor'],
        'causes' => ['Puncture from road debris', 'Leaking valve', 'Corroded rim bead', 'Damaged sidewall'],
        'inspection' => ['Pressure check all four', 'Water/soap test for leak', 'Inspect tread and sidewall'],
        'actions' => ['repair_puncture', 'replace_tyre', 'tighten_wheel_nuts', 'visual_inspection'],
    ],
    [
        'name' => 'Wheel alignment', 'name_ar' => 'ضبط زوايا العجلات',
        'category' => 'tyres', 'system' => 'Wheels & tyres', 'subsystem' => 'Geometry', 'discipline' => 'mechanical', 'risk' => 'moderate',
        'description' => 'Wheel geometry out of specification, or an alignment being performed/checked.',
        'en' => [
            'syn'      => ['alignment', 'wheel alignment', 'four wheel alignment', 'tracking', 'alignment check',
                           'wheel aligned', 'alignment done', 'geometry check'],
            'workshop' => ['toe out of spec', 'camber adjustment', 'needs tracking', 'aligned the wheels',
                           'alignment and balancing'],
            'customer' => ['car does not go straight', 'steering wheel is crooked', 'needs alignment',
                           'garage did the alignment'],
            'miss'     => ['allignment', 'alighnment', 'wheel alingment', 'aligment'],
        ],
        'ar' => [
            'formal'   => ['ضبط زوايا العجلات'],
            'workshop' => ['ترصيص', 'الترصيص', 'ضبط الترصيص', 'سوينا ترصيص', 'الدركسون مايل يبغى ترصيص'],
        ],
        'components' => ['Tie rods', 'Control arms', 'Suspension bushes', 'Wheels'],
        'causes' => ['Kerb or pothole impact', 'Worn suspension component', 'After suspension repair'],
        'inspection' => ['Measure toe, camber, caster', 'Check tyre wear pattern', 'Road test for pull'],
        'actions' => ['align_wheels', 'replace_tie_rod', 'balance_wheels', 'road_test', 'measure_component'],
    ],
    [
        'name' => 'Wheel balancing', 'name_ar' => 'ترصيص العجلات',
        'category' => 'tyres', 'system' => 'Wheels & tyres', 'subsystem' => 'Balance', 'discipline' => 'mechanical', 'risk' => 'moderate',
        'description' => 'Wheel out of balance, or balancing being performed.',
        'en' => [
            'syn'      => ['balancing', 'wheel balancing', 'tyre balancing', 'tires balance', 'wheel balance',
                           'balanced the wheels', 'dynamic balancing'],
            'workshop' => ['needs weights', 'out of balance', 'balanced all four'],
            'customer' => ['car vibrates at speed', 'wheel shakes on the highway', 'needs balancing'],
            'miss'     => ['balancng', 'wheel balacing', 'tyre balence'],
        ],
        'ar' => [
            'formal'   => ['موازنة العجلات'],
            'workshop' => ['بلنس', 'البلنس', 'سوينا بلنس', 'الكفرات تبغى بلنس', 'توازن الكفرات'],
        ],
        'components' => ['Wheels', 'Tyres', 'Balance weights'],
        'causes' => ['Lost balance weight', 'New tyre fitted', 'Uneven tyre wear', 'Buckled rim'],
        'inspection' => ['Balance check on machine', 'Inspect rim for damage', 'Road test at speed'],
        'actions' => ['balance_wheels', 'replace_tyre', 'align_wheels', 'road_test'],
    ],
    [
        'name' => 'Worn / bald tyre', 'name_ar' => 'تآكل الإطار',
        'category' => 'tyres', 'system' => 'Wheels & tyres', 'subsystem' => 'Tyre', 'discipline' => 'mechanical', 'risk' => 'critical',
        'description' => 'Tread depth at or below the legal limit.',
        'en' => [
            'syn'      => ['worn tyre', 'bald tyre', 'tyre wear', 'low tread', 'tyres need changing',
                           'tyre below limit', 'all tires change'],
            'workshop' => ['down to the markers', 'canvas showing', 'tyres are finished', 'changed all four'],
            'customer' => ['tyres look worn', 'garage said tyres need changing', 'tyres are bald'],
            'miss'     => ['worn tyres', 'bald tires', 'tires worn out', 'tiers need to change'],
        ],
        'ar' => [
            'formal'   => ['تآكل الإطارات'],
            'workshop' => ['الكفرات مستهلكة', 'الكفرات خلصت', 'الكفر ملسان', 'يبغى كفرات جديدة'],
        ],
        'components' => ['Tyres'],
        'causes' => ['Normal wear', 'Wheel alignment out of specification', 'Under-inflation', 'Aggressive driving'],
        'inspection' => ['Measure tread depth all four', 'Check wear evenness', 'Check DOT age'],
        'actions' => ['replace_tyre', 'align_wheels', 'balance_wheels', 'measure_component'],
    ],
    [
        'name' => 'Uneven tyre wear', 'name_ar' => 'تآكل غير منتظم للإطار',
        'category' => 'tyres', 'system' => 'Wheels & tyres', 'subsystem' => 'Tyre', 'discipline' => 'mechanical', 'risk' => 'moderate',
        'description' => 'Tread wearing faster on one edge or in patches — a symptom, not a cause.',
        'en' => [
            'syn'      => ['uneven wear', 'inner edge wear', 'feathering', 'cupping', 'one side wearing'],
            'workshop' => ['wearing on the inside', 'scalloped', 'camber wear'],
            'customer' => ['tyre worn on one side only', 'inside of the tyre is finished'],
            'miss'     => ['uneven tyre ware', 'uneaven wear'],
        ],
        'ar' => [
            'formal'   => ['تآكل غير منتظم للإطارات'],
            'workshop' => ['الكفر ياكل من جهة', 'ياكل من الداخل', 'التآكل مو متساوي'],
        ],
        'components' => ['Tyres', 'Suspension geometry', 'Shock absorbers'],
        'causes' => ['Wheel alignment out of specification', 'Worn shock absorber', 'Incorrect tyre pressure', 'Worn bush'],
        'inspection' => ['Note wear pattern', 'Alignment measurement', 'Check shocks and bushes'],
        'actions' => ['align_wheels', 'replace_tyre', 'replace_shock_absorber', 'replace_bush', 'rotate_tyres'],
    ],
    [
        'name' => 'TPMS / tyre-pressure warning', 'name_ar' => 'إنذار ضغط الإطارات',
        'category' => 'tyres', 'system' => 'Wheels & tyres', 'subsystem' => 'TPMS', 'discipline' => 'electrical', 'risk' => 'moderate',
        'description' => 'Tyre-pressure monitoring warning lit — low pressure or a sensor fault.',
        'en' => [
            'syn'      => ['TPMS warning', 'tyre pressure light', 'pressure warning', 'TPMS fault', 'low pressure warning'],
            'workshop' => ['sensor battery dead', 'needs relearn', 'TPMS not reading'],
            'customer' => ['tyre pressure light on', 'warning about tyre pressure', 'yellow tyre symbol on dash'],
            'miss'     => ['tpms ligth', 'tyre presure warning'],
            'abbr'     => ['TPMS'],
        ],
        'ar' => [
            'formal'   => ['إنذار ضغط الإطارات'],
            'workshop' => ['لمبة ضغط الكفرات', 'إشارة الهوا طالعة', 'حساس الهوا خربان'],
        ],
        'components' => ['TPMS sensors', 'Receiver module', 'Tyres'],
        'causes' => ['Genuinely low pressure', 'TPMS sensor battery dead', 'Sensor not relearned after tyre change'],
        'inspection' => ['Check all four pressures', 'Scan TPMS sensor IDs', 'Relearn and retest'],
        'actions' => ['reset_tpms', 'replace_tpms_sensor', 'repair_puncture', 'scan_diagnostics'],
    ],
    [
        'name' => 'Damaged rim', 'name_ar' => 'تلف الجنط',
        'category' => 'tyres', 'system' => 'Wheels & tyres', 'subsystem' => 'Rim', 'discipline' => 'bodywork', 'risk' => 'moderate',
        'description' => 'Rim buckled, cracked, kerbed or refinished. High volume in rental fleets.',
        'en' => [
            'syn'      => ['rim damage', 'buckled rim', 'kerbed rim', 'rim scratch', 'cracked rim',
                           'rims painted', '4 rims', 'rim refurbishment'],
            'workshop' => ['rim is buckled', 'kerb rash', 'rims painted', 'refinished the rims'],
            'customer' => ['rim is scratched', 'hit the kerb', 'wheel is damaged', 'rims look bad'],
            'miss'     => ['rim scrach', 'damaged rims', 'rim damge'],
        ],
        'ar' => [
            'formal'   => ['تلف الجنوط'],
            'workshop' => ['الجنط مخدوش', 'الجنوط منحنية', 'صبغ الجنوط', 'الجنط مكسور'],
        ],
        'components' => ['Rim', 'Tyre'],
        'causes' => ['Kerb impact', 'Pothole impact', 'Corrosion'],
        'inspection' => ['Inspect rim for buckle and cracks', 'Check bead seal', 'Balance check'],
        'actions' => ['balance_wheels', 'replace_tyre', 'paint_panel', 'visual_inspection'],
    ],
    [
        'name' => 'Tyre rotation', 'name_ar' => 'تدوير الإطارات',
        'category' => 'tyres', 'system' => 'Wheels & tyres', 'subsystem' => 'Maintenance', 'discipline' => 'mechanical', 'risk' => 'routine',
        'description' => 'Scheduled repositioning of tyres to even out wear.',
        'en' => [
            'syn'      => ['tyre rotation', 'rotate tyres', 'tire rotation', 'swapped the tyres'],
            'workshop' => ['rotated front to back', 'cross rotation'],
            'customer' => ['change tyre positions', 'move the tyres around'],
            'miss'     => ['tire rotaion', 'tyre rotatin'],
        ],
        'ar' => [
            'formal'   => ['تدوير الإطارات'],
            'workshop' => ['تبديل أماكن الكفرات', 'تدوير الكفرات'],
        ],
        'components' => ['Tyres', 'Wheels'],
        'causes' => ['Scheduled service interval'],
        'inspection' => ['Check tread depth before and after', 'Torque wheel nuts'],
        'actions' => ['rotate_tyres', 'tighten_wheel_nuts', 'balance_wheels'],
    ],
];
