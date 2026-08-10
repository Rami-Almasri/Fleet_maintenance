<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\InspectionSchedule;
use App\Models\Maintenance;
use App\Models\Vehicle;
use App\Models\VehicleLogEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Vehicle Status Dashboard — the "single pane of glass" follow-up board (one row per vehicle):
 * where each car is in its lifecycle, WHO currently holds it, what just happened, what's next,
 * how long it has been stuck, and whether it is blocked (off-road / needs attention).
 *
 * Nothing here is stored — every column is DERIVED live from the same sources the rest of the app
 * already trusts, so this board can never disagree with the dashboard, the /maintenance board or the
 * workflow pipeline:
 *   - the live movement            → Vehicle::operational_status (contract-derived, see OperationsService)
 *   - the fine maintenance stage   → the car's open workflow ticket (Maintenance::workflow_status)
 *   - who holds a rented car       → its open type-C contract's customer
 *   - "days in status"             → the workflow stage anchor / contract out_date
 *
 * The whole board is assembled in a handful of bulk queries (one per signal), not N per car.
 */
class VehicleStatusDashboardService
{
    /** OM lifecycle statuses that mean the car has left the fleet — shown, but not actionable. */
    private const LEFT_FLEET = ['sold', 'disposed', 'returned'];

    /** Fine maintenance workflow_status → the business-friendly stage key used for presentation. */
    private const WF_STAGE = [
        Maintenance::WF_INSPECTION_REQUESTED  => 'inspection_requested',
        Maintenance::WF_INSPECTION_DIAGNOSTIC => 'under_diagnosis',
        Maintenance::WF_INSPECTION_PENDING    => 'awaiting_dispatch',
        Maintenance::WF_AWAITING_DISPATCH     => 'awaiting_pickup',
        Maintenance::WF_IN_TRANSIT            => 'to_garage',
        Maintenance::WF_UNDER_REPAIR          => 'in_garage',
        Maintenance::WF_REPAIR_REVIEW         => 'repair_review',
        // Signed off at the garage, awaiting the driver's return leg (collect + arrive).
        Maintenance::WF_READY_FOR_PICKUP      => 'ready_for_pickup',
        // Transient — arriveAtPark() always auto-continues within the same request, so this is rarely
        // observed, but a row read mid-flight (or a stuck transaction) should still present sensibly.
        Maintenance::WF_IN_OUR_PARK           => 'in_our_park',
        // The final re-inspection — now entered from in_our_park, only for major (critical/moderate)
        // repairs; a minor (routine) repair auto-closes on arrival and never reaches this stage.
        Maintenance::WF_READY_REINSPECTION    => 'reinspection',
        Maintenance::WF_REINSPECTION_FAILED   => 'reinspection_failed',
    ];

