<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Maintenance;
use App\Models\MaintenanceTask;
use App\Models\Vehicle;
use App\Support\FaultVocabulary;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Maintenance Foresight — catch a car BEFORE it breaks down, and quantify the cost of
 * NOT acting, so the fleet can be ready instead of surprised.
 *
 * Every signal is a concrete, traceable fact (no fabricated probabilities):
 *   - Service overdue / near-due  → strict km rule (Vehicle::serviceStatus): odometer −
 *                                    last service ≥ interval (from the "Oil Change" sheet)
 *   - Chronic fault               → the SAME issue logged across 2+ separate garage visits
 *                                    (derived from the N-Maintenance workshop log)
 *   - Frequent breakdowns         → a car in the workshop 4+ times overall
 *   - Battery overdue             → battery_last_changed older than BATTERY_LIFE_MONTHS
 *
 * The impact of leaving it to fail is simulated from the fleet's OWN repair history — real
 * repair durations (workshop out_date → actual_in_date), so the "car stuck for weeks waiting
 * on a part" case is measured, not guessed:
 *   - predicted downtime (typical + worst-case days), parts-wait risk (long-downtime history)
 *   - revenue at risk = downtime × the car's daily rate (off-road = no rental income)
 *   - preventable saving = acting now (short planned stop) vs the full unplanned hit
 */
class MaintenanceForesightService
{
    /** Within this many km of the service point → "due soon" (watch). */
    private const NEAR_DUE_KM = 1000;
    /** A battery older than this (months) is overdue for replacement. */
    private const BATTERY_LIFE_MONTHS = 30;
    /** A repair this long (days) is a long-downtime / parts-wait event. */
    private const LONG_DOWNTIME_DAYS = 14;
    /** A car serviced proactively (when idle) only loses this many rental days. */
    private const PLANNED_DOWNTIME_DAYS = 2;
    /** Same issue this many distinct EPISODES → chronic (real recurrence). */
    private const CHRONIC_MIN_VISITS = 2;
    /** Total distinct EPISODES at/over this → "frequent breakdowns" problem car. */
    private const FREQUENT_MIN_VISITS = 4;
    /**
     * Repair-episode logic. A return within WAITING_BUFFER_DAYS of the previous visit for the
     * same issue is the SAME episode (workshop waiting on parts / stalling), not a recurrence —
     * a genuine "chronic" recurrence only counts when the car comes back AFTER this gap.
     */
    private const WAITING_BUFFER_DAYS = 7;
    /** Grace window before a Type-U contract's out_date in which a workshop log still belongs to it. */
    private const CONTRACT_BUFFER_DAYS = 2;
    /** Same workshop, same issue, this many visits inside STALL_WINDOW_DAYS → workshop stalling. */
    private const STALL_MIN_VISITS = 3;
    private const STALL_WINDOW_DAYS = 10;
    /** Share of an issue's visits running long before we call it a parts-wait risk. */
    private const PARTS_WAIT_LONG_RATE = 0.12;
    /** Fallbacks when a car / issue has no history of its own. */
    private const FALLBACK_DOWNTIME_DAYS = 4;
    private const FALLBACK_DAILY_RATE = 150.0;
    /** How many PRICED past repairs an issue needs to back a high/medium-confidence cost estimate. */
    private const COST_CONF_HIGH = 20;
    private const COST_CONF_MED  = 8;
    /** A model whose own repair cost runs this much above the fleet average for an issue is flagged. */
    private const VARIANCE_THRESHOLD = 0.30;
    /** ...but only when the model has at least this many PRICED repairs for that issue (else it's noise). */
    private const VARIANCE_MIN_SAMPLES = 3;

    public function __construct(
        private MaintenanceAnalyticsService $analytics,
        private RealProfitService $realProfit,
        private OperationsService $operations,
    ) {
    }

    /**
     * @return array{cars: array<int,array<string,mixed>>, summary: array<string,mixed>, parts_watch: array<int,array<string,mixed>>}
     */
    public function report(): array
    {
        $intel    = $this->workshopIntel();        // history derived in one pass over the log
        $fleet    = $this->fleetBaselines($intel['issues']);
        $inGarage = $this->vehiclesCurrentlyInGarage();

        $cars = [];

        Vehicle::query()
            // Foresight only plans for cars that can actually earn: Ready (OM status 2) or
            // Rented (OM status 3). Everything else — sold, disposed, under_maintenance,
            // out_of_order, suspended, etc. — is irrelevant to upcoming maintenance planning.
            ->whereIn('status', Vehicle::ACTIVE_STATUSES)
            ->orderBy('code')
            ->chunkById(500, function ($vehicles) use (&$cars, $intel, $fleet, $inGarage) {
                foreach ($vehicles as $v) {
                    if (isset($inGarage[$v->id])) {
                        continue;   // already in the workshop — not a prediction
                    }
                    $row = $this->assess($v, $intel, $fleet);
                    if ($row) {
                        $cars[] = $row;
                    }
                }
            });

        // Real Net Profit + Negative-Yield: replace OM's opaque income with the ground-truth
        // trailing-12mo profitability for every assessed car (one pair of grouped queries).
        $yield = $this->realProfit->vehicleYield(array_column($cars, 'vehicle_id'));
        foreach ($cars as &$car) {
            $y = $yield[$car['vehicle_id']] ?? null;
            $car['real_net_profit']   = $y['real_net_profit'] ?? 0.0;
            $car['maintenance_spend'] = $y['maintenance_spend'] ?? 0.0;
            $car['net_yield']         = $y['net_yield'] ?? null;
            $car['profit_breakdown']  = $y ? [
                'rent_billed'    => $y['rent_billed'],
                'discount'       => $y['discount'],
                'realized_usage' => $y['realized_usage'],
                'operating_cost' => $y['operating_cost'],
            ] : null;
            $car['negative_yield']    = $y['negative_yield'] ?? false;
            $car['yield_window']      = RealProfitService::YIELD_MONTHS;
        }
        unset($car);

        $tierRank = ['act_now' => 0, 'plan_soon' => 1, 'watch' => 2];
        usort($cars, fn ($a, $b) => [$tierRank[$a['tier']], -$a['revenue_at_risk']] <=> [$tierRank[$b['tier']], -$b['revenue_at_risk']]);

        return [
            'cars'             => $cars,
            'summary'          => $this->summarise($cars),
            'parts_watch'      => $this->partsWatch($intel['issues']),
            'longest_sessions' => $intel['longest_sessions'],
            'workshop_stalling' => $this->workshopStallingRollup($cars),
        ];
    }

