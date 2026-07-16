<?php

namespace App\Services;

use App\Models\InspectionRecord;
use App\Models\LogisticsTaskEvent;
use App\Models\VehicleLogEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The Activity Audit Trail read layer — the single normaliser behind the Vehicle History Timeline
 * and the Global Activity Feed.
 *
 * It does NOT introduce a new store. The codebase already keeps the events; they simply live in three
 * append-only tables, each owning its own domain:
 *   - vehicle_log_events    → the workflow / readiness / condition / cleaning / task audit trail
 *   - logistics_task_events → every vehicle MOVEMENT (dispatch, pickup, delivery, return)
 *   - inspection_records    → pre-rental / post-return condition captures + damage findings
 *
 * This service reads all three, maps each row onto ONE common shape, and merges them newest-first so
 * the UI can render a single unbroken timeline. Nothing here writes — it is purely the read side of
 * "total transparency: no gaps, no hidden actions".
 */
class ActivityFeedService
{
    /**
     * The filter buckets the manager feed exposes (and the per-event category tag). Each maps a family
     * of raw event types onto one human category so "show me all Pre-rental checks" is a single filter.
     */
    public const CATEGORIES = ['inspection', 'cleaning', 'condition', 'readiness', 'maintenance', 'movement'];

    /**
     * The three super-tiers the Vehicle Life-Stream groups the six categories under, so the eye can
     * separate *who* acted at a glance: hands-on-the-car TECHNICAL work, on-the-road OPERATIONAL moves,
     * and back-office ADMINISTRATIVE grading. Every event carries both its fine category and its tier.
     */
    public const TIERS = ['technical', 'operational', 'administrative'];

    /** category → tier. Anything unlisted falls through to 'technical'. */
    private const CATEGORY_TIER = [
        'inspection'  => 'technical',
        'maintenance' => 'technical',
        'movement'    => 'operational',
        'readiness'   => 'administrative',
        'condition'   => 'administrative',
        'cleaning'    => 'administrative',
    ];

    /** vehicle_log_events event_type → category. Anything unlisted falls through to 'maintenance'. */
    private const LOG_CATEGORY = [
        VehicleLogEvent::EVENT_READINESS_CONFIRMED => 'readiness',
        VehicleLogEvent::EVENT_READINESS_OVERRIDE  => 'readiness',
        VehicleLogEvent::EVENT_CLEANING_UPDATED    => 'cleaning',
        VehicleLogEvent::EVENT_CONDITION_GRADED    => 'condition',
        // Physical MOVEMENTS the workflow itself records (the dedicated logistics_task_events layer is
        // not always exercised — the maintenance workflow moves the car directly). These are real
        // base↔garage relocations, so they belong under the Movement chip, not lumped into maintenance:
        //   dispatched   → car picked up, now In Transit to the garage  (Check-out)
        //   under_repair → car ARRIVED at the garage (arrival odometer)  (Garage Arrival)
        //   status_update→ a driver's en-route location ping
        VehicleLogEvent::EVENT_DISPATCHED          => 'movement',
        VehicleLogEvent::EVENT_UNDER_REPAIR        => 'movement',
        VehicleLogEvent::EVENT_STATUS_UPDATE       => 'movement',
    ];

