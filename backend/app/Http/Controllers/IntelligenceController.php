<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\GarageRecommendationDecision;
use App\Models\Vehicle;
use App\Services\CostIntelligenceService;
use App\Services\Garage\DecisionLearning;
use App\Services\Explainability\ExplanationContext;
use App\Services\Explainability\ExplanationEngine;
use App\Services\FinancialExplanationService;
use App\Services\MaintenanceCheckpointService;
use App\Services\MaintenanceForecastService;
use App\Services\MaintenanceOpsCenterService;
use App\Services\VehicleFinancialBreakdownService;
use Illuminate\Http\Request;

/**
 * Fleet Intelligence — read-only Phase-1 analytics surfaces. Each action delegates to a service and
 * returns the standard { data } envelope; authorization is the route's insights.view middleware
 * (no in-controller checks, matching the other read controllers). Frontend visibility of these
 * surfaces is gated by the SHOW_FLEET_INTELLIGENCE flag.
 */
class IntelligenceController extends Controller
{
    /**
     * Maintenance cost per km / day / rental, per vehicle + a rental-segment rollup + fleet totals.
     * Optional ?from=Y-m-d&to=Y-m-d windows the maintenance spend, rentals and every derived total.
     */
    public function cost(Request $request, CostIntelligenceService $cost)
    {
        try {
            $from = $request->query('from');
            $to   = $request->query('to');

            $payload = $cost->fleet(is_string($from) ? $from : null, is_string($to) ? $to : null);

            // ?vehicle_id= narrows the per-car list to ONE car — what the vehicle profile's Cost
            // Intelligence panel asks for. The `summary` (the fleet figures) is deliberately left whole:
            // the panel's whole point is reading this car's cost/km against the fleet's, and a fleet
            // figure computed from one car is not a comparison. Filtered here rather than in the service
            // so both surfaces keep answering from the same computation.
            if ($vehicleId = (int) $request->query('vehicle_id')) {
                $payload['vehicles'] = array_values(array_filter(
                    $payload['vehicles'] ?? [],
                    fn ($r) => (int) ($r['vehicle_id'] ?? 0) === $vehicleId,
                ));
            }

            return ResponseHelper::SuccessResponse(
                $payload,
                'Cost intelligence retrieved successfully',
                200,
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Recommendation Intelligence — is the garage engine's advice actually being taken, and where is it
     * being overruled?
     *
     * ⚠️ Returns TWO acceptance figures and the UI must keep them apart. `adjusted_pct` excludes
     * overrides on axes the engine deliberately does not model (a customer asking for a specific
     * workshop, a standing relationship) and is the only one that judges the engine; `acceptance_pct`
     * counts every override and exists to show the difference. Blending them produces a number that
     * gets worse the better the operation serves its customers.
     *
     * Vendor names are resolved HERE rather than in the pure service, so the analysis stays DB-free.
     */
    public function recommendationLearning(Request $request, DecisionLearning $learning)
    {
        try {
            $days = max(1, min(730, (int) ($request->query('days') ?: 180)));

            $rows = GarageRecommendationDecision::query()
                ->where('created_at', '>=', now()->subDays($days))
                ->get(['recommended_vendor_id', 'chosen_vendor_id', 'followed', 'override_reason', 'override_note', 'score_gap', 'chosen_advantages', 'chosen_rank', 'created_at'])
                ->map(fn ($d) => [
                    'recommended_vendor_id' => $d->recommended_vendor_id,
                    'chosen_vendor_id'      => $d->chosen_vendor_id,
                    'followed'              => $d->followed,
                    'override_reason'       => $d->override_reason,
                    'score_gap'             => $d->score_gap,
                    'chosen_advantages'     => $d->chosen_advantages,
                    'chosen_rank'           => $d->chosen_rank,
                ])
                ->all();

            $report = $learning->report($rows);

            // Name the garages in the repeat-substitution list — vendor ids are unreadable in a UI.
            $ids = collect($report['repeat_pairs'])->flatMap(fn ($p) => [$p['recommended_vendor_id'], $p['chosen_vendor_id']])->unique()->all();
            $names = $ids ? \App\Models\Vendor::whereIn('id', $ids)->pluck('name', 'id') : collect();
            $report['repeat_pairs'] = array_map(fn ($p) => $p + [
                'recommended_garage' => $names[$p['recommended_vendor_id']] ?? null,
                'chosen_garage'      => $names[$p['chosen_vendor_id']] ?? null,
            ], $report['repeat_pairs']);

            // The taxonomy travels with the report so the UI never hard-codes reason labels — the
            // config is the single place a reason is named.
            $report['taxonomy'] = (array) config('garage_recommendation.override_reasons', []);
            $report['window_days'] = $days;

            return ResponseHelper::SuccessResponse($report, 'Recommendation learning retrieved successfully', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** Fleet Service-Due board — overdue + due-soon cars + status counts (reuses the forecast engine). */
    public function serviceDue(MaintenanceForecastService $forecast)
    {
        try {
            return ResponseHelper::SuccessResponse($forecast->board(), 'Service-due board retrieved successfully', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Maintenance Operations Center board — the actionable service-due list enriched with the weighted
     * Maintenance Priority Score, risk level, active-maintenance context and a Recommended Next Action
     * per vehicle, plus the attention-driving KPI summary. Composes existing engines (no new logic).
     */
    public function maintenanceOps(MaintenanceOpsCenterService $ops)
    {
        try {
            return ResponseHelper::SuccessResponse($ops->board(), 'Maintenance operations board retrieved successfully', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * The Vehicle Maintenance Context drawer — health, active maintenance (+ checkpoint), service
     * timeline, recent history and the recommended action for one car. Sensitive: insights.view.
     */
    public function maintenanceOpsVehicle(Vehicle $vehicle, MaintenanceOpsCenterService $ops, MaintenanceCheckpointService $checkpoints)
    {
        try {
            return ResponseHelper::SuccessResponse($ops->vehicleDetail($vehicle, $checkpoints), 'Vehicle maintenance detail retrieved successfully', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Full financial traceability for ONE vehicle — the drill-down behind every Profitability number
     * (revenue sources, maintenance tickets, operating costs, net formula, depreciation/economic).
     * Header totals are identical to the /Profitability row (same engine). Sensitive: insights.view.
     */
    public function financialBreakdown(Vehicle $vehicle, VehicleFinancialBreakdownService $breakdown)
    {
        try {
            return ResponseHelper::SuccessResponse($breakdown->forVehicle($vehicle), 'Vehicle financial breakdown retrieved successfully', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Recursive EXPLANATION tree for ONE vehicle — every Profitability / Cost-Intelligence figure as a
     * drillable node graph (formula + reconciliation + source records) down to the original contract,
     * payment, invoice line or purchase record. Pure explanation layer over the same engines.
     */
    public function financialExplain(Vehicle $vehicle, FinancialExplanationService $explain)
    {
        try {
            return ResponseHelper::SuccessResponse($explain->forVehicle($vehicle), 'Vehicle financial explanation retrieved successfully', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Explainability platform — the full multi-module explanation graph for one vehicle (Finance +
     * Service, extensible). Every figure is a self-describing node with formula, dependencies, reverse
     * dependencies, business rule, evidence, reconciliation, confidence, audit and snapshot support.
     * Query: ?as_of=YYYY-MM-DD (snapshot) & modules=finance,service (subset). Sensitive: insights.view.
     */
    public function explain(Vehicle $vehicle, Request $request, ExplanationEngine $engine)
    {
        try {
            $asOf = $request->query('as_of');
            $asOf = (is_string($asOf) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) ? $asOf : null;
            $modules = $request->query('modules');
            $modules = is_string($modules) && $modules !== '' ? array_filter(array_map('trim', explode(',', $modules))) : null;

            $context = new ExplanationContext(asOf: $asOf, filters: $modules ? ['modules' => $modules] : []);

            return ResponseHelper::SuccessResponse($engine->forVehicle($vehicle, $context, $modules), 'Vehicle explanation graph retrieved successfully', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

}