    /**
     * Drill-down behind a cost-breakdown line: every workshop repair across the WHOLE fleet
     * that fixed $issueLabel, with its all-in cost — so "Electrical · AED 675.32 · 37 repairs"
     * can be opened to the 37 actual records on any car.
     *
     * Built exactly like the cost average (visits grouped by vehicle + out_date, one all-in
     * price per visit, mechanical tags only) so the record list reconciles with the headline:
     * `summary.priced` equals the card's sample count and `summary.avg_cost` its shown cost.
     *
     * @return array{issue:string, summary:array<string,mixed>, records:array<int,array<string,mixed>>}
     */
    public function issueHistory(string $issueLabel): array
    {
        $needle = $this->normalise($issueLabel);
        $empty  = ['visits' => 0, 'priced' => 0, 'avg_cost' => null, 'total_cost' => 0.0, 'min_cost' => null, 'max_cost' => null];
        if ($needle === '') {
            return ['issue' => $issueLabel, 'summary' => $empty, 'records' => []];
        }

        $rows = DB::table('maintenances as m')
            ->leftJoin('vehicles as v', 'v.id', '=', 'm.vehicle_id')
            ->leftJoin('vendors as vd', 'vd.id', '=', 'm.vendor_id')
            ->whereIn('m.origin', Maintenance::WORKSHOP_LOG_ORIGINS)
            ->whereNotNull('m.out_date')
            // Planned upkeep is now excluded at LABEL grain by splitIssues(), which is both finer and
            // more complete than the old visit-level `visit_context <> routine` filter — that dropped
            // genuine faults discovered on a routine visit while letting service labels through on every
            // visit whose context was null (the majority). See audit M1.
            ->orderBy('m.vehicle_id')
            ->orderBy('m.out_date')
            ->get(['m.id', 'm.vehicle_id', 'm.out_date', 'm.actual_in_date', 'm.service_main', 'm.service_sup', 'm.cost', 'm.origin', 'm.garage', 'v.plate_no', 'v.make', 'v.model', 'vd.name as vendor']);

        $d10 = fn ($x) => $x ? substr((string) $x, 0, 10) : null;

        // Collapse log rows into visits (vehicle | out_date); one all-in cost per visit.
        $visits = [];
        foreach ($rows as $r) {
            $key = $r->vehicle_id . '|' . ($d10($r->out_date) ?? '');
            if (! isset($visits[$key])) {
                $visits[$key] = [
                    'veh'    => (int) $r->vehicle_id,
                    'plate'  => $r->plate_no,
                    'car'    => trim(($r->make ?? '') . ' ' . ($r->model ?? '')) ?: null,
                    'out'    => $d10($r->out_date),
                    'ret'    => null,
                    'cost'   => 0.0,
                    'garage' => null,
                    'issues' => [],
                    'match'  => false,
                    'event_id'   => null,
                    'event_rank' => -1,
                ];
            }
            $vi = &$visits[$key];

            $in = $d10($r->actual_in_date);
            if ($in && (! $vi['ret'] || $in > $vi['ret'])) {
                $vi['ret'] = $in;
            }
            $vi['cost'] += (float) $r->cost;
            if (! $vi['garage'] && ($r->vendor || $r->garage)) {
                $vi['garage'] = $r->vendor ?: $r->garage;
            }

            $carriesNeedle = false;
            foreach ($this->splitIssues($r->service_main, $r->service_sup) as $t) {
                if (! $this->isMechanical($t)) {
                    continue;   // cosmetics never feed the cost line, so never the drill-down either
                }
                $vi['issues'][$t] = true;
                if ($this->normalise($t) === $needle) {
                    $vi['match']   = true;
                    $carriesNeedle = true;
                }
            }

            // Best deep-link target for the visit: a 'sheet' row (what the vehicle page renders)
            // that actually carries the issue — so the link lands on the right log entry.
            $rank = ($r->origin === 'sheet' ? 2 : 0) + ($carriesNeedle ? 1 : 0);
            if ($rank > $vi['event_rank']) {
                $vi['event_rank'] = $rank;
                $vi['event_id']   = (int) $r->id;
            }
            unset($vi);
        }

        $records = [];
        $costs   = [];
        foreach ($visits as $vi) {
            if (! $vi['match']) {
                continue;
            }
            $cost = round($vi['cost'], 2);
            $days = ($vi['out'] && $vi['ret'])
                ? (int) Carbon::parse($vi['out'])->diffInDays(Carbon::parse($vi['ret']))
                : null;
            if ($cost > 0) {
                $costs[] = $cost;
            }
            $records[] = [
                'vehicle_id'     => $vi['veh'],
                'plate'          => $vi['plate'],
                'car'            => $vi['car'],
                'out_date'       => $vi['out'],
                'actual_in_date' => $vi['ret'],
                'days'           => $days !== null && $days >= 0 ? $days : null,
                'garage'         => $vi['garage'],
                'cost'           => $cost > 0 ? $cost : null,
                'issues'         => array_keys($vi['issues']),
                'event_id'       => $vi['event_id'],
            ];
        }

        // Priced repairs first (dearest on top — that's what a manager scans for), then any
        // unpriced records newest-first.
        usort($records, function ($a, $b) {
            $ap = $a['cost'] !== null;
            $bp = $b['cost'] !== null;
            if ($ap !== $bp) {
                return $ap ? -1 : 1;
            }
            if ($ap && $bp && $a['cost'] !== $b['cost']) {
                return $b['cost'] <=> $a['cost'];
            }
            return (string) ($b['out_date'] ?? '') <=> (string) ($a['out_date'] ?? '');
        });

        return [
            'issue'   => $issueLabel,
            'summary' => [
                'visits'     => count($records),
                'priced'     => count($costs),
                'avg_cost'   => $costs ? round(array_sum($costs) / count($costs), 2) : null,
                'total_cost' => round(array_sum($costs), 2),
                'min_cost'   => $costs ? min($costs) : null,
                'max_cost'   => $costs ? max($costs) : null,
            ],
            'records' => array_slice($records, 0, 300),
        ];
    }

    /**
     * Roll the per-car stalling incidents up by garage — "which workshops are holding my cars
     * hostage". Sorted by the number of stalling incidents, then distinct cars affected.
     *
     * @param  array<int,array<string,mixed>>  $cars
     * @return array<int,array<string,mixed>>
     */
    private function workshopStallingRollup(array $cars): array
    {
        $byVendor = [];
        foreach ($cars as $c) {
            foreach (($c['stalling'] ?? []) as $st) {
                $key = $st['vendor_id'] ?: ('name:' . $st['vendor']);
                $byVendor[$key] ??= ['vendor_id' => $st['vendor_id'], 'vendor' => $st['vendor'] ?: 'Unknown garage', 'cars' => [], 'incidents' => 0, 'samples' => []];
                $byVendor[$key]['incidents']++;
                $byVendor[$key]['cars'][$c['vehicle_id']] = true;
                if (count($byVendor[$key]['samples']) < 6) {
                    $byVendor[$key]['samples'][] = [
                        'vehicle_id' => $c['vehicle_id'],
                        'plate'      => $c['plate'],
                        'issue'      => $st['issue'],
                        'visits'     => $st['visits'],
                        'span_days'  => $st['span_days'],
                    ];
                }
            }
        }

        $out = [];
        foreach ($byVendor as $b) {
            $out[] = [
                'vendor_id' => $b['vendor_id'],
                'vendor'    => $b['vendor'],
                'cars'      => count($b['cars']),
                'incidents' => $b['incidents'],
                'samples'   => $b['samples'],
            ];
        }
        usort($out, fn ($a, $b) => [$b['incidents'], $b['cars']] <=> [$a['incidents'], $a['cars']]);
        return $out;
    }

    // ---- per-vehicle assessment ------------------------------------------------------

