<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Models\Maintenance;
use App\Models\Vehicle;
use App\Services\DiagnosticGateService;
use App\Services\MaintenanceWorkflowService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Proactive Diagnostic Monitor. The system's own trigger for a "Needs Test Drive": it walks the active
 * fleet, asks the DiagnosticGateService what each car is DUE for, and for any car with an outstanding
 * condition it raises a system-attributed inspection request (workflow_status = pending_review) in the
 * Controllers' (Lin & Marwa) Inspection Review Queue AND notifies them — the agenda spells out exactly
 * what to check ("go check the oil and battery"). It only reaches Abu Maroof once a Controller approves
 * it (see MaintenanceWorkflowService::approveInspectionReview()). The human-driven equivalent is a
 * Driver's "Request inspection".
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
 *      "requested" state too, so we never double-request a car in the pipeline), and it has no OPEN
 *      OfficeManager maintenance contract (type U): a car can be in the workshop on an OM contract with
 *      no ticket here at all, and flagging that car for a test drive queues a decision about a car on a
 *      lift. The same fact retires requests raised BEFORE the contract appeared — this run withdraws
 *      those first (MaintenanceWorkflowService::withdrawRequestsForMaintenanceContracts()), and
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

    // The oil sanity ceiling itself lives on DiagnosticGateService (oilCeilingKm /
    // isOilOverdueImplausible) so this command and the Suggested Checks panel cannot disagree about
    // whether a reading is real. It used to be a private const here — the second consumer is why it moved.

    public function handle(MaintenanceWorkflowService $workflow, DiagnosticGateService $gate): int
    {
        $dry     = (bool) $this->option('dry-run');
        $limit   = max(1, (int) $this->option('limit'));
        $onlyId  = $this->option('vehicle') !== null ? (int) $this->option('vehicle') : null;

        // Every scan cycle is logged to storage/logs/laravel.log, not just echoed to the console — this
        // command runs headless off the Windows Task/schedule:run tick with no attached terminal, so
        // console-only output (the tables/lines below) would otherwise be silently lost. This is the
        // canonical, continuously-running source of system-generated inspection requests; the log is
        // what makes "did it actually run, and what did it find" answerable without a live terminal.
        Log::info('Proactive Diagnostic Monitor — scan started', [
            'report'   => 'inspections_generate_tasks_scan',
            'dry_run'  => $dry,
            'limit'    => $limit,
            'vehicle'  => $onlyId,
        ]);

        // First, clear out requests that reality has already answered: a car that went into the workshop
        // under an OfficeManager maintenance contract while its request sat in the review queue. Done
        // BEFORE the scan so the counts below describe a queue that is actually current.
        if (! $dry) {
            $withdrawn = $workflow->withdrawRequestsForMaintenanceContracts();
            // TRANSITIONAL: while workshop trips are still recorded on the sheet instead of as OM
            // contracts, the garage log answers a pending request the same way a contract does.
            $withdrawn += $workflow->withdrawRequestsForWorkshopLog();
            // …and the rule those two are snapshots of: a system request whose car has since been to a
            // workshop and COME BACK. Its count restarted on the day it returned, so what the request
            // asked for is no longer due — whether or not the visit is still open anywhere.
            $cleared = $workflow->withdrawRequestsWhoseConditionCleared();
            if ($withdrawn > 0 || $cleared > 0) {
                if ($withdrawn > 0) {
                    $this->line("<comment>Withdrew {$withdrawn} pending request(s) — those cars are already in maintenance (OM contract or garage log).</comment>");
                }
                if ($cleared > 0) {
                    $this->line("<comment>Withdrew {$cleared} system request(s) — those cars came back from maintenance, so the check clock restarted.</comment>");
                }
                Log::info('Proactive Diagnostic Monitor — withdrew requests reality already answered', [
                    'report'    => 'inspections_generate_tasks_scan',
                    'withdrawn' => $withdrawn,
                    'cleared'   => $cleared,
                ]);
            }
        }

        // Cars already somewhere in the workflow pipeline (incl. the pre-ticket "requested" state) —
        // never raise a second request for them.
        $inPipeline = Maintenance::openWorkflow()
            ->whereNotNull('vehicle_id')
            ->pluck('vehicle_id')
            ->flip();

        // …and cars OfficeManager already has in the workshop under an open maintenance contract (type U).
        // The workflow pipeline above doesn't know about them: a car can be in the garage on an OM contract
        // with no ticket in this system at all, and flagging it for a routine test drive would put a card in
        // the Controllers' queue for a car that is on a lift. Same fact that withdraws an existing request,
        // applied one step earlier so the request is never raised in the first place.
        $inOmMaintenance = Contract::where('contract_type', 'U')
            ->currentlyOpen()
            ->whereNotNull('vehicle_id')
            ->pluck('vehicle_id')
            ->flip();

        // …and cars the GARAGE LOG has in the workshop (latest live sheet/hand-entered event still open).
        // TRANSITIONAL twin of the contract set above — while trips are recorded on the sheet rather than
        // as OM contracts, this is what keeps a car on a lift out of the Controllers' queue.
        $inWorkshopLog = collect($workflow->vehicleIdsInWorkshopLog())->flip();

        // Walk active fleet; partition into real REQUESTS (with their agenda) vs oil DATA ANOMALIES.
        $requests  = [];
        $anomalies = [];
        Vehicle::whereIn('status', Vehicle::ACTIVE_STATUSES)
            // A car being sold shouldn't be pulled in for a routine test drive. (Sold / suspended /
            // office-use / disposed statuses are already excluded by ACTIVE_STATUSES = Ready | Rented.)
            ->where(fn ($q) => $q->where('for_sale', false)->orWhereNull('for_sale'))
            ->when($onlyId, fn ($q) => $q->whereKey($onlyId))
            ->orderBy('code')
            ->chunkById(500, function ($vehicles) use (&$requests, &$anomalies, $inPipeline, $inOmMaintenance, $inWorkshopLog, $gate) {
                foreach ($vehicles as $v) {
                    if ($inPipeline->has($v->id)) {
                        continue; // already in the pipeline
                    }

                    if ($inOmMaintenance->has($v->id)) {
                        continue; // already in the workshop on an OM maintenance contract
                    }

                    if ($inWorkshopLog->has($v->id)) {
                        continue; // already in the workshop per the garage log (sheet/hand-entered event)
                    }

                    $conditions = $gate->conditionsDue($v);
                    if (empty($conditions)) {
                        continue; // nothing due
                    }

                    // Oil sanity ceiling — drop an implausible service-due (bad odometer) and log it as an
                    // anomaly, but keep every other condition on the car. `$svc` is also snapshotted onto the
                    // request below (current mileage / interval / overdue) for the Inspection Review Queue.
                    $svc = $v->serviceStatus();
                    if ($gate->isOilOverdueImplausible($svc)) {
                        $anomalies[] = ['vehicle' => $v, 'service' => $svc, 'ceiling' => $gate->oilCeilingKm($svc)];
                        $conditions  = array_values(array_filter($conditions, fn ($c) => ($c['key'] ?? null) !== 'oil_change'));
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
                        // Service snapshot (mileage / interval / overdue) captured NOW — persisted in the
                        // ticket's trigger_detail so the Inspection Review Queue can show the exact values.
                        'service'    => $svc,
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
            Log::info('Proactive Diagnostic Monitor — scan complete, nothing due', [
                'report'         => 'inspections_generate_tasks_scan',
                'dry_run'        => $dry,
                'requests_raised' => 0,
                'anomalies'      => count($anomalies),
            ]);
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
        $failed  = [];
        foreach ($requests as $r) {
            try {
                $workflow->systemRequestInspection($r['vehicle'], [
                    'note'               => $r['note'],
                    'suggested_findings' => $r['suggested_findings'],
                    'conditions'         => $r['conditions'],
                    'service'            => $r['service'],
                ]);
                $created++;
            } catch (\Throwable $e) {
                $this->error('  • ' . ($r['vehicle']->plate_no ?: $r['vehicle']->id) . ': ' . $e->getMessage());
                $failed[] = ['vehicle_id' => $r['vehicle']->id, 'plate' => $r['vehicle']->plate_no, 'error' => $e->getMessage()];
                Log::error('Proactive Diagnostic Monitor — failed to raise a system inspection request', [
                    'report'     => 'inspections_generate_tasks_scan',
                    'vehicle_id' => $r['vehicle']->id,
                    'plate'      => $r['vehicle']->plate_no,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        $this->reportAnomalies($anomalies);

        $this->newLine();
        $this->info("Raised {$created} \"Needs Test Drive\" request(s). Abu Maroof has been notified.");
        if (! empty($anomalies)) {
            $this->warn(count($anomalies) . ' car(s) had their oil condition skipped as data anomalies (fix odometer in Mileage Reconciliation).');
        }

        Log::info('Proactive Diagnostic Monitor — scan complete', [
            'report'          => 'inspections_generate_tasks_scan',
            'dry_run'         => $dry,
            'requests_raised' => $created,
            'requests_failed' => count($failed),
            'failures'        => $failed,
            'anomalies'       => count($anomalies),
            'capped'          => $capped,
        ]);

        return self::SUCCESS;
    }

    /**
     * Turn a car's due conditions into the Inspector's agenda note — the specific "please check X, Y"
     * line that rides on the request and its alert. A post-downtime condition spells out the WHY (how
     * many days since the car's last maintenance completion — NOT idle time, since the clock keeps
     * running even while the car is out with a customer) and the safety checklist to inspect, e.g.:
     *
     *   "Routine check overdue — 25 days since last maintenance completion — please check: Battery, Fluids, and Brakes."
     *
     * @param array<int,array<string,mixed>> $conditions
     */
    private function agenda(array $conditions): string
    {
        $downtime   = collect($conditions)->firstWhere('directive', DiagnosticGateService::DIRECTIVE_DOWNTIME);
        $inactivity = collect($conditions)->firstWhere('directive', DiagnosticGateService::DIRECTIVE_INACTIVITY);
        $routine    = collect($conditions)->reject(fn ($c) => in_array(
            $c['directive'] ?? null,
            [DiagnosticGateService::DIRECTIVE_DOWNTIME, DiagnosticGateService::DIRECTIVE_INACTIVITY],
            true
        ));

        $parts = [];

        if ($routine->isNotEmpty()) {
            $parts[] = 'Routine check due — go check: ' . $routine->map(fn ($c) => $c['label'])->implode(', ') . '.';
        }

        if ($downtime) {
            $days  = (int) ($downtime['days'] ?? 0);
            $items = $this->humanList($downtime['checklist'] ?? DiagnosticGateService::POST_DOWNTIME_CHECKLIST);
            $parts[] = 'Routine check overdue — ' . $days . ' day' . ($days === 1 ? '' : 's') . ' since last maintenance completion'
                . ' — please check: ' . $items . '.';
        }

        // Inactivity — a car untouched (never re-rented) since its last test. Its own detail already spells
        // out the "why" ("Vehicle inactive for N days since last test …"), so it rides through verbatim.
        if ($inactivity) {
            $parts[] = (string) ($inactivity['detail'] ?? 'Vehicle inactive since its last test — run a check-up.');
        }

        // ONE FACT PER LINE. Each part above is an independent obligation the Inspector has to answer
        // separately, and a car can carry all three at once. Glued into a paragraph they read as a wall
        // of text on the review card; on their own lines they read as the checklist they are. Every
        // surface that shows the note renders it as a dashed list (see NoteLines.js) — this only saves
        // it from having to guess where one fact ends and the next begins.
        return implode("\n", $parts);
    }

    /**
     * The ready-entry-point chip list for the Decide step — the "System flagged — tap to confirm" row.
     * ONLY the data-driven routine conditions feed it: an oil change actually over its km/date limit, a
     * battery actually past its service life, a tyre/other reminder actually overdue. Each contributes
     * its `finding_keyword`, deduped in encounter order, so the Inspector taps to confirm a real finding.
     *
     * The post-downtime safety check is deliberately EXCLUDED: its checklist (Battery / Fluids / Brakes)
     * is a fixed "go look at these" agenda, NOT a verdict that anything is due — pre-filling those as
     * tap-to-confirm chips would suggest replacements the car's oil/battery validity never called for.
     * That check still spells its items out in the agenda note (see agenda()); it just isn't a chip.
     *
     * @param array<int,array<string,mixed>> $conditions
     * @return array<int,string>
     */
    private function suggestedFindings(array $conditions): array
    {
        return collect($conditions)
            ->reject(fn ($c) => ($c['directive'] ?? null) === DiagnosticGateService::DIRECTIVE_DOWNTIME)
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
