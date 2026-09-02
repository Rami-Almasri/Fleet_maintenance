<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Resources\MaintenanceLineItemResource;
use App\Http\Resources\MaintenanceWorkflowResource;
use App\Models\Contract;
use App\Models\FaultCause;
use App\Models\InspectionRecord;
use App\Models\Maintenance;
use App\Models\MaintenanceHandover;
use App\Models\MaintenanceIncident;
use App\Models\MaintenanceTask;
use App\Models\MaintenanceTemporaryRelease;
use App\Models\Vehicle;
use App\Models\VehicleLogEvent;
use App\Services\ActivityFeedService;
use App\Services\MaintenanceAnalyticsService;
use App\Services\MaintenanceWorkflowService;
use App\Services\PlateResolver;
use App\Services\ReviewReminderService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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
     * Upper bound (km) for any odometer reading captured through the workflow. The odometer columns are
     * unsignedInteger (max ~4.29 billion); a real vehicle reading sits far below this, so we cap inputs
     * well under the column limit. This turns a fat-fingered / garbage value into a clean 422 validation
     * error instead of a raw "SQLSTATE[22003] Out of range value" 500 at write time.
     *
     * LOWERED from 9,999,999 (2026-08-01). That bound was three orders of magnitude above anything this
     * fleet will ever read, so it caught nothing: 6,276,888 km and 10,002,122 km were both accepted and
     * written through, and one vehicle ended up holding 9,999,999 — the cap itself, which is what a
     * "type the biggest number you can" input looks like. The highest genuine reading in the fleet is
     * ~113,000 km; 1,000,000 stays generous for a long-lived vehicle while making a digit slip visible.
     *
     * This is only the ABSOLUTE bound. The relative one — how far a reading may jump from the previous
     * one — is OdometerContinuityService::MAX_JUMP_KM, and that is the guard that actually does the work,
     * because a plausible-looking absolute value can still be an impossible journey.
     */
    public const MAX_ODOMETER = 1_000_000;

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
        // Paused — Returned to Service — the repair is on hold while the car is released back into
        // service. Its own lane so these cars are visible and one "Resume Maintenance" click sends them
        // back into the pipeline. Split from "Returned – Waiting Resume" in board() below by whether the
        // vehicle has been marked physically returned yet (vehicle_returned_at) — both share this one
        // workflow_status, so the split happens in the query, not this status map.
        'paused' => [Maintenance::WF_PAUSED_RETURNED_TO_SERVICE],
    ];

    // `vehicle.odometer` is selected because it IS the anchor the transfer/release odometer gates compare
    // against server-side; without it the resource's `vehicle_odometer` silently serializes as null and the
    // modal falls back to a stale ticket-chain reading, contradicting the server's own verdict.
    private const EAGER = ['vendor', 'transferToVendor:id,name', 'vehicle:id,plate_no,make,model,operational_status,odometer', 'inspector:id,name', 'requester:id,name', 'assignedDriver:id,name', 'delegatedBy:id,name', 'recommendationReviewer:id,name', 'watchers:id,name', 'linkedContract:id,contract_no', 'lineItems',
        // Multi-garage routing: the ticket's faults, each with its garage-stint timeline + current garage.
        // lastFailedVendor drives the "Unresolved at Garage X" blame badge on a re-inspection failure.
        'tasks.assignments.vendor:id,name', 'tasks.assignments.assignedBy:id,name', 'tasks.assignments.releasedBy:id,name',
        // The active-work ledger rides along with the stints: FaultRepairTimeService::forTask() reads it
        // for every fault on the card, so loading it here is what keeps that a single query instead of
        // one per fault.
        'tasks.workSessions',
        'tasks.currentVendor:id,name', 'tasks.lastFailedVendor:id,name', 'tasks.media', 'tasks.markedIncorrectBy:id,name',
        // Event Type layer — the catalog row each fault/service/inspection was typed from, so the resource
        // can ship `catalog` (and resolve a service's reminder type) without an N+1 per task.
        // `category_key` + `location_mode` ride along because the resource answers "does this fault type
        // take a place, and is it required?" per task; resolving that lazily would N+1 the drawer.
        'tasks.faultCatalog:id,slug,name,category_key,location_mode', 'tasks.serviceCatalog:id,slug,name,service_reminder_type', 'tasks.inspectionType:id,slug,name',
        'tasks.damageCatalog:id,slug,name,category_key,location_mode',
        // WHERE each fault is — the per-fault places, with the vocabulary row each points at.
        'tasks.locations.location:id,slug,name,name_ar,group_key,precision,inspection_zone',
        // Per-fault parts (Parts Purchase workflow) — drawer/command only, so opening a fault lists its parts.
        // `partRequests` (ticket-level) additionally catches requests raised with no fault attached.
        'tasks.partRequests', 'partRequests',
        // Execution layer: the ticket's currently-open MOVE (transit) — drives the unified live position.
        'activeMove',
        // Garage Invoice Portal: the one submission awaiting audit — drives the "Awaiting Audit" flag.
        'pendingGarageInvoice',
        // One Ticket → Many Invoices: each garage bill with its garage, covered faults + line breakdown.
        'invoices.vendor:id,name', 'invoices.tasks:id,maintenance_invoice_id,symptom,status,kind', 'invoices.lineItems',
        // WHICH PART a billed line actually fitted — the catalog row, not the wording typed on the paper.
        // Without it the invoice can only print what the biller wrote, so a bill and the parts ledger can
        // name the same part two ways and nothing on screen says they are the same thing.
        'invoices.lineItems.catalogPart:id,name,name_ar,slug,category_key',
        // Enterprise Handover Workflow — the open discrepancy incident (if any), the latest pause/resume
        // custody handovers, and every generated comparison report on the ticket's history.
        'activeIncident.acknowledgedBy:id,name', 'lastPauseHandover', 'lastResumeHandover', 'handoverComparisons',
        // Data-driven garage-choice record — powers the "Why this garage?" card in the ticket history.
        'latestRecommendationDecision.recommendedVendor:id,name', 'latestRecommendationDecision.chosenVendor:id,name', 'latestRecommendationDecision.actor:id,name',
        // Operations card (`ops`) — the State-resolver graph, the progress checkpoints and the follow-up
        // owners, so a fully-hydrated ticket carries the SAME ops block the board does.
        'tasks.partRequests.purchases', 'tasks.partRequests.purchases.sourceVendor:id,name',
        'checkpoints', 'responsibles:id,name', 'pickedUpFromGarageBy:id,name',
        // SYSTEM CHECKS — the obligations the Decide step must resolve. On the hydrated ticket only;
        // the board's own query does not load this relation, so the 141-card queue pays nothing.
        'checkRequirements.inspector:id,name',
        // Temporary Vehicle Release — while the car is out, the release row (and the drivers named on its
        // legs) is what the ticket's whole action surface reads from, so it never lazy-loads.
        'activeTemporaryRelease.outDriver:id,name', 'activeTemporaryRelease.returnDriver:id,name',
        // …and WHO let the car out. "Why is it out of the workshop" is a decision with an owner, so the
        // drawer states the reason next to the person who authorised it.
        'activeTemporaryRelease.releasedBy:id,name',
        // The last three handoffs (Marked ready → Back at base → Closed) carried an actor id but no
        // relation, so the timeline could only print a date for them.
        'readyBy:id,name', 'parkArrivedBy:id,name', 'wfClosedBy:id,name',
        // The OfficeManager maintenance contract this visit was opened under — the paper the whole visit
        // hangs off. The ticket view shows its header (number, customer, window, money) so a reader does
        // not have to leave the ticket to learn what the office recorded for the same visit.
        'contract.customer:id,name_en,name_ar'];

    // Mileage-chain sources kept OUT of the drawer's chain. Purely a display filter: the readings still
    // exist on their source rows (contracts, tickets) and every other consumer — utilization, the mileage
    // baseline, reconciliation — reads them unchanged. Empty this array to show the full chain again.
    private const MILEAGE_CHAIN_HIDDEN = ['contract_out', 'contract_in'];

    /** The standard eager set for a fully-hydrated ticket — reused by the invoice controller's reloads. */
    public static function eagerWith(): array
    {
        return self::EAGER;
    }

    // Lightweight eager set for the BOARD only. The board renders summary cards, not the full ticket —
    // so we load just what a card draws (vehicle, garage, faults + their current/last-failed garage,
    // the driver/delegation names, and the live-position move) and DROP the heavy drawer-only relations
    // (invoices + their line items, ticket line items, per-fault assignment stints, handover
    // comparisons, incidents, watchers, pending garage invoice). Every dropped relation is rendered
    // behind whenLoaded()/relationLoaded() in the resources, so it cleanly serializes as null/empty
    // here with no lazy N+1 — and the drawer refetches the fully-hydrated ticket (self::EAGER) when it
    // opens. Cheap belongsTo(:id,name) loads are kept so no card field ever silently drops.
    private const BOARD_EAGER = [
        'vendor', 'transferToVendor:id,name',
        'vehicle:id,plate_no,make,model,operational_status,odometer',
        'inspector:id,name', 'requester:id,name', 'pickedUpFromGarageBy:id,name',
        'assignedDriver:id,name', 'delegatedBy:id,name',
        'recommendationReviewer:id,name', 'linkedContract:id,contract_no',
        'tasks.currentVendor:id,name', 'tasks.lastFailedVendor:id,name',
        // Event Type layer — the card renders a type pill and filters by kind, so the catalog each task
        // was typed from is loaded once for the whole board rather than per card.
        'tasks.faultCatalog:id,slug,name,category_key,location_mode', 'tasks.serviceCatalog:id,slug,name,service_reminder_type', 'tasks.inspectionType:id,slug,name',
        'tasks.damageCatalog:id,slug,name,category_key,location_mode',
        // WHERE each fault is — loaded once for the whole board so a card can print "2 scratches —
        // rims and body" without a query per fault.
        'tasks.locations.location:id,slug,name,name_ar,group_key,precision,inspection_zone',
        // Part requests per fault — so the board (and the Car Status stage board) can show whether a car
        // is still waiting on a part, without a second round-trip to the Parts board.
        // Operations Dashboard (Car Status) — the graph the State resolvers read (faults → part requests
        // → purchases → supplier) plus the progress checkpoints and the ticket's standing follow-up
        // owners. Loaded here, once for the whole board, because WorkflowStateResolver /
        // MaintenanceDelayResolver are pure readers that never eager-load themselves. See
        // MaintenanceOpsCardService.
        'tasks.partRequests', 'tasks.partRequests.purchases', 'tasks.partRequests.purchases.sourceVendor:id,name',
        // Ticket-level requests too — a part raised against the ticket with no fault attached would
        // otherwise never reach the card (the per-fault walk above can't see it). Their purchases come
        // along because the card's parts badge asks isOutstanding(), which reads delivered_at/installed_at
        // off the purchase — without this the board would lazy-load one query per request.
        'partRequests', 'partRequests.purchases',
        'checkpoints', 'responsibles:id,name',
        'activeMove',
        // A released card's next action, its lane and its custody gate all come off the release row.
        'activeTemporaryRelease.outDriver:id,name', 'activeTemporaryRelease.returnDriver:id,name',
    ];

    public function __construct(
        private MaintenanceWorkflowService $workflow,
        private \App\Services\MaintenanceTaskService $tasks,
        private \App\Services\MaintenanceForecastService $forecast,
        private \App\Services\RepairInspectionService $inspections,
        private \App\Services\MaintenanceRequiredPartService $requiredParts,
        // Approve/reject a finding the car's own data disagrees with — the other end of the hold.
        private \App\Services\FindingApprovalService $approvals,
        // Turns the workflow steps below into immutable domain events. The technician never sees it,
        // never enters anything extra for it, and a failure inside it can never fail their work —
        // see [[CaptureTranslator]].
        private \App\Evidence\Capture\CaptureTranslator $capture,
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
        // Shipped WITH the catalog rather than fetched per selection. The inspector taps four or five
        // chips in a row on a phone in a yard; four or five round trips to say "this is usually worn
        // spark plugs" would arrive after they had already moved on. The whole library is ~105 concepts
        // and the picker already fetches this once per modal.
        //
        // `causes` / `fixes` come from RepairOutlook so the inspector's picker, the supervisor's
        // dispatch plan and the AI suggestion card cannot answer the same question differently.
        $keywordRisk = \App\Models\FindingKeyword::active()
            ->with(['profile:id,finding_keyword_id,likely_causes', 'repairActions:id,label,label_ar'])
            ->get(['id', 'keyword', 'keyword_ar', 'risk'])
            ->keyBy('keyword')
            ->map(fn ($k) => \App\Models\FindingKeyword::riskMeta($k->risk) + [
                'risk'   => $k->risk,
                'ar'     => $k->keyword_ar,
                'causes' => \App\Services\Garage\RepairOutlook::causesOf($k),
                'fixes'  => \App\Services\Garage\RepairOutlook::fixesOf($k),
            ]);

        // WHERE ON THE CAR — the shared location vocabulary, shipped WITH the findings catalog rather
        // than behind its own request for the same reason keyword_risk is: the picker needs it the
        // instant a chip is tapped, and the modal already makes this one call. `location_policy` tells
        // the client which keywords take a place at all (required / optional / none), so a fault type
        // with no location — a wiper fault, an overheating engine — never shows a picker nobody can
        // answer. Both are derived from config + the catalogs; neither is hard-coded per fault type.
        $locations = app(\App\Services\FaultLocationService::class);

        // WHAT KIND OF WORK IS THIS WORD? — resolved for every selectable keyword, shipped with the
        // catalog, so the picker can say "Service" or "Fault" on the chip ITSELF rather than the
        // answer only becoming visible after the task exists.
        //
        // The distinction is real work, not decoration: a service is planned upkeep falling due, a
        // fault is a claim the car failed — and only the fault feeds Top Faults, recurrence and the
        // health score (see the two-menus note below). An inspector picking "Oil Change" and one
        // picking "Engine noise" are doing different things, and until now the screen looked identical
        // for both. Resolved through EventClassificationService — the SAME resolver syncFromFindings()
        // uses — so the badge cannot disagree with the task that is eventually created.
        //
        // One pass over ~200 keywords against maps the classifier builds once; the modal already makes
        // this call exactly once.
        // MERGED INTO keyword_risk rather than shipped as a fourth map: every picker in the app
        // already receives that one (three separate fetch sites), so a keyword's kind reaches all of
        // them without threading a new prop through screens this change has no other business
        // touching. Entries are CREATED for catalog words the keyword library has no row for, so
        // coverage is the catalog's, not the library's.
        $classifier  = app(\App\Services\EventClassificationService::class);
        $keywordRisk = $keywordRisk->toArray();

        foreach ((array) config('maintenance_findings.categories', []) as $category) {
            foreach ($category['keywords'] ?? [] as $keyword) {
                $kind = $classifier->classifyFromFinding(['text' => $keyword])['kind'] ?? null;

                $keywordRisk[$keyword] = ($keywordRisk[$keyword] ?? []) + [];
                // Null is honest and NOT hidden. A keyword neither catalog recognises is promoted by
                // the legacy shield, which DEFAULTS it to fault — so omitting the badge would let a
                // word quietly become a fault while looking like a considered choice.
                $keywordRisk[$keyword]['kind']       = $kind;
                $keywordRisk[$keyword]['kind_label'] = $kind ? (MaintenanceTask::KIND_META[$kind]['label'] ?? $kind) : null;
            }
        }

        return ResponseHelper::SuccessResponse(
            [
                'categories'        => array_values(config('maintenance_findings.categories', [])),
                // Each category carries an `on_site` flag; these tokens (battery/oil) additionally force
                // an On-Site suggestion + surface in the On-Site checklist even from an In-Shop category.
                'on_site_keywords'  => array_values(config('maintenance_findings.on_site_keywords', [])),
                'maintenance_types' => $types,
                // Keyed by normalised symptom (FaultCause::normalizeKey) → [{ id, root_cause, description }]
                'fault_causes'      => $faultCauses,
                // Keyed by keyword string → { risk, label, tone, emoji, rank } from the active library,
                // PLUS { kind, kind_label } for every catalog word — service | fault | damage, or null
                // when neither catalog recognises it (see the classification block above).
                'keyword_risk'      => $keywordRisk,
                // Grouped "where on the car" vocabulary → [{ key, label, label_ar, locations[] }]
                'locations'         => $locations->groupedCatalog(),
                // Keyed by keyword string → 'required' | 'optional' | 'none'
                'location_policy'   => $locations->policyByKeyword((array) config('maintenance_findings.categories', [])),
                'max_quantity'      => $locations->maxQuantity(),
            ],
            'Findings catalog retrieved successfully',
            200
        );
    }

    /**
     * Everything the Send-a-car-in form needs that does NOT depend on which car is picked, in one call:
     * the fault vocabulary it may offer, the two reason lists, and who the filer is.
     *
     * WHO IS FILING IS NOT A FIELD. It is read from the authenticated user and returned here purely so
     * the form can SHOW it — there is no picker, and the server stamps `requested_by` from the token
     * regardless of anything the client sends. A request whose author can be typed in is a request whose
     * author cannot be trusted.
     *
     * TWO MENUS, NOT ONE MERGED LIST. The fault menu is the live FaultCatalog (kind = fault); the service
     * menu is the live ServiceCatalog (kind = service). Both are grouped by category with the labels from
     * config/maintenance_findings — SELECTABLE vocabulary only, per [[findings-vocabulary-contract]].
     *
     * Two LISTS, one PICKER. They are returned separately because "Oil Change" is not something that went
     * wrong — a fault is a claim the car failed and feeds Top Faults, recurrence and the health score,
     * while a service is planned work falling due and feeds none of them (see
     * docs/Service-vs-Fault-Domain-Separation.md). The form then shows them in a single searchable list
     * with the service groups badged, exactly as the Inspector's findings picker does: what keeps the two
     * apart downstream is each row's catalog identity, not which menu it was tapped in.
     *
     * Both doors accept either kind, and both may be named together. `interval_km` / `interval_months`
     * ride along because "every 10,000 km · 6 months" is the cadence the person is answering against.
     */
    public function requestOptions(Request $request)
    {
        return $this->run(function () use ($request) {
            $labels = collect(config('maintenance_findings.categories', []))
                ->keyBy('key')
                ->map(fn ($c) => ['label' => $c['label'] ?? null, 'label_ar' => $c['label_ar'] ?? null]);

            $groups = \App\Models\FaultCatalog::active()->ordered()
                ->get(['id', 'slug', 'name', 'name_ar', 'category_key', 'default_severity', 'on_site'])
                ->groupBy('category_key')
                ->map(fn ($rows, $key) => [
                    'key'      => $key,
                    'label'    => $labels[$key]['label'] ?? Str::title(str_replace('_', ' ', (string) $key)),
                    'label_ar' => $labels[$key]['label_ar'] ?? null,
                    'faults'   => $rows->map(fn ($f) => [
                        'id'               => $f->id,
                        'slug'             => $f->slug,
                        'name'             => $f->name,
                        'name_ar'          => $f->name_ar,
                        'category_key'     => $f->category_key,
                        'default_severity' => $f->default_severity,
                        'on_site'          => (bool) $f->on_site,
                    ])->values(),
                ])
                ->values();

            // THE SERVICE MENU — planned/preventive work, same shape as the fault groups so the picker is
            // one component. `interval_km` / `interval_months` ride along because "Oil Change · every
            // 10,000 km" is the cadence the person is answering against, and it is a FACT off the catalog
            // row rather than anything computed about this car.
            $serviceGroups = \App\Models\ServiceCatalog::active()->ordered()
                ->get(['id', 'slug', 'name', 'name_ar', 'category_key', 'interval_km', 'interval_months'])
                ->groupBy('category_key')
                ->map(fn ($rows, $key) => [
                    'key'      => $key,
                    'label'    => $labels[$key]['label'] ?? Str::title(str_replace('_', ' ', (string) $key)),
                    'label_ar' => $labels[$key]['label_ar'] ?? null,
                    'services' => $rows->map(fn ($s) => [
                        'id'              => $s->id,
                        'slug'            => $s->slug,
                        'name'            => $s->name,
                        'name_ar'         => $s->name_ar,
                        'category_key'    => $s->category_key,
                        'interval_km'     => $s->interval_km,
                        'interval_months' => $s->interval_months,
                    ])->values(),
                ])
                ->values();

            // WHAT A NAMED FAULT USUALLY TURNS OUT TO BE — the curated Symptom → Root-Cause short-list
            // (FaultCause, approved rows only), keyed by the same normalised symptom the Diagnosis step
            // keys it by, so the intake form and the Inspector's picker can never quote different lists.
            //
            // SELECTABLE at intake: the requester may name the cause they suspect, and it is stored beside
            // their fault as `suspected_cause` — a claim, exactly as the fault name is a claim. It does not
            // diagnose the car; the Inspector still decides at the Decide step from this same short-list.
            // (The one exception is the straight-to-garage door, where the person holds diagnostic
            // authority and no inspector follows, so the pick lands on the finding itself.)
            //
            // The `id` is what rides back, because the code is the stored fact and the words are only how
            // it reads. `fault_catalog_id` is NOT used to join — it is unpopulated on every row;
            // `symptom_key` is the live key.
            $causes = \App\Models\FaultCause::approved()
                ->orderBy('root_cause')
                ->get(['id', 'symptom_key', 'root_cause', 'description'])
                ->groupBy('symptom_key')
                ->map(fn ($rows) => $rows->map(fn ($c) => array_filter([
                    'id'          => $c->id,
                    'root_cause'  => $c->root_cause,
                    'description' => $c->description,
                ], fn ($v) => $v !== null && $v !== ''))->values());

            $user = $request->user();

            return ResponseHelper::SuccessResponse([
                'fault_groups'   => $groups,
                // Planned work, kept in its own list for the reason spelled out above.
                'service_groups' => $serviceGroups,
                'fault_causes'   => $causes,
                // CODE => label. The code is the stored fact; the label is presentation and the client is
                // free to render its own translation instead (see [[reason-code-contract]]).
                'reasons'      => [
                    'inspection' => Maintenance::REQUEST_REASONS_INSPECTION,
                    'dispatch'   => Maintenance::REQUEST_REASONS_DISPATCH,
                ],
                // Read-only identity for the "Filed by" line — never an input.
                'filed_by'     => ['id' => $user?->id, 'name' => $user?->name],
                // WHICH DOORS THIS PERSON MAY ACTUALLY USE. The routes enforce both; these flags only
                // stop a door being offered to somebody whose submit would come back 403. They mirror
                // POST /request (logistics), POST /request-inspection (manage) and POST /direct-dispatch
                // (initiate|manage) — change one there, change it here.
                'can_request'  => (bool) ($user?->can('maintenance.logistics') || $user?->can('maintenance.manage')),
                'can_dispatch' => (bool) ($user?->can('maintenance.initiate') || $user?->can('maintenance.manage')),
            ], 'Request options retrieved', 200);
        });
    }

    /**
     * "Is it this again?" — the faults THIS car has already been in the shop for, so the person filling
     * in the form is offered their own car's history before a generic catalog.
     *
     * Every row is a FACT off the record (what, when, which ticket, which garage, was it fixed) plus one
     * derived flag: `within_recurrence_window` says the fault was last FIXED inside the configured
     * window. It does not say the fault has returned — nobody knows that yet, and only the workshop's
     * confirmation ever establishes it (see RecurringFaultService). Picking one stamps
     * `repeat_of_ticket_id` on the request: the requester's claim, recorded as theirs.
     */
    public function vehicleRecentFaults(Request $request, Vehicle $vehicle)
    {
        return $this->run(function () use ($vehicle) {
            return ResponseHelper::SuccessResponse(
                ['faults' => $this->workflow->recentFaultsFor($vehicle)],
                'Recent faults retrieved',
                200
            );
        });
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

    /**
     * Fixed & Completed Repairs — every repair this fleet has finished, from BOTH data origins:
     *
     *   source = 'system' — an app workflow ticket signed off through the lifecycle (workflow_status
     *                       = closed). Carries the full story: who requested it, who drove it, the
     *                       per-fault root cause / resolution / parts, the odometer chain and the cost.
     *   source = 'sheet'  — a historical workshop event imported from the Google Sheet (origin =
     *                       sheet / customer-sheet) whose car CAME BACK (actual_in_date is set). No
     *                       people chain exists for these — the sheet never recorded one — so they
     *                       carry only what the sheet holds: service, garage, dates, cost.
     *
     * Newest-completed first, the two origins merged into one ledger. Filter with ?source=all|system|
     * sheet, ?search= (plate / car / garage), ?vehicle_id= and ?limit=. Backs the /completed-repairs
     * page and the Dashboard's "Recently Fixed" card.
     */
    public function completed(Request $request)
    {
        try {
            $q      = trim((string) $request->query('search', ''));
            $source = (string) $request->query('source', 'all');   // all | system | sheet
            // The ledger is capped at 500; ?limit= lets a compact surface (the Dashboard's "Recently
            // Fixed" card) ask for just the newest handful instead of the whole set.
            $limit  = max(1, min(500, (int) $request->query('limit', 500)));

            $rows = collect();

            // A) SYSTEM — tickets signed off through the app's own workflow.
            if ($source !== 'sheet') {
                $tickets = Maintenance::workflowTickets()
                    ->where('workflow_status', Maintenance::WF_CLOSED)
                    ->with(self::EAGER)->withCount('media')
                    ->when($request->filled('vehicle_id'), fn ($qq) => $qq->where('vehicle_id', $request->integer('vehicle_id')))
                    ->when($q !== '', function ($qq) use ($q) {
                        $qq->where(function ($w) use ($q) {
                            $w->where('plate', 'like', "%{$q}%")
                              ->orWhere('car_label', 'like', "%{$q}%")
                              ->orWhere('garage', 'like', "%{$q}%")
                              ->orWhereHas('vehicle', fn ($v) => $v->where('plate_no', 'like', "%{$q}%"))
                              ->orWhereHas('vendor', fn ($v) => $v->where('name', 'like', "%{$q}%"));
                        });
                    })
                    ->orderByDesc('wf_closed_at')
                    ->orderByDesc('id')
                    ->limit($limit)
                    ->get();

                $rows = $rows->concat(
                    collect(MaintenanceWorkflowResource::collection($tickets)->toArray($request))
                        ->map(fn (array $r) => array_merge($r, [
                            'source' => 'system',
                            // One canonical "when was this finished" key both origins fill, so the ledger
                            // sorts + date-filters on ONE field. Falls back for a legacy closed ticket
                            // whose wf_closed_at was never stamped.
                            'completed_at' => $r['handoffs']['closed']['at']
                                ?? ($r['actual_in_date'] ? $r['actual_in_date'] . 'T00:00:00' : null)
                                ?? $r['updated_at'] ?? null,
                        ]))
                );
            }

            // B) SHEET — CONTRACT-ANCHORED. A sheet repair is finished when its type-'U' maintenance
            // CONTRACT is closed (in_date set) — the contract, not the sheet row, is the authority on
            // when the visit ended (the sheet's actual_in_date is a hand-typed field that is often
            // blank or stale). One row per closed contract; the workshop-log events matched to that
            // contract window supply WHAT was done. A contract already owned by an app workflow ticket
            // is skipped — that repair belongs to the System lane.
            if ($source !== 'system') {
                $contracts = Contract::query()
                    ->where('contract_type', 'U')
                    ->whereNotNull('in_date')       // CLOSED — the visit is over
                    ->whereNotNull('out_date')      // …and we know when it started
                    ->whereNotIn('id', Maintenance::workflowTickets()->whereNotNull('linked_contract_id')->select('linked_contract_id'))
                    ->with('vehicle:id,plate_no,make,model')
                    ->when($request->filled('vehicle_id'), fn ($qq) => $qq->where('vehicle_id', $request->integer('vehicle_id')))
                    // The garage / service text lives on the linked EVENTS, not the contract — so the
                    // event match is correlated to THIS contract's window (same rule as the join
                    // below). Matching at vehicle level instead would return every later visit of any
                    // car that once had, say, a brake job.
                    ->when($q !== '', function ($qq) use ($q) {
                        $qq->where(function ($w) use ($q) {
                            $w->where('contract_no', 'like', "%{$q}%")
                              ->orWhereHas('vehicle', fn ($v) => $v->where('plate_no', 'like', "%{$q}%")
                                  ->orWhere('make', 'like', "%{$q}%")
                                  ->orWhere('model', 'like', "%{$q}%"))
                              ->orWhereExists(fn ($sub) => $sub
                                  ->from('maintenances as m')
                                  ->selectRaw('1')
                                  ->whereColumn('m.vehicle_id', 'contracts.vehicle_id')
                                  ->whereIn('m.origin', Maintenance::WORKSHOP_LOG_ORIGINS)
                                  ->whereNull('m.workflow_status')
                                  ->whereNotNull('m.out_date')
                                  ->whereRaw('m.out_date >= DATE_SUB(contracts.out_date, INTERVAL ' . (int) MaintenanceAnalyticsService::LINK_BUFFER_DAYS . ' DAY)')
                                  ->whereColumn('m.out_date', '<=', 'contracts.in_date')
                                  ->where(fn ($e) => $e->where('m.garage', 'like', "%{$q}%")
                                      ->orWhere('m.service_main', 'like', "%{$q}%")
                                      ->orWhere('m.service_sup', 'like', "%{$q}%")));
                        });
                    })
                    ->orderByDesc('in_date')
                    ->orderByDesc('id')
                    ->limit($limit)
                    ->get();

                $events = $this->linkedWorkshopEvents($contracts);
                $rows   = $rows->concat($contracts->map(
                    fn (Contract $c) => $this->contractRepairRow($c, $events[$c->id] ?? collect())
                ));
            }

            // One ledger, newest-completed first, then capped — so "the newest N repairs" means the same
            // thing whichever origins are in play.
            $merged = $rows
                ->sortByDesc(fn (array $r) => $r['completed_at'] ?? '')
                ->take($limit)
                ->values();

            return ResponseHelper::SuccessResponse(
                [
                    'tickets' => $merged,
                    'total'   => $merged->count(),
                    'counts'  => [
                        'system' => $merged->where('source', 'system')->count(),
                        'sheet'  => $merged->where('source', 'sheet')->count(),
                    ],
                ],
                'Completed repairs retrieved successfully',
                200
            );
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * The workshop-log events belonging to each closed maintenance contract, by the SAME strict rule
     * MaintenanceAnalyticsService::linkedSheetEvents() uses — vehicle match, event out_date within
     * [contract out_date − LINK_BUFFER_DAYS, contract in_date] — so this ledger can never disagree
     * with the maintenance board about which visit a sheet row belongs to.
     *
     * Done as one bounded id-join (then a single hydrate of exactly the matched events) rather than by
     * calling the service directly, which loads EVERY event for every vehicle involved — fine for one
     * board, far too heavy for a 500-contract ledger page.
     *
     * @param  \Illuminate\Support\Collection<int,Contract>  $contracts
     * @return array<int,\Illuminate\Support\Collection<int,Maintenance>>  keyed by contract id, chronological
     */
    private function linkedWorkshopEvents($contracts): array
    {
        $contracts = collect($contracts);
        if ($contracts->isEmpty()) {
            return [];
        }

        $pairs = DB::table('contracts as c')
            ->join('maintenances as m', function ($j) {
                $j->on('m.vehicle_id', '=', 'c.vehicle_id')
                  ->whereIn('m.origin', Maintenance::WORKSHOP_LOG_ORIGINS)
                  ->whereNull('m.workflow_status')      // an app ticket is never sheet history
                  ->whereNotNull('m.out_date')
                  ->whereRaw('m.out_date >= DATE_SUB(c.out_date, INTERVAL ? DAY)', [MaintenanceAnalyticsService::LINK_BUFFER_DAYS])
                  ->whereColumn('m.out_date', '<=', 'c.in_date');   // Hard Cutoff: the contract's close
            })
            ->whereIn('c.id', $contracts->pluck('id'))
            ->orderBy('m.out_date')->orderBy('m.id')
            ->get(['c.id as contract_id', 'm.id as event_id']);

        if ($pairs->isEmpty()) {
            return [];
        }

        $byId = Maintenance::with('vendor:id,name')
            ->whereIn('id', $pairs->pluck('event_id')->unique())
            ->get()
            ->keyBy('id');

        $out = [];
        foreach ($pairs as $p) {
            if ($e = $byId->get($p->event_id)) {
                $out[$p->contract_id][] = $e;
            }
        }

        return array_map(fn (array $seq) => collect($seq), $out);
    }

    /**
     * One CLOSED maintenance contract as a completed-repair row: the contract owns the timeline (out →
     * in, and therefore the completion date + the downtime), while its matched workshop-log events
     * supply what was actually done — garage, services, spare part, invoice, cost.
     *
     * Only what the data really holds is filled. There is no people chain, no odometer chain and no
     * per-fault breakdown in the sheet, and inventing them would be a lie; a contract with no matching
     * event is still returned (the visit demonstrably happened and closed) but flagged `has_sheet_log
     * = false` so the UI can say so instead of implying a silent gap.
     *
     * @param  \Illuminate\Support\Collection<int,Maintenance>  $events
     */
    private function contractRepairRow(Contract $c, $events): array
    {
        $events = collect($events);
        $last   = $events->last();                       // the closing event = the latest in the window

        $out = $c->out_date;
        $in  = $c->in_date;                              // ← THE completion date: the contract's close

        // What was done: every service line across the visit's events, de-duplicated, in order.
        $services = $events
            ->flatMap(fn (Maintenance $e) => preg_split('/\s*,\s*/', trim(($e->service_main ?? '') . ',' . ($e->service_sup ?? '')), -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn ($s) => trim($s))
            ->filter()
            ->unique()
            ->values();

        $garages = $events
            ->map(fn (Maintenance $e) => $e->vendor?->name ?: $e->garage)
            ->filter()->unique()->values();

        $costs = $events->pluck('cost')->filter(fn ($v) => $v !== null);

        return [
            // Namespaced id — a contract row and a ticket row can share a numeric id, and the merged
            // ledger is keyed by this in the UI.
            'id'          => 'c' . $c->id,
            'contract_id' => $c->id,
            'contract_no' => $c->contract_no,
            'source'      => 'sheet',
            'vehicle_id'  => $c->vehicle_id,
            'plate'       => $c->vehicle?->plate_no,
            'car'         => trim(($c->vehicle?->make ?? '') . ' ' . ($c->vehicle?->model ?? '')) ?: null,
            'garage'      => $garages->join(' → ') ?: null,
            'cost'        => $costs->isNotEmpty() ? (float) $costs->sum() : null,

            // The sheet records the SERVICE performed, which is the closest thing it has to a complaint.
            'customer_complaint'     => $services->first(),
            'maintenance_type'       => $last?->maintenance_type,
            'maintenance_type_label' => $last?->maintenance_type ? (Maintenance::MAINTENANCE_TYPES[$last->maintenance_type] ?? $last->maintenance_type) : null,
            'findings'               => $services->map(fn ($s) => ['symptom' => $s, 'source' => 'sheet'])->all(),
            'tasks'                  => [],
            'severity'               => $last?->severity,
            'maintenance_notes'      => $last?->maintenance_notes,
            'invoice_no'             => $last?->invoice_no,
            'spare_part'             => $last?->spare_part,
            'driver'                 => $last?->driver,
            'event_status'           => $last?->event_status,
            'requested_by_name'      => null,

            // Traceability: how much of this row is backed by a workshop log, and how many garage trips
            // the visit took (an OUT → IN → OUT ping-pong is a real, visible fact).
            'has_sheet_log' => $events->isNotEmpty(),
            'event_count'   => $events->count(),
            'trips'         => $events->where('event_status', 'IN')->count(),

            'out_date'       => optional($out)->toDateString(),
            'actual_in_date' => optional($last?->actual_in_date)->toDateString(),
            'contract_in'    => optional($in)->toDateString(),
            'completed_at'   => optional($in)->toIso8601String(),
            // The only "handoff" that exists here is the close — and the contract names no actor.
            'handoffs'       => ['closed' => ['user_id' => null, 'name' => null, 'at' => optional($in)->toIso8601String()]],
            // Downtime from the CONTRACT window — the same out→in span Fleet Utilization counts, so the
            // ledger and the utilization report can never quote different numbers for the same visit.
            'stage_timing'   => ['durations' => [
                'total_downtime' => ($out && $in) ? max(0, $in->getTimestamp() - $out->getTimestamp()) : null,
            ]],
            'created_at'     => optional($out)->toIso8601String(),
            'updated_at'     => optional($c->updated_at)->toIso8601String(),
        ];
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
                // Newest FIRST inside every lane. The board is read top-down all day, so the card someone
                // just moved here has to be the one you see without scrolling — the old ascending order
                // buried today's work under months of older tickets in the busy lanes. This orders the
                // cards WITHIN each column; the columns themselves still follow the lifecycle (COLUMNS).
                //
                // "Newest" means most recently ARRIVED IN THIS STAGE (last_state_change_at), not
                // most recently created: a lane is a waiting room, and the question its reader is asking
                // is "what just landed in front of me". An old ticket that has just been re-dispatched is
                // new to the lane it now sits in, and sorting by id would hide it near the bottom. Falls
                // back to id for legacy rows that pre-date the stamp (NULLs sort last, then newest id).
                ->with(self::BOARD_EAGER)->withCount('media')
                ->orderByRaw('last_state_change_at IS NULL, last_state_change_at DESC')
                ->orderByDesc('id')
                ->get();

            // Temporary Vehicle Release — while a car is out, its ticket's workflow_status is FROZEN at the
            // repair stage it left (it is still "In Workshop" as far as the repair is concerned), but the
            // car itself is being driven somewhere, parked, and driven back. The board follows the CAR: a
            // released ticket is lifted out of its frozen lane and dropped into the lane its release leg is
            // at (Needs Dispatch → Awaiting Pickup → En Route → Returned — Resume Due → and round again for
            // the way back). See MaintenanceTemporaryRelease::STAGE_LANES.
            $released = $open->filter(fn ($t) => $t->activeTemporaryRelease?->laneKey() !== null);
            $open     = $open->reject(fn ($t) => $t->activeTemporaryRelease?->laneKey() !== null)->values();

            $columns = [];
            $counts  = [];
            foreach (self::COLUMNS as $key => $states) {
                $bucket = $open->whereIn('workflow_status', $states)->values();
                // Paused — Returned to Service splits into two lanes on ONE workflow_status: still out
                // with the customer/operation ('paused') vs physically back, handover paperwork due
                // ('returned_waiting_resume', added below) — see Maintenance::isPausedOut()/
                // isReturnedPendingHandover().
                if ($key === 'paused') {
                    $bucket = $bucket->whereNull('vehicle_returned_at')->values();
                }
                $bucket = $bucket->concat($released->filter(fn ($t) => $t->activeTemporaryRelease->laneKey() === $key))->values();
                $columns[$key] = MaintenanceWorkflowResource::collection($bucket);
                $counts[$key]  = $bucket->count();
            }

            // Returned – Waiting Resume — the vehicle is physically back but the resume handover hasn't
            // cleared yet (an open incident, if any, stays in this same lane — it isn't a separate 8th lane).
            // A released car parked at its destination waits in this same lane: same shape of wait — the car
            // is somewhere else and the repair can't move until it comes back.
            $returnedWaitingResume = $open
                ->whereIn('workflow_status', [Maintenance::WF_PAUSED_RETURNED_TO_SERVICE])
                ->whereNotNull('vehicle_returned_at')
                ->concat($released->filter(fn ($t) => $t->activeTemporaryRelease->laneKey() === 'returned_waiting_resume'))
                ->values();
            $columns['returned_waiting_resume'] = MaintenanceWorkflowResource::collection($returnedWaitingResume);
            $counts['returned_waiting_resume']  = $returnedWaitingResume->count();

            // Every live ticket, including the ones lifted into a release lane above.
            $counts['open_total'] = $open->count() + $released->count();

            // "Completed today" — workflow tickets that reached their closing timestamp since midnight
            // (car back in the fleet). Feeds the board's Workshop-Control strip; a single cheap count,
            // not a serialized collection, so it adds nothing to the board payload weight.
            $counts['completed_today'] = Maintenance::workflowTickets()
                ->whereDate('wf_closed_at', now()->toDateString())
                ->count();

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
     * PLATE LOOKUP — "type a plate, tell me where that car is."
     *
     * Answers ONE question for the ⌘K palette: for the car wearing this plate, is there a live record
     * on the two pages where a car is actually being worked on, and if so, WHICH record — so the search
     * can drop the user on that exact card instead of on a page they then have to scan by eye.
     *
     *   • /maintenance-workflow  → an open workflow ticket, deep-linked as /maintenance-workflow/{id}
     *                              (the ticket's own command view).
     *   • /inspection-review     → a request sitting in the Controller's approval gate, deep-linked as
     *                              /inspection-review?ticket={id} (the queue scrolls to that card).
     *
     * Only lanes that the board ACTUALLY RENDERS count as "on /maintenance-workflow" (self::COLUMNS).
     * A ticket parked in awaiting_invoice lives on the invoice tracker, not the repair pipeline, and
     * saying "it's on the board" about it would send someone looking for a card that isn't drawn.
     *
     * The review side reuses reviewQueue()'s OWN source (MaintenanceWorkflowService::pendingReview) rather
     * than re-deriving the predicate here. That query withdraws requests reality has already answered, so
     * a second hand-written copy of it would eventually promise a card the queue has since dropped. It is
     * the expensive half, so it only runs when there is a matched car AND the searcher may open that page
     * at all (`maintenance.manage` — the same gate as the route).
     *
     * Plates are matched on DIGITS (PlateResolver::plateDigits), so "K 19397" / "19397" / "0019397" are
     * one plate. An exact digit match wins; a shorter entry is treated as a prefix so a half-typed plate
     * still narrows, and each distinct plate resolves through PlateResolver to the CURRENT car — never the
     * sold history row that shares its number.
     */
    public function plateLocator(Request $request)
    {
        return $this->run(function () use ($request) {
            $raw    = trim((string) $request->query('plate', ''));
            $digits = PlateResolver::plateDigits($raw);

            // Two digits match half the fleet — that is a filter, not an answer. Below the floor we say
            // "nothing", never a wall of cars.
            if (strlen($digits) < 3) {
                return ResponseHelper::SuccessResponse(
                    ['query' => $raw, 'digits' => $digits, 'matches' => []],
                    'OK',
                    200
                );
            }

            $rows = Vehicle::query()
                ->whereNotNull('plate_no')->where('plate_no', '<>', '')
                ->get(['id', 'plate_no', 'make', 'model', 'status', 'car_serial', 'operational_status']);

            // Group every candidate by its plate digits, then let PlateResolver pick the current car
            // inside each group (plates get re-issued; the sold row must never win the lookup).
            $byPlate = [];
            foreach ($rows as $v) {
                $d = PlateResolver::plateDigits($v->plate_no);
                if ($d !== '' && str_starts_with($d, $digits)) {
                    $byPlate[$d][] = $v;
                }
            }
            if ($byPlate === []) {
                return ResponseHelper::SuccessResponse(
                    ['query' => $raw, 'digits' => $digits, 'matches' => []],
                    'OK',
                    200
                );
            }

            // Exact plate first, then the prefix neighbours in plate order. Capped: this is a jump-to,
            // not a browse.
            uksort($byPlate, function ($a, $b) use ($digits) {
                $ax = $a === $digits ? 0 : 1;
                $bx = $b === $digits ? 0 : 1;
                return $ax === $bx ? strcmp($a, $b) : $ax <=> $bx;
            });
            $byPlate = array_slice($byPlate, 0, 6, true);

            $vehicles = [];
            foreach ($byPlate as $group) {
                $picked = PlateResolver::pickBest($group);
                if ($picked) {
                    $vehicles[(int) $picked->id] = $picked;
                }
            }
            if ($vehicles === []) {
                return ResponseHelper::SuccessResponse(
                    ['query' => $raw, 'digits' => $digits, 'matches' => []],
                    'OK',
                    200
                );
            }

            $vehicleIds = array_keys($vehicles);

            // --- /maintenance-workflow: open tickets, restricted to lanes the board draws -------------
            $boardStates = array_merge(...array_values(self::COLUMNS));
            $tickets = Maintenance::openWorkflow()
                ->whereIn('vehicle_id', $vehicleIds)
                ->whereIn('workflow_status', $boardStates)
                ->with(['vendor:id,name'])
                ->orderByRaw('last_state_change_at IS NULL, last_state_change_at DESC')
                ->orderByDesc('id')
                ->get();

            $laneOf = [];
            foreach (self::COLUMNS as $lane => $states) {
                foreach ($states as $s) {
                    $laneOf[$s] = $lane;
                }
            }

            $hits = [];
            foreach ($tickets as $t) {
                $lane = $laneOf[$t->workflow_status] ?? null;
                // The paused status feeds two lanes; the split is the physical return, exactly as board().
                if ($lane === 'paused' && $t->vehicle_returned_at) {
                    $lane = 'returned_waiting_resume';
                }
                $hits[(int) $t->vehicle_id][] = [
                    'page'         => 'maintenance-workflow',
                    'to'           => '/maintenance-workflow/'.$t->id,
                    'ticket_id'    => (int) $t->id,
                    'status'       => $t->workflow_status,
                    'status_label' => MaintenanceWorkflowResource::LABELS[$t->workflow_status] ?? $t->workflow_status,
                    'lane'         => $lane,
                    'garage'       => optional($t->vendor)->name,
                    'since'        => optional($t->last_state_change_at)->toIso8601String(),
                ];
            }

            // --- /inspection-review: the Controller approval gate --------------------------------------
            if ($request->user()?->can('maintenance.manage')) {
                foreach ($this->workflow->pendingReview() as $t) {
                    if (! in_array((int) $t->vehicle_id, $vehicleIds, true)) {
                        continue;
                    }
                    $hits[(int) $t->vehicle_id][] = [
                        'page'         => 'inspection-review',
                        'to'           => '/inspection-review?ticket='.$t->id,
                        'ticket_id'    => (int) $t->id,
                        'status'       => $t->workflow_status,
                        'status_label' => MaintenanceWorkflowResource::LABELS[$t->workflow_status] ?? $t->workflow_status,
                        // Which tab the queue will land on — mirrors its own is_system_withdrawal split.
                        'lane'         => $t->workflow_status === Maintenance::WF_REVIEW_REJECTED ? 'withdrawn' : 'awaiting',
                        'garage'       => null,
                        'since'        => optional($t->requested_at)->toIso8601String(),
                    ];
                }
            }

            $matches = [];
            foreach ($vehicles as $id => $v) {
                $matches[] = [
                    'vehicle' => [
                        'id'       => (int) $v->id,
                        'plate_no' => $v->plate_no,
                        'make'     => $v->make,
                        'model'    => $v->model,
                        'status'   => $v->status,
                        'label'    => trim(implode(' ', array_filter([$v->make, $v->model]))) ?: $v->plate_no,
                        'to'       => '/vehicles/'.$v->id,
                    ],
                    'hits' => $hits[$id] ?? [],
                ];
            }

            // The car whose plate was actually TYPED comes first — a prefix neighbour that happens to be
            // in the shop must never outrank the exact plate the searcher asked about. Among equals, the
            // car that IS somewhere outranks one that is nowhere: that is the whole point of the search.
            $exact = fn ($m) => PlateResolver::plateDigits($m['vehicle']['plate_no']) === $digits ? 0 : 1;
            usort($matches, fn ($a, $b) => [$exact($a), -count($a['hits'])] <=> [$exact($b), -count($b['hits'])]);

            return ResponseHelper::SuccessResponse(
                ['query' => $raw, 'digits' => $digits, 'matches' => $matches],
                'OK',
                200
            );
        });
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
     * Ahead of all of them sits `assigned_to_me`, which every user gets (possibly empty): the legs this
     * person was personally named to collect or bring back. It is scoped by NAME, not by permission —
     * a supervisor can now be handed a leg himself, and he also holds maintenance.logistics, so without
     * it his own job would be buried among every driver's work.
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
            // Newest first, by arrival in the current stage — the same ordering the board uses, so a
            // ticket doesn't sit near the top of one screen and the bottom of the other.
            $open    = Maintenance::openWorkflow()
                ->whereHas('vehicle', fn ($q) => $q->whereIn('status', Vehicle::ACTIVE_STATUSES))
                ->with(self::EAGER)->withCount('media')
                ->orderByRaw('last_state_change_at IS NULL, last_state_change_at DESC')
                ->orderByDesc('id')
                ->get();
            // A temporarily-released car's repair is FROZEN while it is out — nobody can work on it, so it
            // must not sit in the repair sections below pretending to be actionable. It gets its own
            // sections (the release round trip), the same way the board gives it its own lane.
            $released = $open->filter(fn ($t) => $t->activeTemporaryRelease?->isOpen());
            $open     = $open->reject(fn ($t) => $t->activeTemporaryRelease?->isOpen())->values();

            $section = fn (array $states) => $open->whereIn('workflow_status', $states)->values();
            /** Released tickets sitting at any of these release stages. */
            $releaseSection = fn (array $stages) => $released->filter(fn ($t) => in_array($t->activeTemporaryRelease->stage, $stages, true))->values();

            $sections = [];
            $counts   = [];
            $add = function (string $key, array $states) use (&$sections, &$counts, $section) {
                $rows = $section($states);
                $sections[$key] = MaintenanceWorkflowResource::collection($rows);
                $counts[$key]   = $rows->count();
            };

            // MY LEGS — cars this person was personally named to collect or bring back, whoever they are.
            // A supervisor can now be assigned a leg (see MaintenanceWorkflowService::delegate), and he
            // holds maintenance.logistics too, so the driver sections below would drown his own job in
            // every driver's work. This is his — and any driver's — "what did someone hand ME" list, so
            // it goes first and is scoped by name, not by permission.
            $mine = $open
                ->where('assigned_driver_id', $user->id)
                ->whereIn('workflow_status', [
                    Maintenance::WF_AWAITING_DISPATCH,
                    Maintenance::WF_IN_TRANSIT,
                    Maintenance::WF_UNDER_REPAIR,
                    Maintenance::WF_READY_FOR_PICKUP,
                ])
                ->values();
            // A release leg named to this person belongs in the same "someone handed ME this" list — it is
            // the same job (drive this car there), just on a ticket whose repair is paused in place.
            $mineReleases = $released->filter(function ($t) use ($user) {
                $r = $t->activeTemporaryRelease;
                return in_array($r->stage, [MaintenanceTemporaryRelease::STAGE_OUT_ASSIGNED, MaintenanceTemporaryRelease::STAGE_OUT_TRANSIT], true)
                    ? (int) $r->out_driver_id === (int) $user->id
                    : (in_array($r->stage, [MaintenanceTemporaryRelease::STAGE_RETURN_ASSIGNED, MaintenanceTemporaryRelease::STAGE_RETURN_TRANSIT], true)
                        && (int) $r->return_driver_id === (int) $user->id);
            });
            $mine = $mine->concat($mineReleases)->values();
            $sections['assigned_to_me'] = MaintenanceWorkflowResource::collection($mine);
            $counts['assigned_to_me']   = $mine->count();

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
                // …and the step AFTER his call: garage picked, driver named (or left to the pool), car
                // still standing with us waiting to be collected. It was a driver-only section, so the
                // supervisor who made the assignment had no way of seeing that nobody had acted on it —
                // the one lane where a car sits still precisely because a decision has already been made.
                // He may take the pickup himself or hand it to someone else (maySupersedeDriver), so the
                // card carries its real action rather than being a read-only tracker.
                $add('awaiting_pickup', [Maintenance::WF_AWAITING_DISPATCH]);
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

            // ── Temporary releases — the round trip out of the workshop and back ────────────────────
            // Split by who owns the next step, exactly like the repair sections above: the supervisor
            // decides where a released car goes and confirms the garage it comes back to; the driver
            // does the moving; a parked car is a controller's call to bring back.
            $addRelease = function (string $key, array $stages) use (&$sections, &$counts, $releaseSection) {
                $rows = $releaseSection($stages);
                $sections[$key] = MaintenanceWorkflowResource::collection($rows);
                $counts[$key]   = $rows->count();
            };

            if ($isDispatcher) {
                $addRelease('release_dispatch', [
                    MaintenanceTemporaryRelease::STAGE_OUT_DISPATCH,
                    MaintenanceTemporaryRelease::STAGE_RETURN_DISPATCH,
                ]);
            }
            if ($isDriver) {
                $addRelease('release_moves', [
                    MaintenanceTemporaryRelease::STAGE_OUT_ASSIGNED,
                    MaintenanceTemporaryRelease::STAGE_OUT_TRANSIT,
                    MaintenanceTemporaryRelease::STAGE_RETURN_ASSIGNED,
                    MaintenanceTemporaryRelease::STAGE_RETURN_TRANSIT,
                ]);
            }
            if ($isDispatcher || $user->can('maintenance.manage')) {
                $addRelease('released_parked', [MaintenanceTemporaryRelease::STAGE_AT_DESTINATION]);
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
                // How this car has BEHAVED, next to where it is right now: garage visits and garage
                // downtime over the rolling window, each with its attention level and the plain
                // sentences behind it. Served from the stored reading the alert engine already keeps
                // up to date, so the profile and the notification can never disagree about the
                // numbers — and so opening a car's page costs a single indexed row, not a
                // recalculation. Null until the car has been evaluated at least once (a fresh
                // install before the first sweep). @see \App\Services\Garage\GarageIntelligenceService
                'garage_intelligence' => $this->garageIntelligence($vehicle),
                'tickets'             => MaintenanceWorkflowResource::collection($tickets),
                'photos'              => $photos,
                'last_odometer_photo' => $photos->firstWhere('body_part', 'odometer'),
            ], 'Vehicle maintenance workflow retrieved', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /**
     * The car's garage-behaviour reading for the profile panel.
     *
     * Reads the stored state rather than recomputing: the row is refreshed on every event that can
     * move it (and nightly for the rolling window), so it is current, and serving it from here keeps
     * the page cheap. If a car has never been evaluated — a fresh install, before the first sweep —
     * this evaluates it once, silently, rather than showing an empty panel and rather than raising an
     * alert nobody asked for by opening a page.
     *
     * @return array<string,mixed>|null
     */
    private function garageIntelligence(Vehicle $vehicle): ?array
    {
        $intel = app(\App\Services\Garage\GarageIntelligenceService::class);
        if (! $intel->enabled()) {
            return null;
        }

        $state = \App\Models\VehicleGarageAlertState::where('vehicle_id', $vehicle->id)->first()
            ?: $intel->evaluate($vehicle, notify: false);

        if (! $state) {
            return null;
        }

        return [
            'window_days'         => (int) $state->window_days,
            'visits'              => (int) $state->visits,
            'downtime_days'       => round($state->downtime_seconds / 86400, 1),
            'downtime_pct'        => (float) $state->downtime_pct,
            'visit_severity'      => $state->visit_severity,
            'downtime_severity'   => $state->downtime_severity,
            'severity'            => $state->severity,
            'currently_in_garage' => (bool) $state->currently_in_garage,
            'last_entry_at'       => optional($state->last_entry_at)->toIso8601String(),
            'reasons'             => (array) ($state->reasons ?: []),
            'evaluated_at'        => optional($state->evaluated_at)->toIso8601String(),
        ];
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

    /**
     * THE DECISION LOG — every action ever taken on this ticket and the person who took it.
     *
     * The ticket's own `handoffs` block answers "which stages has this reached", and it answers it from
     * columns on the ticket: one stamp per stage, overwritten if a stage is entered twice. That is a
     * summary of the current state, not a record of decisions. It cannot tell you who re-dispatched a
     * car after a failed re-inspection, who reclassified the job, who let the car out of the workshop,
     * or who changed their mind — because none of those leave a column behind.
     *
     * `vehicle_log_events` does. It is the append-only audit trail the workflow service already writes
     * on every transition (VehicleLogService::record), stamped with the actor, the stage the ticket was
     * at, and the meta the action carried. This endpoint is simply that trail, scoped to one ticket and
     * read newest-first — the same rows the vehicle timeline reads, so the two can be checked against
     * each other.
     *
     * `maintenance_ref` is queried alongside `maintenance_id` on purpose: the FK is nulled if a ticket
     * is ever deleted, and the ref is the FK-free twin that survives it (see VehicleLogEvent).
     *
     * SYSTEM-generated events have no actor. They are returned with `actor_name` null and `is_system`
     * true rather than being labelled with somebody's name — "the system did this" is a real answer to
     * "who decided", and inventing an operator for it would be worse than saying nobody.
     */
    public function decisionLog(Maintenance $ticket)
    {
        try {
            $rows = VehicleLogEvent::query()
                ->where(function ($q) use ($ticket) {
                    $q->where('maintenance_id', $ticket->id)
                      ->orWhere('maintenance_ref', $ticket->id);
                })
                ->with(['actor:id,name', 'task:id,symptom,kind'])
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->limit(300)
                ->get();

            $events = $rows->map(fn (VehicleLogEvent $e) => [
                'id'              => $e->id,
                'event_type'      => $e->event_type,
                // The event's human name, from the SAME map the vehicle timeline uses.
                'action'          => ActivityFeedService::labelFor($e->event_type),
                'actor_id'        => $e->actor_id,
                'actor_name'      => $e->actor?->name,
                'actor_role'      => ActivityFeedService::roleFor($e->event_type),
                'is_system'       => $e->actor_id === null,
                'occurred_at'     => optional($e->occurred_at)->toIso8601String(),
                // The stage the TICKET was at when this happened — what the decision was taken against.
                // Named with the SAME map the ticket's own status pill uses, so a log line and the pill
                // above it never call one stage two different things.
                'workflow_status' => $e->workflow_status,
                'stage_label'     => $e->workflow_status
                    ? (MaintenanceWorkflowResource::LABELS[$e->workflow_status] ?? $e->workflow_status)
                    : null,
                'source_tag'      => $e->source_tag,
                'description'     => $e->description,
                // Which fault this line is about, when it is about one at all.
                'task_id'         => $e->maintenance_task_id,
                'task_symptom'    => $e->task?->symptom,
                'details'         => $e->meta ?: null,
            ])->values();

            return ResponseHelper::SuccessResponse([
                'events' => $events,
                'total'  => $events->count(),
            ], 'Ticket decision log retrieved', 200);
        } catch (\Throwable $e) {
            return ResponseHelper::fromException($e);
        }
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

            // Enterprise Handover Workflow — the pause/resume custody-handover readings, sourced from the
            // handover table (the source of truth) rather than a denormalized column on the ticket.
            $ticket->loadMissing(['lastPauseHandover', 'lastResumeHandover']);
            if ($ticket->lastPauseHandover && $ticket->lastPauseHandover->odometer_reading !== null) {
                $entries->push([
                    'value' => (int) $ticket->lastPauseHandover->odometer_reading,
                    'at'    => $ticket->lastPauseHandover->occurred_at,
                    'how'   => 'pause', 'by' => $ticket->lastPauseHandover->actor?->name, 'ref' => '#' . $ticket->id,
                    'note'  => $ticket->lastPauseHandover->notes, 'this_ticket' => true,
                ]);
            }
            if ($ticket->lastResumeHandover && $ticket->lastResumeHandover->odometer_reading !== null) {
                $entries->push([
                    'value' => (int) $ticket->lastResumeHandover->odometer_reading,
                    'at'    => $ticket->lastResumeHandover->occurred_at,
                    'how'   => 'resume', 'by' => $ticket->lastResumeHandover->actor?->name, 'ref' => '#' . $ticket->id,
                    'note'  => $ticket->lastResumeHandover->notes, 'this_ticket' => true,
                ]);
            }

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

            // 2) Contract handover readings (pickup + return). Collected here but dropped from the
            //    rendered chain by MILEAGE_CHAIN_HIDDEN below — display only, the contracts are untouched.
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
            $rank = ['contract_out' => 1, 'test_drive' => 2, 'report' => 3, 'dispatch' => 4, 'return' => 5, 'pause' => 5.5, 'reinspect' => 6, 'resume' => 5.7, 'contract_in' => 7, 'manual' => 9];

            $sorted = $entries
                ->filter(fn ($e) => $e['value'] !== null && $e['value'] > 0   // drop 0 / null junk readings
                    && ! in_array($e['how'], self::MILEAGE_CHAIN_HIDDEN, true))
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

    /**
     * Is an inspection ALREADY IN FLIGHT for this car — waiting in the review queue, with the Inspector,
     * or being test-driven right now? Read-only, called by the Request Inspection form the moment a car is
     * picked, so the driver is TOLD before they submit instead of being refused after. `data` is null when
     * nothing is in flight (the normal case). Static-segment route precedes /{ticket}.
     */
    public function vehicleInspectionRequest(Vehicle $vehicle)
    {
        return $this->run(function () use ($vehicle) {
            return ResponseHelper::SuccessResponse(
                $this->workflow->inspectionRequestState($vehicle->id),
                'Vehicle inspection request state retrieved',
                200
            );
        });
    }

    /**
     * The RULEBOOK behind system-generated inspection requests — when and why the Proactive Diagnostic
     * Monitor asks for a test. Served live from DiagnosticGateService::rulebook() (thresholds read from
     * config, not retyped) so the Inspection Review Queue's explainer can never describe rules other than
     * the ones that actually fire. Read-only, vehicle-independent, safe to cache client-side.
     */
    public function reviewGateRules(\App\Services\DiagnosticGateService $gate)
    {
        return $this->run(function () use ($gate) {
            return ResponseHelper::SuccessResponse($gate->rulebook(), 'Diagnostic rulebook retrieved', 200);
        });
    }

    /**
     * The whole active fleet's countdown to its next system-raised test — one row per car, soonest
     * first. The review queue shows cars the system has already asked about; this answers the question
     * before it ("which cars are coming, and when"), read from the SAME rulebook so the two can never
     * disagree. Read-only; gated with the queue itself.
     */
    public function testCountdown(\App\Services\DiagnosticGateService $gate)
    {
        return $this->run(function () use ($gate) {
            $rows = $gate->fleetTestCountdown();

            $summary = ['total' => count($rows)];
            foreach ($rows as $r) {
                $summary[$r['state']] = ($summary[$r['state']] ?? 0) + 1;
            }

            $buckets = [];
            foreach ($rows as $r) {
                $buckets[$r['bucket']] = ($buckets[$r['bucket']] ?? 0) + 1;
            }

            return ResponseHelper::SuccessResponse([
                'rows'    => $rows,
                'summary' => $summary,
                'buckets' => $buckets,
                // The live thresholds the numbers were produced with, so the page can label them
                // without hard-coding a limit that config can change underneath it.
                'limits'  => [
                    'downtime_days' => $gate->downtimeLimitDays(),
                    'inactive_days' => $gate->inactivityLimitDays(),
                ],
            ], 'Fleet test countdown retrieved', 200);
        });
    }

    /**
     * Every car physically in a shop right now — driven by the SHOP STAY (open OM type-U contract, or
     * an open garage-log trip), not by whether a request happened to be parked on it. Carries the
     * visit, the car's last check, and the recommendation that was already pending when it went in.
     */
    public function parkedInShop(\App\Services\DiagnosticGateService $gate)
    {
        return $this->run(function () use ($gate) {
            $result = $gate->fleetParked();
            $rows   = $result['rows'];

            $bySource = [];
            $withRequest = 0;
            foreach ($rows as $r) {
                $bySource[$r['parked']['source']] = ($bySource[$r['parked']['source']] ?? 0) + 1;
                if ($r['request']) {
                    $withRequest++;
                }
            }

            return ResponseHelper::SuccessResponse([
                'rows'    => $rows,
                // Cars in a shop that are NOT part of the operational fleet (suspended / up for sale).
                // Reported, never silently dropped — the planning board does not cover them either.
                'outside_fleet' => $result['outside_fleet'],
                'summary' => [
                    'total'        => count($rows),
                    'with_request' => $withRequest,
                ] + $bySource,
            ], 'Parked vehicles retrieved', 200);
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
    /**
     * Stage 0 (manager entry) — a Controller (Lin/Marwa) REQUESTS an inspection. This is the "request"
     * half of the split: the manager only picks the vehicle, the inspection type (routine/scheduled via
     * test_kind) and optional notes — deliberately NO odometer, NO photo, NO diagnostic data, because
     * those are things a manager cannot know. It creates an `inspection_requested` ticket assigned to the
     * Inspector (Abu Maroof), who is notified immediately; he captures every reading later at Start
     * Inspection (startDiagnostic). Gated to maintenance.manage (the Controllers' permission).
     */
    public function storeInspectionRequest(Request $request)
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'vehicle_id'     => ['required', 'integer', Rule::exists('vehicles', 'id')],
                'trigger_reason' => ['required', Rule::in(Maintenance::TRIGGER_REASONS)],
                'notes'          => ['nullable', 'string', 'max:2000'],
                'test_kind'      => ['nullable', Rule::in(Maintenance::TEST_KINDS)],
                // The structured statement is OPTIONAL on this door: the Routine / Scheduled intake tabs
                // already say why by being the tab they are, and demanding a fault name for a car nobody
                // has driven would only invite an invented one. Sent, it is judged like every other door's.
                'reported_faults'                       => ['nullable', 'array', 'max:6'],
                'reported_faults.*.fault_catalog_id'    => ['nullable', 'integer'],
                'reported_faults.*.slug'                => ['nullable', 'string', 'max:64'],
                'reported_faults.*.text'                => ['nullable', 'string', 'max:255'],
                'reported_faults.*.category_key'        => ['nullable', 'string', 'max:64'],
                'reported_faults.*.repeat_of_ticket_id' => ['nullable', 'integer'],
                // The cause they SUSPECT, by id. Shape only here; that the id belongs to THIS fault's own
                // approved short-list is proved in the service, beside the fault-name provenance rule.
                'reported_faults.*.root_cause_id'       => ['nullable', 'integer'],
                // THE SERVICE ANSWER — planned work that is due, named from the live ServiceCatalog. May be
                // sent alongside named faults ("it pulls left and it's due an oil change"); each service
                // proves itself against the catalog in the workflow service, exactly as a fault does.
                'requested_services'                      => ['nullable', 'array', 'max:6'],
                'requested_services.*.service_catalog_id' => ['nullable', 'integer'],
                'requested_services.*.slug'               => ['nullable', 'string', 'max:64'],
                'request_reason_code'                   => ['nullable', 'string', Rule::in(array_keys(Maintenance::REQUEST_REASONS_INSPECTION))],
            ]);

            $ticket = $this->workflow->requestInspectionByController([
                'vehicle_id'          => $data['vehicle_id'],
                'trigger_reason'      => $data['trigger_reason'],
                'customer_complaint'  => $data['notes'] ?? null,
                'test_kind'           => $data['test_kind'] ?? null,
                'reported_faults'     => $data['reported_faults'] ?? null,
                'requested_services'  => $data['requested_services'] ?? null,
                'request_reason_code' => $data['request_reason_code'] ?? null,
            ], $request->user());

            return ResponseHelper::SuccessResponse(
                MaintenanceWorkflowResource::make($ticket),
                'Inspection requested — Abu Maroof notified',
                201
            );
        });
    }

    public function store(Request $request)
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'vehicle_id'         => ['required', 'integer', Rule::exists('vehicles', 'id')],
                'trigger_reason'     => ['required', Rule::in(Maintenance::TRIGGER_REASONS)],
                'customer_complaint' => ['nullable', 'string', 'max:2000'],
                'test_odometer'      => ['required', 'integer', 'min:1', 'max:' . self::MAX_ODOMETER],
                'odometer_photo'     => ['required', 'image', 'max:8192'], // ≤ 8 MB
                // Mandatory (client-enforced) explanation when the reading is >10 km off the previous one.
                'odometer_note'      => ['nullable', 'string', 'max:2000'],
                // The operator's tick of "I've checked — this reading is correct" on the continuity nag.
                'odometer_confirmed' => ['nullable', 'boolean'],
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
     * Triage — "Recommend sending the car in". Abu Maroof recommends routing the complained car to the
     * garage-dispatch queue OR to his own diagnostic (optionally arranging a replacement swap), but the car
     * is NOT moved yet: his decision parks in the Triage Routing Approval gate for a Supervisor/delegate to
     * approve or reject. Gated to maintenance.initiate.
     */
    public function triageRoute(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $data = $request->validate([
                'destination'            => ['required', Rule::in(['garage', 'diagnostic'])],
                'replacement_vehicle_id' => ['nullable', 'integer', Rule::exists('vehicles', 'id')],
                'note'                   => ['nullable', 'string', 'max:2000'],
            ]);
            $ticket = $this->workflow->recommendTriageRoute($ticket, $data, $request->user());
            $sent = $data['destination'] === 'garage' ? 'garage dispatch' : 'diagnostic inspection';
            return ResponseHelper::SuccessResponse(MaintenanceWorkflowResource::make($ticket), "Sent for supervisor approval — routing to {$sent}", 200);
        });
    }

    /**
     * Triage Routing Approval — the Supervisor/delegate APPROVES Abu Maroof's routing recommendation; the
     * car is now actually sent to the garage-dispatch queue or the diagnostic queue (with any replacement
     * arranged). Gated to maintenance.delegate|maintenance.manage.
     */
    public function approveTriageRoute(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $ticket = $this->workflow->approveTriageRoute($ticket, $request->user());
            $dest = $ticket->workflow_status === Maintenance::WF_INSPECTION_REQUESTED ? 'diagnostic inspection' : 'garage dispatch';
            return ResponseHelper::SuccessResponse(MaintenanceWorkflowResource::make($ticket), "Routing approved — sent to {$dest}", 200);
        });
    }

    /**
     * Triage Routing Approval — the Supervisor/delegate REJECTS the routing recommendation; the complaint
     * returns to Abu Maroof's triage lane. Optional reason. Gated to maintenance.delegate|maintenance.manage.
     */
    public function rejectTriageRoute(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);
            $ticket = $this->workflow->rejectTriageRoute($ticket, $data['reason'] ?? null, $request->user());
            return ResponseHelper::SuccessResponse(MaintenanceWorkflowResource::make($ticket), 'Routing rejected — back to triage', 200);
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
     * nor a diagnostic yet; it lands in the Controllers' (Lin & Marwa) review queue — the Inspector
     * (Abu Maroof) is only notified once a Controller approves it.
     */
    public function requestInspection(Request $request)
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'vehicle_id'         => ['required', 'integer', Rule::exists('vehicles', 'id')],
                'trigger_reason'     => ['required', Rule::in(Maintenance::TRIGGER_REASONS)],
                'customer_complaint' => ['nullable', 'string', 'max:2000'],
                // Optional evidence — a still PHOTO or a VIDEO of what the driver saw/heard, attached to the
                // new ticket so the inspector can review it before the test drive. Capped at 256 MB to match
                // the storeVideo ingest (see docker/uploads.ini).
                'media'              => ['nullable', 'file', 'mimetypes:video/mp4,video/quicktime,video/x-msvideo,video/webm,video/3gpp,image/jpeg,image/png,image/webp,image/heic,image/heif', 'max:262144'],
                // maintenance_type is intentionally absent: the Driver has no diagnostic authority.
                // The Inspector sets the classification when filing the report (submitReport).

                // THE STATEMENT — how they said why. Shape only is checked here; the exclusivity rule and
                // the "where did this fault name come from?" proof live in the service, so every door
                // (and every future caller) is judged by the same one implementation.
                'reported_faults'                       => ['nullable', 'array', 'max:6'],
                'reported_faults.*.fault_catalog_id'    => ['nullable', 'integer'],
                'reported_faults.*.slug'                => ['nullable', 'string', 'max:64'],
                'reported_faults.*.text'                => ['nullable', 'string', 'max:255'],
                'reported_faults.*.category_key'        => ['nullable', 'string', 'max:64'],
                'reported_faults.*.repeat_of_ticket_id' => ['nullable', 'integer'],
                // The cause they SUSPECT, by id — proved against this fault's own list in the service.
                'reported_faults.*.root_cause_id'       => ['nullable', 'integer'],
                // Planned work that is due, named from the live ServiceCatalog — may ride alongside faults.
                'requested_services'                      => ['nullable', 'array', 'max:6'],
                'requested_services.*.service_catalog_id' => ['nullable', 'integer'],
                'requested_services.*.slug'               => ['nullable', 'string', 'max:64'],
                'request_reason_code'                   => ['nullable', 'string', Rule::in(array_keys(Maintenance::REQUEST_REASONS_INSPECTION))],
                // requested_by is NOT accepted: the filer comes from the token, never from the payload.
            ]);

            $ticket = $this->workflow->requestInspection($data, $request->user());

            // Store the driver's optional photo/video on the fresh ticket as evidence (best-effort — the
            // request itself already succeeded, so a storage hiccup must not fail the inspection request).
            if ($request->hasFile('media')) {
                $this->storeTicketMedia($ticket, $request->file('media'), $request->user());
            }

            return ResponseHelper::SuccessResponse(MaintenanceWorkflowResource::make($ticket), 'Inspection requested — awaiting Controller review', 201);
        });
    }

    /**
     * THE SECOND DOOR — a car that needs a garage, not a diagnosis.
     *
     * Booked service, parts arrived, the garage asked for it back, a fault we already know: there is
     * nothing for an inspector to find out, so the ticket skips the review gate AND the test drive and is
     * born in the Supervisors' dispatch queue (`inspection_pending` — "Needs Dispatch"). The named faults
     * become real routable faults immediately, because a supervisor cannot dispatch an empty ticket.
     *
     * That is a car committed to a workshop with nobody having diagnosed it, so the route is gated to
     * `maintenance.initiate|maintenance.manage` — the people who already hold diagnostic or dispatch
     * authority. A Driver (maintenance.logistics alone) files an inspection REQUEST and someone decides.
     * Not a breakdown: the car is driveable, is not grounded, and is not forced to critical — reporting a
     * dead car is still storeBreakdown().
     */
    public function storeDirectDispatch(Request $request)
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'vehicle_id'                            => ['required', 'integer', Rule::exists('vehicles', 'id')],
                'reported_faults'                       => ['nullable', 'array', 'max:6'],
                'reported_faults.*.fault_catalog_id'    => ['nullable', 'integer'],
                'reported_faults.*.slug'                => ['nullable', 'string', 'max:64'],
                'reported_faults.*.text'                => ['nullable', 'string', 'max:255'],
                'reported_faults.*.category_key'        => ['nullable', 'string', 'max:64'],
                'reported_faults.*.repeat_of_ticket_id' => ['nullable', 'integer'],
                // On THIS door the pick is a diagnosis, not a suspicion — the service writes it onto the
                // finding, because there is no inspector coming behind this ticket.
                'reported_faults.*.root_cause_id'       => ['nullable', 'integer'],
                // THE SERVICE ANSWER — planned work that is due, named from the live ServiceCatalog. May be
                // sent alongside named faults; on THIS door both become routable work at birth. Shape only
                // is checked here; the catalog proof and the exclusivity rule live in the service.
                'requested_services'                    => ['nullable', 'array', 'max:6'],
                'requested_services.*.service_catalog_id' => ['nullable', 'integer'],
                'requested_services.*.slug'             => ['nullable', 'string', 'max:64'],
                'request_reason_code'                   => ['nullable', 'string', Rule::in(array_keys(Maintenance::REQUEST_REASONS_DISPATCH))],
                'customer_complaint'                    => ['nullable', 'string', 'max:2000'],
            ]);

            $ticket = $this->workflow->openDirectDispatch($data, $request->user());

            return ResponseHelper::SuccessResponse(
                MaintenanceWorkflowResource::make($ticket),
                'Sent in — waiting for a supervisor to pick the garage',
                201
            );
        });
    }

    /**
     * Pause Maintenance & Return to Service — the car is urgently needed back in service mid-repair. The
     * repair is temporarily interrupted and the car released back into service (Available) WITHOUT
     * closing/cancelling the ticket: every bit of its state is preserved and the stage is remembered so
     * it can resume from exactly here. Enterprise Handover Workflow: a full custody handover (odometer +
     * photo, fuel, condition, damage, missing accessories, notes, signature) is mandatory here — mirrors
     * underRepair()'s multipart pattern exactly. Gated to maintenance.manage (the controllers who own the
     * "pull this car out" decision, matching the rental-form pull).
     */
    public function pause(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $fuelScale = config('maintenance_handover.fuel_scale', []);
            $data = $request->validate([
                'reason'              => ['nullable', 'string', 'max:2000'],
                'pause_odometer'      => ['required', 'integer', 'min:1', 'max:' . self::MAX_ODOMETER],
                'odometer_photo'      => ['required', 'image', 'max:8192'], // ≤ 8 MB
                'fuel_level'          => ['required', 'string', Rule::in($fuelScale)],
                'exterior_condition'  => ['required', 'string', 'max:255'],
                'interior_condition'  => ['required', 'string', 'max:255'],
                'damage_findings'     => ['nullable', 'array'],
                'missing_accessories' => ['nullable', 'array'],
                'notes'               => ['nullable', 'string', 'max:2000'],
                'signature'           => ['required', 'image', 'max:2048'],
            ]);

            $ticket = $this->workflow->pauseForRental($ticket, $data['reason'] ?? null, $request->user(), true, [
                'odometer_reading'    => $data['pause_odometer'],
                'fuel_level'          => $data['fuel_level'],
                'exterior_condition'  => $data['exterior_condition'],
                'interior_condition'  => $data['interior_condition'],
                'damage_findings'     => $data['damage_findings'] ?? [],
                'missing_accessories' => $data['missing_accessories'] ?? [],
                'notes'               => $data['notes'] ?? null,
            ]);

            [$photoSaved, $signatureSaved] = $this->storeHandoverEvidence($ticket, $request, MaintenanceHandover::TYPE_PAUSE);

            return ResponseHelper::SuccessResponse(
                [
                    'ticket'                => MaintenanceWorkflowResource::make($ticket),
                    'odometer_photo_saved'  => $photoSaved,
                    'signature_saved'       => $signatureSaved,
                ],
                'Maintenance paused — vehicle released back into service',
                200
            );
        });
    }

    /**
     * Vehicle Physically Returned — a light checkpoint (no odometer/handover required): stamps that the
     * car is back so the return handover paperwork is chased. From this moment the car is blocked from
     * being rented out again until the resume handover clears. Gated to whoever can plausibly be the one
     * handing the keys back in (controllers, supervisors, or the driver/logistics claim role).
     */
    public function markReturned(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $data = $request->validate([
                'note' => ['nullable', 'string', 'max:2000'],
            ]);

            $ticket = $this->workflow->markVehicleReturned($ticket, $request->user(), $data['note'] ?? null);
            return ResponseHelper::SuccessResponse(
                MaintenanceWorkflowResource::make($ticket),
                'Vehicle marked as physically returned — return handover due',
                200
            );
        });
    }

    /**
     * Resume Maintenance — the car is back; the SAME ticket continues from the exact stage it paused at
     * (nothing restarts), UNLESS the mandatory return handover reveals a discrepancy against the pause
     * handover beyond the configured thresholds — in which case the resume is held open pending a
     * supervisor's acknowledgement (`blocked_by_incident: true` in the response; this is an EXPECTED
     * outcome, not an error — the request still returns 200). Gated to a controller (maintenance.manage)
     * or a supervisor (maintenance.delegate) — either can send the car back into the workshop pipeline.
     */
    public function resume(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $fuelScale = config('maintenance_handover.fuel_scale', []);
            $data = $request->validate([
                'resume_odometer'     => ['required', 'integer', 'min:1', 'max:' . self::MAX_ODOMETER],
                'odometer_photo'      => ['required', 'image', 'max:8192'], // ≤ 8 MB
                'fuel_level'          => ['required', 'string', Rule::in($fuelScale)],
                'exterior_condition'  => ['required', 'string', 'max:255'],
                'interior_condition'  => ['required', 'string', 'max:255'],
                'damage_findings'     => ['nullable', 'array'],
                'missing_accessories' => ['nullable', 'array'],
                'notes'               => ['nullable', 'string', 'max:2000'],
                'signature'           => ['required', 'image', 'max:2048'],
            ]);

            $ticket = $this->workflow->resumeMaintenance($ticket, $request->user(), [
                'odometer_reading'    => $data['resume_odometer'],
                'fuel_level'          => $data['fuel_level'],
                'exterior_condition'  => $data['exterior_condition'],
                'interior_condition'  => $data['interior_condition'],
                'damage_findings'     => $data['damage_findings'] ?? [],
                'missing_accessories' => $data['missing_accessories'] ?? [],
                'notes'               => $data['notes'] ?? null,
            ]);

            [$photoSaved, $signatureSaved] = $this->storeHandoverEvidence($ticket, $request, MaintenanceHandover::TYPE_RESUME);

            $blockedByIncident = $ticket->active_incident_id !== null;

            return ResponseHelper::SuccessResponse(
                [
                    'ticket'               => MaintenanceWorkflowResource::make($ticket),
                    'odometer_photo_saved' => $photoSaved,
                    'signature_saved'      => $signatureSaved,
                    'blocked_by_incident'  => $blockedByIncident,
                ],
                $blockedByIncident
                    ? 'Return handover recorded — a discrepancy was flagged and needs acknowledgement before the repair resumes'
                    : 'Maintenance resumed — continuing from where it paused',
                200
            );
        });
    }

    /**
     * Temporarily Release Vehicle — the DECISION that the car may leave the workshop mid-repair (road
     * test / customer test/delivery / external inspection / storage) while the ticket stays open at its
     * current stage. NOT a pause: workflow_status is untouched, the car is NOT freed for rental, the
     * ticket is never closed, the faults stay exactly as they are. Nothing has moved yet — the ticket
     * lands in Needs Dispatch and the movement legs below carry it from there. Gated to
     * maintenance.manage (the controllers who own the "let the car leave" decision).
     */
    public function temporarilyRelease(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $data = $request->validate([
                'reason'         => ['required', 'string', Rule::in(MaintenanceTemporaryRelease::REASONS)],
                // A free-text detail — MANDATORY when the reason is "Other" (there's no preset label then).
                'reason_note'    => ['nullable', 'string', 'max:2000', Rule::requiredIf($request->input('reason') === MaintenanceTemporaryRelease::REASON_OTHER)],
                // Who the car is being released FOR. Defaults to the signed-in user; an explicit name is
                // only needed when it goes out on someone else's behalf.
                'taken_by'       => ['nullable', 'string', 'max:120'],
            ]);

            $ticket = $this->workflow->temporarilyReleaseVehicle($ticket, [
                'reason'      => $data['reason'],
                'reason_note' => $data['reason_note'] ?? null,
                'taken_by'    => trim((string) ($data['taken_by'] ?? '')) ?: $request->user()->name,
            ], $request->user());

            return ResponseHelper::SuccessResponse(
                MaintenanceWorkflowResource::make($ticket),
                'Vehicle released — pick where it goes and who drives it',
                200
            );
        });
    }

    /**
     * Release out-dispatch — WHERE the released car goes (free text; the UI offers the common
     * destinations as quick-picks) and, optionally, WHO drives it. Blank driver = open to the pool.
     * Supervisor authority, matching the garage dispatch it mirrors.
     */
    public function assignReleaseMove(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $data = $request->validate([
                'destination' => ['required', 'string', 'max:160'],
                'driver_id'   => ['nullable', 'integer', 'exists:users,id'],
                'note'        => ['nullable', 'string', 'max:2000'],
            ]);

            $ticket = $this->workflow->assignReleaseMove($ticket, $data, $request->user());

            return ResponseHelper::SuccessResponse(
                MaintenanceWorkflowResource::make($ticket),
                'Release dispatched — awaiting pickup',
                200
            );
        });
    }

    /**
     * Release out-pickup — the driver physically collects the car from the garage. The OUT odometer is
     * captured here, because THIS is the moment the car leaves.
     */
    public function startReleaseMove(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $data = $request->validate([
                'release_odometer' => ['required', 'integer', 'min:1', 'max:' . self::MAX_ODOMETER],
            ]);

            $ticket = $this->workflow->startReleaseMove($ticket, [
                'odometer_out' => $data['release_odometer'],
            ], $request->user());

            return ResponseHelper::SuccessResponse(
                MaintenanceWorkflowResource::make($ticket),
                'Car collected — en route to its destination',
                200
            );
        });
    }

    /** Release out-arrival — the car is parked at its destination; it waits there until it's called back. */
    public function arriveAtReleaseDestination(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $data = $request->validate([
                'note' => ['nullable', 'string', 'max:2000'],
            ]);

            $ticket = $this->workflow->arriveAtReleaseDestination($ticket, $data, $request->user());

            return ResponseHelper::SuccessResponse(
                MaintenanceWorkflowResource::make($ticket),
                'Delivered — the car is parked at its destination',
                200
            );
        });
    }

    /**
     * Call the released car back — re-opens the dispatch queue for the return leg. The garage it left is
     * carried through as the default destination; the supervisor confirms or changes it at the next step.
     */
    public function requestReleaseReturn(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $ticket = $this->workflow->requestReleaseReturn($ticket, $request->user());

            return ResponseHelper::SuccessResponse(
                MaintenanceWorkflowResource::make($ticket),
                'Called back — confirm the garage and assign a driver',
                200
            );
        });
    }

    /**
     * Release return-dispatch — confirm (or change) the garage the car goes back to, and pick a driver.
     * The ticket's own garage only moves when the car physically arrives there.
     */
    public function assignReleaseReturn(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $data = $request->validate([
                'vendor_id' => ['nullable', 'integer', 'exists:vendors,id'],
                'driver_id' => ['nullable', 'integer', 'exists:users,id'],
                'note'      => ['nullable', 'string', 'max:2000'],
            ]);

            $ticket = $this->workflow->assignReleaseReturn($ticket, $data, $request->user());

            return ResponseHelper::SuccessResponse(
                MaintenanceWorkflowResource::make($ticket),
                'Return dispatched — awaiting pickup',
                200
            );
        });
    }

    /**
     * Cancel a release — the decision is taken back before the car has physically moved. Refused once a
     * driver has the keys (from then on the car has to be driven back, which is the return leg).
     * Controller authority, matching the authority that raised it.
     */
    public function cancelTemporaryRelease(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $data = $request->validate([
                'reason' => ['nullable', 'string', 'max:2000'],
            ]);

            $ticket = $this->workflow->cancelTemporaryRelease($ticket, $data['reason'] ?? null, $request->user());

            return ResponseHelper::SuccessResponse(
                MaintenanceWorkflowResource::make($ticket),
                'Release cancelled — the car stays in the workshop',
                200
            );
        });
    }

    /** Release return-pickup — the driver collects the car from where it was parked and heads to the garage. */
    public function startReleaseReturn(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $ticket = $this->workflow->startReleaseReturn($ticket, $request->user());

            return ResponseHelper::SuccessResponse(
                MaintenanceWorkflowResource::make($ticket),
                'Car collected — on the way back to the workshop',
                200
            );
        });
    }

    /**
     * Return Vehicle to Workshop — the temporarily-released car has ARRIVED back at the garage; record the
     * odometer IN, compute the distance driven while out, move the ticket's garage if the return leg was
     * pointed at a different shop, and clear the overlay so the ticket presents at its (unchanged) stage
     * again. Gated to whoever can plausibly hand the keys back (controllers, supervisors, or the
     * driver/logistics claim role) — matching mark-returned's authority.
     */
    public function returnFromTemporaryRelease(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $data = $request->validate([
                'return_odometer' => ['required', 'integer', 'min:1', 'max:' . self::MAX_ODOMETER],
                'return_note'     => ['nullable', 'string', 'max:2000'],
            ]);

            $ticket = $this->workflow->returnTemporarilyReleasedVehicle($ticket, [
                'odometer_in' => $data['return_odometer'],
                'return_note' => $data['return_note'] ?? null,
            ], $request->user());

            return ResponseHelper::SuccessResponse(
                MaintenanceWorkflowResource::make($ticket),
                'Vehicle returned to the workshop — the repair continues',
                200
            );
        });
    }

    /**
     * Acknowledge Incident — a supervisor clears a flagged handover discrepancy, finalizing the resume
     * that was held pending it. No re-capture: the resume handover was already permanently saved.
     * Gated to maintenance.manage.
     */
    public function acknowledgeIncident(Request $request, Maintenance $ticket, MaintenanceIncident $incident)
    {
        return $this->run(function () use ($request, $ticket, $incident) {
            $data = $request->validate([
                'acknowledgement_note' => ['nullable', 'string', 'max:2000'],
            ]);

            $ticket = $this->workflow->acknowledgeIncident($incident, $request->user(), $data['acknowledgement_note'] ?? null);
            return ResponseHelper::SuccessResponse(
                MaintenanceWorkflowResource::make($ticket),
                'Discrepancy acknowledged — maintenance resumed',
                200
            );
        });
    }

    /**
     * Best-effort evidence capture for a pause/resume handover — mirrors storeOdometerPhoto()'s "never
     * block the transition" convention. The odometer photo becomes an InspectionRecord (phase 'pause' or
     * 'resume', same trail every other checkpoint's photos live in); the signature is stored directly
     * (no InspectionRecord — it's not a vehicle condition photo). Both back-fill the just-created
     * MaintenanceHandover row. Returns [odometerPhotoSaved, signatureSaved].
     */
    private function storeHandoverEvidence(Maintenance $ticket, Request $request, string $phase): array
    {
        $handoverId = $phase === MaintenanceHandover::TYPE_PAUSE ? $ticket->last_pause_handover_id : $ticket->last_resume_handover_id;
        $photoSaved = false;
        $signatureSaved = false;

        if (! $handoverId) {
            return [$photoSaved, $signatureSaved]; // the handover row itself failed to save — nothing to attach evidence to
        }

        $updates = [];

        if ($request->hasFile('odometer_photo')) {
            try {
                $record = $this->storeOdometerPhoto($ticket, $request->file('odometer_photo'), $request->user(), $phase);
                if ($record) {
                    $updates['odometer_photo_inspection_record_id'] = $record->id;
                    $photoSaved = true;
                }
            } catch (\Throwable $e) {
                report($e); // logged — the transition already committed
            }
        }

        if ($request->hasFile('signature')) {
            try {
                $file = $request->file('signature');
                $disk = config('filesystems.disks.s3.bucket') ? 's3' : 'public';
                $ext  = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'png');
                $dir  = "inspections/vehicle-{$ticket->vehicle_id}/{$phase}/signature";
                $key  = $file->storeAs($dir, (string) Str::uuid() . '.' . $ext, $disk);
                if ($key) {
                    $updates['signature_path'] = $key;
                    $updates['signature_disk'] = $disk;
                    $signatureSaved = true;
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        if (! empty($updates)) {
            try {
                MaintenanceHandover::where('id', $handoverId)->update($updates);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return [$photoSaved, $signatureSaved];
    }

    /**
     * Inspection Request Review Gate — the Controllers' (Lin & Marwa) queue of requests awaiting
     * approval before they are sent to the Inspector (Abu Maroof).
     */
    public function reviewQueue(Request $request)
    {
        return $this->run(function () use ($request) {
            $tickets = $this->workflow->pendingReview();

            // Attach THIS reviewer's own "remind me later" to each card. One query for the whole queue,
            // not one per card — the queue routinely renders well over a hundred requests.
            $mine = app(ReviewReminderService::class)
                ->forUserAcross($tickets->pluck('id'), $request->user());
            // setRelation, not setAttribute: a relation is never written back on save, so a stray
            // ->save() further down any code path cannot try to persist a column that doesn't exist.
            foreach ($tickets as $ticket) {
                $ticket->setRelation('myReviewReminder', $mine->get($ticket->id));
            }

            return ResponseHelper::SuccessResponse(MaintenanceWorkflowResource::collection($tickets), 'OK', 200);
        });
    }

    /** Approve a pending inspection request — sends it on to the Inspector, unchanged from today. */
    public function approveReview(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $data = $request->validate([
                'notes' => ['nullable', 'string', 'max:2000'],
            ]);

            $ticket = $this->workflow->approveInspectionReview($ticket, $data, $request->user());
            return ResponseHelper::SuccessResponse(MaintenanceWorkflowResource::make($ticket), 'Approved — sent to Abu Maroof', 200);
        });
    }

    /**
     * Reject a pending inspection request — terminates it, nothing sent externally.
     *
     * `rejection_code` is the countable WHY (see Maintenance::REVIEW_REJECTION_REASONS); `rejection_reason`
     * is the reviewer's own words beside it and is only compulsory with the `other` code (enforced in the
     * service, alongside the rest of the rule). It stays `nullable` here rather than `required` because
     * every caller written before the code existed sends text and no code, and those must keep working.
     * `remind_at` books the "ask me again later" revisit reminder.
     */
    public function rejectReview(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $data = $request->validate([
                'rejection_code'   => ['nullable', 'string', Rule::in(array_keys(Maintenance::REVIEW_REJECTION_REASONS))],
                'rejection_reason' => ['nullable', 'string', 'max:2000'],
                'remind_at'        => ['nullable', 'date'],
            ]);

            $ticket = $this->workflow->rejectInspectionReview($ticket, $data, $request->user());
            return ResponseHelper::SuccessResponse(MaintenanceWorkflowResource::make($ticket), 'Inspection request rejected', 200);
        });
    }

    /** The fixed list of rejection reasons the queue's picker renders — code + label, from the model. */
    public function reviewRejectionReasons()
    {
        return $this->run(function () {
            $reasons = collect(Maintenance::REVIEW_REJECTION_REASONS)
                ->map(fn ($label, $code) => [
                    'code'  => $code,
                    'label' => $label,
                    // The one code that cannot stand on its own — the form uses this to make the note
                    // required rather than hardcoding the knowledge a second time.
                    'requires_note' => $code === Maintenance::REVIEW_REJECT_OTHER,
                ])
                ->values();

            return ResponseHelper::SuccessResponse($reasons, 'OK', 200);
        });
    }

    /**
     * "Remind me about this request in 2 hours." Personal to the caller — it books nothing for anyone
     * else and changes nothing about the request, which stays in the queue for whoever gets to it first.
     */
    public function remindReview(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $data = $request->validate([
                'preset'    => ['nullable', 'string', Rule::in(ReviewReminderService::presets())],
                'remind_at' => ['nullable', 'date', 'required_without:preset'],
                'note'      => ['nullable', 'string', 'max:500'],
            ]);

            $reminder = $this->workflow->remindAboutReview($ticket, $data, $request->user());

            return ResponseHelper::SuccessResponse([
                'id'        => $reminder->id,
                'remind_at' => $reminder->remind_at->toIso8601String(),
                'note'      => $reminder->note,
                'kind'      => $reminder->kind,
            ], 'Reminder set', 200);
        });
    }

    /** Drop the caller's own reminder on a request. */
    public function cancelReviewReminder(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $cancelled = $this->workflow->cancelReviewReminder($ticket, $request->user());

            return ResponseHelper::SuccessResponse(
                ['cancelled' => $cancelled],
                $cancelled ? 'Reminder cancelled' : 'You had no reminder on this request',
                200,
            );
        });
    }

    /** Retroactive sign-off for a legacy system request that bypassed the review gate — see pendingReview(). */
    public function acknowledgeLegacyReview(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $ticket = $this->workflow->acknowledgeLegacyInspectionRequest($ticket, $request->user());
            return ResponseHelper::SuccessResponse(MaintenanceWorkflowResource::make($ticket), 'Acknowledged', 200);
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
                'test_odometer'      => ['required', 'integer', 'min:1', 'max:' . self::MAX_ODOMETER],
                'odometer_photo'     => ['required', 'image', 'max:8192'], // ≤ 8 MB
                'odometer_note'      => ['nullable', 'string', 'max:2000'], // explanation for a >10 km gap
                'odometer_confirmed' => ['nullable', 'boolean'],
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
                // WHERE + HOW MANY, per symptom. Same per-symptom side-channel shape as `causes`
                // above. Optional throughout: a fault type whose policy says it has no place sends
                // nothing, and so does every client built before this existed.
                //
                // `locations.*` is validated against the vocabulary table rather than an in-code list,
                // so a place added to config/vehicle_locations.php + re-seeded is accepted with no
                // change here — the point of making location a catalog rather than an enum.
                'details'              => ['nullable', 'array'],
                'details.*.symptom'    => ['required_with:details', 'string', 'max:255'],
                'details.*.quantity'   => ['nullable', 'integer', 'min:1', 'max:' . (int) config('vehicle_locations.max_quantity', 40)],
                'details.*.locations'  => ['nullable', 'array'],
                'details.*.locations.*' => ['string', 'max:60', Rule::exists('vehicle_locations', 'slug')->where('is_active', true)],
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
                // Rental Eligibility — the inspector's one-time "can this car be rented before the
                // maintenance is finished?" call. false/omitted = mandatory (grounded until complete);
                // true = deferrable (a later rental pauses the ticket and it resumes on return).
                'deferrable_for_rental' => ['nullable', 'boolean'],
                // End-of-test-drive odometer (optional) + the >10 km gap explanation. Captured at Decide,
                // stored under the 'report' flag key — never overwrites the start-of-drive test_odometer.
                'report_odometer'      => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_ODOMETER],
                'odometer_note'        => ['nullable', 'string', 'max:2000'],
                'odometer_confirmed'   => ['nullable', 'boolean'],
                // End-of-test-drive odometer PHOTO (optional) — same best-effort, saved-after-commit
                // pattern as dispatch/receive; a storage hiccup can never block the report from filing.
                'odometer_photo'       => ['nullable', 'image', 'max:8192'], // ≤ 8 MB
                // REQUIRED PARTS — the inspector's technical answer to "what will this repair need?".
                // Recorded with the report but deliberately inert: no part request, no approval, no money.
                // Procurement starts only when the coordinator converts these, after the garage is chosen
                // (POST /{ticket}/required-parts/request). See MaintenanceRequiredPartService.
                'required_parts'             => ['nullable', 'array'],
                'required_parts.*.component_catalog_id' => ['nullable', 'integer', 'exists:component_catalog,id'],

                'required_parts.*.part_name' => ['required_with:required_parts', 'string', 'max:255'],
                'required_parts.*.quantity'  => ['nullable', 'numeric', 'min:0.01', 'max:9999'],
                'required_parts.*.priority'  => ['nullable', Rule::in(\App\Models\MaintenanceRequiredPart::PRIORITIES)],
                'required_parts.*.notes'     => ['nullable', 'string', 'max:1000'],
                // Which finding (symptom text) the part serves — the key that binds it to the fault once
                // findings are promoted to first-class tasks.
                'required_parts.*.finding'   => ['nullable', 'string', 'max:500'],
                // SYSTEM CHECKS — the inspector's structured answer to each obligation the system
                // raised on this car. Checkbox/radio only: a result code, and a decision code when the
                // result implies work. Validated for SHAPE here and for MEANING against the catalog in
                // MaintenanceWorkflowService::planCheckAnswers(), which is also what refuses a report
                // that leaves any of them unanswered — the whole point being that "nobody looked" must
                // stop being a possible outcome. See [[VehicleCheckRequirement]].
                'check_results'                 => ['nullable', 'array'],
                'check_results.*.id'            => ['required_with:check_results', 'integer'],
                'check_results.*.result_code'   => ['nullable', 'string', 'max:40'],
                'check_results.*.decision_code' => ['nullable', 'string', 'max:40'],
                // Only read for a check type whose catalog offers no keyword of its own; a catalog
                // keyword is never overridable from the request body.
                'check_results.*.finding_keyword' => ['nullable', 'string', 'max:120'],
            ]);

            $requires = $request->boolean('requires_maintenance');
            $ticket = $this->workflow->submitReport($ticket, $data, $requires, $request->user());

            // Record the technical requirements BEFORE findings are promoted below, so each line binds to
            // its fault automatically (MaintenanceTaskService::bindRequiredParts). A cleared diagnostic
            // needs no parts — the car required no work — so they are only kept when a ticket is opened.
            if ($requires && ! empty($data['required_parts'])) {
                $this->requiredParts->record($ticket, $data['required_parts'], $request->user());
            }

            // Promote the inspector's findings into first-class routable tasks (one per fault), so the
            // ticket can be split across garages from the moment it opens.
            if ($requires) {
                $this->tasks->syncFromFindings($ticket, $request->user());
                $ticket->load(self::EAGER);
            }

            // Hand the required parts to PROCUREMENT — now, not after a maintenance approval. Runs after
            // findings promotion so every request already carries its originating fault. Best-effort
            // internally: a procurement hiccup never fails the inspection the technician just filed.
            $partRequests = ($requires && ! empty($data['required_parts']))
                ? $this->requiredParts->raiseRequests($ticket, $request->user())
                : [];

            $photoSaved = false;
            if ($request->hasFile('odometer_photo')) {
                try {
                    $photoSaved = (bool) $this->storeOdometerPhoto($ticket, $request->file('odometer_photo'), $request->user(), 'report');
                } catch (\Throwable $e) {
                    report($e); // logged, never surfaced — the report already succeeded
                }
            }

            $partsRaised = count($partRequests);

            // Translate the step the inspector just completed into the canonical log. Deliberately
            // AFTER the transaction has committed and after findings were promoted, so the events
            // describe what was actually saved rather than what was attempted — and so a rollback
            // can never leave facts recorded about work that did not happen.
            $this->capture->inspectionSubmitted($ticket, $data, $request->user(), $requires);

            return ResponseHelper::SuccessResponse(
                [
                    'ticket'                => MaintenanceWorkflowResource::make($ticket),
                    'odometer_photo_saved'  => $photoSaved,
                    // So the UI can confirm procurement was actually reached, not just promised.
                    'part_requests_created' => $partsRaised,
                ],
                $requires
                    ? 'Requires maintenance — ticket opened, logistics notified'
                        . ($partsRaised > 0 ? " · {$partsRaised} part request(s) sent to procurement" : '')
                    : 'No maintenance needed — diagnostic closed',
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
                // Decision-support audit: what the recommendation engine suggested, whether the Supervisor
                // took it, and the reason — recorded in the garage_assigned event so "why this garage?" is
                // answerable later. Purely informational; the actual garage is still `vendor_id` above.
                'recommendation'                       => ['nullable', 'array'],
                'recommendation.recommended_vendor_id' => ['nullable', 'integer'],
                'recommendation.accepted'              => ['nullable', 'boolean'],
                'recommendation.rank'                  => ['nullable', 'integer'],
                'recommendation.score'                 => ['nullable', 'numeric'],
                'recommendation.match_score'           => ['nullable', 'integer', 'between:0,100'],
                'recommendation.confidence'            => ['nullable', 'string', 'max:12'],
                'recommendation.reason'                => ['nullable', 'string', 'max:500'],
                'recommendation.reasons'               => ['nullable', 'array'],
                'recommendation.breakdown'             => ['nullable', 'array'],
                'recommendation.strategy'              => ['nullable', 'array'],
                'recommendation.expected_outcomes'     => ['nullable', 'array'],
                'recommendation.fault_criticality'     => ['nullable', 'array'],
                'recommendation.provenance'            => ['nullable', 'array'],
                'recommendation.criteria'              => ['nullable', 'array'],
                'recommendation.source'                => ['nullable', 'string', 'max:60'],
                // The feedback loop. `override_reason` is validated against the configured taxonomy so
                // the counts can never be polluted by a stale client value; the service degrades
                // anything unrecognised to `other` as a second line of defence.
                'recommendation.override_reason'          => ['nullable', 'string', Rule::in(array_keys((array) config('garage_recommendation.override_reasons', [])))],
                'recommendation.override_note'            => ['nullable', 'string', 'max:500'],
                'recommendation.recommended_match_score'  => ['nullable', 'integer', 'between:0,100'],
                'recommendation.chosen_match_score'       => ['nullable', 'integer', 'between:0,100'],
                'recommendation.chosen_rank'              => ['nullable', 'integer'],
                'recommendation.chosen_advantages'        => ['nullable', 'array'],
                'recommendation.chosen_advantages.*'      => ['string', 'max:20'],
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
                'dispatch_odometer'    => ['required', 'integer', 'min:1', 'max:' . self::MAX_ODOMETER],
                'out_date'             => ['nullable', 'date'], // when the car left — defaults to today
                'expected_return_date' => ['nullable', 'date'],
                'odometer_photo'       => ['nullable', 'image', 'max:8192'], // ≤ 8 MB
                'odometer_note'        => ['nullable', 'string', 'max:2000'], // explanation for a >10 km gap
                'odometer_confirmed'   => ['nullable', 'boolean'],
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
    /**
     * Record what the tow COST and the towing company's paperwork (§7, §8).
     *
     * Separate from recoveryDispatch above on purpose: a recovery is dispatched in an emergency and the
     * price usually arrives days later on the towing company's invoice. Demanding it at dispatch time
     * would either block an urgent action or invite a guess that then becomes the figure posted to the
     * ledger.
     *
     * This NEVER changes workflow_status. Operational completion and financial readiness are separate
     * concepts (§8) — the car is back on the road whether or not the tow has been paid for — so this is
     * callable at any stage, including after the ticket has closed. What it does change is the financial
     * event, which the workflow service refreshes and which then blocks or turns READY on its own terms.
     */
    public function recoveryCost(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $data = $request->validate([
                'recovery_cost'             => ['required', 'numeric', 'gt:0', 'max:9999999'],
                'recovery_currency'         => ['nullable', 'string', 'max:8'],
                'recovery_vendor_id'        => ['nullable', 'integer', Rule::exists('vendors', 'id')],
                'recovery_invoice_required' => ['nullable', 'boolean'],
                'recovery_invoice_no'       => ['nullable', 'string', 'max:128'],
                'recovery_invoice_date'     => ['nullable', 'date'],
                // The towing company's bill. Optional here — the validator decides whether the document
                // type this becomes actually requires it, rather than this endpoint assuming so.
                'recovery_invoice'          => ['nullable', 'file', 'max:8192'],
            ]);

            $ticket = $this->workflow->recordRecoveryCost(
                $ticket,
                $data,
                $request->user(),
                $request->file('recovery_invoice')
            );

            return ResponseHelper::SuccessResponse(
                ['ticket' => MaintenanceWorkflowResource::make($ticket->load(self::EAGER))],
                'Recovery cost recorded',
                200
            );
        });
    }

    public function recoveryDispatch(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $data = $request->validate([
                'dispatch_odometer'    => ['required', 'integer', 'min:1', 'max:' . self::MAX_ODOMETER],
                'recovery_unit_name'   => ['required', 'string', 'max:191'],
                'recovery_unit_phone'  => ['nullable', 'string', 'max:40'],
                'odometer_note'        => ['nullable', 'string', 'max:2000'], // explanation for a >10 km gap
                'odometer_confirmed'   => ['nullable', 'boolean'],
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
                'receive_odometer'     => ['required', 'integer', 'min:1', 'max:' . self::MAX_ODOMETER],
                'odometer_photo'       => ['required', 'image', 'max:8192'], // ≤ 8 MB — arrival check-in shot
                'odometer_note'        => ['nullable', 'string', 'max:2000'], // explanation for a >10 km gap
                'odometer_confirmed'   => ['nullable', 'boolean'],
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

    /**
     * Reject a findings payload whose declared type contradicts its catalog reference BEFORE anything is
     * written, so the caller gets a 422 naming the finding rather than a 500 from the model guard
     * halfway through promoting a report.
     *
     * @param  array<int,array<string,mixed>>  $findings
     * @throws \Illuminate\Validation\ValidationException
     */
    private function assertFindingTypesAreCoherent(array $findings): void
    {
        $classifier = app(\App\Services\EventClassificationService::class);

        foreach ($findings as $i => $f) {
            if (empty($f['kind']) || (empty($f['catalog_id']) && empty($f['catalog_slug']))) {
                continue;
            }
            try {
                $classifier->classifyFromCatalog([
                    'kind'         => $f['kind'],
                    'catalog_id'   => $f['catalog_id'] ?? null,
                    'catalog_slug' => $f['catalog_slug'] ?? null,
                ]);
            } catch (\InvalidArgumentException $e) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "findings.{$i}.catalog_id" => $e->getMessage(),
                ]);
            }
        }
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

                // Event Type layer. `kind` is optional (an unlabelled finding is still classified from
                // its text), but if it IS sent it must be a real type and its catalog reference must
                // match — the API refuses to accept a mixed pair rather than storing one and silently
                // correcting the other. The model guard and DB CHECK are the two lines behind this.
                // WHERE + HOW MANY. Carried on the finding itself (this payload is already one object
                // per finding), unlike the Decide step's parallel `details` list. Same optionality.
                'findings.*.quantity'     => ['nullable', 'integer', 'min:1', 'max:' . (int) config('vehicle_locations.max_quantity', 40)],
                'findings.*.locations'    => ['nullable', 'array'],
                'findings.*.locations.*'  => ['string', 'max:60', Rule::exists('vehicle_locations', 'slug')->where('is_active', true)],

                'findings.*.kind'         => ['nullable', 'string', Rule::in(MaintenanceTask::KINDS)],
                'findings.*.catalog_id'   => ['nullable', 'integer'],
                'findings.*.catalog_slug' => ['nullable', 'string', 'max:80'],
            ]);

            $this->assertFindingTypesAreCoherent($data['findings']);

            $ticket = $this->workflow->addGarageFindings($ticket, $data['findings'], $request->user());

            // A garage-found fault is also a routable task — promote it so it can get its own garage.
            $this->tasks->syncFromFindings($ticket, $request->user());
            $ticket->load(self::EAGER);

            return ResponseHelper::SuccessResponse(MaintenanceWorkflowResource::make($ticket), 'Garage findings added', 200);
        });
    }

    /**
     * APPROVE OR REJECT one held finding.
     *
     * The car's own data disagreed with something a person logged — an oil change on a car with most of
     * its interval left, or a fault this car already had inside the window — so the finding was parked and
     * the ticket stopped where it stood. This is the button on the other end of that notification.
     *
     * Approve = an approver overrules the data (they, or the person in front of the car, can see something
     * it cannot): the finding becomes a real fault and the work goes ahead. Reject = it stays on the ticket
     * marked refused, never becomes work, and the ticket is free to move. Both are recorded on the car's
     * timeline naming WHO LOGGED IT and WHO DECIDED. See [[FindingApprovalService]].
     */
    public function decideFindingApproval(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $data = $request->validate([
                // The finding is named by its TEXT, which is its identity everywhere else in the
                // workflow (findings, tasks and the audit trail are all matched on it) — never by an
                // array index, which a concurrent write would silently shift under the approver.
                'finding' => ['required', 'string', 'max:255'],
                'action'  => ['required', 'string', Rule::in(['approve', 'reject'])],
                'note'    => ['nullable', 'string', 'max:500'],
            ]);

            $ticket = $this->approvals->decide(
                $ticket,
                $data['finding'],
                $data['action'],
                $data['note'] ?? null,
                $request->user(),
            );

            $ticket->load(self::EAGER);

            return ResponseHelper::SuccessResponse(
                MaintenanceWorkflowResource::make($ticket),
                $data['action'] === 'approve' ? 'Finding approved' : 'Finding rejected',
                200,
            );
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
                'final_odometer'  => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_ODOMETER],
                'odometer_photo'  => ['nullable', 'image', 'max:8192'],
                'odometer_note'   => ['nullable', 'string', 'max:2000'],
                'odometer_confirmed' => ['nullable', 'boolean'],
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
                'return_odometer' => ['required', 'integer', 'min:1', 'max:' . self::MAX_ODOMETER],
                'odometer_note'   => ['nullable', 'string', 'max:2000'], // explanation for a >10 km gap
                'odometer_confirmed' => ['nullable', 'boolean'],
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
                // Arrival-at-park odometer (mandatory) — the reading the moment the car is back at base on
                // the return leg. Continuity-checked vs the last recorded reading, and it becomes the at-base
                // anchor the final QA sign-off's strict ±5 km cap compares against.
                'park_odometer'  => ['required', 'integer', 'min:1', 'max:' . self::MAX_ODOMETER],
                'odometer_note'  => ['nullable', 'string', 'max:2000'],
                'odometer_confirmed' => ['nullable', 'boolean'],
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
     * Store one uploaded photo/video file against a ticket as a MaintenanceMedia row. Shared by the
     * garage repair-video ingest (storeVideo) and the driver's inspection-request evidence. Bytes land
     * on the local `public` disk; `kind` is derived from the mime. Returns the created row, or null if
     * the file could not be written (callers decide whether that's fatal).
     */
    private function storeTicketMedia(Maintenance $ticket, \Illuminate\Http\UploadedFile $file, ?\App\Models\User $user, ?string $note = null, ?int $taskId = null): ?\App\Models\MaintenanceMedia
    {
        $disk = 'public';
        $mime = (string) $file->getClientMimeType();
        $kind = str_starts_with($mime, 'image/') ? 'image' : 'video';
        $ext  = strtolower($file->getClientOriginalExtension() ?: ($file->guessExtension() ?: 'mp4'));
        $key  = $file->storeAs("maintenance-videos/ticket-{$ticket->id}", (string) Str::uuid() . '.' . $ext, $disk);
        if (! $key) {
            return null;
        }

        return $ticket->media()->create([
            'kind'                => $kind,
            'maintenance_task_id' => $taskId,
            'disk'                => $disk,
            's3_key'              => $key,
            'content_type'        => $mime,
            'original_name'       => $file->getClientOriginalName(),
            'file_size'           => $file->getSize(),
            'note'                => $note,
            'uploaded_by'         => $user?->id,
            'uploaded_by_name'    => $user?->name,
        ]);
    }

    /**
     * Persist one repair video (or still photo) against the ticket. The client posts the raw `file`
     * multipart; it is stored on the local `public` disk. Supervisor authority (maintenance.delegate).
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
                $media = $this->storeTicketMedia($ticket, $request->file('file'), $user, $data['note'] ?? null, $taskId);
                if (! $media) {
                    return ResponseHelper::FailureResponse(null, 'The file could not be stored.', 500);
                }
            } elseif (! empty($data['s3_key'])) {
                $mime = (string) ($data['content_type'] ?? '');
                $media = $ticket->media()->create([
                    'kind'                => str_starts_with($mime, 'image/') ? 'image' : 'video',
                    'maintenance_task_id' => $taskId,
                    'disk'                => $data['disk'] ?? 'public',
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
                Storage::disk($media->disk ?: 'public')->delete($media->s3_key);
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
                'final_odometer' => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_ODOMETER],
                'odometer_note'  => ['nullable', 'string', 'max:2000'],
                'odometer_confirmed' => ['nullable', 'boolean'],
                // Deferred-invoice: sign off + return the car to service now, but park in awaiting_invoice
                // (invoice outstanding) instead of a full close, so the workflow never gets stuck.
                'defer_invoice'  => ['nullable', 'boolean'],
                // Case D — the inspector attended but genuinely could not verify these faults (car already
                // gone, fault would not reproduce, needed a road test). Recorded as `unable_to_verify`
                // rather than being quietly signed off as fixed: a guessed PASS is a false positive in the
                // only trustworthy dataset the platform has.
                'unverifiable_task_ids'   => ['nullable', 'array'],
                'unverifiable_task_ids.*' => ['integer'],
                'unverifiable_reasons'    => ['nullable', 'array'], // { "<task_id>": "vehicle_unavailable" }
                'unverifiable_reasons.*'  => [Rule::in(\App\Models\RepairInspection::UNVERIFIABLE_REASONS)],
            ]);

            // Post-Repair Inspection (PASS) — a close coming off the final QC gate is a "Fixed
            // Successfully" verdict for every fault it verifies. Snapshot the ticket's faults BEFORE the
            // close so each gets a durable repair_inspections row (Case A). We record every non-cancelled
            // fault, not just the still-open ones: an in-shop repair completes its faults at the garage
            // (they're already `completed` by this gate), while an on-site job's faults are still open —
            // both are verified fixed by this PASS. A minor ticket that auto-closes without ever reaching
            // the gate records nothing — it never passed a QC check.
            $wasReinspection = $ticket->workflow_status === Maintenance::WF_READY_REINSPECTION;
            $verifiedFaults  = $wasReinspection
                ? $ticket->tasks()->where('status', '!=', \App\Models\MaintenanceTask::STATUS_CANCELLED)->get()
                : collect();

            // A PASSED re-inspection means every fault is verified fixed — resolve any still-open faults
            // so the container's task progress reflects the sign-off (the car still closes explicitly).
            foreach ($ticket->tasks()->whereNotIn('status', \App\Models\MaintenanceTask::TERMINAL)->get() as $task) {
                $this->tasks->setStatus($task, \App\Models\MaintenanceTask::STATUS_COMPLETED, $request->user());
            }

            $ticket = $this->workflow->close($ticket, $data, $request->user());

            // A PASS records "fixed" for every fault EXCEPT those the inspector flagged as unverifiable —
            // those carry the honest verdict and their own reason. Both count as coverage; only the
            // conclusive ones ever reach a statistic or a model (RepairInspection::CONCLUSIVE_RESULTS).
            $unverifiable = array_map('intval', $data['unverifiable_task_ids'] ?? []);
            $reasons      = $data['unverifiable_reasons'] ?? [];

            foreach ($verifiedFaults as $fault) {
                $couldNotVerify = in_array((int) $fault->id, $unverifiable, true);

                $this->inspections->recordForFault(
                    $ticket,
                    $fault,
                    $couldNotVerify
                        ? \App\Models\RepairInspection::RESULT_UNABLE_TO_VERIFY
                        : \App\Models\RepairInspection::RESULT_FIXED,
                    $couldNotVerify
                        ? ($reasons[$fault->id] ?? \App\Models\RepairInspection::UNVERIFIABLE_OTHER)
                        : null,
                    $data['notes'] ?? null,
                    $request->user(),
                );
            }

            // One user action — "close" — produces several distinct statements: a verification per
            // fault the QC gate passed, the release itself, and the garage's implicit completion
            // claim. The technician files none of them; they pressed one button.
            if ($wasReinspection) {
                $this->capture->repairVerified($ticket, $request->user(), \App\Models\RepairInspection::RESULT_FIXED);
            }
            $this->capture->ticketClosed($ticket, $request->user());

            $msg = $ticket->workflow_status === Maintenance::WF_AWAITING_INVOICE
                ? 'Vehicle back in service — invoice pending'
                : 'Ticket closed — vehicle back in service';
            return ResponseHelper::SuccessResponse(MaintenanceWorkflowResource::make($ticket), $msg, 200);
        });
    }

    /**
     * "Mark as Serviced" — complete the hands-on work of an ON-SITE (mobile) ticket (no garage, no transit).
     * It does NOT close the ticket and does NOT touch the vehicle's service data: it routes the ticket to the
     * final QA re-inspection (ready_for_reinspection), exactly like an in-shop repair. The faults stay OPEN
     * so the inspector can PASS (→ close, which confirms the routine service using the re-inspection
     * odometer) or FAIL (→ reopen). See MaintenanceWorkflowService::markServiced().
     */
    public function markServiced(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $data = $request->validate([
                'notes'          => ['nullable', 'string', 'max:2000'],
                'cost'           => ['nullable', 'numeric', 'min:0'],   // optional on-the-spot cost (else deferred)
                // On-site work never reaches a garage, so the vendor is a free-text mechanic name
                // (NOT a garage FK). Preserved by folding it into the notes carried to the closing summary.
                'vendor_name'    => ['nullable', 'string', 'max:120'],
            ]);

            // Fold the on-site vendor's name into the notes so it survives in the closing summary
            // (there is no garage vendor_id to attach — the car was fixed where it's parked).
            if (! empty($data['vendor_name'])) {
                $prefix = 'On-site vendor: ' . trim($data['vendor_name']);
                $data['notes'] = trim($data['notes'] ?? '') !== ''
                    ? $prefix . ' · ' . trim($data['notes'])
                    : $prefix;
            }
            unset($data['vendor_name']);

            // NOTE: the faults are deliberately left OPEN here. The service is only confirmed to the vehicle
            // at the re-inspection PASS (close), where the tasks are completed and the PASS odometer is used —
            // so an on-site oil/battery service can no longer update the vehicle by bypassing QA.
            $ticket = $this->workflow->markServiced($ticket, $data, $request->user());
            return ResponseHelper::SuccessResponse(
                MaintenanceWorkflowResource::make($ticket),
                'On-site service completed — pending final QA re-inspection',
                200
            );
        });
    }

    /**
     * Invoice Matching Desk (/invoice-matching) — the queue side of "the car is back, key the bill against
     * the work". One row per ticket whose car has come back from the shop, each carrying both halves of the
     * match: how many repaired faults are on a bill, and whether the bills agree with their own receipts.
     * The work/paper detail for a picked ticket is the ordinary ticket show payload — see
     * InvoiceMatchingService for how each state is derived.
     */
    public function invoiceMatchingQueue(Request $request, \App\Services\InvoiceMatchingService $desk)
    {
        return $this->run(function () use ($request, $desk) {
            $data = $request->validate([
                'days' => ['nullable', 'integer', 'min:1', 'max:730'],
            ]);

            return ResponseHelper::SuccessResponse(
                $desk->queue((int) ($data['days'] ?? \App\Services\InvoiceMatchingService::DEFAULT_WINDOW_DAYS)),
                'Invoice matching queue retrieved',
                200,
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
                // Post-Repair Inspection — the STRUCTURED reason each still-broken fault failed (Case B).
                // `failure_reason` is a single fallback for every failed fault; `failure_reasons` overrides
                // it per fault ({ "<task_id>": "wrong_diagnosis" }). Optional at the API layer (a legacy
                // send-back predates the enum → defaults to "unknown"); the modal makes the picker required.
                'failure_reason'       => ['nullable', Rule::in(\App\Models\RepairInspection::REASONS)],
                'failure_reasons'      => ['nullable', 'array'],
                'failure_reasons.*'    => [Rule::in(\App\Models\RepairInspection::REASONS)],
                // Case C — brand-new problems the inspection surfaced (original faults fine, something else
                // is wrong). Each spawns a fresh fault on the ticket, linked to its inspection record.
                'new_issues'              => ['nullable', 'array'],
                'new_issues.*.symptom'    => ['required_with:new_issues', 'string', 'max:255'],
                'new_issues.*.severity'   => ['nullable', Rule::in(array_keys(Maintenance::FAULT_SEVERITY_META))],
                'new_issues.*.category_key' => ['nullable', 'string', 'max:120'],
                'new_issues.*.notes'      => ['nullable', 'string', 'max:2000'],
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

            // Double-submit guard: the per-fault mechanics below (failReinspection bumps the blame
            // counter) run BEFORE the ticket transition asserts — so a duplicate send-back must be
            // stopped here, or a resent request would double-count reinspection_failures per fault.
            if ($ticket->fresh()->workflow_status === Maintenance::WF_REINSPECTION_FAILED) {
                throw new \App\Exceptions\WorkflowTransitionException(
                    'This re-inspection failure was already recorded — the ticket is back with the supervisor for re-dispatch.',
                    ['field' => 'workflow_status'],
                );
            }

            $user = $request->user();
            // Every fault this sign-off passes judgement on (an in-shop repair's faults are already
            // `completed` by the gate, an on-site job's are still open — both get a verdict). Cancelled
            // faults (non-issues) are excluded. NOTE: we intentionally drive BOTH the guard and the fail
            // mechanics below off `$allFaults`, NOT the open-only set. An in-shop ticket reaches this gate
            // with every fault already `completed`, so an open-only set is empty — which used to silently
            // skip failReinspection() (car bounced back with 0 open faults, garage blame never counted)
            // and skip the "flag something" guard (an empty send-back was accepted). Both are fixed here.
            $allFaults = $ticket->tasks()->where('status', '!=', \App\Models\MaintenanceTask::STATUS_CANCELLED)->get();
            $failedIds = array_map('intval', $data['failed_task_ids'] ?? []);
            $notes     = (array) ($data['failed_notes'] ?? []);
            $reasons   = (array) ($data['failure_reasons'] ?? []);
            $newIssues = array_values($data['new_issues'] ?? []);

            // When the ticket carries faults, sending it back needs at least one still-broken fault OR a
            // newly-found problem — an all-passed re-inspection with nothing new should CLOSE, not fail.
            // (A legacy ticket with no tasks skips this.)
            if ($allFaults->isNotEmpty() && empty($failedIds) && empty($newIssues)) {
                throw new \App\Exceptions\WorkflowTransitionException(
                    'Flag at least one fault that is still not fixed (or a new problem found) — or pass the re-inspection to close the ticket.',
                    ['field' => 'failed_task_ids'],
                );
            }

            // Post-Repair Inspection records — DECOUPLED from the mechanics below so every judged fault
            // gets a durable verdict regardless of its current status (a completed in-shop fault included).
            // Recorded BEFORE failReinspection so a still_exists snapshots the garage it came back from.
            foreach ($allFaults as $fault) {
                if (in_array($fault->id, $failedIds, true)) {
                    $reason = $reasons[$fault->id] ?? ($data['failure_reason'] ?? null);
                    $note   = array_key_exists($fault->id, $notes) ? (string) $notes[$fault->id] : null;
                    $this->inspections->recordForFault($ticket, $fault, \App\Models\RepairInspection::RESULT_STILL_EXISTS, $reason, $note, $user); // Case B
                } else {
                    $this->inspections->recordForFault($ticket, $fault, \App\Models\RepairInspection::RESULT_FIXED, null, null, $user); // Case A
                }
            }

            // MECHANICS — driven off EVERY judged fault (`$allFaults`), so an in-shop repair (whose faults
            // are already `completed` at this gate) is handled exactly like an on-site job whose faults are
            // still open. A flagged fault is RE-OPENED via failReinspection() regardless of its current
            // status (it drops the fault back to `pending`, detaches + blames the garage, bumps the failure
            // counter) — this is the fix for the in-shop case where the flagged fault previously stayed
            // `completed` and sailed straight back through to close. A fault that is NOT flagged is verified
            // fixed: on-site jobs are advanced to `completed`; already-`completed` in-shop faults are left
            // untouched (no duplicate resolve event).
            $failedSummary = [];
            foreach ($allFaults as $task) {
                if (in_array($task->id, $failedIds, true)) {
                    // Capture the failing garage BEFORE we detach the fault from it. `current_vendor_id`
                    // survives a completed fault (it is not nulled on resolve), so the blame is captured
                    // for in-shop faults too.
                    $garageName = $task->currentVendor?->name
                        ?: \App\Models\Vendor::whereKey($task->current_vendor_id)->value('name');
                    $note = array_key_exists($task->id, $notes) ? (string) $notes[$task->id] : null;
                    $this->tasks->failReinspection($task, $note, $user);
                    $failedSummary[] = ['id' => $task->id, 'symptom' => $task->symptom, 'garage' => $garageName];
                } elseif ($task->status !== \App\Models\MaintenanceTask::STATUS_COMPLETED) {
                    // Not flagged broken and not yet resolved (on-site job) → verified fixed at this
                    // re-inspection. Already-completed in-shop faults keep their existing resolution.
                    $this->tasks->setStatus($task, \App\Models\MaintenanceTask::STATUS_COMPLETED, $user);
                }
            }

            // Case C — materialise each newly-found problem as a fresh fault on the ticket, and stamp its
            // inspection record so the new fault traces back to the check that surfaced it. It rides the
            // send-back to the supervisor for re-dispatch alongside any still-broken faults.
            foreach ($newIssues as $issue) {
                $newFault = \App\Models\MaintenanceTask::create([
                    'maintenance_id' => $ticket->id,
                    'vehicle_id'     => $ticket->vehicle_id,
                    'symptom'        => trim((string) $issue['symptom']),
                    'category_key'   => $issue['category_key'] ?? null,
                    'source'         => Maintenance::FINDING_INSPECTOR,
                    'severity'       => $issue['severity'] ?? $ticket->fault_severity,
                    'status'         => \App\Models\MaintenanceTask::STATUS_PENDING,
                    'identified_by'  => $user->id,
                    'identified_at'  => now(),
                ]);
                $this->inspections->recordNewIssue($ticket, $newFault, $issue['notes'] ?? null, $user);
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

    /**
     * THE PARTS THIS TICKET ALREADY KNOWS ABOUT — what the invoice form should be offering instead of
     * an empty search box.
     *
     * By the time a bill is keyed, the part has usually been named two or three times already: the
     * inspector listed it as required, the coordinator raised a request, someone bought it at a price
     * we recorded. Making the biller search the catalog from scratch throws all of that away — the
     * quantity is re-typed, the price is re-typed from a receipt we already hold, and the new line
     * joins to none of it.
     *
     * Three sources, deduped by IDENTITY (catalog id, else normalised wording) with the most-advanced
     * record winning, because they are the same part at three stages, not three parts:
     *
     *   purchase  — money actually spent. Carries the unit price and quantity, so the line can be
     *               filled in completely. `already_billed` when it produced a cost line at fitting;
     *               the form shows it as accounted for rather than offering it twice.
     *   request   — asked for, not yet bought. Name and quantity, no price.
     *   required  — the inspector's technical list. The earliest and least certain, offered last.
     *
     * A non-base-currency purchase deliberately ships NO price: converting it here would invent an
     * exchange rate, and a wrong number on an invoice is worse than a blank one.
     */
    public function billableParts(Maintenance $ticket, Request $request)
    {
        return $this->run(function () use ($ticket, $request) {
            $base  = strtoupper((string) config('parts_intelligence.base_currency', 'AED'));
            $items = [];

            // WHOSE BILL IS BEING WRITTEN. A part is only billable on the bill of whoever supplied it,
            // so the answer to "can this go on the invoice" depends on the invoice — the same part is
            // billable here and refused next door. The form passes the garage it is currently writing
            // for; with no vendor named nothing is ruled out, and the caller is simply browsing.
            $billVendorId = $request->filled('vendor_id') ? (int) $request->input('vendor_id') : null;
            $billInternal = filter_var($request->input('is_internal'), FILTER_VALIDATE_BOOL);
            $ruling       = $request->filled('vendor_id') || $request->has('is_internal');

            // Identity of a row for dedupe: the catalog reference when there is one, else the wording
            // normalised the same way part identity normalises it.
            $identity = fn (?int $catalogId, ?string $name) => $catalogId
                ? "c:{$catalogId}"
                : 'n:' . (app(\App\Services\PartIdentityService::class)->nameKey($name) ?? mb_strtolower(trim((string) $name)));

            $take = function (string $key, array $item) use (&$items) {
                // First writer wins — the sources are added most-advanced first.
                if (! isset($items[$key])) {
                    $items[$key] = $item;
                }
            };

            /**
             * WHICH BILL A BOUGHT PART BELONGS ON.
             *
             * The mirror of PartInvoiceService::assertAttachable, answered before the biller can pick
             * rather than after they save. The supplier who sold the part is the one who invoices it:
             *
             *   bought from a supplier  →  that supplier's parts invoice, never a garage bill
             *   bought from garage X    →  garage X's bill, and no other garage's
             *   already fitted          →  its cost line exists; billing it again charges it twice
             *
             * Returns a CODE and its parameters, never a sentence — the wording is the reader's,
             * in the reader's language.
             */
            $verdict = function (\App\Models\PartPurchase $p) use ($ruling, $billVendorId, $billInternal) {
                if ($p->maintenance_line_item_id !== null) {
                    return ['ALREADY_BILLED', []];
                }
                if (! $ruling) {
                    return [null, []];   // no bill named — nothing to rule against
                }
                if ($p->purchase_source === \App\Models\PartPurchase::SOURCE_SUPPLIER) {
                    return ['SUPPLIER_SOURCED', array_filter([
                        'supplier'        => $p->source_name ?: $p->sourceVendor?->name,
                        'part_invoice_id' => $p->part_invoice_id,
                    ], fn ($v) => $v !== null)];
                }
                // A garage buy with no garage recorded is history from before the source was captured:
                // it cannot be shown to belong elsewhere, so it is not refused here.
                if ($p->source_vendor_id && ($billInternal || $p->source_vendor_id !== $billVendorId)) {
                    return ['OTHER_GARAGE', array_filter([
                        'garage'    => $p->source_name ?: $p->sourceVendor?->name,
                        'vendor_id' => $p->source_vendor_id,
                    ], fn ($v) => $v !== null)];
                }

                return [null, []];
            };

            $purchases = \App\Models\PartPurchase::where('maintenance_id', $ticket->id)
                ->with(['task:id,symptom', 'sourceVendor:id,name'])
                ->get();

            foreach ($purchases as $p) {
                $isBase = strtoupper((string) $p->currency) === $base;
                [$blockCode, $blockParams] = $verdict($p);

                $take($identity($p->component_catalog_id, $p->part_name), [
                    'source'               => 'purchase',
                    'id'                   => $p->id,
                    'part_name'            => $p->part_name,
                    'part_number'          => $p->part_number,
                    'component_catalog_id' => $p->component_catalog_id,
                    'category_key'         => $p->category_key,
                    'quantity'             => (float) ($p->quantity ?: 1),
                    'unit_price'           => $isBase && $p->purchase_price !== null ? (float) $p->purchase_price : null,
                    'currency'             => $p->currency,
                    'finding_text'         => $p->task?->symptom,
                    'installed_on'         => optional($p->installed_at)->toDateString(),
                    // Fitting a purchase already writes a cost line (PartWorkflowService::installPurchase).
                    // Offering it again would bill the same part twice.
                    'already_billed'       => $p->maintenance_line_item_id !== null,
                    // WHERE IT CAME FROM — shown on the form, and the reason it can or cannot be billed.
                    'purchase_source'      => $p->purchase_source,
                    'source_vendor_id'     => $p->source_vendor_id,
                    'source_name'          => $p->source_name ?: $p->sourceVendor?->name,
                    'billable_here'        => $blockCode === null,
                    'block_code'           => $blockCode,
                    'block_params'         => $blockParams ?: null,
                ]);
            }

            foreach (\App\Models\PartRequest::where('maintenance_id', $ticket->id)->with('task:id,symptom')->get() as $r) {
                $take($identity($r->component_catalog_id, $r->part_name), [
                    'source'               => 'request',
                    'id'                   => $r->id,
                    'part_name'            => $r->part_name,
                    'part_number'          => $r->part_number,
                    'component_catalog_id' => $r->component_catalog_id,
                    'category_key'         => null,
                    'quantity'             => (float) ($r->quantity ?: 1),
                    'unit_price'           => null,
                    'currency'             => null,
                    'finding_text'         => $r->task?->symptom,
                    'installed_on'         => null,
                    'already_billed'       => false,
                    // Asked for, not yet bought: nobody has supplied it, so no supplier owns the bill
                    // and there is nothing to refuse. The price is still missing and must be keyed.
                    'purchase_source'      => null,
                    'source_vendor_id'     => null,
                    'source_name'          => null,
                    'billable_here'        => true,
                    'block_code'           => null,
                    'block_params'         => null,
                ]);
            }

            $required = \App\Models\MaintenanceRequiredPart::where('maintenance_id', $ticket->id)
                ->where('status', '!=', \App\Models\MaintenanceRequiredPart::STATUS_DISMISSED)
                ->with('task:id,symptom')
                ->get();

            foreach ($required as $rp) {
                $take($identity($rp->component_catalog_id, $rp->part_name), [
                    'source'               => 'required',
                    'id'                   => $rp->id,
                    'part_name'            => $rp->part_name,
                    'part_number'          => null,
                    'component_catalog_id' => $rp->component_catalog_id,
                    'category_key'         => null,
                    'quantity'             => (float) ($rp->quantity ?: 1),
                    'unit_price'           => null,
                    'currency'             => null,
                    'finding_text'         => $rp->task?->symptom ?: $rp->finding_text,
                    'installed_on'         => null,
                    'already_billed'       => false,
                    'purchase_source'      => null,
                    'source_vendor_id'     => null,
                    'source_name'          => null,
                    'billable_here'        => true,
                    'block_code'           => null,
                    'block_params'         => null,
                ]);
            }

            return ResponseHelper::SuccessResponse(
                ['parts' => array_values($items)],
                'Parts on this ticket retrieved',
                200
            );
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
            // Which catalog part a part line fitted — the identity behind the billed wording. Nullable:
            // labor lines have none, and a line may still arrive from a surface without the picker.
            'line_items.*.component_catalog_id' => ['nullable', 'integer', 'exists:component_catalog,id'],
            // WHERE the billed part came from — the purchase paid for, the request raised, or the
            // inspector's required-part line. MaintenanceInvoiceService::assertPartBillable REFUSES a
            // part line that names no such record ("part_not_on_ticket"), so without these two the
            // endpoint could not bill a part at all: they were stripped by normalizeLineItems() and the
            // guard then rejected every part as unrecorded. Same rules the /invoices endpoint already
            // carries (MaintenanceInvoiceController) — the two write paths must accept the same shape.
            'line_items.*.part_source'         => ['nullable', Rule::in(\App\Models\MaintenanceLineItem::PART_SOURCES)],
            'line_items.*.part_source_id'      => ['nullable', 'integer', 'min:1'],
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
            // Tyre audit trail — captured by the editor on a tyres-category part line. Accepted here
            // too, or the brand/DOT/tread a user typed would be dropped on this path alone.
            'line_items.*.tire_brand'          => ['nullable', 'string', 'max:80'],
            'line_items.*.tire_dot'            => ['nullable', 'string', 'max:40'],
            'line_items.*.tire_tread_mm'       => ['nullable', 'numeric', 'min:0'],
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
        $allowed = ['kind', 'description', 'part_number', 'component_catalog_id', 'finding_text', 'category_key',
                    // part_source/part_source_id are what prove a billed part was recorded on this ticket.
                    // Dropping them here is what made every part line fail assertPartBillable().
                    'part_source', 'part_source_id',
                    'quantity', 'uom', 'unit_price', 'installed_on', 'installed_odometer', 'warranty_months', 'entry_source',
                    'tire_brand', 'tire_dot', 'tire_tread_mm'];

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
     * Who a Supervisor can hand the collection to: ACTIVE users in the DRIVER role (`logistics`) — the
     * field pool that actually collects cars — plus the SUPERVISORS, who really do fetch a car themselves
     * when no driver is free. Still deliberately NOT `permission('maintenance.logistics')`: that permission
     * also reaches workshop managers and admins, and listing them turned the picker into a staff directory
     * where the real driver was one name in nine.
     *
     * Each row carries `role` ('driver' | 'supervisor') so the picker can group them and the supervisor
     * knows he is booking a colleague off his own bench rather than a driver.
     *
     * The requesting user is excluded from their own list. Assigning the job to yourself is not a
     * delegation — to take the car yourself you press "Pick up" on the ticket, which records you as the
     * custodian directly instead of routing a notification back to yourself.
     *
     * Feeds the Assign-driver picker. Returns a flat {id, name, email, role} list, drivers first.
     */
    public function assignableDrivers(Request $request)
    {
        return $this->run(function () use ($request) {
            $hasStatus = \Illuminate\Support\Facades\Schema::hasColumn('users', 'status');
            $people = \App\Models\User::role(['logistics', MaintenanceWorkflowService::SUPERVISOR_ROLE])
                ->when($hasStatus, fn ($q) => $q->where('status', 'active'))
                ->where('id', '!=', $request->user()->id)
                ->orderBy('name')
                ->get(['id', 'name', 'email']);

            $rows = $people->map(function ($u) {
                // A user carrying both roles is shown as a driver — that's the pool the job belongs to.
                $role = $u->hasRole('logistics') ? 'driver' : 'supervisor';

                return ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $role];
            })
                // Drivers first: they are the default answer, supervisors the fallback when none is free.
                ->sortBy(fn ($r) => [$r['role'] === 'driver' ? 0 : 1, $r['name']])
                ->values()
                ->all();

            return ResponseHelper::SuccessResponse($rows, 'Assignable drivers retrieved', 200);
        });
    }

    /**
     * Supervisor names the driver who collects the car (→ "Driver Assigned"). Awaiting-Pickup only;
     * the leg is always a pickup at that stage, so no task is asked for or accepted.
     */
    public function delegate(Request $request, Maintenance $ticket)
    {
        return $this->run(function () use ($request, $ticket) {
            $data = $request->validate([
                'driver_id' => ['required', 'integer', Rule::exists('users', 'id')],
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
     * Post-Repair Inspection history for one ticket — the durable QC verdicts (fixed / still_exists /
     * new_issue) recorded at sign-off. Drives the drawer's "Repair Quality Check" panel, including the
     * "REPAIR FAILED" card (fault, previous repair garage + date, reason). Read-only (maintenance.view).
     */
    public function repairInspections(Maintenance $ticket)
    {
        return $this->run(function () use ($ticket) {
            $rows = $ticket->repairInspections()
                ->with(['fault:id,symptom', 'newFault:id,symptom', 'inspector:id,name', 'previousVendor:id,name'])
                ->get();
            return ResponseHelper::SuccessResponse(
                \App\Http\Resources\RepairInspectionResource::collection($rows),
                'Repair inspections retrieved',
                200
            );
        });
    }

    /**
     * Repair Quality Tracking — the fleet-wide quality report: per-garage success rate (repairs completed
     * vs how many came back still broken) and the parts flagged as a Possible Part Failure. Read authority
     * (maintenance.view); managers/admins read it for oversight.
     */
    public function repairQuality()
    {
        return $this->run(function () {
            return ResponseHelper::SuccessResponse([
                'technicians'  => $this->inspections->technicianQuality(),
                'part_signals' => $this->inspections->partFailureSignals(),
            ], 'Repair quality retrieved', 200);
        });
    }

    /** Every post-repair verdict recorded for one vehicle (newest first) — the car's repair-quality trail. */
    public function vehicleRepairQuality(Vehicle $vehicle)
    {
        return $this->run(function () use ($vehicle) {
            $rows = $this->inspections->vehicleHistory($vehicle->id);
            return ResponseHelper::SuccessResponse(
                \App\Http\Resources\RepairInspectionResource::collection($rows),
                'Vehicle repair quality retrieved',
                200
            );
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
                'odometer'  => ['required', 'integer', 'min:1', 'max:' . self::MAX_ODOMETER],
                // Explanation for a >10 km gap from the last recorded reading (mandatory in the UI when it fires).
                'odometer_note' => ['nullable', 'string', 'max:2000'],
                // Transport Responsibility — optional custodian (a driver / logistics officer). When named,
                // the move is recorded as a real logistics task below; omitted → no transport task is spawned.
                'assigned_to_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
                // Set true to move ahead after the operator acknowledges the Conflict Check warning.
                'acknowledge_conflict' => ['nullable', 'boolean'],
                // How the car will actually move: a company driver, or a recovery (tow) truck for a car
                // that isn't drivable. Defaults to 'driver' — omitted by any older client, unchanged behaviour.
                'transport_method' => ['nullable', Rule::in([Maintenance::TRANSPORT_DRIVER, Maintenance::TRANSPORT_RECOVERY])],
            ]);
            $transportMethod = $data['transport_method'] ?? Maintenance::TRANSPORT_DRIVER;

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
                $this->workflow->beginGarageTransfer($ticket, $vendor, $data['assigned_to_id'] ?? null, $data['reason'] ?? null, $request->user(), $transportMethod);

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
                // Actual mechanic time for THIS repair attempt (Option A: per-attempt entry, cumulative
                // is derived). Lands write-once on the stint this fix closes — distinct from the
                // auto-derived wall-clock elapsed, which is never manually editable.
                'labor_hours' => ['nullable', 'numeric', 'min:0', 'max:' . \App\Services\FaultRepairTimeService::MAX_ATTEMPT_LABOR_HOURS],
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

            // Recording labor is a distinct authority from moving a fault's status: this route is open to
            // the whole workshop delegate role, but booking hours against a repair is the act that feeds
            // cost and garage performance, so it carries its own permission. Omitting labor_hours needs
            // nothing extra — only the person actually entering time is gated.
            if (isset($data['labor_hours']) && $data['labor_hours'] !== ''
                && ! $request->user()?->can('maintenance.labor.record')) {
                throw new \App\Exceptions\WorkflowTransitionException(
                    'You are not authorised to record labor time on a repair.',
                    ['field' => 'labor_hours'],
                );
            }

            $this->tasks->setStatus(
                $task,
                $data['status'],
                $request->user(),
                $data['note'] ?? null,
                $data['odometer'] ?? null,
                isset($data['labor_hours']) && $data['labor_hours'] !== '' ? (float) $data['labor_hours'] : null,
            );
            return $this->ticketFor($task, 'Fault status updated');
        });
    }

    /**
     * WORKSHOP CONFIRMATION — the technician confirms a reported fault is real while the car is In Workshop.
     * `confirmed` is the only accepted verdict; it opens a recurring-fault review (RecurringFaultService).
     * The "not a real fault" outcome is the separate Incorrect path (markTaskIncorrect). Non-blocking,
     * independent of the repair status.
     */
    public function confirmTask(Request $request, \App\Models\MaintenanceTask $task)
    {
        return $this->run(function () use ($request, $task) {
            $data = $request->validate([
                'confirmation_status' => ['required', Rule::in(\App\Models\MaintenanceTask::CONFIRMATION_STATUSES)],
                'note'                => ['nullable', 'string', 'max:2000'],
            ]);

            $this->tasks->confirmFault($task, $data['confirmation_status'], $request->user(), $data['note'] ?? null);
            return $this->ticketFor($task, 'Fault review recorded');
        });
    }

    // ── THE PER-FAULT WORK CLOCK ──────────────────────────────────────────────────────────────────
    // Four doors onto maintenance_task_work_sessions. They exist so "how long did this fault take" is
    // measured rather than inferred: active labor is the sum of the work intervals clocked here, and
    // everything else the car spent waiting for is recorded as a named block instead of silently
    // inflating the repair. See FaultWorkSessionService for the one-open-session invariant.

    /** START or RESUME hands-on work on this fault. Idempotent — a double-tap does not double the clock. */
    public function startFaultWork(Request $request, \App\Models\MaintenanceTask $task, \App\Services\FaultWorkSessionService $sessions)
    {
        return $this->run(function () use ($request, $task, $sessions) {
            $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
            $sessions->startWork($task, $request->user(), $data['note'] ?? null);

            return $this->ticketFor($task, 'Work clock started');
        });
    }

    /**
     * PAUSE work and say what it is waiting for. The reason is mandatory and comes from a closed list —
     * an unexplained pause is exactly the ambiguity this whole ledger exists to remove.
     */
    public function pauseFaultWork(Request $request, \App\Models\MaintenanceTask $task, \App\Services\FaultWorkSessionService $sessions)
    {
        return $this->run(function () use ($request, $task, $sessions) {
            $data = $request->validate([
                'block_reason' => ['required', Rule::in(\App\Models\MaintenanceTaskWorkSession::BLOCK_REASONS)],
                'note'         => ['nullable', 'string', 'max:500'],
            ]);
            $sessions->block($task, $data['block_reason'], $request->user(), $data['note'] ?? null);

            return $this->ticketFor($task, 'Work paused');
        });
    }

    /**
     * CORRECT an already-recorded attempt labor value. Behind `maintenance.labor.correct` (the route),
     * needs a reason, writes an audit event — and is still bound by the work-time ceiling: correcting a
     * number is not a way around the rule.
     */
    public function correctFaultLabor(Request $request, \App\Models\MaintenanceTaskAssignment $stint, \App\Services\FaultRepairTimeService $repairTime)
    {
        return $this->run(function () use ($request, $stint, $repairTime) {
            $data = $request->validate([
                'labor_hours' => ['required', 'numeric', 'min:0', 'max:' . \App\Services\FaultRepairTimeService::MAX_ATTEMPT_LABOR_HOURS],
                'reason'      => ['required', 'string', 'max:500'],
            ]);
            $repairTime->overwriteAttemptLabor($stint, (float) $data['labor_hours'], $data['reason'], $request->user());

            return $this->ticketFor($stint->task, 'Labor time corrected');
        });
    }

    /**
     * EXCEPTIONAL OVERRIDE — book labor ABOVE the recorded work time. Behind its own permission
     * (`maintenance.labor.override`), needs a substantive reason, and stamps the row `labor_basis =
     * override` plus a dedicated audit event naming the ceiling that was exceeded. Deliberately not a
     * silent admin bypass: the number survives, flagged, and analytics can exclude it.
     */
    public function overrideFaultLabor(Request $request, \App\Models\MaintenanceTaskAssignment $stint, \App\Services\FaultRepairTimeService $repairTime)
    {
        return $this->run(function () use ($request, $stint, $repairTime) {
            $data = $request->validate([
                'labor_hours' => ['required', 'numeric', 'min:0', 'max:' . \App\Services\FaultRepairTimeService::MAX_ATTEMPT_LABOR_HOURS],
                'reason'      => ['required', 'string', 'min:10', 'max:500'],
            ]);
            $repairTime->overrideAttemptLabor($stint, (float) $data['labor_hours'], $data['reason'], $request->user());

            return $this->ticketFor($stint->task, 'Labor override recorded');
        });
    }

    /**
     * What the capture form should offer for THIS fault — the suggested actions for its system, plus
     * the outcome and verification vocabularies and whatever was captured before.
     *
     * Served from the server so the picker opens on the right handful of actions rather than the full
     * ninety-entry catalogue. A picker that requires scrolling is a picker people route around.
     */
    public function captureOptions(\App\Models\MaintenanceTask $task, \App\Services\RepairCaptureService $capture)
    {
        return $this->run(function () use ($task, $capture) {
            $existing = \App\Models\MaintenanceTaskAction::with('action')
                ->where('maintenance_task_id', $task->id)
                ->orderBy('sequence')
                ->get();

            return ResponseHelper::SuccessResponse([
                'fault' => [
                    'id'         => $task->id,
                    'symptom'    => $task->symptom,
                    'category'   => $task->category_key,
                    'root_cause' => $task->root_cause,
                    'status'     => $task->status,
                ],
                'suggested_actions' => $capture->suggestedActions($task)->map(fn ($a) => [
                    'id'              => $a->id,
                    'label'           => $a->label,
                    'verb'            => $a->verb,
                    'target'          => $a->target,
                    'requires_part'   => $a->requires_part,
                    'is_verification' => $a->is_verification,
                    'default_hours'   => $a->default_labor_hours,
                ])->values(),
                'outcomes' => \App\Services\RepairCaptureService::OUTCOMES,
                // Verification methods are deliberately NOT offered here — verification is the
                // inspector's act, served by verificationOptions().
                'captured' => [
                    'actions'             => $existing->map(fn ($a) => [
                        'action_catalog_id' => $a->action_catalog_id,
                        'label'             => $a->action?->label,
                        'sequence'          => $a->sequence,
                        'note'              => $a->note,
                    ])->values(),
                    'claimed_outcome'     => $task->claimed_outcome,
                    'no_fault_found'      => $task->no_fault_found,
                    // Null here means UNKNOWN, never "complete" — the UI must render it that way.
                    'captured_at'         => optional($task->claimed_outcome_at)->toIso8601String(),
                ],
            ], 'Capture options', 200);
        });
    }

    /**
     * Record Tier 1 repair capture. See [[RepairCaptureService]] for why it is only four questions.
     */
    public function captureRepair(Request $request, \App\Models\MaintenanceTask $task, \App\Services\RepairCaptureService $capture)
    {
        return $this->run(function () use ($request, $task, $capture) {
            $data = $request->validate([
                'actions'                     => ['nullable', 'array', 'max:30'],
                'actions.*.action_catalog_id' => ['required_with:actions', 'integer', Rule::exists('action_catalog', 'id')],
                'actions.*.note'              => ['nullable', 'string', 'max:500'],
                'actions.*.line_item_id'      => ['nullable', 'integer', Rule::exists('maintenance_line_items', 'id')],

                'claimed_outcome' => ['nullable', Rule::in(\App\Services\RepairCaptureService::OUTCOMES)],
                'no_fault_found'  => ['nullable', 'boolean'],
                'note'            => ['nullable', 'string', 'max:2000'],

                // Rollout instrumentation, supplied by the client. Never required — telemetry must
                // not be able to block the work it is measuring.
                'session_id'       => ['nullable', 'uuid'],
                'duration_ms'      => ['nullable', 'integer', 'min:0'],
                'last_step'        => ['nullable', 'integer', 'min:0', 'max:10'],
                'skipped_fields'   => ['nullable', 'array', 'max:20'],
                'skipped_fields.*' => ['string', 'max:48'],
            ]);

            $capture->capture($task, $data, $request->user());

            return $this->ticketFor($task, 'Repair recorded');
        });
    }

    /** Opens a friction session when the capture form opens — see [[CaptureFrictionService]]. */
    public function captureStart(Request $request, \App\Models\MaintenanceTask $task, \App\Services\CaptureFrictionService $friction)
    {
        return $this->run(function () use ($request, $task, $friction) {
            return ResponseHelper::SuccessResponse([
                'session_id' => $friction->start('repair_capture', $task->id, $task->maintenance_id, $request->user(), 4),
            ], 'Capture started', 200);
        });
    }

    /**
     * Closes a friction session that was never completed.
     *
     * Without this the abandonment rate is unmeasurable: rows only ever existed for people who
     * finished, so the population that would prove abandonment was exactly the one missing.
     */
    public function captureAbandon(Request $request, \App\Models\MaintenanceTask $task, \App\Services\CaptureFrictionService $friction)
    {
        return $this->run(function () use ($request, $friction) {
            $data = $request->validate([
                'session_id'  => ['required', 'uuid'],
                'duration_ms' => ['nullable', 'integer', 'min:0'],
                'last_step'   => ['nullable', 'integer', 'min:0', 'max:10'],
            ]);

            $friction->resolve($data['session_id'], \App\Services\CaptureFrictionService::STATUS_ABANDONED, $data);

            return ResponseHelper::SuccessResponse(null, 'Capture abandoned', 200);
        });
    }

    /**
     * What the inspector's verification form should offer — and whether this user may verify at all.
     */
    public function verificationOptions(Request $request, \App\Models\MaintenanceTask $task, \App\Services\RepairVerificationService $verification)
    {
        return $this->run(function () use ($request, $task, $verification) {
            $eligibility = $verification->eligibility($task, $request->user());

            return ResponseHelper::SuccessResponse(array_merge($eligibility, [
                'fault' => [
                    'id'         => $task->id,
                    'symptom'    => $task->symptom,
                    'root_cause' => $task->root_cause,
                ],
                // What the workshop claimed, shown so the inspector checks against a stated claim
                // rather than forming an impression from scratch.
                'workshop_claim' => [
                    'claimed_outcome' => $task->claimed_outcome,
                    'claimed_at'      => optional($task->claimed_outcome_at)->toIso8601String(),
                    'no_fault_found'  => $task->no_fault_found,
                    'actions'         => \App\Models\MaintenanceTaskAction::with('action')
                        ->where('maintenance_task_id', $task->id)
                        ->orderBy('sequence')
                        ->get()
                        ->map(fn ($a) => ['sequence' => $a->sequence, 'label' => $a->action?->label, 'note' => $a->note])
                        ->values(),
                ],
                'results' => \App\Services\RepairVerificationService::RESULTS,
                'methods' => \App\Services\RepairCaptureService::VERIFICATION_METHODS,
                'verified' => $task->verified_at ? [
                    'result'      => $task->verification_result,
                    'method'      => $task->verification_method,
                    'note'        => $task->verification_note,
                    'verified_at' => optional($task->verified_at)->toIso8601String(),
                ] : null,
            ]), 'Verification options', 200);
        });
    }

    /** Record an independent verification. See [[RepairVerificationService]]. */
    public function verifyRepair(Request $request, \App\Models\MaintenanceTask $task, \App\Services\RepairVerificationService $verification)
    {
        return $this->run(function () use ($request, $task, $verification) {
            $data = $request->validate([
                'result' => ['required', Rule::in(\App\Services\RepairVerificationService::RESULTS)],
                'method' => ['required', Rule::in(\App\Services\RepairCaptureService::VERIFICATION_METHODS)],
                'note'   => ['nullable', 'string', 'max:2000'],
            ]);

            $verification->verify($task, $data, $request->user());

            return $this->ticketFor($task, 'Verification recorded');
        });
    }

    /**
     * Approve or REJECT a recurring fault's repair gate. When a confirmed fault recurred within the window
     * its repair is frozen; a manager clears it here (approve → repair proceeds; reject → fault cancelled,
     * not repaired again). Gated to the recurring-fault approval authority, not the workshop delegate.
     */
    public function repairApproval(Request $request, \App\Models\MaintenanceTask $task)
    {
        return $this->run(function () use ($request, $task) {
            $data = $request->validate([
                'decision' => ['required', Rule::in(['approve', 'reject'])],
                'note'     => ['nullable', 'string', 'max:2000'],
            ]);

            $this->tasks->resolveRepairGate($task, $request->user(), $data['decision'] === 'approve', $data['note'] ?? null);
            return $this->ticketFor($task, $data['decision'] === 'approve' ? 'Repair approved — work may proceed' : 'Repair rejected — fault cancelled');
        });
    }

    /**
     * INCORRECT — a supervisor (Waleed / Abdullah) rules a reported fault (any source) is not a real fault
     * while the car is In Workshop. The single "not a real fault" outcome: drops it out of the must-fix set
     * (→ cancelled) and stamps who/why so it stays auditable (and, for inspector-raised faults, surfaces on
     * the mis-diagnosis report). Reason is mandatory.
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
