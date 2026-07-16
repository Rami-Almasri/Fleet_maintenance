<?php

namespace App\Services;

use App\Models\Maintenance;
use App\Models\Vehicle;
use App\Models\VehicleLogEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The read-only MONITOR over the automatic inspection engine — the data brain behind the Inspection
 * Intelligence Center page. It answers "why did the system request this inspection, which rule fired,
 * which cars qualify right now, and which were skipped (and why)" WITHOUT ever writing anything.
 *
 * There is exactly ONE automatic inspection generator in this codebase:
 *
 *   Command  : inspections:generate-tasks   (App\Console\Commands\InspectionsGenerateTasks)
 *   Schedule : daily at 07:30               (routes/console.php)
 *   Rules    : DiagnosticGateService::conditionsDue()  — oil/service, reminders (tyres…), battery age,
 *              post-downtime idle
 *   Writer   : MaintenanceWorkflowService::systemRequestInspection()  → a `pending_review` ticket,
 *              trigger_reason = periodic, requested_by = null, driver = "System · Auto-Check"
 *
 * This service re-derives the SAME classification the command applies (active-fleet only, skip
 * for-sale, dedup against the open pipeline, the oil "implausible odometer" sanity ceiling), so the
 * live queue on the page matches exactly what the next 07:30 run would do — but it only READS. The
 * command remains the sole writer. Historical tallies are read straight off the `maintenances` rows
 * the writer produced (trigger_reason = periodic) and the `vehicle_log_events` audit trail.
 */
class InspectionEngineService
{
    /**
     * Absolute floor for the oil sanity ceiling, in km — mirrors
     * InspectionsGenerateTasks::ANOMALY_FLOOR_KM. A service-due distance over
     * max(20,000 km, 3 × interval) is almost certainly a bad odometer reading; the command drops that
     * oil condition and logs a data anomaly instead of requesting a nonsense inspection.
     */
    private const ANOMALY_FLOOR_KM = 20000;

    /** The engine's cron cadence (routes/console.php: inspections:generate-tasks->dailyAt('07:30')). */
    private const RUN_HOUR = 7;
    private const RUN_MINUTE = 30;

    /**
     * OM lifecycle statuses that mean the car has left the active fleet — mirrors
     * DiagnosticGateService::LEFT_FLEET. conditionsDue() already returns [] for these, but we also
     * exclude them from the scan query so the monitor never walks sold/disposed cars.
     */
    private const LEFT_FLEET = ['disposed', 'sold', 'out_of_order', 'suspended', 'returned'];

    /**
     * The canonical rule catalog — the four condition groups DiagnosticGateService can raise, in the
     * order the page displays them. `match_keys` maps a live condition (or a persisted trigger_detail
     * rule) back to its rule via the condition `key` shape the gate emits.
     *
     * @var array<int,array<string,mixed>>
     */
    private const RULES = [
        [
            'key'         => 'oil',
            'name'        => 'Oil / Service Overdue',
            'description' => 'Service interval passed by distance (odometer − last service ≥ interval) OR by date (the oil-change reminder is past due). Either axis trips the rule.',
            'severity'    => 'moderate',
        ],
        [
            'key'         => 'reminder',
            'name'        => 'Service Reminder Overdue',
            'description' => 'Any other active Service Reminder is overdue by km or date — tyre rotation / change, brakes, filters, or a manually-added reminder.',
            'severity'    => 'moderate',
        ],
        [
            'key'         => 'battery',
            'name'        => 'Battery Past Service Life',
            'description' => 'Battery age has passed its ~30-month service life. Fallback rule — only fires when no explicit battery Service Reminder already covers the car.',
            'severity'    => 'routine',
        ],
        [
            'key'         => 'downtime',
            'name'        => 'Post-Downtime Safety Check',
            'description' => 'A free (not rented / in-shop / in-transit) car has sat idle beyond the downtime limit. Carries the idle-day count and a Battery / Fluids / Brakes checklist.',
            'severity'    => 'moderate',
        ],
        [
            'key'         => 'inactivity',
            'name'        => 'Inactivity Check',
            'description' => 'The counterpart of the downtime rule: a car that has NOT been rented at all since its last test is auto-checked once the (longer) inactivity window lapses, so a parked-and-forgotten car is kept road-ready even though the downtime rule holds off for never-re-rented cars.',
            'severity'    => 'routine',
        ],
    ];