    /** Human labels for the raw event types. Unlisted types fall back to a headline-cased slug. */
    private const LABELS = [
        VehicleLogEvent::EVENT_INSPECTION_REQUESTED => 'Inspection requested',
        VehicleLogEvent::EVENT_DIAGNOSTIC_STARTED   => 'Diagnostic started',
        VehicleLogEvent::EVENT_REPORT_FILED         => 'Fault report filed',
        VehicleLogEvent::EVENT_DIAGNOSTIC_CLEARED   => 'Cleared — no work needed',
        VehicleLogEvent::EVENT_GARAGE_ASSIGNED      => 'Garage assigned',
        VehicleLogEvent::EVENT_DISPATCHED           => 'Dispatched to garage',
        VehicleLogEvent::EVENT_UNDER_REPAIR         => 'Under repair',
        VehicleLogEvent::EVENT_READY                => 'Repair ready',
        VehicleLogEvent::EVENT_CLOSED               => 'Ticket closed',
        VehicleLogEvent::EVENT_REOPENED             => 'Re-inspection failed — reopened',
        VehicleLogEvent::EVENT_TYPE_CHANGED         => 'Type reclassified',
        VehicleLogEvent::EVENT_REASSIGNED           => 'Reassigned',
        VehicleLogEvent::EVENT_STATUS_UPDATE        => 'Status update',
        VehicleLogEvent::EVENT_DELEGATED            => 'Driver delegated',
        VehicleLogEvent::EVENT_COST_RECORDED        => 'Cost recorded',
        VehicleLogEvent::EVENT_INVOICE_REQUESTED    => 'Invoice requested',
        VehicleLogEvent::EVENT_TRANSPORT_ASSIGNED   => 'Transport method assigned',
        VehicleLogEvent::EVENT_AWAITING_INVOICE     => 'Awaiting invoice',
        VehicleLogEvent::EVENT_INVOICE_RECEIVED     => 'Invoice received',
        VehicleLogEvent::EVENT_GARAGE_INVOICE_SUBMITTED => 'Garage invoice submitted',
        VehicleLogEvent::EVENT_GARAGE_INVOICE_ACCEPTED  => 'Garage invoice accepted',
        VehicleLogEvent::EVENT_GARAGE_INVOICE_REJECTED  => 'Garage invoice rejected',
        VehicleLogEvent::EVENT_READINESS_CONFIRMED  => 'Readiness confirmed',
        VehicleLogEvent::EVENT_READINESS_OVERRIDE   => 'Readiness override',
        VehicleLogEvent::EVENT_CONDITION_GRADED     => 'Condition graded',
        VehicleLogEvent::EVENT_CLEANING_UPDATED     => 'Cleaning updated',
        VehicleLogEvent::EVENT_TASK_IDENTIFIED      => 'Fault identified',
        VehicleLogEvent::EVENT_TASK_ASSIGNED        => 'Fault routed to garage',
        VehicleLogEvent::EVENT_TASK_TRANSFERRED     => 'Fault transferred',
        VehicleLogEvent::EVENT_TASK_RESOLVED        => 'Fault resolved',
        VehicleLogEvent::EVENT_TASK_REINSPECTION_FAILED => 'Fault failed re-inspection',
        VehicleLogEvent::EVENT_TASK_MARKED_INCORRECT => 'Fault marked incorrect',
    ];

    private const LOGISTICS_LABELS = [
        LogisticsTaskEvent::EVENT_DISPATCHED    => 'Movement dispatched',
        LogisticsTaskEvent::EVENT_CLAIMED       => 'Move claimed',
        LogisticsTaskEvent::EVENT_PICKED_UP     => 'Picked up',
        LogisticsTaskEvent::EVENT_DELIVERED     => 'Delivered',
        LogisticsTaskEvent::EVENT_RETURNED      => 'Returned to base',
        LogisticsTaskEvent::EVENT_CANCELLED     => 'Move cancelled',
        LogisticsTaskEvent::EVENT_STATUS_UPDATE => 'Location update',
        LogisticsTaskEvent::EVENT_REASSIGNED    => 'Move reassigned',
    ];

    /**
     * Operational "stage" — the plain-language milestone each raw event maps to, so the Story feed can
     * headline a burst of workflow rows as "Check-out → Garage Arrival → Ready for Rent" instead of a
     * dump of 'dispatched' / 'under_repair' / 'ready'. Null = no operational milestone, in which case
     * the precise `action` label stands on its own. Keyed by vehicle_log_events.event_type.
     */
    private const LOG_STAGE = [
        VehicleLogEvent::EVENT_INSPECTION_REQUESTED => 'Test Drive',
        VehicleLogEvent::EVENT_DIAGNOSTIC_STARTED   => 'Test Drive',
        VehicleLogEvent::EVENT_REPORT_FILED         => 'Fault Reported',
        VehicleLogEvent::EVENT_TASK_IDENTIFIED      => 'Fault Reported',
        VehicleLogEvent::EVENT_GARAGE_ASSIGNED      => 'Garage Assigned',
        VehicleLogEvent::EVENT_DISPATCHED           => 'Check-out',
        VehicleLogEvent::EVENT_UNDER_REPAIR         => 'Garage Arrival',
        VehicleLogEvent::EVENT_READY                => 'Repair Complete',
        VehicleLogEvent::EVENT_TASK_RESOLVED        => 'Repair Complete',
        VehicleLogEvent::EVENT_CLOSED               => 'Back in Service',
        VehicleLogEvent::EVENT_READINESS_CONFIRMED  => 'Ready for Rent',
        VehicleLogEvent::EVENT_READINESS_OVERRIDE   => 'Ready for Rent',
        VehicleLogEvent::EVENT_REOPENED             => 'Re-inspection Failed',
        VehicleLogEvent::EVENT_TASK_REINSPECTION_FAILED => 'Re-inspection Failed',
    ];

