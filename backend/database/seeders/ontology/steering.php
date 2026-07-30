<?php

/**
 * Steering fault concepts.
 *
 * STEERING is 2,870 recorded events — the third-highest non-exposure category in this fleet, ahead
 * of brakes. Worth knowing before assuming engine and brakes dominate a rental operation.
 */

return [
    [
        'name' => 'Steering vibration', 'name_ar' => 'اهتزاز في المقود',
        'category' => 'suspension', 'category_label' => 'Steering & suspension', 'category_label_ar' => 'التوجيه والتعليق',
        'system' => 'Steering', 'subsystem' => 'Wheel / tyre balance', 'discipline' => 'mechanical', 'risk' => 'moderate',
        'description' => 'Vibration felt through the steering wheel, usually speed-dependent.',
        'en' => [
            'syn'      => ['steering wheel vibration', 'wheel shake', 'shaking steering', 'wobble at speed', 'steering shudder'],
            'workshop' => ['out of balance at 100', 'wheel needs balancing', 'buckled front rim'],
            'customer' => ['steering shakes', 'wheel vibrates at highway speed', 'car shakes at high speed',
                           'vibration in the steering wheel', 'steering wheel shakes when driving fast'],
            'miss'     => ['steering vibration', 'steering wheal shake', 'wobling steering'],
        ],
        'ar' => [
            'formal'   => ['اهتزاز في المقود'],
            'workshop' => ['الدركسون يرجف', 'اهتزاز في السرعة العالية', 'الطارة ترجف', 'يرجف على السرعة'],
        ],
        'components' => ['Wheels', 'Tyres', 'Wheel bearings', 'Tie rods', 'Ball joints'],
        'causes' => ['Wheel out of balance', 'Buckled or damaged rim', 'Uneven tyre wear',
                     'Worn wheel bearing', 'Worn tie-rod end'],
        'inspection' => ['Note speed at which vibration appears', 'Balance check all four wheels',
                         'Inspect rims for damage', 'Check steering play'],
        'actions' => ['balance_wheels', 'align_wheels', 'replace_tyre', 'replace_wheel_bearing',
                      'replace_tie_rod', 'road_test'],
    ],
    [
        'name' => 'Hard / heavy steering', 'name_ar' => 'ثقل في المقود',
        'category' => 'suspension', 'system' => 'Steering', 'subsystem' => 'Power steering', 'discipline' => 'mechanical', 'risk' => 'critical',
        'description' => 'Steering requires abnormal effort — power assistance reduced or lost.',
        'en' => [
            'syn'      => ['heavy steering', 'stiff steering', 'hard to steer', 'power steering failure', 'no power steering'],
            'workshop' => ['lost assist', 'pump whining', 'rack is tight'],
            'customer' => ['the car feels heavy', 'steering is stiff', 'hard to turn the wheel',
                           'steering feels tight', 'difficult to turn when parking'],
            'miss'     => ['heavey steering', 'hard steering wheel'],
        ],
        'ar' => [
            'formal'   => ['ثقل في المقود', 'فقدان مساعدة التوجيه'],
            'workshop' => ['الدركسون ثقيل', 'صعب في اللف', 'الطارة ثقيلة', 'ما فيه باور'],
        ],
        'components' => ['Power steering pump', 'Steering rack', 'Drive belt', 'Power steering fluid', 'EPS motor'],
        'causes' => ['Low power-steering fluid', 'Failing power-steering pump', 'Slipping / broken drive belt',
                     'Seized steering rack', 'EPS electrical fault'],
        'inspection' => ['Check fluid level', 'Check belt condition and tension', 'Listen for pump noise', 'Scan EPS codes'],
        'actions' => ['top_up_power_steering_fluid', 'replace_power_steering_pump', 'replace_accessory_belt',
                      'scan_diagnostics', 'road_test'],
    ],
    [
        'name' => 'Loose steering', 'name_ar' => 'خلخلة في المقود',
        'category' => 'suspension', 'system' => 'Steering', 'subsystem' => 'Linkage', 'discipline' => 'mechanical', 'risk' => 'critical',
        'description' => 'Excessive free play at the steering wheel before the wheels respond.',
        'en' => [
            'syn'      => ['steering play', 'loose steering wheel', 'excessive free play', 'vague steering', 'wandering steering'],
            'workshop' => ['play in the rack', 'tie rods are shot', 'slop in the steering'],
            'customer' => ['steering feels loose', 'wheel moves before the car turns', 'car wanders on the road',
                           'have to keep correcting the steering'],
            'miss'     => ['lose steering', 'loose stearing'],
        ],
        'ar' => [
            'formal'   => ['خلخلة في المقود'],
            'workshop' => ['الدركسون فيه خلعة', 'الطارة رخوة', 'فيه لعب بالدركسون', 'السيارة تتمايل'],
        ],
        'components' => ['Tie-rod ends', 'Steering rack', 'Ball joints', 'Steering column', 'Idler arm'],
        'causes' => ['Worn tie-rod ends', 'Worn steering rack', 'Worn ball joints', 'Loose steering column coupling'],
        'inspection' => ['Measure steering free play', 'Shake test on ramp', 'Inspect tie rods and ball joints'],
        'actions' => ['replace_tie_rod', 'replace_ball_joint', 'align_wheels', 'road_test', 'visual_inspection'],
    ],
    [
        'name' => 'Pulling / drifting', 'name_ar' => 'انحراف السيارة عن المسار',
        'category' => 'suspension', 'system' => 'Steering', 'subsystem' => 'Alignment', 'discipline' => 'mechanical', 'risk' => 'moderate',
        'description' => 'Vehicle drifts to one side on a level road without steering input.',
        'en' => [
            'syn'      => ['vehicle pulling', 'car drifts', 'pulls to one side', 'wanders to the left', 'off-centre steering'],
            'workshop' => ['alignment out', 'camber pull', 'tyre conicity'],
            'customer' => ['car pulls to one side', 'drifts to the right', 'have to hold the wheel straight',
                           'steering wheel is not straight'],
            'miss'     => ['car puling', 'drfiting to one side'],
        ],
        'ar' => [
            'formal'   => ['انحراف السيارة عن المسار'],
            'workshop' => ['الموتر يميل', 'يسحب على جنب', 'الدركسون مايل', 'يشد على اليمين'],
        ],
        'components' => ['Tyres', 'Suspension geometry', 'Wheel alignment', 'Brakes'],
        'causes' => ['Wheel alignment out of specification', 'Uneven tyre pressure', 'Uneven tyre wear',
                     'Dragging brake', 'Worn suspension bush'],
        'inspection' => ['Check tyre pressures', 'Alignment measurement', 'Check for brake drag', 'Road test'],
        'actions' => ['align_wheels', 'replace_tyre', 'replace_bush', 'road_test'],
    ],
    [
        'name' => 'Steering noise', 'name_ar' => 'صوت عند تدوير المقود',
        'category' => 'suspension', 'system' => 'Steering', 'subsystem' => 'Pump / linkage', 'discipline' => 'mechanical', 'risk' => 'moderate',
        'description' => 'Noise while turning the steering wheel — whine, clunk or groan.',
        'en' => [
            'syn'      => ['steering whine', 'noise when turning', 'clunk when steering', 'groaning steering'],
            'workshop' => ['pump whine', 'low on fluid', 'top mount knocking'],
            'customer' => ['noise when i turn the wheel', 'whining sound when turning', 'clunk when i turn'],
            'miss'     => ['stearing noise', 'noise when turnning'],
        ],
        'ar' => [
            'formal'   => ['صوت عند تدوير المقود'],
            'workshop' => ['صوت عند اللف', 'الدركسون يصفر', 'صوت طقطقة عند اللف'],
        ],
        'components' => ['Power steering pump', 'Strut top mount', 'Steering rack', 'CV joints'],
        'causes' => ['Low power-steering fluid', 'Failing power-steering pump', 'Worn strut top mount', 'Worn CV joint'],
        'inspection' => ['Check fluid level', 'Turn lock to lock and listen', 'Check top mounts'],
        'actions' => ['top_up_power_steering_fluid', 'replace_power_steering_pump', 'replace_shock_absorber', 'road_test'],
    ],
    [
        'name' => 'Power-steering leak', 'name_ar' => 'تسريب زيت المقود',
        'category' => 'fluids', 'system' => 'Steering', 'subsystem' => 'Hydraulics', 'discipline' => 'mechanical', 'risk' => 'moderate',
        'description' => 'Power-steering fluid escaping from the hydraulic circuit.',
        'en' => [
            'syn'      => ['power steering fluid leak', 'PAS leak', 'steering fluid leak', 'leaking steering rack'],
            'workshop' => ['rack boot full of fluid', 'high-pressure hose weeping'],
            'customer' => ['fluid leaking from the front', 'oil under the front of the car', 'steering fluid keeps going down'],
            'miss'     => ['power stearing leak', 'steering fuild leak'],
            'abbr'     => ['PAS leak'],
        ],
        'ar' => [
            'formal'   => ['تسريب زيت نظام التوجيه'],
            'workshop' => ['زيت الباور ناقص', 'تسريب من الباور', 'زيت الدركسون ينقص'],
        ],
        'components' => ['Steering rack', 'High-pressure hose', 'Power steering pump', 'Reservoir'],
        'causes' => ['Leaking rack seal', 'Perished high-pressure hose', 'Leaking pump seal'],
        'inspection' => ['Locate leak with system pressurised', 'Inspect rack boots', 'Check hose condition'],
        'actions' => ['top_up_power_steering_fluid', 'replace_power_steering_pump', 'repair_oil_leak', 'visual_inspection'],
    ],
    [
        'name' => 'Steering warning light', 'name_ar' => 'إضاءة لمبة نظام التوجيه',
        'category' => 'suspension', 'system' => 'Steering', 'subsystem' => 'EPS', 'discipline' => 'electrical', 'risk' => 'critical',
        'description' => 'Electric power steering warning lamp lit — assistance may reduce or cut out.',
        'en' => [
            'syn'      => ['EPS warning', 'power steering light', 'steering fault light', 'EPS fault'],
            'workshop' => ['EPS code stored', 'torque sensor fault'],
            'customer' => ['steering warning light on', 'red steering wheel symbol on dash'],
            'miss'     => ['eps ligth', 'stearing warning'],
            'abbr'     => ['EPS'],
        ],
        'ar' => [
            'formal'   => ['إضاءة لمبة نظام التوجيه'],
            'workshop' => ['لمبة الدركسون طالعة', 'إشارة الباور طالعة'],
        ],
        'components' => ['EPS motor', 'Torque sensor', 'Steering angle sensor', 'Wiring'],
        'causes' => ['EPS motor fault', 'Torque sensor fault', 'Low system voltage', 'Wiring fault'],
        'inspection' => ['Scan EPS codes', 'Check battery voltage', 'Inspect wiring and connectors'],
        'actions' => ['scan_diagnostics', 'repair_wiring', 'reset_warning_light', 'road_test'],
    ],
];
