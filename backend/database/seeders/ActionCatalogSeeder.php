<?php

namespace Database\Seeders;

use App\Models\ActionCatalog;
use Illuminate\Database\Seeder;

/**
 * The starting action vocabulary for a rental fleet.
 *
 * SIZED FOR THIS FLEET, NOT FOR A DEALERSHIP. Roughly ninety actions covers the work that actually
 * happens here, drawn from the 64 catalogued faults and the 108 curated root causes rather than
 * authored blind — every root cause implies the repair that addresses it. A thousand-entry catalogue
 * copied from a labour-time manual would be more complete and far worse: technicians would not find
 * the right row, and the ones they picked would be inconsistent.
 *
 * Verification actions are seeded alongside repairs on purpose. Road-testing a car is something that
 * was done, at a cost, and belongs in the same list — flagged so a repair claiming completion with
 * no verification against it stands out without anyone building a report to look for it.
 */
class ActionCatalogSeeder extends Seeder
{
    /** [slug, verb, target, label, category, requires_part, hours, is_verification, compatible_systems] */
    private const ACTIONS = [
        // --- brakes ---------------------------------------------------------------------------
        ['replace_brake_pads',       'replace', 'brake_pads',       'Replace brake pads',            'brakes', true,  1.2, false, ['brakes']],
        ['replace_brake_discs',      'replace', 'brake_discs',      'Replace brake discs',           'brakes', true,  1.8, false, ['brakes']],
        ['machine_brake_disc',       'machine', 'brake_disc',       'Machine / skim brake disc',     'brakes', false, 1.0, false, ['brakes']],
        ['replace_brake_caliper',    'replace', 'brake_caliper',    'Replace brake caliper',         'brakes', true,  1.5, false, ['brakes']],
        ['repair_brake_caliper',     'repair',  'brake_caliper',    'Free off / rebuild caliper',    'brakes', false, 1.2, false, ['brakes']],
        ['bleed_brake_system',       'bleed',   'brake_system',     'Bleed brake system',            'brakes', false, 0.6, false, ['brakes', 'transmission']],
        ['replace_brake_fluid',      'replace', 'brake_fluid',      'Replace brake fluid',           'brakes', true,  0.6, false, ['brakes']],
        ['replace_brake_hose',       'replace', 'brake_hose',       'Replace brake hose / line',     'brakes', true,  1.0, false, ['brakes']],
        ['adjust_handbrake',         'adjust',  'handbrake',        'Adjust handbrake',              'brakes', false, 0.4, false, ['brakes']],
        ['replace_abs_sensor',       'replace', 'abs_sensor',       'Replace ABS sensor',            'brakes', true,  0.8, false, ['brakes', 'electrical']],

        // --- engine ---------------------------------------------------------------------------
        ['replace_engine_oil',       'replace', 'engine_oil',       'Change engine oil',             'engine', true,  0.5, false, ['engine', 'routine']],
        ['replace_oil_filter',       'replace', 'oil_filter',       'Replace oil filter',            'engine', true,  0.2, false, ['engine', 'routine']],
        ['replace_air_filter',       'replace', 'air_filter',       'Replace air filter',            'engine', true,  0.3, false, ['engine', 'routine']],
        ['replace_fuel_filter',      'replace', 'fuel_filter',      'Replace fuel filter',           'engine', true,  0.8, false, ['engine']],
        ['replace_spark_plugs',      'replace', 'spark_plugs',      'Replace spark plugs',           'engine', true,  1.0, false, ['engine']],
        ['replace_ignition_coil',    'replace', 'ignition_coil',    'Replace ignition coil',         'engine', true,  0.8, false, ['engine', 'electrical']],
        ['replace_fuel_injector',    'replace', 'fuel_injector',    'Replace fuel injector',         'engine', true,  1.8, false, ['engine']],
        ['clean_fuel_injector',      'clean',   'fuel_injector',    'Clean fuel injectors',          'engine', false, 1.2, false, ['engine']],
        ['clean_throttle_body',      'clean',   'throttle_body',    'Clean throttle body',           'engine', false, 0.8, false, ['engine']],
        ['replace_timing_chain',     'replace', 'timing_chain',     'Replace timing chain / belt',   'engine', true,  6.0, false, ['engine']],
        ['replace_valve_cover_gasket', 'replace', 'valve_cover_gasket', 'Replace valve-cover gasket', 'engine', true, 1.5, false, ['engine']],
        ['replace_crank_seal',       'replace', 'crank_seal',       'Replace crank / cam seal',      'engine', true,  3.0, false, ['engine']],
        ['replace_head_gasket',      'replace', 'head_gasket',      'Replace head gasket',           'engine', true, 10.0, false, ['engine']],
        ['replace_engine_mount',     'replace', 'engine_mount',     'Replace engine mount',          'engine', true,  1.5, false, ['engine']],
        ['replace_accessory_belt',   'replace', 'accessory_belt',   'Replace accessory belt',        'engine', true,  0.8, false, ['engine']],
        ['replace_sump_plug',        'replace', 'sump_plug',        'Replace sump plug / washer',    'engine', true,  0.2, false, ['engine']],
        ['repair_vacuum_leak',       'repair',  'vacuum_line',      'Repair vacuum leak',            'engine', false, 1.0, false, ['engine']],
        ['replace_oxygen_sensor',    'replace', 'oxygen_sensor',    'Replace oxygen sensor',         'engine', true,  0.8, false, ['engine', 'electrical']],

        // --- cooling --------------------------------------------------------------------------
        ['replace_coolant',          'replace', 'coolant',          'Replace coolant',               'fluids', true,  0.8, false, ['engine', 'fluids']],
        ['top_up_coolant',           'top_up',  'coolant',          'Top up coolant',                'fluids', true,  0.2, false, ['engine', 'fluids']],
        ['replace_radiator',         'replace', 'radiator',         'Replace radiator',              'engine', true,  2.5, false, ['engine']],
        ['clean_radiator',           'clean',   'radiator',         'Flush / clean radiator',        'engine', false, 1.5, false, ['engine']],
        ['replace_water_pump',       'replace', 'water_pump',       'Replace water pump',            'engine', true,  3.0, false, ['engine']],
        ['replace_thermostat',       'replace', 'thermostat',       'Replace thermostat',            'engine', true,  1.2, false, ['engine']],
        ['replace_coolant_hose',     'replace', 'coolant_hose',     'Replace coolant hose',          'engine', true,  0.8, false, ['engine']],
        ['replace_cooling_fan',      'replace', 'cooling_fan',      'Replace cooling fan',           'engine', true,  1.5, false, ['engine', 'electrical']],
        ['replace_expansion_tank',   'replace', 'expansion_tank',   'Replace expansion tank',        'engine', true,  0.8, false, ['engine']],

        // --- electrical -----------------------------------------------------------------------
        ['replace_battery',          'replace', 'battery',          'Replace battery',               'electrical', true, 0.4, false, ['electrical', 'routine']],
        ['clean_battery_terminals',  'clean',   'battery_terminals','Clean battery terminals',       'electrical', false, 0.3, false, ['electrical']],
        ['replace_alternator',       'replace', 'alternator',       'Replace alternator',            'electrical', true, 2.0, false, ['electrical']],
        ['replace_starter_motor',    'replace', 'starter_motor',    'Replace starter motor',         'electrical', true, 2.0, false, ['electrical']],
        ['repair_wiring',            'repair',  'wiring',           'Repair wiring / connector',     'electrical', false, 1.5, false, ['electrical']],
        ['replace_fuse',             'replace', 'fuse',             'Replace fuse / relay',          'electrical', true, 0.2, false, ['electrical']],
        ['reset_warning_light',      'reset',   'warning_light',    'Clear warning light',           'electrical', false, 0.2, false, ['electrical']],
        ['program_key_fob',          'program', 'key_fob',          'Program key fob',               'electrical', false, 0.5, false, ['electrical']],
        ['replace_window_regulator', 'replace', 'window_regulator', 'Replace window regulator',      'electrical', true, 1.5, false, ['electrical', 'interior']],

        // --- A/C ------------------------------------------------------------------------------
        ['recharge_ac_gas',          'top_up',  'ac_refrigerant',   'Recharge A/C gas',              'ac', true,  1.0, false, ['ac']],
        ['replace_ac_compressor',    'replace', 'ac_compressor',    'Replace A/C compressor',        'ac', true,  3.0, false, ['ac']],
        ['replace_cabin_filter',     'replace', 'cabin_filter',     'Replace cabin filter',          'ac', true,  0.3, false, ['ac', 'routine']],
        ['clean_ac_evaporator',      'clean',   'ac_evaporator',    'Clean / disinfect evaporator',  'ac', false, 1.5, false, ['ac']],
        ['repair_ac_leak',           'repair',  'ac_system',        'Repair A/C leak',               'ac', false, 2.0, false, ['ac']],
        ['replace_blower_motor',     'replace', 'blower_motor',     'Replace blower motor',          'ac', true,  1.5, false, ['ac', 'electrical']],
        ['replace_ac_condenser',     'replace', 'ac_condenser',     'Replace A/C condenser',         'ac', true,  2.0, false, ['ac']],

        // --- suspension & steering --------------------------------------------------------------
        ['replace_shock_absorber',   'replace', 'shock_absorber',   'Replace shock / strut',         'suspension', true, 2.0, false, ['suspension']],
        ['replace_control_arm',      'replace', 'control_arm',      'Replace control arm',           'suspension', true, 2.0, false, ['suspension']],
        ['replace_ball_joint',       'replace', 'ball_joint',       'Replace ball joint',            'suspension', true, 1.5, false, ['suspension']],
        ['replace_bush',             'replace', 'bush',             'Replace suspension bush',       'suspension', true, 1.5, false, ['suspension']],
        ['replace_wheel_bearing',    'replace', 'wheel_bearing',    'Replace wheel bearing',         'suspension', true, 2.0, false, ['suspension']],
        ['replace_link_rod',         'replace', 'link_rod',         'Replace anti-roll bar link',    'suspension', true, 0.8, false, ['suspension']],
        ['replace_tie_rod',          'replace', 'tie_rod',          'Replace tie rod end',           'suspension', true, 1.2, false, ['suspension']],
        ['replace_power_steering_pump', 'replace', 'power_steering_pump', 'Replace power-steering pump', 'suspension', true, 2.5, false, ['suspension']],
        ['top_up_power_steering_fluid', 'top_up', 'power_steering_fluid', 'Top up power-steering fluid', 'fluids', true, 0.2, false, ['suspension', 'fluids']],

        // --- tyres & wheels ---------------------------------------------------------------------
        ['replace_tyre',             'replace', 'tyre',             'Replace tyre',                  'tyres', true,  0.5, false, ['tyres']],
        ['repair_puncture',          'repair',  'tyre',             'Repair puncture',               'tyres', false, 0.4, false, ['tyres']],
        ['align_wheels',             'adjust',  'wheel_alignment',  'Wheel alignment',               'tyres', false, 1.0, false, ['tyres', 'suspension']],
        ['balance_wheels',           'adjust',  'wheel_balance',    'Wheel balancing',               'tyres', false, 0.6, false, ['tyres']],
        ['rotate_tyres',             'adjust',  'tyre_position',    'Rotate tyres',                  'tyres', false, 0.5, false, ['tyres', 'routine']],
        ['tighten_wheel_nuts',       'tighten', 'wheel_nuts',       'Torque wheel nuts',             'tyres', false, 0.2, false, ['tyres']],
        ['replace_tpms_sensor',      'replace', 'tpms_sensor',      'Replace TPMS sensor',           'tyres', true,  0.6, false, ['tyres', 'electrical']],
        ['reset_tpms',               'reset',   'tpms',             'Reset TPMS',                    'tyres', false, 0.2, false, ['tyres']],

        // --- transmission -----------------------------------------------------------------------
        ['replace_transmission_oil', 'replace', 'transmission_oil', 'Replace transmission oil',      'transmission', true, 1.5, false, ['transmission']],
        ['replace_clutch',           'replace', 'clutch',           'Replace clutch',                'transmission', true, 5.0, false, ['transmission']],
        ['adjust_clutch',            'adjust',  'clutch',           'Adjust clutch',                 'transmission', false, 0.6, false, ['transmission']],
        ['replace_gearbox_mount',    'replace', 'gearbox_mount',    'Replace gearbox mount',         'transmission', true, 1.5, false, ['transmission']],
        ['replace_cv_joint',         'replace', 'cv_joint',         'Replace CV joint / driveshaft', 'transmission', true, 2.0, false, ['transmission']],

        // --- fluids & leaks ---------------------------------------------------------------------
        ['top_up_engine_oil',        'top_up',  'engine_oil',       'Top up engine oil',             'fluids', true,  0.2, false, ['engine', 'fluids']],
        ['repair_oil_leak',          'repair',  'oil_leak',         'Repair oil leak',               'fluids', false, 2.0, false, ['engine', 'fluids']],
        ['repair_fuel_leak',         'repair',  'fuel_system',      'Repair fuel leak',              'fluids', false, 2.0, false, ['engine', 'fluids']],

        // --- body, lights, interior ---------------------------------------------------------------
        ['replace_headlight',        'replace', 'headlight',        'Replace headlight / bulb',      'lights', true,  0.5, false, ['lights']],
        ['replace_tail_light',       'replace', 'tail_light',       'Replace tail / brake light',    'lights', true,  0.4, false, ['lights']],
        ['replace_wiper_blades',     'replace', 'wiper_blades',     'Replace wiper blades',          'lights', true,  0.2, false, ['lights', 'routine']],
        ['repair_body_panel',        'repair',  'body_panel',       'Repair body panel',             'bodywork', false, 4.0, false, ['bodywork']],
        ['replace_body_panel',       'replace', 'body_panel',       'Replace body panel',            'bodywork', true,  5.0, false, ['bodywork']],
        ['paint_panel',              'repair',  'paint',            'Paint / refinish panel',        'bodywork', true,  4.0, false, ['bodywork']],
        ['replace_windscreen',       'replace', 'windscreen',       'Replace windscreen',            'bodywork', true,  2.0, false, ['bodywork']],
        ['replace_mirror',           'replace', 'mirror',           'Replace mirror',                'bodywork', true,  0.6, false, ['bodywork']],
        ['clean_interior',           'clean',   'interior',         'Deep clean interior',           'interior', false, 2.0, false, ['interior']],
        ['lubricate_hinges',         'lubricate', 'hinges',         'Lubricate hinges / locks',      'bodywork', false, 0.3, false, ['bodywork']],

        // --- verification: proving the work, not doing it -----------------------------------------
        ['road_test',                'test',    'vehicle',          'Road test',                     null, false, 0.5, true,  []],
        ['scan_diagnostics',         'test',    'ecu',              'Diagnostic scan',               null, false, 0.5, true,  []],
        ['pressure_test',            'test',    'system',           'Pressure test',                 null, false, 0.8, true,  []],
        ['visual_inspection',        'inspect', 'vehicle',          'Visual inspection',             null, false, 0.3, true,  []],
        ['measure_component',        'measure', 'component',        'Measure component wear',        null, false, 0.3, true,  []],
        ['leak_test',                'test',    'system',           'Leak test / dye check',         null, false, 0.8, true,  []],
        // Added after the ontology referenced it and the seeder's unknown-slug guard flagged the gap
        // — a battery/charging test is a distinct verification from a generic measurement, and the
        // fleet's highest-frequency category (electrical) needs it.
        ['test_battery',             'test',    'battery',          'Battery & charging test',       'electrical', false, 0.3, true, ['electrical']],
        ['test_ac_performance',      'test',    'ac_system',        'A/C performance test',          'ac', false, 0.5, true, ['ac']],
    ];

    public function run(): void
    {
        $order = 0;

        foreach (self::ACTIONS as [$slug, $verb, $target, $label, $category, $requiresPart, $hours, $isVerification, $systems]) {
            $action = ActionCatalog::firstOrNew(['slug' => $slug]);

            $action->fill([
                'verb'                => $verb,
                'target'              => $target,
                'label'               => $label,
                'category_key'        => $category,
                'compatible_systems'  => $systems ?: null,
                'requires_part'       => $requiresPart,
                'is_verification'     => $isVerification,
                'default_labor_hours' => $hours,
                'is_active'           => true,
                'sort_order'          => $order += 10,
            ])->save();
        }

        $total = count(self::ACTIONS);
        $verify = count(array_filter(self::ACTIONS, fn ($a) => $a[7]));

        $this->command?->info("Action catalog: {$total} action(s), {$verify} of them verification.");
    }
}