    /** logistics_task_events.event → operational stage. Movements are the spine of the Story feed. */
    private const LOGISTICS_STAGE = [
        LogisticsTaskEvent::EVENT_DISPATCHED    => 'Dispatch Requested',
        LogisticsTaskEvent::EVENT_CLAIMED       => 'Driver Assigned',
        LogisticsTaskEvent::EVENT_PICKED_UP     => 'Check-out',
        LogisticsTaskEvent::EVENT_DELIVERED     => 'Garage Arrival',
        LogisticsTaskEvent::EVENT_RETURNED      => 'Check-in',
        LogisticsTaskEvent::EVENT_STATUS_UPDATE => 'Location Update',
        LogisticsTaskEvent::EVENT_REASSIGNED    => 'Reassigned',
        LogisticsTaskEvent::EVENT_CANCELLED     => 'Move Cancelled',
    ];

    /** The stages that mean a base↔garage MOVEMENT — the primary events the Story feed anchors on. */
    private const PRIMARY_STAGES = ['Check-out', 'Check-in'];

    /** UI tone per category — the frontend maps these to its shared badge/marker palette. */
    private const CATEGORY_TONE = [
        'inspection'  => 'blue',
        'cleaning'    => 'cyan',
        'condition'   => 'amber',
        'readiness'   => 'emerald',
        'maintenance' => 'indigo',
        'movement'    => 'violet',
    ];

    /**
     * One vehicle's full history, newest first — every source unioned. Powers the Vehicle History
     * Timeline (click a car → its whole life). No date window: a car's complete trail, capped.
     */
    public function forVehicle(int $vehicleId, int $limit = 300): array
    {
        $events = array_merge(
            $this->fromVehicleLog(fn ($q) => $q->where('vehicle_id', $vehicleId), $limit),
            $this->fromLogistics(fn ($q) => $q->where('vehicle_id', $vehicleId), $limit),
            $this->fromInspections(fn ($q) => $q->where('vehicle_id', $vehicleId), $limit),
        );

        return $this->sortAndSlice($events, $limit);
    }