    /**
     * @param  array<string,mixed>  $intel
     * @param  array<string,mixed>  $fleet
     */
    private function assess(Vehicle $v, array $intel, array $fleet): ?array
    {
        $signals = [];
        $issueCandidates = [];   // issue label => weight, the impact is costed against the heaviest

        // --- Signal 1: service due / near-due (strict km rule) ----------------------
        $svc = $v->serviceStatus();
        if ($svc['status'] === 'service_due') {
            $over = (int) ($svc['overdue_km'] ?? 0);
            $signals[] = [
                'type'   => 'service_overdue',
                'tier'   => $over >= 2000 ? 'act_now' : 'plan_soon',
                'label'  => 'Service is late',
                'detail' => 'It is ' . number_format($over) . ' km past its service (now at '
                            . number_format((int) $svc['current']) . ' km).',
            ];
            $issueCandidates['oil change'] = 1;
        } elseif ($svc['status'] === 'ok' && $svc['remaining'] !== null && $svc['remaining'] <= self::NEAR_DUE_KM) {
            $signals[] = [
                'type'   => 'service_due_soon',
                'tier'   => 'watch',
                'label'  => 'Service coming up',
                'detail' => 'Only ' . number_format((int) $svc['remaining']) . ' km left before its next service.',
            ];
            $issueCandidates['oil change'] = 1;
        }

        // --- Signal 2: workshop stalling vs. genuine chronic fault ------------------
        // A return within the waiting buffer is the same repair episode (parts wait / stalling),
        // NOT a recurrence. A genuine chronic fault = the car comes back AFTER the buffer because
        // the fix didn't hold. If the SAME garage held the car 3×/10 days, that's the garage's
        // problem, not the car's — flag stalling instead.
        $episodes  = $intel['vehicle_issue_episodes'][$v->id] ?? [];
        $stallMap  = $intel['vehicle_issue_stalling'][$v->id] ?? [];
        $stalling  = [];   // for the fleet-wide workshop roll-up
        arsort($episodes);
        $chronicShown = 0;
        foreach ($episodes as $issue => $eps) {
            $low = strtolower($issue);

            if (isset($stallMap[$issue])) {
                $st = $stallMap[$issue];
                $signals[] = [
                    'type'   => 'workshop_stalling',
                    'tier'   => 'plan_soon',
                    'label'  => 'Garage too slow: ' . ($st['vendor'] ?: 'garage'),
                    'detail' => ($st['vendor'] ?: 'A garage') . ' had this car ' . $st['visits'] . ' times in '
                                . $st['span_days'] . ' days for ' . $issue . '. Looks like they are slow or waiting '
                                . 'for parts — this is the garage, not the car.',
                ];
                $stalling[] = ['vendor_id' => $st['vendor_id'], 'vendor' => $st['vendor'], 'issue' => $issue, 'visits' => $st['visits'], 'span_days' => $st['span_days']];
                $issueCandidates[$low] = max($issueCandidates[$low] ?? 0, 8 + $eps);
                continue;   // stalling supersedes chronic for this issue
            }

            if ($eps < self::CHRONIC_MIN_VISITS) {
                continue;
            }
            $issueCandidates[$low] = 10 + $eps;   // feeds the impact even if not shown
            if ($chronicShown >= 3) {
                continue;                          // cap the chips so the card stays readable
            }
            $chronicShown++;
            $level = $this->analytics->classifyPriority([$issue])['level'];
            $tier  = ($level === 'critical' || $eps >= 4) ? 'act_now' : ($eps === 3 ? 'plan_soon' : 'watch');
            $basis = $intel['vehicle_issue_basis'][$v->id][$issue] ?? null;
            $signals[] = [
                'type'     => 'chronic_fault',
                'tier'     => $tier,
                'label'    => 'Keeps coming back: ' . $issue,
                'issue'    => $issue,   // clean fault name, for a de-duplicated headline
                'count'    => $eps,     // how many separate visits for this exact fault
                'basis'    => $basis,   // plain hint: why this counts as a repeat
                'detail'   => 'Went to the garage ' . $eps . ' times for ' . $issue . '. The same problem keeps coming back.',
                'evidence' => $intel['vehicle_issue_evidence'][$v->id][$issue] ?? [],   // contracts + gaps behind it
            ];
        }

        // --- Signal 3: frequent breakdowns (problem car) ----------------------------
        $episodeTotal = $intel['vehicle_episodes'][$v->id] ?? 0;
        $hasFaultSignal = collect($signals)->contains(fn ($s) => in_array($s['type'], ['chronic_fault', 'workshop_stalling'], true));
        if ($episodeTotal >= self::FREQUENT_MIN_VISITS && ! $hasFaultSignal) {
            $signals[] = [
                'type'   => 'frequent_breakdowns',
                'tier'   => $episodeTotal >= 7 ? 'plan_soon' : 'watch',
                'label'  => 'Breaks down a lot',
                'count'  => $episodeTotal,   // total separate workshop visits
                'detail' => 'Went to the garage ' . $episodeTotal . ' different times. This car breaks down more than the others.',
            ];
            foreach (($intel['vehicle_last_issues'][$v->id] ?? []) as $li) {
                $issueCandidates[strtolower($li)] = max($issueCandidates[strtolower($li)] ?? 0, 5);
            }
        }

        // --- Signal 4: battery overdue ----------------------------------------------
        if ($v->battery_last_changed) {
            $ageMonths = (int) Carbon::parse($v->battery_last_changed)->diffInMonths(Carbon::today());
            if ($ageMonths >= self::BATTERY_LIFE_MONTHS) {
                $signals[] = [
                    'type'   => 'battery_overdue',
                    'tier'   => 'watch',
                    'label'  => 'Old battery',
                    'detail' => 'The battery is ' . $ageMonths . ' months old (best to change around '
                                . self::BATTERY_LIFE_MONTHS . ' months). It may not start one day.',
                ];
                $issueCandidates['battery'] = max($issueCandidates['battery'] ?? 0, 2);
            }
        }

        // --- Signal 5: visual condition grade (Abu Marouf) -------------------------
        // Orange (cosmetic), Yellow (maintenance-needed) and Red (critical/grounded) all
        // surface here so a flagged car shows up in the Maintenance Forecast. Only Orange STAYS
        // in the rental pool; Yellow & Red are both grounded (see VehicleResource + booking
        // guard), so a Yellow/Red car appears in the forecast AND is pulled from Available.
        if (in_array($v->condition_grade, ['orange', 'yellow', 'red'], true)) {
            $meta = [
                'orange' => [
                    'tier' => 'watch', 'label' => 'Graded Orange — cosmetic issues', 'key' => 'bodywork', 'weight' => 3,
                    'fallback' => 'Minor scratches / cosmetic issues logged. Still rentable; flag them to the customer at handover.',
                ],
                'yellow' => [
                    'tier' => 'plan_soon', 'label' => 'Graded Yellow — maintenance needed', 'key' => 'general', 'weight' => 4,
                    'fallback' => 'Showing symptoms — blocked from renting until repaired. Route it to the garage.',
                ],
                'red' => [
                    'tier' => 'act_now', 'label' => 'Graded Red — critical / grounded', 'key' => 'general', 'weight' => 6,
                    'fallback' => 'Unsafe or a major fault — grounded from rental until it is repaired.',
                ],
            ][$v->condition_grade];

            $signals[] = [
                'type'   => 'condition_grade',
                'tier'   => $meta['tier'],
                'label'  => $meta['label'],
                'detail' => trim((string) $v->condition_note) ?: $meta['fallback'],
            ];
            $issueCandidates[$meta['key']] = max($issueCandidates[$meta['key']] ?? 0, $meta['weight']);
        }

        if (empty($signals)) {
            return null;
        }

        // --- Secondary context (never the sole reason a car is listed) --------------
        $context = [];
        if ($this->outOfWarranty($v)) {
            $context[] = 'No warranty — you pay full price for repairs.';
        }
        if ($v->replacement_due_date && Carbon::parse($v->replacement_due_date)->isPast()) {
            $context[] = 'Older than ' . Vehicle::REPLACEMENT_YEARS . ' years — time to think about replacing it.';
        }
        if ($v->odometer && $v->odometer >= 200000) {
            $context[] = 'Very high mileage (' . number_format((int) $v->odometer) . ' km).';
        }

        // The issue we cost the impact against = the heaviest candidate.
        arsort($issueCandidates);
        $primaryIssue = array_key_first($issueCandidates) ?: 'general';

        $impact = $this->simulateImpact($v, $primaryIssue, $intel['issues'], $fleet);

        // Per-issue cost breakdown — one priced line per flagged fault, so the manager sees what
        // each problem costs (and how well it is backed) instead of one opaque lump sum.
        $modelKey      = $this->normalise(trim($v->make . ' ' . $v->model));
        $costBreakdown = $this->costBreakdown($issueCandidates, $intel['issues'], $fleet, $modelKey, $intel['model_issue_cost'] ?? []);
        $repairTotal   = $costBreakdown
            ? round(array_sum(array_column($costBreakdown, 'cost')), 2)
            : $impact['repair_cost'];

        $tierRank = ['act_now' => 0, 'plan_soon' => 1, 'watch' => 2];
        $tier = collect($signals)->sortBy(fn ($s) => $tierRank[$s['tier']])->first()['tier'];

        return [
            'vehicle_id'              => $v->id,
            'plate'                   => $v->plate_no,
            'code'                    => $v->code,
            'car'                     => trim($v->make . ' ' . $v->model) ?: null,
            'year'                    => $v->year,
            'odometer'                => $v->odometer !== null ? (int) $v->odometer : null,
            'operational_status'      => $v->operational_status,
            'condition_grade'         => $v->condition_grade,
            'condition_note'          => $v->condition_note,
            'tier'                    => $tier,
            'signals'                 => $signals,
            'context'                 => $context,
            'primary_issue'           => $primaryIssue,
            'predicted_downtime_days' => $impact['downtime_days'],
            'worst_case_days'         => $impact['worst_case_days'],
            'parts_wait_risk'         => $impact['parts_wait'],
            'predicted_repair_cost'   => $repairTotal,        // combined estimate across all flagged faults
            'cost_breakdown'          => $costBreakdown,       // per-issue cost + confidence
            'daily_rate'              => $impact['daily_rate'],
            'revenue_at_risk'         => $impact['revenue_at_risk'],
            'worst_case_revenue'      => $impact['worst_case_revenue'],
            'potential_saving'        => $impact['potential_saving'],
            'recommended_action'      => $this->recommend($signals, $impact),
            'estimate_basis'          => $impact['basis'],
            'stalling'                => $stalling,   // workshop-stalling incidents, for the roll-up
        ];
    }