    /** Skip-reason codes surfaced on the page (Section 5). */
    private const SKIP_LABELS = [
        'already_pending'      => 'Already in the workflow pipeline',
        'implausible_odometer' => 'Oil condition dropped — implausible odometer (data anomaly)',
        'for_sale'             => 'Vehicle flagged for sale',
        'vehicle_inactive'     => 'Vehicle not in active fleet (Ready / Rented)',
        'scan_error'           => 'Engine errored while evaluating this vehicle',
    ];

    public function __construct(private DiagnosticGateService $gate)
    {
    }

    /**
     * The full monitor payload for the Inspection Intelligence Center. One pure-read pass over the live
     * fleet plus a lightweight roll-up of the periodic tickets the engine has already created.
     *
     * @return array<string,mixed>
     */
    public function monitor(): array
    {
        $scan = $this->liveScan();
        $history = $this->history();

        return [
            'generated_at' => Carbon::now()->toIso8601String(),
            'engine'       => $this->engineMeta(),
            'health'       => $this->health($scan, $history),
            'queue'        => $scan['queue'],
            'skipped'      => $scan['skipped'],
            'rules'        => $this->rules($scan, $history),
            'timeline'     => $history['timeline'],
            'log'          => $history['log'],
            'future'       => $this->futureTriggers(),
        ];
    }

    // ── Live scan ────────────────────────────────────────────────────────────────────────────────

    /**
     * Walk the live fleet and classify every car that currently matches a rule into the SAME buckets
     * the command would produce: a real request (queue), or a skip with its exact reason.
     *
     * @return array{queue:array,skipped:array,scanned:int,errors:int,matching:array<string,int>,skip_counts:array<string,int>}
     */
    private function liveScan(): array
    {
        // Cars already somewhere in the open workflow pipeline (incl. the pre-ticket "requested" state)
        // — the engine never raises a second request for these. Keyed by vehicle for stage lookup.
        $pipeline = Maintenance::openWorkflow()
            ->whereNotNull('vehicle_id')
            ->get(['id', 'vehicle_id', 'workflow_status', 'trigger_reason'])
            ->keyBy('vehicle_id');

        $queue    = [];
        $skipped  = [];
        $scanned  = 0;
        $errors   = 0;
        $matching = ['oil' => 0, 'reminder' => 0, 'battery' => 0, 'downtime' => 0, 'inactivity' => 0];
        $skipCounts = array_fill_keys(array_keys(self::SKIP_LABELS), 0);

        Vehicle::query()
            ->whereNotIn('status', self::LEFT_FLEET)
            ->orderBy('code')
            ->chunkById(500, function ($vehicles) use (
                &$queue, &$skipped, &$scanned, &$errors, &$matching, &$skipCounts, $pipeline
            ) {
                foreach ($vehicles as $v) {
                    $scanned++;

                    try {
                        $conditions = $this->gate->conditionsDue($v);
                    } catch (\Throwable $e) {
                        $errors++;
                        $skipCounts['scan_error']++;
                        $skipped[] = $this->skipRow($v, [], 'scan_error', $e->getMessage());
                        continue;
                    }

                    if (empty($conditions)) {
                        continue; // scanned, nothing due — not shown
                    }

                    foreach ($conditions as $c) {
                        $bucket = $this->ruleBucket($c['key'] ?? '');
                        if ($bucket) {
                            $matching[$bucket]++;
                        }
                    }

                    $isActive = in_array($v->status, Vehicle::ACTIVE_STATUSES, true);
                    $forSale  = (bool) $v->for_sale;
                    $existing = $pipeline->get($v->id);

                    // Precedence mirrors the command's filter order: fleet eligibility → for-sale → dedup
                    // → oil sanity ceiling. Each of these is a real reason the engine withholds a request.
                    if (! $isActive) {
                        $skipCounts['vehicle_inactive']++;
                        $skipped[] = $this->skipRow($v, $conditions, 'vehicle_inactive');
                        continue;
                    }
                    if ($forSale) {
                        $skipCounts['for_sale']++;
                        $skipped[] = $this->skipRow($v, $conditions, 'for_sale');
                        continue;
                    }
                    if ($existing) {
                        $skipCounts['already_pending']++;
                        $skipped[] = $this->skipRow($v, $conditions, 'already_pending', null, $existing);
                        continue;
                    }

                    // Oil sanity ceiling — drop an implausible service-due, keep every other condition.
                    [$kept, $anomaly] = $this->applyOilCeiling($v, $conditions);
                    if (empty($kept)) {
                        $skipCounts['implausible_odometer']++;
                        $skipped[] = $this->skipRow($v, $conditions, 'implausible_odometer', $anomaly['detail'] ?? null);
                        continue;
                    }

                    $queue[] = $this->queueRow($v, $kept, $anomaly);
                }
            });

        // Worst-severity first, then by exceeded amount, so the most urgent cars lead the queue.
        usort($queue, fn ($a, $b) => [$b['priority_rank'], $b['exceeded_sort']] <=> [$a['priority_rank'], $a['exceeded_sort']]);

        return compact('queue', 'skipped', 'scanned', 'errors', 'matching') + ['skip_counts' => $skipCounts];
    }

