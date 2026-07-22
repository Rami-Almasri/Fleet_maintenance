<?php

namespace App\Services\Explainability;

use App\Models\Vehicle;
use App\Services\MaintenanceForecastService;

/**
 * Maintenance-Intelligence module on the SAME Explainability platform — proof that the graph is not a
 * finance feature but a shared platform. It explains a DECISION, not a transaction: WHY a car is (or is
 * not) due for service, as a business-rule node with its measured inputs, threshold, evidence and the
 * action it triggered.
 *
 * Every value comes straight from the authoritative engines (Vehicle::serviceStatus() for the km rule,
 * MaintenanceForecastService for the usage-rate projection). This explainer only structures them —
 * and, crucially, grades each input's confidence (a MEASURED odometer vs an ESTIMATED usage rate).
 */
class ServiceDueExplainer implements Explainer
{
    public function __construct(private MaintenanceForecastService $forecast) {}

    public function module(): string
    {
        return 'service';
    }

    public function label(): string
    {
        return 'Service Due';
    }

    public function explain(Vehicle $vehicle, ExplanationGraph $graph, ExplanationContext $context): array
    {
        $f = $this->forecast->forecast($vehicle);
        $status = $f['status'];                 // overdue | due_soon | ok | no_data
        $current  = $f['current'];
        $baseline = $f['baseline'];
        $interval = $f['interval'];
        $remaining = $f['remaining_km'];
        $distance  = ($current !== null && $baseline !== null) ? $current - $baseline : null;

        if ($status === 'no_data') {
            $ruleId = $graph->upsert([
                'id' => 'service:rule', 'type' => NodeType::RULE, 'label' => 'Service Due Rule',
                'value' => null, 'unit' => 'km', 'confidence' => Confidence::MISSING,
                'note' => 'No service baseline / interval / odometer on file — the rule cannot be evaluated for this car.',
                'business_rule' => [
                    'statement' => 'Service is due when distance since the last service ≥ the service interval.',
                    'result'    => 'Cannot evaluate — missing inputs', 'action' => 'None',
                ],
                'source_module' => 'MaintenanceForecastService',
                'audit' => $this->audit($context, 'Point-in-time (current odometer)'),
            ]);

            return ['service_status' => $ruleId, 'service' => $ruleId, 'service_due' => $ruleId];
        }

        // Evidence nodes — the measured / imported inputs the decision rests on.
        $currentId = $graph->upsert([
            'id' => 'service:current_odo', 'type' => NodeType::EVIDENCE, 'label' => 'Current odometer',
            'value' => $current, 'unit' => 'km', 'confidence' => Confidence::MEASURED,
            'record' => ['Current odometer' => $current], 'source_module' => 'Latest odometer (contracts / RTA)',
        ]);
        $baselineId = $graph->upsert([
            'id' => 'service:baseline', 'type' => NodeType::EVIDENCE, 'label' => 'Odometer at last service',
            'value' => $baseline, 'unit' => 'km', 'confidence' => Confidence::IMPORTED,
            'record' => ['Baseline odometer' => $baseline], 'source_module' => 'Last service record',
        ]);
        $intervalId = $graph->upsert([
            'id' => 'service:interval', 'type' => NodeType::EVIDENCE, 'label' => 'Service interval',
            'value' => $interval, 'unit' => 'km', 'confidence' => Confidence::IMPORTED,
            'record' => ['Service interval' => $interval], 'source_module' => 'Service policy (per car)',
        ]);

        $evidence = [
            ['label' => 'Current odometer', 'kind' => 'measurement', 'ref' => $currentId],
            ['label' => 'Odometer at last service', 'kind' => 'record', 'ref' => $baselineId],
            ['label' => 'Service interval', 'kind' => 'threshold', 'ref' => $intervalId],
        ];

        // Usage-rate projection — an ESTIMATED input (km/day from recent readings) → projected date.
        if ($f['usage_rate'] !== null) {
            $rateId = $graph->upsert([
                'id' => 'service:usage_rate', 'type' => NodeType::COMPONENT, 'label' => 'Usage rate',
                'value' => $f['usage_rate'], 'unit' => 'km/day', 'confidence' => Confidence::ESTIMATED,
                'note' => 'Average daily distance from the car\'s recent contract odometer readings.',
                'source_module' => 'MaintenanceForecastService',
            ]);
            $projId = $graph->upsert([
                'id' => 'service:projection', 'type' => NodeType::COMPONENT, 'label' => 'Projected service date',
                'value' => $f['days_to_due'], 'unit' => 'days', 'confidence' => Confidence::ESTIMATED,
                'formula' => [
                    'result_label' => 'Days to service', 'result' => $f['days_to_due'], 'result_unit' => 'days',
                    'terms' => [
                        ['op' => '',  'label' => 'Remaining distance', 'value' => $remaining, 'unit' => 'km'],
                        ['op' => '÷', 'label' => 'Usage rate', 'value' => $f['usage_rate'], 'unit' => 'km/day', 'ref' => $rateId],
                    ],
                ],
                'record' => ['Projected date' => $f['projected_date'], 'Days to due' => $f['days_to_due']],
                'source_module' => 'MaintenanceForecastService',
            ]);
            $evidence[] = ['label' => 'Usage rate', 'kind' => 'estimate', 'ref' => $rateId];
            $evidence[] = ['label' => 'Projected service date', 'kind' => 'estimate', 'ref' => $projId];
        }

        $matched = in_array($status, ['overdue', 'due_soon'], true);
        $result = match ($status) {
            'overdue'  => 'Rule matched — service is OVERDUE',
            'due_soon' => 'Approaching — within the near-due threshold',
            default    => 'Not due — serviced recently',
        };

        $ruleId = $graph->upsert([
            'id' => 'service:rule', 'type' => NodeType::RULE, 'label' => 'Service Due Rule',
            'subtitle' => $vehicle->plate_no,
            'value' => $distance, 'unit' => 'km', 'confidence' => Confidence::CALCULATED,
            'business_rule' => [
                'statement' => 'Service is due when distance since the last service ≥ the service interval.',
                'measured'  => [
                    ['label' => 'Distance since last service', 'value' => $distance, 'unit' => 'km', 'ref' => $currentId],
                ],
                'threshold' => ['label' => 'Service interval', 'value' => $interval, 'unit' => 'km', 'ref' => $intervalId],
                'operator'  => '≥',
                'result'    => $result,
                'action'    => $matched ? 'Flagged on the Service-Due board' . ($status === 'overdue' ? ' + overdue alert' : ' + due-soon alert') : 'None',
            ],
            'formula' => [
                'result_label' => 'Distance since last service', 'result' => $distance, 'result_unit' => 'km',
                'terms' => [
                    ['op' => '',  'label' => 'Current odometer', 'value' => $current, 'unit' => 'km', 'ref' => $currentId],
                    ['op' => '−', 'label' => 'Odometer at last service', 'value' => $baseline, 'unit' => 'km', 'ref' => $baselineId],
                ],
            ],
            'reconciliation' => [
                'displayed'  => $distance,
                'source_sum' => ($current ?? 0) - ($baseline ?? 0),
                'difference' => $distance - (($current ?? 0) - ($baseline ?? 0)),
                'ok'         => true,
                'basis'      => 'Current odometer − odometer at last service',
            ],
            'evidence' => $evidence,
            'source_records' => [$currentId, $baselineId, $intervalId],
            'record' => [
                'Status' => $status, 'Distance since service' => $distance, 'Interval' => $interval,
                'Remaining km' => $remaining, 'Overdue km' => $f['overdue_km'], 'Projected date' => $f['projected_date'],
            ],
            'downstream' => [['id' => 'surface:service_due', 'label' => 'Service-Due Board',
                'downstream' => [['id' => 'surface:maintenance_pulse', 'label' => 'Maintenance Pulse KPI']]]],
            'source_module' => 'MaintenanceForecastService + Vehicle::serviceStatus()',
            'audit' => $this->audit($context, 'Point-in-time (current odometer vs last service)'),
        ]);

        return ['service_status' => $ruleId, 'service' => $ruleId, 'service_due' => $ruleId];
    }

    private function audit(ExplanationContext $ctx, string $window): array
    {
        return [
            'engine_version' => $ctx->engineVersion,
            'as_of'          => $ctx->asOf,
            'window'         => $window,
            'precision'      => 0,
            'source_module'  => 'MaintenanceForecastService',
        ];
    }
}
