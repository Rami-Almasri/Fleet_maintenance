<?php

namespace App\Services;

use App\Models\Maintenance;
use App\Models\MaintenanceCheckpoint;
use App\Models\ServiceDueSnooze;
use App\Models\ServiceRecord;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Maintenance Operations Center — the read/aggregate layer that turns the plain Service-Due board into
 * a decision center. It answers exactly three operational questions, and nothing more (no workshop
 * analytics, no revenue dashboards, no team workload):
 *
 *   1. WHICH vehicles need attention?  → the actionable service-due list (overdue + due-soon), from the
 *      unchanged km engine (MaintenanceForecastService::board / Vehicle::serviceStatus).
 *   2. WHY are they prioritized?       → a transparent, weighted Maintenance Priority Score (0–100) with
 *      a plain-language priority_reasons[] for each vehicle. It blends km overdue, usage intensity,
 *      failure-risk (MaintenanceForesightService tiers), service history, operational importance and a
 *      data-confidence penalty. It is deterministic — no ML, no fabricated probabilities.
 *   3. WHAT should happen next?        → one Recommended Next Action per vehicle, decided by current
 *      state (already in the workshop / waiting for parts / immediate inspection / create ticket /
 *      schedule / verify odometer). First matching rule wins, so it is always explainable.
 *
 * Everything here COMPOSES engines that already exist; it never re-computes business logic. Snoozed
 * vehicles (service_due_snoozes) drop off the board until their snooze elapses.
 */
class MaintenanceOpsCenterService
{
    private const BOARD_CACHE_TTL = 300;

    /** Priority-score weights (sum = 100). See the class docblock for the model. */
    private const W_KM_OVERDUE  = 35;
    private const W_USAGE       = 20;
    private const W_RISK        = 20;
    private const W_HISTORY     = 10;
    private const W_IMPORTANCE  = 10;
    private const W_CONFIDENCE  = 5;

    /** km/day that maps to a full usage factor (a hard-driven fleet car). */
    private const USAGE_REF_KMPD = 60.0;
    /** At/over this daily distance an overdue car is a "high-usage risk". */
    private const HIGH_USAGE_KMPD = 50.0;

    /** Risk-level cut points on the 0–100 score. */
    private const SCORE_CRITICAL   = 75;
    private const SCORE_ATTENTION  = 50;
    private const SCORE_UPCOMING   = 25;

    /** Odometer sanity — an overdue distance past this ceiling (or an impossible reading) is untrusted. */
    private const SANITY_FLOOR_KM = 20000;
    private const SANITY_MULT     = 3;
    private const IMPOSSIBLE_ODO  = 2_000_000;

    public function __construct(
        private MaintenanceForecastService $forecast,
        private MaintenanceForesightService $foresight,
    ) {
    }

    /**
     * The full Operations-Center board — the enriched actionable list + KPI summary. Cached like the
     * underlying Service-Due board (the fleet scan is moderately heavy).
     *
     * @return array{vehicles: array<int,array<string,mixed>>, summary: array<string,mixed>, thresholds: array<string,mixed>}
     */
    public function board(): array
    {
        return Cache::remember('intelligence:maintenance_ops:v1', self::BOARD_CACHE_TTL, fn () => $this->build());
    }

    private function build(): array
    {
        $base = $this->forecast->board();
        $rows = $base['vehicles'];

        if (empty($rows)) {
            return $this->emptyBoard($base);
        }

        $ids = array_column($rows, 'vehicle_id');

        $snoozes  = ServiceDueSnooze::liveByVehicle();
        $tickets  = $this->activeTicketsByVehicle($ids);
        $lastSvc  = $this->lastServiceByVehicle($ids);
        $vehicles = Vehicle::whereIn('id', $ids)->get(['id', 'status', 'condition_grade'])->keyBy('id');
        $risk     = $this->foresightByVehicle();

        $enriched = [];
        foreach ($rows as $row) {
            $vid = $row['vehicle_id'];

            // A live snooze hides the row from the actionable board (counted separately, never lost).
            if ($snoozes->has($vid)) {
                continue;
            }

            $veh      = $vehicles->get($vid);
            $ticket   = $tickets->get($vid);
            $lastAt   = $lastSvc[$vid] ?? null;
            $rk       = $risk[$vid] ?? null;
            $quality  = $this->dataQuality($row);

            $score    = $this->scoreVehicle($row, $veh, $lastAt, $rk, $quality);
            $level    = $this->riskLevel($score['score'], $rk, $quality);
            $active   = $this->activeMaintenance($ticket);
            $action   = $this->recommendedAction($row, $active, $level, $quality);

            $enriched[] = $row + [
                'priority_score'     => $score['score'],
                'priority_reasons'   => $score['reasons'],
                'risk_level'         => $level,
                'foresight_tier'     => $rk['tier'] ?? null,
                'last_service_at'    => $lastAt,
                'active_maintenance' => $active,
                'data_quality'       => $quality,
                'recommended_action' => $action,
            ];
        }

        // Highest priority first — the manager's morning triage order.
        usort($enriched, fn ($a, $b) => $b['priority_score'] <=> $a['priority_score']);

        return [
            'vehicles'   => $enriched,
            'summary'    => $this->summary($enriched, $base['summary'] ?? [], $snoozes->count()),
            'thresholds' => ($base['thresholds'] ?? []) + [
                'high_usage_kmpd' => self::HIGH_USAGE_KMPD,
                'score_critical'  => self::SCORE_CRITICAL,
                'score_attention' => self::SCORE_ATTENTION,
                'score_upcoming'  => self::SCORE_UPCOMING,
            ],
        ];
    }

