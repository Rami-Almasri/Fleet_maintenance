<?php

/**
 * Enterprise Handover Workflow — configured thresholds/vocabulary for the pause/resume custody
 * transfer, mirroring the config-driven-thresholds convention already used across the app
 * (OdometerContinuityService::TOLERANCE_KM style constants, config/maintenance_findings.php for
 * keyword lists). See HandoverComparisonService.
 */
return [
    // A resume odometer reading BELOW the pause reading by more than this many km is a breach (the
    // car rolled backwards while it was out — reuses the same tolerance idea as OdometerContinuityService).
    'thresholds' => [
        'odometer_rollback_km' => 5,
        // A fuel level that dropped this many notches (or more) on the fuel_scale is a breach.
        'fuel_drop_levels' => 2,
    ],

    // Fixed missing-accessories checklist (mirrors config/maintenance_findings.php's config-driven pattern).
    'accessories' => [
        'spare_tire',
        'jack',
        'first_aid_kit',
        'warning_triangle',
        'floor_mats',
        'charging_cable',
    ],

    'fuel_scale' => ['E', '1/4', '1/2', '3/4', 'F'],
];
