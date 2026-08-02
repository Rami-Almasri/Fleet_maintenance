<?php

/**
 * Interior, infotainment, seats and locks.
 *
 * INTERIOR is 3,376 recorded events — the second-highest non-exposure category in this fleet, and
 * the largest gap remaining after body. Every concept below was justified by unmatched real notes
 * from the coverage report: "check for new dashboard", "screen issue", "carplay programming,
 * missing carpets", "interior upholstery of the seats", "rear seat seatbelt lock fix",
 * "collecting interior parts".
 */

return [
    [
        'name' => 'Seat / upholstery damage', 'name_ar' => 'تلف المقاعد',
        'category' => 'interior', 'category_label' => 'Interior', 'category_label_ar' => 'المقصورة الداخلية',
        'system' => 'Interior', 'subsystem' => 'Seats', 'discipline' => 'bodywork', 'risk' => 'routine',
        'description' => 'Seat fabric or leather torn, burned, stained or worn.',
        'en' => [
            'syn'      => ['seat damage', 'torn seat', 'upholstery damage', 'seat cover torn', 'burn on the seat',
                           'seat stained', 'interior upholstery', 'leather damaged'],
            'workshop' => ['retrimmed the seat', 'upholstery work', 'seat cover replaced'],
            'customer' => ['seat is torn', 'cigarette burn on the seat', 'seat is dirty and torn',
                           'the leather is cracked'],
            'miss'     => ['upholstry', 'seat damge', 'uphlostery damage'],
        ],
        'ar' => [
            'formal'   => ['تلف في تنجيد المقاعد'],
            'workshop' => ['الكرسي مقطوع', 'تنجيد', 'الجلد مقطع', 'الكراسي متسخة', 'يبغى تنجيد'],
        ],
        'components' => ['Seat covers', 'Foam', 'Seat frame', 'Trim'],
        'causes' => ['Wear', 'Cigarette burn', 'Spill / staining', 'Customer damage'],
        'inspection' => ['Photograph each seat', 'Assess repair vs retrim'],
        'actions' => ['clean_interior', 'visual_inspection'],
    ],
    [
        'name' => 'Seat adjustment fault', 'name_ar' => 'عطل في تعديل المقعد',
        'category' => 'interior', 'system' => 'Interior', 'subsystem' => 'Seat mechanism', 'discipline' => 'mechanical', 'risk' => 'moderate',
        'description' => 'Seat will not slide, recline or adjust — manual or electric.',
        'en' => [
            'syn'      => ['seat not moving', 'seat adjustment failure', 'seat stuck', 'electric seat fault',
                           'seat will not recline', 'seat motor not working'],
            'workshop' => ['seat rail seized', 'seat motor dead', 'switch faulty'],
            'customer' => ['seat does not move', 'cannot adjust the seat', 'driver seat is stuck'],
            'miss'     => ['seat adjustmnet', 'seat not moveing'],
        ],
        'ar' => [
            'formal'   => ['عطل في آلية ضبط المقعد'],
            'workshop' => ['الكرسي ما يتحرك', 'الكرسي عالق', 'موتر الكرسي خربان'],
        ],
        'components' => ['Seat motor', 'Seat rails', 'Switches', 'Wiring'],
        'causes' => ['Failed seat motor', 'Seized rail', 'Faulty switch', 'Broken wiring in the loom'],
        'inspection' => ['Test all directions', 'Check fuse and switch', 'Inspect rails for obstruction'],
        'actions' => ['repair_wiring', 'replace_fuse', 'lubricate_hinges', 'visual_inspection'],
    ],
    [
        'name' => 'Seat belt fault', 'name_ar' => 'عطل في حزام الأمان',
        // Described in this file because a belt is cabin hardware, filed under `safety` because that is
        // what it protects. The file a concept is written in never has to match its category.
        'category' => 'safety', 'category_label' => 'Safety & Driver Assist', 'category_label_ar' => 'أنظمة السلامة والمساعدة',
        'system' => 'Safety', 'subsystem' => 'Restraints', 'discipline' => 'mechanical', 'risk' => 'critical',
        'description' => 'Seat belt not retracting, not latching, or its buckle damaged. Safety-critical.',
        'en' => [
            'syn'      => ['seat belt fault', 'seatbelt not working', 'belt not retracting', 'buckle broken',
                           'seatbelt lock', 'belt stuck', 'seat belt warning'],
            'workshop' => ['retractor jammed', 'buckle latch broken', 'pretensioner deployed'],
            'customer' => ['seat belt will not pull out', 'belt does not click in', 'seatbelt stays loose',
                           'rear seat belt is broken'],
            'miss'     => ['seat belt fualt', 'seatbelt lok', 'seat blet'],
        ],
        'ar' => [
            'formal'   => ['عطل في حزام الأمان'],
            'workshop' => ['الحزام ما يرجع', 'الحزام ما يثبت', 'قفل الحزام مكسور', 'حزام الأمان خربان'],
        ],
        'components' => ['Seat belt retractor', 'Buckle', 'Pretensioner', 'Belt webbing'],
        'causes' => ['Jammed retractor', 'Broken buckle latch', 'Deployed pretensioner', 'Debris in the buckle'],
        'inspection' => ['Test extend and retract', 'Test latch engagement', 'Check webbing for damage',
                         'Scan for restraint codes'],
        'actions' => ['repair_wiring', 'scan_diagnostics', 'visual_inspection'],
    ],
    [
        'name' => 'Infotainment / screen issue', 'name_ar' => 'عطل في الشاشة',
        'category' => 'interior', 'system' => 'Infotainment', 'subsystem' => 'Head unit', 'discipline' => 'electrical', 'risk' => 'routine',
        'description' => 'Head unit, display, or phone integration not working.',
        'en' => [
            'syn'      => ['screen issue', 'infotainment fault', 'display not working', 'radio not working',
                           'touchscreen not responding', 'carplay not working', 'android auto fault',
                           'bluetooth not connecting', 'screen blank', 'carplay programming'],
            'workshop' => ['head unit needs coding', 'screen not responding', 'software update needed'],
            'customer' => ['screen is black', 'radio does not work', 'phone will not connect',
                           'carplay stopped working', 'touchscreen is frozen'],
            'miss'     => ['infotaiment', 'car play', 'blutooth not working', 'screen isue'],
            'abbr'     => ['carplay', 'android auto'],
        ],
        'ar' => [
            'formal'   => ['عطل في نظام المعلومات والترفيه'],
            'workshop' => ['الشاشة ما تشتغل', 'الشاشة طافية', 'المسجل خربان', 'البلوتوث ما يمسك', 'الشاشة معلقة'],
        ],
        'components' => ['Head unit', 'Display', 'Antenna', 'USB port', 'Speakers'],
        'causes' => ['Software fault needing update', 'Failed head unit', 'Blown fuse', 'Faulty USB port'],
        'inspection' => ['Power-cycle and retest', 'Check fuse', 'Test with a known phone', 'Scan for codes'],
        'actions' => ['scan_diagnostics', 'replace_fuse', 'repair_wiring', 'program_key_fob'],
    ],
    [
        'name' => 'Dashboard fault', 'name_ar' => 'عطل في الطبلون',
        'category' => 'interior', 'system' => 'Interior', 'subsystem' => 'Instrument panel', 'discipline' => 'electrical', 'risk' => 'moderate',
        'description' => 'Instrument cluster or dashboard trim faulty, damaged or noisy.',
        'en' => [
            'syn'      => ['dashboard fault', 'instrument cluster fault', 'dash not working', 'gauges not working',
                           'dashboard noise', 'dashboard rattle', 'new dashboard', 'dashboard cracked'],
            'workshop' => ['cluster is dead', 'dash rattle from the vent', 'cracked dash top'],
            'customer' => ['dashboard is cracked', 'gauges are not working', 'rattling from the dashboard',
                           'speedometer not working'],
            'miss'     => ['dash board fault', 'dashbord', 'dashbaord'],
        ],
        'ar' => [
            'formal'   => ['عطل في لوحة العدادات'],
            'workshop' => ['الطبلون خربان', 'الطبلون مكسور', 'العدادات ما تشتغل', 'صوت من الطبلون'],
        ],
        'components' => ['Instrument cluster', 'Dashboard trim', 'Speedometer', 'Wiring'],
        'causes' => ['Failed cluster', 'UV cracking of trim', 'Loose trim clip', 'Wiring fault'],
        'inspection' => ['Check all gauges on ignition', 'Locate rattle by pressing panels', 'Scan cluster codes'],
        'actions' => ['scan_diagnostics', 'repair_wiring', 'visual_inspection'],
    ],
    [
        // NARROWED to MISSING and WORN items. The breakage wording moved to `Broken trim` below —
        // see the note there. What is left is the inventory question: what should be in this car and
        // is not, which is the one a rental fleet asks at every handover.
        'name' => 'Interior trim damage', 'name_ar' => 'تلف الأجزاء الداخلية',
        'category' => 'interior', 'system' => 'Interior', 'subsystem' => 'Trim', 'discipline' => 'bodywork', 'risk' => 'routine',
        'description' => 'Interior panels, carpets or mats missing, worn or damaged.',
        'en' => [
            'syn'      => ['interior trim damage', 'missing carpets', 'carpet damage',
                           'door card damaged', 'interior parts', 'missing floor mats', 'worn trim'],
            'workshop' => ['refitted the trim', 'collecting interior parts', 'mats not in the car'],
            'customer' => ['carpets are missing', 'floor mats are gone', 'the trim looks worn out'],
            'miss'     => ['intirior trim', 'carpet missng', 'trim damge'],
        ],
        'ar' => [
            'formal'   => ['تلف في الأجزاء الداخلية'],
            'workshop' => ['الفرش ناقص', 'الدواسات ناقصة', 'الفرش مستهلك'],
        ],
        'components' => ['Door cards', 'Carpets', 'Floor mats', 'Trim panels'],
        'causes' => ['Wear', 'Removed and not refitted', 'Not returned with the vehicle', 'Customer damage'],
        'inspection' => ['Inventory what is missing against the handover checklist', 'Grade wear on visible trim'],
        'actions' => ['clean_interior', 'visual_inspection'],
    ],
    [
        // SPLIT from `Interior trim damage`, which had absorbed its wording and left the library's
        // own `Broken trim` keyword unreachable by anything except its exact name.
        //
        // The two are close and the line between them is the ACTION they lead to: a broken clip or a
        // cracked panel is a part to order and refit, while missing carpets are an inventory item to
        // chase. If that distinction ever stops paying for itself, merge them — this is the pair most
        // worth revisiting in `ontology:duplicates`.
        'name' => 'Broken trim', 'name_ar' => 'تلف الزينة الداخلية',
        'category' => 'interior', 'system' => 'Interior', 'subsystem' => 'Trim', 'discipline' => 'bodywork', 'risk' => 'routine',
        'description' => 'A trim piece broken, cracked or hanging off — a part to refit or replace.',
        'en' => [
            'syn'      => ['broken trim', 'trim broken', 'panel clip broken', 'cracked trim panel',
                           'trim hanging off', 'loose trim', 'broken plastic panel'],
            'workshop' => ['clips broken', 'trim clipped back on', 'panel needs a new clip set'],
            'customer' => ['piece of trim is broken', 'plastic panel came off', 'something is hanging inside'],
            'miss'     => ['broken trm', 'trim brocken', 'panel clip brokn'],
        ],
        'ar' => [
            'formal'   => ['تلف الزينة الداخلية'],
            'workshop' => ['التبليط مكسور', 'قطع داخلية مكسورة', 'الزينة مكسورة', 'الزينة فاكة'],
        ],
        'claim' => ['broken trim', 'trim broken', 'panel clip broken', 'clips broken',
                    'piece of trim is broken', 'plastic panel came off', 'التبليط مكسور', 'قطع داخلية مكسورة'],
        'components' => ['Trim panels', 'Clips', 'Door cards', 'Pillar trim'],
        'causes' => ['Broken clip', 'Impact or forced removal', 'UV-embrittled plastic', 'Refitted incorrectly'],
        'inspection' => ['Press the panel to find the failed clip', 'Check mounting points before refitting',
                         'Photograph before ordering the part'],
        'actions' => ['visual_inspection', 'clean_interior'],
    ],
    [
        'name' => 'Bad odour', 'name_ar' => 'رائحة كريهة داخل السيارة',
        'category' => 'interior', 'system' => 'Interior', 'subsystem' => 'Cabin', 'discipline' => 'bodywork', 'risk' => 'routine',
        'description' => 'Persistent smell inside the cabin — smoke, damp or spillage.',
        'en' => [
            'syn'      => ['bad smell inside', 'cabin odour', 'smoke smell', 'damp smell in the car',
                           'car smells', 'bad odour'],
            'workshop' => ['ozone treatment', 'deep cleaned the cabin', 'smoke damage'],
            'customer' => ['car smells of smoke', 'bad smell inside the car', 'smells damp'],
            'miss'     => ['bad odor', 'bad smel inside'],
        ],
        'ar' => [
            'formal'   => ['رائحة كريهة داخل المقصورة'],
            'workshop' => ['ريحة داخل السيارة', 'ريحة دخان', 'ريحة كريهة', 'ريحة رطوبة'],
        ],
        'components' => ['Carpets', 'Seats', 'Cabin filter', 'Evaporator'],
        'causes' => ['Smoking in the vehicle', 'Water ingress / damp', 'Spilled liquid', 'Mould on evaporator'],
        'inspection' => ['Check carpets for damp', 'Inspect cabin filter', 'Check for water ingress'],
        'actions' => ['clean_interior', 'replace_cabin_filter', 'clean_ac_evaporator'],
    ],
    [
        'name' => 'Water leakage into cabin', 'name_ar' => 'تسرب ماء إلى المقصورة',
        'category' => 'interior', 'system' => 'Body', 'subsystem' => 'Seals', 'discipline' => 'bodywork', 'risk' => 'moderate',
        'description' => 'Water entering the cabin — wet carpets, damp headlining or misting.',
        'en' => [
            'syn'      => ['water leak inside', 'water leakage', 'wet carpet', 'damp floor', 'leaking into the car',
                           'water in the footwell', 'roof leaking'],
            'workshop' => ['drain blocked', 'seal perished', 'water test found the leak'],
            'customer' => ['water comes inside when it rains', 'carpet is wet', 'water dripping from the roof',
                           'windows fog up all the time'],
            'miss'     => ['water leackage', 'water leak insid'],
        ],
        'ar' => [
            'formal'   => ['تسرب مياه إلى المقصورة'],
            'workshop' => ['ماي داخل السيارة', 'الفرش مبلول', 'يدخل ماي من السقف', 'تسريب ماء للداخل'],
        ],
        'components' => ['Door seals', 'Sunroof drains', 'Windscreen seal', 'Body plugs', 'AC condensate drain'],
        'causes' => ['Blocked sunroof drain', 'Perished door seal', 'Leaking windscreen seal',
                     'Blocked AC condensate drain'],
        'inspection' => ['Water test to locate entry', 'Check sunroof and AC drains', 'Lift carpet to find extent'],
        'actions' => ['clean_interior', 'replace_windscreen', 'clean_ac_evaporator', 'leak_test', 'visual_inspection'],
    ],
    [
        'name' => 'Door lock fault', 'name_ar' => 'عطل في قفل الباب',
        'category' => 'interior', 'system' => 'Body electrics', 'subsystem' => 'Locks', 'discipline' => 'electrical', 'risk' => 'moderate',
        'description' => 'Door will not lock or unlock, or the handle is broken.',
        'en' => [
            'syn'      => ['lock problem', 'door will not lock', 'door handle broken', 'lock actuator fault',
                           'door will not open', 'central locking not working'],
            'workshop' => ['actuator clicking', 'latch seized', 'handle cable snapped'],
            'customer' => ['door does not lock', 'handle is broken', 'cannot open the door from inside',
                           'only one door locks'],
            'miss'     => ['dor lock', 'lock fualt', 'door handel broken'],
        ],
        'ar' => [
            'formal'   => ['عطل في قفل الباب'],
            'workshop' => ['الباب ما يسكر', 'اليد مكسورة', 'القفل خربان', 'الباب ما يفتح من جوا'],
        ],
        'components' => ['Lock actuator', 'Door latch', 'Handle', 'Wiring'],
        'causes' => ['Failed lock actuator', 'Seized latch', 'Broken handle cable', 'Door loom wiring break'],
        'inspection' => ['Test lock from key, fob and switch', 'Listen for actuator', 'Check door loom'],
        'actions' => ['repair_wiring', 'lubricate_hinges', 'replace_window_regulator', 'visual_inspection'],
    ],
    [
        'name' => 'Interior light fault', 'name_ar' => 'عطل في إضاءة المقصورة',
        'category' => 'interior', 'system' => 'Body electrics', 'subsystem' => 'Interior lighting', 'discipline' => 'electrical', 'risk' => 'routine',
        'description' => 'Cabin, map, boot or vanity light not working or staying on.',
        'en' => [
            'syn'      => ['interior light not working', 'cabin light fault', 'dome light', 'map light not working',
                           'interior light stays on', 'boot light not working'],
            'workshop' => ['door switch stuck', 'bulb blown', 'LED module failed'],
            'customer' => ['light inside does not work', 'interior light stays on', 'roof light not working'],
            'miss'     => ['interior ligth', 'inteior light issue'],
        ],
        'ar' => [
            'formal'   => ['عطل في إضاءة المقصورة'],
            'workshop' => ['لمبة الداخل ما تشتغل', 'إضاءة السقف خربانة', 'اللمبة تضل شغالة'],
        ],
        'components' => ['Interior lamps', 'Door switches', 'Fuse', 'Body control module'],
        'causes' => ['Blown bulb', 'Stuck door switch', 'Blown fuse', 'BCM fault'],
        'inspection' => ['Test each lamp', 'Check door switches', 'Check fuse'],
        'actions' => ['replace_fuse', 'repair_wiring', 'visual_inspection'],
    ],
];