    /**
     * Simulate the cost of letting the car fail, from the fleet's own repair history.
     *
     * @param  array<string,array<string,mixed>>  $issueStats
     * @param  array<string,mixed>  $fleet
     * @return array<string,mixed>
     */
    private function simulateImpact(Vehicle $v, string $issue, array $issueStats, array $fleet): array
    {
        $stat = $this->matchIssue($issue, $issueStats);
        $basis = $stat
            ? ('based on ' . $stat['n'] . ' past repair' . ($stat['n'] === 1 ? '' : 's') . ' like this')
            : 'based on the whole fleet';

        $downtime  = max(1.0, round((float) ($stat['avg_days'] ?? $fleet['avg_days']), 1));
        $worstDays = (int) ($stat['max_days'] ?? 0);

        $partsWait = $stat
            ? ((float) $stat['long_rate'] >= self::PARTS_WAIT_LONG_RATE || (int) $stat['max_days'] >= self::LONG_DOWNTIME_DAYS)
            : false;

        $repairCost = isset($stat['avg_cost']) && $stat['avg_cost']
            ? round((float) $stat['avg_cost'], 2)
            : ($fleet['avg_cost'] ?: null);

        // Daily rental rate — what the car earns on the road.
        $daily = (float) ($v->day_rent_value ?: 0);
        if ($daily <= 0) {
            $daily = (float) $fleet['avg_daily_rate'];
        }
        $daily = round($daily ?: self::FALLBACK_DAILY_RATE, 2);

        $revenueAtRisk   = round($downtime * $daily, 2);
        $worstCaseRev    = $worstDays > 0 ? round($worstDays * $daily, 2) : null;

        // Reactive = full unplanned downtime. Preventive = a short stop planned while idle.
        $plannedDays   = min(self::PLANNED_DOWNTIME_DAYS, $downtime);
        $saving        = round(max(0, ($downtime - $plannedDays) * $daily), 2);

        return [
            'downtime_days'      => $downtime,
            'worst_case_days'    => $worstDays ?: null,
            'parts_wait'         => $partsWait,
            'repair_cost'        => $repairCost,
            'daily_rate'         => $daily,
            'revenue_at_risk'    => $revenueAtRisk,
            'worst_case_revenue' => $worstCaseRev,
            'potential_saving'   => $saving,
            'basis'              => $basis,
        ];
    }

    /**
     * Break the repair estimate down into one priced line per fault driving the card, each tagged
     * with how many PAST PRICED repairs back it and a plain-language confidence level.
     *
     * Honesty notes:
     *   - The cost is the issue's own historical average (from `maintenances.cost`); a fault with
     *     no priced history of its own falls back to the fleet average and is marked 'fleet'.
     *   - Confidence is purely the SAMPLE SIZE of priced repairs — not a fabricated probability.
     *   - The workshop records a single all-in cost: there is NO parts-vs-labour split in the
     *     data (the `spare_part` field is empty fleet-wide and `maintenance_items` is unused), so
     *     every line is the full repair price. The card states this rather than inventing a split.
     *
     * Variance flag: the displayed cost stays the reliable fleet-wide number, but if THIS car's
     * model has its own priced history for the fault and that history runs >VARIANCE_THRESHOLD
     * above the fleet average — backed by at least VARIANCE_MIN_SAMPLES priced model repairs, so
     * it is signal not noise — the line carries a `variance` payload for the UI to warn on.
     *
     * @param  array<string,int|float>  $issueCandidates  issue label => weight
     * @param  array<string,array<string,mixed>>  $issueStats
     * @param  array<string,mixed>  $fleet
     * @param  string  $modelKey  normalised make+model of the car being assessed
     * @param  array<string,array<string,array<string,mixed>>>  $modelIssueCost  model key => issue key => cost stats
     * @return array<int,array<string,mixed>>
     */
    private function costBreakdown(array $issueCandidates, array $issueStats, array $fleet, string $modelKey = '', array $modelIssueCost = []): array
    {
        arsort($issueCandidates);   // heaviest fault first
        $out  = [];
        $seen = [];
        foreach (array_keys($issueCandidates) as $issue) {
            $stat = $this->matchIssue($issue, $issueStats);
            // Dedupe: two candidate labels that resolve to the same history line are one cost.
            $key = $this->normalise($stat['label'] ?? $issue);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $hasOwn = $stat && ($stat['avg_cost'] ?? null) !== null;
            $cost   = $hasOwn ? (float) $stat['avg_cost'] : ($fleet['avg_cost'] ?? null);
            if ($cost === null) {
                continue;   // nothing priced — not even a fleet average to show
            }
            $seen[$key] = true;

            $samples = $hasOwn ? (int) $stat['cost_n'] : 0;
            $conf    = ! $hasOwn
                ? 'fleet'
                : ($samples >= self::COST_CONF_HIGH ? 'high' : ($samples >= self::COST_CONF_MED ? 'medium' : 'low'));

            $line = [
                'issue'      => $stat['label'] ?? $issue,
                'cost'       => round($cost, 2),
                'samples'    => $samples,           // priced repairs backing this line
                'confidence' => $conf,              // high | medium | low | fleet
            ];

            // Budget-breaker variance flag — this model vs. the fleet for this exact fault.
            if ($hasOwn && $modelKey !== '' && $cost > 0) {
                $mc = $modelIssueCost[$modelKey][$key] ?? null;
                if ($mc
                    && (int) $mc['cost_n'] >= self::VARIANCE_MIN_SAMPLES
                    && (float) $mc['avg_cost'] > $cost * (1 + self::VARIANCE_THRESHOLD)) {
                    $line['variance'] = [
                        'model'         => $mc['model_label'],
                        'model_cost'    => round((float) $mc['avg_cost'], 2),
                        'model_samples' => (int) $mc['cost_n'],
                        'pct_over'      => (int) round(((float) $mc['avg_cost'] / $cost - 1) * 100),
                    ];
                }
            }

            $out[] = $line;
        }
        return array_slice($out, 0, 6);   // heaviest faults first; keep the card readable
    }

    private function recommend(array $signals, array $impact): string
    {
        $types = array_column($signals, 'type');
        $parts = $impact['parts_wait']
            ? ' Order the parts now — this kind of repair has kept cars stuck for weeks before.'
            : '';

        if (in_array('service_overdue', $types, true)) {
            return 'Book the service now — it is already late.' . $parts;
        }
        if (in_array('workshop_stalling', $types, true)) {
            return 'Call the garage and push them. They are keeping the car too long — it is not the car.';
        }
        if (in_array('chronic_fault', $types, true)) {
            return 'Fix this properly while the car is free. The same problem keeps coming back.' . $parts;
        }
        if (in_array('frequent_breakdowns', $types, true)) {
            return 'Give this car a full check. It breaks down more than the others.' . $parts;
        }
        if (in_array('service_due_soon', $types, true)) {
            return 'Plan its service soon, before it gets late.' . $parts;
        }
        if (in_array('battery_overdue', $types, true)) {
            return 'Change the battery before it leaves a customer stuck.' . $parts;
        }
        return 'Give it a quick check when it is free.' . $parts;
    }

