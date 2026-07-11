<?php

namespace App\Console\Commands;

use App\Models\Maintenance;
use App\Models\Vehicle;
use App\Services\DiagnosticGateService;
use App\Services\MaintenanceWorkflowService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Proactive Diagnostic Monitor. The system's own trigger for a "Needs Test Drive": it walks the active
 * fleet, asks the DiagnosticGateService what each car is DUE for, and for any car with an outstanding
 * condition it raises a system-attributed inspection request in Abu Maroof's queue (workflow_status =
 * inspection_requested) AND notifies him — the agenda spells out exactly what to check ("go check the
 * oil and battery"). The human-driven equivalent is a Driver's "Request inspection".
 *
 * Conditions that raise a request (all from DiagnosticGateService::conditionsDue):
 *   - Oil / service overdue (km or date)     → "Oil Change"
 *   - Tyres overdue (rotation / change)      → "Tire Rotation" / "Tire Change"
 *   - Battery past its service life          → "Battery Status"
 *   - Idle too long (post-downtime)          → "Safety Check"
 *
 * A car is requested only when:
 *   1. it is active fleet (Ready / Rented — Vehicle::ACTIVE_STATUSES), and
 *   2. DiagnosticGateService reports ≥1 due condition, and
 *   3. it has NO open workflow ticket already (Maintenance::openWorkflow — covers the pre-ticket
 *      "requested" state too, so we never double-request a car in the pipeline), and
 *   4. it passes the OIL SANITY CEILING — a service-due distance over max(20,000 km, 3 × interval) is
 *      almost certainly a bad odometer reading, so that oil condition is dropped and logged as a Data
 *      Anomaly instead of pushing nonsense to the Inspector. (Other conditions on the same car still
 *      raise the request; a car whose ONLY condition was the implausible oil is skipped entirely.)
 *
 *   php artisan inspections:generate-tasks --dry-run   # preview, write nothing
 *   php artisan inspections:generate-tasks             # raise the requests + notify the Inspector
 */
class InspectionsGenerateTasks extends Command
{
    protected $signature = 'inspections:generate-tasks
        {--dry-run : Preview the vehicles that would be requested, without writing anything to the database}
        {--vehicle= : Restrict the scan to a single vehicle id (for targeted runs / testing)}
        {--limit=100 : Safety cap on how many requests to raise in one run}';

    protected $description = 'Proactively raise "Needs Test Drive" requests for cars due for a routine/check-up (oil, tyres, battery, long idle), skipping implausible odometer readings';

    /** Absolute floor for the oil sanity ceiling, in km — used when 3× the interval is smaller. */
    private const ANOMALY_FLOOR_KM = 20000;

    public function handle(MaintenanceWorkflowService $workflow, DiagnosticGateService $gate): int
    {
        $dry     = (bool) $this->option('dry-run');
        $limit   = max(1, (int) $this->option('limit'));
        $onlyId  = $this->option('vehicle') !== null ? (int) $this->option('vehicle') : null;

        // Cars already somewhere in the workflow pipeline (incl. the pre-ticket "requested" state) —
        // never raise a second request for them.
        $inPipeline = Maintenance::openWorkflow()
            ->whereNotNull('vehicle_id')
            ->pluck('vehicle_id')
            ->flip();

        // Walk active fleet; partition into real REQUESTS (with their agenda) vs oil DATA ANOMALIES.
        $requests  = [];
        $anomalies = [];
        Vehicle::whereIn('status', Vehicle::ACTIVE_STATUSES)
            // A car being sold shouldn't be pulled in for a routine test drive. (Sold / suspended /
            // office-use / disposed statuses are already excluded by ACTIVE_STATUSES = Ready | Rented.)
            ->where(fn ($q) => $q->where('for_sale', false)->orWhereNull('for_sale'))
            ->when($onlyId, fn ($q) => $q->whereKey($onlyId))
            ->orderBy('code')
            ->chunkById(500, function ($vehicles) use (&$requests, &$anomalies, $inPipeline, $gate) {
                foreach ($vehicles as $v) {
                    if ($inPipeline->has($v->id)) {
                        continue; // already in the pipeline
                    }

                    $conditions = $gate->conditionsDue($v);
                    if (empty($conditions)) {
                        continue; // nothing due
                    }

                    // Oil sanity ceiling — drop an implausible service-due (bad odometer) and log it as an
                    // anomaly, but keep every other condition on the car.
                    $svc = $v->serviceStatus();
                    if (($svc['status'] ?? null) === 'service_due') {
                        $interval = (int) ($svc['interval'] ?? 0);
                        $over     = (int) ($svc['overdue_km'] ?? 0);
                        $ceiling  = max(self::ANOMALY_FLOOR_KM, 3 * $interval);
                        if ($over > $ceiling) {
                            $anomalies[] = ['vehicle' => $v, 'service' => $svc, 'ceiling' => $ceiling];
                            $conditions  = array_values(array_filter($conditions, fn ($c) => ($c['key'] ?? null) !== 'oil_change'));
                        }
                    }

                    if (empty($conditions)) {
                        continue; // the car's only condition was the implausible oil — skip the request
                    }

                    $requests[] = [
                        'vehicle'    => $v,
                        'conditions' => $conditions,
                        'note'       => $this->agenda($conditions),
                        // Ready-entry-point chips for the Decide step — every condition's mapped Findings
                        // keyword(s), deduped, so the Inspector taps instead of hunting the picker.
                        'suggested_findings' => $this->suggestedFindings($conditions),
                    ];
                }
            });

        // Cap applies to request CREATION only (anomalies are reported, never created).
        $capped   = count($requests) > $limit;
        $requests = array_slice($requests, 0, $limit);

        $this->newLine();
        $this->line(($dry ? '<comment>[DRY RUN]</comment> ' : '') . 'Proactive Diagnostic Monitor — due routines & check-ups');

        // ── Oil data anomalies (skipped) ────────────────────────────────────────
        if (! empty($anomalies)) {
            $this->newLine();
            $this->line('<comment>⚠ Data anomalies — oil condition SKIPPED (implausible odometer):</comment>');
            $this->table(
                ['Plate', 'Vehicle', 'Current km', 'Interval', 'Over by', 'Ceiling', 'Action'],
                collect($anomalies)->map(fn ($a) => [
                    $a['vehicle']->plate_no ?: '—',
                    trim(($a['vehicle']->make ?? '') . ' ' . ($a['vehicle']->model ?? '')) ?: '—',
                    number_format($a['service']['current'] ?? 0),
                    number_format($a['service']['interval'] ?? 0),
                    number_format($a['service']['overdue_km'] ?? 0) . ' km',
                    '> ' . number_format($a['ceiling']) . ' km',
                    $dry ? 'would skip + report' : 'skipped + reported',
                ])->all()
            );
        }

        // ── Real requests ───────────────────────────────────────────────────────
        if (empty($requests)) {
            $this->newLine();
            $this->info('No car needs a test-drive request right now (all current, already in the pipeline, or filtered as anomalies).');
            if (! $dry && ! empty($anomalies)) {
                $this->reportAnomalies($anomalies);
            }
            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('Cars to flag "Needs Test Drive":');
        $this->table(
            ['Code', 'Plate', 'Vehicle', 'Go check', 'Action'],
            collect($requests)->map(fn ($r) => [
                $r['vehicle']->code ?: '—',
                $r['vehicle']->plate_no ?: '—',
                trim(($r['vehicle']->make ?? '') . ' ' . ($r['vehicle']->model ?? '')) ?: '—',
                collect($r['conditions'])->map(fn ($c) => $c['label'])->implode(', '),
                $dry ? 'would request' : 'requesting…',
            ])->all()
        );
        if ($capped) {
            $this->warn("Note: more than {$limit} cars qualified — capped at {$limit} this run (raise with --limit).");
        }

        if ($dry) {
            $this->newLine();
            $this->warn(count($requests) . ' request(s) WOULD be raised, ' . count($anomalies) . ' oil anomaly(ies) WOULD be skipped. Dry run — nothing written, no one notified.');
            $this->line('Run again without <info>--dry-run</info> to raise them.');
            return self::SUCCESS;
        }

        // Real run — raise each request (system-attributed) and notify the Inspector.
        $created = 0;
        foreach ($requests as $r) {
            try {
                $workflow->systemRequestInspection($r['vehicle'], [
                    'note'               => $r['note'],
                    'suggested_findings' => $r['suggested_findings'],
                ]);
                $created++;
            } catch (\Throwable $e) {
                $this->error('  • ' . ($r['vehicle']->plate_no ?: $r['vehicle']->id) . ': ' . $e->getMessage());
            }
        }

        $this->reportAnomalies($anomalies);

        $this->newLine();
        $this->info("Raised {$created} \"Needs Test Drive\" request(s). Abu Maroof has been notified.");
        if (! empty($anomalies)) {
            $this->warn(count($anomalies) . ' car(s) had their oil condition skipped as data anomalies (fix odometer in Mileage Reconciliation).');
        }
        return self::SUCCESS;
    }

    /**
     * Turn a car's due conditions into the Inspector's agenda note — the specific "go check X, Y" line
     * that rides on the request and its alert. A post-downtime condition spells out the WHY (how many
     * days the car has been idle) and the safety checklist to inspect, so the inspector sees the
     * seriousness at a glance without digging through logs, e.g.:
     *
     *   "Post-downtime safety check due (Vehicle idle for 25 days) — go check: Battery, Fluids, and Brakes."
     *
     * @param array<int,array<string,mixed>> $conditions
     */
    private function agenda(array $conditions): string
    {
        $downtime = collect($conditions)->firstWhere('directive', DiagnosticGateService::DIRECTIVE_DOWNTIME);
        $routine  = collect($conditions)->reject(fn ($c) => ($c['directive'] ?? null) === DiagnosticGateService::DIRECTIVE_DOWNTIME);

        $parts = [];

        if ($routine->isNotEmpty()) {
            $parts[] = 'Routine check due — go check: ' . $routine->map(fn ($c) => $c['label'])->implode(', ') . '.';
        }

        if ($downtime) {
            $days  = (int) ($downtime['days'] ?? 0);
            $items = $this->humanList($downtime['checklist'] ?? DiagnosticGateService::POST_DOWNTIME_CHECKLIST);
            $parts[] = 'Post-downtime safety check due (Vehicle idle for ' . $days . ' day' . ($days === 1 ? '' : 's') . ')'
                . ' — go check: ' . $items . '.';
        }

        return implode(' ', $parts);
    }

    /**
     * The ready-entry-point chip list for the Decide step — every condition's `finding_keyword` (single)
     * or `finding_keywords` (post-downtime's per-item list), deduped in encounter order. This is what
     * turns the agenda note ("go check: Battery Status") into an actual one-tap FindingsPicker chip
     * instead of free text the Inspector has to translate into the picker himself.
     *
     * @param array<int,array<string,mixed>> $conditions
     * @return array<int,string>
     */
    private function suggestedFindings(array $conditions): array
    {
        return collect($conditions)
            ->flatMap(fn ($c) => $c['finding_keywords'] ?? array_filter([$c['finding_keyword'] ?? null]))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** "A, B, and C" — a natural-language list for the agenda checklist. */
    private function humanList(array $items): string
    {
        $items = array_values(array_filter($items));
        if (count($items) <= 1) {
            return (string) ($items[0] ?? '');
        }
        if (count($items) === 2) {
            return $items[0] . ' and ' . $items[1];
        }
        $last = array_pop($items);

        return implode(', ', $items) . ', and ' . $last;
    }

    /** Record each skipped oil condition as a Data Anomaly report (structured app log) so nothing is lost. */
    private function reportAnomalies(array $anomalies): void
    {
        foreach ($anomalies as $a) {
            Log::warning('Inspection auto-request oil skipped — implausible odometer (data anomaly)', [
                'report'      => 'routine_inspection_data_anomaly',
                'vehicle_id'  => $a['vehicle']->id,
                'code'        => $a['vehicle']->code,
                'plate'       => $a['vehicle']->plate_no,
                'vehicle'     => trim(($a['vehicle']->make ?? '') . ' ' . ($a['vehicle']->model ?? '')),
                'current_km'  => $a['service']['current'] ?? null,
                'interval_km' => $a['service']['interval'] ?? null,
                'overdue_km'  => $a['service']['overdue_km'] ?? null,
                'ceiling_km'  => $a['ceiling'],
            ]);
        }
    }
}
