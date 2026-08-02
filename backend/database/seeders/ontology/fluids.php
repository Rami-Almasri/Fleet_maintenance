<?php

/**
 * Fluid and leak concepts.
 *
 * Three of the six `fluids` keywords already live in the files that own the system they belong to —
 * Coolant leak in cooling.php, Brake-fluid leak in brakes.php, Power-steering leak in steering.php —
 * because a leak is diagnosed by the circuit it comes from, not by the fact that it is wet. What was
 * left behind were the three that belong to no single system: engine oil, fuel, and "the level is
 * low" as a finding in its own right.
 *
 * LOW FLUID LEVEL IS NOT A LEAK, AND THAT IS THE POINT. It is what an inspector records when a
 * reservoir is under the mark and nothing is visibly dripping. Filing it under whichever fluid
 * happened to be low would lose the one thing it tells you: a car is consuming something and nobody
 * knows where it goes. It stays a concept so the pattern is visible across visits.
 *
 * Fuel wording is claimed from `Fuel system fault` (safety.php). That concept covers the pump,
 * filter, injectors and sender — things that stop the car running. A fuel LEAK or SMELL is a fire
 * risk with a different response, and while both lived on one concept the distinction could not be
 * reported on.
 */

return [
    [
        'name' => 'Oil leak', 'name_ar' => 'تسريب زيت',
        'category' => 'fluids', 'category_label' => 'Fluids & Leaks', 'category_label_ar' => 'السوائل والتسريبات',
        'system' => 'Engine', 'subsystem' => 'Lubrication', 'discipline' => 'mechanical', 'risk' => 'critical',
        'description' => 'Engine oil escaping — from a gasket, seal, sump or filter joint.',
        'en' => [
            'syn'      => ['engine oil leak', 'oil leak', 'oil leakage', 'leaking oil', 'oil seepage',
                           'sump leak', 'oil drip'],
            'workshop' => ['valve cover gasket weeping', 'sump plug seeping', 'crank seal leaking',
                           'oily around the sump', 'wet under the rocker cover'],
            'customer' => ['oil patch where i park', 'black oil under the car', 'oil spots on the floor',
                           'oil level keeps dropping', 'smell of burning oil'],
            'miss'     => ['oil leek', 'engine oil leek', 'oill leak', 'oil laek'],
        ],
        'ar' => [
            'formal'   => ['تسريب زيت المحرك', 'تسريب الزيت'],
            'workshop' => ['المكينة ترشح زيت', 'الزيت ينقص', 'فيه زيت تحت المكينة', 'تنقيط زيت', 'المكينة تنقط زيت'],
        ],
        'components' => ['Sump', 'Valve-cover gasket', 'Crank seal', 'Oil filter housing', 'Sump plug'],
        'causes' => ['Failed valve-cover gasket', 'Worn crank or cam seal', 'Loose or missing sump plug washer',
                     'Damaged oil filter seal', 'Cracked sump from road impact', 'Over-filled oil'],
        'inspection' => ['Clean the area and re-inspect to find the source', 'Check oil level and condition',
                         'Inspect sump plug and filter seal', 'Dye test when the source is not obvious'],
        'actions' => ['repair_oil_leak', 'replace_valve_cover_gasket', 'replace_crank_seal',
                      'replace_sump_plug', 'replace_oil_filter', 'top_up_engine_oil', 'leak_test'],
    ],
    [
        'name' => 'Fuel smell / leak', 'name_ar' => 'رائحة / تسريب وقود',
        'category' => 'fluids', 'category_label' => 'Fluids & Leaks', 'category_label_ar' => 'السوائل والتسريبات',
        'system' => 'Fuel system', 'subsystem' => 'Fuel containment', 'discipline' => 'mechanical', 'risk' => 'critical',
        'description' => 'Fuel escaping or a smell of fuel — a fire risk, grounded until traced.',
        'en' => [
            'syn'      => ['fuel leak', 'fuel smell', 'petrol smell', 'petrol leak', 'diesel leak',
                           'leaking fuel', 'smell of fuel'],
            'workshop' => ['fuel line weeping', 'leak at the tank seam', 'injector seal leaking fuel',
                           'stain along the fuel line'],
            'customer' => ['i can smell petrol', 'smells of fuel inside the car', 'petrol leaking under the car',
                           'smell of petrol'],
            'miss'     => ['feul leak', 'feul smell', 'petrol leek', 'fule leak', 'petrol smel'],
        ],
        'ar' => [
            'formal'   => ['تسريب وقود', 'رائحة وقود'],
            'workshop' => ['ريحة بنزين', 'تسريب بنزين', 'ريحة بنزين داخل السيارة', 'البنزين يسرب'],
        ],
        // Taken from `Fuel system fault`, which keeps the pump / filter / injector / sender wording.
        'claim' => ['fuel leak', 'fuel smell', 'petrol smell', 'petrol leaking under the car',
                    'smell of petrol', 'feul leak', 'petrol smel', 'ريحة بنزين', 'تسريب بنزين'],
        'components' => ['Fuel lines', 'Fuel tank', 'Injector seals', 'Fuel filter housing',
                         'Fuel pump seal', 'Charcoal canister'],
        'causes' => ['Corroded or chafed fuel line', 'Perished injector seal', 'Leaking tank seam or sender gasket',
                     'Loose fuel filter connection', 'Failed evaporative-emissions hose'],
        'inspection' => ['Trace the smell with the engine hot and running', 'Inspect lines and the tank underside',
                         'Pressure-test the fuel system', 'Check for staining at the injectors'],
        'actions' => ['repair_fuel_leak', 'replace_fuel_filter', 'replace_fuel_injector',
                      'leak_test', 'pressure_test', 'visual_inspection'],
    ],
    [
        'name' => 'Low fluid level', 'name_ar' => 'انخفاض مستوى السوائل',
        'category' => 'fluids', 'category_label' => 'Fluids & Leaks', 'category_label_ar' => 'السوائل والتسريبات',
        'system' => 'Fluids', 'subsystem' => 'Levels', 'discipline' => 'mechanical', 'risk' => 'critical',
        'description' => 'A reservoir below its mark with no leak yet identified — the car is consuming something.',
        'en' => [
            'syn'      => ['low fluid level', 'fluid level low', 'low oil level', 'fluid top up',
                           'levels topped up', 'below minimum mark'],
            'workshop' => ['topped up the levels', 'oil below minimum', 'reservoir under the mark',
                           'checked all levels'],
            'customer' => ['oil light came on', 'warning to check the oil', 'garage topped up the fluids',
                           'oil is low on the dipstick'],
            'miss'     => ['low fuild level', 'flud level low', 'low oill level'],
        ],
        'ar' => [
            'formal'   => ['انخفاض مستوى السوائل'],
            'workshop' => ['الزيت ناقص', 'السوائل ناقصة', 'عبينا الزيت', 'المستوى تحت الحد'],
        ],
        'components' => ['Engine oil', 'Coolant', 'Brake fluid', 'Power-steering fluid', 'Washer fluid'],
        'causes' => ['Consumption between services', 'An undetected leak', 'Not topped up at the last service',
                     'Service interval overdue'],
        'inspection' => ['Check every reservoir against min and max', 'Look for the leak BEFORE topping up',
                         'Record the level at handover so consumption is measurable'],
        'actions' => ['top_up_engine_oil', 'top_up_coolant', 'top_up_power_steering_fluid',
                      'leak_test', 'visual_inspection'],
    ],
];
