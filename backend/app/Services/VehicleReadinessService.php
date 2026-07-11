<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\InspectionRecord;
use App\Models\Maintenance;
use App\Models\ServiceReminder;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;

/**
 * The Readiness data provider — the single "how fit is this car?" authority. It runs one checklist
 * over a vehicle (pass / warn / fail per item, each tagged with its inspection PILLAR) and is consumed
 * by TWO deliberately different callers with OPPOSITE enforcement postures:
 *
 *   1. SOFT — the internal Maintenance side (VehicleStatusController::setReady + the Readiness Dashboard).
 *      Here the checklist is ADVISORY: a failing item is a warning, never a block. The user always
 *      proceeds; a proceed-past-warnings is recorded to the audit trail (EVENT_READINESS_OVERRIDE).
 *      Use evaluate() for the full advisory checklist.
 *
 *   2. HARD — the customer-facing Rental side (booking / check-out guards, see rentalGuard()). Here a
 *      subset of the SAME signals are strict guards that protect an "Available for rental" status:
 *      a Red grade or an open Damage / Maintenance ticket is a hard block; a Yellow grade is blocked
 *      unless a manager overrides. This is the ONE place a readiness signal stops an action.
 *
 * Keeping both on one service means the soft advisory and the hard guard can never disagree about the
 * facts — they read the same condition grade, open ticket and damage flags. Checks are derived live
 * from tables that already exist (condition grade, the open workflow ticket, registration/insurance
 * expiry, flagged-but-unreviewed damage) plus the two readiness columns (cleaning_status, gps_last_seen_at).
 *
 * SIGN-OFF NUANCE (soft side): "Set to Ready" is itself the act of closing a car's maintenance, so the
 * `active_maintenance` fail is tagged `resolved_by_signoff` and excluded from the sign-off's own advisory
 * summary — it is exactly what the sign-off resolves.
 */
class VehicleReadinessService
{
    // Rental-guard verdicts (HARD side). A block stops the booking/check-out; a manager-override block
    // stops it unless an authorised manager approves; a warn is advisory only.
    public const RENTAL_BLOCK            = 'block';
    public const RENTAL_MANAGER_OVERRIDE = 'manager_override';
    public const RENTAL_WARN             = 'warn';

    // The four inspection pillars + the readiness-checklist categories, used as the tooltip/label.
    public const PILLAR_CHECKOUT = 'Check-Out';
    public const PILLAR_CHECKIN  = 'Check-In';
    public const PILLAR_DAMAGE   = 'Damage Assessment';
    public const PILLAR_GARAGE   = 'Garage Inspection';

    // How soon an expiring document turns from a pass into an amber warning (still deliverable).
    private const EXPIRY_WARN_DAYS = 14;

    /**
     * Whether an EXPIRED registration / insurance is a hard ground (fail) or an advisory (warn).
     *
     * Legally an expired document SHOULD block a handover, but this is deliberately OFF today: the
     * registration/insurance expiry is populated by a periodic external sync (F Insurance / F RTA)
     * and is broadly stale in the current data — treating it as a hard block would false-ground the
     * bulk of the fleet. It stays a loud WARNING until that sync's freshness is confirmed, then this
     * one flag promotes it to a hard block. Expiry is still surfaced on every checklist either way.
     */
    private const EXPIRY_BLOCKS = false;

    // A tracker silent longer than this reads as "not reporting" (soft, never grounds the car).
    private const GPS_STALE_HOURS = 24;

    // Battery life estimate (months) — mirrors MaintenanceForesightService's age-based signal.
    private const BATTERY_LIFE_MONTHS = 30;

