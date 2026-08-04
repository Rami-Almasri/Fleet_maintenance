<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Models\FindingKeyword;
use App\Models\Maintenance;
use App\Models\PartRequest;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleLogEvent;
use App\Services\LeftGarageInvoiceService;
use App\Services\VehicleLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Workflow Oversight — the read-only accountability & data-integrity layer over the Maintenance
 * Workflow. Four focused audit surfaces + a roll-up overview, all pure reads (insights.view):
 *
 *   1. mileageDiscrepancies() — every stage where the odometer entered didn't match what was expected
 *      (ran backwards, jumped, or a garage test-drive), with the before/after reading, the note the
 *      operator left and WHO entered it.
 *   2. stageAccountability()  — per ticket, the full stage-by-stage chain: who owned each stage and the
 *      mileage they recorded there.
 *   3. leftGarage()           — cars that have physically left the garage but whose invoice is still
 *      outstanding, so the team knows exactly which garages to chase for a bill.
 *   4. severityReview()       — tickets whose fault-severity grade looks UNDER-graded versus the signals
 *      (a critical-risk keyword / a breakdown / a red-graded car scored only Routine or Moderate).
 *   5. checkpointCompliance() — cars whose supervisor was reminded that the car is due back and never
 *      answered: who was notified, for how many days running, and the reason history behind that car.
 *   6. overview()             — the counts in one call for the section landing page.
 *
 * Nothing here mutates state — every fix is applied on the ticket itself (deep-linked from each row).
 */
class WorkflowOversightController extends Controller
{
    public function __construct(private LeftGarageInvoiceService $leftGarageQueue) {}

    /**
     * The odometer capture points across a ticket's life, in lifecycle order. Each maps the JSON
     * `odometer_flags` key (written by MaintenanceWorkflowService::recordOdometerFlag) to a human
     * stage label, the ticket column that timestamps it, and the *_by column that names the person
     * who captured the reading (the flag itself carries the value + note but not the actor).
     */
    private const STAGE_MAP = [
        // flag key      => [label,                         at column,                  actor column]
        'test_drive' => ['Inspection Test Drive',       'test_started_at',          'inspected_by'],
        'report'     => ['End of Test Drive (Decide)',  'inspected_at',             'inspected_by'],
        'dispatch'   => ['Dispatch to Garage',          'dispatched_at',            'dispatched_by'],
        'receive'    => ['Garage Arrival',              'repair_started_at',        'repair_started_by'],
        'transfer'   => ['Garage Transfer',             'last_state_change_at',     'delegated_by'],
        'return'     => ['Collected from Garage',       'picked_up_from_garage_at', 'picked_up_from_garage_by'],
        'reinspect'  => ['Re-Inspection Sign-off',      'wf_closed_at',             'wf_closed_by'],
    ];

    /**
     * The full workflow chain in lifecycle order, used to build the per-ticket investigation timeline
     * shown in the Mileage Investigation drawer. Each entry maps a stage to [label, at column, actor
     * column, odometer column|null]. Mirrors stageAccountability()'s spec — a stage with no owner AND
     * no timestamp AND no reading is treated as "not reached" and skipped when building the story.
     */
    private const TIMELINE_SPEC = [
        // key         => [label,                    at column,                  actor column,               odometer column]
        'requested'  => ['Inspection Requested',   'requested_at',             'requested_by',             null],
        'test_drive' => ['Inspection Test Drive',  'test_started_at',          'inspected_by',             'test_odometer'],
        'report'     => ['Diagnosis (Decide)',     'inspected_at',             'inspected_by',             'report_odometer'],
        'delegated'  => ['Driver Delegated',       'delegated_at',             'delegated_by',             null],
        'dispatched' => ['Dispatched to Garage',   'dispatched_at',            'dispatched_by',            'dispatch_odometer'],
        'received'   => ['Garage Arrival',         'repair_started_at',        'repair_started_by',        'receive_odometer'],
        'ready'      => ['Repair Complete',        'ready_at',                 'ready_by',                 null],
        'collected'  => ['Collected from Garage',  'picked_up_from_garage_at', 'picked_up_from_garage_by', 'return_odometer'],
        'park'       => ['Back in Fleet Park',     'park_arrived_at',          'park_arrived_by',          null],
        'closed'     => ['Re-Inspection / Close',  'wf_closed_at',             'wf_closed_by',             'reinspect_odometer'],
    ];

    /** How far a forward reading may drift from expected before it's worth surfacing (mirrors the UI note gate). */
    private const NOTE_THRESHOLD_KM = 10;