    // ---- history aggregation (single pass over the workshop log) ----------------------

    /**
     * One pass over the N-Maintenance workshop log, producing everything the assessment
     * needs: per-issue repair-impact stats, per-vehicle issue/visit counts, and each
     * vehicle's most recent issues. Visits are grouped (vehicle + out_date) so multi-row
     * visits and garage ping-pong count once.
     *
     * @return array{issues:array<string,array<string,mixed>>, vehicle_issue_visits:array<int,array<string,int>>, vehicle_visit_total:array<int,int>, vehicle_last_issues:array<int,array<int,string>>}
     */
    private function workshopIntel(): array
    {
        $rows = DB::table('maintenances as m')
            ->leftJoin('vendors as vd', 'vd.id', '=', 'm.vendor_id')
            ->whereIn('m.origin', Maintenance::WORKSHOP_LOG_ORIGINS)
            ->whereNotNull('m.out_date')
            // "Rental-First" policy: routine service (oil/filters/periodic) is PLANNED upkeep, not a
            // failure — so recurring oil changes never read as a "Chronic" fault or push a car onto the
            // "Act now" list. Enforced at LABEL grain in splitIssues() rather than by dropping whole
            // visits, so a real fault found during a routine visit is still seen (audit M1). (The
            // proactive, odometer-based service-due reminder lives in Signal 1 via
            // Vehicle::serviceStatus() and is untouched by this.)
            ->orderBy('m.vehicle_id')
            ->orderBy('m.out_date')
            ->get(['m.vehicle_id', 'm.out_date', 'm.actual_in_date', 'm.service_main', 'm.service_sup', 'm.spare_part', 'm.cost', 'm.vendor_id', 'm.garage', 'vd.name as vendor']);

        $d10 = fn ($x) => $x ? substr((string) $x, 0, 10) : null;

        // Build visits keyed by vehicle|out_date.
        $visits = [];
        foreach ($rows as $r) {
            $key = $r->vehicle_id . '|' . ($d10($r->out_date) ?? '');
            if (! isset($visits[$key])) {
                $visits[$key] = ['veh' => (int) $r->vehicle_id, 'out' => $d10($r->out_date), 'ret' => null, 'issues' => [], 'primary' => [], 'spare' => false, 'cost' => 0.0, 'vendor_id' => null, 'vendor' => null];
            }
            $vi = &$visits[$key];
            $in = $d10($r->actual_in_date);
            if ($in && (! $vi['ret'] || $in > $vi['ret'])) {
                $vi['ret'] = $in;
            }
            foreach ($this->splitIssues($r->service_main, $r->service_sup) as $t) {
                $vi['issues'][$t] = true;
            }
            // The PRIMARY fault(s) of the visit come from service_main (service_sup is secondary).
            // Used to credit the visit's stuck-time to its main reason, not every co-occurring fault.
            foreach ($this->splitIssues($r->service_main, null) as $t) {
                $vi['primary'][$t] = true;
            }
            if (trim((string) $r->spare_part) !== '') {
                $vi['spare'] = true;
            }
            $vi['cost'] += (float) $r->cost;
            // First identifiable workshop for the visit (vendor name, else the raw garage label).
            if (! $vi['vendor_id'] && $r->vendor_id) {
                $vi['vendor_id'] = (int) $r->vendor_id;
                $vi['vendor'] = $r->vendor ?: $r->garage;
            } elseif (! $vi['vendor'] && ($r->vendor || $r->garage)) {
                $vi['vendor'] = $r->vendor ?: $r->garage;
            }
            unset($vi);
        }

        // Type-U maintenance contracts per vehicle (open + closed). A car's workshop logs are
        // grouped by the contract they fall under: everything under ONE maintenance contract is a
        // single repair episode, no matter how many times the car shuffles in and out of the shop.
        $uRows = Contract::where('contract_type', 'U')
            ->whereNotNull('out_date')
            ->orderBy('out_date')
            ->get(['id', 'contract_no', 'vehicle_id', 'out_date', 'in_date']);
        $contractNoById = $uRows->pluck('contract_no', 'id')->all();
        $uContracts = $uRows->groupBy('vehicle_id');

        // Vehicle id => its make+model identity, for the per-model cost-variance flag.
        $modelMap = [];
        foreach (Vehicle::query()->get(['id', 'make', 'model']) as $mv) {
            $label = trim(($mv->make ?? '') . ' ' . ($mv->model ?? ''));
            $key = $this->normalise($label);
            if ($key !== '') {
                $modelMap[$mv->id] = ['key' => $key, 'label' => $label];
            }
        }

        $issueAgg      = [];   // normalised issue => duration bag
        $modelIssueAgg = [];   // model key => [issue key => priced-cost bag] (for the variance flag)
        $issueLog      = [];   // veh => [issue label => [ {date, vendor_id, vendor, contract}, ... ]]
        $vehMechVisits = [];   // veh => [ {date, contract}, ... ] of mechanical visits
        $vehLatest     = [];   // veh => [out_date, issues]

        foreach ($visits as $vi) {
            // Predictive maintenance is about MECHANICAL / safety faults, not rental-return
            // cosmetics (scratches, dents, cleaning). Keep only the mechanical issues.
            $mech = array_values(array_filter(array_keys($vi['issues']), fn ($t) => $this->isMechanical($t)));
            if (empty($mech) || ! $vi['out']) {
                continue;
            }
            $veh = $vi['veh'];
            // Which Type-U maintenance contract (if any) this workshop log belongs to.
            $uId = $this->resolveUContract($uContracts[$veh] ?? null, $vi['out']);
            $vehMechVisits[$veh][] = ['date' => $vi['out'], 'contract' => $uId];

            if (! isset($vehLatest[$veh]) || $vi['out'] > $vehLatest[$veh]['out']) {
                $vehLatest[$veh] = ['out' => $vi['out'], 'issues' => $mech];
            }

            foreach ($mech as $issue) {
                $issueLog[$veh][$issue][] = ['date' => $vi['out'], 'vendor_id' => $vi['vendor_id'], 'vendor' => $vi['vendor'], 'contract' => $uId];
            }

            // duration aggregation needs both ends
            if (! $vi['ret']) {
                continue;
            }
            $days = Carbon::parse($vi['out'])->diffInDays(Carbon::parse($vi['ret']), false);
            if ($days < 0) {
                continue;
            }
            // De-dupe the stuck-time: a visit's duration is credited only to its PRIMARY fault(s)
            // (service_main), so one multi-issue session can't post as the "longest" for every fault
            // it happened to carry. Frequency, spare-rate and cost still count EVERY fault (the
            // parts-ordering signal). Fall back to the first mechanical fault when service_main has
            // no mechanical tag, so a long session is never dropped from the duration stats entirely.
            $primaryMech = array_values(array_filter(array_keys($vi['primary']), fn ($t) => $this->isMechanical($t)));
            if (empty($primaryMech)) {
                $primaryMech = [$mech[0]];
            }
            $primaryKeys = array_flip(array_filter(array_map(fn ($t) => $this->normalise($t), $primaryMech)));

            foreach ($mech as $issue) {
                $k = $this->normalise($issue);
                if ($k === '') {
                    continue;
                }
                $issueAgg[$k] ??= ['label' => $issue, 'n' => 0, 'dur_n' => 0, 'days_sum' => 0.0, 'long' => 0, 'spare' => 0, 'cost_sum' => 0.0, 'cost_n' => 0, 'max_days' => 0, 'worst' => null];
                $a = &$issueAgg[$k];
                $a['n']++;   // FREQUENCY — every fault on the visit (drives "how often does this part fail")
                if ($vi['spare']) {
                    $a['spare']++;
                }
                if ($vi['cost'] > 0) {
                    $a['cost_sum'] += $vi['cost'];
                    $a['cost_n']++;
                    // Same priced repair, bucketed by the car's MODEL — feeds the variance flag.
                    if ($mk = ($modelMap[$veh] ?? null)) {
                        $modelIssueAgg[$mk['key']][$k] ??= ['model_label' => $mk['label'], 'cost_sum' => 0.0, 'cost_n' => 0];
                        $modelIssueAgg[$mk['key']][$k]['cost_sum'] += $vi['cost'];
                        $modelIssueAgg[$mk['key']][$k]['cost_n']++;
                    }
                }
                // DURATION — only when this fault is the visit's primary reason (de-duped stuck-time).
                if (isset($primaryKeys[$k])) {
                    $a['dur_n']++;
                    $a['days_sum'] += $days;
                    $a['max_days'] = max($a['max_days'], $days);
                    if ($days >= self::LONG_DOWNTIME_DAYS) {
                        $a['long']++;
                    }
                    // Remember the single longest visit for this fault — the deep-dive offender.
                    if ($days > (int) ($a['worst']['days'] ?? -1)) {
                        $a['worst'] = ['veh' => $veh, 'out' => $vi['out'], 'ret' => $vi['ret'], 'days' => $days];
                    }
                }
                unset($a);
            }
        }

        $issues = [];
        foreach ($issueAgg as $k => $a) {
            $issues[$k] = [
                'label'      => $a['label'],
                'n'          => $a['n'],                                    // total occurrences (frequency)
                // Duration stats are over the visits where this fault was PRIMARY (dur_n), so a fault
                // that only ever rode along on someone else's long repair shows null/0 here, not 20 days.
                'avg_days'   => $a['dur_n'] ? round($a['days_sum'] / $a['dur_n'], 1) : null,
                'max_days'   => $a['max_days'],
                'long_rate'  => $a['dur_n'] ? round($a['long'] / $a['dur_n'], 2) : 0.0,
                'long_count' => $a['long'],
                'spare_rate' => round($a['spare'] / max($a['n'], 1), 2),
                'avg_cost'   => $a['cost_n'] ? round($a['cost_sum'] / $a['cost_n'], 2) : null,
                'cost_n'     => $a['cost_n'],   // how many PRICED repairs back avg_cost (drives confidence)
                'worst'      => $a['worst'],
            ];
        }

        // Episode + stalling analysis per (vehicle, issue). Contract-aware: all logs under one
        // Type-U maintenance contract are ONE episode (same contract = same repair); outside a
        // maintenance contract the 7-day buffer joins continuous logs. A genuine recurrence is a
        // new/different contract, or a >7-day gap with no contract.
        $vehIssueEpisodes = [];
        $vehIssueBasis    = [];   // veh => [issue => human hint explaining the chronic recurrence]
        $vehIssueEvidence = [];   // veh => [issue => per-episode evidence (contracts + gaps)]
        $vehIssueStalling = [];
        foreach ($issueLog as $veh => $byIssue) {
            foreach ($byIssue as $issue => $entries) {
                $ep = $this->groupEpisodes($entries);
                $vehIssueEpisodes[$veh][$issue] = $ep['count'];
                $vehIssueBasis[$veh][$issue] = $this->episodeHint($ep);
                $vehIssueEvidence[$veh][$issue] = $this->buildEvidence($ep['episodes'], $contractNoById);
                if ($stall = $this->detectStalling($entries)) {
                    $vehIssueStalling[$veh][$issue] = $stall;
                }
            }
        }

        // Distinct repair episodes per vehicle (across all mechanical issues), same rules.
        $vehicleEpisodes = [];
        foreach ($vehMechVisits as $veh => $entries) {
            $vehicleEpisodes[$veh] = $this->groupEpisodes($entries)['count'];
        }

        $vehLastIssues = [];
        foreach ($vehLatest as $veh => $info) {
            $vehLastIssues[$veh] = $info['issues'];
        }

        // Per-model, per-issue average priced cost — compared against the fleet figure to flag
        // models that run expensive for a given fault (the "budget-breaker" variance warning).
        $modelIssueCost = [];
        foreach ($modelIssueAgg as $mKey => $byIssue) {
            foreach ($byIssue as $iKey => $a) {
                $modelIssueCost[$mKey][$iKey] = [
                    'avg_cost'    => round($a['cost_sum'] / max($a['cost_n'], 1), 2),
                    'cost_n'      => $a['cost_n'],
                    'model_label' => $a['model_label'],
                ];
            }
        }

        return [
            'issues'                 => $issues,
            'longest_sessions'       => $this->buildLongestSessions($visits),
            'model_issue_cost'       => $modelIssueCost,
            'vehicle_issue_episodes' => $vehIssueEpisodes,
            'vehicle_issue_basis'    => $vehIssueBasis,
            'vehicle_issue_evidence' => $vehIssueEvidence,
            'vehicle_issue_stalling' => $vehIssueStalling,
            'vehicle_episodes'       => $vehicleEpisodes,
            'vehicle_last_issues'    => $vehLastIssues,
        ];
    }