    /**
     * Run the whole checklist for one vehicle.
     *
     * @return array{
     *   ready: bool, blocked: bool, gate_ready: bool,
     *   checks: array<int, array{key:string,pillar:string,label:string,status:string,detail:string,resolved_by_signoff:bool}>,
     *   blockers: array<int, array<string,string>>,
     *   gate_blockers: array<int, array<string,string>>,
     *   summary: string
     * }
     */
    public function evaluate(Vehicle $vehicle): array
    {
        $checks = [
            $this->conditionCheck($vehicle),
            $this->maintenanceCheck($vehicle),
            $this->damageCheck($vehicle),
            $this->registrationCheck($vehicle),
            $this->insuranceCheck($vehicle),
            $this->checkInCheck($vehicle),
            $this->cleaningCheck($vehicle),
            $this->serviceCheck($vehicle),
            $this->gpsCheck($vehicle),
        ];

        // A failing check with a short "Pillar — reason" line for the tooltip / 422 payload.
        $fail = fn (array $c) => ['key' => $c['key'], 'pillar' => $c['pillar'], 'label' => $c['label'], 'detail' => $c['detail']];

        $blockers     = array_values(array_map($fail, array_filter($checks, fn ($c) => $c['status'] === 'fail')));
        $gateBlockers = array_values(array_map($fail, array_filter(
            $checks,
            fn ($c) => $c['status'] === 'fail' && ! $c['resolved_by_signoff'],
        )));

        return [
            'ready'         => empty($blockers),      // deliverable right now
            'blocked'       => ! empty($blockers),
            'gate_ready'    => empty($gateBlockers),  // "Set to Ready" would be allowed
            'checks'        => $checks,
            'blockers'      => $blockers,
            'gate_blockers' => $gateBlockers,
            'summary'       => $this->summarise($blockers, $checks),
        ];
    }

    /** Convenience for the soft advisory summary: the fail-reasons a Set-to-Ready sign-off cannot clear. */
    public function gateBlockers(Vehicle $vehicle): array
    {
        return $this->evaluate($vehicle)['gate_blockers'];
    }

    /**
     * The customer-facing RENTAL READINESS CHECKLIST — the interactive 8-point gate a sales agent
     * clears before a rental/booking is confirmed (see ContractForm). It reuses the same live checks
     * as evaluate() but presents EXACTLY the eight business-named points, in order, each carrying a
     * deep-link (`fix_url`) to the page that resolves it and a `manual` flag for the one point the
     * agent sets by hand at handover (Cleaning).
     *
     * A `fail` is a hard blocker (the frontend disables Confirm until it clears). Cleaning is STRICT
     * here: an unentered status is a hard block, forcing the agent to record Clean or Dirty before a
     * handover (see cleaningCheck's $strict). Registration / Insurance / Service / Battery / GPS
     * deliberately `warn` rather than `fail` (stale external-sync data — see EXPIRY_BLOCKS) so they
     * surface loudly without false-grounding the fleet; flip EXPIRY_BLOCKS to promote expiry to a
     * hard block once that sync is trusted.
     *
     * @return array{vehicle_id:int, plate:?string, points:array<int,array<string,mixed>>,
     *   blockers:array<int,array<string,mixed>>, warnings:array<int,array<string,mixed>>,
     *   ready:bool, summary:string}
     */
    public function rentalChecklist(Vehicle $vehicle): array
    {
        $vid = $vehicle->id;
        // Inspection (Body) folds the condition grade and any open damage flags into one verdict.
        $inspection = $this->worst('inspection', self::PILLAR_DAMAGE, 'Inspection (Body)', [
            $this->conditionCheck($vehicle),
            $this->damageCheck($vehicle),
        ]);

        $points = [
            // Active Repairs leads the list — an open workshop ticket / type-U contract is the single
            // most critical block (the car is physically in the shop), so it shows first and grounds hard.
            $this->point($this->maintenanceCheck($vehicle),  'Active Repairs',   '/maintenance'),
            $this->point($this->registrationCheck($vehicle), 'Registration',     '/registrations'),
            $this->point($this->insuranceCheck($vehicle),    'Insurance',        '/registrations'),
            $this->point($this->serviceCheck($vehicle),      'Service Interval', '/reminders/service'),
            $this->point($this->cleaningCheck($vehicle, strict: true), 'Cleaning',   "/vehicles/$vid", manual: true, field: 'cleaning_status'),
            $this->point($inspection,                        'Inspection (Body)', '/inspection-prototype'),
            $this->point($this->tiresCheck($vehicle),        'Tires',            '/reminders/service'),
            $this->point($this->batteryCheck($vehicle),      'Battery',          '/reminders/service'),
            $this->point($this->gpsCheck($vehicle),          'GPS Status',       null),
        ];

        $blockers = array_values(array_filter($points, fn ($p) => $p['status'] === 'fail'));
        $warnings = array_values(array_filter($points, fn ($p) => $p['status'] === 'warn'));

        return [
            'vehicle_id' => $vid,
            'plate'      => $vehicle->plate_no,
            'points'     => $points,
            'blockers'   => $blockers,
            'warnings'   => $warnings,
            'ready'      => empty($blockers),
            'summary'    => empty($blockers)
                ? (empty($warnings) ? 'All ' . count($points) . ' checks passed — ready to rent' : count($warnings) . ' advisory, no blockers')
                : count($blockers) . ' blocking issue' . (count($blockers) === 1 ? '' : 's') . ' — resolve before renting',
        ];
    }

