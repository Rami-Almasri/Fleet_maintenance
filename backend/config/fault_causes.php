<?php

/**
 * Seed source for the Symptom → Root-Cause knowledge base (the `fault_causes` table).
 *
 * This mirrors the convention of config/maintenance_findings.php: the *menu* lives in git as a
 * one-edit-per-line PHP array, so the diagnostic library can grow without code changes, and
 * FaultCauseSeeder upserts it into the database (which is the runtime source of truth — it also
 * holds user-submitted "pending" causes the picker must NOT show until an admin approves them).
 *
 * Shape: a list of symptom blocks, each:
 *   [
 *     'symptom'  => 'Overheating',          // MUST match a keyword in config/maintenance_findings.php
 *     'category' => 'engine',               // the findings category key (for trend reporting)
 *     'causes'   => ['Coolant leak', ...],  // curated probable root causes, most-likely first
 *   ]
 *
 * Only symptoms with a meaningful differential diagnosis are listed; a symptom absent here simply
 * offers no preset causes (the picker still lets the user type a custom one, which is then flagged
 * for review). `symptom` is matched case-insensitively against the chosen finding text.
 */

return [

    'symptoms' => [

        // ── Engine ──────────────────────────────────────────────────────────
        [
            'symptom' => 'Overheating',
            'category' => 'engine',
            'causes' => [
                'Coolant leak',
                'Water-pump failure',
                'Faulty thermostat',
                'Radiator blockage / scaling',
                'Cooling-fan not running',
                'Blown head gasket',
                'Low coolant level',
            ],
        ],
        [
            'symptom' => 'Engine noise',
            'category' => 'engine',
            'causes' => [
                'Low / degraded engine oil',
                'Worn timing chain / tensioner',
                'Faulty hydraulic lifters',
                'Worn main / big-end bearings',
                'Accessory belt / pulley wear',
            ],
        ],
        [
            'symptom' => 'Rough idle / misfire',
            'category' => 'engine',
            'causes' => [
                'Worn spark plugs / coils',
                'Clogged / faulty fuel injector',
                'Vacuum leak',
                'Dirty throttle body',
                'Faulty MAF / O2 sensor',
            ],
        ],
        [
            'symptom' => 'Loss of power',
            'category' => 'engine',
            'causes' => [
                'Clogged air filter',
                'Blocked fuel filter',
                'Turbo / boost leak',
                'Catalytic converter blockage',
                'Faulty MAF sensor',
            ],
        ],
        [
            'symptom' => 'Excessive exhaust smoke',
            'category' => 'engine',
            'causes' => [
                'Worn valve seals / piston rings (blue)',
                'Coolant in combustion — head gasket (white)',
                'Over-fuelling / dirty injectors (black)',
                'Faulty turbo seals',
            ],
        ],
        [
            'symptom' => 'Hard starting',
            'category' => 'engine',
            'causes' => [
                'Weak battery',
                'Faulty starter motor',
                'Fuel-pump / pressure issue',
                'Glow-plug fault (diesel)',
                'Worn spark plugs',
            ],
        ],
        [
            'symptom' => 'Stalling',
            'category' => 'engine',
            'causes' => [
                'Dirty throttle body / idle-control valve',
                'Vacuum leak',
                'Faulty crankshaft-position sensor',
                'Fuel-delivery problem',
            ],
        ],
        [
            'symptom' => 'Check-engine light',
            'category' => 'engine',
            'causes' => [
                'O2 / MAF sensor fault',
                'Misfire detected',
                'Catalytic-converter efficiency',
                'EVAP / loose fuel cap',
                'Awaiting OBD scan',
            ],
        ],

        // ── Brakes ──────────────────────────────────────────────────────────
        [
            'symptom' => 'Brake noise (squeal / grind)',
            'category' => 'brakes',
            'causes' => [
                'Worn brake pads',
                'Scored / worn discs',
                'Glazed pads',
                'Stuck caliper',
                'Debris between pad and disc',
            ],
        ],
        [
            'symptom' => 'Soft / spongy pedal',
            'category' => 'brakes',
            'causes' => [
                'Air in brake lines',
                'Brake-fluid leak',
                'Failing master cylinder',
                'Worn flexible hose',
            ],
        ],
        [
            'symptom' => 'Vibration when braking',
            'category' => 'brakes',
            'causes' => [
                'Warped brake discs',
                'Uneven pad deposits',
                'Loose / worn wheel bearing',
            ],
        ],
        [
            'symptom' => 'Pulling to one side',
            'category' => 'brakes',
            'causes' => [
                'Seized caliper on one side',
                'Uneven pad wear',
                'Collapsed brake hose',
            ],
        ],

        // ── Tyres & Wheels ──────────────────────────────────────────────────
        [
            'symptom' => 'Uneven tyre wear',
            'category' => 'tyres',
            'causes' => [
                'Wheel misalignment',
                'Under / over-inflation',
                'Worn suspension component',
                'Out-of-balance wheel',
            ],
        ],
        [
            'symptom' => 'Puncture / slow leak',
            'category' => 'tyres',
            'causes' => [
                'Nail / road debris',
                'Damaged valve stem',
                'Corroded / bent rim seal',
                'Sidewall damage',
            ],
        ],

        // ── Suspension & Steering ───────────────────────────────────────────
        [
            'symptom' => 'Knocking over bumps',
            'category' => 'suspension',
            'causes' => [
                'Worn ball joint',
                'Worn anti-roll-bar link',
                'Worn control-arm bush',
                'Failing strut mount',
            ],
        ],
        [
            'symptom' => 'Steering vibration',
            'category' => 'suspension',
            'causes' => [
                'Wheel imbalance',
                'Worn wheel bearing',
                'Warped brake disc',
                'Bent rim',
            ],
        ],
        [
            'symptom' => 'Hard / heavy steering',
            'category' => 'suspension',
            'causes' => [
                'Low power-steering fluid',
                'Failing power-steering pump',
                'Faulty EPS motor / sensor',
                'Seized track rod end',
            ],
        ],

        // ── Transmission & Drivetrain ───────────────────────────────────────
        [
            'symptom' => 'Hard / jerky shifting',
            'category' => 'transmission',
            'causes' => [
                'Low / degraded transmission fluid',
                'Worn clutch (manual)',
                'Faulty solenoid / valve body',
                'Worn engine / gearbox mounts',
            ],
        ],
        [
            'symptom' => 'Gear slipping',
            'category' => 'transmission',
            'causes' => [
                'Low transmission fluid',
                'Worn clutch / bands',
                'Faulty torque converter',
            ],
        ],

        // ── Electrical ──────────────────────────────────────────────────────
        [
            'symptom' => 'Battery / won\'t start',
            'category' => 'electrical',
            'causes' => [
                'Dead / aged battery',
                'Faulty alternator',
                'Corroded terminals',
                'Parasitic drain',
                'Faulty starter motor',
            ],
        ],
        [
            'symptom' => 'Alternator / charging fault',
            'category' => 'electrical',
            'causes' => [
                'Worn alternator brushes',
                'Faulty voltage regulator',
                'Slipping / worn drive belt',
                'Wiring / earth fault',
            ],
        ],

        // ── Climate / A/C ───────────────────────────────────────────────────
        [
            'symptom' => 'A/C not cooling',
            'category' => 'ac',
            'causes' => [
                'Low / leaking refrigerant',
                'Faulty compressor',
                'Blocked condenser',
                'Faulty expansion valve',
                'Failed blend-door actuator',
            ],
        ],
        [
            'symptom' => 'Bad smell from vents',
            'category' => 'ac',
            'causes' => [
                'Mould on evaporator',
                'Clogged cabin filter',
                'Blocked A/C drain',
            ],
        ],

        // ── Fluids & Leaks ──────────────────────────────────────────────────
        [
            'symptom' => 'Oil leak',
            'category' => 'fluids',
            'causes' => [
                'Worn valve-cover gasket',
                'Failed crankshaft / cam seal',
                'Loose / stripped sump plug',
                'Worn oil-filter seal',
            ],
        ],
        [
            'symptom' => 'Coolant leak',
            'category' => 'fluids',
            'causes' => [
                'Cracked / split hose',
                'Leaking radiator',
                'Failed water-pump seal',
                'Blown head gasket',
                'Cracked expansion tank',
            ],
        ],
    ],

];
