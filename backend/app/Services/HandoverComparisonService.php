<?php

namespace App\Services;

use App\Models\MaintenanceHandover;

/**
 * Handover Comparison Report — diffs a pause-leg handover against its matching resume-leg handover
 * into the permanent record attached to the ticket's history (see MaintenanceHandoverComparison).
 *
 * Pure classification, like OdometerContinuityService: never throws, never blocks. `compare()` only
 * describes what changed and whether any dimension breached its configured threshold
 * (config/maintenance_handover.php) — the caller (MaintenanceWorkflowService) decides what to do with
 * a breach (raise a MaintenanceIncident and gate the resume).
 */
class HandoverComparisonService
{
    public function __construct(private OdometerContinuityService $continuity)
    {
    }

    /**
     * @return array{
     *   mileage_delta:int,
     *   odometer_classification:array,
     *   fuel_delta:int,
     *   new_damages:array,
     *   missing_accessories:array,
     *   condition_changes:array,
     *   exceeds_threshold:bool,
     *   threshold_breaches:array,
     * }
     */
    public function compare(MaintenanceHandover $pause, MaintenanceHandover $resume): array
    {
        $thresholds = config('maintenance_handover.thresholds', []);
        $fuelScale  = config('maintenance_handover.fuel_scale', []);

        $pauseReading  = (int) ($pause->odometer_reading ?? 0);
        $resumeReading = (int) ($resume->odometer_reading ?? 0);
        $mileageDelta  = $resumeReading - $pauseReading;

        $classification = $this->continuity->evaluate($resumeReading, $pauseReading, OdometerContinuityService::STAGE_RETURN);

        $fuelDelta = $this->fuelIndex($resume->fuel_level, $fuelScale) - $this->fuelIndex($pause->fuel_level, $fuelScale);

        $newDamages = $this->newDamages($pause->damage_findings ?? [], $resume->damage_findings ?? []);

        $missingAccessories = array_values(array_diff(
            $resume->missing_accessories ?? [],
            $pause->missing_accessories ?? []
        ));

        $conditionChanges = [];
        if ($pause->exterior_condition !== null && $resume->exterior_condition !== null
            && $pause->exterior_condition !== $resume->exterior_condition) {
            $conditionChanges['exterior'] = [$pause->exterior_condition, $resume->exterior_condition];
        }
        if ($pause->interior_condition !== null && $resume->interior_condition !== null
            && $pause->interior_condition !== $resume->interior_condition) {
            $conditionChanges['interior'] = [$pause->interior_condition, $resume->interior_condition];
        }

        $breaches = [];

        $rollbackTolerance = (int) ($thresholds['odometer_rollback_km'] ?? 5);
        if ($mileageDelta < 0 && abs($mileageDelta) > $rollbackTolerance) {
            $breaches[] = [
                'field'  => 'odometer',
                'detail' => 'Odometer rolled back ' . abs($mileageDelta) . ' km since the pause reading (' . number_format($pauseReading) . ' km → ' . number_format($resumeReading) . ' km).',
            ];
        }

        $fuelDropTolerance = (int) ($thresholds['fuel_drop_levels'] ?? 2);
        if ($fuelDelta < 0 && abs($fuelDelta) >= $fuelDropTolerance) {
            $breaches[] = [
                'field'  => 'fuel_level',
                'detail' => 'Fuel dropped ' . abs($fuelDelta) . ' level(s) since the pause handover (' . $pause->fuel_level . ' → ' . $resume->fuel_level . ').',
            ];
        }

        foreach ($newDamages as $damage) {
            $breaches[] = [
                'field'  => 'new_damage',
                'detail' => 'New damage found at return: ' . ($damage['location'] ?? 'unknown location')
                    . ($damage['severity'] ? ' (' . $damage['severity'] . ')' : '') . '.',
            ];
        }

        foreach ($missingAccessories as $accessory) {
            $breaches[] = [
                'field'  => 'missing_accessory',
                'detail' => 'Accessory missing at return: ' . $accessory . '.',
            ];
        }

        return [
            'mileage_delta'            => $mileageDelta,
            'odometer_classification'  => $classification,
            'fuel_delta'               => $fuelDelta,
            'new_damages'              => $newDamages,
            'missing_accessories'      => $missingAccessories,
            'condition_changes'        => $conditionChanges,
            'exceeds_threshold'        => ! empty($breaches),
            'threshold_breaches'       => $breaches,
        ];
    }

    /** Position of a fuel level on the configured scale, or 0 (no signal) when unknown/missing. */
    private function fuelIndex(?string $level, array $scale): int
    {
        if ($level === null) {
            return 0;
        }
        $idx = array_search($level, $scale, true);
        return $idx === false ? 0 : $idx;
    }

    /**
     * Damage findings present on the resume handover that weren't already on the pause handover,
     * matched by (location, severity) — a new damage worth flagging, not a re-listing of a pre-existing
     * one. Guards every array access so a malformed/legacy row never throws.
     */
    private function newDamages(array $pauseDamages, array $resumeDamages): array
    {
        $seen = collect($pauseDamages)
            ->map(fn ($d) => ($d['location'] ?? '') . '|' . ($d['severity'] ?? ''))
            ->flip();

        return collect($resumeDamages)
            ->filter(fn ($d) => is_array($d) && ! $seen->has(($d['location'] ?? '') . '|' . ($d['severity'] ?? '')))
            ->values()
            ->all();
    }
}