    /**
     * The command's oil "implausible odometer" ceiling, replicated read-only. Returns the conditions
     * that survive plus the dropped-oil anomaly detail (or null when nothing was dropped).
     *
     * @param  array<int,array<string,mixed>>  $conditions
     * @return array{0:array<int,array<string,mixed>>,1:?array<string,mixed>}
     */
    private function applyOilCeiling(Vehicle $v, array $conditions): array
    {
        $svc = $v->serviceStatus();
        if (($svc['status'] ?? null) !== 'service_due') {
            return [$conditions, null];
        }

        $interval = (int) ($svc['interval'] ?? 0);
        $over     = (int) ($svc['overdue_km'] ?? 0);
        $ceiling  = max(self::ANOMALY_FLOOR_KM, 3 * $interval);
        if ($over <= $ceiling) {
            return [$conditions, null];
        }

        $kept = array_values(array_filter($conditions, fn ($c) => ($c['key'] ?? null) !== 'oil_change'));
        $anomaly = [
            'current_km' => $svc['current'] ?? null,
            'interval'   => $interval,
            'overdue_km' => $over,
            'ceiling'    => $ceiling,
            'detail'     => number_format($over) . ' km over — beyond the ' . number_format($ceiling) . ' km sanity ceiling (fix the odometer in Mileage Reconciliation).',
        ];

        return [$kept, $anomaly];
    }

    /**
     * A queue card — a car the engine WOULD request an inspection for right now.
     *
     * @param  array<int,array<string,mixed>>  $conditions  the surviving (post-ceiling) conditions
     * @param  array<string,mixed>|null  $anomaly  a dropped-oil note, if one was filtered out
     */
    private function queueRow(Vehicle $v, array $conditions, ?array $anomaly): array
    {
        $primary  = $this->primaryCondition($v, $conditions);
        $severity = $this->worstSeverity($conditions);

        return [
            'vehicle_id'         => $v->id,
            'code'               => $v->code,
            'plate'              => $v->plate_no,
            'car'                => trim(($v->make ?? '') . ' ' . ($v->model ?? '')) ?: null,
            'reason'             => collect($conditions)->map(fn ($c) => $c['detail'] ?? $c['label'])->implode(' '),
            'triggers'           => array_values(array_map(fn ($c) => [
                'rule'     => $this->ruleBucket($c['key'] ?? ''),
                'label'    => $c['label'] ?? null,
                'severity' => $c['severity'] ?? 'routine',
                'why'      => $c['detail'] ?? null,
                'axis'     => $c['axis'] ?? null,
            ], $conditions)),
            'priority'           => $severity,
            'priority_rank'      => $this->severityRank($severity),
            'current_km'         => $v->odometer,
            'threshold'          => $primary['threshold'],
            'exceeded'           => $primary['exceeded'],
            'exceeded_sort'      => (int) ($primary['exceeded_value'] ?? 0),
            'metric_label'       => $primary['label'],
            'would_create'       => true,
            'workflow_stage'     => 'pending_review',
            'rental_status'      => $v->operational_status,
            'maintenance_status' => null, // no open ticket — that's why it qualifies
            'condition_grade'    => $v->condition_grade,
            'anomaly'            => $anomaly['detail'] ?? null,
        ];
    }

