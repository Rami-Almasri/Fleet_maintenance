<?php

/**
 * SHEET → FAULT CATALOG BRIDGE — the missing rung between the two fault vocabularies.
 *
 * The fleet names the same fault twice, in two closed vocabularies that were written years apart and
 * barely touch:
 *
 *   • The N-Maintenance sheet's `service_sup` column — 92 labels, 72 of them faults. Its own wording:
 *     "Battery Weak or Dead", "Brake Pad Wear", "Gearbox Not Engaging".
 *   • `fault_catalog` — 81 slugged rows, the menu the ticket workflow's finding picker offers:
 *     "Battery / won't start", "Worn pads / discs", "Cannot select gear".
 *
 * Measured overlap: FOUR of the 92 sheet labels match a catalog name exactly. So an engine that compares
 * wording finds nothing across the two ledgers, and one that falls back to `FaultVocabulary::categoryOf`
 * merges at SYSTEM grain — where every electrical fault is the recurrence of every other electrical
 * fault. Neither is the answer a technician needs when they tap "Battery Replacement" on a test drive.
 *
 * This file is that missing rung, and it is DATA, not inference. Every row is a human ruling that two
 * names denote one fault ([[treat-data-as-source-of-truth]] — nothing here is scored or guessed at
 * runtime). Ambiguous sheet labels are deliberately ABSENT rather than mapped to a plausible slug:
 * "Squeaking or Grinding Noise" could be brakes or suspension, "Ignition Issues" could be three
 * different catalog rows. An absent label still resolves to its category and still recurs against
 * itself — it simply never claims to be the same fault as a catalog row it might not be.
 *
 * Adding a row is safe and needs no migration. Removing one only ever weakens a match back to category
 * grain. `php artisan maintenance:fault-extraction-audit` prints live coverage.
 *
 * @see \App\Support\FaultVocabulary::catalogSlugOf()
 * @see config/fault_catalog.php   the slugs referenced below
 */