    /**
     * The Type-U maintenance contract a workshop log on $date belongs to (the latest contract
     * whose window — out_date − buffer … in_date — covers the date), or null if none. A null
     * means the log happened with no maintenance contract open (e.g. during a rental).
     *
     * @param  \Illuminate\Support\Collection<int,object>|null  $contracts
     */
    private function resolveUContract($contracts, ?string $date): ?int
    {
        if (! $contracts || ! $date) {
            return null;
        }
        $d = Carbon::parse($date);
        $bestId = null;
        $bestOut = null;
        foreach ($contracts as $c) {
            if ($d->lt(Carbon::parse($c->out_date)->subDays(self::CONTRACT_BUFFER_DAYS))) {
                continue;                                              // before this contract started
            }
            if ($c->in_date && $d->gt(Carbon::parse($c->in_date)->endOfDay())) {
                continue;                                              // after this (closed) contract ended
            }
            if ($bestOut === null || $c->out_date > $bestOut) {        // prefer the latest covering contract
                $bestOut = $c->out_date;
                $bestId = (int) $c->id;
            }
        }
        return $bestId;
    }

    /**
     * Split a list of visits into distinct repair EPISODES, contract-aware, and record WHY each
     * new episode started (so the report can explain the chronic flag):
     *   - Rule A (contract-bound): visits sharing the same Type-U maintenance contract = ONE
     *     episode (any number of in/out shuffles), whatever the dates — "same contract = same
     *     repair". The chronic counter is NOT incremented inside a contract.
     *   - Rule B (rental / no contract): visits with NO maintenance contract join the current
     *     episode only if within WAITING_BUFFER_DAYS of it.
     *   - A NEW episode (chronic recurrence) starts when the contract id changes, a contract
     *     opens/closes, or there is a >buffer gap with no contract.
     *
     * @param  array<int,array{date:string,contract:?int}>  $entries
     * @return array{count:int, boundaries:array<int,string>, episodes:array<int,array<string,mixed>>}
     *         boundaries holds the reason each episode after the first began
     *         ('gap'|'contract_change'|'contract_opened'|'contract_closed'); episodes is the full
     *         breakdown — each {contract, visits, first, last, gap_days, boundary} — the evidence.
     */
    private function groupEpisodes(array $entries): array
    {
        usort($entries, fn ($a, $b) => $a['date'] <=> $b['date']);

        $episodes = [];
        $i = -1;   // index of the current episode
        foreach ($entries as $e) {
            $c = $e['contract'] ?? null;
            $same = false;
            if ($i >= 0) {
                $cur = $episodes[$i];
                if ($c !== null && $cur['contract'] !== null) {
                    $same = $c === $cur['contract'];
                } elseif ($c === null && $cur['contract'] === null) {
                    $same = Carbon::parse($cur['last'])->diffInDays(Carbon::parse($e['date'])) <= self::WAITING_BUFFER_DAYS;
                }
            }

            if ($same) {
                $episodes[$i]['visits']++;
                if ($e['date'] > $episodes[$i]['last']) {
                    $episodes[$i]['last'] = $e['date'];
                }
                continue;
            }

            // start a new episode — record WHY and the gap from the previous one
            $gap = null;
            $boundary = null;
            if ($i >= 0) {
                $prev = $episodes[$i];
                $gap = (int) Carbon::parse($prev['last'])->diffInDays(Carbon::parse($e['date']));
                if ($c !== null && $prev['contract'] !== null) {
                    $boundary = 'contract_change';
                } elseif ($c !== null && $prev['contract'] === null) {
                    $boundary = 'contract_opened';
                } elseif ($c === null && $prev['contract'] !== null) {
                    $boundary = 'contract_closed';
                } else {
                    $boundary = 'gap';
                }
            }
            $episodes[] = ['contract' => $c, 'visits' => 1, 'first' => $e['date'], 'last' => $e['date'], 'gap_days' => $gap, 'boundary' => $boundary];
            $i++;
        }

        $boundaries = array_values(array_filter(array_map(fn ($ep) => $ep['boundary'], $episodes)));

        return ['count' => count($episodes), 'boundaries' => $boundaries, 'episodes' => $episodes];
    }