    /**
     * A skipped card — a car that matched a rule but got NO new request, with the exact reason.
     *
     * @param  array<int,array<string,mixed>>  $conditions
     * @param  \App\Models\Maintenance|null  $existing  the open ticket blocking a new request
     */
    private function skipRow(Vehicle $v, array $conditions, string $reasonCode, ?string $detail = null, $existing = null): array
    {
        return [
            'vehicle_id'      => $v->id,
            'code'            => $v->code,
            'plate'           => $v->plate_no,
            'car'             => trim(($v->make ?? '') . ' ' . ($v->model ?? '')) ?: null,
            'reason_code'     => $reasonCode,
            'reason'          => self::SKIP_LABELS[$reasonCode] ?? $reasonCode,
            'detail'          => $detail,
            'matched'         => array_values(array_map(fn ($c) => [
                'rule'     => $this->ruleBucket($c['key'] ?? ''),
                'label'    => $c['label'] ?? null,
                'severity' => $c['severity'] ?? 'routine',
            ], $conditions)),
            'status'          => $v->status,
            'rental_status'   => $v->operational_status,
            'existing_ticket' => $existing ? [
                'id'    => $existing->id,
                'stage' => $existing->workflow_status,
            ] : null,
        ];
    }

    /** Pick the headline metric for a queue card — the oil rule's km figures when present, else the worst rule's own detail. */
    private function primaryCondition(Vehicle $v, array $conditions): array
    {
        $oil = collect($conditions)->firstWhere('key', 'oil_change');
        if ($oil) {
            $svc = $v->serviceStatus();
            if (($svc['status'] ?? null) === 'service_due') {
                $limit = (int) ($svc['baseline'] ?? 0) + (int) ($svc['interval'] ?? 0);
                $over  = (int) ($svc['overdue_km'] ?? 0);
                return [
                    'label'          => 'Service limit',
                    'threshold'      => number_format($limit) . ' km',
                    'exceeded'       => number_format($over) . ' km over',
                    'exceeded_value' => $over,
                ];
            }
        }

        $downtime = collect($conditions)->firstWhere('key', 'downtime');
        if ($downtime) {
            $days  = (int) ($downtime['days'] ?? 0);
            $limit = (int) $this->gate->downtimeLimitDays();
            return [
                'label'          => 'Idle limit',
                'threshold'      => $limit . ' days',
                'exceeded'       => max(0, $days - $limit) . ' days over',
                'exceeded_value' => max(0, $days - $limit),
            ];
        }

        $inactivity = collect($conditions)->firstWhere('key', 'inactivity');
        if ($inactivity) {
            $days  = (int) ($inactivity['days'] ?? 0);
            $limit = (int) $this->gate->inactivityLimitDays();
            return [
                'label'          => 'Inactivity limit',
                'threshold'      => $limit . ' days',
                'exceeded'       => max(0, $days - $limit) . ' days over',
                'exceeded_value' => max(0, $days - $limit),
            ];
        }

        return ['label' => null, 'threshold' => null, 'exceeded' => null, 'exceeded_value' => 0];
    }

    // ── Idle Fleet Watch ─────────────────────────────────────────────────────────────────────────

