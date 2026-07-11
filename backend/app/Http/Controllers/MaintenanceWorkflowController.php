<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Resources\MaintenanceLineItemResource;
use App\Http\Resources\MaintenanceWorkflowResource;
use App\Models\FaultCause;
use App\Models\InspectionRecord;
use App\Models\Maintenance;
use App\Models\Vehicle;
use App\Services\MaintenanceWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Fleet Maintenance Workflow API — drives the server-side state machine
 * (MaintenanceWorkflowService) that replaces the WhatsApp relay.
 *
 * Reads (index / board / show) feed the Controller dashboard & the live pipeline; the transition
 * endpoints (store → report → dispatch → under-repair → ready → close, plus reopen) each advance
 * one ticket through exactly one legal step. Every move is role-gated by the route middleware and
 * re-checked by the service, so a stale tab can never push a ticket out of sequence.
 */
class MaintenanceWorkflowController extends Controller
{
    /**
     * Live workflow rows grouped into the dashboard columns. The two-stage model adds a leading
     * `diagnostic` lane (Stage 1, not yet a ticket); `pending` is the just-opened ticket awaiting
     * Logistics dispatch. Terminal rows (closed / diagnostic_cleared) are excluded by openWorkflow().
     */
    private const COLUMNS = [
        'triage'          => [Maintenance::WF_COMPLAINT_TRIAGE],     // customer complaint — Abu Maroof triages before any garage
        'requested'       => [Maintenance::WF_INSPECTION_REQUESTED],
        'diagnostic'      => [Maintenance::WF_INSPECTION_DIAGNOSTIC],
        'pending'         => [Maintenance::WF_INSPECTION_PENDING],   // in-shop ticket open — awaiting the Supervisor's dispatch decision
        // On-Site (mobile) lane — the car stays available; a mechanic services it where it's parked and
        // marks it serviced. Its own bucket so the two lanes never blur on the board.
        'on_site'         => [Maintenance::WF_ON_SITE_PENDING],
        'awaiting_pickup' => [Maintenance::WF_AWAITING_DISPATCH],    // garage + driver assigned — awaiting the driver's pickup (outbound leg)
        'in_transit'      => [Maintenance::WF_IN_TRANSIT],
        'under_repair'    => [Maintenance::WF_UNDER_REPAIR],
        // Supervisor Video-Review: the garage finished + sent its video — Waleed/Abdullah review it, then
        // approve it for pickup or send it back for a re-fix. Its own lane so the review work stands out.
        'repair_review'   => [Maintenance::WF_REPAIR_REVIEW],
        // Signed off, sitting at the garage — awaiting the driver's RETURN leg (collectFromGarage() then
        // arriveAtPark()). Distinct from awaiting_pickup, which is the OUTBOUND leg (car hasn't left base).
        'ready_for_pickup' => [Maintenance::WF_READY_FOR_PICKUP],
        // Transient — arriveAtPark() always auto-continues within the same request, so this lane is
        // normally empty; kept for completeness/audit visibility if a row is ever read mid-flight.
        'in_our_park'     => [Maintenance::WF_IN_OUR_PARK],
        // Final QA re-inspection — now entered from in_our_park, ONLY for major (critical/moderate)
        // repairs; a minor (routine) repair skips this lane and auto-closes on arrival.
        'qa_reinspection' => [Maintenance::WF_READY_REINSPECTION],
        // Quality-Control: back from the garage but a fault is still broken — stands out for the Supervisor.
        'reinspection_failed' => [Maintenance::WF_REINSPECTION_FAILED],
    ];

    private const EAGER = ['vendor', 'transferToVendor:id,name', 'vehicle:id,plate_no,make,model', 'inspector:id,name', 'requester:id,name', 'assignedDriver:id,name', 'delegatedBy:id,name', 'watchers:id,name', 'linkedContract:id,contract_no', 'lineItems',
        // Multi-garage routing: the ticket's faults, each with its garage-stint timeline + current garage.
        // lastFailedVendor drives the "Unresolved at Garage X" blame badge on a re-inspection failure.
        'tasks.assignments.vendor:id,name', 'tasks.currentVendor:id,name', 'tasks.lastFailedVendor:id,name', 'tasks.media', 'tasks.markedIncorrectBy:id,name',
        // Execution layer: the ticket's currently-open MOVE (transit) — drives the unified live position.
        'activeMove',
        // Garage Invoice Portal: the one submission awaiting audit — drives the "Awaiting Audit" flag.
        'pendingGarageInvoice',
        // One Ticket → Many Invoices: each garage bill with its garage, covered faults + line breakdown.
        'invoices.vendor:id,name', 'invoices.tasks:id,maintenance_invoice_id,symptom,status', 'invoices.lineItems'];

    /** The standard eager set for a fully-hydrated ticket — reused by the invoice controller's reloads. */
    public static function eagerWith(): array
    {
        return self::EAGER;
    }

    public function __construct(
        private MaintenanceWorkflowService $workflow,
        private \App\Services\MaintenanceTaskService $tasks,
        private \App\Services\MaintenanceForecastService $forecast,
    ) {
    }

    // ── Reads ────────────────────────────────────────────────────────────────

    /**
     * The central "Findings" library — the category-grouped issue keywords the test-drive report and
     * the garage-findings step offer as quick-pick tags. Served straight from config/maintenance_findings
     * so the menu can grow without a deploy of the frontend, and every client reads one source.
     * Also returns the maintenance-type classification options so the frontend is always in sync.
     */
    public function findingsCatalog(Request $request)
    {
        // Context-Aware Classification — a technician (no maintenance.manage) may only ever SET Routine
        // or Breakdown; the administrative classifications (Insurance / Non-Insurance Incident,
        // Modification, Upgrade) are manager-only and are never even offered to him. Filtering here (the
        // single source the pickers read) is what keeps Abu Maroof from ever seeing them, at initiation
        // or at the Decide step. The submitReport validation below re-enforces the same set server-side.
        $allowedTypes = $request->user()?->can('maintenance.manage')
            ? array_keys(Maintenance::MAINTENANCE_TYPES)
            : Maintenance::TYPES_TECHNICIAN;

        $types = collect(Maintenance::MAINTENANCE_TYPES)
            ->only($allowedTypes)
            ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
            ->values()
            ->all();

        // Symptom → Root-Cause knowledge base, grouped by normalised symptom so the client can look a
        // cause-list up the instant a symptom chip is tapped — no per-symptom round trip. Only APPROVED
        // causes ship to the picker; pending (user-submitted) ones stay hidden until an admin clears them.
        $faultCauses = FaultCause::approved()
            ->orderByDesc('usage_count') // most-likely (most-used) cause first
            ->orderBy('root_cause')
            ->get(['id', 'symptom_key', 'root_cause', 'description'])
            ->groupBy('symptom_key')
            ->map(fn ($group) => $group->map(fn ($c) => [
                'id'          => $c->id,
                'root_cause'  => $c->root_cause,
                'description' => $c->description,
            ])->values());

        // Per-keyword RISK grade (critical / moderate / routine) from the admin-curated library, keyed
        // by keyword string so a client can colour each quick-pick chip by seriousness. Additive: the
        // `categories` shape is unchanged (still string keywords), so existing pickers keep working.
        $keywordRisk = \App\Models\FindingKeyword::active()
            ->get(['keyword', 'keyword_ar', 'risk'])
            ->keyBy('keyword')
            ->map(fn ($k) => \App\Models\FindingKeyword::riskMeta($k->risk) + ['risk' => $k->risk, 'ar' => $k->keyword_ar]);

        return ResponseHelper::SuccessResponse(
            [
                'categories'        => array_values(config('maintenance_findings.categories', [])),
                // Each category carries an `on_site` flag; these tokens (battery/oil) additionally force
                // an On-Site suggestion + surface in the On-Site checklist even from an In-Shop category.
                'on_site_keywords'  => array_values(config('maintenance_findings.on_site_keywords', [])),
                'maintenance_types' => $types,
                // Keyed by normalised symptom (FaultCause::normalizeKey) → [{ id, root_cause, description }]
                'fault_causes'      => $faultCauses,
                // Keyed by keyword string → { risk, label, tone, emoji, rank } (active library rows only)
                'keyword_risk'      => $keywordRisk,
            ],
            'Findings catalog retrieved successfully',
            200
        );
    }