    /**
     * The manager Global Activity Feed. Merges all sources across the whole fleet, filtered by an
     * optional action category, vehicle, actor, free-text search and — by default — a rolling time
     * window (so "all pre-rental checks in the last 24 hours" is one call).
     *
     * @param array{category?:?string, vehicle_id?:?int, actor_id?:?int, q?:?string,
     *              from?:?string, to?:?string, limit?:int, page?:int} $filters
     */
    public function global(array $filters = []): array
    {
        $category = in_array($filters['category'] ?? null, self::CATEGORIES, true) ? $filters['category'] : null;
        $vehicleId = $filters['vehicle_id'] ?? null;
        $actorId   = $filters['actor_id'] ?? null;
        $search    = trim((string) ($filters['q'] ?? ''));
        $limit     = max(1, min(200, (int) ($filters['limit'] ?? 60)));
        $page      = max(1, (int) ($filters['page'] ?? 1));

        // Bound the merge with a time window. Defaults to the last 7 days; the caller can widen it
        // (the UI's 24h / 7d / 30d / All chips) or pass an explicit range.
        $from = ! empty($filters['from']) ? Carbon::parse($filters['from']) : Carbon::now()->subDays(7);
        $to   = ! empty($filters['to']) ? Carbon::parse($filters['to']) : null;

        $windowLog = function ($q, string $col = 'occurred_at') use ($from, $to, $vehicleId) {
            $q->where($col, '>=', $from);
            if ($to) {
                $q->where($col, '<=', $to);
            }
            if ($vehicleId) {
                $q->where('vehicle_id', $vehicleId);
            }
            return $q;
        };

        $events = [];

        // vehicle_log_events — the workflow/readiness/condition/cleaning/movement trail. When a category
        // is requested, restrict to the event types that back it; an empty list (e.g. 'inspection', which
        // lives only in inspection_records) skips this source entirely. Note 'movement' IS partly log-
        // backed now (dispatched/under_repair/status_update), so it draws from BOTH here and logistics.
        $logTypes = $category === null ? null : $this->logEventTypesFor($category);
        if ($category === null || $logTypes !== []) {
            $events = array_merge($events, $this->fromVehicleLog(function ($q) use ($windowLog, $actorId, $logTypes) {
                $windowLog($q);
                if ($actorId) {
                    $q->where('actor_id', $actorId);
                }
                if ($logTypes !== null) {
                    $q->whereIn('event_type', $logTypes);
                }
            }, 1500));
        }

        // logistics_task_events — vehicle movements (the dedicated dispatch/claim layer).
        if ($category === null || $category === 'movement') {
            $events = array_merge($events, $this->fromLogistics(function ($q) use ($windowLog, $actorId) {
                $windowLog($q);
                if ($actorId) {
                    $q->where('actor_id', $actorId);
                }
            }, 1500));
        }

        // inspection_records — pre/post captures.
        if ($category === null || $category === 'inspection') {
            $events = array_merge($events, $this->fromInspections(function ($q) use ($windowLog, $actorId) {
                $windowLog($q, 'captured_at');
                if ($actorId) {
                    $q->where('inspector_id', $actorId);
                }
            }, 1500));
        }

        if ($search !== '') {
            $needle = Str::lower($search);
            $events = array_values(array_filter($events, function ($e) use ($needle) {
                $hay = Str::lower(implode(' ', array_filter([
                    $e['action'], $e['description'], $e['actor_name'], $e['plate'], $e['model'],
                ])));
                return str_contains($hay, $needle);
            }));
        }

        usort($events, fn ($a, $b) => strcmp($b['occurred_at'] ?? '', $a['occurred_at'] ?? ''));

        // Summary is computed over the FULL merged window (before pagination) so the filter chips and
        // KPIs reflect the real totals, not just the current page.
        $dayAgo = Carbon::now()->subDay()->toIso8601String();
        $summary = [
            'total'       => count($events),
            'last_24h'    => count(array_filter($events, fn ($e) => ($e['occurred_at'] ?? '') >= $dayAgo)),
            'vehicles'    => count(array_unique(array_filter(array_column($events, 'vehicle_id')))),
            'by_category' => array_count_values(array_column($events, 'category')),
            'by_tier'     => array_count_values(array_filter(array_column($events, 'tier'))),
        ];

        $offset = ($page - 1) * $limit;

        // Balanced default: on the unfiltered firehose (no category / vehicle / actor / search), the
        // sheer volume of maintenance-workflow rows would otherwise bury the movement, inspection and
        // stage-transition lanes at the row cap. diversify() guarantees each lane a fair share so the
        // first thing a user sees is a genuine mix, not a wall of ticket logs. Any explicit filter (or a
        // deeper page) keeps the plain newest-first slice — the user has already narrowed the view.
        $filtersActive = $category !== null || $vehicleId || $actorId || $search !== '';
        $pageEvents = ($page === 1 && ! $filtersActive)
            ? $this->diversify($events, $limit)
            : array_slice($events, $offset, $limit);

        return [
            'events'   => $pageEvents,
            'summary'  => $summary,
            'page'     => $page,
            'limit'    => $limit,
            'has_more' => ($offset + $limit) < count($events),
            'window'   => ['from' => $from->toIso8601String(), 'to' => $to?->toIso8601String()],
        ];
    }

    // ── Per-source normalisers ───────────────────────────────────────────────────