    /**
     * Every active-fleet car that has been SITTING IDLE for at least $minDays — no open rental, nobody
     * using it, no live movement — with the day it last became free and how long it has sat since. This
     * is the raw feed behind the Post-Downtime Safety Check rule: `idleInfo()` decides a car is "sitting"
     * only when it is free (not rented / in-shop / in-transit), and dates its idle clock from the return
     * of its last rental (or, for a never-rented car, its onboarding). Read-only.
     *
     * The engine's own downtime limit is surfaced alongside so the page can mark which of these idle cars
     * have already crossed it (and would be auto-flagged) vs. those still under it.
     *
     * @return array{min_days:int,limit:int,count:int,generated_at:string,vehicles:array}
     */
    public function idleWatch(int $minDays = 15): array
    {
        $minDays = max(1, min(365, $minDays));
        $limit   = $this->gate->downtimeLimitDays();

        // Cars already in the pipeline — so each idle car can show whether the engine has already raised
        // a request for it, or it is still sitting with nothing done.
        $pipeline = Maintenance::openWorkflow()
            ->whereNotNull('vehicle_id')
            ->get(['id', 'vehicle_id', 'workflow_status'])
            ->keyBy('vehicle_id');

        $rows = [];
        Vehicle::query()
            ->whereIn('status', Vehicle::ACTIVE_STATUSES)
            ->orderBy('code')
            ->chunkById(500, function ($vehicles) use (&$rows, $minDays, $limit, $pipeline) {
                foreach ($vehicles as $v) {
                    $idle = $this->gate->idleInfo($v);
                    // This board is the cars physically sitting free: in-fleet AND not busy (rented / in
                    // the shop / in transit). The downtime clock itself is test-based and keeps running
                    // even while a car is out — but a car out with a customer belongs on the review queue
                    // (with Approve held), not on this "sitting idle" board.
                    if (! ($idle['eligible'] ?? false) || ! ($idle['free'] ?? false)) {
                        continue;
                    }
                    $days = $idle['days'] ?? null;
                    if ($days === null || $days < $minDays) {
                        continue;
                    }

                    // The clock's anchor is now the car's last confirmed-ready-after-test moment.
                    $reason = $idle['anchor_reason'] ?? 'onboarding';
                    $since  = $idle['idle_since'];
                    $anchorLabel = match ($reason) {
                        'maintenance' => 'Ready after maintenance ' . $since,
                        'test'        => 'Last test ' . $since,
                        default       => 'Never tested — onboarded ' . $since,
                    };
                    $existing = $pipeline->get($v->id);

                    $rows[] = [
                        'vehicle_id'    => $v->id,
                        'code'          => $v->code,
                        'plate'         => $v->plate_no,
                        'car'           => trim(($v->make ?? '') . ' ' . ($v->model ?? '')) ?: null,
                        'idle_days'     => $days,
                        'idle_since'    => $since,
                        'limit'         => $limit,
                        'over_limit'    => (bool) ($idle['exceeded'] ?? false),
                        'over_by'       => max(0, $days - $limit),
                        'anchor'        => $anchorLabel,     // "Last test 2026-06-29" / "Ready after maintenance …" / "Never tested — …"
                        'source'        => $reason === 'onboarding' ? 'onboarding' : 'last_ready',
                        'rental_status' => $v->operational_status,
                        'condition_grade' => $v->condition_grade,
                        // Whether the engine has already acted on this idle car (a request in flight) or it
                        // is still sitting with nothing done — the exact "no one has touched it" signal.
                        'request'       => $existing ? [
                            'id'    => $existing->id,
                            'stage' => $existing->workflow_status,
                        ] : null,
                    ];
                }
            });

        // Attach each car's last actual inspection/test-drive, so the operator can see when this idle car
        // was last looked at (and what was found) before deciding it needs another one.
        $lastTests = $this->lastInspections(array_column($rows, 'vehicle_id'));
        foreach ($rows as &$row) {
            $row['last_test'] = $lastTests[$row['vehicle_id']] ?? null;
        }
        unset($row);

        // Longest-idle first.
        usort($rows, fn ($a, $b) => $b['idle_days'] <=> $a['idle_days']);

        return [
            'min_days'     => $minDays,
            'limit'        => $limit,
            'count'        => count($rows),
            'generated_at' => Carbon::now()->toIso8601String(),
            'vehicles'     => $rows,
        ];
    }