    // ── Priority score ─────────────────────────────────────────────────────────────────────────────

    /**
     * The weighted Maintenance Priority Score (0–100) + the plain-language reasons behind it.
     * Every factor is normalised to 0..1, multiplied by its weight, and summed. A data-quality problem
     * neutralises the km-overdue factor (so a bad odometer can't fake urgency) AND forfeits the
     * confidence points.
     *
     * @return array{score:int, reasons: array<int,string>}
     */
    private function scoreVehicle(array $row, ?Vehicle $veh, ?string $lastAt, ?array $rk, array $quality): array
    {
        $reasons = [];
        $trusted = ! $quality['suspect'];

        // 1) KM overdue (vs the interval) — the core signal, zeroed when the reading is untrusted.
        $kmFactor = 0.0;
        $interval = $row['interval'] ?: null;
        if ($trusted && ($row['service_status'] ?? null) === 'overdue' && $interval) {
            $kmFactor = min(1.0, ($row['overdue_km'] ?? 0) / $interval);
            $reasons[] = number_format($row['overdue_km'] ?? 0) . ' km overdue';
        } elseif (($row['service_status'] ?? null) === 'due_soon') {
            $kmFactor = 0.25;
            if ($row['remaining_km'] !== null) {
                $reasons[] = number_format($row['remaining_km']) . ' km until service';
            }
        }

        // 2) Usage intensity — a hard-driven car burns through the interval faster.
        $usageFactor = 0.0;
        $rate = $row['usage_rate'] ?? null;
        if ($rate !== null && $rate > 0) {
            $usageFactor = min(1.0, $rate / self::USAGE_REF_KMPD);
            if ($rate >= self::HIGH_USAGE_KMPD) {
                $reasons[] = number_format($rate, 0) . ' km/day usage';
            }
        }

        // 3) Failure-risk prediction — from the Foresight engine's tier for this car.
        $riskFactor = 0.0;
        if ($rk) {
            $riskFactor = ['act_now' => 1.0, 'plan_soon' => 0.6, 'watch' => 0.3][$rk['tier']] ?? 0.0;
            if (! empty($rk['reason'])) {
                $reasons[] = $rk['reason'];
            }
        }

        // 4) Service history — no record on file, or a long time since the last one, is worse.
        $historyFactor = 0.0;
        if ($lastAt === null) {
            $historyFactor = 1.0;
            $reasons[] = 'No recent maintenance record';
        } else {
            $months = Carbon::parse($lastAt)->diffInMonths(now());
            $historyFactor = min(1.0, $months / 12);
            if ($months >= 9) {
                $reasons[] = 'Last service ' . $months . ' months ago';
            }
        }

        // 5) Operational importance — a car on rent right now costs more to ground.
        $importanceFactor = ($veh && $veh->status === 'rented') ? 1.0 : 0.5;
        if ($veh && $veh->status === 'rented') {
            $reasons[] = 'Currently on rent';
        }

        // 6) Data confidence — full points when the reading is trusted, forfeited otherwise.
        $confidenceFactor = $trusted ? 1.0 : 0.0;
        if (! $trusted) {
            $reasons[] = 'Data unreliable — score reduced';
        }

        $score = ($kmFactor * self::W_KM_OVERDUE)
            + ($usageFactor * self::W_USAGE)
            + ($riskFactor * self::W_RISK)
            + ($historyFactor * self::W_HISTORY)
            + ($importanceFactor * self::W_IMPORTANCE)
            + ($confidenceFactor * self::W_CONFIDENCE);

        return [
            'score'   => (int) round(max(0, min(100, $score))),
            'reasons' => array_values(array_slice($reasons, 0, 4)),
        ];
    }