    /** @param callable $scope A closure that constrains the query. */
    private function fromVehicleLog(callable $scope, int $limit): array
    {
        $q = VehicleLogEvent::query()
            ->with(['vehicle:id,plate_no,make,model', 'actor:id,name', 'linkedContract:id,contract_no']);
        $scope($q);
        $rows = $q->orderByDesc('occurred_at')->limit($limit)->get();

        // Derive each transition's PRIOR stage so we can render it first-class as "Check-out → Garage
        // Arrival". We chain on the event-type-derived STAGE, not the raw workflow_status column: stage
        // is a reliable function of event_type, whereas workflow_status is a mutable ticket field that
        // isn't a faithful per-row "state reached". maintenance_id is null on almost every row, so we
        // chain per VEHICLE — walking the fetched set oldest-first, the last stage a car reached becomes
        // the `from` of its next transition. Only stage-bearing (lifecycle) events advance the chain;
        // admin rows don't. A `from` predating the fetched window is simply null (the event then stands
        // on its destination stage alone).
        $fromStageByEventId = [];
        $prevStage = [];
        foreach ($rows->sortBy([['occurred_at', 'asc'], ['id', 'asc']]) as $e) {
            $stage = self::LOG_STAGE[$e->event_type] ?? null;
            if ($stage === null) {
                continue;
            }
            $fromStageByEventId[$e->id] = $prevStage[$e->vehicle_id] ?? null;
            $prevStage[$e->vehicle_id] = $stage;
        }

        $shaped = $rows->map(function (VehicleLogEvent $e) use ($fromStageByEventId) {
            $category = self::LOG_CATEGORY[$e->event_type] ?? 'maintenance';
            $meta = $e->meta ?? [];
            $stage = self::LOG_STAGE[$e->event_type] ?? null;
            // A stage-bearing event IS a lifecycle transition (the workflow spine); a stage-less one is a
            // ticket-admin log (cost, invoice, reclassify…). This is what lets the UI keep transitions
            // first-class and the balanced default keep admin noise from drowning them.
            $isTransition = $stage !== null;
            $fromStage = $fromStageByEventId[$e->id] ?? null;
            return $this->shape([
                'id'          => 'log-' . $e->id,
                'source'      => 'log',
                'event_type'  => $e->event_type,
                'maintenance_id' => $e->maintenance_id,
                'category'    => $category,
                'action'      => self::LABELS[$e->event_type] ?? Str::headline($e->event_type),
                'stage'       => $stage,
                'is_transition' => $isTransition,
                'from_stage'  => $fromStage,
                'to_stage'    => $stage,
                'transition'  => ($isTransition && $fromStage && $fromStage !== $stage)
                    ? $fromStage . ' → ' . $stage
                    : null,
                'primary'     => in_array($stage, self::PRIMARY_STAGES, true),
                'actor_name'  => $e->actor?->name ?? 'System',
                'occurred_at' => optional($e->occurred_at)->toIso8601String(),
                'vehicle'     => $e->vehicle,
                'description' => $e->description,
                'odometer'    => $meta['odometer'] ?? $meta['arrival_odometer'] ?? $meta['reading'] ?? null,
                'details'     => $meta,
                'contract_id' => $e->linked_contract_id,
                'contract_no' => $e->linkedContract?->contract_no,
                'source_tag'  => $e->source_tag,
                'category_hint' => $category,
            ]);
        })->all();

        return $this->collapseTaskEvents($shaped);
    }