return [

    /**
     * Sheet `service_sup` label => `fault_catalog.slug`.
     *
     * Matched on the NORMALISED label (lowercase, punctuation collapsed), so "Engine Oil leak" and
     * "Engine Oil Leak" — both of which the sheet writes — need only one row.
     */
    'catalog' => [

        // ── Electrical ──────────────────────────────────────────────────────────────────────────
        'Battery Weak or Dead'            => 'elec_battery_failure',
        'Dashboard Warning Lights'        => 'elec_dash_warning',
        'Central Locking Issue'           => 'elec_central_locking',
        'Power Windows Not Working'       => 'elec_window_lock',
        'Wiring / Short Circuit'          => 'elec_wiring_fuse',
        'Fuse Blown'                      => 'elec_wiring_fuse',

        // ── Brakes ──────────────────────────────────────────────────────────────────────────────
        'Brake Pad Wear'                  => 'brake_worn_pads',
        'ABS Warning Light'               => 'brake_abs_light',
        'Brake Pedal Vibration'           => 'brake_vibration',
        'Soft or Spongy Brake Pedal'      => 'brake_soft_pedal',

        // ── Engine ──────────────────────────────────────────────────────────────────────────────
        'Engine Overheating'              => 'engine_overheating',
        'Overheating without Leak'        => 'engine_overheating',
        'Exhaust Leak'                    => 'engine_exhaust_fault',
        'Exhaust Sensor Fault'            => 'engine_exhaust_fault',
        'Modified Exhaust Issue'          => 'engine_exhaust_fault',
        'Excessive Emissions'             => 'engine_exhaust_smoke',
        'Fuel Pump Failure'               => 'engine_fuel_system',
        'Fuel Injector Problem'           => 'engine_fuel_system',
        'Fuel Tank Cap Issue'             => 'engine_fuel_system',
        'EVAP System Fault'               => 'engine_fuel_system',

        // ── Fluids & leaks ──────────────────────────────────────────────────────────────────────
        'Engine Oil leak'                 => 'fluid_oil_leak',
        'Coolant Leak'                    => 'fluid_coolant_leak',
        'Radiator Leak'                   => 'fluid_coolant_leak',
        'Coolant Hose Burst'              => 'fluid_coolant_leak',
        'Coolant Reservoir Crack'         => 'fluid_coolant_leak',

        // ── Transmission ────────────────────────────────────────────────────────────────────────
        'Gearbox Not Engaging'            => 'trans_cannot_select',
        'Jerking / Slipping Transmission' => 'trans_gear_slipping',
        'Gear Shifting Delay'             => 'trans_delayed_engage',
        'Transmission Fluid Leak'         => 'trans_fluid_leak',

        // ── Tyres ───────────────────────────────────────────────────────────────────────────────
        'Flat Tire'                       => 'tyre_puncture',
        'Tire Air Leak'                   => 'tyre_puncture',
        'Uneven Tire Wear'                => 'tyre_uneven_wear',
        'Tire Aging / Cracks'             => 'tyre_worn',
        'Tire Pressure Sensor Fault'      => 'tyre_tpms_warning',

        // ── Suspension & steering ───────────────────────────────────────────────────────────────
        'Steering Instability'            => 'susp_loose_steering',
        'Suspension Noise on Bumps'       => 'susp_knocking',

        // ── A/C ─────────────────────────────────────────────────────────────────────────────────
        'AC Not Cooling'                  => 'ac_not_cooling',
        'Bad AC Smell'                    => 'ac_bad_smell',

        // ── Safety ──────────────────────────────────────────────────────────────────────────────
        'Airbag'                          => 'safety_airbag',
        'Seatbelt Malfunction'            => 'safety_seatbelt',
        'Seatbelts'                       => 'safety_seatbelt',
        'Camera System Issue'             => 'safety_camera_adas',

        // ── Interior ────────────────────────────────────────────────────────────────────────────
        'LCD / Infotainment Fault'        => 'int_infotainment',
        'Seat Adjustment Fault'           => 'int_seat_adjust',
    ],

    /**
     * Sheet label => canonical CATEGORY key, for labels with no single honest catalog row.
     *
     * These 26-odd labels resolve to no category at all today (FaultVocabulary::categoryOf returns
     * null), so each becomes its own isolated bucket that can never join a ticket fault. Giving them a
     * system is a strictly weaker claim than giving them a slug, and it is one we can actually make:
     * "Thermostat Failure" is unambiguously a cooling-system fault even though it names no catalog row.
     */
    'category' => [
        'Squeaking or Grinding Noise'   => 'brakes',
        'Compressor Failure'            => 'ac',
        'AC Control Panel Fault'        => 'ac',
        'AC Blower Motor Issue'         => 'ac',
        'Thermostat Failure'            => 'fluids',
        'Water Pump Failure'            => 'fluids',
        'Headlights / Taillights Fault' => 'lights',
        'LED / Light Accessory Issue'   => 'lights',
        'ACC Programming Error'         => 'electrical',
        'ECU Reprogramming Side Effect' => 'electrical',
        'OEM Sound System Fault'        => 'electrical',
        'Non-OEM Screen Fault'          => 'interior',
        'Dashboard Rattling'            => 'interior',
        'Turbo Sensor Failure'          => 'engine',
        'Oil and Coolant Mixing'        => 'engine',
        'Suspension Bushing Damage'     => 'suspension',
        'Steering box issue'            => 'suspension',

        // These name real catalog faults but ALSO happen to be category-shaped words the keyword
        // resolver claims first, which split one fault across two buckets — "Seatbelts" and "Seatbelt
        // Malfunction" were two separate recurring faults on the same cars.
        'Airbag'                        => 'safety',
        'Seatbelts'                     => 'safety',
        'Seatbelt Malfunction'          => 'safety',
        'Camera System Issue'           => 'safety',
        'Sensor Add-on Malfunction'     => 'safety',
    ],
];
