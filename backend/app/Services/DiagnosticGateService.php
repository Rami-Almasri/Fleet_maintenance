<?php

namespace App\Services;

use App\Models\Maintenance;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
     * exists. Same shape as the `idle` block of context(): { eligible, idle_since, days, limit, exceeded }.
     *
     * @return array{eligible:bool,idle_since:?string,days:?int,limit:int,exceeded:bool}
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

        // 4) POST-DOWNTIME safety check — the car has been sitting too long. Carries the idle-days count
        //    and the specific safety checklist so the monitor can spell out the "why" + "what" on the
        //    ticket agenda ("Vehicle idle for 25 days — go check: Battery, Fluids, and Brakes.").
        if ($downtime['exceeded']) {
            $checks[] = [
                'key'             => 'downtime',
                'directive'       => self::DIRECTIVE_DOWNTIME,
                'label'           => 'Post-Downtime Safety Check',
                'severity'        => 'moderate',
                'detail'          => 'Idle ' . $downtime['days'] . ' days (limit ' . $downtime['limit'] . '). Run a safety check before it goes back out.',
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

        return $checks;
    }

    /**
     * Idle-time verdict for the Post-Downtime check. Only a car that is actually free (in-fleet, not
     * rented / in the shop / in transit) can be "sitting"; its idle clock starts at the return of its
     * last rental, or — for a car never rented — the day it was onboarded.
     *
     * @return array{eligible:bool,idle_since:?string,days:?int,limit:int,exceeded:bool}
     */
    private function downtimeInfo(Vehicle $vehicle): array
    {
        $limit    = $this->downtimeLimitDays();
        $eligible = ! in_array($vehicle->status, self::LEFT_FLEET, true)
            && ! in_array($vehicle->operational_status, self::BUSY_OPERATIONAL, true);

        if (! $eligible) {
            return ['eligible' => false, 'idle_since' => null, 'days' => null, 'limit' => $limit, 'exceeded' => false];
        }

        $since = $this->idleSince($vehicle);
        $days  = $since ? (int) $since->diffInDays(Carbon::now()->startOfDay()) : null;

        return [
            'eligible'   => true,
            'idle_since' => optional($since)->toDateString(),
            'days'       => $days,
            'limit'      => $limit,
            'exceeded'   => $days !== null && $days >= $limit,
        ];
    }

    /** The day the car last became free (last rental's return), else its onboarding date. */
    private function idleSince(Vehicle $vehicle): ?Carbon
    {
        $lastReturn = DB::table('contracts')
            ->where('vehicle_id', $vehicle->id)
            ->where('contract_type', 'C')
            ->whereNotNull('in_date')
            ->max('in_date');

        if ($lastReturn) {
            return Carbon::parse($lastReturn)->startOfDay();
        }

        $anchor = $vehicle->purchase_date ?? $vehicle->created_at;

        return $anchor ? Carbon::parse($anchor)->startOfDay() : null;
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