    /**
     * Fold the per-fault (maintenance_task) log rows into their single ticket-level milestone so the feed
     * reads as clean workflow stages, not a fault × milestone matrix. One test-drive report writes a
     * ticket-level `report_filed` AND a `task_identified` per fault; assigning a garage writes a
     * `garage_assigned` AND a `task_assigned` per fault; each fault fixed writes a `task_resolved` beside
     * the ticket-level `ready`. Here we keep the ticket-level row — folding the fault list into the opened
     * line as "Maintenance ticket opened: Fault 1, Fault 2" — and drop the per-fault twins, but ONLY when
     * that ticket-level twin is actually in the row set (a fault event with no companion still stands on its
     * own). Nothing is deleted from the store; this is display-only. `task_transferred` /
     * `task_reinspection_failed` have no ticket-level twin and are left as genuine standalone milestones.
     */
    private function collapseTaskEvents(array $rows): array
    {
        // Per-fault event → the ticket-level milestone it collapses into.
        $foldInto = [
            VehicleLogEvent::EVENT_TASK_IDENTIFIED => VehicleLogEvent::EVENT_REPORT_FILED,
            VehicleLogEvent::EVENT_TASK_ASSIGNED   => VehicleLogEvent::EVENT_GARAGE_ASSIGNED,
            VehicleLogEvent::EVENT_TASK_RESOLVED   => VehicleLogEvent::EVENT_READY,
        ];

        // Which ticket-level milestones each ticket actually has here, and the fault list per ticket
        // (parsed off the "Fault identified: X" task rows) to fold into its opened line.
        $milestones = [];   // maintenance_id => [event_type => true]
        $symptoms   = [];   // maintenance_id => [symptom, …]
        foreach ($rows as $e) {
            $mid = $e['maintenance_id'] ?? null;
            if (! $mid) {
                continue;
            }
            $milestones[$mid][$e['event_type']] = true;
            if (($e['event_type'] ?? null) === VehicleLogEvent::EVENT_TASK_IDENTIFIED) {
                $sym = trim(Str::after((string) $e['description'], 'Fault identified:'));
                $sym = $sym !== '' ? $sym : trim((string) $e['description']);
                if ($sym !== '') {
                    $symptoms[$mid][] = $sym;
                }
            }
        }

        $out = [];
        foreach ($rows as $e) {
            $type = $e['event_type'] ?? null;
            $mid  = $e['maintenance_id'] ?? null;

            // Drop a per-fault row when its ticket-level milestone is present for the same ticket.
            if ($mid && isset($foldInto[$type]) && ! empty($milestones[$mid][$foldInto[$type]])) {
                continue;
            }

            // Rewrite the ticket-opened line to the folded fault list. Prefer the task rows (the real
            // created faults); fall back to the raw symptom list the report itself stamped in meta, in case
            // the per-fault rows fell outside the fetched window.
            if ($type === VehicleLogEvent::EVENT_REPORT_FILED && $mid) {
                $faults = $symptoms[$mid] ?? [];
                if (empty($faults) && is_array($e['details']['symptoms'] ?? null)) {
                    foreach ($e['details']['symptoms'] as $s) {
                        $s = is_array($s) ? (string) ($s['text'] ?? '') : (string) $s;
                        if (trim($s) !== '') {
                            $faults[] = trim($s);
                        }
                    }
                }
                if (! empty($faults)) {
                    $e['description'] = 'Maintenance ticket opened: ' . implode(', ', array_values(array_unique($faults)));
                }
            }

            $out[] = $e;
        }

        return $out;
    }

    private function fromLogistics(callable $scope, int $limit): array
    {
        $q = LogisticsTaskEvent::query()
            ->with(['vehicle:id,plate_no,make,model', 'actor:id,name'])
            ->whereNotNull('vehicle_id');
        $scope($q);
        $rows = $q->orderByDesc('occurred_at')->limit($limit)->get();

        return $rows->map(function (LogisticsTaskEvent $e) {
            $desc = $e->note;
            if (! $desc && ($e->from_status || $e->to_status)) {
                $desc = trim(($e->from_status ?: '—') . ' → ' . ($e->to_status ?: '—'));
            }
            $stage = self::LOGISTICS_STAGE[$e->event] ?? null;
            return $this->shape([
                'id'          => 'logi-' . $e->id,
                'source'      => 'logistics',
                'event_type'  => $e->event,
                'category'    => 'movement',
                'action'      => self::LOGISTICS_LABELS[$e->event] ?? Str::headline((string) $e->event),
                'stage'       => $stage,
                'is_transition' => $stage !== null,
                // Logistics rows carry reliable from/to status columns — humanise them into the same
                // first-class "Dispatched → Picked Up" transition the workflow log renders from stages.
                'transition'  => $this->transitionLabel($stage !== null, $e->from_status, $e->to_status),
                'primary'     => in_array($stage, self::PRIMARY_STAGES, true),
                'actor_name'  => $e->actor_name ?: ($e->actor?->name ?? 'System'),
                'occurred_at' => optional($e->occurred_at)->toIso8601String(),
                'vehicle'     => $e->vehicle,
                'description' => $desc,
                'odometer'    => null,
                'details'     => array_filter([
                    'from' => $e->from_status, 'to' => $e->to_status,
                    'lat' => $e->lat, 'lng' => $e->lng,
                ], fn ($v) => $v !== null && $v !== ''),
                'category_hint' => 'movement',
            ]);
        })->all();
    }

