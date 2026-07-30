<?php

/**
 * Brake fault concepts.
 *
 * Every entry here is `critical` except pad/disc wear reported without a symptom. Brakes are the one
 * system where a wrong classification has a physical consequence, so the severity floor is set high
 * deliberately — an over-cautious brake ticket costs an inspection, an under-cautious one does not
 * fail safely.
 */

return [
    [
        'name' => 'Brake noise (squeal / grind)', 'name_ar' => 'صوت عند الفرامل',
        'category' => 'brakes', 'category_label' => 'Brakes', 'category_label_ar' => 'الفرامل',
        'system' => 'Braking system', 'subsystem' => 'Friction components', 'discipline' => 'mechanical',
        'risk' => 'critical',
        'description' => 'Noise while braking — grinding indicates metal-to-metal contact and immediate pad replacement.',
        'en' => [
            'syn'      => ['brake squeal', 'brake squeak', 'grinding brakes', 'squealing brakes', 'brake noise', 'metal on metal braking'],
            'workshop' => ['pads are gone', 'metal to metal', 'wear indicator squealing', 'scoring the discs'],
            'customer' => ['strange metallic sound when braking', 'squealing when i brake', 'grinding when stopping',
                           'screeching noise when slowing down', 'brakes make noise', 'noise when i press the brake'],
            'miss'     => ['break noise', 'break squeal', 'braek noise', 'grinding breaks'],
        ],
        'ar' => [
            'formal'   => ['صوت عند الفرامل', 'صرير الفرامل'],
            'workshop' => ['صوت عند الفرامل', 'صرير عند البريك', 'صوت حديد عند الفرملة',
                           'البريك يصفر', 'الفرامل تصوت', 'صوت حك عند الفرامل'],
        ],
        'components' => ['Brake pads', 'Brake discs', 'Calipers', 'Wear indicators', 'Guide pins'],
        'causes' => ['Worn brake pads', 'Scored / worn discs', 'Glazed pads', 'Stuck caliper', 'Debris between pad and disc'],
        'inspection' => ['Measure pad thickness', 'Inspect disc surface and lip', 'Check caliper slides free', 'Road test'],
        'actions' => ['replace_brake_pads', 'replace_brake_discs', 'machine_brake_disc', 'repair_brake_caliper',
                      'replace_brake_caliper', 'lubricate_hinges', 'road_test', 'measure_component'],
    ],
    [
        'name' => 'Vibration when braking', 'name_ar' => 'اهتزاز عند الفرملة',
        'category' => 'brakes', 'system' => 'Braking system', 'subsystem' => 'Discs', 'discipline' => 'mechanical',
        'risk' => 'critical',
        'description' => 'Pulsing or shaking felt through the pedal or wheel while braking — usually disc runout.',
        'en' => [
            'syn'      => ['brake vibration', 'brake judder', 'pedal pulsation', 'warped rotors', 'shaking when braking'],
            'workshop' => ['discs are warped', 'runout on the front discs', 'judder under braking'],
            // "The car shakes when I brake" is the single most common way this fault is reported,
            // and its absence let "brake noise" win the phrase on the word "brake" alone — shaking
            // and noise are different faults with different repairs. Found by testing the exact
            // sentence rather than by reading the list, which is why the phrasings people actually
            // use have to be tested, not assumed.
            'customer' => ['car shakes when i brake', 'shakes when braking', 'car shakes when stopping',
                           'steering wheel shakes when braking', 'pedal pulses when stopping',
                           'car shudders when i slow down', 'shaking when braking at high speed',
                           'wheel vibrates when i brake', 'vibration when i press the brake'],
            'miss'     => ['break vibration', 'brake juder', 'warpped rotors'],
        ],
        'ar' => [
            'formal'   => ['اهتزاز عند الفرملة'],
            'workshop' => ['الدركسون يرجف عند الفرملة', 'اهتزاز عند البريك', 'ترجف عند الفرامل', 'الديسك معوج'],
        ],
        'components' => ['Brake discs', 'Brake pads', 'Wheel bearings', 'Wheel hub'],
        'causes' => ['Warped brake discs', 'Uneven pad deposits', 'Loose / worn wheel bearing', 'Hub runout'],
        'inspection' => ['Measure disc thickness variation', 'Check disc runout', 'Check wheel bearing play', 'Road test'],
        'actions' => ['machine_brake_disc', 'replace_brake_discs', 'replace_brake_pads',
                      'replace_wheel_bearing', 'road_test', 'measure_component'],
    ],
    [
        'name' => 'Soft / spongy pedal', 'name_ar' => 'دواسة فرامل لينة',
        'category' => 'brakes', 'system' => 'Braking system', 'subsystem' => 'Hydraulics', 'discipline' => 'mechanical',
        'risk' => 'critical',
        'description' => 'Pedal travels further than normal or feels soft — air or fluid loss in the hydraulic circuit.',
        'en' => [
            'syn'      => ['spongy brake pedal', 'soft pedal', 'long pedal travel', 'weak brakes', 'brakes not holding'],
            'workshop' => ['air in the system', 'pedal goes to the floor', 'needs bleeding'],
            'customer' => ['brake pedal goes to the floor', 'pedal feels soft', 'brakes feel weak',
                           'have to press hard to stop', 'car takes longer to stop'],
            'miss'     => ['spongey pedal', 'soft brake pedel'],
        ],
        'ar' => [
            'formal'   => ['دواسة فرامل لينة', 'ضعف في الفرامل'],
            'workshop' => ['البريك ما يمسك', 'الدعسة تنزل تحت', 'الفرامل ضعيفة', 'الدعسة طرية'],
        ],
        'components' => ['Master cylinder', 'Brake lines', 'Calipers', 'Brake fluid', 'Wheel cylinders'],
        'causes' => ['Air in the brake lines', 'Brake fluid leak', 'Failing master cylinder',
                     'Worn brake hose', 'Low brake fluid'],
        'inspection' => ['Check fluid level', 'Inspect lines and hoses for leaks', 'Pressure-test system', 'Bleed and re-test'],
        'actions' => ['bleed_brake_system', 'replace_brake_fluid', 'replace_brake_hose', 'road_test', 'pressure_test'],
    ],
    [
        'name' => 'Pulling to one side', 'name_ar' => 'انحراف السيارة عند الفرملة',
        'category' => 'brakes', 'system' => 'Braking system', 'subsystem' => 'Calipers', 'discipline' => 'mechanical',
        'risk' => 'critical',
        'description' => 'Vehicle veers to one side under braking — uneven braking force between sides.',
        'en' => [
            'syn'      => ['brake pull', 'pulls when braking', 'veers under braking', 'uneven braking'],
            'workshop' => ['caliper sticking one side', 'seized slider', 'uneven pad wear side to side'],
            'customer' => ['car pulls to one side when i brake', 'goes left when braking', 'car moves to the side when stopping'],
            'miss'     => ['pulling to one side when breaking'],
        ],
        'ar' => [
            'formal'   => ['انحراف السيارة عند الفرملة'],
            'workshop' => ['السيارة تميل عند الفرملة', 'تسحب على جنب عند البريك', 'تشد على جهة'],
        ],
        'components' => ['Calipers', 'Brake pads', 'Brake hoses', 'Tyres'],
        'causes' => ['Sticking caliper', 'Collapsed brake hose', 'Uneven pad wear', 'Contaminated pad'],
        'inspection' => ['Compare pad wear side to side', 'Check caliper slides', 'Check hose condition', 'Road test'],
        'actions' => ['repair_brake_caliper', 'replace_brake_caliper', 'replace_brake_pads',
                      'replace_brake_hose', 'road_test'],
    ],
    [
        'name' => 'ABS warning light', 'name_ar' => 'إضاءة لمبة ABS',
        'category' => 'brakes', 'system' => 'Braking system', 'subsystem' => 'ABS', 'discipline' => 'electrical',
        'risk' => 'critical',
        'description' => 'ABS warning lamp lit — anti-lock function is disabled even though base braking remains.',
        'en' => [
            'syn'      => ['ABS light', 'ABS fault', 'anti-lock warning', 'ABS warning'],
            'workshop' => ['wheel speed sensor fault', 'ABS ring damaged', 'ABS code stored'],
            'customer' => ['ABS light on dashboard', 'brake warning light came on', 'letters ABS showing on dash'],
            'miss'     => ['abs ligth', 'a b s warning'],
            'abbr'     => ['ABS'],
        ],
        'ar' => [
            'formal'   => ['إضاءة لمبة نظام منع انغلاق الفرامل'],
            'workshop' => ['لمبة ABS', 'إشارة الفرامل طالعة', 'لمبة البريك طالعة'],
        ],
        'components' => ['Wheel speed sensors', 'ABS module', 'Reluctor rings', 'Wiring'],
        'causes' => ['Faulty wheel speed sensor', 'Damaged reluctor ring', 'Wiring fault', 'ABS module failure'],
        'inspection' => ['Scan ABS codes', 'Compare wheel speed signals', 'Inspect sensor and wiring', 'Clear and road test'],
        'actions' => ['scan_diagnostics', 'replace_abs_sensor', 'repair_wiring', 'reset_warning_light', 'road_test'],
    ],
    [
        'name' => 'Worn pads / discs', 'name_ar' => 'تآكل الفحمات والديسكات',
        'category' => 'brakes', 'system' => 'Braking system', 'subsystem' => 'Friction components', 'discipline' => 'mechanical',
        'risk' => 'moderate',
        'description' => 'Friction material below service limit, found on inspection rather than reported as a symptom.',
        'en' => [
            'syn'      => ['worn brake pads', 'worn discs', 'brake wear', 'pads below limit', 'brake pads finished'],
            'workshop' => ['pads at 2mm', 'discs below minimum', 'lip on the disc'],
            'customer' => ['brakes need changing', 'garage said pads are finished'],
            'miss'     => ['worn breaks', 'worn brake pad'],
        ],
        'ar' => [
            'formal'   => ['تآكل الفحمات والأقراص'],
            'workshop' => ['الفحمات خلصت', 'الديسك متآكل', 'الفحمات مستهلكة', 'الفرامل تحتاج تغيير'],
        ],
        'components' => ['Brake pads', 'Brake discs'],
        'causes' => ['Normal wear', 'Sticking caliper causing accelerated wear', 'Aggressive driving'],
        'inspection' => ['Measure pad thickness', 'Measure disc thickness against minimum', 'Check wear evenness'],
        'actions' => ['replace_brake_pads', 'replace_brake_discs', 'measure_component', 'road_test'],
    ],
    [
        'name' => 'Brake-fluid leak', 'name_ar' => 'تسريب زيت الفرامل',
        'category' => 'brakes', 'system' => 'Braking system', 'subsystem' => 'Hydraulics', 'discipline' => 'mechanical',
        'risk' => 'critical',
        'description' => 'Hydraulic fluid escaping from the brake circuit — total brake loss is possible.',
        'en' => [
            'syn'      => ['brake fluid leak', 'leaking brake fluid', 'hydraulic leak', 'brake line leak'],
            'workshop' => ['wet caliper', 'weeping brake line', 'losing fluid'],
            'customer' => ['fluid under the wheel', 'brake fluid keeps going down', 'oil near the wheel'],
            'miss'     => ['break fluid leak', 'brake fuild leak'],
        ],
        'ar' => [
            'formal'   => ['تسريب زيت الفرامل'],
            'workshop' => ['زيت البريك ناقص', 'تسريب من الفرامل', 'فيه تنقيط عند الكفر'],
        ],
        'components' => ['Brake lines', 'Brake hoses', 'Calipers', 'Master cylinder', 'Wheel cylinders'],
        'causes' => ['Corroded brake line', 'Perished brake hose', 'Leaking caliper seal', 'Failing master cylinder'],
        'inspection' => ['Locate leak under pressure', 'Inspect all lines and hoses', 'Check master cylinder', 'Bleed after repair'],
        'actions' => ['replace_brake_hose', 'replace_brake_caliper', 'bleed_brake_system',
                      'replace_brake_fluid', 'pressure_test', 'road_test'],
    ],
    [
        'name' => 'Handbrake fault', 'name_ar' => 'عطل في فرامل اليد',
        'category' => 'brakes', 'system' => 'Braking system', 'subsystem' => 'Parking brake', 'discipline' => 'mechanical',
        'risk' => 'moderate',
        'description' => 'Parking brake does not hold the vehicle or does not release fully.',
        'en' => [
            'syn'      => ['parking brake fault', 'handbrake not holding', 'handbrake loose', 'parking brake stuck'],
            'workshop' => ['cable stretched', 'shoes worn', 'handbrake needs adjusting'],
            'customer' => ['handbrake does not hold', 'car rolls with handbrake on', 'handbrake feels loose',
                           'parking brake stuck on'],
            'miss'     => ['hand break fault', 'handbreak'],
        ],
        'ar' => [
            'formal'   => ['عطل في فرامل الانتظار'],
            'workshop' => ['الهاند بريك ما يمسك', 'فرامل اليد مرتخية', 'الهاند ما يشد'],
        ],
        'components' => ['Handbrake cable', 'Brake shoes', 'Parking brake actuator'],
        'causes' => ['Stretched handbrake cable', 'Worn parking brake shoes', 'Seized cable', 'Actuator failure'],
        'inspection' => ['Count handbrake clicks', 'Check cable free movement', 'Inspect shoes', 'Hold test on incline'],
        'actions' => ['adjust_handbrake', 'lubricate_hinges', 'road_test', 'visual_inspection'],
    ],
];