    /**
     * The HARD rental guard — the strict "can this car be rented / checked out right now?" verdict that
     * protects an Available-for-rental status. Unlike evaluate() (advisory), these are enforceable stops:
     *
     *   - Red grade ............... hard block, no exception.
     *   - Open Damage ticket ...... hard block (unreviewed flagged damage).
     *   - Open Maintenance order .. hard block (open workflow ticket or type-U contract).
     *   - Yellow grade ............ blocked UNLESS an authorised manager overrides.
     *
     * Registration/insurance/service/GPS are intentionally NOT hard-blocked here (they stay advisory on
     * the rental side too, pending the data-freshness work) — the booking/check-out controllers surface
     * them as warnings from evaluate(). This method is the sole authority the rental guards call.
     *
     * @return array{
     *   allowed: bool, requires_manager: bool,
     *   blocks: array<int, array{key:string,pillar:string,label:string,detail:string,severity:string}>,
     *   overridable: array<int, array{key:string,pillar:string,label:string,detail:string,severity:string}>
     * }
     */
    public function rentalGuard(Vehicle $vehicle): array
    {
        $grade = $vehicle->condition_grade;
        $blocks      = [];
        $overridable = [];

        $line = fn (array $c, string $severity) => [
            'key' => $c['key'], 'pillar' => $c['pillar'], 'label' => $c['label'],
            'detail' => $c['detail'], 'severity' => $severity,
        ];

        // Condition grade: Red is an absolute block; Yellow is a manager-overridable block.
        if ($grade === 'red') {
            $blocks[] = $line($this->conditionCheck($vehicle), self::RENTAL_BLOCK);
        } elseif ($grade === 'yellow') {
            $overridable[] = $line($this->conditionCheck($vehicle), self::RENTAL_MANAGER_OVERRIDE);
        }

        // Open, unreviewed damage — a hard block.
        $damage = $this->damageCheck($vehicle);
        if ($damage['status'] === 'fail') {
            $blocks[] = $line($damage, self::RENTAL_BLOCK);
        }

        // An open maintenance order (workflow ticket or type-U contract) — a hard block.
        $maint = $this->maintenanceCheck($vehicle);
        if ($maint['status'] === 'fail') {
            $blocks[] = $line($maint, self::RENTAL_BLOCK);
        }

        return [
            'allowed'          => empty($blocks),        // no absolute blocks (manager may still be required)
            'requires_manager' => ! empty($overridable), // Yellow present → needs an override to proceed
            'blocks'           => $blocks,
            'overridable'      => $overridable,
        ];
    }

    // ── Individual checks ────────────────────────────────────────────────────────────────────────