    // ── 1. Mileage discrepancies ────────────────────────────────────────────────────────────────
    /**
     * Every stage reading that didn't line up with the previous one — a meter that ran backwards
     * (the real data error), a big forward jump, or a garage test-drive. One row per flagged stage:
     * the transition it happened at, the initial → updated figures, the delta, the operator's note
     * and the person who entered it.
     */
    public function mileageDiscrepancies(Request $request)
    {
        try {
            // Load full ticket rows — we need every stage's *_at / *_by / *_odometer column to assemble the
            // per-ticket investigation timeline (below), not just the flagged-stage columns.
            $tickets = Maintenance::query()
                ->whereNotNull('odometer_flags')
                ->with(['vehicle:id,plate_no,make,model,vin', 'vendor:id,name'])
                ->orderByDesc('updated_at')
                ->limit(600)
                ->get();

            // One id → name + role lookup covering every actor referenced across the FULL timeline (not just
            // the flagged stages), so each stage in the drawer names its owner and each row carries the
            // entering operator's role for accountability. Block-event actors are folded in below.
            $timelineByCols = array_map(fn ($s) => self::TIMELINE_SPEC[$s][2], array_keys(self::TIMELINE_SPEC));
            $blockActorIds  = \App\Models\OdometerBlockEvent::query()->orderByDesc('created_at')->limit(400)->pluck('actor_id');
            $actorIds = collect($tickets)
                ->flatMap(fn ($t) => array_map(fn ($c) => $t->{$c} ?? null, $timelineByCols))
                ->merge($blockActorIds)
                ->filter()->unique()->values();
            $users = $actorIds->isEmpty()
                ? collect()
                : User::whereIn('id', $actorIds)->with('roles:id,name')->get(['id', 'name']);
            $names = $users->pluck('name', 'id')->all();
            $roles = $users->mapWithKeys(fn ($u) => [$u->id => $this->prettyRole($u->roles->first()?->name)])->all();

            // Assemble the full workflow chain for each ticket once, keyed by ticket id — the drawer reads this
            // as the investigation story (every stage the car reached, who owned it, the reading, the time).
            $timelines = collect($tickets)->mapWithKeys(fn ($t) => [$t->id => $this->buildTimeline($t, $names, $roles)])->all();

            // The odometer photo captured at each stage lives as an 'odometer' InspectionRecord, tagged with
            // the phase string storeOdometerPhoto() used at that transition. Map each flag key to its phase so
            // we can attach the shot the operator took to prove the reading. (transfer & reinspect capture a
            // reading but no photo, so they have no entry.)
            $stagePhotoPhase = [
                'test_drive' => 'test',
                'report'     => 'report',
                'dispatch'   => 'pre',
                'receive'    => 'arrival',
                'return'     => 'garage_pickup',
            ];
            $vehicleIds = $tickets->pluck('vehicle_id')->filter()->unique()->values()->all();
            $photos = \App\Models\InspectionRecord::query()
                ->whereIn('vehicle_id', $vehicleIds)
                ->where('body_part', 'odometer')
                ->whereNotNull('s3_key')
                ->get(['id', 'vehicle_id', 'phase', 's3_disk', 's3_key', 'captured_at'])
                ->groupBy('vehicle_id');

            // Photos are per-vehicle, so a car with several tickets can hold many shots of the same phase.
            // Pick the one captured closest to the stage timestamp (the photo is saved seconds after the
            // transition), and only within a 6-hour window so a later ticket's shot never bleeds in.
            $matchPhoto = function ($vehicleId, $phase, $at) use ($photos) {
                if (! $phase || ! $at) {
                    return null;
                }
                $atTs = $at instanceof \DateTimeInterface ? $at->getTimestamp() : strtotime((string) $at);
                $best = ($photos[$vehicleId] ?? collect())
                    ->where('phase', $phase)
                    ->filter(fn ($p) => $p->captured_at && abs($p->captured_at->getTimestamp() - $atTs) <= 6 * 3600)
                    ->sortBy(fn ($p) => abs($p->captured_at->getTimestamp() - $atTs))
                    ->first();
                return $best?->viewUrl();
            };

            $rows = collect();
            $readingsTotal = 0; // every odometer reading captured across all stages (clean + flagged) — the KPI denominator
            foreach ($tickets as $t) {
                $flags = $t->odometer_flags ?? [];
                foreach (self::STAGE_MAP as $key => [$label, $atCol, $byCol]) {
                    $flag = $flags[$key] ?? null;
                    if (! is_array($flag)) {
                        continue;
                    }
                    $readingsTotal++;
                    $kind = $this->classifyFlag($flag);
                    if ($kind === null) {
                        continue; // a clean, in-tolerance reading — nothing to review
                    }
                    $delta = $flag['delta'] ?? null;
                    $rows->push([
                        'ticket_id'       => $t->id,
                        'vehicle_id'      => $t->vehicle_id,
                        'plate_no'        => $t->vehicle?->plate_no,
                        'car'             => trim(($t->vehicle?->make ?? '') . ' ' . ($t->vehicle?->model ?? '')) ?: null,
                        'garage'          => $t->vendor?->name,           // the garage that held the car at this stage
                        'workflow_status' => $t->workflow_status,
                        'stage_key'       => $key,
                        'stage_label'     => $label,
                        'previous'        => $flag['previous'] ?? null,   // the mileage we expected to build on
                        'reading'         => $flag['reading'] ?? null,    // what the operator actually entered
                        'delta'           => $delta,
                        'direction'       => $delta === null ? null : ($delta < 0 ? 'lower' : 'higher'),
                        'kind'            => $kind,                        // discrepancy | jump | test_drive | deviation | note
                        'status'          => $flag['status'] ?? null,      // raw continuity verdict (drives the reason label)
                        'tolerance_waived'=> (bool) ($flag['tolerance_waived'] ?? false),
                        'note'            => $flag['note'] ?? null,
                        // Whether the operator ticked "I've checked — this reading is correct" on the
                        // continuity nag: true/false when the step asked for it, null when it never did.
                        'confirmed'       => array_key_exists('confirmed', $flag) ? (bool) $flag['confirmed'] : null,
                        'entered_by'      => $names[$t->{$byCol}] ?? null,
                        'entered_by_role' => $roles[$t->{$byCol}] ?? null,  // the operator's role (accountability)
                        'at'              => optional($t->{$atCol})->toIso8601String(),
                        'outcome'         => 'recorded',                    // the reading was accepted onto the ticket
                        // The odometer photo the operator took at this stage (short-lived signed URL), or null
                        // when the stage captures no photo / none was saved.
                        'photo_url'       => $matchPhoto($t->vehicle_id, $stagePhotoPhase[$key] ?? null, $t->{$atCol}),
                        // The full workflow chain this reading sits inside — the drawer's investigation story.
                        'timeline'        => $timelines[$t->id] ?? [],
                    ]);
                }
            }

            // Blocked attempts — REJECTED readings the workflow threw away (out-of-range / backward strict
            // matches, or a garage arrival that wasn't higher than pickup). They live in their own audit
            // table (nothing survives on the ticket), and are the most important rows here: who tried to
            // force an unauthorised value, and what it was. Merged in so this page is the single odometer log.
            $blocks = \App\Models\OdometerBlockEvent::query()
                ->with(['vehicle:id,plate_no,make,model', 'actor:id,name'])
                ->orderByDesc('created_at')
                ->limit(400)
                ->get();

            foreach ($blocks as $b) {
                $rows->push([
                    'ticket_id'       => $b->maintenance_id,
                    'vehicle_id'      => $b->vehicle_id,
                    'plate_no'        => $b->vehicle?->plate_no,
                    'car'             => trim(($b->vehicle?->make ?? '') . ' ' . ($b->vehicle?->model ?? '')) ?: null,
                    'garage'          => null,                         // a rejected attempt carries no garage context
                    'workflow_status' => null,
                    'stage_key'       => $b->stage_key,
                    'stage_label'     => self::STAGE_MAP[$b->stage_key][0] ?? ucfirst(str_replace('_', ' ', $b->stage_key)),
                    'previous'        => $b->previous,                 // the value it had to match / exceed
                    'reading'         => $b->reading,                 // the REJECTED value the operator attempted
                    'delta'           => $b->delta,
                    'direction'       => $b->delta === null ? null : ($b->delta < 0 ? 'lower' : 'higher'),
                    'kind'            => 'blocked',
                    'status'          => $b->status,                   // raw reject reason (exact_required / must_increase …)
                    'tolerance_waived'=> false,
                    'note'            => $b->note,
                    'confirmed'       => null, // a rejected attempt never reached the acknowledgment gate
                    'entered_by'      => $b->actor?->name,
                    'entered_by_role' => $roles[$b->actor_id] ?? null,
                    'at'              => optional($b->created_at)->toIso8601String(),
                    'outcome'         => 'blocked',                    // the workflow REJECTED this reading
                    'photo_url'       => null,                         // a rejected attempt never saved a photo
                    // The ticket's workflow chain, when the blocked attempt was tied to a live ticket (a park
                    // spot-check rejection may have no ticket → empty story, the row's own detail carries it).
                    'timeline'        => $timelines[$b->maintenance_id] ?? [],
                ]);
            }

            // Blocked attempts first (the audit priority), then backward discrepancies, then most recent.
            $order = ['blocked' => 0, 'discrepancy' => 1, 'jump' => 2, 'test_drive' => 3, 'deviation' => 4, 'note' => 5, 'ack' => 6];
            $sorted = $rows->sort(function ($a, $b) use ($order) {
                return ($order[$a['kind']] ?? 9) <=> ($order[$b['kind']] ?? 9)
                    ?: strcmp((string) $b['at'], (string) $a['at']);
            })->values();

            // Investigation KPIs — the "situation at a glance" managers read before the table. All derived
            // from the same rows (pure read, no extra queries): the biggest forward jump, and the vehicles /
            // drivers / garages that recur most, plus the average absolute deviation across measurable rows.
            $withDelta = $sorted->filter(fn ($r) => $r['delta'] !== null);
            $largest   = $withDelta->sortByDesc(fn ($r) => abs($r['delta']))->first();
            // Median, not mean — a handful of catastrophic legacy odometer errors (readings entered as a full
            // dial value, millions of km off) would blow up an arithmetic average into a meaningless figure.
            // The median reflects the TYPICAL deviation; the extreme outliers are surfaced by 'largest_jump'
            // and the critical-severity rows instead.
            $absDeltas = $withDelta->map(fn ($r) => abs($r['delta']))->sort()->values();
            $avgDev    = $absDeltas->count() ? (int) round($absDeltas->median()) : 0;

            $topBy = function ($rowsIn, $key) {
                return collect($rowsIn)
                    ->filter(fn ($r) => ! empty($r[$key]))
                    ->groupBy($key)
                    ->map(fn ($g, $label) => ['label' => (string) $label, 'count' => $g->count()])
                    ->sortByDesc('count')
                    ->take(5)
                    ->values()
                    ->all();
            };

            // Rejected attempts are readings too — fold them into the denominator so accuracy reflects
            // every entry the workflow ever evaluated, clean or not.
            $readingsTotal += $blocks->count();

            return ResponseHelper::SuccessResponse([
                'rows'          => $sorted,
                'total'         => $sorted->count(),
                'blocked'       => $sorted->where('kind', 'blocked')->count(),
                'discrepancies' => $sorted->where('kind', 'discrepancy')->count(),
                // Every reading captured across all stages (clean + flagged + blocked) — the accuracy denominator.
                'readings_total'=> $readingsTotal,
                'tolerance_km'  => \App\Services\OdometerContinuityService::TOLERANCE_KM,
                'kpis'          => [
                    'flagged'        => $sorted->count(),
                    'blocked'        => $sorted->where('kind', 'blocked')->count(),
                    'avg_deviation'  => $avgDev,
                    'largest_jump'   => $largest ? [
                        'delta'    => $largest['delta'],
                        'plate_no' => $largest['plate_no'],
                        'car'      => $largest['car'],
                        'ticket_id'=> $largest['ticket_id'],
                    ] : null,
                    'top_vehicles'   => $topBy($sorted, 'plate_no'),
                    'top_drivers'    => $topBy($sorted->where('kind', 'blocked'), 'entered_by'),
                    'top_garages'    => $topBy($sorted, 'garage'),
                ],
            ], 'Mileage discrepancies retrieved', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Decide whether a stored flag is worth surfacing, and under which bucket. Returns null for a clean
     * reading (verified & within the note threshold), otherwise: 'discrepancy' (ran backwards — the real
     * error), 'jump' (a big pickup jump flagged CHECK), 'test_drive' (garage drove it), or 'note' (a
     * forward gap over the note threshold that the operator had to explain).
     */
    private function classifyFlag(array $flag): ?string
    {
        $status = $flag['status'] ?? null;
        $delta  = $flag['delta'] ?? null;

        if ($status === 'discrepancy' || $status === 'exact_required') {
            // 'exact_required' is normally blocked before storage, but if one is ever persisted it's a real
            // out-of-range reading — surface it alongside backward discrepancies.
            return 'discrepancy';
        }
        if ($status === 'check') {
            return 'jump';
        }
        if ($status === 'test_drive') {
            return 'test_drive';
        }
        // An "authorized deviation" (+1..tolerance km at a strict-match park spot-check) — always carries a
        // note; surface every one so a supervisor can audit the small, sanctioned overrides.
        if ($status === 'authorized_deviation') {
            return 'deviation';
        }
        // A verified forward reading is still worth showing if it crossed the note threshold and the
        // operator left an explanation (a deliberate site↔garage road trip waives the nag, so skip those).
        if ($delta !== null && abs($delta) > self::NOTE_THRESHOLD_KM && empty($flag['tolerance_waived']) && ! empty($flag['note'])) {
            return 'note';
        }
        // Otherwise-clean reading, but the operator actively ticked (or explicitly left unticked) the
        // "I've checked — this reading is correct" box — surface it so a supervisor can see the
        // acknowledgment happened, not just infer it from a note.
        if (array_key_exists('confirmed', $flag)) {
            return 'ack';
        }
        return null;
    }

    // ── 2. Stage accountability ─────────────────────────────────────────────────────────────────
    /**
     * Per ticket, the whole workflow chain: for every stage the car passed through, who owned it and the
     * mileage recorded there. One card per ticket; each carries an ordered list of the stages it actually
     * reached (a stage with no owner AND no reading AND no timestamp is skipped as "not reached yet").
     */
    public function stageAccountability(Request $request)
    {
        try {
            $tickets = Maintenance::workflowTickets()
                ->with('vehicle:id,plate_no,make,model')
                ->orderByDesc('id')
                ->limit(200)
                ->get();

            $byCols = ['requested_by', 'inspected_by', 'dispatched_by', 'repair_started_by', 'ready_by', 'picked_up_from_garage_by', 'park_arrived_by', 'wf_closed_by', 'delegated_by'];
            $names  = $this->userNames($tickets, $byCols);

            // stage key => [label, actor col, at col, odometer col|null]
            $spec = [
                'requested'  => ['Inspection Requested', 'requested_by',             'requested_at',             null],
                'test_drive' => ['Inspection Test Drive','inspected_by',             'test_started_at',          'test_odometer'],
                'report'     => ['Diagnosis (Decide)',   'inspected_by',             'inspected_at',             'report_odometer'],
                'delegated'  => ['Driver Delegated',     'delegated_by',             'delegated_at',             null],
                'dispatched' => ['Dispatched to Garage', 'dispatched_by',            'dispatched_at',            'dispatch_odometer'],
                'received'   => ['Garage Arrival',       'repair_started_by',        'repair_started_at',        'receive_odometer'],
                'ready'      => ['Repair Complete',      'ready_by',                 'ready_at',                 null],
                'collected'  => ['Collected from Garage','picked_up_from_garage_by', 'picked_up_from_garage_at', 'return_odometer'],
                'park'       => ['Back in Fleet Park',   'park_arrived_by',          'park_arrived_at',          null],
                'closed'     => ['Re-Inspection / Close','wf_closed_by',             'wf_closed_at',             'reinspect_odometer'],
            ];

            $out = $tickets->map(function ($t) use ($spec, $names) {
                $stages = [];
                foreach ($spec as $key => [$label, $byCol, $atCol, $odoCol]) {
                    $actorId = $t->{$byCol} ?? null;
                    $at      = $t->{$atCol} ?? null;
                    $odo     = $odoCol ? $t->{$odoCol} : null;
                    if ($actorId === null && $at === null && $odo === null) {
                        continue; // stage not reached
                    }
                    $stages[] = [
                        'key'      => $key,
                        'label'    => $label,
                        'owner'    => $actorId ? ($names[$actorId] ?? 'User #' . $actorId) : null,
                        'odometer' => $odo !== null ? (int) $odo : null,
                        'at'       => optional($at)->toIso8601String(),
                    ];
                }

                $meta = Maintenance::FAULT_SEVERITY_META[$t->fault_severity] ?? null;

                return [
                    'ticket_id'        => $t->id,
                    'vehicle_id'       => $t->vehicle_id,
                    'plate_no'         => $t->vehicle?->plate_no,
                    'car'              => trim(($t->vehicle?->make ?? '') . ' ' . ($t->vehicle?->model ?? '')) ?: null,
                    'workflow_status'  => $t->workflow_status,
                    'fault_severity'   => $t->fault_severity,
                    'severity_label'   => $meta['label'] ?? null,
                    'severity_emoji'   => $meta['emoji'] ?? null,
                    'severity_tone'    => $meta['tone'] ?? null,
                    'issue'            => $t->trigger_reason ?? Str::limit((string) $t->maintenance_notes, 80) ?: null,
                    'stage_count'      => count($stages),
                    'stages'           => $stages,
                    'updated_at'       => optional($t->updated_at)->toIso8601String(),
                ];
            })->values();

            return ResponseHelper::SuccessResponse([
                'tickets' => $out,
                'total'   => $out->count(),
            ], 'Stage accountability retrieved', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    // ── 3. Left the garage → chase the invoice ──────────────────────────────────────────────────
    /**
     * Cars that have physically left the garage (a garage-out reading was captured) but whose invoice is
     * still outstanding — the actionable list for "which garages do I still need a bill from?". Each row
     * says which garage worked on it, when it left, how long ago, and whether an invoice was already
     * requested. The actual "request invoice" / link happens on the ticket (deep-linked).
     */
    public function leftGarage(Request $request)
    {
        try {
            // The rule itself lives in LeftGarageInvoiceService so this page and the Action Center's
            // Checkpoint lane (which alerts on the same condition) read one definition.
            $rows = $this->leftGarageQueue->rows();

            return ResponseHelper::SuccessResponse([
                'rows'          => $rows,
                'total'         => $rows->count(),
                'needs_request' => $rows->where('needs_request', true)->count(),
                'requested'     => $rows->where('invoice_requested', true)->count(),
            ], 'Left-garage invoice queue retrieved', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    // ── 4. Severity grade review ────────────────────────────────────────────────────────────────
    /**
     * Tickets whose fault-severity grade looks too LOW for the situation — the inspector scored it
     * Routine / Moderate when the signals point to Critical. Signals, strongest first:
     *   • a finding keyword the library grades critical-risk;
     *   • a breakdown-origin ticket (a not-driveable car);
     *   • the car currently graded RED (blocked from rent).
     * Each flagged row shows what they picked, what it should be, and why.
     */
    public function severityReview(Request $request)
    {
        try {
            // The admin-curated keyword library, lowercased for forgiving matching. We keep each keyword's
            // risk AND its findings category (engine / brakes / …) so a flag can carry a real explainability
            // block — what the risk is, its impact, and the recommended action (config/severity_impact.php).
            $library = FindingKeyword::active()->get(['keyword', 'risk', 'category_key', 'category_label'])
                ->mapWithKeys(fn ($k) => [Str::lower(trim($k->keyword)) => [
                    'risk'           => $k->risk,
                    'category_key'   => $k->category_key,
                    'category_label' => $k->category_label,
                ]]);

            $tickets = Maintenance::workflowTickets()
                ->whereNotNull('fault_severity')
                ->with('vehicle:id,plate_no,make,model,condition_grade')
                ->orderByDesc('id')
                ->limit(500)
                ->get();

            $names = $this->userNames($tickets, ['inspected_by']);

            // Prior QC decisions — the audit trail IS the state store in V1 (no dedicated table). The latest
            // severity-review event per ticket tells us whether a recommendation was already Upgraded (applied)
            // or Kept (reviewed & dismissed as a false alarm), plus who / when / why.
            $decisions = $this->latestSeverityDecisions($tickets->pluck('id'));

            $rows = collect();
            $upgradedCount = 0;
            foreach ($tickets as $t) {
                $graded     = $t->fault_severity;
                $gradedRank = $this->severityRank($graded);

                $reasons     = [];
                $expectedRank = $gradedRank;
                $expected     = $graded;

                // (a) critical-risk keyword among the findings.
                [$kwRisk, $kwHit, $kwCatKey, $kwCatLabel] = $this->topKeywordRisk($t, $library);
                if ($kwRisk !== null) {
                    $r = $this->severityRank($kwRisk);
                    if ($r > $expectedRank) {
                        $expectedRank = $r;
                        $expected     = $kwRisk;
                    }
                    if ($this->severityRank($kwRisk) > $gradedRank) {
                        $reasons[] = ['type' => 'keyword', 'label' => 'Keyword "' . $kwHit . '" is graded ' . ucfirst($kwRisk), 'risk' => $kwRisk, 'keyword' => $kwHit, 'category_key' => $kwCatKey, 'category_label' => $kwCatLabel];
                    }
                }

                // (b) breakdown origin — a not-driveable car is a critical situation by definition.
                if ($t->trigger_reason === Maintenance::TRIGGER_BREAKDOWN && $gradedRank < $this->severityRank('critical')) {
                    $expectedRank = max($expectedRank, $this->severityRank('critical'));
                    $expected     = 'critical';
                    $reasons[]    = ['type' => 'breakdown', 'label' => 'Reported as a breakdown (not driveable)', 'risk' => 'critical'];
                }

                // (c) the car is graded RED (blocked from rent) — a grounded car shouldn't sit at Routine.
                if ($t->vehicle?->condition_grade === 'red' && $gradedRank < $this->severityRank('critical')) {
                    $expectedRank = max($expectedRank, $this->severityRank('critical'));
                    $expected     = 'critical';
                    $reasons[]    = ['type' => 'condition', 'label' => 'Car is graded RED (blocked from rent)', 'risk' => 'critical'];
                }

                $decision = $decisions[$t->id] ?? null;

                // A prior UPGRADE means the recommendation was already applied — the ticket now grades at (or
                // above) the recommendation, so it no longer surfaces as a mismatch. Count it for the KPI and
                // move on; the audit history lives on the vehicle log.
                if ($decision && $decision['decision'] === 'upgraded') {
                    $upgradedCount++;
                }

                if (empty($reasons) || $expectedRank <= $gradedRank) {
                    continue; // graded appropriately (or higher) — not a mismatch
                }

                // The single strongest signal drives the headline explainability + confidence.
                $top     = $this->topReason($reasons);
                $gap     = $expectedRank - $gradedRank;
                $explain = $this->explainSignal($top, $expected);
                [$confidence, $confidenceBasis] = $this->signalConfidence($reasons, $top, $gap);

                // State: 'kept' if the last decision dismissed this recommendation, else 'pending'.
                $state = ($decision && $decision['decision'] === 'kept') ? 'kept' : 'pending';

                $gm = Maintenance::FAULT_SEVERITY_META[$graded] ?? [];
                $em = Maintenance::FAULT_SEVERITY_META[$expected] ?? [];
                $rows->push([
                    'ticket_id'        => $t->id,
                    'vehicle_id'       => $t->vehicle_id,
                    'plate_no'         => $t->vehicle?->plate_no,
                    'car'              => trim(($t->vehicle?->make ?? '') . ' ' . ($t->vehicle?->model ?? '')) ?: null,
                    'workflow_status'  => $t->workflow_status,
                    'graded'           => $graded,
                    'graded_label'     => $gm['label'] ?? ucfirst((string) $graded),
                    'graded_emoji'     => $gm['emoji'] ?? null,
                    'graded_tone'      => $gm['tone'] ?? null,
                    'expected'         => $expected,
                    'expected_label'   => $em['label'] ?? ucfirst((string) $expected),
                    'expected_emoji'   => $em['emoji'] ?? null,
                    'expected_tone'    => $em['tone'] ?? null,
                    'gap'              => $gap,
                    'transition'       => $graded . '_' . $expected,   // e.g. routine_critical — the filter key
                    'reasons'          => $reasons,
                    'explain'          => $explain,                    // detected / risk_category / impact / action
                    'confidence'       => $confidence,                 // deterministic, from the rule library
                    'confidence_basis' => $confidenceBasis,
                    'graded_by'        => $names[$t->inspected_by] ?? null,
                    'at'               => optional($t->inspected_at)->toIso8601String(),
                    // Human decision (from the audit trail), null while still pending.
                    'decision'         => $state,
                    'decided_by'       => $decision['decided_by'] ?? null,
                    'decided_at'       => $decision['decided_at'] ?? null,
                    'decision_note'    => $decision['note'] ?? null,
                ]);
            }

            // Pending first (highest gap on top), then reviewed/kept rows for the audit view.
            $sorted = $rows->sortBy([
                fn ($a, $b) => ($a['decision'] === 'pending' ? 0 : 1) <=> ($b['decision'] === 'pending' ? 0 : 1),
                fn ($a, $b) => $b['gap'] <=> $a['gap'],
            ])->values();

            $pending = $sorted->where('decision', 'pending');
            $kept    = $sorted->where('decision', 'kept');

            return ResponseHelper::SuccessResponse([
                'rows'         => $sorted,
                // KPI dashboard — the state of the QC queue.
                'total'        => $pending->count() + $kept->count() + $upgradedCount, // every issue detected, any state
                'pending'      => $pending->count(),
                'upgraded'     => $upgradedCount,          // recommendations applied
                'kept'         => $kept->count(),          // reviewed & dismissed (false alarms)
                'critical'     => $pending->where('expected', 'critical')->count(),
                // Transition breakdown over the still-pending queue (what kind of under-grading is open).
                'transitions'  => [
                    'routine_critical'  => $pending->where('transition', 'routine_critical')->count(),
                    'moderate_critical' => $pending->where('transition', 'moderate_critical')->count(),
                    'moderate_high'     => $pending->where('transition', 'moderate_high')->count(),
                ],
                // Every ticket the inspector actually graded — the denominator for a grading-accuracy read.
                'graded_total' => $tickets->count(),
            ], 'Severity grade review retrieved', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Apply a supervisor's Quality-Control decision on an under-graded ticket. Two actions, both written
     * to the vehicle log (the V1 audit trail + state store — no dedicated table yet):
     *   • upgrade — raise the ticket's fault_severity (and the board's `severity` headline) to the
     *     recommendation (or an explicit override), recording previous → new. The car re-grades so the
     *     card falls off the pending queue on the next load.
     *   • keep    — the recommendation is a false alarm; record the review + reason, leave the grade as-is.
     *
     * Write-gated (maintenance.manage) at the route. The read page stays insights.view.
     */
    public function decide(Request $request, Maintenance $ticket, VehicleLogService $log)
    {
        try {
            $data = $request->validate([
                'action'   => ['required', Rule::in(['upgrade', 'keep'])],
                // Optional explicit target on upgrade (a supervisor may pick a tier other than the
                // recommendation); defaults to the recommended severity computed below.
                'severity' => ['nullable', Rule::in(Maintenance::FAULT_SEVERITIES)],
                'note'     => ['nullable', 'string', 'max:2000'],
            ]);

            $actor    = $request->user();
            $previous = $ticket->fault_severity;

            if ($data['action'] === 'keep') {
                $log->record($ticket, VehicleLogEvent::EVENT_SEVERITY_REVIEW_KEPT, $actor, [
                    'description' => 'Severity review — kept ' . ($previous ?: 'current grade')
                                     . ' (recommendation dismissed as a false alarm)'
                                     . ($actor ? ' by ' . $actor->name : '')
                                     . (($data['note'] ?? null) ? ': ' . $data['note'] : ''),
                    'meta'        => [
                        'decision'         => 'kept',
                        'previous_severity' => $previous,
                        'note'             => $data['note'] ?? null,
                    ],
                ]);

                return ResponseHelper::SuccessResponse(['ticket_id' => $ticket->id, 'decision' => 'kept'], 'Severity review recorded', 200);
            }

            // upgrade — target the recommendation unless an explicit tier is supplied. Only ever raises,
            // never lowers: a QC upgrade that would drop the grade is a no-op guard.
            $target = $data['severity'] ?? Maintenance::FAULT_SEVERITY_CRITICAL;
            if ($this->severityRank($target) <= $this->severityRank($previous)) {
                return ResponseHelper::FailureResponse(null, 'The chosen grade is not higher than the current one — nothing to upgrade.', 422);
            }

            $ticket->fault_severity = $target;
            $ticket->severity       = $target; // the board reads `severity` as the headline urgency, keep in lock-step
            $ticket->save();

            $pm = Maintenance::FAULT_SEVERITY_META[$previous] ?? [];
            $tm = Maintenance::FAULT_SEVERITY_META[$target] ?? [];
            $log->record($ticket, VehicleLogEvent::EVENT_SEVERITY_UPGRADED, $actor, [
                'description' => 'Severity upgraded ' . ($pm['label'] ?? ucfirst((string) $previous))
                                 . ' → ' . ($tm['label'] ?? ucfirst((string) $target))
                                 . ($actor ? ' by ' . $actor->name : '')
                                 . (($data['note'] ?? null) ? ': ' . $data['note'] : ''),
                'meta'        => [
                    'decision'          => 'upgraded',
                    'previous_severity' => $previous,
                    'new_severity'      => $target,
                    'note'              => $data['note'] ?? null,
                ],
            ]);

            return ResponseHelper::SuccessResponse([
                'ticket_id'         => $ticket->id,
                'decision'          => 'upgraded',
                'previous_severity' => $previous,
                'new_severity'      => $target,
            ], 'Severity upgraded', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    // ── 5. Mis-diagnosis review ─────────────────────────────────────────────────────────────────
    /**
     * The inspector got it wrong — every fault Abu Maroof (the inspector) diagnosed that a supervisor
     * later OVERRULED as a mis-diagnosis (the "mark fault incorrect" override, In-Workshop only). One row
     * per disputed fault: the symptom he called, who diagnosed it, who overruled it, the reason and when.
     * Plus a per-inspector tally so a recurring mis-caller stands out.
     */
    public function misdiagnoses(Request $request)
    {
        try {
            $tasks = \App\Models\MaintenanceTask::query()
                ->whereNotNull('marked_incorrect_at')
                // Inspector accountability only: the Incorrect action now covers faults from any source, but
                // this report is specifically "the inspector got it wrong", so limit it to inspector-raised.
                ->where('source', Maintenance::FINDING_INSPECTOR)
                ->with([
                    'vehicle:id,plate_no,make,model',
                    'maintenance:id,inspected_by,workflow_status',
                    'maintenance.inspector:id,name',
                    'identifiedBy:id,name',
                    'markedIncorrectBy:id,name',
                    'rootCause:id,root_cause',
                ])
                ->orderByDesc('marked_incorrect_at')
                ->limit(400)
                ->get();

            $rows = $tasks->map(function ($task) {
                $meta = Maintenance::FAULT_SEVERITY_META[$task->severity] ?? [];
                // Who called the fault: the fault's own identifier, else the ticket's inspector (Abu Maroof).
                $diagnosedBy = $task->identifiedBy?->name ?? $task->maintenance?->inspector?->name;

                return [
                    'task_id'         => $task->id,
                    'ticket_id'       => $task->maintenance_id,
                    'vehicle_id'      => $task->vehicle_id,
                    'plate_no'        => $task->vehicle?->plate_no,
                    'car'             => trim(($task->vehicle?->make ?? '') . ' ' . ($task->vehicle?->model ?? '')) ?: null,
                    'workflow_status' => $task->maintenance?->workflow_status,
                    'symptom'         => $task->symptom,               // the fault he called
                    'root_cause'      => $task->rootCause?->root_cause ?? $task->root_cause,
                    'severity'        => $task->severity,
                    'severity_label'  => $meta['label'] ?? null,
                    'severity_emoji'  => $meta['emoji'] ?? null,
                    'severity_tone'   => $meta['tone'] ?? null,
                    'diagnosed_by'    => $diagnosedBy,
                    'overruled_by'    => $task->markedIncorrectBy?->name,
                    'reason'          => $task->incorrect_reason,      // why it was a mis-diagnosis
                    'at'              => optional($task->marked_incorrect_at)->toIso8601String(),
                ];
            })->values();

            // Per-inspector tally — a recurring mis-caller should stand out at the top.
            $byInspector = $rows
                ->filter(fn ($r) => $r['diagnosed_by'])
                ->groupBy('diagnosed_by')
                ->map(fn ($g, $name) => ['name' => $name, 'count' => $g->count()])
                ->sortByDesc('count')
                ->values();

            return ResponseHelper::SuccessResponse([
                'rows'          => $rows,
                'total'         => $rows->count(),
                'by_inspector'  => $byInspector,
                // Every fault ever diagnosed (one maintenance_task = one called fault) — the denominator for a
                // diagnosis-accuracy KPI (diagnosed_total − overruled = the calls that stood).
                'diagnosed_total' => \App\Models\MaintenanceTask::count(),
            ], 'Mis-diagnosis review retrieved', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    // ── 6. Resolved-Transfer Oversight ────────────────────────────────────────────────────────────
    /**
     * Cars transferred to a DIFFERENT garage while EVERY fault on the ticket was already fixed — an
     * unusual move (nothing left to repair) gated behind a mandatory justification note at transfer
     * time (see MaintenanceWorkflowController::transferGarage). One row per flagged transfer: the car,
     * from → to garage, the odometer captured, WHO moved it, WHEN, and the note explaining WHY.
     */
    public function resolvedTransfers(Request $request)
    {
        try {
            $flags = \App\Models\ResolvedTransferFlag::query()
                ->with(['vehicle:id,plate_no,make,model', 'flaggedBy:id,name'])
                ->latest()
                ->limit(500)
                ->get();

            $rows = $flags->map(fn ($f) => [
                'id'          => $f->id,
                'ticket_id'   => $f->maintenance_id,
                'plate_no'    => $f->vehicle?->plate_no,
                'car'         => trim(($f->vehicle?->make ?? '') . ' ' . ($f->vehicle?->model ?? '')) ?: null,
                'from_garage' => $f->from_garage,
                'to_garage'   => $f->to_garage,
                'odometer'    => $f->odometer,
                'note'        => $f->note,
                'flagged_by'  => $f->flaggedBy?->name,
                'at'          => optional($f->created_at)->toIso8601String(),
            ])->values();

            return ResponseHelper::SuccessResponse([
                'rows'  => $rows,
                'total' => $rows->count(),
            ], 'Resolved-transfer oversight retrieved', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Checkpoint Compliance — did the daily chase actually get answered? Every car whose supervisor was
     * notified ("this one is due back, confirm the date or give a new one") and never replied. Each row
     * carries who was notified, on which days, how long the silence has run, and every reason that car's
     * date has moved for before, so an admin sees both the miss and the pattern behind it.
     *
     * Read-only; the fix is filing the checkpoint itself, deep-linked from each row.
     * See [[maintenance-checkpoint-feature]].
     */
    public function checkpointCompliance(Request $request, \App\Services\MaintenanceCheckpointService $checkpoints)
    {
        try {
            $report = $checkpoints->complianceReport();

            return ResponseHelper::SuccessResponse([
                'rows'       => $report['rows'],
                'total'      => count($report['rows']),
                // Cars that need chasing but have nobody assigned — the reminder was withheld on purpose.
                'unassigned' => $report['unassigned'],
                'summary'    => $report['summary'],
                'alert_days' => $report['alert_days'],
            ], 'Checkpoint compliance retrieved', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    // ── Overview roll-up ────────────────────────────────────────────────────────────────────────
    /** The six counts in one call, for the section landing page. */
    public function overview(Request $request)
    {
        try {
            $mileage  = $this->mileageDiscrepancies($request)->getData(true)['data'] ?? [];
            $garage   = $this->leftGarage($request)->getData(true)['data'] ?? [];
            $severity = $this->severityReview($request)->getData(true)['data'] ?? [];
            $misdiag  = $this->misdiagnoses($request)->getData(true)['data'] ?? [];
            $resolved = $this->resolvedTransfers($request)->getData(true)['data'] ?? [];
            $chase    = $this->checkpointCompliance($request, app(\App\Services\MaintenanceCheckpointService::class))
                ->getData(true)['data'] ?? [];

            // Open tickets whose repair is waiting on a part. This used to read the recommendation queue's
            // own awaiting_parts state; that state is gone, because "are we waiting on a part?" now has
            // exactly one owner — the ticket's part requests, which actually know what the part is and
            // where it has got to. See [[inspection-required-parts-split]].
            $awaitingParts = Maintenance::query()
                ->whereIn('workflow_status', Maintenance::WF_TICKET_STATES)
                ->whereHas('partRequests', fn ($q) => $q->outstanding())
                ->whereHas('vehicle', fn ($q) => $q->whereIn('status', Vehicle::ACTIVE_STATUSES))
                ->count();

            return ResponseHelper::SuccessResponse([
                'mileage_flags'        => $mileage['total'] ?? 0,
                'mileage_discrepancies'=> $mileage['discrepancies'] ?? 0,
                'mileage_readings_total'=> $mileage['readings_total'] ?? 0,
                'left_garage'          => $garage['total'] ?? 0,
                'needs_invoice'        => $garage['needs_request'] ?? 0,
                // The landing card counts what still NEEDS review (pending), not resolved/kept items.
                'severity_mismatches'  => $severity['pending'] ?? $severity['total'] ?? 0,
                'severity_critical'    => $severity['critical'] ?? 0,
                'severity_graded_total'=> $severity['graded_total'] ?? 0,
                'misdiagnoses'         => $misdiag['total'] ?? 0,
                'diagnosed_total'      => $misdiag['diagnosed_total'] ?? 0,
                'resolved_transfers'   => $resolved['total'] ?? 0,
                // The landing card counts cars whose supervisor has been silent past the tolerated day,
                // not every open reminder — a reminder sent this morning isn't yet a finding.
                'checkpoint_silent'    => $chase['summary']['breached'] ?? 0,
                'checkpoint_open'      => $chase['summary']['open'] ?? 0,
                'awaiting_parts'       => $awaitingParts,
            ], 'Workflow oversight overview retrieved', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────────

    /**
     * The full workflow chain for one ticket, oldest→newest — the investigation story the drawer reads.
     * One entry per stage the car actually reached (a stage with no owner AND no timestamp AND no reading
     * is skipped as "not reached"). Each entry carries the stage label, who owned it (+ role), the reading
     * captured there and when. Pure presentation of columns already on the ticket — no state is derived.
     */
    private function buildTimeline($t, array $names, array $roles): array
    {
        $out = [];
        foreach (self::TIMELINE_SPEC as $key => [$label, $atCol, $byCol, $odoCol]) {
            $actorId = $t->{$byCol} ?? null;
            $at      = $t->{$atCol} ?? null;
            $odo     = $odoCol ? ($t->{$odoCol} ?? null) : null;
            if ($actorId === null && $at === null && $odo === null) {
                continue; // stage not reached
            }
            $out[] = [
                'key'      => $key,
                'label'    => $label,
                'owner'    => $actorId ? ($names[$actorId] ?? null) : null,
                'role'     => $actorId ? ($roles[$actorId] ?? null) : null,
                'odometer' => $odo !== null ? (int) $odo : null,
                'at'       => optional($at)->toIso8601String(),
            ];
        }
        return $out;
    }

    /** Humanise a Spatie role slug (e.g. "workshop-manager" → "Workshop Manager") for display. */
    private function prettyRole(?string $slug): ?string
    {
        if (! $slug) {
            return null;
        }
        return Str::title(str_replace(['-', '_'], ' ', $slug));
    }

    /** id => name lookup for every actor referenced across the given tickets' *_by columns (one query). */
    private function userNames($tickets, array $cols): array
    {
        $ids = collect($tickets)
            ->flatMap(fn ($t) => array_map(fn ($c) => $t->{$c} ?? null, $cols))
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return User::whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /** Common numeric rank so a keyword's risk and a ticket's fault_severity compare on one scale. */
    private function severityRank(?string $level): int
    {
        return [
            'critical' => 4,
            'high'     => 3,
            'moderate' => 2,
            'routine'  => 1,
        ][$level] ?? 0;
    }

    /**
     * The highest-risk finding keyword on a ticket, matched against the library. Scans the ticket's
     * findings text (inspector + garage) and the inspector's suggested findings; returns
     * [risk, hitText, categoryKey, categoryLabel] or [null, null, null, null]. Matching is forgiving:
     * exact lowercased hit, else a library keyword contained in the finding text (or vice-versa) so
     * "engine overheating" still catches the "overheating" keyword.
     */
    private function topKeywordRisk(Maintenance $t, $library): array
    {
        $texts = collect($t->findings ?? [])->pluck('text')
            ->merge(collect($t->suggested_findings ?? [])->map(fn ($f) => is_array($f) ? ($f['text'] ?? null) : $f))
            ->filter()
            ->map(fn ($s) => Str::lower(trim((string) $s)))
            ->unique();

        $bestRank = 0;
        $best     = [null, null, null, null];

        foreach ($texts as $text) {
            foreach ($library as $keyword => $meta) {
                if ($keyword === '') {
                    continue;
                }
                if ($text === $keyword || Str::contains($text, $keyword) || Str::contains($keyword, $text)) {
                    $r = $this->severityRank($meta['risk']);
                    if ($r > $bestRank) {
                        $bestRank = $r;
                        $best     = [$meta['risk'], $keyword, $meta['category_key'], $meta['category_label']];
                    }
                }
            }
        }

        return $best;
    }

    /**
     * The latest Severity-Review decision per ticket, read straight off the vehicle log (V1 has no
     * dedicated table — the append-only audit trail IS the state store). Returns
     * [ticket_id => ['decision' => upgraded|kept, 'decided_by', 'decided_at', 'note']].
     */
    private function latestSeverityDecisions($ticketIds): array
    {
        $ids = collect($ticketIds)->filter()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $events = VehicleLogEvent::query()
            ->whereIn('maintenance_id', $ids)
            ->whereIn('event_type', [VehicleLogEvent::EVENT_SEVERITY_UPGRADED, VehicleLogEvent::EVENT_SEVERITY_REVIEW_KEPT])
            ->with('actor:id,name')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get(['id', 'maintenance_id', 'event_type', 'meta', 'actor_id', 'occurred_at']);

        $out = [];
        foreach ($events as $e) {
            if (isset($out[$e->maintenance_id])) {
                continue; // ordered newest-first, so the first hit per ticket is the current state
            }
            $out[$e->maintenance_id] = [
                'decision'   => $e->event_type === VehicleLogEvent::EVENT_SEVERITY_UPGRADED ? 'upgraded' : 'kept',
                'decided_by' => $e->actor?->name,
                'decided_at' => optional($e->occurred_at)->toIso8601String(),
                'note'       => $e->meta['note'] ?? null,
            ];
        }

        return $out;
    }

    /** The single strongest reason driving a mismatch — highest risk rank, breakdown/condition break ties. */
    private function topReason(array $reasons): array
    {
        $priority = ['breakdown' => 3, 'condition' => 2, 'keyword' => 1];
        usort($reasons, function ($a, $b) use ($priority) {
            $ra = $this->severityRank($a['risk'] ?? null);
            $rb = $this->severityRank($b['risk'] ?? null);
            return $rb <=> $ra ?: (($priority[$b['type']] ?? 0) <=> ($priority[$a['type']] ?? 0));
        });
        return $reasons[0] ?? [];
    }

    /**
     * Build the explainability block for a mismatch — what was detected, the risk category it belongs to,
     * the impact of leaving it under-graded, and the recommended action. Deterministic, curated copy from
     * config/severity_impact.php, localised to the app locale (falls back to English).
     */
    private function explainSignal(array $top, string $expected): array
    {
        $impact = config('severity_impact');
        $cat    = $top['category_key'] ?? null;
        $node   = $impact['categories'][$cat] ?? $impact['_default'];

        // "Detected" — the concrete signal the reviewer is judging.
        $detected = match ($top['type'] ?? null) {
            'keyword'   => $top['keyword'] ?? null,
            'breakdown' => 'Reported as a breakdown',
            'condition' => 'Car graded RED',
            default     => null,
        };

        $action = $impact['actions'][$expected] ?? $impact['actions']['moderate'];

        return [
            'detected'      => $detected,
            'risk_category' => $this->localise($node['risk_category'] ?? $impact['_default']['risk_category']),
            'impact'        => $this->localise($node['impact'] ?? $impact['_default']['impact']),
            'action'        => $this->localise($action),
        ];
    }

    /** Pick the app-locale string from a {en, ar} copy node, defaulting to English. */
    private function localise(array $node): ?string
    {
        return $node[app()->getLocale()] ?? $node['en'] ?? null;
    }

    /**
     * Deterministic confidence that this ticket really is under-graded — how certain the RULE is, NOT a
     * learned score. Anchored on the strongest signal (a breakdown is near-certain; an explicitly critical
     * keyword is strong), lifted a little when independent signals agree and when the grading gap is wide.
     * Returns [0-99 score, human-readable basis].
     */
    private function signalConfidence(array $reasons, array $top, int $gap): array
    {
        $type = $top['type'] ?? null;
        $risk = $top['risk'] ?? null;

        $base = match ($type) {
            'breakdown' => 95,   // an undriveable car IS critical by definition
            'condition' => 90,   // the car is already grounded (graded RED)
            'keyword'   => $risk === 'critical' ? 85 : ($risk === 'high' ? 80 : 70),
            default     => 70,
        };

        // Corroboration — each additional independent signal type beyond the strongest adds certainty.
        $distinctTypes = collect($reasons)->pluck('type')->unique()->count();
        $score = $base + max(0, $distinctTypes - 1) * 4;

        // A two-tier jump (e.g. routine → critical) is a bigger, clearer miss than a one-tier nudge.
        if ($gap >= 2) {
            $score += 3;
        }

        $score = (int) min(99, max(50, $score));

        $basis = match ($type) {
            'breakdown' => 'Reported as a breakdown — an undriveable car is critical by definition.',
            'condition' => 'The car is already graded RED (blocked from rent).',
            'keyword'   => 'Keyword “' . ($top['keyword'] ?? '') . '” is a known ' . ($risk ?? 'moderate') . '-risk fault.',
            default     => 'Signals point to a higher grade than the one recorded.',
        };
        if ($distinctTypes > 1) {
            $basis .= ' ' . $distinctTypes . ' independent signals agree.';
        }

        return [$score, $basis];
    }
}