    /**
     * Turn the raw episode list into card "evidence": each episode with its contract number (for
     * Case A) or gap days (for Case B), so the user sees exactly why the fault is chronic.
     *
     * @param  array<int,array<string,mixed>>  $episodes
     * @param  array<int,mixed>  $contractNoById  contract id => contract_no
     * @return array<int,array<string,mixed>>
     */
    private function buildEvidence(array $episodes, array $contractNoById): array
    {
        $out = [];
        foreach ($episodes as $ep) {
            $out[] = [
                'contract_id' => $ep['contract'],
                'contract_no' => $ep['contract'] ? ($contractNoById[$ep['contract']] ?? $ep['contract']) : null,
                'visits'      => $ep['visits'],
                'gap_days'    => $ep['gap_days'],
                'kind'        => $ep['contract'] ? 'contract' : 'rental',
            ];
        }
        return $out;
    }

    /**
     * A short, human hint for WHY an issue is chronic — the filter that started the recurrence(s).
     * e.g. "New episode after a 7-day gap", "New episode after the contract closed",
     * "3 episodes · separated by a new contract + a 7-day gap". Null when not chronic.
     *
     * @param  array{count:int, boundaries:array<int,string>}  $ep
     */
    private function episodeHint(array $ep): ?string
    {
        if ($ep['count'] < 2 || empty($ep['boundaries'])) {
            return null;
        }
        $map = [
            'gap'              => 'came back a week later',
            'contract_change'  => 'fixed before, broke again',
            'contract_opened'  => 'fixed before, broke again',
            'contract_closed'  => 'fixed before, broke again',
        ];
        $frags = [];
        foreach ($ep['boundaries'] as $b) {
            $frags[$map[$b] ?? $b] = true;
        }
        $list = array_keys($frags);

        if (count($ep['boundaries']) === 1) {
            return $list[0];
        }
        return 'broke again ' . $ep['count'] . ' separate times';
    }

    /**
     * Detect workshop stalling: the SAME garage holding the car STALL_MIN_VISITS times for the
     * same issue inside a STALL_WINDOW_DAYS window — a vendor problem, not a recurring car fault.
     *
     * @param  array<int,array{date:string,vendor_id:?int,vendor:?string}>  $entries
     * @return array{vendor_id:?int,vendor:?string,visits:int,span_days:int,from:string,to:string}|null
     */
    private function detectStalling(array $entries): ?array
    {
        $byVendor = [];
        foreach ($entries as $e) {
            $key = $e['vendor_id'] ?: $e['vendor'];
            if (! $key) {
                continue;   // can't attribute to a specific workshop
            }
            $byVendor[$key]['vendor'] = $e['vendor'];
            $byVendor[$key]['vendor_id'] = $e['vendor_id'] ?: null;
            $byVendor[$key]['dates'][] = $e['date'];
        }

        $best = null;
        foreach ($byVendor as $info) {
            $dates = array_values(array_unique($info['dates']));
            sort($dates);
            $n = count($dates);
            for ($i = 0; $i < $n; $i++) {
                $cnt = 1;
                $last = $dates[$i];
                for ($j = $i + 1; $j < $n; $j++) {
                    if (Carbon::parse($dates[$i])->diffInDays(Carbon::parse($dates[$j])) <= self::STALL_WINDOW_DAYS) {
                        $cnt++;
                        $last = $dates[$j];
                    } else {
                        break;   // dates are sorted, so no later one fits either
                    }
                }
                if ($cnt >= self::STALL_MIN_VISITS) {
                    $span = (int) Carbon::parse($dates[$i])->diffInDays(Carbon::parse($last));
                    $cand = ['vendor_id' => $info['vendor_id'], 'vendor' => $info['vendor'], 'visits' => $cnt, 'span_days' => $span, 'from' => $dates[$i], 'to' => $last];
                    if (! $best || $cand['visits'] > $best['visits'] || ($cand['visits'] === $best['visits'] && $span < $best['span_days'])) {
                        $best = $cand;
                    }
                }
            }
        }
        return $best;
    }

    /** Fleet-wide fallbacks for cars/issues with no history of their own. */
    private function fleetBaselines(array $issues): array
    {
        // avg_days is null for faults that were never a visit's primary reason — exclude those.
        $days  = array_filter(array_column($issues, 'avg_days'), fn ($d) => $d !== null);
        $costs = array_filter(array_column($issues, 'avg_cost'));

        $avgDaily = (float) Vehicle::whereNotIn('status', ['disposed', 'sold'])
            ->where('day_rent_value', '>', 0)->avg('day_rent_value');

        return [
            'avg_days'       => $days ? round(array_sum($days) / count($days), 1) : self::FALLBACK_DOWNTIME_DAYS,
            'avg_cost'       => $costs ? round(array_sum($costs) / count($costs), 2) : null,
            'avg_daily_rate' => $avgDaily ? round($avgDaily, 2) : self::FALLBACK_DAILY_RATE,
        ];
    }

    /**
     * Vehicle ids already in the shop — the canonical maintenance set (open U-contract, manual
     * garage event, OR open workflow ticket). These are excluded from predictions: a car with an
     * OPEN workflow ticket is already being handled, so Foresight must not also flag it "act now".
     */
    private function vehiclesCurrentlyInGarage(): array
    {
        return array_fill_keys($this->operations->vehiclesInMaintenance(), true);
    }

    /**
     * The longest repair SESSIONS (one row per workshop visit, not per fault) — the de-duped
     * "stuck cars" list. A visit that carried four faults appears ONCE, with its primary fault and
     * a "+N more" count, its real entry→exit window and a deep-link to the workshop event. Sorted
     * longest-first, top 10. Built from the same (vehicle + out_date) visit grouping as the per-fault
     * stats, so the two views reconcile.
     *
     * @param  array<string,array<string,mixed>>  $visits
     * @return array<int,array<string,mixed>>
     */
    private function buildLongestSessions(array $visits): array
    {
        $sessions = [];
        foreach ($visits as $vi) {
            if (! $vi['out'] || ! $vi['ret']) {
                continue;   // need both ends to measure a session length
            }
            $mech = array_values(array_filter(array_keys($vi['issues']), fn ($t) => $this->isMechanical($t)));
            if (empty($mech)) {
                continue;
            }
            $days = Carbon::parse($vi['out'])->diffInDays(Carbon::parse($vi['ret']), false);
            if ($days < 0) {
                continue;
            }
            $primary = array_values(array_filter(array_keys($vi['primary']), fn ($t) => $this->isMechanical($t)));
            $sessions[] = [
                'veh'     => $vi['veh'],
                'out'     => $vi['out'],
                'ret'     => $vi['ret'],
                'days'    => $days,
                'primary' => $primary[0] ?? $mech[0],
                'issues'  => $mech,
            ];
        }

        usort($sessions, fn ($a, $b) => $b['days'] <=> $a['days']);
        $sessions = array_slice($sessions, 0, 10);

        // Enrich the top sessions with car / workshop / event deep-link (bounded: ≤10 lookups).
        $out = [];
        foreach ($sessions as $s) {
            $info = $this->resolveOffender(['veh' => $s['veh'], 'out' => $s['out'], 'ret' => $s['ret'], 'days' => $s['days']], $s['primary']);
            if (! $info) {
                continue;
            }
            $out[] = $info + [
                'primary'     => $s['primary'],
                'issues'      => $s['issues'],
                'issue_count' => count($s['issues']),
            ];
        }

        return $out;
    }

