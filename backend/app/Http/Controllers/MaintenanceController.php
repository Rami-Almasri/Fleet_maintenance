<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\Contract;
use App\Models\Maintenance;
use App\Models\MaintenanceReason;
use App\Models\Vehicle;
use App\Services\MaintenanceAnalyticsService;
use App\Services\MaintenanceForesightService;
use App\Services\MaintenanceIncidentService;
use App\Services\OperationsService;
use App\Services\RealProfitService;

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
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * BOOKING CONFLICTS — every car that is BOTH currently in the workshop AND has an upcoming
     * booking (type-R reservation). This is the /maintenance-bookings board: the at-a-glance list of
     * "a customer is expecting this car soon, but it's still in the shop" so ops can chase the garage
     * or swap the car before the pickup slips.
     *
     * "In maintenance" is the canonical OperationsService set (open type-U contract / manual garage
     * event / open workflow ticket) — the same rule the dashboard, the bell alert and the maintenance
     * board use. A row is `at_risk` when the shop can't guarantee the car back in time: no expected
     * return on record, an expected return AFTER the booking starts, or a pickup ≤ 2 days away.
     */
    public function bookingConflicts(OperationsService $operations)
    {
        try {
            $inShop = $operations->vehiclesInMaintenance();
            if (empty($inShop)) {
                return ResponseHelper::SuccessResponse(
                    ['cars' => [], 'summary' => ['total' => 0, 'at_risk' => 0]],
                    'No cars in maintenance',
                    200,
                );
            }

            $today = \Illuminate\Support\Carbon::today();

            // Upcoming reservations on cars that are in the shop — earliest booking per car.
            $bookings = Contract::query()
                ->upcomingReservation()
                ->whereIn('vehicle_id', $inShop)
                ->whereNotNull('out_date')
                ->whereDate('out_date', '>=', $today)
                ->with(['vehicle:id,plate_no,make,model', 'customer:id,name_en'])
                ->orderBy('out_date')
                ->get()
                ->groupBy('vehicle_id');

            if ($bookings->isEmpty()) {
                return ResponseHelper::SuccessResponse(
                    ['cars' => [], 'summary' => ['total' => 0, 'at_risk' => 0]],
                    'No booked cars are currently in maintenance',
                    200,
                );
            }

            $bookedIds = $bookings->keys()->all();

            // The maintenance side for those cars: the open type-U contract (garage + expected return)
            // and any open workflow ticket (live stage). Both keyed by vehicle_id, one query each.
            $uContracts = Contract::where('contract_type', 'U')->currentlyOpen()
                ->whereIn('vehicle_id', $bookedIds)
                ->with('maintenance.vendor')
                ->get()->keyBy('vehicle_id');

            $tickets = Maintenance::openWorkflow()
                ->whereIn('vehicle_id', $bookedIds)
                ->whereIn('workflow_status', Maintenance::WF_TICKET_STATES)
                ->orderByDesc('id')
                ->get()->unique('vehicle_id')->keyBy('vehicle_id');

            $atRisk = 0;

            $cars = collect($bookedIds)->map(function ($vid) use ($bookings, $uContracts, $tickets, $today, &$atRisk) {
                $booking  = $bookings[$vid]->first();          // earliest upcoming reservation
                $vehicle  = $booking->vehicle;
                $u        = $uContracts[$vid] ?? null;
                $ticket   = $tickets[$vid] ?? null;

                $expected = $u?->maintenance?->expected_return_date ?: $u?->expected_return_date;
                $start    = \Illuminate\Support\Carbon::parse($booking->out_date);
                $daysLeft = (int) $today->diffInDays($start, false);   // 0 today · 1 · 2 · …

                // Why (if at all) this pickup is at risk.
                $reasons = [];
                if (! $expected) {
                    $reasons[] = 'No expected return date on record';
                } elseif (\Illuminate\Support\Carbon::parse($expected)->gt($start)) {
                    $reasons[] = 'Expected back ' . \Illuminate\Support\Carbon::parse($expected)->format('M j') . ' — after the booking starts';
                }
                if ($daysLeft <= 2) {
                    $reasons[] = $daysLeft <= 0 ? 'Pickup is today' : 'Pickup in ' . $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's');
                }
                $risk = ! empty($reasons);
                if ($risk) {
                    $atRisk++;
                }

                return [
                    'vehicle_id'           => (int) $vid,
                    'plate'                => $vehicle?->plate_no,
                    'car'                  => $vehicle ? trim(($vehicle->make ?? '') . ' ' . ($vehicle->model ?? '')) : null,
                    // Maintenance side
                    'garage'               => $u?->maintenance?->vendor?->name,
                    'in_maintenance_since' => optional($u?->out_date)->toDateString(),
                    'expected_return_date' => optional($expected)->toDateString(),
                    'workflow_stage'       => $ticket ? str_replace('_', ' ', $ticket->workflow_status) : null,
                    // Booking side
                    'booking_contract_no'  => $booking->contract_no,
                    'booking_id'           => $booking->id,
                    'customer'             => $booking->customer?->name_en,
                    'booking_start'        => $start->toDateString(),
                    'days_left'            => $daysLeft,
                    'upcoming_count'       => $bookings[$vid]->count(),
                    // Verdict
                    'at_risk'              => $risk,
                    'risk_reasons'         => $reasons,
                ];
            })
            // Most urgent first: at-risk before safe, then soonest pickup.
            ->sortBy(fn ($r) => [$r['at_risk'] ? 0 : 1, $r['days_left']])
            ->values();

            return ResponseHelper::SuccessResponse(
                ['cars' => $cars, 'summary' => ['total' => $cars->count(), 'at_risk' => $atRisk]],
                'Booking conflicts retrieved',
                200,
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * The live maintenance board: every car currently in the garage (open type-U
     * contracts) with its garage, issues, due date, cost and a traffic-light SLA
     * status (green on_track / yellow at_risk / red breached).
     */
    public function board(MaintenanceAnalyticsService $analytics, OperationsService $operations, RealProfitService $realProfit, \App\Services\MaintenanceForecastService $forecast)
    {
        try {
            $open = Contract::where('contract_type', 'U')
                ->currentlyOpen()
                ->with(['vehicle', 'maintenance.vendor', 'maintenance.reason', 'items'])
                ->orderBy('out_date')
                ->get();

            // Cars in the garage on a hand-entered workshop event with NO open contract — they have
            // no type-U contract to anchor a board card, so we synthesise one per car below. Same
            // set the dashboard KPI / donut count, so the board total agrees with them.
            $manualVehicleIds = array_values(array_diff(
                $operations->manualOnlyGarageVehicleIds(),
                $open->pluck('vehicle_id')->filter()->all()
            ));

            // OfficeManager carries no maintenance detail for type-U contracts, so a car's
            // current garage visit (status / garage / issues / notes) is read from the
            // "N-Maintenance & Repair" sheet log — the latest event per vehicle — whenever
            // no maintenance header was entered by hand in FleetView.
            // Strictly link each open contract to its OWN visit(s) in the sheet — the FULL
            // event sequence within the contract's lifespan window (same vehicle, no
            // vehicle-only fallback). A contract with no matching event shows "No Log"
            // instead of borrowing a stale, already-closed one.
            $sheetEvents = $analytics->linkedSheetEvents($open);

            // --- Profitability inputs: per-vehicle lifetime Net Profit -------------------
            // Sourced from the CANONICAL RealProfitService::vehicleBridge() — the SAME engine the
            // Vehicle Profile ("Lifetime Net Profit") and the Profitability table use, batched by
            // vehicle_id (no N+1). Previously this board computed its own ad-hoc figure (invoice
            // income, maintenance cost with no origin filter, ignoring operating cost), which
            // disagreed with every other page for the same car. Now the board's Net Margin is
            // identical to the profile's Net Profit:
            //   net_profit = gross_revenue − operating_cost − maintenance (workshop-log origins only).
            $vehicleIds = $open->pluck('vehicle_id')->filter()
                ->merge($manualVehicleIds)->unique()->values()->all();

            $bridge = $realProfit->vehicleBridge($vehicleIds ?: [0]);

            $cars = $open->map(function ($c) use ($analytics, $sheetEvents, $bridge) {
                $m     = $c->maintenance;                          // hand-entered header (direct contract_id link)
                $seq   = $sheetEvents[$c->id] ?? collect();        // full window sequence (ping-pong), oldest→newest
                $sheet = $seq->last();                             // latest event = current workshop stage

                // Prefer the hand-entered header; otherwise the strictly-linked sheet event.
                // If neither exists, this contract is "No Log" — everything below stays empty.
                $tags    = ! empty($m?->maintenance_tags) ? $m->maintenance_tags : $analytics->sheetIssueTags($sheet);
                $notes   = $m?->maintenance_notes ?: $sheet?->maintenance_notes;
                $garage  = $m?->vendor?->name ?: ($sheet?->vendor?->name ?: $sheet?->garage);
                $expected = $m?->expected_return_date ?: $sheet?->expected_return_date;

                // Is anything linked to THIS contract, and has the car actually come back?
                // The API (the type-U contract's in_date) is the source of truth for the return —
                // NOT the sheet. The sheet log can show an 'IN' / actual return date while the
                // OfficeManager contract is still open; in that case the car is NOT "Returned"
                // here (the API still has it out). Every card on this board is currentlyOpen()
                // (in_date IS NULL), so the API says it's still out — we never let the sheet's
                // "back" close it. That sheet↔API gap is surfaced via the "back from garage ·
                // contract still open" bell alert instead.
                $linked   = (bool) ($m || $sheet);
                $returned = $c->in_date !== null;   // API/contract return only — never the sheet

                $sla = $analytics->slaStatus($c->out_date, $expected, [
                    'linked'   => $linked,
                    'returned' => $returned,
                ]);

                // A linked reason (chosen by hand) is authoritative; otherwise classify the issues.
                $priority = ($m && $m->maintenance_reason_id && $m->reason)
                    ? ['level' => $m->reason->level, 'matched' => $m->reason->reason_en]
                    : $analytics->classifyPriority($tags, $notes);

                $cost = round((float) $c->items->sum('cost'), 2) ?: (float) $c->contract_debit;

                // Lifetime profitability for this car — straight from the canonical Profit Bridge,
                // so it ties out with the Vehicle Profile and Profitability page to the dirham.
                $b             = $bridge[$c->vehicle_id] ?? null;
                $vehicleIncome = round((float) ($b['gross_revenue'] ?? 0), 2);
                $vehicleSpend  = round((float) ($b['maintenance'] ?? 0), 2);
                $vehicleNet    = round((float) ($b['net_profit'] ?? ($vehicleIncome - $vehicleSpend)), 2);

                return [
                    'id'                   => $c->id,
                    'is_contract'          => true,
                    'contract_no'          => $c->contract_no,
                    'vehicle_id'           => $c->vehicle_id,
                    'plate'                => $c->vehicle?->plate_no,
                    'car'                  => $c->vehicle ? trim($c->vehicle->make . ' ' . $c->vehicle->model) : null,
                    'garage'               => $garage,
                    'issues'               => $tags,
                    'notes'                => $notes,
                    'responsible'          => $m?->responsible ?: $sheet?->responsible,
                    // Most recent activity on this visit — latest event edit, else the header edit.
                    'last_update'          => optional($sheet?->updated_at ?: $m?->updated_at)->toIso8601String(),
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
                    // Maintenance + financials joined: lifetime profit for this car, from the
                    // canonical Profit Bridge (net = gross revenue − operating cost − maintenance).
                    'vehicle_income'           => $vehicleIncome,
                    'vehicle_maintenance_cost' => $vehicleSpend,
                    'net_margin'               => $vehicleNet,
                ];
            })->values();

            // --- Contract-less garage cards: cars in on a hand-entered workshop event only -------
            // Build one card per such car from its manual event run (most recent OUT → now), so a
            // workshop visit logged without a maintenance contract still shows on the board.
            if ($manualVehicleIds) {
                $manualEvents = Maintenance::query()
                    ->where('origin', Maintenance::ORIGIN_MANUAL)
                    ->whereIn('vehicle_id', $manualVehicleIds)
                    ->with(['vendor', 'reason', 'vehicle'])
                    ->orderBy('out_date')->orderBy('id')
                    ->get()
                    ->groupBy('vehicle_id');

                $manualCars = collect($manualVehicleIds)->map(function ($vid) use ($manualEvents, $analytics, $bridge) {
                    $seq = $manualEvents[$vid] ?? collect();
                    if ($seq->isEmpty()) {
                        return null;
                    }
                    // The current open run = events since the last 'IN' (the car's latest trip out).
                    $lastInIdx = $seq->search(fn ($e) => $e->event_status === 'IN');
                    $run     = $lastInIdx === false ? $seq : $seq->slice($lastInIdx + 1)->values();
                    $run     = $run->isEmpty() ? $seq : $run;
                    $latest  = $run->last();
                    $vehicle = $latest->vehicle;

                    $tags     = $analytics->sheetIssueTags($latest);
                    $notes    = $latest->maintenance_notes;
                    $garage   = $latest->vendor?->name ?: $latest->garage;
                    $expected = $latest->expected_return_date;

                    $sla = $analytics->slaStatus($latest->out_date, $expected, [
                        'linked'   => true,
                        'returned' => false,   // by definition the latest event is not 'IN'
                    ]);

                    $priority = ($latest->maintenance_reason_id && $latest->reason)
                        ? ['level' => $latest->reason->level, 'matched' => $latest->reason->reason_en]
                        : $analytics->classifyPriority($tags, $notes);

                    $cost          = round((float) $run->sum('cost'), 2);
                    $b             = $bridge[$vid] ?? null;
                    $vehicleIncome = round((float) ($b['gross_revenue'] ?? 0), 2);
                    $vehicleSpend  = round((float) ($b['maintenance'] ?? 0), 2);
                    $vehicleNet    = round((float) ($b['net_profit'] ?? ($vehicleIncome - $vehicleSpend)), 2);

                    return [
                        'id'                   => 'm' . $vid,   // string id → no collision with contract ids
                        'is_contract'          => false,
                        'contract_no'          => null,
                        'vehicle_id'           => (int) $vid,
                        'plate'                => $vehicle?->plate_no,
                        'car'                  => $vehicle ? trim($vehicle->make . ' ' . $vehicle->model) : null,
                        'garage'               => $garage,
                        'issues'               => $tags,
                        'notes'                => $notes,
                        'responsible'          => $latest->responsible,
                        'last_update'          => optional($latest->updated_at)->toIso8601String(),
                        'type'                 => $latest->maintenance_type,
                        'stage'                => $latest->event_status,
                        'events'               => $run->map(fn ($e) => [
                            'stage'                => $e->event_status,
                            'out_date'             => optional($e->out_date)->toDateString(),
                            'expected_return_date' => optional($e->expected_return_date)->toDateString(),
                            'actual_in_date'       => optional($e->actual_in_date)->toDateString(),
                            'garage'               => $e->vendor?->name ?: $e->garage,
                            'services'             => $analytics->sheetIssueTags($e),
                            'notes'                => $e->maintenance_notes,
                            'cost'                 => $e->cost ? (float) $e->cost : null,
                        ])->all(),
                        'out_date'             => optional($latest->out_date)->toDateString(),
                        'in_date'              => null,
                        'due_sheet'            => optional($latest->expected_return_date)->toDateString(),
                        'sheet_back'           => optional($latest->actual_in_date)->toDateString(),
                        'expected_return_date' => optional($expected)->toDateString(),
                        'due'                  => $sla['due'],
                        'days_out'             => $sla['days_out'],
                        'overdue_days'         => $sla['overdue_days'],
                        'status'               => $sla['status'],
                        'priority'             => $priority['level'],
                        'priority_matched'     => $priority['matched'],
                        'cost'                 => $cost ?: null,
                        'vehicle_income'           => $vehicleIncome,
                        'vehicle_maintenance_cost' => $vehicleSpend,
                        'net_margin'               => $vehicleNet,
                    ];
                })->filter()->values();

                $cars = $cars->concat($manualCars)->values();
            }

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

            // Fleet-wide vital signs for the "Maintenance Pulse" strip at the top of the board.
            // `service_due_soon` = cars APPROACHING their service (predictive) — the proactive KPI that
            // pairs with the "Service Due Soon" bell alert, so the number on the board matches the feed.
            $pulse = array_merge(
                ['in_garage' => $cars->count()],
                $analytics->maintenancePulse(),
                ['service_due_soon' => $forecast->dueSoonCount()],
            );

            return ResponseHelper::SuccessResponse(
                ['cars' => $cars, 'summary' => $summary, 'pulse' => $pulse],
                "Maintenance board retrieved successfully",
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
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
            return ResponseHelper::fromException($e);
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
            return ResponseHelper::fromException($e);
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
            return ResponseHelper::fromException($e);
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
            return ResponseHelper::fromException($e);
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
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Maintenance Foresight: catch cars BEFORE they break (service overdue, chronic faults,
     * aging battery) and simulate the cost of inaction — predicted downtime, parts-wait risk,
     * lost rental revenue, and the saving from acting early. The "be ready" board.
     */
    public function foresight(MaintenanceForesightService $foresight)
    {
        try {
            return ResponseHelper::SuccessResponse(
                $foresight->report(),
                "Maintenance foresight retrieved successfully",
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Drill-down behind a Foresight cost line: every workshop repair across the WHOLE
     * fleet that fixed a given issue, with its all-in cost — "show me where we fixed
     * this, on any car, and what it cost". Reconciles with the card's "avg · n repairs".
     */
    public function issueHistory(\Illuminate\Http\Request $request, MaintenanceForesightService $foresight)
    {
        try {
            $issue = trim((string) $request->query('issue', ''));
            if ($issue === '') {
                return ResponseHelper::FailureResponse(null, 'An issue is required.', 422);
            }

            return ResponseHelper::SuccessResponse(
                $foresight->issueHistory($issue),
                "Issue repair history retrieved successfully",
                200
            );
        } catch (\Exception $e) {
            return ResponseHelper::fromException($e);
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
            return ResponseHelper::fromException($e);
        }
    }
}