    /**
     * The most recent real inspection/test-drive for each of the given vehicles — the last time someone
     * actually put eyes (or a test drive) on the car. Keyed by vehicle_id. One batched query, no N+1.
     *
     * @param  int[]  $vehicleIds
     * @return array<int,array{at:string,ago_days:int,by:?string,severity:?string,summary:?string}>
     */
    private function lastInspections(array $vehicleIds): array
    {
        $vehicleIds = array_values(array_unique(array_filter($vehicleIds)));
        if ($vehicleIds === []) {
            return [];
        }

        // Latest inspected ticket per vehicle: pull the candidates newest-first, then keep the first seen
        // for each vehicle. `inspected_at` is only set once a diagnostic/test-drive has actually been filed.
        $tickets = Maintenance::query()
            ->whereIn('vehicle_id', $vehicleIds)
            ->whereNotNull('inspected_at')
            ->orderByDesc('inspected_at')
            ->with('inspector:id,name')
            ->get(['id', 'vehicle_id', 'inspected_at', 'inspected_by', 'fault_severity', 'test_drive_report']);

        $out = [];
        foreach ($tickets as $t) {
            if (isset($out[$t->vehicle_id])) {
                continue; // already have this car's newest
            }
            $report  = is_array($t->test_drive_report) ? $t->test_drive_report : [];
            $summary = trim((string) ($report['recommended_action'] ?? ''));
            if ($summary === '') {
                $symptoms = array_filter(array_map('trim', (array) ($report['symptoms'] ?? [])));
                $summary  = $symptoms ? implode(', ', $symptoms) : '';
            }

            $out[$t->vehicle_id] = [
                'at'       => $t->inspected_at->toIso8601String(),
                'ago_days' => (int) $t->inspected_at->diffInDays(Carbon::now()),
                'by'       => $t->inspector?->name,
                'severity' => $t->fault_severity,
                'summary'  => $summary !== '' ? Str::limit($summary, 120) : null,
            ];
        }

        return $out;
    }

    // ── History (persisted, from the maintenances + audit rows the writer created) ─────────────────

    /**
     * Roll up the periodic tickets the engine has already created plus its audit trail.
     *
     * @return array{tickets:\Illuminate\Support\Collection,timeline:array,log:array,auto_today:int,manual_today:int,last_auto_at:?string}
     */
    private function history(): array
    {
        $startOfDay = Carbon::now()->startOfDay();

        // Every system-raised routine ticket, all time — bounded (one row per auto request ever made),
        // carrying the trigger_detail snapshot we tally rules from.
        $tickets = Maintenance::query()
            ->where('trigger_reason', Maintenance::TRIGGER_PERIODIC)
            ->whereNull('requested_by')
            ->orderByDesc('requested_at')
            ->get(['id', 'vehicle_id', 'workflow_status', 'requested_at', 'trigger_detail', 'customer_complaint']);

        $autoToday = $tickets->filter(fn ($t) => $t->requested_at && $t->requested_at->gte($startOfDay))->count();

        $manualToday = Maintenance::query()
            ->whereNotNull('requested_by')
            ->where('requested_at', '>=', $startOfDay)
            ->count();

        $lastAutoAt = optional($tickets->first()?->requested_at)->toIso8601String();

        // Today's inspection-request audit events, newest first — the real chronology of what the engine
        // (and drivers) raised today. `auto` in the meta distinguishes a system request from a human one.
        $events = VehicleLogEvent::query()
            ->where('event_type', VehicleLogEvent::EVENT_INSPECTION_REQUESTED)
            ->where('created_at', '>=', $startOfDay)
            ->orderByDesc('created_at')
            ->limit(60)
            ->get(['id', 'vehicle_id', 'created_at', 'description', 'meta']);

        $timeline = $events->map(function ($e) {
            $auto = (bool) data_get($e->meta, 'auto', false);
            return [
                'id'         => $e->id,
                'time'       => $e->created_at->toIso8601String(),
                'vehicle_id' => $e->vehicle_id,
                'trigger'    => $auto ? 'System auto-check' : 'Driver request',
                'action'     => 'Inspection request created',
                'result'     => data_get($e->meta, 'trigger_reason', '—'),
                'auto'       => $auto,
                'detail'     => $e->description,
            ];
        })->values()->all();

        // Engine log — the most recent SYSTEM inspection events (all time, last 25), for the audit feed.
        $log = VehicleLogEvent::query()
            ->where('event_type', VehicleLogEvent::EVENT_INSPECTION_REQUESTED)
            ->where('meta->auto', true)
            ->orderByDesc('created_at')
            ->limit(25)
            ->get(['id', 'vehicle_id', 'created_at', 'description', 'meta'])
            ->map(fn ($e) => [
                'id'         => $e->id,
                'time'       => $e->created_at->toIso8601String(),
                'vehicle_id' => $e->vehicle_id,
                'trigger'    => 'Proactive Diagnostic Monitor',
                'action'     => 'systemRequestInspection()',
                'result'     => 'pending_review',
                'detail'     => $e->description,
            ])->values()->all();

        return compact('tickets', 'timeline', 'log', 'lastAutoAt') + [
            'auto_today'   => $autoToday,
            'manual_today' => $manualToday,
            'last_auto_at' => $lastAutoAt,
        ];
    }