    /** The fault types that have historically stranded cars longest — the parts-wait list. */
    private function partsWatch(array $issues): array
    {
        $out = [];
        foreach ($issues as $s) {
            if ($s['n'] < 2 || $s['max_days'] < self::LONG_DOWNTIME_DAYS) {
                continue;
            }
            $out[] = [
                'issue'      => $s['label'],
                'visits'     => $s['n'],
                'avg_days'   => $s['avg_days'],
                'max_days'   => $s['max_days'],
                'long_count' => $s['long_count'],
                'long_rate'  => $s['long_rate'],
                // The exact car + workshop event behind the worst-case number, for the deep-dive.
                'offender'   => $this->resolveOffender($s['worst'] ?? null, $s['label']),
            ];
        }
        usort($out, fn ($a, $b) => [$b['max_days'], $b['long_count']] <=> [$a['max_days'], $a['long_count']]);
        return array_slice($out, 0, 8);
    }

    /**
     * Resolve the worst-case visit to a concrete car + workshop event (the one to highlight
     * on the vehicle page). Picks the row of that visit that actually carries the issue and
     * has the latest return date, so the deep-dive lands on the event that ran long.
     *
     * @param  array{veh:int,out:?string,ret:?string,days:int}|null  $worst
     * @return array<string,mixed>|null
     */
    private function resolveOffender(?array $worst, string $issueLabel): ?array
    {
        if (! $worst || empty($worst['veh'])) {
            return null;
        }

        $rows = DB::table('maintenances as m')
            ->leftJoin('vehicles as v', 'v.id', '=', 'm.vehicle_id')
            ->leftJoin('vendors as vd', 'vd.id', '=', 'm.vendor_id')
            ->where('m.vehicle_id', $worst['veh'])
            ->whereDate('m.out_date', $worst['out'])
            // origin 'sheet' = the rows the vehicle page's Maintenance Log renders, so the
            // deep-dive always lands on an event the user can actually see & open.
            ->where('m.origin', 'sheet')
            ->get(['m.id', 'm.service_main', 'm.service_sup', 'm.actual_in_date', 'm.garage', 'v.plate_no', 'v.make', 'v.model', 'vd.name as vendor']);

        if ($rows->isEmpty()) {
            return null;
        }

        $needle = $this->normalise($issueLabel);
        $best = null;
        foreach ($rows as $r) {
            $carries = $needle !== '' && str_contains($this->normalise($r->service_main . ' ' . $r->service_sup), $needle);
            $ret = (string) ($r->actual_in_date ?? '');
            if ($best === null
                || ($carries && ! $best['carries'])
                || ($carries === $best['carries'] && $ret > $best['ret'])) {
                $best = ['r' => $r, 'carries' => $carries, 'ret' => $ret];
            }
        }

        $first = $rows->first();

        return [
            'vehicle_id'     => (int) $worst['veh'],
            'plate'          => $first->plate_no,
            'car'            => trim($first->make . ' ' . $first->model) ?: null,
            'out_date'       => $worst['out'],
            'actual_in_date' => $worst['ret'],
            'days'           => (int) $worst['days'],
            'garage'         => $best['r']->vendor ?: $best['r']->garage,
            'event_id'       => (int) $best['r']->id,
        ];
    }

    // ---- small helpers ---------------------------------------------------------------

    /**
     * True when an issue label is a mechanical / safety fault (not cosmetic). Cosmetic / appearance
     * work is excluded from breakdown prediction (deny-first) — the workshop log is dominated by
     * rental-return cosmetics and those are not failures. The keyword lists are shared with the
     * per-car recurrence engine via FaultVocabulary so the two views can never disagree.
     */
    private function isMechanical(string $issue): bool
    {
        return FaultVocabulary::isMechanical($issue);
    }

    private function outOfWarranty(Vehicle $v): bool
    {
        if ($v->warranty_end_date && Carbon::parse($v->warranty_end_date)->isPast()) {
            return true;
        }
        if ($v->warranty_end_km && $v->odometer && $v->odometer > $v->warranty_end_km) {
            return true;
        }
        return false;
    }

    /** Find the issue-stats entry that best matches a fault/issue label (word overlap). */
    private function matchIssue(string $issue, array $issues): ?array
    {
        if ($issue === '' || empty($issues)) {
            return null;
        }
        $key = $this->normalise($issue);
        if ($key !== '' && isset($issues[$key])) {
            return $issues[$key];
        }
        $words = array_filter(explode(' ', $key), fn ($w) => strlen($w) >= 4);
        $best = null;
        foreach ($issues as $k => $s) {
            foreach ($words as $w) {
                if (str_contains($k, $w) && (! $best || $s['n'] > $best['n'])) {
                    $best = $s;
                }
            }
        }
        return $best;
    }

    /** @return array<int,string> */
    /**
     * The visit's FAULT labels — split from the sheet's free-text columns and then typed.
     *
     * The engine's whole output (Chronic, Act-now, cost-per-issue) is "which faults keep happening", so a
     * planned-service label must not enter it and a bookkeeping word must not become an issue. This used
     * to be handled only by excluding whole visits whose `visit_context` was routine, which is both too
     * coarse (a real fault found during a routine visit was dropped) and too narrow (`visit_context` is
     * null on most sheet rows, so an "Oil & Fillter Change" label on an ordinary visit still counted as a
     * fault). Typing the LABELS through the one vocabulary fixes both directions. See audit M1.
     */
    private function splitIssues(?string $main, ?string $sup): array
    {
        return app(EventClassificationService::class)
            ->splitLabels(FaultVocabulary::splitIssues($main, $sup))[MaintenanceTask::KIND_FAULT];
    }

    private function normalise(string $s): string
    {
        return FaultVocabulary::normalise($s);
    }

    /** @param  array<int,array<string,mixed>>  $cars */
    private function summarise(array $cars): array
    {
        $sum = fn ($key) => round(array_sum(array_column($cars, $key)), 2);

        // Worst-case exposure: what the parts-wait cars would cost in lost rental if their
        // fault runs as long as it historically has (the "stuck 2 months on a part" scenario).
        $exposure = round(array_sum(array_map(
            fn ($c) => $c['parts_wait_risk'] ? (float) ($c['worst_case_revenue'] ?? $c['revenue_at_risk']) : 0,
            $cars
        )), 2);

        return [
            'flagged'             => count($cars),
            'act_now'             => count(array_filter($cars, fn ($c) => $c['tier'] === 'act_now')),
            'plan_soon'           => count(array_filter($cars, fn ($c) => $c['tier'] === 'plan_soon')),
            'watch'               => count(array_filter($cars, fn ($c) => $c['tier'] === 'watch')),
            'stalling_cars'       => count(array_filter($cars, fn ($c) => ! empty($c['stalling']))),
            'parts_wait_cars'     => count(array_filter($cars, fn ($c) => $c['parts_wait_risk'])),
            'negative_yield_cars' => count(array_filter($cars, fn ($c) => ! empty($c['negative_yield']))),
            'parts_wait_exposure' => $exposure,
            'revenue_at_risk'     => $sum('revenue_at_risk'),
            'potential_saving'    => $sum('potential_saving'),
            'downtime_days'       => round(array_sum(array_column($cars, 'predicted_downtime_days')), 1),
        ];
    }
}