    private function fromInspections(callable $scope, int $limit): array
    {
        $q = InspectionRecord::query()
            ->with(['vehicle:id,plate_no,make,model'])
            ->whereNotNull('vehicle_id');
        $scope($q);
        // Odometer shots are workflow CAPTURE EVIDENCE, not condition inspections — the maintenance and
        // logistics flows both stamp one on every stage (test-drive, pickup, garage arrival, return). The
        // reading + photo already live on the ticket, so surfacing them here is pure noise and mislabels a
        // maintenance capture as a "Pre-rental / Return Check". Keep the feed to genuine milestones and real
        // damage walk-arounds; drop the odometer frames (a NULL body_part row stays — it's a plain photo).
        $q->where(function ($w) {
            $w->whereNull('body_part')->orWhere('body_part', '!=', 'odometer');
        });
        $rows = $q->orderByDesc('captured_at')->orderByDesc('id')->limit($limit)->get();

        return $rows->map(function (InspectionRecord $r) {
            $isPre = $r->phase === 'pre';
            if ($r->damage_flagged) {
                $zone = $r->body_part ? ' on ' . str_replace('_', ' ', $r->body_part) : '';
                $desc = 'Damage flagged: ' . str_replace('_', ' ', (string) $r->damage_type)
                        . ($r->severity ? ' (' . $r->severity . ')' : '') . $zone;
            } else {
                $desc = 'Condition photo' . ($r->body_part ? ' — ' . str_replace('_', ' ', $r->body_part) : '');
            }
            return $this->shape([
                'id'          => 'insp-' . $r->id,
                'source'      => 'inspection',
                'event_type'  => $isPre ? 'pre_inspection' : 'post_inspection',
                'category'    => 'inspection',
                'action'      => $isPre ? 'Pre-rental inspection' : 'Post-return check',
                'stage'       => $isPre ? 'Pre-rental Check' : 'Return Check',
                'primary'     => false,
                'actor_name'  => $r->inspector_name ?: 'System',
                'occurred_at' => optional($r->captured_at ?? $r->created_at)->toIso8601String(),
                'vehicle'     => $r->vehicle,
                'description' => $desc,
                'odometer'    => null,
                'details'     => array_filter([
                    'body_part'      => $r->body_part,
                    'damage_flagged' => $r->damage_flagged,
                    'damage_type'    => $r->damage_type,
                    'severity'       => $r->severity,
                    'checkpoint'     => $r->checkpoint_type,
                ], fn ($v) => $v !== null && $v !== ''),
                'contract_id' => $r->contract_id,
                'photo_url'   => $r->viewUrl(),
                'flagged'     => (bool) $r->damage_flagged,
                'category_hint' => 'inspection',
            ]);
        })->all();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────────

    /** Fill the common event shape, deriving vehicle display fields + tone from the raw row. */
    private function shape(array $e): array
    {
        $vehicle = $e['vehicle'] ?? null;
        unset($e['vehicle']);

        return array_merge([
            'contract_id' => null,
            'contract_no' => null,
            'odometer'    => null,
            'photo_url'   => null,
            'flagged'     => false,
            'source_tag'  => null,
            'stage'       => null,
            'primary'     => false,
            'is_transition' => false,
            'from_stage'  => null,
            'to_stage'    => null,
            'transition'  => null,
        ], $e, [
            'vehicle_id' => $vehicle?->id ?? ($e['vehicle_id'] ?? null),
            'plate'      => $vehicle?->plate_no,
            'model'      => $vehicle ? trim(($vehicle->make ?? '') . ' ' . ($vehicle->model ?? '')) : null,
            'tone'       => self::CATEGORY_TONE[$e['category']] ?? 'slate',
            'tier'       => self::CATEGORY_TIER[$e['category']] ?? 'technical',
        ]);
    }

    /**
     * The vehicle_log_events event_types that back a given category. Returns [] for a category that
     * lives entirely outside the log (e.g. 'inspection' → inspection_records only), which the caller
     * uses to skip the log source. 'movement' now returns its log-backed slice (dispatched / under_repair
     * / status_update) IN ADDITION to the logistics_task_events the caller merges separately.
     */
    private function logEventTypesFor(string $category): array
    {
        if ($category === 'maintenance') {
            // Everything NOT explicitly re-categorised in LOG_CATEGORY is a maintenance-workflow event.
            $reassigned = array_keys(self::LOG_CATEGORY);
            return array_values(array_diff(array_keys(self::LABELS), $reassigned));
        }

        return array_keys(array_filter(self::LOG_CATEGORY, fn ($c) => $c === $category));
    }

    /** Human "In Transit → Under Repair" label for a transition, or null when there's no clean prior. */
    private function transitionLabel(bool $isTransition, ?string $from, ?string $to): ?string
    {
        if (! $isTransition || ! $from || ! $to || $from === $to) {
            return null;
        }
        return Str::headline($from) . ' → ' . Str::headline($to);
    }

    /**
     * The lane an event balances under. Movement and inspection are their own lanes; a stage-bearing log
     * event is a 'transition' (the workflow spine); everything else is 'admin' (ticket-log noise —
     * cost/invoice/reclassify/cleaning/condition), which the balanced default caps hardest.
     */
    private function laneOf(array $e): string
    {
        $cat = $e['category'] ?? 'maintenance';
        if ($cat === 'movement' || $cat === 'inspection') {
            return $cat;
        }
        return ! empty($e['is_transition']) ? 'transition' : 'admin';
    }

    /**
     * Balance a newest-first event pool so the default view is a genuine mix, not a wall of the one
     * dominant lane. Ceilings alone don't cut it: if the newest rows are all maintenance/movement, they
     * fill the page before older inspection rows are ever reached. So we RESERVE a floor of the newest
     * rows for each primary lane (movement / inspection / transition) up front, then fill the rest by
     * global recency — holding admin (the ticket-log noise) to a ceiling so it can't crowd the fill.
     * Admin over its ceiling only backfills if the page would otherwise be short. Re-sorted newest-first.
     */
    private function diversify(array $events, int $limit): array
    {
        $lanes = ['movement' => [], 'inspection' => [], 'transition' => [], 'admin' => []];
        foreach ($events as $e) { // already newest-first, so each lane bucket stays newest-first
            $lanes[$this->laneOf($e)][] = $e;
        }

        $floor    = max(1, (int) floor($limit * 0.15)); // guaranteed newest rows per primary lane
        $adminCap = max(1, (int) ceil($limit * 0.15));  // ticket-log noise ceiling

        $picked = [];
        $used   = [];
        $take = function (array $e) use (&$picked, &$used) {
            $picked[] = $e;
            $used[$e['id']] = true;
        };

        // 1) Reserve each primary lane its floor of newest rows so none can be squeezed out entirely.
        foreach (['movement', 'inspection', 'transition'] as $lane) {
            foreach (array_slice($lanes[$lane], 0, $floor) as $e) {
                if (count($picked) >= $limit) {
                    break 2;
                }
                $take($e);
            }
        }

        // 2) Fill the remainder by global recency, capping admin so noise can't dominate the fill.
        $adminUsed = 0;
        $overflow  = [];
        foreach ($events as $e) {
            if (count($picked) >= $limit) {
                break;
            }
            if (isset($used[$e['id']])) {
                continue;
            }
            if ($this->laneOf($e) === 'admin') {
                if ($adminUsed >= $adminCap) {
                    $overflow[] = $e;
                    continue;
                }
                $adminUsed++;
            }
            $take($e);
        }

        // 3) Backfill deferred admin only if the mix couldn't otherwise fill the page.
        foreach ($overflow as $e) {
            if (count($picked) >= $limit) {
                break;
            }
            $take($e);
        }

        usort($picked, fn ($a, $b) => strcmp($b['occurred_at'] ?? '', $a['occurred_at'] ?? ''));
        return $picked;
    }

    /** Merge helper: sort newest-first and cap. */
    private function sortAndSlice(array $events, int $limit): array
    {
        usort($events, fn ($a, $b) => strcmp($b['occurred_at'] ?? '', $a['occurred_at'] ?? ''));
        return array_slice($events, 0, $limit);
    }
}