    /**
     * Per-stage presentation: the status label + colour chip, the CURRENT OWNER (role/person who
     * holds the car now), the last action taken, the next action needed, whether the car is blocked
     * (can't earn — off-road / needs attention) and which quick-filter bucket it belongs to.
     *
     * Owner is the LIVE holder: a real person resolved from the open workflow ticket (see buildRow) —
     * inspector / assigned driver / garage — with the customer name for a rented car. The strings below
     * are only the generic ROLE fallback shown while that seat is unfilled; they must NEVER name a
     * specific person. A car with NO workflow ticket carries no owner at all → 'No Ticket' (we don't
     * invent a holder — that's the nudge to open a real ticket and keep the board honest).
     */
    private const STAGE_META = [
        'rented'               => ['status' => 'Rented',              'tone' => 'blue',   'owner' => 'Customer',                        'last' => 'Contract opened',       'next' => 'On rent',                'blocked' => false, 'bucket' => 'rented'],
        'available'            => ['status' => 'Ready for Rent',      'tone' => 'green',  'owner' => 'Sales',                           'last' => 'Passed check',          'next' => 'Waiting booking',        'blocked' => false, 'bucket' => 'available'],
        'grounded'            => ['status' => 'Grounded · Critical',  'tone' => 'red',    'owner' => 'No Ticket',                       'last' => 'Graded critical',       'next' => 'Route to garage / regrade', 'blocked' => true,  'bucket' => 'maintenance'],
        'transit'             => ['status' => 'In Transit',           'tone' => 'violet', 'owner' => 'Driver',                          'last' => 'Dispatched',            'next' => 'Deliver to destination', 'blocked' => false, 'bucket' => 'transit'],
        'inspection_requested' => ['status' => 'Inspection Requested','tone' => 'amber',  'owner' => 'Inspector',                       'last' => 'Driver flagged an issue','next' => 'Test drive',            'blocked' => true,  'bucket' => 'maintenance'],
        'under_diagnosis'     => ['status' => 'Under Diagnosis',      'tone' => 'amber',  'owner' => 'Inspector',                       'last' => 'Test drive started',    'next' => 'Complete diagnosis',     'blocked' => true,  'bucket' => 'maintenance'],
        'awaiting_dispatch'   => ['status' => 'Awaiting Dispatch',    'tone' => 'amber',  'owner' => 'Supervisor',                      'last' => 'Diagnosis filed',       'next' => 'Assign garage',          'blocked' => true,  'bucket' => 'maintenance'],
        'awaiting_pickup'     => ['status' => 'Awaiting Pickup',      'tone' => 'amber',  'owner' => 'Driver',                          'last' => 'Garage assigned',       'next' => 'Pick up car',            'blocked' => true,  'bucket' => 'maintenance'],
        'to_garage'           => ['status' => 'In Transit to Garage', 'tone' => 'violet', 'owner' => 'Driver',                          'last' => 'Picked up',             'next' => 'Deliver to garage',      'blocked' => true,  'bucket' => 'maintenance'],
        'in_garage'           => ['status' => 'In Garage',            'tone' => 'red',    'owner' => 'Garage',                          'last' => 'Delivered to garage',   'next' => 'Follow garage',          'blocked' => true,  'bucket' => 'maintenance'],
        'repair_review'       => ['status' => 'Repair Review',        'tone' => 'violet', 'owner' => 'Fleet Ready Officer',             'last' => 'Garage finished',       'next' => 'Review video & approve', 'blocked' => true,  'bucket' => 'maintenance'],
        'ready_for_pickup'    => ['status' => 'Ready for Pickup',     'tone' => 'teal',   'owner' => 'Driver',                          'last' => 'Signed off at garage',  'next' => 'Collect & bring back',   'blocked' => true,  'bucket' => 'maintenance'],
        'in_our_park'         => ['status' => 'In Our Park',          'tone' => 'blue',   'owner' => 'System',                          'last' => 'Arrived at our park',   'next' => 'Auto-evaluating',        'blocked' => true,  'bucket' => 'maintenance'],
        'reinspection'        => ['status' => 'Final QA Re-inspection', 'tone' => 'amber','owner' => 'Inspector',                       'last' => 'Back at our park',      'next' => 'Final QA sign-off',      'blocked' => true,  'bucket' => 'maintenance'],
        'reinspection_failed' => ['status' => 'Re-inspection Failed', 'tone' => 'red',    'owner' => 'Supervisor',                      'last' => 'Re-inspection failed',  'next' => 'Re-dispatch to garage',  'blocked' => true,  'bucket' => 'maintenance'],
        'in_garage_noticket'  => ['status' => 'In Garage · No Ticket', 'tone' => 'red',   'owner' => 'No Ticket',                       'last' => 'Sent to garage',        'next' => 'Follow garage',          'blocked' => true,  'bucket' => 'maintenance'],
        // Enterprise Handover Workflow — a paused ticket splits into two sub-states depending on whether
        // the vehicle has been marked physically returned yet (see Maintenance::isPausedOut() /
        // isReturnedPendingHandover()): still out (rentable, not blocked) vs back but the handover
        // paperwork is outstanding (blocked — it can't be rented out again until it clears).
        'paused_out'                      => ['status' => 'Available · Repair Paused',    'tone' => 'amber',  'owner' => 'Customer', 'last' => 'Repair paused',   'next' => 'Awaiting return',              'blocked' => false, 'bucket' => 'available'],
        'paused_returned_pending_handover' => ['status' => 'Returned · Handover Pending', 'tone' => 'orange', 'owner' => 'Workshop',  'last' => 'Vehicle returned', 'next' => 'Complete return handover',    'blocked' => true,  'bucket' => 'maintenance'],
        'left'                => ['status' => 'Left the Fleet',       'tone' => 'gray',   'owner' => '—',                               'last' => 'Left the fleet',        'next' => '—',                      'blocked' => false, 'bucket' => 'left'],
    ];

