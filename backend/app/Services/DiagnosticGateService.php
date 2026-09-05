<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Maintenance;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;

/**
 * The Diagnostic condition brain — decides what routine upkeep a car is DUE for, and (for the
 * post-downtime case) how long it has been sitting. It has no UI of its own: the Proactive Diagnostic
 * Monitor (inspections:generate-tasks) reads conditionsDue() to raise a system "Needs Test Drive"
 * request + notify the Inspector, injecting the specifics into the ticket agenda.
 *
 * Two directives:
 *   - routine_maintenance : an interval-based check is overdue (oil km/date, tyres, battery age).
 *   - post_downtime       : the car has been idle ≥ the downtime limit → a Post-Downtime Safety Check
 *                           (the check carries the idle-days count + the safety checklist to inspect).
 */
class DiagnosticGateService
{
    public const DIRECTIVE_ROUTINE  = 'routine_maintenance';
    public const DIRECTIVE_DOWNTIME = 'post_downtime';
    public const DIRECTIVE_INACTIVITY = 'inactivity';

    /** Battery age past which it warrants a charge check (mirrors VehicleReadinessService). */
    private const BATTERY_LIFE_MONTHS = 30;

    /** The safety items an inspector should check on a car returning from a long idle. */
    public const POST_DOWNTIME_CHECKLIST = ['Battery', 'Fluids', 'Brakes'];

    /** Each checklist item's ready-entry-point keyword in the Findings catalog (Decide step suggestion). */
    private const CHECKLIST_FINDING_MAP = [
        'Battery' => 'Battery Replacement',
        'Fluids'  => 'Low fluid level',
        'Brakes'  => 'Brake noise (squeal / grind)',
    ];

    /** Live movement states in which the car is actively busy — it cannot be "sitting idle". */
    private const BUSY_OPERATIONAL = ['rented', 'maintenance', 'in_transit', 'test', 'transfer', 'sale_prep'];

    /** OM lifecycle statuses that mean the car has left the active fleet — no monitoring applies. */
    private const LEFT_FLEET = ['disposed', 'sold', 'out_of_order', 'suspended', 'returned'];

    public function enabled(): bool
    {
        return (bool) config('features.diagnostic_gate.enabled', true);
    }

    public function downtimeLimitDays(): int
    {
        return max(1, (int) config('features.diagnostic_gate.downtime_days', 21));
    }

    /**
     * The grace window (calendar days) after which a car that has NOT been rented since its last test is
     * automatically sent for a fresh check-up — the counterpart to the post-downtime rule, which withholds
     * a check for a never-re-rented car. Kept at least as long as the downtime limit so an unused car gets
     * a longer leash before we test it (a rented-again car trips the shorter downtime rule first).
     */
    public function inactivityLimitDays(): int
    {
        return max($this->downtimeLimitDays(), (int) config('features.diagnostic_gate.inactive_days', 30));
    }

    /**
     * The conditions a car is DUE for right now — the raw check list (oil / tyres / battery /
     * post-downtime), each with its key, human label, severity, directive and detail. This is what the
     * proactive monitor (inspections:generate-tasks) reads to decide when to auto-raise a "Needs Test
     * Drive" ticket and what agenda ("go check the oil and battery") to put on it.
     *
     * @return array<int,array<string,mixed>>  each: {key,directive,label,severity,detail,axis,...}
     */
    public function conditionsDue(Vehicle $vehicle): array
    {
        if (! $this->enabled() || in_array($vehicle->status, self::LEFT_FLEET, true)) {
            return [];
        }

        return $this->dueChecks($vehicle, $this->downtimeInfo($vehicle));
    }

    /**
     * The RULEBOOK — the same conditions dueChecks() evaluates, described once for humans, with the LIVE
     * thresholds read from config rather than retyped. This is what the Inspection Review Queue's
     * "When & why the system asks for a test" panel renders, so the explanation on screen can never drift
     * from the rules that actually fire: change `features.diagnostic_gate.*` and the panel changes with it.
     *
     * Deliberately describes rules only — it takes no vehicle and touches no data.
     *
     * @return array<string,mixed>
     */
    public function rulebook(): array
    {
        $downtime = $this->downtimeLimitDays();
        $inactive = $this->inactivityLimitDays();

        return [
            'enabled'  => $this->enabled(),

            // One plain sentence an operator can read in three seconds. Everything below it is detail.
            'headline' => 'Nobody asks for these tests. Every morning the system checks each car in the fleet, '
                . 'and when a car is past one of the limits below, it puts a request here for you to approve.',

            // Mirrors the Schedule::command('inspections:generate-tasks')->dailyAt('07:30') entry in
            // routes/console.php — the one value here NOT read from config, so keep the two in step.
            'schedule' => [
                'command'     => 'inspections:generate-tasks',
                'runs_at'     => '07:30',
                'frequency'   => 'daily',
                'description' => 'The system checks the whole fleet once every morning at 07:30.',
            ],

            // The journey, as four steps — the operator's mental model of where a system request comes from
            // and where it goes. Rendered as the flow strip at the top of the panel.
            'steps' => [
                ['title' => 'Every morning', 'text' => 'The system checks every car that is with us and working.'],
                ['title' => 'It finds a car past a limit', 'text' => 'Oil, tyres, battery, or too long since its last workshop visit.'],
                ['title' => 'It asks here', 'text' => 'A request appears in this queue marked “System Schedule”, with what to check.'],
                ['title' => 'You decide', 'text' => 'Approve and the inspector gets it. Reject and it stops here — nothing leaves the office.'],
            ],

            // Every gate a car must pass before a request is raised at all (see InspectionsGenerateTasks).
            'preconditions' => [
                'The car is with us and working — Ready or Rented. Sold, suspended and office cars are never checked.',
                'The car is not up for sale.',
                'The car is not already in the workshop or waiting for one — so nobody is asked twice for the same car.',
                // No "above"/"below" here — this list is rendered beside the rules, not under them.
                'At least one of the limits has actually been passed.',
            ],

            'rules' => [
                [
                    'key'       => 'oil_change',
                    'label'     => 'Oil change is late',
                    'severity'  => 'moderate',
                    'axis'      => 'km or date',
                    // `plain` is the sentence on the card. `note` is the extra nuance underneath it.
                    'plain'     => 'The car has driven past its oil-change distance, or the oil-change date has passed.',
                    'note'      => 'Whichever comes first counts — distance or date.',
                    'chip'      => 'Service interval',
                    'agenda'    => 'Oil Change',
                ],
                [
                    'key'       => 'reminders',
                    'label'     => 'Another service is late',
                    'severity'  => 'moderate / routine',
                    'axis'      => 'km or date',
                    'plain'     => 'A service reminder set on the car — tyre rotation, tyre change, brakes, filters — is past its date or its kilometres.',
                    'note'      => 'Each reminder has its own limit. Tyres are treated as more urgent than the rest.',
                    'chip'      => 'Per reminder',
                    'agenda'    => 'Whatever the reminder is for',
                ],
                [
                    'key'       => 'battery',
                    'label'     => 'Battery is old',
                    'severity'  => 'routine',
                    'axis'      => 'date',
                    'plain'     => 'The battery was last changed more than ' . self::BATTERY_LIFE_MONTHS . ' months ago.',
                    'note'      => 'Only used when the car has no battery reminder of its own already.',
                    'chip'      => self::BATTERY_LIFE_MONTHS . ' months',
                    'agenda'    => 'Battery Status',
                ],
                [
                    'key'       => 'downtime',
                    'label'     => 'Too long since the last workshop visit',
                    'severity'  => 'moderate',
                    'axis'      => 'date',
                    'plain'     => 'It has been more than ' . $downtime . ' days since the car left the workshop, and it has been rented since then.',
                    'note'      => 'The days keep counting while the car is out with a customer. They only pause while the car is being handled in a workshop — '
                        . 'either on a ticket here, or on a maintenance contract in OfficeManager. The day the car comes back, the count starts again from zero. '
                        . 'If two records say it came back on different days, the later day wins.',
                    'chip'      => $downtime . ' days',
                    'agenda'    => 'Check ' . $this->humanList(self::POST_DOWNTIME_CHECKLIST),
                ],
                [
                    'key'       => 'inactivity',
                    'label'     => 'Car has been sitting unused',
                    'severity'  => 'routine',
                    'axis'      => 'date',
                    'plain'     => 'The car has not been rented once since its last test, and ' . $inactive . ' days have passed.',
                    'note'      => 'This is the opposite case to the one above: that one needs a rental since the last visit, this one needs none. A car can only trip one of the two.',
                    'chip'      => $inactive . ' days',
                    'agenda'    => 'General check-up',
                ],
            ],

            // Conditions the monitor drops on purpose, and why — so a Controller who expected a request and
            // did not get one can tell "not due" apart from "suppressed".
            'suppressions' => [
                [
                    'label' => 'A kilometre reading that cannot be true',
                    'why'   => 'If a car looks more than ' . number_format(self::OIL_ANOMALY_FLOOR_KM) . ' km past its oil change (or three times its interval, whichever is bigger), that is a wrong odometer, not a real car. The oil part is dropped and reported as a data problem instead of being sent to the inspector. Anything else on the same car is still requested.',
                ],
                [
                    'label' => 'The car went to the workshop after the system asked',
                    'why'   => 'A request the system raised is taken back off this queue once the car has been to a workshop and come back — the count started again on the day it returned, so the reason the system asked no longer exists. It moves to the withdrawn list with the date it came back. A request a person made is never taken back this way; only a person can close that.',
                ],
                [
                    'label' => 'The safety list is a "go look", not a verdict',
                    'why'   => 'When a car is asked for the ' . $this->humanList(self::POST_DOWNTIME_CHECKLIST) . ' check, that is a list of things to look at. It is never filled in as work that needs doing — nothing has been found yet.',
                ],
            ],

            'outcome' => 'The request lands here, in this queue, marked “System Schedule” — the system raised it, no person did. '
                . 'It reaches the inspector only after you approve it. If you reject it, it stops here and nothing is sent outside the office.',

            'limits'  => [
                'downtime_days'        => $downtime,
                'inactive_days'        => $inactive,
                'battery_life_months'  => self::BATTERY_LIFE_MONTHS,
                'oil_ceiling_floor_km' => self::OIL_ANOMALY_FLOOR_KM,
            ],
        ];
    }

