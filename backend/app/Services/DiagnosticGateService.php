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
    private function downtimeInfo(Vehicle $vehicle): array
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
     *
     * A new-workflow record is preferred ONLY when it is genuinely the latest completed event; a NEWER
     * legacy IN/OUT/FOLLOWUP close overrides it. Fields are never merged across records — the winning
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
