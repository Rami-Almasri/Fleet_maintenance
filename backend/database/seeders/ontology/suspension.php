<?php

/**
 * Suspension and NVH fault concepts. SUSPENSION is 1,686 recorded events.
 *
 * Noise/vibration/harshness complaints land here because that is how customers report them — a
 * knock over a speed bump is described as a sound, not as a worn bush, and the vocabulary has to
 * meet people where they actually are.
 */

return [
    [
        'name' => 'Knocking over bumps', 'name_ar' => 'صوت طقطقة عند المطبات',
        'category' => 'suspension', 'category_label' => 'Steering & suspension', 'category_label_ar' => 'التوجيه والتعليق',
        'system' => 'Suspension', 'subsystem' => 'Linkage', 'discipline' => 'mechanical', 'risk' => 'moderate',
        'description' => 'Knock, clunk or rattle from the suspension over uneven road.',
        'en' => [
            'syn'      => ['suspension noise', 'knocking noise', 'clunking over bumps', 'rattle over bumps',
                           'suspension knock', 'banging noise'],
            'workshop' => ['top mount knocking', 'drop link rattling', 'bush is shot', 'play in the ball joint'],
            'customer' => ['noise over bumps', 'clunking on rough road', 'banging from the suspension',
                           'knocking sound when i go over a bump', 'rattle from the front wheel'],
            'miss'     => ['knocking over bums', 'suspention noise', 'clunkin noise'],
        ],
        'ar' => [
            'formal'   => ['أصوات من نظام التعليق'],
            'workshop' => ['صوت عند المطبات', 'طقطقة في الشاصي', 'صوت من المساعدين', 'يطق عند الحفر', 'صوت قدام عند المطب'],
        ],
        'components' => ['Anti-roll bar links', 'Bushes', 'Ball joints', 'Strut top mounts', 'Shock absorbers'],
        'causes' => ['Worn anti-roll bar link', 'Worn suspension bush', 'Worn ball joint',
                     'Failed strut top mount', 'Loose subframe bolt'],
        'inspection' => ['Bounce test each corner', 'Shake test on a ramp', 'Inspect bushes and links', 'Road test over bumps'],
        'actions' => ['replace_link_rod', 'replace_bush', 'replace_ball_joint', 'replace_shock_absorber',
                      'align_wheels', 'road_test'],
    ],
    [
        'name' => 'Worn shock / strut', 'name_ar' => 'تلف المساعدين',
        'category' => 'suspension', 'system' => 'Suspension', 'subsystem' => 'Damping', 'discipline' => 'mechanical', 'risk' => 'moderate',
        'description' => 'Shock absorbers no longer damping — the car floats, bounces or dives.',
        'en' => [
            'syn'      => ['worn shocks', 'shock absorber failure', 'leaking strut', 'bouncy ride',
                           'shock absorber leaking', 'struts worn'],
            'workshop' => ['shocks are oily', 'no damping left', 'bounce test fails'],
            'customer' => ['car bounces a lot', 'bumpy ride', 'car feels floaty', 'vehicle bouncing after a bump',
                           'car dips forward when i brake'],
            'miss'     => ['shocks absorber', 'shock absorbers worn', 'shok absorber'],
        ],
        'ar' => [
            'formal'   => ['تلف ممتصات الصدمات'],
            'workshop' => ['المساعدين خربانين', 'المساعد يسرب زيت', 'السيارة تنطط', 'المساعدين ضعاف'],
        ],
        'components' => ['Shock absorbers', 'Struts', 'Top mounts', 'Springs'],
        'causes' => ['Worn shock absorber', 'Leaking strut seal', 'Failed top mount'],
        'inspection' => ['Bounce test each corner', 'Inspect for oil on the body', 'Check tyre wear pattern'],
        'actions' => ['replace_shock_absorber', 'align_wheels', 'road_test', 'visual_inspection'],
    ],
    [
        'name' => 'Broken spring', 'name_ar' => 'كسر في الياي',
        'category' => 'suspension', 'system' => 'Suspension', 'subsystem' => 'Springs', 'discipline' => 'mechanical', 'risk' => 'critical',
        'description' => 'Coil spring cracked or broken — ride height drops on one corner.',
        'en' => [
            'syn'      => ['broken spring', 'coil spring broken', 'snapped spring', 'spring failure',
                           'uneven ride height', 'car sitting low on one side'],
            'workshop' => ['coil snapped', 'sitting low on the left', 'spring cracked at the end'],
            'customer' => ['car is lower on one side', 'one corner is sagging', 'car sits crooked'],
            'miss'     => ['broken sping', 'coil spring brocken'],
        ],
        'ar' => [
            'formal'   => ['كسر في نابض التعليق'],
            'workshop' => ['الياي مكسور', 'السيارة واطية من جهة', 'الرفرف نازل'],
        ],
        'components' => ['Coil springs', 'Spring seats', 'Shock absorbers'],
        'causes' => ['Corrosion fatigue', 'Pothole impact', 'Overloading'],
        'inspection' => ['Measure ride height all four corners', 'Inspect springs for cracks'],
        'actions' => ['replace_shock_absorber', 'align_wheels', 'visual_inspection', 'measure_component'],
    ],
    [
        'name' => 'Wheel-bearing noise', 'name_ar' => 'صوت رمان بلي',
        'category' => 'suspension', 'system' => 'Suspension', 'subsystem' => 'Hub', 'discipline' => 'mechanical', 'risk' => 'critical',
        'description' => 'Humming or growling that rises with speed and changes when cornering.',
        'en' => [
            'syn'      => ['wheel bearing noise', 'bearing hum', 'growling from the wheel', 'bearing failure',
                           'humming with speed'],
            'workshop' => ['bearing rumble', 'play at the hub', 'noise changes on turn-in'],
            'customer' => ['humming noise that gets louder with speed', 'growling from the front wheel',
                           'noise changes when i turn'],
            'miss'     => ['wheel baering', 'bearing nois', 'wheel bering'],
        ],
        'ar' => [
            'formal'   => ['صوت من محمل العجلة'],
            'workshop' => ['رمان بلي', 'صوت رمان البلي', 'صوت هدير من الكفر', 'البلي خربان'],
        ],
        'components' => ['Wheel bearing', 'Hub', 'Driveshaft'],
        'causes' => ['Worn wheel bearing', 'Water ingress into bearing', 'Impact damage'],
        'inspection' => ['Road test noting turn-in change', 'Check hub play on jack', 'Spin wheel and listen'],
        'actions' => ['replace_wheel_bearing', 'road_test', 'measure_component'],
    ],
    [
        'name' => 'Control arm / ball joint', 'name_ar' => 'تلف المقص أو جوزة المقص',
        'category' => 'suspension', 'system' => 'Suspension', 'subsystem' => 'Linkage', 'discipline' => 'mechanical', 'risk' => 'critical',
        'description' => 'Worn control-arm bush or ball joint — play in the front suspension.',
        'en' => [
            'syn'      => ['control arm issue', 'ball joint worn', 'lower arm bush', 'wishbone worn',
                           'ball joint play', 'control arm bush'],
            'workshop' => ['bush is split', 'ball joint has play', 'wishbone needs replacing'],
            'customer' => ['garage says the arm needs changing', 'knocking and the steering feels loose'],
            'miss'     => ['control arm isue', 'bal joint', 'ball joynt'],
        ],
        'ar' => [
            'formal'   => ['تلف ذراع التعليق أو المفصل الكروي'],
            'workshop' => ['المقص خربان', 'جوزة المقص فيها خلعة', 'المقص يحتاج تغيير', 'جوزة مهترئة', 'فيه خلعة بالمقص'],
        ],
        'components' => ['Control arm', 'Ball joint', 'Bushes'],
        'causes' => ['Worn ball joint', 'Split control-arm bush', 'Impact damage'],
        'inspection' => ['Lever test for ball joint play', 'Inspect bushes for splitting', 'Alignment after replacement'],
        'actions' => ['replace_control_arm', 'replace_ball_joint', 'replace_bush', 'align_wheels', 'road_test'],
    ],
    [
        'name' => 'Vibration at speed', 'name_ar' => 'اهتزاز عند السرعة',
        'category' => 'suspension', 'system' => 'NVH', 'subsystem' => 'Balance', 'discipline' => 'mechanical', 'risk' => 'moderate',
        'description' => 'General vibration felt through the car at road speed, not specific to braking.',
        'en' => [
            'syn'      => ['vibration at speed', 'car vibrates', 'shaking at highway speed', 'wobble',
                           'body vibration', 'NVH complaint'],
            'workshop' => ['out of balance', 'driveshaft vibration', 'bent rim'],
            'customer' => ['car vibrates on the highway', 'whole car shakes at speed', 'shaking above 100'],
            'miss'     => ['vibrateion at speed', 'car vibrats'],
            'abbr'     => ['NVH'],
        ],
        'ar' => [
            'formal'   => ['اهتزاز عند السرعات العالية'],
            'workshop' => ['السيارة ترجف على السرعة', 'اهتزاز فوق المية', 'رجة في السيارة'],
        ],
        'components' => ['Wheels', 'Tyres', 'Driveshaft', 'Engine mounts', 'CV joints'],
        'causes' => ['Wheel out of balance', 'Buckled rim', 'Worn CV joint', 'Worn engine mount', 'Tyre deformation'],
        'inspection' => ['Note speed range of vibration', 'Balance all four', 'Inspect driveshaft and CV boots'],
        'actions' => ['balance_wheels', 'align_wheels', 'replace_tyre', 'replace_cv_joint',
                      'replace_engine_mount', 'road_test'],
    ],
];
