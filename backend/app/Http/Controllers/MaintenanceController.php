<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Maintenance;
use App\Models\MaintenanceReason;
use App\Services\MaintenanceAnalyticsService;
use App\Services\MaintenanceIncidentService;

class MaintenanceController extends Controller
{
    /**
     * The controlled maintenance reason -> status vocabulary (from the "Main reason"
     * sheet tab). Used to populate the issue-tag picker so each chosen reason carries
     * its priority colour. Grouped by level.
     */
    public function reasons()
    {
        try {
            $reasons = MaintenanceReason::orderBy('reason_en')->get()
                ->map(fn ($r) => [
                    'reason'    => $r->reason_en,
                    'reason_ar' => $r->reason_ar,
                    'level'     => $r->level,
                ])->values();

            return ResponseHelper::SuccessResponse($reasons, "Maintenance reasons retrieved successfully", 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    /**
     * The live maintenance board: every car currently in the garage (open type-U
     * contracts) with its garage, issues, due date, cost and a traffic-light SLA
     * status (green on_track / yellow at_risk / red breached).
     */
    public function board(MaintenanceAnalyticsService $analytics)
    {
        try {
            $open = Contract::where('contract_type', 'U')
                ->currentlyOpen()
                ->with(['vehicle', 'maintenance.vendor', 'maintenance.reason', 'items'])
                ->orderBy('out_date')
                ->get();

            // OfficeManager carries no maintenance detail for type-U contracts, so a car's
            // current garage visit (status / garage / issues / notes) is read from the
            // "N-Maintenance & Repair" sheet log — the latest event per vehicle — whenever
            // no maintenance header was entered by hand in FleetView.
            // Strictly link each open contract to its OWN visit(s) in the sheet — the FULL
            // event sequence within the contract's lifespan window (same vehicle, no
            // vehicle-only fallback). A contract with no matching event shows "No Log"
            // instead of borrowing a stale, already-closed one.
            $sheetEvents = $analytics->linkedSheetEvents($open);

            // --- Profitability inputs: per-vehicle income vs maintenance spend ----------
            // Maintenance and financials are no longer separate silos: for every car on the
            // board we pre-roll its LIFETIME income and maintenance expense (two grouped
            // queries, keyed by vehicle_id — no N+1), so each row can show a Net Margin.
            //   Income  = ex-VAT value of every invoice linked to the car's contracts
            //             (via Invoice.contract_id -> contracts.vehicle_id; VAT is a
            //             pass-through tax, not revenue, so total_value not total_after_vat).
            //   Expense = every maintenance event cost logged for the car (maintenances.cost).
            $vehicleIds = $open->pluck('vehicle_id')->filter()->unique()->values()->all();

            $income = Invoice::query()
                ->join('contracts', 'invoices.contract_id', '=', 'contracts.id')
                ->whereIn('contracts.vehicle_id', $vehicleIds ?: [0])
                ->groupBy('contracts.vehicle_id')
                ->selectRaw('contracts.vehicle_id as vid, SUM(invoices.total_value) as total')
                ->pluck('total', 'vid');

            $spend = Maintenance::query()
                ->whereIn('vehicle_id', $vehicleIds ?: [0])
                ->groupBy('vehicle_id')
                ->selectRaw('vehicle_id as vid, SUM(cost) as total')
                ->pluck('total', 'vid');

            $cars = $open->map(function ($c) use ($analytics, $sheetEvents, $income, $spend) {
                $m     = $c->maintenance;                          // hand-entered header (direct contract_id link)
                $seq   = $sheetEvents[$c->id] ?? collect();        // full window sequence (ping-pong), oldest→newest
                $sheet = $seq->last();                             // latest event = current workshop stage

                // Prefer the hand-entered header; otherwise the strictly-linked sheet event.
                // If neither exists, this contract is "No Log" — everything below stays empty.
                $tags    = ! empty($m?->maintenance_tags) ? $m->maintenance_tags : $analytics->sheetIssueTags($sheet);
                $notes   = $m?->maintenance_notes ?: $sheet?->maintenance_notes;
                $garage  = $m?->vendor?->name ?: ($sheet?->vendor?->name ?: $sheet?->garage);
                $expected = $m?->expected_return_date ?: $sheet?->expected_return_date;

                // Is anything actually linked to THIS contract, and has the car come back?
                // A latest event of 'IN' — or any recorded actual return date — means the
                // visit is closed/returned → never overdue (the sheet can log an in-date
                // while the stage on that row is still OUT).
                $linked   = (bool) ($m || $sheet);
                $returned = $sheet && ($sheet->event_status === 'IN' || $sheet->actual_in_date !== null);

                $sla = $analytics->slaStatus($c->out_date, $expected, [
                    'linked'   => $linked,
                    'returned' => $returned,
                ]);

                // A linked reason (chosen by hand) is authoritative; otherwise classify the issues.
                $priority = ($m && $m->maintenance_reason_id && $m->reason)
                    ? ['level' => $m->reason->level, 'matched' => $m->reason->reason_en]
                    : $analytics->classifyPriority($tags, $notes);

                $cost = round((float) $c->items->sum('cost'), 2) ?: (float) $c->contract_debit;

                // Lifetime profitability for this car (income from invoices − maintenance spend).
                $vehicleIncome = round((float) ($income[$c->vehicle_id] ?? 0), 2);
                $vehicleSpend  = round((float) ($spend[$c->vehicle_id] ?? 0), 2);

                return [
                    'id'                   => $c->id,
                    'contract_no'          => $c->contract_no,
                    'vehicle_id'           => $c->vehicle_id,
                    'plate'                => $c->vehicle?->plate_no,
                    'car'                  => $c->vehicle ? trim($c->vehicle->make . ' ' . $c->vehicle->model) : null,
                    'garage'               => $garage,
                    'issues'               => $tags,
                    'notes'                => $notes,
                    'responsible'          => $m?->responsible ?: $sheet?->responsible,
                    // maintenance category (Routine / Breakdown / Body Damage / Periodic …)
                    'type'                 => $m?->maintenance_type ?: $sheet?->maintenance_type,
                    // live workshop stage from the sheet log (OUT / IN / Follow up / Change / Delay / Test)
                    'stage'                => $sheet?->event_status,
                    // full garage history for this contract (ping-pong: out → back → out again),
                    // oldest→newest, with each event's own garage / services / progress note so
                    // the board can pop a complete "what happened in the sheet" log.
                    'events'               => $seq->map(fn ($e) => [
                        'stage'                => $e->event_status,
                        'out_date'             => optional($e->out_date)->toDateString(),
                        'expected_return_date' => optional($e->expected_return_date)->toDateString(),
                        'actual_in_date'       => optional($e->actual_in_date)->toDateString(),
                        'garage'               => $e->vendor?->name ?: $e->garage,
                        'services'             => $analytics->sheetIssueTags($e),
                        'notes'                => $e->maintenance_notes,
                        'cost'                 => $e->cost ? (float) $e->cost : null,
                    ])->all(),
                    // Contract (OfficeManager / API) dates
                    'out_date'             => optional($c->out_date)->toDateString(),
                    'in_date'              => optional($c->in_date)->toDateString(),
                    // Maintenance-sheet dates for the strictly-linked visit
                    'due_sheet'            => optional($sheet?->expected_return_date)->toDateString(),
                    'sheet_back'           => optional($sheet?->actual_in_date)->toDateString(),
                    'expected_return_date' => optional($expected)->toDateString(),
                    'due'                  => $sla['due'],
                    'days_out'             => $sla['days_out'],
                    'overdue_days'         => $sla['overdue_days'],
                    'status'               => $sla['status'],
                    'priority'             => $priority['level'],     // critical | special | minor | routine
                    'priority_matched'     => $priority['matched'],   // the keyword that triggered it
                    'cost'                 => $cost,
                    // Maintenance + financials joined: lifetime income vs spend for this car.
                    'vehicle_income'           => $vehicleIncome,
                    'vehicle_maintenance_cost' => $vehicleSpend,
                    'net_margin'               => round($vehicleIncome - $vehicleSpend, 2),
                ];
            })->values();

            $summary = [
                'total'    => $cars->count(),
                'on_track' => $cars->where('status', 'on_track')->count(),
                'at_risk'  => $cars->where('status', 'at_risk')->count(),
                'breached' => $cars->where('status', 'breached')->count(),
                // priority breakdown (reason -> status classification)
                'critical' => $cars->where('priority', 'critical')->count(),
                'minor'    => $cars->where('priority', 'minor')->count(),
                'routine'  => $cars->where('priority', 'routine')->count(),
                'special'  => $cars->where('priority', 'special')->count(),
                // live workshop stage breakdown (from the sheet log)
                'stages'   => $cars->countBy(fn ($c) => $c['stage'] ?: 'unknown'),
            ];

            return ResponseHelper::SuccessResponse(
                ['cars' => $cars, 'summary' => $summary],
                "Maintenance board retrieved successfully",
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    /**
     * Garage directory + performance: jobs, cars in now, overdue, on-time rate,
     * average delay, total spend, and the cars currently at each garage.
     */
    public function garages(MaintenanceAnalyticsService $analytics)
    {
        try {
            return ResponseHelper::SuccessResponse(
                ['garages' => $analytics->garagePerformance()],
                "Garage performance retrieved successfully",
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    /**
     * Maintenance jobs awaiting approval — cost over the threshold (default AED 500).
     */
    public function approvals(\Illuminate\Http\Request $request)
    {
        try {
            $threshold = (float) config('fleet.maintenance_approval_threshold', 500);

            $jobs = Contract::where('contract_type', 'U')
                ->whereHas('maintenance', fn ($q) => $q->where('approval_status', 'pending'))
                ->with(['vehicle', 'maintenance.vendor', 'maintenance.reason', 'items'])
                ->orderByDesc('out_date')->orderByDesc('id')
                ->get()
                ->map(function ($c) {
                    $cost = max((float) $c->items->sum('cost'), (float) $c->contract_debit);
                    return [
                        'id'          => $c->id,
                        'contract_no' => $c->contract_no,
                        'vehicle_id'  => $c->vehicle_id,
                        'plate'       => $c->vehicle?->plate_no,
                        'car'         => $c->vehicle ? trim($c->vehicle->make . ' ' . $c->vehicle->model) : null,
                        'garage'      => $c->maintenance?->vendor?->name,
                        'reason'      => $c->maintenance?->reason?->reason_en,
                        'date'        => optional($c->out_date)->toDateString(),
                        'cost'        => round($cost, 2),
                        'items'       => $c->items->map(fn ($i) => ['service' => $i->service_name, 'cost' => (float) $i->cost])->values(),
                    ];
                })->values();

            return ResponseHelper::SuccessResponse(
                ['threshold' => $threshold, 'jobs' => $jobs],
                "Pending approvals retrieved successfully",
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    /**
     * Approve a maintenance job's bill (records who/when/amount).
     */
    public function approve(\Illuminate\Http\Request $request, Contract $contract)
    {
        try {
            $cost = max((float) $contract->items()->sum('cost'), (float) $contract->contract_debit);

            $contract->maintenance()->updateOrCreate(
                ['contract_id' => $contract->id],
                [
                    'approval_status' => 'approved',
                    'approved_amount' => round($cost, 2),
                    'approved_at'     => now(),
                    'approved_by'     => $request->user()?->name ?: 'admin',
                ]
            );

            return ResponseHelper::SuccessResponse(
                ['id' => $contract->id, 'approved_amount' => round($cost, 2)],
                "Maintenance bill approved",
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    /**
     * Damage & accident log: every damage/accident maintenance record straight from
     * the log, colour-coded by the fault stated in the data — red (renter at fault) /
     * green (third party / insured accident). No inference, no date-matching.
     */
    public function incidents(MaintenanceIncidentService $incidents)
    {
        try {
            return ResponseHelper::SuccessResponse(
                $incidents->log(),
                "Damage & accident log retrieved successfully",
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    /**
     * Cars repeatedly in for the same fault (scenario step 8 — "عطل متكرر").
     */
    public function recurring(MaintenanceAnalyticsService $analytics)
    {
        try {
            return ResponseHelper::SuccessResponse(
                $analytics->recurringFaults(3),
                "Recurring faults retrieved successfully",
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }

    /**
     * Fleet-wide maintenance cost intelligence: average cost per service and a
     * cheapest-vs-dearest vendor comparison for each service.
     */
    public function analytics(MaintenanceAnalyticsService $analytics)
    {
        try {
            $data = [
                'service_averages'  => $analytics->serviceAverages(),
                'vendor_comparison' => $analytics->vendorComparison(),
                'recurring_faults'  => $analytics->recurringFaults(3),
            ];

            return ResponseHelper::SuccessResponse($data, "Maintenance analytics retrieved successfully", 200);
        } catch (\Exception $e) {
            return ResponseHelper::FailureResponse(null, $e->getMessage(), 400);
        }
    }
}