    /** Visual Condition Grade: Red & Yellow are hard grounds (must be re-graded first). */
    private function conditionCheck(Vehicle $v): array
    {
        $blocked = $v->rentBlockedByCondition();               // red | yellow
        $warn    = $v->requiresConditionAcknowledgement();     // orange
        return $this->check(
            'condition', self::PILLAR_DAMAGE, 'Condition grade',
            $blocked ? 'fail' : ($warn ? 'warn' : 'pass'),
            $blocked ? 'Graded ' . ($v->condition_grade === 'red' ? 'Red — grounded' : 'Yellow — needs maintenance')
                     : ($warn ? 'Orange — cosmetic, needs handover acknowledgement' : 'Green — clean'),
            resolvedBySignoff: false,
        );
    }

    /**
     * An open workflow ticket / type-U contract means the car is still in the shop. This is the ONE
     * check a Set-to-Ready resolves (it closes exactly this), so it is tagged resolved_by_signoff.
     */
    private function maintenanceCheck(Vehicle $v): array
    {
        $ticket = Maintenance::openWorkflow()
            ->where('vehicle_id', $v->id)
            ->whereIn('workflow_status', Maintenance::WF_TICKET_STATES)
            ->orderByDesc('id')
            ->first();

        $openU = Contract::where('contract_type', 'U')->currentlyOpen()->where('vehicle_id', $v->id)->exists();
        $open  = $ticket !== null || $openU;

        return $this->check(
            'active_maintenance', self::PILLAR_GARAGE, 'Active maintenance',
            $open ? 'fail' : 'pass',
            $ticket
                ? 'Open workshop ticket #' . $ticket->id . ' (' . str_replace('_', ' ', $ticket->workflow_status) . ')'
                : ($openU ? 'Open maintenance contract' : 'No open maintenance'),
            resolvedBySignoff: true,
        );
    }

    /** Damage Assessment: any flagged-but-unreviewed inspection damage still needs a supervisor. */
    private function damageCheck(Vehicle $v): array
    {
        $count = InspectionRecord::where('vehicle_id', $v->id)
            ->where('damage_flagged', true)
            ->whereNull('review_outcome')
            ->count();

        return $this->check(
            'open_damage', self::PILLAR_DAMAGE, 'Damage review',
            $count > 0 ? 'fail' : 'pass',
            $count > 0 ? $count . ' flagged damage item' . ($count === 1 ? '' : 's') . ' awaiting supervisor review' : 'No open damage flags',
            resolvedBySignoff: false,
        );
    }

    /** Registration (Mulkiya) must be valid at handover. */
    private function registrationCheck(Vehicle $v): array
    {
        $reg  = $this->registration($v);
        $days = $reg?->registration_days_left;
        return $this->expiryCheck('registration', 'Registration (Mulkiya)', self::PILLAR_CHECKOUT, $days);
    }

    /** Insurance must be valid at handover. */
    private function insuranceCheck(Vehicle $v): array
    {
        $reg  = $this->registration($v);
        $days = $reg?->insurance_days_left;
        return $this->expiryCheck('insurance', 'Insurance', self::PILLAR_CHECKOUT, $days);
    }

    /**
     * Check-In: the car's last rental must have a closed return. A car returned (in_date set) but whose
     * contract is still open, or a rental with no post-inspection on record, is an unfinished check-in.
     * We block on the "returned but contract still open" case (a genuine loose end); a missing
     * post-inspection is surfaced as a warning (documentation gap), not a hard ground.
     */
    private function checkInCheck(Vehicle $v): array
    {
        // A type-C contract that has been returned (in_date) but never closed — the check-in is not done.
        $danglingReturn = Contract::where('contract_type', 'C')
            ->where('vehicle_id', $v->id)
            ->where('state', 'open')
            ->whereNotNull('in_date')
            ->exists();

        return $this->check(
            'check_in', self::PILLAR_CHECKIN, 'Check-in closed',
            $danglingReturn ? 'fail' : 'pass',
            $danglingReturn ? 'A returned rental is still open — close the check-in first' : 'Last return is closed',
            resolvedBySignoff: false,
        );
    }