    /** "A, B, and C" — natural-language list used by the rulebook copy. */
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

    /**
     * PUBLIC: just the idle (park-duration) info for a car — how long it has been sitting since its last
     * movement. Drives the Scheduled-tab intake ("this car has been parked N days") before any ticket
     * exists. Same shape as the `idle` block of context(): { eligible, idle_since, days, limit, exceeded,
     * held } — `held` is true while the car is actively being processed through a workflow (clock paused).
     *
     * @return array{eligible:bool,idle_since:?string,days:?int,limit:int,exceeded:bool,held:bool}
     */
    public function idleInfo(Vehicle $vehicle): array
    {
        return $this->downtimeInfo($vehicle);
    }

    /**
     * "How many days before the system asks for a test on this car?" — the countdown the review queue's
     * fleet tab renders, one row per car.
     *
     * The queue only ever shows cars the system has ALREADY asked about. This answers the question that
     * comes before it: which cars are coming, and when. It is the same rulebook read forwards instead of
     * backwards — no second definition of "due", so a car listed here at 0 days is exactly a car the
     * 07:30 scan raises.
     *
     * The number is the DATE clock (post-downtime, or inactivity for a car never rented since its last
     * test). It is deliberately not a number in the four cases where a number would be a lie:
     *
     *   due_now            — a rule has already fired (any rule, including the km-based oil/reminder
     *                        ones, which have no day count at all). The next scan raises it.
     *   in_pipeline        — already requested or in a workflow; the scan skips this car, so no clock is
     *                        running towards a NEW request.
     *   held               — in the shop (active workflow or open OM maintenance contract). The clock is
     *                        paused and restarts from zero when the car comes back.
     *   waiting_for_rental — the limit has passed but the car has not been rented since its last test, so
     *                        the post-downtime rule withholds. Its inactivity clock is the live one.
     *
     * @param  array<int,true>  $inPipeline  vehicle_id ⇒ true, prefetched by fleetTestCountdown()
     * @return array<string,mixed>
     */
    public function testCountdown(Vehicle $vehicle, array $inPipeline = [], ?array $prefetchedLog = null): array
    {
        $anchor = $this->readyAnchor($vehicle);
        $stay   = $this->shopStay($vehicle, $prefetchedLog);
        $row    = [
            'vehicle_id'   => $vehicle->id,
            'plate_no'     => $vehicle->plate_no,
            'make'         => $vehicle->make,
            'model'        => $vehicle->model,
            'status'       => $vehicle->status,
            // Rental / movement state. While the car is in a shop this is NOT "rented", whatever a
            // stale contract row says — being on a lift is not being out with a customer.
            'operational_status' => $stay ? 'maintenance' : $vehicle->operational_status,
            // The record the count runs from — never a bare number without the evidence behind it.
            'anchor'       => $anchor,
            // The last REAL test drive, which most cars simply do not have. Kept separate from the
            // anchor so the UI can never pass "came back from a garage" off as "was tested".
            'last_test'    => $this->lastTest($vehicle),
            'days_since'   => $anchor['days_ago'],
            'days_left'    => null,
            'days_over'    => null,
            'due_on'       => null,
            'parked'       => $stay ? [
                'source'       => $stay['source'],
                'ref_id'       => $stay['ref_id'],
                'label'        => $stay['label'],
                'contract_no'  => $stay['contract_no'],
                'garage'       => $stay['garage'],
                'work'         => $stay['work'],
                'started_at'   => optional($stay['started_at'])->toDateString(),
                'days_in_shop' => $stay['days_in_shop'],
            ] : null,
            'reasons'      => [],
            'why'          => null,
        ];

        if (! $this->enabled() || in_array($vehicle->status, self::LEFT_FLEET, true)) {
            return array_merge($row, [
                'state'      => 'not_monitored',
                'bucket'     => 'not_monitored',
                'limit_days' => $this->downtimeLimitDays(),
                'why'        => 'This car is not in the active fleet, so the system does not check it.',
            ]);
        }

        $idle  = $this->downtimeInfo($vehicle, $prefetchedLog);
        $limit = (int) $idle['limit'];

        // PARKED WINS. A car on a lift is not counting down towards anything, and saying "due in 3
        // days" about it would be a promise the system cannot keep — it does not know when the shop
        // will release it. OM contracts carry no planned end date while they are open, so there is no
        // honest "maintenance ends on" to show; what IS knowable is what happens next, and that is
        // stated instead. Checked BEFORE the pipeline test so a parked car with a live ticket still
        // reads as parked, which is the physically true thing about it.
        if (! empty($idle['held']) && $stay !== null) {
            return array_merge($row, [
                'state'      => 'parked',
                'bucket'     => 'parked',
                'limit_days' => $limit,
                'why'        => $stay['source'] === 'om_contract'
                    ? 'In OM maintenance since ' . optional($stay['started_at'])->format('d M Y')
                        . ' (' . $stay['days_in_shop'] . ' ' . ($stay['days_in_shop'] === 1 ? 'day' : 'days') . '). The countdown is paused.'
                    : 'At ' . ($stay['garage'] ?: 'a garage') . ' since ' . optional($stay['started_at'])->format('d M Y')
                        . ' (' . $stay['days_in_shop'] . ' ' . ($stay['days_in_shop'] === 1 ? 'day' : 'days') . '). The countdown is paused.',
                'on_release' => 'When the visit closes, the count starts again from the day it comes back.',
            ]);
        }

        // Being actively worked on under a ticket (test drive started / committed repair) — also held,
        // but with no shop stay behind it, so it is the workflow that is holding the clock.
        if (! empty($idle['held'])) {
            return array_merge($row, [
                'state'      => 'in_workflow',
                // Its OWN lane, not 'parked': these cars are being worked on under a ticket but are not
                // in a shop, and folding them together would have the board claim 81 cars are on lifts
                // when 58 are.
                'bucket'     => 'in_workflow',
                'limit_days' => $limit,
                'why'        => 'Being worked on right now, so the countdown is paused.',
                'on_release' => 'When the ticket closes, the count starts again from that day.',
            ]);
        }

        // Already asked for — the scan skips this car, so no clock is running towards a NEW request.
        if (isset($inPipeline[$vehicle->id])) {
            return array_merge($row, [
                'state'      => 'in_pipeline',
                'bucket'     => 'requested',
                'limit_days' => $limit,
                'why'        => 'A request for this car is already open, so the system will not raise another.',
            ]);
        }

        $daysSince = (int) ($idle['days'] ?? 0);

        // Anything due right now wins the label, whatever axis it came from (km or date).
        $due = $this->dueChecks($vehicle, $idle);
        if ($due !== []) {
            $row['reasons'] = array_values(array_map(fn ($c) => [
                'key'      => $c['key'] ?? null,
                'label'    => $c['label'] ?? null,
                'severity' => $c['severity'] ?? null,
                'detail'   => $c['detail'] ?? ($c['why'] ?? null),
            ], $due));

            // Overdue is only meaningful on the DATE clock. A car due on kilometres (oil, a service
            // reminder) has no "days late" at all, so it reports due-today rather than inventing one.
            $over   = max(0, $daysSince - $limit);
            $labels = implode(', ', array_filter(array_column($row['reasons'], 'label')));

            return array_merge($row, [
                'state'      => 'due_now',
                'bucket'     => $over > 0 ? 'overdue' : 'today',
                'limit_days' => $limit,
                'days_left'  => 0,
                'days_over'  => $over > 0 ? $over : null,
                'due_on'     => Carbon::now()->startOfDay()->toDateString(),
                'why'        => $over > 0
                    ? $labels . ' — ' . $daysSince . ' days since it was last ready, ' . $over . ' past the ' . $limit . '-day limit.'
                    : $labels . ' — ' . $daysSince . ' days since it was last ready (limit ' . $limit . ').',
            ]);
        }

        // The limit lapsed, but the car has not been back on the road since its last test, so the
        // post-downtime rule deliberately withholds. Its longer inactivity clock is the live one.
        if (! empty($idle['awaiting_service'])) {
            $inactive = $this->inactivityLimitDays();
            $left     = max(0, $inactive - $daysSince);

            return array_merge($row, [
                'state'      => 'waiting_for_rental',
                'bucket'     => $this->bucketFor($left),
                'limit_days' => $inactive,
                'days_left'  => $left,
                'due_on'     => Carbon::now()->startOfDay()->addDays($left)->toDateString(),
                'why'        => 'Past the ' . $limit . '-day limit (' . $daysSince . ' days) but not rented since its last check, '
                    . 'so no test is asked for yet. If it stays unused it is checked at ' . $inactive . ' days.',
            ]);
        }

        $left = max(0, $limit - $daysSince);

        return array_merge($row, [
            'state'      => 'counting',
            'bucket'     => $this->bucketFor($left),
            'limit_days' => $limit,
            'days_left'  => $left,
            'due_on'     => Carbon::now()->startOfDay()->addDays($left)->toDateString(),
            'why'        => $daysSince . ' of ' . $limit . ' days since it was last ready'
                . ($vehicle->operational_status === 'rented' ? ', and it is out on hire.' : '.'),
        ]);
    }