    /** 🔴 critical / 🟠 attention / 🟡 upcoming / 🟢 healthy — from the score + hard gates. */
    private function riskLevel(int $score, ?array $rk, array $quality): string
    {
        // A confirmed act-now failure risk is critical regardless of the km score.
        if (($rk['tier'] ?? null) === 'act_now' && ! $quality['suspect']) {
            return 'critical';
        }
        if ($score >= self::SCORE_CRITICAL)  return 'critical';
        if ($score >= self::SCORE_ATTENTION) return 'attention';
        if ($score >= self::SCORE_UPCOMING)  return 'upcoming';

        return 'healthy';
    }

    // ── Recommended Next Action ─────────────────────────────────────────────────────────────────────

    /**
     * One action per vehicle, first matching rule wins (top = highest precedence). This is what turns
     * the page from a monitor into a decision center.
     *
     * @return array{key:string, label:string, tone:string, informational:bool, ticket_id:?int}
     */
    private function recommendedAction(array $row, ?array $active, string $level, array $quality): array
    {
        $make = fn (string $key, string $label, string $tone, bool $info = false, ?int $ticket = null) => [
            'key' => $key, 'label' => $label, 'tone' => $tone, 'informational' => $info, 'ticket_id' => $ticket,
        ];

        // 1) Untrusted odometer — fix the data before anyone opens a ticket off a bad reading.
        if ($quality['suspect']) {
            return $make('verify_odometer', 'Verify Odometer', 'amber');
        }

        // 2/3) The car is already an active maintenance job — don't offer to open another.
        if ($active) {
            if (($active['workflow_status'] ?? null) === Maintenance::WF_AWAITING_PARTS) {
                return $make('waiting_parts', 'Waiting for Parts', 'violet', true, $active['ticket_id']);
            }

            return $make('in_workshop', 'Already in Workshop', 'blue', true, $active['ticket_id']);
        }

        // 4) Critical & not yet in the shop — get eyes on it now.
        if ($level === 'critical') {
            return $make('immediate_inspection', 'Immediate Inspection', 'red');
        }

        // 5) Overdue — open the maintenance ticket.
        if (($row['service_status'] ?? null) === 'overdue') {
            return $make('create_ticket', 'Create Maintenance Ticket', 'amber');
        }

        // 6) Approaching — book it before it's overdue.
        if (($row['service_status'] ?? null) === 'due_soon') {
            return $make('schedule_service', 'Schedule Service', 'yellow');
        }

        return $make('monitor', 'Monitor', 'slate', true);
    }

    // ── Active maintenance (in-shop context) ────────────────────────────────────────────────────────

    /**
     * The live in-shop state for the drawer + the "already in workshop / waiting for parts" action.
     * Null when the car has no open maintenance ticket.
     *
     * @return array<string,mixed>|null
     */
    private function activeMaintenance(?Maintenance $ticket): ?array
    {
        if (! $ticket) {
            return null;
        }

        $label  = $ticket->workflow_status;
        $detail = null;
        try {
            $pos    = $ticket->livePosition();
            $label  = $pos['label'] ?? $label;
            $detail = $pos['detail'] ?? null;
        } catch (\Throwable $e) {
            // Fall back to the raw status — a presentation glitch must never break the board.
        }

        return [
            'ticket_id'                => $ticket->id,
            'workflow_status'          => $ticket->workflow_status,
            'stage_label'              => $label,
            'stage_detail'             => $detail,
            'responsible'              => $ticket->responsible,
            'expected_completion_date' => optional($ticket->effectiveExpectedCompletion())->toDateString(),
            'last_checkpoint_at'       => optional($ticket->last_checkpoint_at)->toIso8601String(),
        ];
    }

    // ── Data quality ────────────────────────────────────────────────────────────────────────────────