    /**
     * Cleaning: an explicit "dirty" always grounds. An UNENTERED status (null / pending) is a soft
     * WARNING on the advisory side, but a HARD BLOCK on the strict rental gate ($strict = true): the
     * agent must record Clean or Dirty before a handover is confirmed, so a blank entry can no longer
     * hide behind an "unknown" pass. Only "clean" clears it either way.
     */
    private function cleaningCheck(Vehicle $v, bool $strict = false): array
    {
        $status = $v->cleaning_status;
        if ($status === 'clean') {
            return $this->check('cleaning', 'Cleaning', 'Cleaning', 'pass', 'Clean', resolvedBySignoff: false);
        }
        if ($status === 'dirty') {
            return $this->check('cleaning', 'Cleaning', 'Cleaning', 'fail', 'Marked dirty — needs cleaning', resolvedBySignoff: false);
        }
        // Never assessed (null / pending): the strict rental gate blocks; the advisory side warns.
        return $this->check(
            'cleaning', 'Cleaning', 'Cleaning',
            $strict ? 'fail' : 'warn',
            $strict ? 'Pending — set Clean or Dirty before handover' : 'Not yet assessed',
            resolvedBySignoff: false,
        );
    }

    /** Service-due is advisory in this app (odometer-based), so it warns — it never grounds. */
    private function serviceCheck(Vehicle $v): array
    {
        $svc = $v->serviceStatus();
        $due = ($svc['status'] ?? null) === 'service_due';
        return $this->check(
            'service', self::PILLAR_GARAGE, 'Scheduled service',
            $due ? 'warn' : 'pass',
            $due ? 'Service due (' . (int) ($svc['overdue_km'] ?? 0) . ' km over)' : ($svc['label'] ?? 'OK'),
            resolvedBySignoff: false,
        );
    }

    /**
     * GPS: SOFT only. There is no live telematics feed yet, so a null / stale reading warns ("not
     * reporting") but never grounds a car. Ready to harden to a fail the day a feed lands.
     */
    private function gpsCheck(Vehicle $v): array
    {
        $seen = $v->gps_last_seen_at;
        if (! $seen) {
            return $this->check('gps', 'GPS', 'GPS tracker', 'warn', 'Not reporting (no telematics feed)', resolvedBySignoff: false);
        }
        $stale = Carbon::parse($seen)->lt(Carbon::now()->subHours(self::GPS_STALE_HOURS));
        return $this->check(
            'gps', 'GPS', 'GPS tracker',
            $stale ? 'warn' : 'pass',
            $stale ? 'Last seen ' . Carbon::parse($seen)->diffForHumans() : 'Reporting',
            resolvedBySignoff: false,
        );
    }

    /** Tires: driven by the tire_rotation / tire_change service reminders — overdue grounds the rental. */
    private function tiresCheck(Vehicle $v): array
    {
        $reminders = ServiceReminder::where('vehicle_id', $v->id)
            ->whereIn('service_type', ['tire_rotation', 'tire_change'])
            ->where('active', true)
            ->get();

        if ($reminders->isEmpty()) {
            return $this->check('tires', self::PILLAR_GARAGE, 'Tires', 'pass', 'No tire reminder on record', resolvedBySignoff: false);
        }

        $worst = 'ok';
        foreach ($reminders as $r) {
            $r->setRelation('vehicle', $v);            // reuse the loaded odometer, no N+1
            $s = $r->statusInfo()['status'];
            if ($s === 'overdue') { $worst = 'overdue'; break; }
            if ($s === 'due_soon') { $worst = 'due_soon'; }
        }

        return $this->check(
            'tires', self::PILLAR_GARAGE, 'Tires',
            $worst === 'overdue' ? 'fail' : ($worst === 'due_soon' ? 'warn' : 'pass'),
            $worst === 'overdue' ? 'Tire service overdue' : ($worst === 'due_soon' ? 'Tire service due soon' : 'Tires OK'),
            resolvedBySignoff: false,
        );
    }