    /** All workflow tickets, newest first. Filter with ?status= and ?vehicle_id=. */
    public function index(Request $request)
    {
        try {
            $request->validate([
                'status'     => ['nullable', Rule::in(Maintenance::WORKFLOW_STATUSES)],
                'vehicle_id' => ['nullable', 'integer'],
            ]);

            $tickets = Maintenance::workflowTickets()
                ->with(self::EAGER)->withCount('media')
                ->when($request->filled('status'), fn ($q) => $q->where('workflow_status', $request->string('status')))
                ->when($request->filled('vehicle_id'), fn ($q) => $q->where('vehicle_id', $request->integer('vehicle_id')))
                ->orderByDesc('id')
                ->limit(500)
                ->get();

            return ResponseHelper::SuccessResponse(
                MaintenanceWorkflowResource::collection($tickets),
                'Workflow tickets retrieved successfully',
                200
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** The live pipeline: open tickets bucketed into the four dashboard columns, with counts. */
    public function board()
    {
        try {
            // Active fleet only: cars that are Ready (OM status 2) or Rented (3). awaiting_invoice tickets
            // are operationally DONE (car back in service) — they live on the invoice tracker, not the
            // repair pipeline — so they're excluded here.
            $open = Maintenance::openWorkflow()
                ->where('workflow_status', '!=', Maintenance::WF_AWAITING_INVOICE)
                ->whereHas('vehicle', fn ($q) => $q->whereIn('status', Vehicle::ACTIVE_STATUSES))
                ->with(self::EAGER)->withCount('media')->orderBy('id')->get();

            $columns = [];
            $counts  = [];
            foreach (self::COLUMNS as $key => $states) {
                $bucket = $open->whereIn('workflow_status', $states)->values();
                $columns[$key] = MaintenanceWorkflowResource::collection($bucket);
                $counts[$key]  = $bucket->count();
            }
            $counts['open_total'] = $open->count();

            return ResponseHelper::SuccessResponse(
                ['columns' => $columns, 'counts' => $counts],
                'Workflow pipeline retrieved successfully',
                200
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * The signed-in user's ROLE-SCOPED queue — the focused dashboard each role opens, holding only
     * the work that is theirs to act on:
     *
     *   Inspector  (maintenance.initiate): pending_inspections (requested + diagnostic),
     *                                      on_site (mobile jobs to Mark as Serviced), and
     *                                      final_reinspections (cars back, awaiting sign-off).
     *   Dispatcher (maintenance.delegate): awaiting_dispatch_decision (open tickets awaiting a garage
     *                                      + driver assignment — the Supervisor's call) and
     *                                      final_reinspections (cars back, sign-off shared w/ Inspector).
     *   Driver     (maintenance.logistics): active_dispatches (assigned, ready to pick up + in transit),
     *                                      waiting_followup (at the garage, log follow-ups) and
     *                                      back_from_garage (READ-ONLY tracking of cars that are back
     *                                      and waiting on the inspector's sign-off — drivers see them
     *                                      to know the car is in, but cannot close/reopen).
     *
     * A user who holds several (a manager/admin) gets every section. Tickets are serialized exactly as
     * the board serializes them, so the frontend reuses the same card.
     */
    public function myQueue(Request $request)
    {
        try {
            $user         = $request->user();
            $isInspector  = $user->can('maintenance.initiate');
            $isDispatcher = $user->can('maintenance.delegate');
            $isDriver     = $user->can('maintenance.logistics');

            // Active fleet only: cars that are Ready (OM status 2) or Rented (3) — keeps each role's
            // queue aligned with the board.
            $open    = Maintenance::openWorkflow()
                ->whereHas('vehicle', fn ($q) => $q->whereIn('status', Vehicle::ACTIVE_STATUSES))
                ->with(self::EAGER)->withCount('media')->orderBy('id')->get();
            $section = fn (array $states) => $open->whereIn('workflow_status', $states)->values();

            $sections = [];
            $counts   = [];
            $add = function (string $key, array $states) use (&$sections, &$counts, $section) {
                $rows = $section($states);
                $sections[$key] = MaintenanceWorkflowResource::collection($rows);
                $counts[$key]   = $rows->count();
            };

            if ($isInspector) {
                // Customer complaints awaiting Abu Maroof's triage (talk / resolve on-site / send in).
                $add('complaint_triage', [Maintenance::WF_COMPLAINT_TRIAGE]);
                $add('pending_inspections', [Maintenance::WF_INSPECTION_REQUESTED, Maintenance::WF_INSPECTION_DIAGNOSTIC]);
                // On-Site (mobile) lane — a minor job the inspector committed to do where the car is
                // parked (Repair Location = on_site). One step closes it (Mark as Serviced); no garage,
                // no dispatch, no re-inspection. Shared with the Supervisor (same maintenance.initiate |
                // maintenance.delegate authority as the serviced action).
                $add('on_site', [Maintenance::WF_ON_SITE_PENDING]);
                $add('final_reinspections', [Maintenance::WF_READY_REINSPECTION]);
            }
            if ($isDispatcher) {
                // The Supervisor's call: open tickets waiting on a garage + driver assignment.
                $add('awaiting_dispatch_decision', [Maintenance::WF_INSPECTION_PENDING]);
                // Supervisor Video-Review: garage finished — review the video, then approve it for
                // re-inspection or request a re-fix.
                $add('repair_review', [Maintenance::WF_REPAIR_REVIEW]);
                // Quality-Control: cars that came back from the garage but FAILED re-inspection — the
                // fault is still broken. Surfaced as its own section so it stands out; the Supervisor
                // re-dispatches (same garage or another) from here.
                $add('reinspection_failed', [Maintenance::WF_REINSPECTION_FAILED]);
                // A Supervisor may also re-check a car back from the garage and sign off / send it
                // back (shared with the Inspector — see the close/reopen routes).
                $add('final_reinspections', [Maintenance::WF_READY_REINSPECTION]);
            }
            if ($isDriver) {
                // Assigned and ready to pick up (awaiting_dispatch), plus any legacy in-transit rows.
                $add('active_dispatches', [Maintenance::WF_AWAITING_DISPATCH, Maintenance::WF_IN_TRANSIT]);
                $add('waiting_followup', [Maintenance::WF_UNDER_REPAIR]);
                // Signed off at the garage — the driver's RETURN leg: collect the car (mandatory photo,
                // no status change) then arrive at our park (mandatory photo, auto-branches by severity).
                $add('return_to_base', [Maintenance::WF_READY_FOR_PICKUP]);
                // Read-only: major-repair cars already back at our park, awaiting the Inspector's final
                // QA sign-off. The driver tracks them here but the close/reopen action isn't theirs
                // (routes/api.php).
                $add('back_from_garage', [Maintenance::WF_READY_REINSPECTION]);
            }

            return ResponseHelper::SuccessResponse(
                ['roles' => ['inspector' => $isInspector, 'dispatcher' => $isDispatcher, 'driver' => $isDriver], 'sections' => $sections, 'counts' => $counts],
                'My maintenance queue retrieved',
                200
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * Everything the vehicle profile's "Maintenance Workflow" panel needs in one call: the car's
     * current HEALTH status, its workflow tickets + diagnostics (newest first), and its condition
     * PHOTOS (the pre/post odometer shots and any other inspection images) — the audit trail.
     */
    public function vehicleTimeline(Vehicle $vehicle)
    {
        try {
            $tickets = Maintenance::workflowTickets()
                ->where('vehicle_id', $vehicle->id)
                ->with(self::EAGER)
                ->orderByDesc('id')
                ->limit(50)
                ->get();

            $photos = InspectionRecord::where('vehicle_id', $vehicle->id)
                ->whereNotNull('s3_key')
                ->orderByDesc('captured_at')
                ->orderByDesc('id')
                ->limit(60)
                ->get()
                ->map(fn (InspectionRecord $p) => [
                    'id'              => $p->id,
                    'phase'           => $p->phase,                 // test = at test drive, pre = at dispatch, post = at return
                    'body_part'       => $p->body_part,             // 'odometer' for the workflow shots
                    'checkpoint_type' => $p->checkpoint_type,
                    'captured_at'     => optional($p->captured_at)->toIso8601String(),
                    'url'             => $p->viewUrl(),
                ])
                ->values();

            return ResponseHelper::SuccessResponse([
                'health'              => $this->vehicleHealth($vehicle, $tickets),
                'tickets'             => MaintenanceWorkflowResource::collection($tickets),
                'photos'              => $photos,
                'last_odometer_photo' => $photos->firstWhere('body_part', 'odometer'),
            ], 'Vehicle maintenance workflow retrieved', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * The car's at-a-glance maintenance HEALTH: an open workflow ticket wins (its unified live position
     * says exactly where the car is — in transit / in workshop / awaiting sign-off), else the strict
     * service-due km rule, else healthy. A colour tone the UI maps straight to a chip.
     *
     * Single source of truth: the open-ticket case delegates to Maintenance::livePosition(), so the
     * vehicle profile, the board and the command view never disagree about where the car is.
     */
    private function vehicleHealth(Vehicle $vehicle, $tickets): array
    {
        $open = $tickets->first(fn ($t) => $t->workflow_status !== null && ! in_array($t->workflow_status, Maintenance::WF_TERMINAL, true));

        if ($open) {
            $p = $open->livePosition();
            return [
                'status'         => $p['phase'],
                'label'          => $p['label'],
                'tone'           => $p['tone'],
                'detail'         => $p['detail'],
                'garage'         => $p['garage'],
                'driver'         => $p['driver'],
                'moving'         => $p['moving'],
                'open_ticket_id' => $p['open_ticket_id'],
            ];
        }

        $svc = $vehicle->serviceStatus();
        if (($svc['status'] ?? null) === 'service_due') {
            return ['status' => 'service_due', 'label' => 'Service Due', 'tone' => 'amber',
                    'detail' => number_format($svc['overdue_km'] ?? 0) . ' km over the service interval', 'open_ticket_id' => null];
        }

        return ['status' => 'healthy', 'label' => 'Healthy', 'tone' => 'green', 'detail' => 'No open maintenance', 'open_ticket_id' => null];
    }

    /** One ticket with its full handoff trail. */
    public function show(Maintenance $ticket)
    {
        try {
            $ticket->load(self::EAGER)->loadCount('media');
            return ResponseHelper::SuccessResponse(
                MaintenanceWorkflowResource::make($ticket),
                'Workflow ticket retrieved successfully',
                200
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * The car's REAL current mileage (vehicles.odometer, the self-healed canonical reading) plus a
     * unified chronological log of EVERY mileage change on this vehicle and HOW each one happened —
     * manual corrections (who made them), contract pickup/return readings, and the maintenance workflow
     * captures (test drive / dispatch / return). Each entry carries the reading, when, the `how` source,
     * who (where an actor is known), a reference (contract no. / ticket #) and the delta vs the previous
     * reading. Ordered oldest→newest so it reads as a story of the odometer through the car's life;
     * `total` is the number of recorded changes.
     */
    public function mileage(Maintenance $ticket)
    {
        try {
            $vid     = $ticket->vehicle_id;
            $vehicle = $ticket->vehicle; // full row → odometer / baseline_odometer / baseline_synced_at
            $entries = collect();

            // 1) Manual corrections — the only source with a human actor (who changed the reading). Anchored
            //    to the DATE of the reading it fixes (the contract's out/in date), not the edit time, so it
            //    sits in the story right next to the value it corrected ("read X … then corrected to Y").
            \App\Models\MileageOverride::query()
                ->whereNotNull('corrected_value')
                ->whereHas('contract', fn ($q) => $q->where('vehicle_id', $vid))
                ->with('contract:id,contract_no,out_date,in_date')
                ->get()
                ->each(function ($o) use ($entries) {
                    $readingDate = $o->field === 'in_milage' ? $o->contract?->in_date : $o->contract?->out_date;
                    $entries->push([
                        'value' => (int) $o->corrected_value, 'at' => $readingDate ?: $o->updated_at,
                        'how' => 'manual', 'by' => $o->user_name, 'ref' => $o->contract?->contract_no,
                        'note' => $o->note, 'this_ticket' => false,
                    ]);
                });

            // 2) Contract handover readings — the backbone of the mileage chain (pickup + return).
            \App\Models\Contract::query()
                ->where('vehicle_id', $vid)
                ->get(['id', 'contract_no', 'out_date', 'out_milage', 'in_date', 'in_milage'])
                ->each(function ($c) use ($entries) {
                    if ($c->out_milage !== null) $entries->push(['value' => (int) $c->out_milage, 'at' => $c->out_date, 'how' => 'contract_out', 'by' => null, 'ref' => $c->contract_no, 'note' => null, 'this_ticket' => false]);
                    if ($c->in_milage !== null)  $entries->push(['value' => (int) $c->in_milage,  'at' => $c->in_date,  'how' => 'contract_in',  'by' => null, 'ref' => $c->contract_no, 'note' => null, 'this_ticket' => false]);
                });

            // 3) Maintenance workflow captures — test drive start, dispatch to garage, return. Entries from
            //    the ticket the user opened are flagged so the UI can highlight "this ticket" in the story.
            Maintenance::query()
                ->where('vehicle_id', $vid)
                ->get(['id', 'test_odometer', 'test_started_at', 'report_odometer', 'inspected_at', 'dispatch_odometer', 'dispatched_at', 'return_odometer', 'returned_at', 'picked_up_from_garage_at', 'reinspect_odometer', 'wf_closed_at', 'awaiting_invoice_since', 'actual_in_date', 'odometer_flags'])
                ->each(function ($m) use ($entries, $ticket) {
                    $ref   = '#' . $m->id;
                    $mine  = $m->id === $ticket->id;
                    // Odometer Continuity verdict per capture stage — carried onto the entry so the drawer
                    // can badge a "Discrepancy" (or garage test-drive) the Supervisor should eyeball.
                    $flags = $m->odometer_flags ?? [];
                    // The operator's >10 km-gap explanation is stored on the stage flag — surface it as the
                    // entry note so the Supervisor reads WHY the meter jumped, right under the reading.
                    if ($m->test_odometer !== null)     $entries->push(['value' => (int) $m->test_odometer,     'at' => $m->test_started_at, 'how' => 'test_drive', 'by' => null, 'ref' => $ref, 'note' => $flags['test_drive']['note'] ?? null, 'this_ticket' => $mine, 'flag' => $flags['test_drive'] ?? null]);
                    // End-of-test-drive reading captured at the Decide step — its own row, distinct from the start anchor.
                    if ($m->report_odometer !== null)   $entries->push(['value' => (int) $m->report_odometer,   'at' => $m->inspected_at,     'how' => 'report',     'by' => null, 'ref' => $ref, 'note' => $flags['report']['note'] ?? null, 'this_ticket' => $mine, 'flag' => $flags['report'] ?? null]);
                    if ($m->dispatch_odometer !== null) $entries->push(['value' => (int) $m->dispatch_odometer, 'at' => $m->dispatched_at,   'how' => 'dispatch',   'by' => null, 'ref' => $ref, 'note' => $flags['dispatch']['note'] ?? null, 'this_ticket' => $mine, 'flag' => $flags['dispatch'] ?? null]);
                    // Garage-OUT reading — now captured when the driver collects the car (picked_up_from_garage_at);
                    // falls back to returned_at for legacy tickets that took it at mark-ready.
                    if ($m->return_odometer !== null)   $entries->push(['value' => (int) $m->return_odometer,   'at' => $m->picked_up_from_garage_at ?? $m->returned_at, 'how' => 'return', 'by' => null, 'ref' => $ref, 'note' => $flags['return']['note'] ?? null, 'this_ticket' => $mine, 'flag' => $flags['return'] ?? null]);
                    // Final re-inspection sign-off — its own row, distinct from the garage-OUT 'return' reading.
                    // Timestamped at close (full close → wf_closed_at, deferred → awaiting_invoice_since, else the
                    // return date). Same value/note/flag shape as every other capture.
                    if ($m->reinspect_odometer !== null) $entries->push(['value' => (int) $m->reinspect_odometer, 'at' => $m->wf_closed_at ?? $m->awaiting_invoice_since ?? $m->actual_in_date, 'how' => 'reinspect', 'by' => null, 'ref' => $ref, 'note' => $flags['reinspect']['note'] ?? null, 'this_ticket' => $mine, 'flag' => $flags['reinspect'] ?? null]);
                });

            // Same-instant tiebreak: a sensible lifecycle order when several readings share a day (dateless
            // contract dates all land at midnight). Unknown sources sort in the middle.
            $rank = ['contract_out' => 1, 'test_drive' => 2, 'report' => 3, 'dispatch' => 4, 'return' => 5, 'reinspect' => 6, 'contract_in' => 7, 'manual' => 9];

            $sorted = $entries
                ->filter(fn ($e) => $e['value'] !== null && $e['value'] > 0) // drop 0 / null junk readings
                ->sort(function ($a, $b) use ($rank) {
                    $ta = $a['at'] ? $a['at']->timestamp : PHP_INT_MAX;
                    $tb = $b['at'] ? $b['at']->timestamp : PHP_INT_MAX;
                    return $ta <=> $tb
                        ?: (($rank[$a['how']] ?? 5) <=> ($rank[$b['how']] ?? 5))
                        ?: ($a['value'] <=> $b['value']);
                })
                ->values();

            // Collapse a reading that repeats unchanged on the SAME day (a clean handoff, e.g. return ==
            // the next rental's pickup) — it carries no new information and only clutters the story.
            $deduped = collect();
            foreach ($sorted as $e) {
                $last = $deduped->last();
                if ($last && $last['value'] === $e['value'] && $last['at'] && $e['at'] && $last['at']->isSameDay($e['at'])) {
                    continue;
                }
                $deduped->push($e);
            }

            // Delta vs the previous kept reading (how far the car moved between records).
            $prev = null;
            $withDelta = $deduped->map(function ($e) use (&$prev) {
                $delta = $prev !== null ? $e['value'] - $prev : null;
                $prev = $e['value'];
                return [
                    'value'       => $e['value'],
                    'at'          => optional($e['at'])->toIso8601String(),
                    'how'         => $e['how'],
                    'by'          => $e['by'],
                    'ref'         => $e['ref'],
                    'note'        => $e['note'],
                    'delta'       => $delta,
                    'this_ticket' => (bool) $e['this_ticket'],
                    'flag'        => $e['flag'] ?? null, // Odometer Continuity verdict (null for contract/override rows)
                ];
            })->values();

            // Guard against a car with a huge rental history: keep the most recent slice (deltas already
            // computed so the newest reading still shows the right jump). `total` reports the true count.
            $cap       = 50;
            $truncated = $withDelta->count() > $cap;
            $history   = $truncated ? $withDelta->slice(-$cap)->values() : $withDelta;

            $first    = $history->first();
            $last     = $history->last();
            $distance = ($first && $last) ? $last['value'] - $first['value'] : null;

            return ResponseHelper::SuccessResponse([
                'current'   => $vehicle?->odometer !== null ? (int) $vehicle->odometer : null,
                'baseline'  => $vehicle?->baseline_odometer,
                'synced_at' => optional($vehicle?->baseline_synced_at)->toIso8601String(),
                'history'   => $history,
                'total'     => $withDelta->count(),
                'shown'     => $history->count(),
                'truncated' => $truncated,
                'distance'  => $distance !== null && $distance >= 0 ? $distance : null,
                // Preventive forecast: is this car approaching / overdue for service, and when (projected
                // from its own usage rate)? Same engine the "service due soon" bell alert uses.
                'forecast'  => $vehicle ? $this->forecast->forecast($vehicle) : null,
            ], 'Vehicle mileage retrieved successfully', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * The DIAGNOSTIC CONTEXT for this ticket's car — the "why was it flagged" detail shown on the ticket
     * drawer: how long it has been idle, the last check (date + link to that visit), and the live oil /
     * battery / tyre status with the actual numbers and the chosen limits. Read-only; lazily fetched when
     * the drawer opens.
     */
    public function diagnosticContext(Maintenance $ticket, \App\Services\DiagnosticGateService $gate)
    {
        return $this->run(function () use ($ticket, $gate) {
            $vehicle = $ticket->loadMissing('vehicle')->vehicle;
            $context = $vehicle ? $gate->context($vehicle) : null;

            return ResponseHelper::SuccessResponse($context, 'Diagnostic context retrieved', 200);
        });
    }

    /**
     * The park-duration (idle) info for a vehicle — how long it has been sitting since its last movement.
     * Feeds the Scheduled-tab intake so the operator sees "this car has been parked N days" the moment they
     * pick it, BEFORE any ticket exists. Read-only. Static-segment route precedes /{ticket}.
     */
    public function vehicleIdle(Vehicle $vehicle, \App\Services\DiagnosticGateService $gate)
    {
        return $this->run(function () use ($vehicle, $gate) {
            return ResponseHelper::SuccessResponse($gate->idleInfo($vehicle), 'Vehicle idle info retrieved', 200);
        });
    }

    // ── Transitions ──────────────────────────────────────────────────────────

    /**
     * Stage 1 — Inspector starts a diagnostic test drive (vehicle + reason). NOT a ticket yet. The
     * odometer reading + its photo are mandatory: captured BEFORE the drive as the first link in the
     * mileage chain (test → dispatch → receive → return) and the car's new canonical reading. The
     * reading is stored on the ticket; the photo becomes a 'test'-phase odometer inspection record,
     * saved best-effort once the diagnostic commits (a storage hiccup never blocks the drive).
     */
    public function store(Request $request)
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'vehicle_id'         => ['required', 'integer', Rule::exists('vehicles', 'id')],
                'trigger_reason'     => ['required', Rule::in(Maintenance::TRIGGER_REASONS)],
                'customer_complaint' => ['nullable', 'string', 'max:2000'],
                'test_odometer'      => ['required', 'integer', 'min:1'],
                'odometer_photo'     => ['required', 'image', 'max:8192'], // ≤ 8 MB
                // Mandatory (client-enforced) explanation when the reading is >10 km off the previous one.
                'odometer_note'      => ['nullable', 'string', 'max:2000'],
                // Which intake tab produced this test (Routine oil/battery/tyres vs Scheduled park-time).
                'test_kind'          => ['nullable', Rule::in(Maintenance::TEST_KINDS)],
                // maintenance_type is intentionally absent: the diagnostic hasn't happened yet, so
                // there is nothing to classify. The Inspector sets it later in submitReport().
            ]);

            $ticket = $this->workflow->open($data, $request->user());

            $photoSaved = false;
            try {
                $photoSaved = (bool) $this->storeOdometerPhoto($ticket, $request->file('odometer_photo'), $request->user(), 'test');
            } catch (\Throwable $e) {
                report($e); // logged, never surfaced — the diagnostic already started
            }

            return ResponseHelper::SuccessResponse(
                ['ticket' => MaintenanceWorkflowResource::make($ticket), 'odometer_photo_saved' => $photoSaved],
                'Diagnostic test drive started',
                201
            );
        });
    }

    /**
     * Complaint Intake — the Operations controllers (Marwa & Leen) log a customer complaint against a
     * car. Unlike the inspector's diagnostic open, there is NO odometer/test-drive. The ticket is born in
     * the TRIAGE lane (complaint_triage) — Abu Maroof's queue — NOT the garage-dispatch queue: he decides
     * how to handle it (talk / resolve on-site / send in) before any garage is involved. Gated to
     * maintenance.manage (the Controllers' permission) on the route.
     */
    public function storeComplaint(Request $request)
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'vehicle_id'        => ['required', 'integer', Rule::exists('vehicles', 'id')],
                'fault_description' => ['required', 'string', 'max:2000'],
            ]);

            $ticket = $this->workflow->openComplaint($data, $request->user());

            return ResponseHelper::SuccessResponse(
                MaintenanceWorkflowResource::make($ticket),
                'Complaint logged — Abu Maroof notified to triage it',
                201
            );
        });
    }

    /**
     * Triage — "Spoke with the customer". Abu Maroof logs that a conversation took place; the complaint
     * stays in triage (a touchpoint, not a resolution). Optional note. Gated to maintenance.initiate.
     */
    public function triageCall(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
            $ticket = $this->workflow->logComplaintCall($ticket, $data['note'] ?? null, $request->user());
            return ResponseHelper::SuccessResponse(MaintenanceWorkflowResource::make($ticket), 'Conversation logged', 200);
        });
    }

    /**
     * Triage — "Resolved on site". The complaint was handled at the customer with no garage trip → closed
     * as resolved (terminal). Optional note recorded in the audit trail. Gated to maintenance.initiate.
     */
    public function triageResolve(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
            $ticket = $this->workflow->resolveComplaintOnSite($ticket, $data['note'] ?? null, $request->user());
            return ResponseHelper::SuccessResponse(MaintenanceWorkflowResource::make($ticket), 'Complaint resolved on-site', 200);
        });
    }

    /**
     * Triage — "Send the car in". Abu Maroof routes the complained car to the garage-dispatch queue OR to
     * his own diagnostic, optionally arranging a replacement swap for the customer. Gated to
     * maintenance.initiate.
     */
    public function triageRoute(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $data = $request->validate([
                'destination'            => ['required', Rule::in(['garage', 'diagnostic'])],
                'replacement_vehicle_id' => ['nullable', 'integer', Rule::exists('vehicles', 'id')],
                'note'                   => ['nullable', 'string', 'max:2000'],
            ]);
            $ticket = $this->workflow->routeComplaint($ticket, $data, $request->user());
            $sent = $data['destination'] === 'garage' ? 'garage dispatch' : 'diagnostic inspection';
            return ResponseHelper::SuccessResponse(MaintenanceWorkflowResource::make($ticket), "Complaint sent to {$sent}", 200);
        });
    }

    /**
     * Breakdown Intake — the EMERGENCY entry point. A technician (Abu Maroof) reports a car that is not
     * driveable: there is NO test drive / odometer, the ticket is born straight in the Supervisors'
     * dispatch queue (inspection_pending), the car is GROUNDED (condition_grade → red, operational_status
     * → maintenance), and it is classified Breakdown + graded critical
     * automatically. A brief description of the failure is mandatory. Gated to maintenance.initiate on
     * the route (the technician's own permission; managers hold it too).
     */
    public function storeBreakdown(Request $request)
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'vehicle_id'        => ['required', 'integer', Rule::exists('vehicles', 'id')],
                'fault_description' => ['required', 'string', 'max:2000'],
            ]);

            $ticket = $this->workflow->openBreakdown($data, $request->user());

            return ResponseHelper::SuccessResponse(
                MaintenanceWorkflowResource::make($ticket),
                'Breakdown reported — car grounded, management notified to dispatch',
                201
            );
        });
    }

    /**
     * Stage 0 — a Driver (Logistics) requests an inspection (vehicle + reason + notes). NOT a ticket
     * nor a diagnostic yet; it alerts the Inspector (Abu Maroof) that this car needs a test drive.
     */
    public function requestInspection(Request $request)
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'vehicle_id'         => ['required', 'integer', Rule::exists('vehicles', 'id')],
                'trigger_reason'     => ['required', Rule::in(Maintenance::TRIGGER_REASONS)],
                'customer_complaint' => ['nullable', 'string', 'max:2000'],
                // maintenance_type is intentionally absent: the Driver has no diagnostic authority.
                // The Inspector sets the classification when filing the report (submitReport).
            ]);

            $ticket = $this->workflow->requestInspection($data, $request->user());
            return ResponseHelper::SuccessResponse(MaintenanceWorkflowResource::make($ticket), 'Inspection requested — Abu Maroof notified', 201);
        });
    }

    /**
     * Reclassify the maintenance type on an open ticket. The true nature of a visit is sometimes
     * discovered mid-repair (e.g. "Routine" becomes "Insurance Incident" when the garage finds
     * accident damage). The change is logged in the vehicle timeline so managers can see when and
     * by whom the reclassification was made. Requires maintenance.manage permission (wired on the route).
     */
    public function updateType(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $data = $request->validate([
                'maintenance_type' => ['required', Rule::in(array_keys(Maintenance::MAINTENANCE_TYPES))],
            ]);

            $ticket = $this->workflow->updateMaintenanceType($ticket, $data['maintenance_type'], $request->user());
            return ResponseHelper::SuccessResponse(MaintenanceWorkflowResource::make($ticket), 'Maintenance type updated', 200);
        });
    }

    /**
     * Stage 0 → Stage 1 — the Inspector picks up a Driver's request and starts the test drive. The
     * odometer reading + photo are mandatory here too (same chain anchor as a direct open): the
     * reading is stored on the ticket, the photo becomes a 'test'-phase odometer record (best-effort).
     */
    public function startDiagnostic(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $data = $request->validate([
                'test_odometer'  => ['required', 'integer', 'min:1'],
                'odometer_photo' => ['required', 'image', 'max:8192'], // ≤ 8 MB
                'odometer_note'  => ['nullable', 'string', 'max:2000'], // explanation for a >10 km gap
            ]);

            $ticket = $this->workflow->startDiagnostic($ticket, $data, $request->user());

            $photoSaved = false;
            try {
                $photoSaved = (bool) $this->storeOdometerPhoto($ticket, $request->file('odometer_photo'), $request->user(), 'test');
            } catch (\Throwable $e) {
                report($e); // logged, never surfaced — the diagnostic already started
            }

            return ResponseHelper::SuccessResponse(
                ['ticket' => MaintenanceWorkflowResource::make($ticket), 'odometer_photo_saved' => $photoSaved],
                'Test drive started — file your report',
                200
            );
        });
    }

    /**
     * Stage 2 — Inspector files the test-drive report and decides. `requires_maintenance=true` opens
     * the maintenance ticket (→ inspection_pending, Logistics notified); `false` clears the diagnostic
     * (→ diagnostic_cleared) so no ticket is ever created.
     */
    public function submitReport(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            // Context-Aware Classification — the inspector may only file Routine or Breakdown; the
            // administrative types stay manager-only (set via PATCH /{ticket}/type). This is the
            // server-side guard behind the (already filtered) picker, so a crafted request can't slip
            // an admin classification in either.
            $allowedTypes = $request->user()?->can('maintenance.manage')
                ? array_keys(Maintenance::MAINTENANCE_TYPES)
                : Maintenance::TYPES_TECHNICIAN;

            $data = $request->validate([
                'requires_maintenance' => ['required', 'boolean'],
                'symptoms'             => ['nullable', 'array'],
                'symptoms.*'           => ['string', 'max:255'],
                // Symptom → Root-Cause diagnostic: the cause chosen (or typed) for each symptom. A
                // null root_cause_id means a custom cause — recorded as 'pending' for admin review.
                'causes'               => ['nullable', 'array'],
                'causes.*.symptom'       => ['required_with:causes', 'string', 'max:255'],
                'causes.*.root_cause'    => ['nullable', 'string', 'max:191'],
                'causes.*.root_cause_id' => ['nullable', 'integer', Rule::exists('fault_causes', 'id')],
                'severity'             => ['nullable', 'string', 'max:30'],
                // The inspector's mandatory fault-severity grade. Required when a ticket is opened
                // (enforced in the service against requires_maintenance); validated for shape here.
                'fault_severity'       => ['nullable', Rule::in(Maintenance::FAULT_SEVERITIES)],
                'recommended_action'   => ['nullable', 'string', 'max:2000'],
                'notes'                => ['nullable', 'string', 'max:2000'],
                // The inspector's official classification — this is the authoritative source,
                // not the Driver's request. Optional: can be set later via PATCH /{ticket}/type.
                // Restricted to what this user is allowed to set (technician → routine/breakdown only).
                'maintenance_type'     => ['nullable', Rule::in($allowedTypes)],
                // Repair Location — the Supervisor's "where does this repair happen?" call. 'on_site'
                // routes it to the mobile lane (car stays available); 'in_shop' (or omitted) → the
                // classic workshop pipeline. Only meaningful when requires_maintenance is true.
                'repair_location'      => ['nullable', Rule::in(Maintenance::REPAIR_LOCATIONS)],
                // End-of-test-drive odometer (optional) + the >10 km gap explanation. Captured at Decide,
                // stored under the 'report' flag key — never overwrites the start-of-drive test_odometer.
                'report_odometer'      => ['nullable', 'integer', 'min:1'],
                'odometer_note'        => ['nullable', 'string', 'max:2000'],
            ]);

            $requires = $request->boolean('requires_maintenance');
            $ticket = $this->workflow->submitReport($ticket, $data, $requires, $request->user());

            // Promote the inspector's findings into first-class routable tasks (one per fault), so the
            // ticket can be split across garages from the moment it opens.
            if ($requires) {
                $this->tasks->syncFromFindings($ticket, $request->user());
                $ticket->load(self::EAGER);
            }

            return ResponseHelper::SuccessResponse(
                MaintenanceWorkflowResource::make($ticket),
                $requires ? 'Requires maintenance — ticket opened, logistics notified' : 'No maintenance needed — diagnostic closed',
                200
            );
        });
    }

    /**
     * Phase 2 — the Supervisor (dispatcher) reviews the open ticket, picks the ONE primary garage and
     * assigns a driver to collect the car. Advances inspection_pending → awaiting_dispatch; the car stays
     * parked until the driver physically picks it up. Single-garage model: every open fault is routed to
     * this garage's active stint here, so the whole ticket sits at one place. Gated to maintenance.delegate.
     */
    public function assignDispatch(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $data = $request->validate([
                'vendor_id'            => ['required', 'integer', Rule::exists('vendors', 'id')],
                'driver_id'            => ['nullable', 'integer', Rule::exists('users', 'id')],
                'expected_return_date' => ['nullable', 'date'],
                'note'                 => ['nullable', 'string', 'max:2000'], // supervisor's reason/note on (re)assigning the garage
                // SPLIT-DISPATCH (delegate control): the subset of faults to route to THIS garage now. Omit
                // or send null to dispatch every open fault (classic whole-ticket dispatch). Each id must be
                // a fault on this ticket. Unselected open faults stay Pending Assignment for the Dispatch Queue.
                'fault_ids'            => ['nullable', 'array'],
                'fault_ids.*'          => ['integer', Rule::exists('maintenance_tasks', 'id')->where('maintenance_id', $ticket->id)],
            ]);

            $ticket = $this->workflow->assignDispatch($ticket, $data, $request->user());

            // Route the CHOSEN faults to the primary garage (null = all). Unselected open faults remain
            // Pending Assignment (no stint) — surfaced in the delegate's Dispatch Queue to assign later. The
            // per-fault "assigned to garage" log is SUPPRESSED here (log: false): at dispatch the car hasn't
            // arrived, so the per-fault confirmation is deferred to the In-Workshop check-in (markUnderRepair).
            $this->tasks->dispatchFaults($ticket, (int) $data['vendor_id'], $data['fault_ids'] ?? null, $request->user(), false);
            $ticket->load(self::EAGER);

            return ResponseHelper::SuccessResponse(MaintenanceWorkflowResource::make($ticket), 'Garage and driver assigned — driver notified to pick up', 200);
        });
    }

    /**
     * SPLIT-DISPATCH — assign one or more Pending-Assignment faults to the car's CURRENT garage. The
     * delegate (Waleed/Abdullah) works the Dispatch Queue: faults left Pending at dispatch are routed here
     * once they decide. Single-garage model — the car is at one place, so pending faults join the garage
     * it's at NOW; to work a fault at a DIFFERENT garage, transfer the car there first, then assign.
     * Gated to maintenance.delegate.
     */
    public function assignPending(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $data = $request->validate([
                'fault_ids'   => ['required', 'array', 'min:1'],
                'fault_ids.*' => ['integer', Rule::exists('maintenance_tasks', 'id')->where('maintenance_id', $ticket->id)],
            ]);

            if (! $ticket->vendor_id) {
                throw new \App\Exceptions\WorkflowTransitionException(
                    'The car has no current garage yet — dispatch it to a garage before assigning pending faults.',
                    ['field' => 'vendor_id']
                );
            }

            // dispatchFaults skips any of these already at a garage, so this only routes the truly-pending ones.
            $assigned = $this->tasks->dispatchFaults($ticket, (int) $ticket->vendor_id, $data['fault_ids'], $request->user(), true);
            $ticket->load(self::EAGER);

            return ResponseHelper::SuccessResponse(
                MaintenanceWorkflowResource::make($ticket),
                $assigned > 0 ? $assigned . ' fault(s) assigned to ' . $ticket->garage : 'No pending faults to assign.',
                200
            );
        });
    }

    /**
     * UC-3 — the Driver captures the odometer and picks the car up. The garage is the Supervisor's
     * call (set at dispatch-assignment) — the driver cannot pick or change it, so vendor_id is NOT
     * accepted here. Optionally accepts the odometer PHOTO directly (multipart), ingested server-side
     * so the Driver role needs no inspections.* permission. The photo is supplementary: it's saved
     * best-effort AFTER the transition commits, so a storage hiccup can never stop the car from moving.
     */
    public function dispatch(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $data = $request->validate([
                'dispatch_odometer'    => ['required', 'integer', 'min:1'],
                'out_date'             => ['nullable', 'date'], // when the car left — defaults to today
                'expected_return_date' => ['nullable', 'date'],
                'odometer_photo'       => ['nullable', 'image', 'max:8192'], // ≤ 8 MB
                'odometer_note'        => ['nullable', 'string', 'max:2000'], // explanation for a >10 km gap
            ]);

            $ticket = $this->workflow->dispatch($ticket, $data, $request->user());

            $photoSaved = false;
            if ($request->hasFile('odometer_photo')) {
                try {
                    $photoSaved = (bool) $this->storeOdometerPhoto($ticket, $request->file('odometer_photo'), $request->user());
                } catch (\Throwable $e) {
                    report($e); // logged, never surfaced — the dispatch already succeeded
                }
            }

            return ResponseHelper::SuccessResponse(
                ['ticket' => MaintenanceWorkflowResource::make($ticket), 'odometer_photo_saved' => $photoSaved],
                'Vehicle dispatched to garage',
                200
            );
        });
    }

    /**
     * UC-3 (Recovery variant) — a Recovery Truck (winch) tows a broken-down car to the garage. Reachable
     * straight from `inspection_pending` (a breakdown ticket the moment it's born) OR `awaiting_dispatch`
     * — DECOUPLED from the classic "Assign Garage" screen: `vendor_id` is accepted here so a supervisor
     * picks the destination + the towing unit in one action when the ticket doesn't have a garage yet
     * (optional when it already does — see dispatchRecovery()). Same mandatory odometer/condition gate as
     * a driver dispatch (the reading + photo are required), but the moving entity is a RECOVERY UNIT (name
     * + operator mobile), not a driver. The photo is saved best-effort after the transition commits, so a
     * storage hiccup never stops the recovery. Gated to a driver or supervisor
     * (maintenance.logistics|maintenance.delegate) on the route.
     */
    public function recoveryDispatch(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $data = $request->validate([
                'dispatch_odometer'    => ['required', 'integer', 'min:1'],
                'recovery_unit_name'   => ['required', 'string', 'max:191'],
                'recovery_unit_phone'  => ['nullable', 'string', 'max:40'],
                'odometer_note'        => ['nullable', 'string', 'max:2000'], // explanation for a >10 km gap
                // Only required when the ticket has no garage yet — dispatchRecovery() enforces that itself
                // (reusing ticket->vendor_id when this is omitted), so it's optional at the validation layer.
                'vendor_id'            => ['nullable', 'integer', Rule::exists('vendors', 'id')],
                'out_date'             => ['nullable', 'date'],
                'expected_return_date' => ['nullable', 'date'],
                'odometer_photo'       => ['required', 'image', 'max:8192'], // ≤ 8 MB — mandatory condition/odometer shot
            ]);

            $ticket = $this->workflow->dispatchRecovery($ticket, $data, $request->user());

            // Route any open fault tasks to the (now-set) garage — mirrors assignDispatch's own side
            // effect. Idempotent (dispatchFaults skips faults already at a garage), so safe to always call,
            // whether the garage was just picked here or already set by the classic assign step.
            $this->tasks->dispatchFaults($ticket, (int) $ticket->vendor_id, null, $request->user(), false);
            $ticket->load(self::EAGER);

            $photoSaved = false;
            try {
                $photoSaved = (bool) $this->storeOdometerPhoto($ticket, $request->file('odometer_photo'), $request->user());
            } catch (\Throwable $e) {
                report($e); // logged, never surfaced — the recovery dispatch already succeeded
            }

            return ResponseHelper::SuccessResponse(
                ['ticket' => MaintenanceWorkflowResource::make($ticket), 'odometer_photo_saved' => $photoSaved],
                'Recovery unit dispatched — vehicle being towed to the garage',
                200
            );
        });
    }

    /**
     * Persist the dispatch odometer photo as an InspectionRecord (phase 'pre', zone 'odometer'),
     * anchored to the car. Bytes go to S3 when a bucket is configured, otherwise the local `public`
     * disk — so it works on a demo machine with no AWS env. Returns the saved record, or null.
     */
    private function storeOdometerPhoto(Maintenance $ticket, UploadedFile $file, $user, string $phase = 'pre'): ?InspectionRecord
    {
        $disk = config('filesystems.disks.s3.bucket') ? 's3' : 'public';
        $ext  = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
        $dir  = "inspections/vehicle-{$ticket->vehicle_id}/{$phase}/odometer";

        // Snapshot metadata BEFORE the move (the temp file is gone after storeAs).
        $mime = $file->getMimeType();
        $size = $file->getSize();
        [$width, $height] = @getimagesize($file->getRealPath()) ?: [null, null];

        $key = $file->storeAs($dir, (string) Str::uuid() . '.' . $ext, $disk);
        if (! $key) {
            return null;
        }

        return InspectionRecord::create([
            'vehicle_id'            => $ticket->vehicle_id,
            'inspection_session_id' => (string) Str::uuid(),
            'phase'                 => $phase,
            'body_part'             => 'odometer',
            'checkpoint_type'       => 'interior',
            's3_disk'               => $disk,
            's3_key'                => $key,
            'mime_type'             => $mime,
            'file_size'             => $size,
            'width'                 => $width ?: null,
            'height'                => $height ?: null,
            'inspector_id'          => $user?->id,
            'inspector_name'        => $user?->name,
            'captured_at'           => now(),
        ]);
    }

    /**
     * UC-4 — "Now at Garage" arrival checkpoint. The driver/shop confirms arrival; the arrival odometer
     * and its photo are BOTH mandatory (the integrity gate that logs the mileage at check-in, distinct
     * from the pickup 'pre' shot and the return 'post' shot). Reading is stored on the ticket; the photo
     * becomes an 'arrival'-phase odometer inspection record. Photo is saved best-effort post-commit.
     */
    public function underRepair(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $data = $request->validate([
                'receive_odometer'     => ['required', 'integer', 'min:1'],
                'odometer_photo'       => ['required', 'image', 'max:8192'], // ≤ 8 MB — arrival check-in shot
                'odometer_note'        => ['nullable', 'string', 'max:2000'], // explanation for a >10 km gap
                'garage_feedback'      => ['nullable', 'string', 'max:2000'],
                'expected_return_date' => ['nullable', 'date'],
            ]);

            $ticket = $this->workflow->markUnderRepair($ticket, $data, $request->user());

            $photoSaved = false;
            try {
                $photoSaved = (bool) $this->storeOdometerPhoto($ticket, $request->file('odometer_photo'), $request->user(), 'arrival');
            } catch (\Throwable $e) {
                report($e); // logged — the transition + the reading already committed
            }

            return ResponseHelper::SuccessResponse(
                ['ticket' => MaintenanceWorkflowResource::make($ticket), 'odometer_photo_saved' => $photoSaved],
                'Vehicle checked in at the garage',
                200
            );
        });
    }

    /** Stage 3 — append GARAGE-identified findings while the car is under repair (no state change). */
    public function addFindings(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $data = $request->validate([
                'findings'                 => ['required', 'array', 'min:1'],
                'findings.*.text'          => ['required', 'string', 'max:255'],
                'findings.*.severity'      => ['nullable', 'string', 'max:30'],
                // Each garage finding may carry its diagnosed root cause (Symptom → Root-Cause). A
                // missing root_cause_id (a typed cause) is recorded 'pending' for admin review.
                'findings.*.root_cause'    => ['nullable', 'string', 'max:191'],
                'findings.*.root_cause_id' => ['nullable', 'integer', Rule::exists('fault_causes', 'id')],
            ]);

            $ticket = $this->workflow->addGarageFindings($ticket, $data['findings'], $request->user());

            // A garage-found fault is also a routable task — promote it so it can get its own garage.
            $this->tasks->syncFromFindings($ticket, $request->user());
            $ticket->load(self::EAGER);

            return ResponseHelper::SuccessResponse(MaintenanceWorkflowResource::make($ticket), 'Garage findings added', 200);
        });
    }

    /** Follow-up & Repair — a Driver logs a follow-up note while the car is in transit / under repair. */
    public function followUp(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $data = $request->validate([
                'note' => ['required', 'string', 'max:2000'],
            ]);

            $ticket = $this->workflow->addFollowUp($ticket, $data['note'], $request->user());
            return ResponseHelper::SuccessResponse(MaintenanceWorkflowResource::make($ticket), 'Follow-up logged', 200);
        });
    }

    /**
     * UC-5 — Logistics/Garage relays "garage finished" → ready for re-inspection. No odometer is
     * captured at this step: the car doesn't move inside the workshop, so a reading here would just
     * duplicate the garage-arrival (receive) one — the return-to-service reading is taken at the
     * re-inspection sign-off. final_odometer/odometer_photo stay OPTIONAL for backward compatibility
     * (still stored best-effort if a client sends them), but the step no longer requires them.
     */
    public function ready(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $data = $request->validate([
                // No odometer is captured at "Maintenance complete" — the car doesn't move inside the
                // workshop, so a reading here would just duplicate the garage-arrival one. Kept nullable
                // (not removed) so the endpoint stays backward-compatible if a client still sends it.
                'final_odometer'  => ['nullable', 'integer', 'min:1'],
                'odometer_photo'  => ['nullable', 'image', 'max:8192'],
                'odometer_note'   => ['nullable', 'string', 'max:2000'],
                'garage_feedback' => ['nullable', 'string', 'max:2000'],
                'cost'            => ['nullable', 'numeric', 'min:0'],
                // Granular time-per-fault: a JSON array [{text, hours}] attributing actual repair time
                // to each fault tag. Sent as a string because this step posts multipart (odometer photo).
                'repair_times'    => ['nullable', 'string'],
                // Structured Parts + Labor breakdown — a JSON array of line items, also a string for the
                // same multipart reason. Decoded + shape-normalised below before reaching the service.
                'line_items'      => ['nullable', 'string'],
            ]);

            if (isset($data['final_odometer'])) {
                $data['return_odometer'] = $data['final_odometer']; // service field name — only when supplied
            }
            $decoded = json_decode((string) $request->input('repair_times'), true);
            if (is_array($decoded)) {
                $data['repair_times'] = $decoded;
            }
            $lines = json_decode((string) $request->input('line_items'), true);
            if (is_array($lines)) {
                $data['line_items'] = $this->normalizeLineItems($lines);
            }
            $ticket = $this->workflow->markReady($ticket, $data, $request->user());

            $photoSaved = false;
            if ($request->hasFile('odometer_photo')) {
                try {
                    $photoSaved = (bool) $this->storeOdometerPhoto($ticket, $request->file('odometer_photo'), $request->user(), 'post');
                } catch (\Throwable $e) {
                    report($e); // logged — the transition already committed
                }
            }

            return ResponseHelper::SuccessResponse(
                ['ticket' => MaintenanceWorkflowResource::make($ticket), 'odometer_photo_saved' => $photoSaved],
                'Vehicle ready for pickup',
                200
            );
        });
    }

    // ── UC-5a — Supervisor Video-Review gate (Waleed / Abdullah) ────────────────

    /**
     * Supervisor Video-Review — APPROVE. Waleed/Abdullah watched the garage's video and are satisfied →
     * the car advances to Ready for Pickup. At least one video must be uploaded first (enforced in the
     * service). Supervisor authority (maintenance.delegate).
     */
    public function approveRepair(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $ticket = $this->workflow->approveRepair($ticket, $request->user());
            return ResponseHelper::SuccessResponse(MaintenanceWorkflowResource::make($ticket), 'Repair approved — ready for pickup', 200);
        });
    }

    /**
     * Supervisor Video-Review — REQUEST A RE-FIX. Not satisfied with the video → send the car back to the
     * SAME garage for more work (→ under_repair) with a reason. Supervisor authority (maintenance.delegate).
     */
    public function requestRefix(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
            $ticket = $this->workflow->requestRefix($ticket, $data['reason'], $request->user());
            return ResponseHelper::SuccessResponse(MaintenanceWorkflowResource::make($ticket), 'Re-fix requested — sent back to the garage', 200);
        });
    }

    // ── UC-5b/c — Ready for Pickup → In Our Park (mandatory return-leg photo checkpoints) ────────

    /**
     * UC-5b — Driver COLLECTS the car from the garage: the first of two mandatory checkpoints on the
     * return leg (arriveAtPark() is the second). BOTH the "received from garage" photo AND the garage-OUT
     * odometer reading are mandatory here (required at the API edge) — this is when the car physically
     * leaves the garage. No workflow_status change — the ticket stays at ready_for_pickup while the car is
     * en route back to base. Driver/Logistics authority (maintenance.logistics).
     */
    public function collectFromGarage(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $data = $request->validate([
                'odometer_photo'  => ['required', 'image', 'max:8192'], // ≤ 8 MB — mandatory "received from garage" shot
                // Garage-OUT reading — mandatory: this is when the car physically leaves the garage.
                'return_odometer' => ['required', 'integer', 'min:1'],
                'odometer_note'   => ['nullable', 'string', 'max:2000'], // explanation for a >10 km gap
            ]);

            $ticket = $this->workflow->collectFromGarage($ticket, $data, $request->user());

            $photoSaved = false;
            try {
                $photoSaved = (bool) $this->storeOdometerPhoto($ticket, $request->file('odometer_photo'), $request->user(), 'garage_pickup');
            } catch (\Throwable $e) {
                report($e); // logged — the transition already committed
            }

            return ResponseHelper::SuccessResponse(
                ['ticket' => MaintenanceWorkflowResource::make($ticket), 'odometer_photo_saved' => $photoSaved],
                'Car collected from the garage',
                200
            );
        });
    }

    /**
     * UC-5c — Driver ARRIVES back at base: the second mandatory photo checkpoint on the return leg. This
     * action CANNOT complete without the arrival photo (required at the API edge — a missing file 422s
     * before any transition runs). The service then auto-branches by repair severity: a minor (routine)
     * repair auto-closes and frees the car immediately; a major (critical/moderate) repair routes to the
     * final QA re-inspection and the car stays "in maintenance" until the Inspector signs off. Driver/
     * Logistics authority (maintenance.logistics).
     */
    public function arriveAtPark(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $data = $request->validate([
                'odometer_photo' => ['required', 'image', 'max:8192'], // ≤ 8 MB — mandatory arrival shot; blocks the transition
                'cost'           => ['nullable', 'numeric', 'min:0'],
                'vendor_id'      => ['nullable', 'integer', Rule::exists('vendors', 'id')],
                'actual_in_date' => ['nullable', 'date'],
                'notes'          => ['nullable', 'string', 'max:2000'],
                // Deferred-invoice, same meaning as close(): only applies on the minor auto-close branch.
                'defer_invoice'  => ['nullable', 'boolean'],
            ]);

            $ticket = $this->workflow->arriveAtPark($ticket, $data, $request->user());

            $photoSaved = false;
            try {
                $photoSaved = (bool) $this->storeOdometerPhoto($ticket, $request->file('odometer_photo'), $request->user(), 'park_arrival');
            } catch (\Throwable $e) {
                report($e); // logged — the transition already committed
            }

            $msg = match ($ticket->workflow_status) {
                Maintenance::WF_CLOSED             => 'Back in our park — ticket closed, vehicle available',
                Maintenance::WF_AWAITING_INVOICE   => 'Back in our park — vehicle available, invoice pending',
                Maintenance::WF_READY_REINSPECTION => 'Back in our park — awaiting the final QA re-inspection',
                default                             => 'Arrived at our park',
            };

            return ResponseHelper::SuccessResponse(
                ['ticket' => MaintenanceWorkflowResource::make($ticket), 'odometer_photo_saved' => $photoSaved],
                $msg,
                200
            );
        });
    }

    // ── Video Evidence — the garage's repair videos (the permanent repair record) ────

    /**
     * Hand back a presigned S3 PUT URL so the browser uploads the garage's repair video DIRECTLY to S3
     * (videos are large — they never touch the app server). The browser then POSTs the resulting key back
     * to storeVideo(). Namespaced per ticket. Supervisor authority (maintenance.delegate).
     */
    public function presignVideo(Request $request, Maintenance $ticket)
    {
        try {
            $data = $request->validate([
                'content_type' => ['required', 'string', 'max:100'],
                'extension'    => ['nullable', 'string', 'max:10'],
            ]);
            $ext = strtolower(preg_replace('/[^a-z0-9]/i', '', $data['extension'] ?? '')) ?: 'mp4';
            $key = sprintf('maintenance-videos/ticket-%d/%s.%s', $ticket->id, (string) Str::uuid(), $ext);

            $signed = Storage::disk('s3')->temporaryUploadUrl($key, now()->addMinutes(15), ['ContentType' => $data['content_type']]);

            return ResponseHelper::SuccessResponse([
                'disk'       => 's3',
                'key'        => $key,
                'upload_url' => $signed['url'],
                'headers'    => $signed['headers'] ?? ['Content-Type' => $data['content_type']],
                'expires_in' => 900,
            ], 'Presigned upload URL generated', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::FailureResponse(
                null,
                'Could not generate an upload URL. Check the S3 disk is configured (AWS_* env). Small clips can still upload directly. [' . $e->getMessage() . ']',
                400
            );
        }
    }

    /**
     * Persist one repair video against the ticket. Two paths: (a) after a browser-direct S3 PUT the client
     * posts {s3_key, disk, content_type, …}; (b) a small-file fallback posts the raw `file` multipart,
     * ingested to S3 (when configured) or the local `public` disk. Supervisor authority (maintenance.delegate).
     */
    public function storeVideo(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $data = $request->validate([
                's3_key'        => ['nullable', 'string', 'max:1024'],
                'disk'          => ['nullable', 'string', 'max:30'],
                'content_type'  => ['nullable', 'string', 'max:100'],
                'original_name' => ['nullable', 'string', 'max:255'],
                'file_size'     => ['nullable', 'integer', 'min:0'],
                'note'          => ['nullable', 'string', 'max:500'],
                // Optionally pin the video to ONE fault on this ticket (the "Mark fixed" evidence). Must
                // belong to this ticket; left null it stays a plain ticket-scoped video.
                'maintenance_task_id' => ['nullable', 'integer', Rule::exists('maintenance_tasks', 'id')->where('maintenance_id', $ticket->id)],
                // Fallback multipart ingest — capped at 256 MB (browser-direct presign has no such limit).
                // Accepts a repair VIDEO or a still PHOTO — an image is sufficient evidence when a clip
                // isn't warranted, so video is no longer mandatory for the completion step.
                'file'          => ['nullable', 'file', 'mimetypes:video/mp4,video/quicktime,video/x-msvideo,video/webm,video/3gpp,image/jpeg,image/png,image/webp,image/heic,image/heif', 'max:262144'],
            ]);

            $user   = $request->user();
            $taskId = $data['maintenance_task_id'] ?? null;

            if ($request->hasFile('file')) {
                $file = $request->file('file');
                $disk = config('filesystems.disks.s3.bucket') ? 's3' : 'public';
                $mime = (string) $file->getClientMimeType();
                $kind = str_starts_with($mime, 'image/') ? 'image' : 'video';
                $ext  = strtolower($file->getClientOriginalExtension() ?: ($file->guessExtension() ?: 'mp4'));
                $key  = $file->storeAs("maintenance-videos/ticket-{$ticket->id}", (string) Str::uuid() . '.' . $ext, $disk);
                if (! $key) {
                    return ResponseHelper::FailureResponse(null, 'The file could not be stored.', 500);
                }
                $media = $ticket->media()->create([
                    'kind'                => $kind,
                    'maintenance_task_id' => $taskId,
                    'disk'                => $disk,
                    's3_key'              => $key,
                    'content_type'        => $mime,
                    'original_name'       => $file->getClientOriginalName(),
                    'file_size'           => $file->getSize(),
                    'note'                => $data['note'] ?? null,
                    'uploaded_by'         => $user?->id,
                    'uploaded_by_name'    => $user?->name,
                ]);
            } elseif (! empty($data['s3_key'])) {
                $mime = (string) ($data['content_type'] ?? '');
                $media = $ticket->media()->create([
                    'kind'                => str_starts_with($mime, 'image/') ? 'image' : 'video',
                    'maintenance_task_id' => $taskId,
                    'disk'                => $data['disk'] ?? 's3',
                    's3_key'              => $data['s3_key'],
                    'content_type'        => $data['content_type'] ?? null,
                    'original_name'       => $data['original_name'] ?? null,
                    'file_size'           => $data['file_size'] ?? null,
                    'note'                => $data['note'] ?? null,
                    'uploaded_by'         => $user?->id,
                    'uploaded_by_name'    => $user?->name,
                ]);
            } else {
                return ResponseHelper::FailureResponse(null, 'Upload a photo or video first (no file or key received).', 422);
            }

            return ResponseHelper::SuccessResponse($this->mediaArray($media), 'Evidence saved', 201);
        });
    }

    /** List a ticket's videos with short-lived signed view URLs. */
    public function listMedia(Maintenance $ticket)
    {
        return $this->run(function () use ($ticket) {
            $items = $ticket->media()->get()->map(fn ($m) => $this->mediaArray($m))->all();
            return ResponseHelper::SuccessResponse($items, 'Media retrieved', 200);
        });
    }

    /** Delete one video (S3/local object + row). Supervisor authority (maintenance.delegate). */
    public function destroyMedia(Maintenance $ticket, \App\Models\MaintenanceMedia $media)
    {
        return $this->run(function () use ($ticket, $media) {
            abort_unless((int) $media->maintenance_id === (int) $ticket->id, 404);
            try {
                Storage::disk($media->disk ?: 's3')->delete($media->s3_key);
            } catch (\Throwable $e) {
                // best-effort — still remove the row even if the object is already gone
            }
            $media->delete();
            return ResponseHelper::SuccessResponse(null, 'Video removed', 200);
        });
    }

    /** Serialise one media row (with a short-lived signed view URL). */
    private function mediaArray(\App\Models\MaintenanceMedia $m): array
    {
        return [
            'id'               => $m->id,
            'kind'             => $m->kind,
            'note'             => $m->note,
            'content_type'     => $m->content_type,
            'original_name'    => $m->original_name,
            'file_size'        => $m->file_size,
            'uploaded_by_name' => $m->uploaded_by_name,
            'created_at'       => optional($m->created_at)->toIso8601String(),
            'url'              => $m->viewUrl(),
        ];
    }

    /** UC-6 — Inspector's final QA re-inspection PASSED (major repairs, from ready_for_reinspection) →
     *  close & return to service. Minor repairs auto-close via arriveAtPark() instead. */
    public function close(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $data = $request->validate([
                'cost'           => ['nullable', 'numeric', 'min:0'],
                'vendor_id'      => ['nullable', 'integer', Rule::exists('vendors', 'id')],
                'actual_in_date' => ['nullable', 'date'], // when the car came back — defaults to today
                'notes'          => ['nullable', 'string', 'max:2000'],
                // Final re-inspection odometer captured at sign-off (car is physically back) + the >10 km
                // gap explanation. Optional at the validation layer; the modal makes it required on PASS.
                'final_odometer' => ['nullable', 'integer', 'min:1'],
                'odometer_note'  => ['nullable', 'string', 'max:2000'],
                // Deferred-invoice: sign off + return the car to service now, but park in awaiting_invoice
                // (invoice outstanding) instead of a full close, so the workflow never gets stuck.
                'defer_invoice'  => ['nullable', 'boolean'],
            ]);

            // A PASSED re-inspection means every fault is verified fixed — resolve any still-open faults
            // so the container's task progress reflects the sign-off (the car still closes explicitly).
            foreach ($ticket->tasks()->whereNotIn('status', \App\Models\MaintenanceTask::TERMINAL)->get() as $task) {
                $this->tasks->setStatus($task, \App\Models\MaintenanceTask::STATUS_COMPLETED, $request->user());
            }

            $ticket = $this->workflow->close($ticket, $data, $request->user());
            $msg = $ticket->workflow_status === Maintenance::WF_AWAITING_INVOICE
                ? 'Vehicle back in service — invoice pending'
                : 'Ticket closed — vehicle back in service';
            return ResponseHelper::SuccessResponse(MaintenanceWorkflowResource::make($ticket), $msg, 200);
        });
    }

    /**
     * "Mark as Serviced" — complete an ON-SITE (mobile) ticket in one step. No garage, no re-inspection,
     * no QA: the minor job was done where the car is parked, so this resolves the faults and closes the
     * ticket, clearing the "Pending Maintenance" tag. The car was never out of service. Same authority as
     * a close (the inspector who logged it or a supervisor). See MaintenanceWorkflowService::markServiced().
     */
    public function markServiced(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $data = $request->validate([
                'notes'          => ['nullable', 'string', 'max:2000'],
                'cost'           => ['nullable', 'numeric', 'min:0'],   // optional on-the-spot cost (else deferred)
                'vendor_id'      => ['nullable', 'integer', Rule::exists('vendors', 'id')], // optional mobile vendor
                'actual_in_date' => ['nullable', 'date'],
                // Odometer at the on-site service — rolls any routine-service fault's Service Reminder
                // forward from this reading (an in-house oil change never reaches a garage odometer capture).
                'odometer'       => ['nullable', 'integer', 'min:0'],
            ]);

            // The on-site job is done → resolve every open fault so the container reflects completion
            // (mirrors the re-inspection pass in close()); the ticket still closes explicitly below. The
            // odometer rides through so a routine oil/battery service schedules its next reminder.
            foreach ($ticket->tasks()->whereNotIn('status', \App\Models\MaintenanceTask::TERMINAL)->get() as $task) {
                $this->tasks->setStatus($task, \App\Models\MaintenanceTask::STATUS_COMPLETED, $request->user(), null, $data['odometer'] ?? null);
            }

            $ticket = $this->workflow->markServiced($ticket, $data, $request->user());
            return ResponseHelper::SuccessResponse(
                MaintenanceWorkflowResource::make($ticket),
                'On-site service completed — vehicle stays available',
                200
            );
        });
    }

    /**
     * Invoice tracker (/invoices/pending-submission) — every ticket parked in awaiting_invoice: the repair
     * is done and the car is back in service, but the invoice hasn't landed. Oldest-waiting first so the
     * overdue ones (past the SLA) surface at the top.
     */
    public function pendingInvoices()
    {
        return $this->run(function () {
            $tickets = Maintenance::awaitingInvoice()
                ->with(self::EAGER)->withCount('media')
                ->orderBy('awaiting_invoice_since')
                ->limit(500)
                ->get();

            $overdue = $tickets->filter->invoiceIsOverdue()->count();

            return ResponseHelper::SuccessResponse([
                'tickets'     => MaintenanceWorkflowResource::collection($tickets),
                'total'       => $tickets->count(),
                'overdue'     => $overdue,
                'sla_days'    => Maintenance::INVOICE_SLA_DAYS,
            ], 'Pending invoices retrieved', 200);
        });
    }

    /**
     * Mark the outstanding invoice as received — close out an awaiting_invoice ticket. (Recording the
     * itemised invoice via line-items / the garage portal closes it automatically; this is the manual
     * "the paperwork is in" button on the tracker.) Money action → maintenance.manage on the route.
     */
    public function finalizeInvoice(Maintenance $ticket)
    {
        return $this->run(function () use ($ticket) {
            if ($ticket->workflow_status !== Maintenance::WF_AWAITING_INVOICE) {
                throw new \App\Exceptions\WorkflowTransitionException('This ticket is not awaiting an invoice.', ['field' => 'workflow_status']);
            }
            $ticket = $this->workflow->finalizeInvoice($ticket, request()->user());
            return ResponseHelper::SuccessResponse(MaintenanceWorkflowResource::make($ticket), 'Invoice received — ticket closed', 200);
        });
    }

    /**
     * Re-inspection FAILED (Quality-Control) — one or more faults came back still broken. Per fault the
     * inspector flags what is still wrong (`failed_task_ids`, with optional per-fault notes); every other
     * open fault is treated as verified fixed. Each still-broken fault is stamped with the garage that
     * failed it (blame + counter), then the whole ticket returns to the SUPERVISOR's dispatch queue
     * (→ reinspection_failed) to be re-dispatched — never a silent bounce back to the same garage.
     */
    public function reopen(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $data = $request->validate([
                'reason'               => ['nullable', 'string', 'max:2000'],
                'failed_task_ids'      => ['nullable', 'array'],
                'failed_task_ids.*'    => ['integer'],
                'failed_notes'         => ['nullable', 'array'], // { "<task_id>": "note" }
                // Optional inspector suggestion: re-route to a different garage for the supervisor's
                // re-dispatch (pre-selects it). Blame for the failure stays with the original garage.
                'redispatch_vendor_id' => ['nullable', 'integer', Rule::exists('vendors', 'id')],
            ]);

            // Re-routing the car to a DIFFERENT garage than the one it came back broken from is a
            // deliberate call the supervisor must be able to act on — so the reason stops being optional
            // the moment the garage changes. (Same-garage / no-change re-dispatch keeps it optional.)
            $redispatch    = $data['redispatch_vendor_id'] ?? null;
            $garageChanged = $redispatch !== null && (int) $redispatch !== (int) $ticket->vendor_id;
            if ($garageChanged && trim((string) ($data['reason'] ?? '')) === '') {
                throw new \App\Exceptions\WorkflowTransitionException(
                    'Add a reason for the garage change — it is required when you re-route to a different garage.',
                    ['field' => 'reason'],
                );
            }

            $user      = $request->user();
            $openTasks = $ticket->tasks()->whereNotIn('status', \App\Models\MaintenanceTask::TERMINAL)->get();
            $failedIds = array_map('intval', $data['failed_task_ids'] ?? []);
            $notes     = (array) ($data['failed_notes'] ?? []);

            // When the ticket carries faults, at least one must be flagged still-broken to send it back —
            // an all-passed re-inspection should CLOSE, not fail. (A legacy ticket with no tasks skips this.)
            if ($openTasks->isNotEmpty() && empty($failedIds)) {
                throw new \App\Exceptions\WorkflowTransitionException(
                    'Flag at least one fault that is still not fixed — or pass the re-inspection to close the ticket.',
                    ['field' => 'failed_task_ids'],
                );
            }

            $failedSummary = [];
            foreach ($openTasks as $task) {
                if (in_array($task->id, $failedIds, true)) {
                    // Capture the failing garage BEFORE we detach the fault from it.
                    $garageName = $task->currentVendor?->name
                        ?: \App\Models\Vendor::whereKey($task->current_vendor_id)->value('name');
                    $note = array_key_exists($task->id, $notes) ? (string) $notes[$task->id] : null;
                    $this->tasks->failReinspection($task, $note, $user);
                    $failedSummary[] = ['id' => $task->id, 'symptom' => $task->symptom, 'garage' => $garageName];
                } else {
                    // Not flagged broken → verified fixed at this re-inspection.
                    $this->tasks->setStatus($task, \App\Models\MaintenanceTask::STATUS_COMPLETED, $user);
                }
            }

            $ticket = $this->workflow->markReinspectionFailed($ticket, $data['reason'] ?? null, $failedSummary, $user, $data['redispatch_vendor_id'] ?? null);
            return ResponseHelper::SuccessResponse(MaintenanceWorkflowResource::make($ticket), 'Re-inspection failed — returned to the supervisor for re-dispatch', 200);
        });
    }

    /**
     * Financial Decoupling (Deferred Cost) — record the final repair cost AFTER the ticket was closed,
     * once the invoice paperwork is processed. Gated to maintenance.manage (the controllers who own the
     * money), since closing the ticket never required it.
     */
    public function recordCost(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $data = $request->validate([
                'cost' => ['required', 'numeric', 'min:0'],
            ]);

            $ticket = $this->workflow->recordCost($ticket, $data['cost'], $request->user());
            return ResponseHelper::SuccessResponse(MaintenanceWorkflowResource::make($ticket), 'Repair cost recorded', 200);
        });
    }

    /**
     * Path A (Manual Entry) — ask the garage for an itemised invoice. Stamps the request + alerts the
     * controllers who chase the garage (vendors have no login, so this is an internal prompt carrying
     * the garage's contact, not an in-app message to the vendor). Gated to maintenance.manage.
     */
    public function requestInvoice(Maintenance $ticket)
    {
        return $this->run(function () use ($ticket) {
            $ticket = $this->workflow->requestInvoice($ticket, request()->user());
            return ResponseHelper::SuccessResponse(MaintenanceWorkflowResource::make($ticket), 'Itemised invoice requested', 200);
        });
    }

    /** The ticket's structured Parts + Labor lines (the deferred-edit read). */
    public function lineItems(Maintenance $ticket)
    {
        return $this->run(function () use ($ticket) {
            $ticket->load('lineItems');
            return ResponseHelper::SuccessResponse(
                MaintenanceLineItemResource::collection($ticket->lineItems),
                'Line items retrieved',
                200
            );
        });
    }

    /**
     * Record/replace the ticket's structured Parts + Labor breakdown (the "deferred edit" path — works
     * on a ticket in ANY state, like recordCost). The full set is submitted each time; the service
     * re-derives parts_total / labor_total / cost. Gated to maintenance.manage (the controllers who own
     * the money) on the route.
     */
    public function syncLineItems(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $data = $request->validate($this->lineItemRules());
            $ticket = $this->workflow->syncLineItems(
                $ticket,
                $this->normalizeLineItems($data['line_items']),
                $request->user(),
                isset($data['receipt_total']) ? (float) $data['receipt_total'] : null,
                $data['variance_explanation'] ?? null,
            );
            return ResponseHelper::SuccessResponse(MaintenanceWorkflowResource::make($ticket), 'Parts & labor recorded', 200);
        });
    }

    /** Validation rules for a Parts + Labor line-item set (shared by the JSON sync endpoint). */
    private function lineItemRules(): array
    {
        return [
            'line_items'                       => ['present', 'array'],
            'line_items.*.kind'                => ['required', Rule::in(\App\Models\MaintenanceLineItem::KINDS)],
            'line_items.*.description'         => ['required', 'string', 'max:255'],
            'line_items.*.part_number'         => ['nullable', 'string', 'max:120'],
            // Diagnosis-First — every line MUST link to a finding on the ticket (validated against the
            // actual findings set in the service). No finding link = no cost, so no ghost spend.
            'line_items.*.finding_text'        => ['required', 'string', 'max:255'],
            'line_items.*.category_key'        => ['nullable', 'string', 'max:40'],
            // Cost integrity — every line must carry a REAL charge: a positive quantity (units / hours)
            // AND a positive unit price, so quantity × unit_price > 0. A zero-cost line is a data-entry
            // slip on an invoice form, not a valid charge.
            'line_items.*.quantity'            => ['required', 'numeric', 'gt:0'],
            'line_items.*.uom'                 => ['nullable', 'string', 'max:16'],
            'line_items.*.unit_price'          => ['required', 'numeric', 'gt:0'],
            'line_items.*.installed_on'        => ['nullable', 'date'],
            'line_items.*.installed_odometer'  => ['nullable', 'integer', 'min:0'],
            'line_items.*.warranty_months'     => ['nullable', 'integer', 'min:0', 'max:600'],
            // OCR-ready provenance — defaults to 'manual' server-side when omitted.
            'line_items.*.entry_source'        => ['nullable', Rule::in(['manual', 'ocr', 'import'])],

            // Garage Invoice Validation — the receipt grand total the itemised lines are checked
            // against, and the note that MUST accompany any mismatch (the variance gate itself is
            // enforced in the service, which knows the itemised sum after the lines are written).
            'receipt_total'                    => ['nullable', 'numeric', 'min:0'],
            'variance_explanation'             => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Normalise a decoded line-item array to exactly the keys the service consumes, dropping anything
     * stray a client might send. Keeps the multipart (markReady) and JSON (sync) paths identical.
     *
     * @param  array<int,mixed> $lines
     * @return array<int,array>
     */
    private function normalizeLineItems(array $lines): array
    {
        $allowed = ['kind', 'description', 'part_number', 'finding_text', 'category_key',
                    'quantity', 'uom', 'unit_price', 'installed_on', 'installed_odometer', 'warranty_months', 'entry_source'];

        return array_values(array_filter(array_map(function ($row) use ($allowed) {
            return is_array($row) ? array_intersect_key($row, array_flip($allowed)) : null;
        }, $lines)));
    }

    /**
     * Chronic Fault Watchdog — given a set of fault tags (?tags[]=…), return the vehicle's prior CLOSED
     * repairs of the same faults so the inspector is warned about recurring issues the moment they pick
     * a tag. With no tags it falls back to the faults on the vehicle's current open ticket (so a ticket
     * view can surface the watchdog automatically). Excludes the ticket being worked on via ?exclude_ticket_id.
     */
    public function faultInsights(Request $request, Vehicle $vehicle)
    {
        return $this->run(function () use ($request, $vehicle) {
            $tags    = array_values(array_filter((array) $request->input('tags', []), fn ($t) => is_string($t)));
            $exclude = $request->integer('exclude_ticket_id') ?: null;

            // No explicit tags → derive them from the vehicle's current open ticket's findings.
            if (empty($tags)) {
                $open = Maintenance::openWorkflow()
                    ->where('vehicle_id', $vehicle->id)
                    ->orderByDesc('id')
                    ->first();
                $tags = collect($open?->findings ?? [])->pluck('text')->filter()->values()->all();
            }

            $insights = $this->workflow->faultHistory($vehicle, $tags, $exclude);
            return ResponseHelper::SuccessResponse(['insights' => $insights], 'Fault history retrieved', 200);
        });
    }

    // ── Shared error mapping ─────────────────────────────────────────────────

    /**
     * Run a transition closure, mapping the two failure shapes to a clean 422:
     * a validation error, or an illegal state-machine move (with its from→to context).
     */
    // Location tracking (reassign / ping / status) moved to the canonical Logistics Dispatch system
    // (LogisticsDispatchController) — one channel for every vehicle movement, not just repairs.

    // ── Supervisor Notification & Delegation ───────────────────────────────────

    /**
     * The drivers a Supervisor can delegate to: every ACTIVE user holding maintenance.logistics —
     * the people who physically pick up / drop off cars (and can be notified in-app). Feeds the
     * Delegate picker. Returns a flat {id, name, email} list.
     */
    public function assignableDrivers()
    {
        return $this->run(function () {
            $hasStatus = \Illuminate\Support\Facades\Schema::hasColumn('users', 'status');
            $drivers = \App\Models\User::permission('maintenance.logistics')
                ->when($hasStatus, fn ($q) => $q->where('status', 'active'))
                ->orderBy('name')
                ->get(['id', 'name', 'email']);

            return ResponseHelper::SuccessResponse(
                $drivers->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])->all(),
                'Assignable drivers retrieved',
                200
            );
        });
    }

    /** Supervisor delegates a specific driver to pick up / drop off the car (→ "Driver Assigned"). */
    public function delegate(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $data = $request->validate([
                'driver_id'       => ['required', 'integer', Rule::exists('users', 'id')],
                'delegation_task' => ['required', Rule::in(Maintenance::DELEGATION_TASKS)],
            ]);
            $ticket = $this->workflow->delegate($ticket, $data, $request->user());
            return ResponseHelper::SuccessResponse(MaintenanceWorkflowResource::make($ticket), 'Driver assigned — notified', 200);
        });
    }

    // ── Garage routing (single-garage / sequential) ───────────────────────────

    /**
     * The ticket's faults as first-class tasks (each with its stint timeline), all grouped under the
     * ticket's single current garage. Drives the garage-routing panel; the same data also rides on every
     * board/show payload under `tasks`.
     */
    public function listTasks(Maintenance $ticket)
    {
        return $this->run(function () use ($ticket) {
            // Tasks lag findings on legacy tickets — promote on read so the panel is never empty.
            $this->tasks->syncFromFindings($ticket);
            $rows = $ticket->tasks()->with(['assignments.vendor:id,name', 'currentVendor:id,name'])->orderBy('id')->get();
            return ResponseHelper::SuccessResponse(\App\Http\Resources\MaintenanceTaskResource::collection($rows), 'Tasks retrieved', 200);
        });
    }

    /**
     * Garage Transfer (whole car) — the Supervisor sends the car to a DIFFERENT garage when work is still
     * needed. Closes the current garage's stint for every open fault (transferred_out + reason) and opens
     * a fresh stint at the next garage, then re-points the ticket's single current garage. The faults stay
     * tracked individually but always move together — the car is only ever in one place. Gated to
     * maintenance.delegate (the Supervisor's dispatch authority) on the route.
     */
    public function transferGarage(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $data = $request->validate([
                'vendor_id' => ['required', 'integer', Rule::exists('vendors', 'id')],
                'reason'    => ['nullable', 'string', 'max:2000'],
                // Mileage Gate — the current odometer is mandatory on every garage switch; the move is
                // rejected here (min:1) until it's recorded. No more silent, mileage-less transfers.
                'odometer'  => ['required', 'integer', 'min:1'],
                // Explanation for a >10 km gap from the last recorded reading (mandatory in the UI when it fires).
                'odometer_note' => ['nullable', 'string', 'max:2000'],
                // Transport Responsibility — optional custodian (a driver / logistics officer). When named,
                // the move is recorded as a real logistics task below; omitted → no transport task is spawned.
                'assigned_to_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
                // Set true to move ahead after the operator acknowledges the Conflict Check warning.
                'acknowledge_conflict' => ['nullable', 'boolean'],
            ]);

            if (! $ticket->vendor_id) {
                throw new \App\Exceptions\WorkflowTransitionException('Assign a primary garage first before transferring the car.', ['field' => 'vendor_id']);
            }
            if ((int) $data['vendor_id'] === (int) $ticket->vendor_id) {
                throw new \App\Exceptions\WorkflowTransitionException('The car is already at that garage.', ['field' => 'vendor_id']);
            }

            // ── Resolved-Transfer Oversight ───────────────────────────────────────────────────────
            // Moving a car to another garage while EVERY fault on the ticket is already fixed is unusual
            // (nothing left to repair). Gate it behind a MANDATORY justification note and log the case for
            // the /oversight/resolved-transfers board. Evaluated + the "from" garage snapshotted here,
            // BEFORE the move below re-points the ticket's current garage.
            $allResolved  = $ticket->tasksProgress()['all_resolved'];
            $fromVendorId = $ticket->vendor_id;
            $fromGarage   = $ticket->garage;
            if ($allResolved && trim((string) ($data['reason'] ?? '')) === '') {
                throw new \App\Exceptions\WorkflowTransitionException(
                    'Every fault on this ticket is already fixed. Add a note explaining why the car is being transferred.',
                    ['field' => 'reason'],
                );
            }

            $vendor = \App\Models\Vendor::findOrFail($data['vendor_id']);

            // ── Rule 1 · Conflict Check ───────────────────────────────────────────────────────────
            // Is the SAME car already under active repair at a DIFFERENT garage on another open ticket?
            // If so the car can't physically be in two workshops at once — warn before we move it, and
            // only proceed once the operator has consciously acknowledged the clash.
            if ($ticket->vehicle_id && ! ($data['acknowledge_conflict'] ?? false)) {
                $conflicts = Maintenance::openWorkflow()
                    ->where('vehicle_id', $ticket->vehicle_id)
                    ->where('id', '<>', $ticket->id)
                    ->whereNotNull('vendor_id')
                    ->where('vendor_id', '<>', $vendor->id)
                    ->with('vendor:id,name')
                    ->get();

                if ($conflicts->isNotEmpty()) {
                    $names = $conflicts->map(fn ($m) => $m->vendor?->name)->filter()->unique()->values();
                    return ResponseHelper::FailureResponse(
                        [
                            'conflict' => true,
                            'garages'  => $names->all(),
                            'tickets'  => $conflicts->map(fn ($m) => [
                                'id'     => $m->id,
                                'garage' => $m->vendor?->name,
                                'status' => $m->workflow_status,
                            ])->values()->all(),
                        ],
                        'Vehicle has active repairs in ' . ($names->join(', ', ' and ') ?: 'another garage') . '. Do you want to proceed?',
                        409,
                    );
                }
            }

            // ── Rule 2 · Mileage Gate ─────────────────────────────────────────────────────────────
            // Stamp the mandatory current odometer onto the ticket (continuity verdict) and heal the
            // car's live mileage forward. Mutates odometer_flags in memory; the rollback save below
            // persists it alongside the new garage — one write.
            $this->workflow->recordGarageTransferOdometer($ticket, (int) $data['odometer'], $data['odometer_note'] ?? null, $request->user());

            // Oversight record — the transfer is now committed (guards + conflict check passed). If every
            // fault was already fixed, log the case with the Supervisor's justification note. Best-effort:
            // a logging hiccup must never undo a committed transfer.
            if ($allResolved) {
                try {
                    \App\Models\ResolvedTransferFlag::create([
                        'maintenance_id' => $ticket->id,
                        'vehicle_id'     => $ticket->vehicle_id,
                        'from_vendor_id' => $fromVendorId,
                        'from_garage'    => $fromGarage,
                        'to_vendor_id'   => $vendor->id,
                        'to_garage'      => $vendor->name,
                        'note'           => trim((string) $data['reason']),
                        'odometer'       => (int) $data['odometer'],
                        'flagged_by'     => $request->user()?->id,
                    ]);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Failed to record resolved-transfer oversight flag', [
                        'ticket' => $ticket->id,
                        'error'  => $e->getMessage(),
                    ]);
                }
            }

            if ($ticket->hasReachedGarage()) {
                // ── Planned transfer (the car is physically AT its current garage) ─────────────────
                // Ground truth stays put: vendor_id KEEPS pointing at the garage the car is at, and only
                // the destination is recorded (transfer_to_vendor_id). The ticket drops to "Awaiting
                // Pickup" so a driver collects the car — the board reads "Awaiting Pickup · [current] →
                // [new]". The fault-stint hand-over + vendor re-point are DEFERRED to the destination
                // arrival check-in (markUnderRepair), so the single-garage invariant holds the whole leg.
                $this->workflow->beginGarageTransfer($ticket, $vendor, $data['assigned_to_id'] ?? null, $data['reason'] ?? null, $request->user());

                $ticket = Maintenance::with(self::EAGER)->findOrFail($ticket->id);
                return ResponseHelper::SuccessResponse(MaintenanceWorkflowResource::make($ticket), 'Transfer requested — the car is awaiting pickup at ' . ($ticket->garage ?: 'its garage') . ' to move to ' . $vendor->name, 200);
            }

            // ── Pre-arrival re-route (car never reached a garage — still in our park / en route) ──────
            // There is no "current garage" to preserve, so this is a plain destination change: move the
            // fault stints + re-point the ticket exactly as before, keeping the pre-arrival stage.
            $this->tasks->routeTicketToGarage($ticket, $vendor->id, $data['reason'] ?? null, $request->user(), (int) $data['odometer']);

            // The ticket owns its single current garage — re-point it (vendor_id is no longer derived
            // from tasks, so it must be set here to keep the car's whereabouts unambiguous).
            $ticket->vendor_id = $vendor->id;
            $ticket->garage    = $vendor->name;

            // ── Workflow Stage Rollback ───────────────────────────────────────────────────────────
            // Persist the garage re-point + mileage-gate flags (a pre-arrival car keeps its stage — the
            // rollback only kicks a car that had already reached a garage, which is handled above).
            $this->workflow->rollbackForGarageTransfer($ticket, $request->user());

            // ── Transport Manifest ────────────────────────────────────────────────────────────────
            // When a custodian was named, record the move as a canonical logistics task assigned to them.
            // It lands in their My Queue + bell, drives the ticket's live "In Transit · [driver]" position,
            // and is closed when they run the "Now at Garage" check-in (Confirm Handover). Best-effort: a
            // logistics hiccup must never undo a committed transfer. Omitted → no transport task is spawned.
            if ($ticket->vehicle_id && ! empty($data['assigned_to_id'])) {
                try {
                    app(\App\Services\LogisticsDispatchService::class)->dispatchForMaintenanceTransfer(
                        $ticket->vehicle,
                        $vendor->name,
                        (int) $data['assigned_to_id'],
                        $ticket->id,
                        $request->user(),
                    );
                } catch (\Throwable $e) {
                    report($e); // the transfer already committed; surface nothing, just log the move failure
                }
            }

            $ticket = Maintenance::with(self::EAGER)->findOrFail($ticket->id);
            return ResponseHelper::SuccessResponse(MaintenanceWorkflowResource::make($ticket), 'Car transferred to ' . $vendor->name . ' — awaiting arrival check-in', 200);
        });
    }

    /** Move a fault's status directly (pending / in_progress / completed / cancelled). */
    public function setTaskStatus(Request $request, \App\Models\MaintenanceTask $task)
    {
        return $this->run(function () use ($request, $task) {
            $data = $request->validate([
                'status' => ['required', Rule::in(\App\Models\MaintenanceTask::STATUSES)],
                // Resolution note captured when a fault is marked fixed (the repair video is uploaded
                // separately via POST /{ticket}/video with this task's id, just before this call).
                'note'   => ['nullable', 'string', 'max:2000'],
                // Odometer at the moment of a SCHEDULED routine service (oil change / battery). Used to
                // roll that car's recurring Service Reminder forward when the fault is a routine service;
                // ignored for ordinary faults. Falls back to the ticket/vehicle reading when omitted.
                'odometer' => ['nullable', 'integer', 'min:0'],
            ]);

            // Fix Evidence is MANDATORY to mark a fault fixed: a resolution note + at least one piece of
            // repair media — a VIDEO or a still PHOTO (the client uploads it first, so it's already linked
            // by now). Cancelling / reopening a fault is exempt — only "completed" (fixed) demands evidence.
            if ($data['status'] === \App\Models\MaintenanceTask::STATUS_COMPLETED) {
                if (trim((string) ($data['note'] ?? '')) === '') {
                    throw new \App\Exceptions\WorkflowTransitionException('Add a resolution note before marking the fault fixed.', ['field' => 'note']);
                }
                if (! $task->media()->exists()) {
                    throw new \App\Exceptions\WorkflowTransitionException('Attach a repair photo or video before marking the fault fixed.', ['field' => 'media']);
                }
            }

            $this->tasks->setStatus($task, $data['status'], $request->user(), $data['note'] ?? null, $data['odometer'] ?? null);
            return $this->ticketFor($task, 'Fault status updated');
        });
    }

    /**
     * Delegate DISPUTE — a supervisor (Waleed / Abdullah) rules an inspector-flagged fault a mis-diagnosis
     * while the car is In Workshop. Drops it out of the must-fix set (→ cancelled) but stamps who/why so
     * the override of the inspector's call stays auditable. Reason is mandatory.
     */
    public function markTaskIncorrect(Request $request, \App\Models\MaintenanceTask $task)
    {
        return $this->run(function () use ($request, $task) {
            $data = $request->validate([
                'reason' => ['required', 'string', 'max:2000'],
            ]);

            $this->tasks->markIncorrect($task, $data['reason'], $request->user());
            return $this->ticketFor($task, 'Fault marked incorrect');
        });
    }

    /** Reload a task's parent ticket (with all tasks) and return it, so the UI updates the whole card. */
    private function ticketFor(\App\Models\MaintenanceTask $task, string $message)
    {
        $ticket = Maintenance::with(self::EAGER)->findOrFail($task->maintenance_id);
        return ResponseHelper::SuccessResponse(MaintenanceWorkflowResource::make($ticket), $message, 200);
    }

    private function run(callable $fn)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            // One unified path: ValidationException → 422 (+errors), WorkflowTransitionException → 422
            // (+from→to context), everything else mapped + 5xx logged. See ResponseHelper::fromException.
            return ResponseHelper::fromException($e);
        }
    }
}