    /**
     * Flag an obviously-wrong odometer (impossible reading, or an overdue distance far past any sane
     * ceiling). We surface it, never hide it — and the score neutralises it so bad data can't rank #1.
     *
     * @return array{suspect:bool, reason:?string}
     */
    private function dataQuality(array $row): array
    {
        $current  = $row['current'] ?? null;
        $interval = $row['interval'] ?? null;
        $overdue  = $row['overdue_km'] ?? null;

        if ($current !== null && $current >= self::IMPOSSIBLE_ODO) {
            return ['suspect' => true, 'reason' => 'Impossible odometer value (' . number_format($current) . ' km)'];
        }

        if ($overdue !== null && $interval) {
            $ceiling = max(self::SANITY_FLOOR_KM, self::SANITY_MULT * $interval);
            if ($overdue > $ceiling) {
                return ['suspect' => true, 'reason' => 'Overdue distance exceeds a plausible limit — check the odometer'];
            }
        }

        return ['suspect' => false, 'reason' => null];
    }

    // ── Bulk lookups ────────────────────────────────────────────────────────────────────────────────

    /**
     * The single open maintenance ticket per vehicle (latest wins), with the relations livePosition
     * needs eager-loaded.
     *
     * @param  array<int,int>  $ids
     * @return \Illuminate\Support\Collection<int,Maintenance>
     */
    private function activeTicketsByVehicle(array $ids): \Illuminate\Support\Collection
    {
        return Maintenance::openWorkflow()
            ->whereIn('vehicle_id', $ids)
            ->with(['vendor', 'activeMove', 'transferToVendor'])
            ->orderByDesc('id')
            ->get()
            ->groupBy('vehicle_id')
            ->map(fn ($group) => $group->first());
    }

    /**
     * Last service DATE per vehicle — one grouped query (the board only needs the date; the drawer
     * pulls the full per-type history).
     *
     * @param  array<int,int>  $ids
     * @return array<int,?string>
     */
    private function lastServiceByVehicle(array $ids): array
    {
        return ServiceRecord::whereIn('vehicle_id', $ids)
            ->selectRaw('vehicle_id, MAX(performed_at) as last_at')
            ->groupBy('vehicle_id')
            ->pluck('last_at', 'vehicle_id')
            ->map(fn ($d) => $d ? Carbon::parse($d)->toDateString() : null)
            ->all();
    }

    /**
     * Failure-risk tier per vehicle from the Foresight engine — one cached call, mapped to a compact
     * {tier, reason}. Guarded: Foresight leans on several services, and a hiccup there must degrade to
     * "no risk signal", never take the board down.
     *
     * @return array<int,array{tier:string, reason:?string}>
     */
    private function foresightByVehicle(): array
    {
        try {
            $report = $this->foresight->report();
        } catch (\Throwable $e) {
            Log::warning('MaintenanceOpsCenter: foresight unavailable — ' . $e->getMessage());

            return [];
        }

        $map = [];
        foreach ($report['cars'] ?? [] as $car) {
            $signal = $car['signals'][0]['label'] ?? $car['primary_issue'] ?? null;
            $map[$car['vehicle_id']] = [
                'tier'   => $car['tier'] ?? 'watch',
                'reason' => $signal,
            ];
        }

        return $map;
    }

    // ── KPI summary ─────────────────────────────────────────────────────────────────────────────────

    /**
     * The four attention-driving KPI cards + the base status counts. Deliberately NO business/revenue
     * metrics — this page only surfaces what needs attention.
     *
     * @param  array<int,array<string,mixed>>  $rows
     */
    private function summary(array $rows, array $base, int $snoozedCount): array
    {
        $critical = array_filter($rows, fn ($r) => $r['risk_level'] === 'critical');
        $overdue  = array_filter($rows, fn ($r) => ($r['service_status'] ?? null) === 'overdue' && ! $r['data_quality']['suspect']);

        $highUsage = array_filter($rows, fn ($r) => ($r['service_status'] ?? null) === 'overdue'
            && ($r['usage_rate'] ?? 0) >= self::HIGH_USAGE_KMPD
            && ! $r['data_quality']['suspect']
            && $r['active_maintenance'] === null);

        $overdueKmValues = array_map(fn ($r) => (int) ($r['overdue_km'] ?? 0), $overdue);

        return $base + [
            'critical_count'           => count($critical),
            'critical_overdue_km_total' => (int) array_sum(array_map(fn ($r) => (int) ($r['overdue_km'] ?? 0), $critical)),
            'due_soon_count'           => count(array_filter($rows, fn ($r) => ($r['service_status'] ?? null) === 'due_soon')),
            'high_usage_risk_count'    => count($highUsage),
            'avg_overdue_km'           => $overdueKmValues ? (int) round(array_sum($overdueKmValues) / count($overdueKmValues)) : 0,
            'data_quality_flags'       => count(array_filter($rows, fn ($r) => $r['data_quality']['suspect'])),
            'snoozed_count'            => $snoozedCount,
            'listed'                   => count($rows),
        ];
    }