    /** Battery: age-based advisory from the last-change date (mirrors the foresight life estimate). */
    private function batteryCheck(Vehicle $v): array
    {
        $changed = $v->battery_last_changed;
        if (! $changed) {
            return $this->check('battery', self::PILLAR_GARAGE, 'Battery', 'warn', 'No battery-change date on record', resolvedBySignoff: false);
        }
        $months = (int) Carbon::parse($changed)->diffInMonths(Carbon::now());
        $old    = $months >= self::BATTERY_LIFE_MONTHS;
        return $this->check(
            'battery', self::PILLAR_GARAGE, 'Battery',
            $old ? 'warn' : 'pass',
            $old ? "Battery {$months} months old (past " . self::BATTERY_LIFE_MONTHS . 'mo life — check charge)' : "Battery {$months} months old",
            resolvedBySignoff: false,
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────────────────────────────

    /** Shape one rental-checklist row: business label + deep-link + manual/field metadata for the UI. */
    private function point(array $check, string $label, ?string $fixUrl, bool $manual = false, ?string $field = null): array
    {
        return [
            'key'     => $check['key'],
            'label'   => $label,
            'status'  => $check['status'],
            'detail'  => $check['detail'],
            'fix_url' => $fixUrl,
            'manual'  => $manual,
            'field'   => $field,
        ];
    }

    /** Fold several checks into the single worst verdict (fail > warn > pass), keeping its detail line. */
    private function worst(string $key, string $pillar, string $label, array $checks): array
    {
        $rank  = ['pass' => 0, 'warn' => 1, 'fail' => 2];
        $worst = $checks[0];
        foreach ($checks as $c) {
            if ($rank[$c['status']] > $rank[$worst['status']]) {
                $worst = $c;
            }
        }
        return $this->check($key, $pillar, $label, $worst['status'], $worst['detail'], resolvedBySignoff: false);
    }

    /** Shared expiry verdict for a document with `daysLeft` (negative = expired, null = unknown). */
    private function expiryCheck(string $key, string $label, string $pillar, ?int $daysLeft): array
    {
        if ($daysLeft === null) {
            return $this->check($key, $pillar, $label, 'warn', 'No expiry on record', resolvedBySignoff: false);
        }
        if ($daysLeft < 0) {
            return $this->check($key, $pillar, $label, self::EXPIRY_BLOCKS ? 'fail' : 'warn', 'Expired ' . abs($daysLeft) . ' day' . (abs($daysLeft) === 1 ? '' : 's') . ' ago', resolvedBySignoff: false);
        }
        if ($daysLeft <= self::EXPIRY_WARN_DAYS) {
            return $this->check($key, $pillar, $label, 'warn', 'Expires in ' . $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's'), resolvedBySignoff: false);
        }
        return $this->check($key, $pillar, $label, 'pass', 'Valid (' . $daysLeft . ' days left)', resolvedBySignoff: false);
    }

    private function check(string $key, string $pillar, string $label, string $status, string $detail, bool $resolvedBySignoff): array
    {
        return compact('key', 'pillar', 'label', 'status', 'detail') + ['resolved_by_signoff' => $resolvedBySignoff];
    }

    /** Registration/insurance record without triggering an N+1 when it is already eager-loaded. */
    private function registration(Vehicle $v)
    {
        return $v->relationLoaded('registration') ? $v->registration : $v->registration()->first();
    }

    private function summarise(array $blockers, array $checks): string
    {
        if (! empty($blockers)) {
            $pillars = array_values(array_unique(array_map(fn ($b) => $b['pillar'], $blockers)));
            return 'Not ready — ' . implode(', ', $pillars);
        }
        $warns = array_filter($checks, fn ($c) => $c['status'] === 'warn');
        return empty($warns) ? 'Ready for delivery' : 'Ready — ' . count($warns) . ' advisory';
    }
}