    /** Which planning lane a countdown falls in — the grouping the planning board renders. */
    private function bucketFor(int $daysLeft): string
    {
        return match (true) {
            $daysLeft <= 0 => 'today',
            $daysLeft === 1 => 'tomorrow',
            $daysLeft <= 3 => 'soon',
            default => 'later',
        };
    }

    /**
     * The whole active fleet's countdown, soonest first. The two fleet-wide facts — which cars already
     * have an open request, and which are sitting in a garage per the log — are resolved ONCE here
     * rather than per row: the same sets InspectionsGenerateTasks refuses to raise for, so the planning
     * board and the scan can never disagree about who is spoken for or who is on a lift.
     *
     * @return array<int,array<string,mixed>>
     */
    public function fleetTestCountdown(): array
    {
        $inPipeline = Maintenance::openWorkflow()
            ->whereNotNull('vehicle_id')
            ->pluck('vehicle_id')
            ->flip()
            ->map(fn () => true)
            ->all();

        $log = $this->openWorkshopLogEvents(null);

        $rows = [];
        Vehicle::whereIn('status', Vehicle::ACTIVE_STATUSES)
            ->where(fn ($q) => $q->where('for_sale', false)->orWhereNull('for_sale'))
            ->orderBy('code')
            ->chunkById(500, function ($vehicles) use (&$rows, $inPipeline, $log) {
                foreach ($vehicles as $v) {
                    $rows[] = $this->testCountdown($v, $inPipeline, $log);
                }
            });

        // Soonest first; rows with no number (parked / requested / not monitored) sink to the bottom,
        // because "no date" is not "date zero". Overdue cars sort above due-today by how late they are.
        $rank = ['overdue' => 0, 'today' => 1, 'tomorrow' => 2, 'soon' => 3, 'later' => 4, 'requested' => 5, 'in_workflow' => 6, 'parked' => 7, 'not_monitored' => 8];
        usort($rows, function ($a, $b) use ($rank) {
            $ra = $rank[$a['bucket']] ?? 9;
            $rb = $rank[$b['bucket']] ?? 9;
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            // Within overdue: the latest first. Within the rest: the soonest first.
            if ($a['bucket'] === 'overdue') {
                return ($b['days_over'] ?? 0) <=> ($a['days_over'] ?? 0);
            }

            return (($a['days_left'] ?? PHP_INT_MAX) <=> ($b['days_left'] ?? PHP_INT_MAX))
                ?: strcmp((string) $a['plate_no'], (string) $b['plate_no']);
        });

        return $rows;
    }