    // ── Rules (catalog + live/historical tallies) ──────────────────────────────────────────────────

    /**
     * The rule catalog with its live match count and historical request tallies — powers both the
     * Inspection Rules section and the Rule Coverage matrix.
     *
     * @return array<int,array<string,mixed>>
     */
    private function rules(array $scan, array $history): array
    {
        $tickets     = $history['tickets'];
        $startOfDay  = Carbon::now()->startOfDay();
        $startOfWeek = Carbon::now()->startOfWeek();
        $enabled     = $this->gate->enabled();

        // Tally each periodic ticket's fired rules from its trigger_detail snapshot.
        $today = $week = $total = $lastFired = [];
        foreach (array_keys($scan['matching']) as $k) {
            $today[$k] = $week[$k] = $total[$k] = 0;
            $lastFired[$k] = null;
        }

        // Open (still-in-pipeline) periodic tickets per rule → the "pending" column.
        $pendingIds = Maintenance::openWorkflow()
            ->where('trigger_reason', Maintenance::TRIGGER_PERIODIC)
            ->pluck('id')
            ->flip();
        $pending = array_fill_keys(array_keys($scan['matching']), 0);

        foreach ($tickets as $t) {
            $buckets = $this->ticketRuleBuckets($t);
            foreach ($buckets as $b) {
                $total[$b]++;
                if ($t->requested_at) {
                    if ($t->requested_at->gte($startOfDay)) {
                        $today[$b]++;
                    }
                    if ($t->requested_at->gte($startOfWeek)) {
                        $week[$b]++;
                    }
                    if ($lastFired[$b] === null || $t->requested_at->gt(Carbon::parse($lastFired[$b]))) {
                        $lastFired[$b] = $t->requested_at->toIso8601String();
                    }
                }
                if ($pendingIds->has($t->id)) {
                    $pending[$b]++;
                }
            }
        }

        return array_map(function ($rule) use ($scan, $enabled, $today, $week, $total, $lastFired, $pending) {
            $k = $rule['key'];
            return [
                'key'           => $k,
                'name'          => $rule['name'],
                'description'   => $rule['description'],
                'severity'      => $rule['severity'],
                'enabled'       => $enabled,
                'source'        => 'DiagnosticGateService::conditionsDue()',
                'automatic'     => true,
                'frequency'     => 'Daily · 07:30',
                'matching_now'  => $scan['matching'][$k] ?? 0,
                'requests_today' => $today[$k] ?? 0,
                'requests_week' => $week[$k] ?? 0,
                'requests_total' => $total[$k] ?? 0,
                'pending'       => $pending[$k] ?? 0,
                'last_fired_at' => $lastFired[$k] ?? null,
                'never_fired'   => ($total[$k] ?? 0) === 0,
                'health'        => $this->ruleHealth($enabled, $total[$k] ?? 0, $scan['matching'][$k] ?? 0),
            ];
        }, self::RULES);
    }

    /**
     * A rule's health light: disabled → gray; fired before → healthy (green); never fired but matching
     * cars exist right now → armed (blue); never fired and nothing matching → idle (slate).
     */
    private function ruleHealth(bool $enabled, int $total, int $matching): string
    {
        if (! $enabled) {
            return 'disabled';
        }
        if ($total > 0) {
            return 'healthy';
        }
        return $matching > 0 ? 'armed' : 'idle';
    }

    // ── Health KPIs ────────────────────────────────────────────────────────────────────────────────

    private function health(array $scan, array $history): array
    {
        return [
            'scanned'          => $scan['scanned'],
            'would_create'     => count($scan['queue']),
            'auto_created'     => $history['auto_today'],
            'manual_created'   => $history['manual_today'],
            'already_pending'  => $scan['skip_counts']['already_pending'] ?? 0,
            'skipped'          => count($scan['skipped']),
            'anomalies'        => $scan['skip_counts']['implausible_odometer'] ?? 0,
            'errors'           => $scan['errors'],
            'last_run'         => $history['last_auto_at'],
            'next_run'         => $this->nextRun()->toIso8601String(),
            'cadence'          => 'Daily · 07:30',
        ];
    }