    private function emptyBoard(array $base): array
    {
        return [
            'vehicles'   => [],
            'summary'    => ($base['summary'] ?? []) + [
                'critical_count' => 0, 'critical_overdue_km_total' => 0, 'due_soon_count' => 0,
                'high_usage_risk_count' => 0, 'avg_overdue_km' => 0, 'data_quality_flags' => 0,
                'snoozed_count' => 0, 'listed' => 0,
            ],
            'thresholds' => $base['thresholds'] ?? [],
        ];
    }

    // ── Drawer: one-vehicle maintenance intelligence ────────────────────────────────────────────────

    /**
     * The Vehicle Maintenance Context drawer payload — health, active maintenance (+ checkpoint),
     * timeline anchors, recent service history and the same recommended action as the board row.
     *
     * @return array<string,mixed>
     */
    public function vehicleDetail(Vehicle $vehicle, MaintenanceCheckpointService $checkpoints): array
    {
        $forecast = $this->forecast->forecast($vehicle);
        $row      = ['vehicle_id' => $vehicle->id] + $forecast;
        $quality  = $this->dataQuality($row);

        $ticket  = Maintenance::openWorkflow()
            ->where('vehicle_id', $vehicle->id)
            ->with(['vendor', 'activeMove', 'transferToVendor', 'checkpoints'])
            ->orderByDesc('id')
            ->first();

        $active = $this->activeMaintenance($ticket);
        if ($ticket && $active) {
            try {
                $active['monitor'] = $checkpoints->monitorState($ticket);
                $latest = $ticket->checkpoints->first();
                $active['latest_checkpoint'] = $latest ? [
                    'status'                 => $latest->status,
                    'summary'                => $latest->summary,
                    'delay_reason'           => $latest->delay_reason,
                    'delay_reason_other'     => $latest->delay_reason_other,
                    'previous_expected_date' => optional($latest->previous_expected_date)->toDateString(),
                    'next_expected_date'     => optional($latest->next_expected_date)->toDateString(),
                    'submitted_by_name'      => $latest->submitted_by_name,
                    'created_at'             => optional($latest->created_at)->toIso8601String(),
                ] : null;
            } catch (\Throwable $e) {
                // Monitoring extras are best-effort — the core drawer must still render.
            }
        }

        $recent = ServiceRecord::forVehicle($vehicle->id)
            ->orderByDesc('performed_at')->orderByDesc('id')
            ->limit(6)
            ->get(['service_type', 'description', 'performed_at', 'odometer', 'result'])
            ->map(fn ($r) => [
                'service_type' => $r->service_type,
                'description'  => $r->description,
                'performed_at' => optional($r->performed_at)->toDateString(),
                'odometer'     => $r->odometer,
                'result'       => $r->result,
            ])->all();

        $lastAt = $recent[0]['performed_at'] ?? null;

        return [
            'vehicle' => [
                'id'    => $vehicle->id,
                'plate' => $vehicle->plate_no,
                'car'   => trim((string) ($vehicle->make . ' ' . $vehicle->model)) ?: null,
                'status' => $vehicle->status,
                'condition_grade' => $vehicle->condition_grade,
            ],
            'health' => [
                'current_km'      => $forecast['current'],
                'interval'        => $forecast['interval'],
                'baseline'        => $forecast['baseline'],
                'remaining_km'    => $forecast['remaining_km'],
                'overdue_km'      => $forecast['overdue_km'],
                'service_status'  => $forecast['status'],
                'usage_rate'      => $forecast['usage_rate'],
                'last_service_at' => $lastAt,
            ],
            'timeline' => [
                'last_service_at'   => $lastAt,
                'last_service_km'   => $recent[0]['odometer'] ?? $forecast['baseline'],
                'current_km'        => $forecast['current'],
                'projected_due'     => $forecast['projected_date'],
                'interval'          => $forecast['interval'],
            ],
            'active_maintenance' => $active,
            'recent_maintenance' => $recent,
            'data_quality'       => $quality,
            'recommended_action' => $this->recommendedAction(
                $row,
                $active,
                $this->riskLevel(
                    $this->scoreVehicle($row, $vehicle, $lastAt, null, $quality)['score'],
                    null,
                    $quality,
                ),
                $quality,
            ),
        ];
    }

    /** Drop the cached board (call after a snooze write so the change shows immediately). */
    public static function flush(): void
    {
        Cache::forget('intelligence:maintenance_ops:v1');
    }
}