    /**
     * Every car physically in a shop right now, with the story a Controller needs: which visit parked
     * it, since when, what the car's last check was, and — the part that was missing — the test request
     * that was already pending when it went in, so a recommendation made before the visit is not simply
     * lost from view.
     *
     * Driven by the SHOP STAY, not by the requests: a car in the shop belongs on this list whether or
     * not anyone had asked for a test on it. That is the fix — the old tab could only ever show cars
     * that happened to have a parked request, so most cars in the shop were invisible on it.
     *
     * @return array{rows:array<int,array<string,mixed>>,outside_fleet:array<int,array<string,mixed>>}
     */
    public function fleetParked(): array
    {
        $outsideFleet = [];
        $log = $this->openWorkshopLogEvents(null);

        $onContract = Contract::where('contract_type', 'U')
            ->currentlyOpen()
            ->whereNotNull('vehicle_id')
            ->pluck('vehicle_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        $ids = array_values(array_unique(array_merge($onContract, array_keys($log))));
        if ($ids === []) {
            return ['rows' => [], 'outside_fleet' => []];
        }

        // The request each car had when it went in — parked (system-withdrawn) or still awaiting a
        // decision. Newest per car. This is the "previous recommendation" the visit interrupted.
        $requests = Maintenance::query()
            ->whereIn('vehicle_id', $ids)
            ->whereIn('workflow_status', [Maintenance::WF_PENDING_REVIEW, Maintenance::WF_REVIEW_REJECTED, Maintenance::WF_INSPECTION_REQUESTED])
            ->orderByDesc('id')
            ->get(['id', 'vehicle_id', 'workflow_status', 'request_origin', 'trigger_reason', 'trigger_detail', 'created_at', 'requested_at', 'review_rejection_code', 'review_auto_context'])
            ->groupBy('vehicle_id');

        $rows = [];
        foreach (Vehicle::whereIn('id', $ids)->orderBy('code')->get() as $v) {
            $stay = $this->shopStay($v, $log);
            if (! $stay) {
                continue; // released between the two queries — it belongs on the planning board now
            }

            // SAME SCOPE AS THE PLANNING BOARD. A car that is suspended or up for sale is not part of
            // the operational fleet the countdown covers, and listing it here would make the two tabs
            // disagree about how many cars are in the shop — the very confusion this rewrite removes.
            // Flagged rather than silently dropped, so "why is my suspended car missing" has an answer.
            $inActiveFleet = in_array($v->status, Vehicle::ACTIVE_STATUSES, true) && ! $v->for_sale;
            if (! $inActiveFleet) {
                $outsideFleet[] = ['plate_no' => $v->plate_no, 'status' => $v->status, 'for_sale' => (bool) $v->for_sale];
                continue;
            }

            $req = ($requests[$v->id] ?? collect())->first();
            $anchor = $this->readyAnchor($v);

            $rows[] = [
                'vehicle_id' => $v->id,
                'plate_no'   => $v->plate_no,
                'make'       => $v->make,
                'model'      => $v->model,
                'parked'     => [
                    'source'       => $stay['source'],
                    'ref_id'       => $stay['ref_id'],
                    'label'        => $stay['label'],
                    'contract_no'  => $stay['contract_no'],
                    'garage'       => $stay['garage'],
                    'work'         => $stay['work'],
                    'customer'     => $stay['customer'],
                    'started_at'   => optional($stay['started_at'])->toDateString(),
                    'days_in_shop' => $stay['days_in_shop'],
                ],
                'anchor'    => $anchor,
                'last_test' => $this->lastTest($v),
                // The recommendation that existed before the visit — preserved, not deleted.
                'request'   => $req ? [
                    'ticket_id'      => $req->id,
                    'status'         => $req->workflow_status,
                    'origin'         => $req->request_origin,
                    'trigger_reason' => $req->trigger_reason,
                    // Which lane raised it — a routine test, or an Oil Projection follow-up. The two
                    // must stay tellable apart (they answer to different rules entirely).
                    'source_lane'    => (is_array($req->trigger_detail) && ($req->trigger_detail['source'] ?? null) === 'oil_projection')
                        ? 'oil_projection'
                        : 'test_schedule',
                    'raised_at'      => optional($req->requested_at ?? $req->created_at)->toIso8601String(),
                    'parked_code'    => $req->review_rejection_code,
                    'was_parked_by_this_visit' => Maintenance::isSystemWithdrawal($req->review_rejection_code),
                ] : null,
                'on_release' => 'The count starts again from the day it comes back, and the next morning scan re-checks it.',
            ];
        }

        // Longest in the shop first — that is the one worth chasing.
        usort($rows, fn ($a, $b) => ($b['parked']['days_in_shop'] ?? 0) <=> ($a['parked']['days_in_shop'] ?? 0));

        return ['rows' => $rows, 'outside_fleet' => $outsideFleet];
    }

    /** Absolute floor for the oil sanity ceiling, in km — used when 3× the interval is smaller. */
    private const OIL_ANOMALY_FLOOR_KM = 20000;

    /**
     * THE oil sanity ceiling: a service-due distance beyond max(20,000 km, 3 × interval) is almost
     * certainly a bad odometer reading, not a car that genuinely drove that far past its service.
     *
     * This lives here, once, because more than one surface has to make the same call and they must not
     * disagree: the Proactive Diagnostic Monitor drops the oil condition and files a Data Anomaly
     * instead of raising a nonsense request, and VehicleSuggestedChecksService suppresses the same
     * reading rather than printing "overdue by 852,999 km" to an inspector. A second copy of this
     * threshold would let one surface call a car due while the other calls it broken data.
     *
     * @param  array<string,mixed>  $serviceStatus  the array from Vehicle::serviceStatus()
     */
    public function oilCeilingKm(array $serviceStatus): int
    {
        return max(self::OIL_ANOMALY_FLOOR_KM, 3 * (int) ($serviceStatus['interval'] ?? 0));
    }

    /** True when a car's service-due distance is too large to be a real reading (see oilCeilingKm). */
    public function isOilOverdueImplausible(array $serviceStatus): bool
    {
        if (($serviceStatus['status'] ?? null) !== 'service_due') {
            return false;
        }

        return (int) ($serviceStatus['overdue_km'] ?? 0) > $this->oilCeilingKm($serviceStatus);
    }

    /**
     * Reality-check for a single routine service type (oil_change / battery / tire_rotation /
     * tire_change) against a car's LIVE status — is it actually due, or is someone logging a routine
     * finding the car doesn't need? Backs the "status conflict" warning at the Decide/Garage step and
     * its Data Health audit. Returns null for anything that isn't one of the monitored routines (an
     * ordinary fault always passes through with no reality-check).
     *
     * @return array{key:string,label:string,status:string,tone:string,summary:string}|null
     */
    public function routineStatus(Vehicle $vehicle, string $serviceType): ?array
    {
        return match ($serviceType) {
            'oil_change'                    => $this->oilContext($vehicle),
            'battery'                       => $this->batteryContext($vehicle),
            'tire_rotation', 'tire_change'  => $this->tyresContext($vehicle),
            default                         => null,
        };
    }

    /**
     * The full DIAGNOSTIC CONTEXT for a car — the "why was this flagged" story shown on the ticket
     * detail: how long it has been idle, when it was last checked (with a link to that visit), and the
     * live status of each routine (oil / battery / tyres) with the actual numbers and the limit, so the
     * inspector reads "5,000 km over the 5,000 km limit" / "past the 30-month battery life" at a glance.
     *
     * @return array<string,mixed>
     */
    public function context(Vehicle $vehicle): array
    {
        return [
            'limit_days' => $this->downtimeLimitDays(),
            'idle'       => $this->downtimeInfo($vehicle),
            'last_check' => $this->lastCheck($vehicle),
            'conditions' => array_values(array_filter([
                $this->oilContext($vehicle),
                $this->batteryContext($vehicle),
                $this->tyresContext($vehicle),
            ])),
        ];
    }

    /**
     * Public accessor for the car's "last ready" anchor — the SAME record the Post-Downtime safety check
     * counts its days from (most recent completed maintenance / cleared test / legacy IN close, else the
     * onboarding-purchase fallback when the car has no history at all). Surfaces like the Inspection Review
     * card use this so their "last maintenance" figure never contradicts the system's own flag.
     *
     * @return array{at:?string,days_ago:?int,reason:string,source:string,source_id:?int,odometer:?int}
     */
    public function readyAnchor(Vehicle $vehicle): array
    {
        $a   = $this->lastReadyAnchor($vehicle);
        $at  = $a['at'] ?? null;

        return [
            'at'        => $at ? $at->toIso8601String() : null,
            'days_ago'  => $at ? (int) $at->copy()->startOfDay()->diffInDays(Carbon::now()->startOfDay()) : null,
            'reason'    => $a['reason'] ?? 'onboarding',
            'source'    => $a['source'] ?? 'onboarding',
            'source_id' => $a['source_id'] ?? null,
            'odometer'  => $a['odometer'] ?? null,
        ];
    }

    /** The car's last recorded check — its most recent CLOSED maintenance visit (linkable), else its last oil service. */
    private function lastCheck(Vehicle $vehicle): ?array
    {
        $ticket = Maintenance::where('vehicle_id', $vehicle->id)
            ->where('workflow_status', Maintenance::WF_CLOSED)
            ->orderByDesc('wf_closed_at')
            ->orderByDesc('id')
            ->first(['id', 'wf_closed_at', 'actual_in_date']);

        if ($ticket) {
            $date = $ticket->wf_closed_at ?? $ticket->actual_in_date;
            return [
                'date'      => optional($date)->toDateString(),
                'days_ago'  => $date ? (int) Carbon::parse($date)->startOfDay()->diffInDays(Carbon::now()->startOfDay()) : null,
                'label'     => 'Last maintenance visit',
                'ticket_id' => $ticket->id,
                'link'      => '/maintenance-workflow/' . $ticket->id,
            ];
        }

        $oil = $vehicle->serviceReminders()->where('service_type', 'oil_change')->first();
        if ($oil && $oil->last_service_at) {
            $date = Carbon::parse($oil->last_service_at);
            return [
                'date'      => $date->toDateString(),
                'days_ago'  => (int) $date->copy()->startOfDay()->diffInDays(Carbon::now()->startOfDay()),
                'label'     => 'Last oil service',
                'ticket_id' => null,
                'link'      => null,
            ];
        }

        return null;
    }

    /** Live OIL status with the numbers behind the verdict (overdue vs. km left of the interval). */
    private function oilContext(Vehicle $vehicle): array
    {
        $s        = $vehicle->serviceStatus();
        $reminder = $vehicle->serviceReminders()->where('service_type', 'oil_change')->first();
        $lastAt   = $reminder && $reminder->last_service_at ? Carbon::parse($reminder->last_service_at)->toDateString() : null;

        $base = [
            'key'             => 'oil',
            'label'           => 'Oil / Service',
            'current_km'      => $s['current'],
            'last_service_km' => $s['baseline'],
            'interval_km'     => $s['interval'],
            'remaining_km'    => $s['remaining'],
            'last_service_at' => $lastAt,
        ];

        if (($s['status'] ?? null) === 'no_data') {
            return $base + ['status' => 'no_data', 'tone' => 'gray', 'summary' => 'No service data on file'];
        }

        if ($s['status'] === 'service_due') {
            return $base + [
                'status'  => 'overdue',
                'tone'    => 'red',
                'summary' => number_format((int) $s['overdue_km']) . ' km over the ' . number_format((int) $s['interval']) . ' km limit',
            ];
        }

        return $base + [
            'status'  => 'ok',
            'tone'    => 'green',
            'summary' => number_format(max(0, (int) $s['remaining'])) . ' km left of the ' . number_format((int) $s['interval']) . ' km limit',
        ];
    }

    /** Live BATTERY status from the last-change date (age vs. the ~30-month service life). */
    private function batteryContext(Vehicle $vehicle): array
    {
        if (! $vehicle->battery_last_changed) {
            return ['key' => 'battery', 'label' => 'Battery', 'status' => 'no_data', 'tone' => 'gray', 'summary' => 'No battery-change date on file', 'last_changed' => null, 'months' => null];
        }

        $months = (int) Carbon::parse($vehicle->battery_last_changed)->diffInMonths(Carbon::now());
        $due    = $months >= self::BATTERY_LIFE_MONTHS;

        return [
            'key'          => 'battery',
            'label'        => 'Battery',
            'status'       => $due ? 'overdue' : 'ok',
            'tone'         => $due ? 'red' : 'green',
            'summary'      => $months . ' months old' . ($due
                ? ' — past the ' . self::BATTERY_LIFE_MONTHS . '-month life, check the charge'
                : ' (life ~' . self::BATTERY_LIFE_MONTHS . ' months)'),
            'last_changed' => Carbon::parse($vehicle->battery_last_changed)->toDateString(),
            'months'       => $months,
        ];
    }

    /** Live TYRES status from the tyre-rotation / tyre-change reminders (worst wins); null when none exist. */
    private function tyresContext(Vehicle $vehicle): ?array
    {
        $reminders = $vehicle->serviceReminders()
            ->whereIn('service_type', ['tire_rotation', 'tire_change'])
            ->where('active', true)
            ->get();

        if ($reminders->isEmpty()) {
            return null;
        }

        $worst = 'ok';
        $detail = 'Tyre service OK';
        foreach ($reminders as $r) {
            $r->setRelation('vehicle', $vehicle);
            $status = $r->statusInfo()['status'];
            if ($status === 'overdue') {
                $worst = 'overdue';
                $detail = $r->displayName() . ' overdue';
                break;
            }
            if ($status === 'due_soon' && $worst !== 'overdue') {
                $worst = 'due_soon';
                $detail = $r->displayName() . ' due soon';
            }
        }

        return [
            'key'     => 'tyres',
            'label'   => 'Tyres',
            'status'  => $worst === 'overdue' ? 'overdue' : ($worst === 'due_soon' ? 'due' : 'ok'),
            'tone'    => $worst === 'overdue' ? 'red' : ($worst === 'due_soon' ? 'amber' : 'green'),
            'summary' => $detail,
        ];
    }

    // ── Due-check derivation ───────────────────────────────────────────────────────────────────────

    /**
     * The routine + downtime checks that are DUE for this car right now (before applying acks).
     *
     * @return array<int,array<string,mixed>>
     */
    private function dueChecks(Vehicle $vehicle, array $downtime): array
    {
        $checks    = [];
        $reminders = $vehicle->serviceReminders()->where('active', true)->get();

        // 1) OIL — the canonical km verdict (Vehicle::serviceStatus) plus the oil_change reminder's
        //    calendar axis, so a car overdue by EITHER distance or time trips the gate.
        $svc         = $vehicle->serviceStatus();
        $kmDue       = ($svc['status'] ?? null) === 'service_due';
        $oilReminder = $reminders->firstWhere('service_type', 'oil_change');
        $oilDaysOver = null;
        if ($oilReminder) {
            $oilReminder->setRelation('vehicle', $vehicle);
            $info = $oilReminder->statusInfo();
            if (($info['days_remaining'] ?? null) !== null && $info['days_remaining'] < 0) {
                $oilDaysOver = abs((int) $info['days_remaining']);
            }
        }

        if ($kmDue || $oilDaysOver !== null) {
            $parts = [];
            if ($kmDue) {
                $parts[] = number_format((int) ($svc['overdue_km'] ?? 0)) . ' km over';
            }
            if ($oilDaysOver !== null) {
                $parts[] = $oilDaysOver . ' day' . ($oilDaysOver === 1 ? '' : 's') . ' over';
            }
            $checks[] = [
                'key'             => 'oil_change',
                'directive'       => self::DIRECTIVE_ROUTINE,
                'label'           => 'Oil Change',
                'severity'        => 'moderate',
                'detail'          => 'Oil change due (' . implode(', ', $parts) . ').',
                'axis'            => $kmDue ? 'km' : 'date',
                // Cycle rolls forward once the car is serviced (last_service_odometer moves) or the
                // reminder's next date advances.
                'cycle_key'       => 'oil|' . ($vehicle->last_service_odometer ?? '') . '|' . ($vehicle->service_interval_km ?? '')
                    . '|' . (optional($oilReminder?->next_due_at)->toDateString() ?? ''),
                'resolvable'      => true, // can be logged (serviced) inline from the gate
                'fix_hint'        => 'Log the oil change now, or mark checked / defer with a reason.',
                // The exact Findings-catalog keyword this condition maps to — the Decide step's "ready
                // entry point" chip. Matches the catalog verbatim so it's a known keyword, not a custom tag.
                'finding_keyword' => 'Oil Change',
            ];
        }

        // 2) OTHER active reminders overdue (tyres / brakes / filters / a manual battery reminder …).
        foreach ($reminders as $r) {
            if ($r->service_type === 'oil_change') {
                continue; // owned by the oil check above
            }
            $r->setRelation('vehicle', $vehicle);
            $info = $r->statusInfo();
            if (($info['status'] ?? null) !== 'overdue') {
                continue;
            }
            $isTire = in_array($r->service_type, ['tire_rotation', 'tire_change'], true);
            $checks[] = [
                'key'             => 'reminder:' . $r->id,
                'directive'       => self::DIRECTIVE_ROUTINE,
                'label'           => $r->displayName(),
                'severity'        => $isTire ? 'moderate' : 'routine',
                'detail'          => $this->reminderDetail($info),
                'axis'            => (($info['km_remaining'] ?? null) !== null && $info['km_remaining'] < 0) ? 'km' : 'date',
                'cycle_key'       => 'reminder:' . $r->id . '|' . ($r->next_due_odometer ?? '') . '|' . (optional($r->next_due_at)->toDateString() ?? ''),
                'resolvable'      => false,
                'fix_hint'        => 'Mark checked once inspected, or defer with a reason.',
                // The reminder's own display name doubles as the ready-entry-point suggestion — an exact
                // catalog match for Tire Rotation/Change, or a plain custom tag for any other reminder type.
                'finding_keyword' => $r->displayName(),
            ];
        }

        // 3) BATTERY age fallback — only when no explicit battery reminder already covers it.
        $hasBatteryReminder = $reminders->contains(fn ($r) => $r->service_type === 'battery');
        if (! $hasBatteryReminder && $vehicle->battery_last_changed) {
            $months = (int) Carbon::parse($vehicle->battery_last_changed)->diffInMonths(Carbon::now());
            if ($months >= self::BATTERY_LIFE_MONTHS) {
                $checks[] = [
                    'key'             => 'battery',
                    'directive'       => self::DIRECTIVE_ROUTINE,
                    'label'           => 'Battery Status',
                    'severity'        => 'routine',
                    'detail'          => "Battery {$months} months old (past " . self::BATTERY_LIFE_MONTHS . '-month life — check the charge).',
                    'axis'            => 'date',
                    'cycle_key'       => 'battery_age|' . Carbon::parse($vehicle->battery_last_changed)->toDateString(),
                    'resolvable'      => false,
                    'fix_hint'        => 'Check the battery, then mark checked or defer with a reason.',
                    // Maps to the catalog's "Battery Replacement" (not the label above) — that's the
                    // keyword that actually rolls the battery service reminder forward when resolved.
                    'finding_keyword' => 'Battery Replacement',
                ];
            }
        }

        // 4) POST-DOWNTIME safety check — too long since the car's last maintenance completion (its ready
        //    anchor). Carries the days-since count (NOT idle time — the clock runs even while the car is
        //    out with a customer) and the safety checklist, so the monitor can spell out the "why" + "what"
        //    on the ticket agenda ("25 days since last maintenance completion — please check: Battery, Fluids, Brakes.").
        if ($downtime['exceeded']) {
            $checks[] = [
                'key'             => 'downtime',
                'directive'       => self::DIRECTIVE_DOWNTIME,
                'label'           => 'Post-Downtime Safety Check',
                'severity'        => 'moderate',
                'detail'          => $downtime['days'] . ' days since last maintenance completion (limit ' . $downtime['limit'] . '). Run a safety check before it goes back out.',
                'days'            => $downtime['days'],
                'checklist'       => self::POST_DOWNTIME_CHECKLIST,
                'axis'            => 'date',
                'cycle_key'       => 'downtime|' . $downtime['idle_since'],
                'resolvable'      => false,
                'fix_hint'        => 'Complete the safety check, then mark checked or defer with a reason.',
                // One ready-entry-point keyword per checklist item, so each safety item the agenda names
                // ("go check: Battery, Fluids, and Brakes") is a one-tap chip at the Decide step.
                'finding_keywords' => array_values(array_filter(array_map(
                    fn ($item) => self::CHECKLIST_FINDING_MAP[$item] ?? null,
                    self::POST_DOWNTIME_CHECKLIST
                ))),
            ];
        }

        // 5) INACTIVITY CHECK — the car has NOT been rented at all since its last test, yet the inactivity
        //    grace window has now lapsed. This is the deliberate COUNTERPART of the post-downtime rule:
        //    that rule only becomes due once the car has gone back into service (≥ 1 rental since the last
        //    test — hasReturnedToServiceSince), so a car parked-and-forgotten since its last PASS is never
        //    asked to re-inspect there. This rule keeps such an inactive car honest — once `inactive_days`
        //    calendar days pass with no rental since the last test, we send it for a fresh check-up anyway
        //    so it stays road-ready. The two rules are mutually exclusive on any given car: downtime needs
        //    a rental since the anchor, inactivity needs NONE. Never fires while the car is held (being
        //    actively processed) — the same window in which the downtime clock itself is paused.
        $inactiveLimit = $this->inactivityLimitDays();
        $daysSinceTest = $downtime['days'] ?? null;
        $lastTestDate  = $downtime['idle_since'] ?? null;
        if (
            ! ($downtime['held'] ?? false)
            && $daysSinceTest !== null
            && $daysSinceTest >= $inactiveLimit
            && $lastTestDate
            && ! $this->hasReturnedToServiceSince($vehicle, Carbon::parse($lastTestDate))
        ) {
            $checks[] = [
                'key'        => 'inactivity',
                'directive'  => self::DIRECTIVE_INACTIVITY,
                'label'      => 'Inactivity Check',
                'severity'   => 'routine',
                'detail'     => 'Vehicle inactive for ' . $daysSinceTest . ' days since last test'
                    . ' (limit ' . $inactiveLimit . ') and not rented since — run a check-up to keep it road-ready.',
                'days'       => $daysSinceTest,
                'axis'       => 'date',
                'cycle_key'  => 'inactivity|' . $lastTestDate,
                'resolvable' => false,
                'fix_hint'   => 'Complete the check, then mark checked or defer with a reason.',
            ];
        }

        return $checks;
    }

    /**
     * Post-Downtime verdict. The clock counts CALENDAR days since the car's last maintenance completion
     * (its "ready" anchor) — a completed test-drive with no work needed (ready immediately), or a
     * ready-after-repair (maintenance closed). A rental NEVER resets it; only a test / repair does. So a
     * car out on hire keeps accruing days (`with_customer` = true) and, once past the limit, still
     * qualifies for a check — the review queue just holds Approve until it's physically back.
     *
     * Service-eligibility gate: exceeding the limit only makes the check DUE when the car has actually
     * been back in service (had ≥ 1 rental since its last inspection — hasReturnedToServiceSince). A car
     * that has sat parked since its last PASS stays available and is never asked to re-inspect; when the
     * limit lapses without a rental yet, `exceeded` is false and `awaiting_service` is true. The timer is
     * still live and unmodified — the very first rental after it lapses makes the check due at once.
     *
     * @return array{eligible:bool,free:bool,with_customer:bool,idle_since:?string,days:?int,limit:int,exceeded:bool,awaiting_service?:bool,held:bool,anchor_reason:?string,anchor_ticket_id:?int,anchor_source:?string,anchor_odometer:?int}
     */
    private function downtimeInfo(Vehicle $vehicle, ?array $prefetchedLog = null): array
    {
        $limit   = $this->downtimeLimitDays();
        $inFleet = ! in_array($vehicle->status, self::LEFT_FLEET, true);

        if (! $inFleet) {
            return [
                'eligible' => false, 'free' => false, 'with_customer' => false,
                'idle_since' => null, 'days' => null, 'limit' => $limit, 'exceeded' => false,
                'held' => false, 'anchor_reason' => null, 'anchor_ticket_id' => null,
                'anchor_source' => null, 'anchor_odometer' => null,
            ];
        }

        // HELD while actively processed. If the car is CURRENTLY moving through an inspection/maintenance
        // workflow whose processing has actually begun — its test drive started ("Being Inspected") or a
        // committed repair is under way — the downtime clock is PAUSED. The car is already in hand, so the
        // old countdown must not run out mid-flight and trigger a second, redundant "Needs Test Drive". The
        // baseline moves forward on its own the moment that workflow COMPLETES: its closed / diagnostic-
        // cleared record becomes the new lastReadyAnchor (a later timestamp), giving a fresh full window.
        // A mere request that hasn't started yet (pending_review / inspection_requested) does NOT hold —
        // nothing is being processed there, so the ordinary clock keeps running. (Rules 2 & 3.)
        $active = $this->activeWorkflowAnchor($vehicle);
        if ($active !== null) {
            $since = $active['at'];
            $days  = $since ? (int) $since->diffInDays(Carbon::now()->startOfDay()) : 0;

            return [
                'eligible'         => true,
                'free'             => false,   // it's on the test drive / in the shop — not free to inspect anew
                'with_customer'    => $vehicle->operational_status === 'rented',
                'idle_since'       => optional($since)->toDateString(),
                'days'             => $days,   // days SINCE processing began (informational — not "idle")
                'limit'            => $limit,
                'exceeded'         => false,   // held: cannot expire while the car is being actively processed
                'held'             => true,
                'anchor_reason'    => 'in_progress',
                'anchor_ticket_id' => $active['source_id'],
                'anchor_source'    => 'workflow_active',
                'anchor_odometer'  => null,
            ];
        }

        // HELD while the car is physically in a shop. Same rule as above, for the stay that leaves no
        // ticket behind: an open OM type-'U' contract, or an open garage-log trip. The car is on a lift,
        // so the countdown must not run out underneath it and declare a parked car overdue — and it
        // restarts on its own the moment the stay ends, because the return date becomes the newest
        // lastReadyAnchor. Parked days therefore never count as time the car was out being used.
        $stay = $this->shopStay($vehicle, $prefetchedLog);
        if ($stay !== null) {
            return [
                'eligible'         => true,
                'free'             => false,   // it is at the garage — not free to inspect
                'with_customer'    => false,   // in the shop is not with a renter, whatever the rental row says
                'idle_since'       => optional($stay['started_at'])->toDateString(),
                'days'             => $stay['days_in_shop'] ?? 0,   // days since it went IN (not "idle")
                'limit'            => $limit,
                'exceeded'         => false,   // held: cannot expire while the car is in the shop
                'held'             => true,
                'anchor_reason'    => $stay['source'] === 'om_contract' ? 'in_maintenance_contract' : 'in_workshop_log',
                'anchor_ticket_id' => $stay['source'] === 'workshop_log' ? $stay['ref_id'] : null,
                'anchor_source'    => $stay['source'],
                'anchor_odometer'  => null,
            ];
        }

        $anchor   = $this->lastReadyAnchor($vehicle);
        $since    = $anchor['at'];
        $reason   = $anchor['reason'];
        $ticketId = $anchor['source_id'];
        $days = $since ? (int) $since->diffInDays(Carbon::now()->startOfDay()) : null;
        $busy = in_array($vehicle->operational_status, self::BUSY_OPERATIONAL, true);

        // The 15-day timer itself is UNCHANGED — it still counts calendar days from the last inspection
        // and is never reset here. We only add a final eligibility gate before the reminder becomes due:
        // the car must have actually gone back into service (≥ 1 rental contract) since that last PASS.
        // A car that has sat parked since its last inspection is still in the same inspected state, so
        // the limit may lapse without ever asking for another inspection; the moment a rental puts it
        // back on the road the ordinary 15-day behaviour resumes untouched. (The query runs only once
        // the timer has actually lapsed, so parked cars under the limit cost nothing.)
        $timerLapsed = $days !== null && $days >= $limit;
        $exceeded    = $timerLapsed && $this->hasReturnedToServiceSince($vehicle, $since);

        return [
            // In active fleet → the clock is meaningful. Unlike before, a busy (rented / in-shop) car
            // still accrues days — the clock is test-based, not free-time-based.
            'eligible'         => true,
            'free'             => ! $busy,                                   // physically available to inspect right now
            'with_customer'    => $vehicle->operational_status === 'rented', // out on hire → can't be inspected yet
            'idle_since'       => optional($since)->toDateString(),
            'days'             => $days,
            'limit'            => $limit,
            'exceeded'         => $exceeded,
            // Timer lapsed but withheld because the car hasn't been rented since its last inspection —
            // it stays available and no reminder is raised until it next goes into service.
            'awaiting_service' => $timerLapsed && ! $exceeded,
            'held'             => false,     // clock is live (no active workflow holding it)
            'anchor_reason'    => $reason,   // test | maintenance | onboarding
            'anchor_ticket_id' => $ticketId,
            'anchor_source'    => $anchor['source'],   // workflow | legacy | onboarding
            'anchor_odometer'  => $anchor['odometer'], // the winning record's odometer, if it carried one
        ];
    }

    /**
     * Has the car actually gone back into service since the given inspection anchor? True when it has
     * had at least one real RENTAL (contract_type 'C' — not a 'U' maintenance stint nor an 'R' future
     * booking) that put it on the road on/after the anchor. A rental counts if it STARTED on/after the
     * anchor, or was still out (or returned) on/after it — covering a rental that spanned the inspection.
     * Future-dated reservations (out_date after today) are excluded: a car merely reserved has not yet
     * been in service. Used purely to gate the post-downtime reminder; the timer itself is untouched.
     */
    private function hasReturnedToServiceSince(Vehicle $vehicle, ?Carbon $anchor): bool
    {
        if ($anchor === null) {
            return false;
        }

        $anchorDate = $anchor->toDateString();
        $today      = Carbon::now()->toDateString();

        return Contract::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('contract_type', 'C')                 // an actual rental, not maintenance ('U') / booking ('R')
            ->whereNotNull('out_date')
            ->whereDate('out_date', '<=', $today)         // rental has actually commenced (exclude future bookings)
            ->where(function ($q) use ($anchorDate) {
                $q->whereDate('out_date', '>=', $anchorDate)   // started on/after the last inspection
                    ->orWhereNull('in_date')                   // … or still out on hire right now
                    ->orWhereDate('in_date', '>=', $anchorDate); // … or returned on/after the last inspection
            })
            ->exists();
    }

    /**
     * The car's last real inspection event (its "ready" anchor) — what the downtime clock counts from,
     * and the single record that answers "Last Inspection = the most recent real inspection event",
     * whether it came from the new workflow or from legacy data.
     *
     * Selection is by TIMESTAMP, not by source. We resolve the newest completed event from EACH source,
     * then let the later one win outright:
     *
     *   • NEW workflow — the most recent terminal "came out ready" event: a maintenance visit that
     *     CLOSED (ready after repair → wf_closed_at/ready_at) or a diagnostic that CLEARED with no work
     *     needed (ready straight after the test → inspected_at).
     *   • LEGACY timeline — a pre-workflow maintenance that CLOSED (rows with no workflow_status, only
     *     the old TEST / IN / OUT / FOLLOWUP stages): the car came back "IN" (actual_in_date set), whose
     *     return date is the legacy equivalent of "ready after maintenance".
     *   • OM CONTRACT — a type-'U' OfficeManager maintenance contract that CLOSED: its `in_date` is the
     *     day the car physically came back from the garage. A workshop stint booked only as a contract
     *     leaves no maintenances row at all, so without this the clock would keep counting straight
     *     through a three-week visit as if the car had never moved.
     *
     * A new-workflow record is preferred ONLY when it is genuinely the latest completed event; a NEWER
     * legacy IN/OUT/FOLLOWUP close or a NEWER contract return overrides it. Fields are never merged across records — the winning
     * record supplies the WHOLE anchor (result, timestamp, odometer, source). If neither source has a
     * record we fall back to the onboarding/purchase date, so a car with no history still trips the limit.
     *
     * @return array{at:?Carbon,reason:string,source:string,source_id:?int,odometer:?int,result:?string}
     */
    private function lastReadyAnchor(Vehicle $vehicle): array
    {
        $candidates = array_filter([
            $this->workflowReadyAnchor($vehicle),
            $this->legacyReadyAnchor($vehicle),
            $this->omContractReadyAnchor($vehicle),
        ]);

        if ($candidates !== []) {
            // Latest valid inspection event by timestamp. On an exact tie (same day) the new-workflow
            // record — listed first above — is kept, so "consider workflow first when it is the latest".
            usort($candidates, fn ($a, $b) => $b['at'] <=> $a['at']);

            return $candidates[0];
        }

        // Onboarding / purchase fallback — no inspection of either kind on record.
        $onboard = $vehicle->purchase_date ?? $vehicle->created_at;

        return [
            'at'        => $onboard ? Carbon::parse($onboard)->startOfDay() : null,
            'reason'    => 'onboarding',
            'source'    => 'onboarding',
            'source_id' => null,
            'odometer'  => null,
            'result'    => null,
        ];
    }

    /**
     * The car's CURRENTLY ACTIVE inspection/maintenance workflow, if any — an OPEN (non-terminal) ticket
     * whose processing has genuinely begun: the test drive has started (`test_started_at` set, the "Being
     * Inspected" moment) or it is a committed repair ticket (WF_TICKET_STATES). This is exactly the window
     * in which the car is "already being handled", so the downtime clock is held (see downtimeInfo).
     *
     * A pre-ticket request that has NOT started — pending_review / inspection_requested, born from a Driver
     * or system "please inspect" with no test drive yet — is deliberately EXCLUDED (Rule 1: request created
     * only ⇒ no hold, the ordinary clock keeps running until the test actually starts).
     *
     * @return array{at:?Carbon,source_id:int}|null
     */
    private function activeWorkflowAnchor(Vehicle $vehicle): ?array
    {
        $ticket = Maintenance::query()
            ->where('vehicle_id', $vehicle->id)
            ->openWorkflow() // non-terminal live rows only
            ->where(function ($q) {
                // Started being inspected (test drive underway) …
                $q->whereNotNull('test_started_at')
                    // … or already a committed repair ticket (e.g. a breakdown that skipped the test drive).
                    ->orWhereIn('workflow_status', Maintenance::WF_TICKET_STATES);
            })
            ->orderByDesc('id')
            ->first(['id', 'test_started_at', 'last_state_change_at', 'created_at']);

        if (! $ticket) {
            return null;
        }

        $at = $ticket->test_started_at ?? $ticket->last_state_change_at ?? $ticket->created_at;

        return [
            'at'        => $at ? Carbon::parse($at)->startOfDay() : null,
            'source_id' => (int) $ticket->id,
        ];
    }

    /** Lookback for "the car is still in the shop per the garage log" — mirrors FleetUtilizationService::activeShopStays(). */
    private const WORKSHOP_LOG_LOOKBACK_DAYS = 60;

    /**
     * Does the garage log get to park a car, or is the OfficeManager contract the only fact that may?
     * OFF by owner's decision — see config/features.php for why, and for what turning it on restores.
     */
    public function workshopLogParks(): bool
    {
        return (bool) config('features.diagnostic_gate.workshop_log_parks', false);
    }

    /**
     * IS THIS CAR AT A GARAGE RIGHT NOW — the single answer, used by every surface that asks.
     *
     * THE OFFICEMANAGER MAINTENANCE CONTRACT (type 'U') IS THE FACT. A car is parked when OM has an
     * open maintenance contract on it, and not otherwise. The garage log can act as a second source
     * but is switched OFF (`workshopLogParks()`): it was a stand-in from before every workshop trip
     * opened a contract, and it is not reliable enough to freeze a car's test clock on.
     *
     * Returns the whole stay, not just a flag, so a card can say WHICH visit parked the car and since
     * when — no bare claims. Null when the car is out of the shop.
     *
     * @return array{source:string,ref_id:int,label:?string,contract_no:?string,garage:?string,work:?string,started_at:?Carbon,days_in_shop:?int,customer:?string}|null
     */
    public function shopStay(Vehicle $vehicle, ?array $prefetchedLog = null): ?array
    {
        $contract = $this->openMaintenanceContract($vehicle);
        if ($contract) {
            $since = $contract->out_date ? Carbon::parse($contract->out_date)->startOfDay() : null;

            return [
                'source'       => 'om_contract',
                'ref_id'       => (int) $contract->id,
                'label'        => $contract->contract_no ? '#' . $contract->contract_no : '#' . $contract->id,
                'contract_no'  => $contract->contract_no,
                'garage'       => null,
                'work'         => null,
                'started_at'   => $since,
                'days_in_shop' => $since ? (int) $since->diffInDays(Carbon::now()->startOfDay()) : null,
                'customer'     => $contract->relationLoaded('customer') ? $contract->customer?->name_en : null,
            ];
        }

        if (! $this->workshopLogParks()) {
            return null; // no OM contract ⇒ not parked. The log does not get a vote.
        }

        $event = $prefetchedLog !== null
            ? ($prefetchedLog[$vehicle->id] ?? null)
            : ($this->openWorkshopLogEvents([$vehicle->id])[$vehicle->id] ?? null);

        if (! $event) {
            return null;
        }

        $since = $event->out_date ? Carbon::parse($event->out_date)->startOfDay() : null;

        return [
            'source'       => 'workshop_log',
            'ref_id'       => (int) $event->id,
            'label'        => trim((string) $event->garage) ?: null,
            'contract_no'  => null,
            'garage'       => trim((string) $event->garage) ?: null,
            'work'         => FleetUtilizationService::workLabel($event),
            'started_at'   => $since,
            'days_in_shop' => $since ? (int) $since->diffInDays(Carbon::now()->startOfDay()) : null,
            'customer'     => null,
        ];
    }

    /**
     * Each car's currently-open workshop-log event, keyed by vehicle_id — only cars whose LATEST
     * sheet/manual event says the car is at a garage right now. Cars whose latest event came back
     * (stage 'IN' or a past return date) or whose visit is older than the lookback are absent.
     *
     * LIVE VIEW — the Maintenance model's SoftDeletes scope applies, so a deleted event releases the car.
     *
     * @param  int[]|null  $vehicleIds  limit to these vehicles (null = whole fleet)
     * @return array<int,Maintenance>
     */
    public function openWorkshopLogEvents(?array $vehicleIds): array
    {
        // The switch lives here as well as in shopStay(), so the REQUEST SWEEPS that read this
        // (withdrawRequestsForWorkshopLog, the monitor's refusal set) go quiet with it. Otherwise the
        // log would still be parking requests for cars this page reports as counting down — the exact
        // two-answers-to-one-question split this service exists to prevent.
        if (! $this->workshopLogParks()) {
            return [];
        }

        if ($vehicleIds === []) {
            return [];
        }

        $today = Carbon::today();
        $floor = $today->copy()->subDays(self::WORKSHOP_LOG_LOOKBACK_DAYS);

        $latest = [];
        // Ascending order → the last write per vehicle is its latest event ("latest event wins", the
        // same rule the board and FleetUtilizationService use).
        foreach (Maintenance::query()
            ->whereIn('origin', Maintenance::WORKSHOP_LOG_ORIGINS)
            ->whereNotNull('vehicle_id')
            ->when($vehicleIds !== null, fn ($q) => $q->whereIn('vehicle_id', $vehicleIds))
            ->whereNotNull('out_date')
            ->whereDate('out_date', '<=', $today->toDateString())
            ->orderBy('out_date')->orderBy('id')
            ->get(['id', 'vehicle_id', 'origin', 'out_date', 'actual_in_date', 'event_status', 'garage', 'service_main', 'service_sup', 'maintenance_type']) as $e) {
            $latest[(int) $e->vehicle_id] = $e;
        }

        $open = [];
        foreach ($latest as $vid => $e) {
            $stillOut = $e->actual_in_date === null || $e->actual_in_date->gte($today);
            $recent   = $e->out_date->gte($floor);
            if ($e->event_status !== 'IN' && $stillOut && $recent) {
                $open[$vid] = $e;
            }
        }

        return $open;
    }

    /**
     * The car's last REAL test drive — a diagnostic that was actually driven (`inspected_at`), which is
     * a different and much rarer fact than "the car last came out of a workshop". Only 24 tickets in the
     * whole history carry one, so most cars honestly have no test on record and the countdown runs from
     * their workshop return instead (see lastReadyAnchor). Kept separate precisely so the UI can say
     * which of the two it is showing rather than passing one off as the other.
     *
     * @return array{at:string,days_ago:int,ticket_id:int,result:string}|null
     */
    public function lastTest(Vehicle $vehicle): ?array
    {
        $t = Maintenance::query()
            ->where('vehicle_id', $vehicle->id)
            ->whereNotNull('inspected_at')
            ->orderByDesc('inspected_at')
            ->first(['id', 'inspected_at', 'workflow_status']);

        if (! $t || ! $t->inspected_at) {
            return null;
        }

        $at = Carbon::parse($t->inspected_at);

        return [
            'at'        => $at->toIso8601String(),
            'days_ago'  => (int) $at->copy()->startOfDay()->diffInDays(Carbon::now()->startOfDay()),
            'ticket_id' => (int) $t->id,
            'result'    => (string) $t->workflow_status,
        ];
    }

    /**
     * The car's currently OPEN OfficeManager maintenance contract (type 'U'), if any — the car is at a
     * garage right now under a contract, whether or not anyone opened a ticket for the visit. Newest
     * first, so a car with overlapping rows reports the one it actually went in on last.
     */
    private function openMaintenanceContract(Vehicle $vehicle): ?Contract
    {
        return Contract::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('contract_type', 'U')
            ->currentlyOpen()
            ->orderByDesc('out_date')
            ->orderByDesc('id')
            ->first(['id', 'contract_no', 'out_date']);
    }

    /**
     * The newest COMPLETED new-workflow inspection event for the car — a closed maintenance (ready after
     * repair) or a cleared diagnostic (ready straight after the test) — as a complete anchor, or null
     * when the car has none.
     *
     * @return array{at:Carbon,reason:string,source:string,source_id:int,odometer:?int,result:string}|null
     */
    private function workflowReadyAnchor(Vehicle $vehicle): ?array
    {
        $tickets = Maintenance::query()
            ->where('vehicle_id', $vehicle->id)
            ->whereIn('workflow_status', [Maintenance::WF_CLOSED, Maintenance::WF_DIAGNOSTIC_CLEARED])
            ->get([
                'id', 'workflow_status', 'inspected_at', 'ready_at', 'wf_closed_at', 'last_state_change_at',
                'reinspect_odometer', 'return_odometer', 'report_odometer', 'test_odometer',
            ]);

        $bestAt = null;
        $best   = null;
        foreach ($tickets as $t) {
            if ($t->workflow_status === Maintenance::WF_CLOSED) {
                $at       = $t->wf_closed_at ?? $t->ready_at ?? $t->last_state_change_at;
                $reason   = 'maintenance';
                $odometer = $t->reinspect_odometer ?? $t->return_odometer ?? $t->report_odometer ?? $t->test_odometer;
            } else { // diagnostic_cleared — the test itself is the "ready" moment
                $at       = $t->inspected_at ?? $t->last_state_change_at;
                $reason   = 'test';
                $odometer = $t->report_odometer ?? $t->test_odometer;
            }
            if (! $at) {
                continue;
            }
            if (! $bestAt || $at->greaterThan($bestAt)) {
                $bestAt = $at;
                $best   = [
                    'at'        => $at->copy()->startOfDay(),
                    'reason'    => $reason,
                    'source'    => 'workflow',
                    'source_id' => $t->id,
                    'odometer'  => $odometer !== null ? (int) $odometer : null,
                    'result'    => $t->workflow_status,
                ];
            }
        }

        return $best;
    }

    /**
     * The newest COMPLETED legacy inspection event for the car — a pre-workflow maintenance that closed
     * ("IN", actual_in_date set) on the old TEST / IN / OUT / FOLLOWUP timeline — as a complete anchor,
     * or null when the car has none. Legacy rows carry no captured odometer, so that field stays null.
     *
     * @return array{at:Carbon,reason:string,source:string,source_id:int,odometer:?int,result:string}|null
     */
    private function legacyReadyAnchor(Vehicle $vehicle): ?array
    {
        $legacy = Maintenance::query()
            ->where('vehicle_id', $vehicle->id)
            ->whereNull('workflow_status')      // legacy / non-workflow row
            ->whereNotNull('actual_in_date')    // it concluded — the car returned "IN"
            ->orderByDesc('actual_in_date')
            ->first(['id', 'actual_in_date', 'event_status']);

        if (! $legacy || ! $legacy->actual_in_date) {
            return null;
        }

        return [
            'at'        => $legacy->actual_in_date->copy()->startOfDay(),
            'reason'    => 'maintenance',
            'source'    => 'legacy',
            'source_id' => $legacy->id,
            'odometer'  => null,
            'result'    => $legacy->event_status ?: 'IN',
        ];
    }

    /**
     * The newest CLOSED OfficeManager maintenance contract (type 'U') for the car — its `in_date` is the
     * day the car came back from the garage, which is the same "ready after maintenance" moment the other
     * two sources record, just booked in OfficeManager instead of on a ticket or the sheet.
     *
     * `in_date` is the source of truth for "returned" (see Contract::scopeCurrentlyOpen) — a row whose
     * `state` was never flipped to 'closed' still counts as returned once it carries one. Contracts hold
     * no odometer of their own, so that field stays null.
     *
     * @return array{at:Carbon,reason:string,source:string,source_id:int,odometer:?int,result:string}|null
     */
    private function omContractReadyAnchor(Vehicle $vehicle): ?array
    {
        $contract = Contract::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('contract_type', 'U')
            ->whereNotNull('in_date')
            ->orderByDesc('in_date')
            ->orderByDesc('id')
            ->first(['id', 'contract_no', 'in_date']);

        if (! $contract || ! $contract->in_date) {
            return null;
        }

        return [
            'at'        => Carbon::parse($contract->in_date)->startOfDay(),
            'reason'    => 'maintenance',
            'source'    => 'om_contract',
            'source_id' => (int) $contract->id,
            'odometer'  => null,
            'result'    => 'returned',
        ];
    }

    // ── Helpers ────────────────────────────────────────────────────────────────────────────────────

    private function reminderDetail(array $info): string
    {
        if (($info['km_remaining'] ?? null) !== null && $info['km_remaining'] < 0) {
            return 'Overdue by ' . number_format(abs((int) $info['km_remaining'])) . ' km.';
        }
        if (($info['days_remaining'] ?? null) !== null && $info['days_remaining'] < 0) {
            $d = abs((int) $info['days_remaining']);
            return 'Overdue by ' . $d . ' day' . ($d === 1 ? '' : 's') . '.';
        }
        return 'Overdue.';
    }

}