    /**
     * Workflow event_type → a business-friendly stage label for the per-car history drill-down. This is
     * the vocabulary a follow-up manager reads ("who did what, when"), one entry per logged transition.
     */
    private const EVENT_LABEL = [
        VehicleLogEvent::EVENT_INSPECTION_REQUESTED     => 'Inspection Requested',
        VehicleLogEvent::EVENT_DIAGNOSTIC_STARTED       => 'Test Drive & Diagnosis',
        VehicleLogEvent::EVENT_REPORT_FILED             => 'Diagnosis Report Filed',
        VehicleLogEvent::EVENT_DIAGNOSTIC_CLEARED       => 'Cleared — No Work Needed',
        VehicleLogEvent::EVENT_GARAGE_ASSIGNED          => 'Garage Assigned',
        VehicleLogEvent::EVENT_DISPATCHED               => 'Picked Up & In Transit',
        VehicleLogEvent::EVENT_UNDER_REPAIR             => 'In Garage — Under Repair',
        VehicleLogEvent::EVENT_READY                    => 'Repair Finished',
        VehicleLogEvent::EVENT_CLOSED                   => 'Signed Off — Back in Service',
        VehicleLogEvent::EVENT_REOPENED                 => 'Re-inspection Failed — Returned',
        VehicleLogEvent::EVENT_TYPE_CHANGED             => 'Reclassified',
        VehicleLogEvent::EVENT_REASSIGNED               => 'Driver Reassigned',
        VehicleLogEvent::EVENT_STATUS_UPDATE            => 'Location Update',
        VehicleLogEvent::EVENT_DELEGATED                => 'Driver Delegated',
        VehicleLogEvent::EVENT_COST_RECORDED            => 'Cost Recorded',
        VehicleLogEvent::EVENT_INVOICE_REQUESTED        => 'Invoice Requested',
        VehicleLogEvent::EVENT_READINESS_CONFIRMED      => 'Confirmed Ready for Delivery',
        VehicleLogEvent::EVENT_CONDITION_GRADED         => 'Condition Graded',
        VehicleLogEvent::EVENT_TASK_IDENTIFIED          => 'Fault Identified',
        VehicleLogEvent::EVENT_TASK_ASSIGNED            => 'Fault Routed to Garage',
        VehicleLogEvent::EVENT_TASK_TRANSFERRED         => 'Fault Moved to Another Garage',
        VehicleLogEvent::EVENT_TASK_RESOLVED            => 'Fault Resolved',
        VehicleLogEvent::EVENT_TASK_REINSPECTION_FAILED => 'Fault Failed Re-inspection',
        VehicleLogEvent::EVENT_TASK_LABOR_CORRECTED     => 'Labor Time Corrected',
        // Enterprise Handover Workflow
        VehicleLogEvent::EVENT_RETURNED_TO_SERVICE      => 'Paused & Returned to Service',
        VehicleLogEvent::EVENT_VEHICLE_RETURNED         => 'Vehicle Physically Returned',
        VehicleLogEvent::EVENT_RESUMED                  => 'Repair Resumed',
        VehicleLogEvent::EVENT_HANDOVER_INCIDENT        => 'Handover Discrepancy Flagged',
        VehicleLogEvent::EVENT_INCIDENT_ACKNOWLEDGED    => 'Discrepancy Acknowledged — Repair Resumed',
    ];

    public function __construct(protected OperationsService $ops)
    {
    }