    private function engineMeta(): array
    {
        return [
            'name'            => 'Proactive Diagnostic Monitor',
            'command'         => 'inspections:generate-tasks',
            'schedule'        => 'Daily at 07:30 (withoutOverlapping)',
            'enabled'         => $this->gate->enabled(),
            'downtime_limit'  => $this->gate->downtimeLimitDays(),
            'writer'          => 'MaintenanceWorkflowService::systemRequestInspection()',
            'lands_in'        => 'pending_review · Inspection Review Queue (Controllers)',
        ];
    }

    // ── Verification: scenarios the engine does NOT cover ────────────────────────────────────────────

    /**
     * Real gaps in today's automatic coverage — surfaced for the page's "Potential Future Inspection
     * Triggers" panel. These are NOT implemented; they are honest observations from the discovered
     * engine so Fleet Management knows the edges of what is automated.
     *
     * @return array<int,array<string,string>>
     */
    private function futureTriggers(): array
    {
        return [
            [
                'title'  => 'Recurring Inspection Schedules never auto-open a ticket',
                'detail' => 'InspectionSchedule plans (safety / operations) only raise an "inspection_due" NOTIFICATION via notifications:scan — they never call systemRequestInspection(), so a due safety schedule does not create a workflow request the way an overdue oil service does.',
            ],
            [
                'title'  => 'Post-accident / post-incident inspection',
                'detail' => 'A logged damage/accident or an auto-diffed handover incident does not automatically raise a re-inspection request — it is handled manually in the damage log and complaint flow.',
            ],
            [
                'title'  => 'Registration / insurance expiry inspection',
                'detail' => 'fleet:check-expiry grounds cars with expired documents but raises no inspection request — a car cleared to return after renewal is not auto-flagged for a safety look.',
            ],
            [
                'title'  => 'Return-from-rental condition check',
                'detail' => 'A rental return is not itself a trigger; only the downtime clock (idle ≥ limit) eventually catches it. A car handed back and immediately re-rented is never auto-inspected between customers.',
            ],
            [
                'title'  => 'Mileage-spike / hard-usage trigger',
                'detail' => 'A car driven an unusually high distance in one rental (heavy usage) is not flagged for an early check — only the fixed service interval applies.',
            ],
        ];
    }

    // ── Small helpers ────────────────────────────────────────────────────────────────────────────────

    /** Map a condition/trigger `key` to its canonical rule bucket. */
    private function ruleBucket(string $key): ?string
    {
        if ($key === 'oil_change') {
            return 'oil';
        }
        if (str_starts_with($key, 'reminder:')) {
            return 'reminder';
        }
        if ($key === 'battery') {
            return 'battery';
        }
        if ($key === 'downtime') {
            return 'downtime';
        }
        if ($key === 'inactivity') {
            return 'inactivity';
        }
        return null;
    }

    /** The distinct rule buckets a persisted ticket fired, read from its trigger_detail snapshot. */
    private function ticketRuleBuckets(Maintenance $t): array
    {
        $rules = data_get($t->trigger_detail, 'rules', []);
        if (! is_array($rules)) {
            return [];
        }

        return collect($rules)
            ->map(fn ($r) => $this->ruleBucket((string) ($r['key'] ?? '')))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function worstSeverity(array $conditions): string
    {
        $rank = 0;
        $label = 'routine';
        foreach ($conditions as $c) {
            $r = $this->severityRank($c['severity'] ?? 'routine');
            if ($r > $rank) {
                $rank = $r;
                $label = $c['severity'] ?? 'routine';
            }
        }
        return $label;
    }

    private function severityRank(string $severity): int
    {
        return ['critical' => 3, 'moderate' => 2, 'routine' => 1][$severity] ?? 1;
    }

    /** The next scheduled 07:30 run from now (today if still before 07:30, else tomorrow). */
    private function nextRun(): Carbon
    {
        $next = Carbon::now()->setTime(self::RUN_HOUR, self::RUN_MINUTE, 0);
        if ($next->isPast()) {
            $next->addDay();
        }
        return $next;
    }
}