    /**
     * The full stage-by-stage maintenance HISTORY for one vehicle: every workshop journey it has been
     * through (a "journey" = one workflow ticket), newest first, each broken into the exact stages it
     * passed through. For every stage we surface WHO was responsible (the actor who drove that
     * transition), WHAT happened (the logged description) and HOW LONG the car sat in that stage (the
     * gap to the next event — or up to now, if the stage is still running). Built purely from the
     * append-only VehicleLogEvent stream, optionally clipped to a [from, to] window.
     *
     * @return array{vehicle: array<string,mixed>, journeys: array<int, array<string,mixed>>}
     */
    public function vehicleTimeline(Vehicle $v, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $events = VehicleLogEvent::where('vehicle_id', $v->id)
            ->when($from, fn ($q) => $q->where('occurred_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('occurred_at', '<=', $to))
            ->with(['actor:id,name', 'maintenance:id,workflow_status'])
            ->orderBy('occurred_at')->orderBy('id')
            ->get();

        // One journey per ticket; the rare event with no ticket lands in a shared 'general' bucket.
        $journeys = $events
            ->groupBy(fn ($e) => $e->maintenance_id ?? 'general')
            ->map(fn (Collection $evs, $ticketId) => $this->buildJourney($evs->values(), $ticketId))
            ->values()
            ->sortByDesc(fn ($j) => $j['opened_at'] ?? '')
            ->values()
            ->all();

        return [
            'vehicle' => [
                'id'       => $v->id,
                'title'    => trim(($v->model ?: ($v->make ?: 'Vehicle')) . ' ' . ($v->year ?? '')),
                'plate_no' => $v->plate_no,
                'make'     => $v->make,
                'model'    => $v->model,
            ],
            'journeys' => $journeys,
        ];
    }

    /**
     * The period overview: every maintenance JOURNEY that had activity inside [from, to], across the whole
     * fleet — one summary row per ticket (car, when it ran, outcome, total time, who touched it last, how
     * many stages). This is what the board shows when a manager picks "Last month" instead of the live now.
     * A journey counts if any of its events fall in the window; its summary is built from the ticket's FULL
     * event history so the duration + outcome are accurate even when it started before the window.
     *
     * @return array{journeys: array<int, array<string,mixed>>, counts: array<string,int>}
     */
    public function history(?Carbon $from = null, ?Carbon $to = null): array
    {
        // Tickets touched in the window.
        $ticketIds = VehicleLogEvent::query()
            ->whereNotNull('maintenance_id')
            ->when($from, fn ($q) => $q->where('occurred_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('occurred_at', '<=', $to))
            ->distinct()->pluck('maintenance_id')->all();

        if (empty($ticketIds)) {
            return ['journeys' => [], 'counts' => ['journeys' => 0, 'open' => 0, 'closed' => 0]];
        }

        // Their FULL event history (not clipped) so each summary is accurate + a car label per event.
        $events = VehicleLogEvent::whereIn('maintenance_id', $ticketIds)
            ->with(['actor:id,name', 'maintenance:id,workflow_status', 'vehicle:id,make,model,year,plate_no'])
            ->orderBy('occurred_at')->orderBy('id')
            ->get();

        $open = 0;
        $journeys = $events
            ->groupBy('maintenance_id')
            ->map(function (Collection $evs, $ticketId) {
                $evs     = $evs->values();
                $journey = $this->buildJourney($evs, $ticketId);
                $v       = $evs->first()->vehicle;

                // Summary-only fields the list needs on top of the shared journey shape.
                $journey['vehicle'] = $v ? [
                    'id'       => $v->id,
                    'title'    => trim(($v->model ?: ($v->make ?: 'Vehicle')) . ' ' . ($v->year ?? '')),
                    'plate_no' => $v->plate_no,
                ] : null;
                $journey['last_responsible'] = $evs->last()->actor?->name;
                unset($journey['stages']); // the list is a summary; stages load in the drawer
                return $journey;
            })
            ->values();

        foreach ($journeys as $j) {
            if ($j['open']) {
                $open++;
            }
        }
        $journeys = $journeys->sortByDesc(fn ($j) => $j['opened_at'] ?? '')->values()->all();

        return [
            'journeys' => $journeys,
            'counts'   => ['journeys' => count($journeys), 'open' => $open, 'closed' => count($journeys) - $open],
        ];
    }

    /**
     * Fold one ticket's ordered event stream into a journey: its stages (each with the responsible actor
     * + dwell time) and the roll-up (opened/closed, total time, open?). Shared by the per-car timeline and
     * the period history so both speak the same shape. The final stage of a still-open ticket is counted
     * as running (dwell measured up to now); a closed ticket's last event has no dwell.
     */
    private function buildJourney(Collection $evs, $ticketId): array
    {
        $ticket = $evs->first()->maintenance; // may be null for an orphan event
        $status = $ticket?->workflow_status;
        $open   = $status !== null && ! in_array($status, Maintenance::WF_TERMINAL, true);

        $stages = [];
        foreach ($evs as $i => $e) {
            $next = $evs[$i + 1] ?? null;
            $end  = $next?->occurred_at ?? ($open ? Carbon::now() : null);
            $dur  = ($e->occurred_at && $end) ? $e->occurred_at->diffInSeconds($end) : null;

            $stages[] = [
                'event_type'       => $e->event_type,
                'label'            => self::EVENT_LABEL[$e->event_type] ?? ucfirst(str_replace('_', ' ', $e->event_type)),
                'responsible'      => $e->actor?->name,
                'source'           => $e->source_tag,   // inspector | garage
                'tone'             => $this->eventTone($e),
                'description'      => $e->description,
                'garage'           => is_array($e->meta) ? ($e->meta['garage'] ?? null) : null,
                'at'               => optional($e->occurred_at)->toIso8601String(),
                'duration_seconds' => $dur,
                'running'          => $next === null && $open,
            ];
        }

        $first    = $evs->first()->occurred_at;
        $last     = $evs->last()->occurred_at;
        $totalEnd = $open ? Carbon::now() : $last;

        return [
            'ticket_id'     => is_numeric($ticketId) ? (int) $ticketId : null,
            'open'          => $open,
            'outcome'       => $open ? 'In progress' : ($status === Maintenance::WF_DIAGNOSTIC_CLEARED ? 'Cleared — no work' : 'Completed'),
            'opened_at'     => optional($first)->toIso8601String(),
            'closed_at'     => $open ? null : optional($last)->toIso8601String(),
            'total_seconds' => ($first && $totalEnd) ? $first->diffInSeconds($totalEnd) : null,
            'stage_count'   => count($stages),
            'stages'        => $stages,
        ];
    }

    /** Timeline dot colour for an event — outcome-aware, else by which side (inspector/garage) acted. */
    private function eventTone(VehicleLogEvent $e): string
    {
        return match ($e->event_type) {
            VehicleLogEvent::EVENT_CLOSED, VehicleLogEvent::EVENT_DIAGNOSTIC_CLEARED,
            VehicleLogEvent::EVENT_READY, VehicleLogEvent::EVENT_TASK_RESOLVED => 'green',
            VehicleLogEvent::EVENT_REOPENED, VehicleLogEvent::EVENT_TASK_REINSPECTION_FAILED => 'red',
            VehicleLogEvent::EVENT_UNDER_REPAIR => 'amber',
            VehicleLogEvent::EVENT_DISPATCHED, VehicleLogEvent::EVENT_STATUS_UPDATE => 'violet',
            VehicleLogEvent::EVENT_RETURNED_TO_SERVICE   => 'slate',
            VehicleLogEvent::EVENT_VEHICLE_RETURNED      => 'amber',
            VehicleLogEvent::EVENT_HANDOVER_INCIDENT     => 'red',
            VehicleLogEvent::EVENT_INCIDENT_ACKNOWLEDGED => 'emerald',
            default => ($e->source_tag === Maintenance::FINDING_INSPECTOR ? 'blue' : 'amber'),
        };
    }

    /**
     * The full board — one derived row per vehicle, plus headline counts for the KPI strip.
     *
     * @return array{rows: array<int, array<string, mixed>>, counts: array<string, int>}
     */
    public function build(): array
    {
        $vehicles = Vehicle::query()
            ->orderBy('make')->orderBy('model')->orderBy('year')
            ->get();

        // --- One bulk query per signal (not per car) ---------------------------------------

        // The car's open workflow ticket (latest wins) → the fine maintenance stage + the real
        // person holding the car now (inspector / driver / garage) for the Owner column.
        $tickets = Maintenance::openWorkflow()
            ->whereIn('workflow_status', Maintenance::WF_TICKET_STATES)
            ->whereNotNull('vehicle_id')
            ->with(['inspector:id,name', 'assignedDriver:id,name', 'vendor:id,name'])
            ->get(['id', 'vehicle_id', 'workflow_status', 'inspected_by', 'assigned_driver_id', 'vendor_id', 'last_state_change_at', 'updated_at'])
            ->groupBy('vehicle_id')
            ->map(fn ($g) => $g->sortByDesc('id')->first());

        // Enterprise Handover Workflow — a paused ticket is DELIBERATELY not in WF_TICKET_STATES (see
        // $tickets above), so it needs its own bulk query to surface the "paused_out" /
        // "paused_returned_pending_handover" split (see buildRow()).
        $pausedTickets = Maintenance::where('workflow_status', Maintenance::WF_PAUSED_RETURNED_TO_SERVICE)
            ->whereNotNull('vehicle_id')
            ->get(['id', 'vehicle_id', 'workflow_status', 'vehicle_returned_at', 'paused_reason', 'last_state_change_at', 'updated_at'])
            ->groupBy('vehicle_id')
            ->map(fn ($g) => $g->sortByDesc('id')->first());

        // Open rental (type-C) → who holds the car + since when.
        $rentals = Contract::where('contract_type', 'C')->currentlyOpen()
            ->whereNotNull('vehicle_id')
            ->with('customer:id,name_en,name_ar')
            ->get(['id', 'vehicle_id', 'out_date', 'customer_id'])
            ->groupBy('vehicle_id')
            ->map(fn ($g) => $g->sortByDesc('id')->first());

        // Open maintenance (type-U) contract → since when (the "in the garage, no formal ticket" case).
        $maintContracts = Contract::where('contract_type', 'U')->currentlyOpen()
            ->whereNotNull('vehicle_id')
            ->get(['id', 'vehicle_id', 'out_date'])
            ->groupBy('vehicle_id')
            ->map(fn ($g) => $g->sortByDesc('id')->first());

        // Cars in the garage on a hand-entered event (their latest OUT is still open) + when they left.
        $manualIds = $this->ops->manualGarageVehicleIds();
        $manualSet = array_flip($manualIds);
        $manualOut = Maintenance::query()
            ->where('origin', Maintenance::ORIGIN_MANUAL)
            ->whereIn('vehicle_id', $manualIds ?: [0])
            ->where('event_status', '<>', 'IN')
            ->get(['id', 'vehicle_id', 'out_date'])
            ->groupBy('vehicle_id')
            ->map(fn ($g) => $g->sortByDesc('out_date')->first());

        // Cars with a safety/ops inspection due (time axis) → the "return check" next-step for an idle
        // car. One bulk query; the id set is a cheap lookup in the per-car derivation below.
        $dueInspectionSet = array_flip(
            InspectionSchedule::query()
                ->where('active', true)
                ->whereNotNull('vehicle_id')
                ->whereNotNull('next_due_at')
                ->where('next_due_at', '<=', Carbon::now()->addDays(InspectionSchedule::DUE_SOON_DAYS))
                ->distinct()->pluck('vehicle_id')->all()
        );

        $rows   = [];
        $counts = ['total' => 0, 'available' => 0, 'rented' => 0, 'maintenance' => 0, 'blocked' => 0, 'transit' => 0];

        foreach ($vehicles as $v) {
            $row = $this->buildRow(
                $v,
                $tickets->get($v->id),
                $rentals->get($v->id),
                $maintContracts->get($v->id),
                $manualOut->get($v->id),
                isset($manualSet[$v->id]),
                isset($dueInspectionSet[$v->id]),
                $pausedTickets->get($v->id),
            );
            $rows[] = $row;

            $counts['total']++;
            if ($row['bucket'] !== 'left') {
                $counts[$row['bucket']] = ($counts[$row['bucket']] ?? 0) + 1;
            }
            if ($row['blocked']) {
                $counts['blocked']++;
            }
        }

        // Blocked / needs-attention first, then longest-waiting — the order a follow-up screen wants.
        usort($rows, function ($a, $b) {
            if ($a['blocked'] !== $b['blocked']) {
                return $b['blocked'] <=> $a['blocked'];
            }
            $order = ['maintenance' => 0, 'transit' => 1, 'rented' => 2, 'available' => 3, 'left' => 4];
            $ao = $order[$a['bucket']] ?? 5;
            $bo = $order[$b['bucket']] ?? 5;
            if ($ao !== $bo) {
                return $ao <=> $bo;
            }
            return ($b['days_in_status'] ?? -1) <=> ($a['days_in_status'] ?? -1);
        });

        return ['rows' => $rows, 'counts' => $counts];
    }

    /**
     * Derive the one row for a single vehicle from its already-loaded signals.
     */
    private function buildRow(
        Vehicle $v,
        ?Maintenance $ticket,
        ?Contract $rental,
        ?Contract $maintContract,
        ?Maintenance $manualEvent,
        bool $manualHold,
        bool $dueInspection = false,
        ?Maintenance $pausedTicket = null,
    ): array {
        $name   = $v->model ?: ($v->make ?: 'Vehicle');
        $title  = trim($name . ' ' . ($v->year ?? ''));
        $sub    = trim(($v->make && $v->model ? $v->make . ' · ' : '') . ($v->plate_no ?: '')); // e.g. "Nissan · A 12345"

        $inMaintenance = $ticket !== null || $maintContract !== null || $manualHold || $v->status === 'under_maintenance';
        $left          = in_array($v->status, self::LEFT_FLEET, true);

        // Stage precedence mirrors the operational_status cascade: left fleet → maintenance →
        // transit → rented → grounded(red/yellow) → paused (Enterprise Handover Workflow) → available.
        if ($left) {
            $stage = 'left';
        } elseif ($inMaintenance) {
            $stage = $ticket ? (self::WF_STAGE[$ticket->workflow_status] ?? 'in_garage') : 'in_garage_noticket';
        } elseif ($v->operational_status === 'in_transit') {
            $stage = 'transit';
        } elseif ($rental !== null || $v->operational_status === 'rented') {
            $stage = 'rented';
        } elseif (in_array($v->condition_grade, ['red', 'yellow'], true)) {
            $stage = 'grounded';
        } elseif ($pausedTicket !== null) {
            $stage = $pausedTicket->vehicle_returned_at === null ? 'paused_out' : 'paused_returned_pending_handover';
        } else {
            $stage = 'available';
        }

        $meta = self::STAGE_META[$stage];

        // Owner: WHO physically holds / is responsible for the car RIGHT NOW. As the ticket moves
        // through the hand-off chain the seat changes hands, so resolve the real person on the live
        // ticket for the current stage; fall back to the generic role label when the seat is empty.
        // A rented car is "held" by its customer.
        $owner = $meta['owner'];
        if ($stage === 'rented') {
            $customer = $rental?->customer;
            $cname    = $customer ? ($customer->name_en ?: $customer->name_ar) : null;
            $owner    = $cname ? 'Customer · ' . $cname : 'Customer';
        } elseif ($ticket) {
            // stage → [role prefix, the real person on the ticket driving this stage].
            [$role, $person] = match ($stage) {
                'inspection_requested', 'under_diagnosis', 'reinspection'
                    => ['Inspector', $ticket->inspector?->name],
                'awaiting_pickup', 'to_garage'
                    => ['Driver', $ticket->assignedDriver?->name],
                'in_garage', 'repair_review'
                    => ['Garage', $ticket->vendor?->name],
                default => [null, null],
            };
            if ($person) {
                $owner = $role . ' · ' . $person;
            }
        }

        // "Days in status" anchor — the moment the car entered its current situation.
        $anchor = match (true) {
            $ticket !== null                 => $ticket->last_state_change_at ?? $ticket->updated_at,
            $stage === 'in_garage_noticket'  => $maintContract?->out_date ?? $manualEvent?->out_date,
            $stage === 'rented'              => $rental?->out_date,
            $pausedTicket !== null && in_array($stage, ['paused_out', 'paused_returned_pending_handover'], true)
                => $pausedTicket->last_state_change_at ?? $pausedTicket->updated_at,
            default                          => null,
        };
        $days = $anchor ? Carbon::parse($anchor)->startOfDay()->diffInDays(Carbon::now()->startOfDay()) : null;

        // Left-fleet rows carry their real OM lifecycle label rather than the generic "Left the Fleet".
        $status = $left
            ? (Vehicle::STATUS_LABELS[$v->status] ?? $meta['status'])
            : $meta['status'];

        // "Set to Ready" only where it can honestly succeed WITHOUT corrupting the workflow engine:
        //   - a car held only by a hand-entered event / type-U contract (no formal ticket), or
        //   - a ticket awaiting re-inspection (WF_READY_REINSPECTION legally closes).
        $canSetReady = $stage === 'in_garage_noticket'
            || ($ticket && $ticket->workflow_status === Maintenance::WF_READY_REINSPECTION);

        return [
            'id'             => $v->id,
            'title'          => $title,
            'subtitle'       => $sub,
            'plate_no'       => $v->plate_no,
            'model'          => $v->model,
            'make'           => $v->make,
            'year'           => $v->year,
            'stage'          => $stage,
            'status'         => $status,
            'status_tone'    => $meta['tone'],
            'owner'          => $owner,
            'last_action'    => $meta['last'],
            'next_action'    => $meta['next'],
            // The single actionable "do this next" step — turns the status board into a task list.
            'next_step'      => $this->deriveNextStep($stage, $ticket, $v, $dueInspection),
            'days_in_status' => $days,
            'blocked'        => $meta['blocked'],
            'bucket'         => $meta['bucket'],
            'ticket_id'      => $ticket?->id ?? $pausedTicket?->id,
            'can_set_ready'  => (bool) $canSetReady,
            // Enterprise Handover Workflow — present only on the two paused stages.
            'pause_reason'        => $pausedTicket?->paused_reason,
            'vehicle_returned_at' => optional($pausedTicket?->vehicle_returned_at)->toIso8601String(),
        ];
    }

    /**
     * The single most useful ACTION a team member can take on this car right now — the "Next Step"
     * that turns the status board into a daily task list. It is derived from the same lifecycle stage
     * the row already carries (driven by the car's open workflow_status), plus two readiness signals
     * for an idle car: an oil service that is due, and a safety/ops inspection that is due (the
     * "return check"). Every actionable step carries an `href` that lands the user on the EXACT page
     * (or deep-linked ticket) where the step is performed; a non-actionable step (on rent, left the
     * fleet, ready & waiting on a booking) has href null.
     *
     * @return array{label:string, tone:string, href:?string, actionable:bool}
     */
    private function deriveNextStep(string $stage, ?Maintenance $ticket, Vehicle $v, bool $dueInspection): array
    {
        // Every maintenance-workflow hand-off is performed on the ticket's full-page command view.
        $ticketHref = $ticket ? '/maintenance-workflow/' . $ticket->id : '/maintenance';

        // Decision gates — stages whose outcome BRANCHES two ways. We surface BOTH so the board shows
        // the real choice the responsible person faces (both take them to the ticket to execute it):
        //   reinspection  — the final road test & sign-off: Pass (back to fleet) or Fail (send back).
        //   repair_review — the supervisor's video review: Approve or Request a re-fix.
        if ($stage === 'reinspection') {
            return $this->step('Conduct Test Drive', 'violet', $ticketHref, [
                ['label' => 'Pass — Return to Fleet', 'tone' => 'green', 'href' => $ticketHref],
                ['label' => 'Fail — Send Back',       'tone' => 'red',   'href' => $ticketHref],
            ]);
        }
        if ($stage === 'repair_review') {
            return $this->step('Review Repair Video', 'violet', $ticketHref, [
                ['label' => 'Approve',        'tone' => 'green', 'href' => $ticketHref],
                ['label' => 'Request Re-fix', 'tone' => 'red',   'href' => $ticketHref],
            ]);
        }

        // Single-action maintenance stages: label = the next hand-off in the workflow chain.
        $workflow = [
            'inspection_requested' => ['Start Test Drive',      'amber'],
            'under_diagnosis'      => ['Complete Diagnosis',    'amber'],
            'awaiting_dispatch'    => ['Assign Garage',         'amber'],
            'awaiting_pickup'      => ['Pick Up Car',           'amber'],
            'to_garage'            => ['Confirm Arrival',       'violet'],
            'in_garage'            => ['Monitor Repair',        'amber'],
            'reinspection_failed'  => ['Re-dispatch to Garage', 'red'],
        ];
        if (isset($workflow[$stage])) {
            return $this->step($workflow[$stage][0], $workflow[$stage][1], $ticketHref);
        }

        return match ($stage) {
            'in_garage_noticket' => $this->step('Open Repair Ticket', 'red',    '/maintenance'),
            'grounded'           => $this->step('Route to Garage',    'red',    '/maintenance'),
            'transit'            => $this->step('Track Delivery',     'violet', '/logistics'),
            'rented'             => $this->step('On Rent — Monitor',  'blue',   null),
            'left'               => $this->step('—',                  'gray',   null),
            'available'          => $this->idleNextStep($v, $dueInspection),
            default              => $this->step('—', 'gray', null),
        };
    }

    /**
     * Shape one next-step. `href` null ⇒ non-actionable (a quiet status pill). `options` (each
     * {label, tone, href}) is present only at a decision gate that branches two ways — the UI renders
     * one pill per option so the real choice is visible on the board.
     *
     * @param  array<int, array{label:string, tone:string, href:?string}>  $options
     * @return array{label:string, tone:string, href:?string, actionable:bool, options:array}
     */
    private function step(string $label, string $tone, ?string $href, array $options = []): array
    {
        return [
            'label'      => $label,
            'tone'       => $tone,
            'href'       => $href,
            'actionable' => $href !== null || ! empty($options),
            'options'    => $options,
        ];
    }

    /**
     * Next step for an idle (Ready-for-Rent) car: a due oil service wins ("Schedule Oil Change"),
     * else a due safety/ops inspection ("Perform Return Inspection"), else nothing to do but wait
     * for a booking.
     *
     * @return array{label:string, tone:string, href:?string, actionable:bool, options:array}
     */
    private function idleNextStep(Vehicle $v, bool $dueInspection): array
    {
        if (($v->serviceStatus()['status'] ?? null) === 'service_due') {
            return $this->step('Schedule Oil Change', 'amber', '/inspections/schedules?tab=service');
        }
        if ($dueInspection) {
            return $this->step('Perform Return Inspection', 'amber', '/inspections/schedules?tab=readiness');
        }
        return $this->step('Ready — Awaiting Booking', 'green', null);
    }
}
