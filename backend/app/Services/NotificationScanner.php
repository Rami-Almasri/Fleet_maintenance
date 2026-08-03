<?php

namespace App\Services;

use App\Models\ContactReminder;
use App\Models\Contract;
use App\Models\Maintenance;
use App\Models\ServiceReminder;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleRegistration;
use App\Notifications\FleetAlert;
use App\Services\State\MaintenanceDelay;
use App\Services\State\MaintenanceDelayResolver;
use App\Services\State\OperationalStateLoader;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The single place that turns "current fleet conditions" into notifications.
 *
 * It re-uses the exact definitions the dashboard already trusts (DashboardService) plus the
 * strict km-rule on the Vehicle model, so an alert always means precisely what the matching
 * page means — no second, drifting source of truth.
 *
 * Idempotent by design: every raised alert has a stable `key` (e.g. "overdue_rental:42").
 * Before notifying a user we load the keys they already hold and skip those — so running the
 * scan every few minutes refreshes the feed with genuinely new conditions only, never spam.
 */
class NotificationScanner
{
    /** Per-category safety cap so a first run on a messy fleet can't raise thousands of rows. */
    private const CAP = 100;

    /**
     * Key prefixes the SCAN owns — the live-condition detectors below. Auto-resolution only ever
     * touches alerts whose key starts with one of these, so event-driven hand-offs (the Maintenance
     * Workflow's `maint_wf:*` / `maint_dispatch_ready` …) and ad-hoc `demo:*` alerts are never
     * auto-cleared. Keep in lock-step with the detector keys.
     */
    private const MANAGED_KEY_PREFIXES = [
        'overdue_rental:', 'overdue_maintenance:', 'maint_back_open:', 'expiry:',
        'service_inspection:', 'service_inspection_soon:',
        'service_due:', 'service_due_soon:', // legacy oil-alert keys — kept so old rows auto-resolve after the retarget
        'approval:', 'negative_yield:', 'high_maint_cost:',
        'service_reminder:', 'contact_reminder:',
        'rental_expiring:', 'invoice_overdue:', 'inspection_due:',
        'booking_in_maintenance:', 'booking_readiness:', 'deferred_maint_return:',
        'part_delivery_overdue:', 'test_interrupted:',
    ];

    /**
     * Role gate per alert TYPE: the permission a user must hold to RECEIVE that alert. This is the
     * subscription model — an Inspector/Driver never gets finance or rental-desk noise, while a
     * manager/admin (who holds every permission, super-admin via Gate::before) sees everything.
     *
     * Keyed by the `type` each detector emits. A type missing here (or mapped to null) is treated as
     * everyone-gets-it. Gating happens before $user->notify(), so an unauthorized user's row is never
     * even written — the filter is server-side, not a frontend hide.
     */
    private const ALERT_PERMISSIONS = [
        'overdue_rental'        => 'contracts.view',     // rental desk: operations / finance / manager
        'overdue_maintenance'   => 'maintenance.view',   // workshop: inspector / driver / maintenance / manager
        'maintenance_back_open' => 'maintenance.view',
        'service_inspection'    => 'maintenance.initiate', // Service & Inspection task → the Inspector (Abo Marouf): log oil + flag issues

        'approval_pending'      => 'maintenance.approve', // sign-off authority only
        'document_expiry'       => 'registration.view',   // insurance / registration desk
        'negative_yield'        => 'insights.view',       // finance / analytics
        'high_maintenance_cost' => 'insights.view',

        'service_reminder_due'  => 'reminders.view',      // Reminders section (service + contact)
        'contact_reminder_due'  => 'reminders.view',

        'rental_expiring'       => 'contracts.view',       // rental desk: contract ending within the window
        'invoice_overdue'       => 'billing.view',         // finance: concluded rental with unpaid balance
        'inspection_due'        => 'inspections.view',     // inspections desk: schedule overdue / due soon
        'booking_in_maintenance' => 'contracts.view',      // rental desk: a booked car still in the workshop
        'booking_readiness'      => 'booking_readiness.view', // rental desk: an upcoming booking whose car needs prep
        'deferred_maintenance_return' => 'maintenance.manage', // supervisors/ops: car back from rental still owes the workshop
        'part_delivery_overdue'       => 'parts.view',          // parts desk: a purchased part is past its promised delivery date
        'test_interrupted'            => 'maintenance.manage',   // controllers (Leen): a recommended test lapsed because the car went back on rent
        'maint_invoice_missing'       => 'maintenance.checkpoint.manage', // the Checkpoint lane's owners: car left the garage, bill never arrived
    ];

    /** Days a garage is given to send its bill before the missing invoice becomes an alert. */
    private const INVOICE_GRACE_DAYS = 2;

    public function __construct(
        private DashboardService $dashboard,
        private MaintenanceReturnService $returns,
        private RealProfitService $realProfit,
        private MaintenanceForecastService $forecast,
        private OperationsService $operations,
        private BookingReadinessService $bookingReadiness,
        private OperationalStateLoader $loader,
        private MaintenanceDelayResolver $delayResolver,
        private LeftGarageInvoiceService $leftGarageQueue,
    ) {}

    /**
     * Detect every current condition, then fan the *new* ones out to every active user.
     *
     * @return array{alerts:int, recipients:int, created:int} summary for the CLI / API
     */
    public function scan(): array
    {
        $alerts     = $this->detect();
        $activeKeys = collect($alerts)->pluck('key')->filter()->unique();
        $users      = $this->recipients();

        $created  = 0;
        $resolved = 0;
        foreach ($users as $user) {
            $existing = $this->existingKeys($user);
            foreach ($alerts as $alert) {
                if (! $this->userMayReceive($user, $alert)) {
                    continue; // not for this user's role — never written to their feed
                }
                if ($existing->contains($alert['key'])) {
                    continue; // user already holds this exact live condition
                }
                $user->notify(new FleetAlert($alert));
                $created++;
            }
            // Clear the backlog: any scan-owned alert whose condition no longer holds is marked
            // read, so the bell badge reflects LIVE conditions instead of growing forever.
            $resolved += $this->resolveStale($user, $activeKeys);
        }

        return ['alerts' => count($alerts), 'recipients' => $users->count(), 'created' => $created, 'resolved' => $resolved];
    }

    /**
     * Mark-read this user's unread scan alerts whose condition has cleared (key no longer in the
     * current active set). Only scan-owned keys (MANAGED_KEY_PREFIXES) are eligible, so event-driven
     * and demo alerts are left untouched. History is kept (read, not deleted).
     *
     * @return int how many were auto-resolved
     */
    private function resolveStale(User $user, Collection $activeKeys): int
    {
        $resolved = 0;
        // Scan ALL scan-owned alerts (read included): a condition the user manually read and that
        // then cleared must still be stamped resolved, otherwise it could never recur (see below).
        $user->notifications()
            ->where('type', FleetAlert::class)
            ->get()
            ->each(function ($n) use ($activeKeys, &$resolved) {
                $key = $n->data['key'] ?? null;
                if (! $key || ! Str::startsWith($key, self::MANAGED_KEY_PREFIXES)) {
                    return; // not a scan-owned condition → leave it alone
                }
                if ($activeKeys->contains($key)) {
                    return; // still a live condition → keep nagging
                }
                if (($n->data['resolved'] ?? false) === true) {
                    return; // already resolved on a previous scan
                }
                // Stamp the row resolved so existingKeys() stops treating it as "still held" — this is
                // what lets the same condition re-alert if it recurs later. Distinct from a plain
                // manual read (which keeps suppressing, so a live alert the user read never re-nags).
                $wasUnread = is_null($n->read_at);
                $data = $n->data;
                $data['resolved'] = true;
                $n->data = $data;
                if ($wasUnread) {
                    $n->read_at = now();
                    $resolved++;
                }
                $n->save();
            });

        return $resolved;
    }

    /** Raise a one-off ad-hoc alert to a single user (used by the "send me a test" button). */
    public function notifyUser(User $user, array $payload): void
    {
        $user->notify(new FleetAlert($payload));
    }

    /**
     * Fan a single event-driven alert out to every active user holding a permission — the way the
     * Maintenance Workflow reaches a ROLE ("notify Logistics", "notify Controllers") rather than one
     * person. Unlike scan(), this is fired on a real state change, so it delivers immediately with no
     * dedup loop; the optional $excludeUserId skips the actor who just triggered the transition.
     *
     * @return int how many users were notified
     */
    public function notifyByPermission(string $permission, array $payload, ?int $excludeUserId = null): int
    {
        $query = User::permission($permission);
        if (DB::getSchemaBuilder()->hasColumn('users', 'status')) {
            $query->where('status', 'active');
        }

        $count = 0;
        foreach ($query->get() as $user) {
            if ($excludeUserId && (int) $user->id === (int) $excludeUserId) {
                continue;
            }
            $user->notify(new FleetAlert($payload));
            $count++;
        }

        return $count;
    }

    /**
     * Fan one event-driven alert out to every active user holding ANY of the given permissions,
     * each notified at most ONCE even if they hold several of them — the way the workflow reaches a
     * combined audience ("Inspector AND all Drivers") without double-alerting a manager who holds both.
     *
     * @param array<int,string> $permissions
     * @return int how many distinct users were notified
     */
    public function notifyByAnyPermission(array $permissions, array $payload, ?int $excludeUserId = null): int
    {
        $query = User::permission($permissions); // Spatie accepts an array → users with any of them
        if (DB::getSchemaBuilder()->hasColumn('users', 'status')) {
            $query->where('status', 'active');
        }

        $count = 0;
        foreach ($query->get() as $user) {
            if ($excludeUserId && (int) $user->id === (int) $excludeUserId) {
                continue;
            }
            $user->notify(new FleetAlert($payload));
            $count++;
        }

        return $count;
    }

    /**
     * Fan an alert out to every active user with ANY of the given ROLE(s) — used when the audience is
     * defined by role rather than permission, e.g. "all admins" (super-admin + admin) for oversight
     * alerts. Robust to permission drift: a role member is reached even if a permission wasn't re-synced.
     *
     * @param array<int,string>|string $roles
     * @return int how many users were notified
     */
    public function notifyByRole(array|string $roles, array $payload, ?int $excludeUserId = null): int
    {
        $query = User::role($roles); // Spatie scope: users with any of the given role(s)
        if (DB::getSchemaBuilder()->hasColumn('users', 'status')) {
            $query->where('status', 'active');
        }

        $count = 0;
        foreach ($query->get() as $user) {
            if ($excludeUserId && (int) $user->id === (int) $excludeUserId) {
                continue;
            }
            $user->notify(new FleetAlert($payload));
            $count++;
        }

        return $count;
    }

    /**
     * Dismiss a still-unread alert from everyone EXCEPT one user — the way a pooled "claim this" ping
     * vanishes from the other drivers' bells the moment one of them takes the job. Matches the alert's
     * stable dedup key against the stored JSON payload and marks the rest read in one statement.
     *
     * @return int how many notifications were resolved
     */
    public function resolveKeyForOthers(string $key, ?int $exceptUserId = null): int
    {
        // Exact JSON match on the stored key. (A LIKE '%"key":"..."%' would treat the underscores in
        // keys like `service_due:5` as single-char wildcards, matching more loosely than intended.)
        $query = \Illuminate\Notifications\DatabaseNotification::query()
            ->whereNull('read_at')
            ->where('data->key', $key);

        if ($exceptUserId) {
            $query->where('notifiable_id', '!=', $exceptUserId);
        }

        return $query->update(['read_at' => now()]);
    }

    /**
     * Build the full list of live conditions worth surfacing. Each detector returns
     * self-describing payloads; order here is roughly most → least urgent.
     *
     * @return array<int,array>
     */
    public function detect(): array
    {
        return collect()
            ->concat($this->overdueRentals())
            ->concat($this->overdueMaintenance())
            ->concat($this->maintenanceBackNotClosed())
            ->concat($this->expiringDocuments())
            ->concat($this->serviceDue())
            ->concat($this->serviceDueSoon())
            ->concat($this->pendingApprovals())
            ->concat($this->negativeYield())
            ->concat($this->highMaintenanceCost())
            ->concat($this->serviceRemindersDue())
            ->concat($this->contactRemindersDue())
            ->concat($this->rentalExpiring())
            ->concat($this->bookingInMaintenance())
            ->concat($this->bookingReadinessAlerts())
            ->concat($this->deferredMaintenanceReturns())
            ->concat($this->invoiceOverdue())
            ->concat($this->inspectionDue())
            ->concat($this->partsAwaitingDelivery())
            ->concat($this->testRecommendationsInterrupted())
            ->concat($this->leftGarageInvoiceMissing())
            ->all();
    }

    // ── Detectors ─────────────────────────────────────────────────────────────

    /** Open rentals past their estimated return date (handover + rental days). */
    private function overdueRentals(): Collection
    {
        return collect($this->dashboard->overdueRentalsList())
            ->take(self::CAP)
            ->map(fn ($r) => [
                'type'     => 'overdue_rental',
                'category' => 'operations',
                'severity' => $r['days_overdue'] >= 14 ? 'critical' : 'warning',
                'title'    => 'Overdue rental · ' . ($r['days_overdue']) . 'd late',
                'body'     => trim(($r['car'] ?: 'Vehicle') . ' · ' . ($r['customer'] ?: 'customer')
                                . ' — due ' . $r['due']
                                . (($r['balance'] ?? 0) > 0 ? ' · balance AED ' . number_format($r['balance']) : '')),
                'url'      => '/overdue-rentals',
                'key'      => 'overdue_rental:' . $r['id'],
                'icon'     => 'clock',
                'meta'     => ['contract_no' => $r['contract_no'], 'plate' => $r['plate'], 'days_overdue' => $r['days_overdue']],
            ]);
    }

    /** Cars stuck in the garage past their expected return-from-maintenance date. */
    private function overdueMaintenance(): Collection
    {
        return collect($this->dashboard->overdueMaintenanceList())
            ->take(self::CAP)
            ->map(fn ($r) => [
                'type'     => 'overdue_maintenance',
                'category' => 'maintenance',
                'severity' => $r['days_overdue'] >= 14 ? 'critical' : 'warning',
                'title'    => 'Maintenance overrun · ' . $r['days_overdue'] . 'd late',
                'body'     => trim(($r['car'] ?: 'Vehicle') . ($r['plate'] ? ' (' . $r['plate'] . ')' : '')
                                . ' — at ' . ($r['garage'] ?: 'garage') . ', due ' . $r['due']),
                'url'      => '/maintenance',
                'key'      => 'overdue_maintenance:' . $r['id'],
                'icon'     => 'wrench',
                'meta'     => ['plate' => $r['plate'], 'garage' => $r['garage'], 'days_overdue' => $r['days_overdue']],
            ]);
    }

    /**
     * The proactive "chase the supplier" nudge for the parts desk. This detector does NOT own the delay
     * rule — it CONSUMES the single derived source ({@see MaintenanceDelayResolver}) over the open-ticket
     * graph ({@see OperationalStateLoader}) and asks only: "is this a DERIVED parts delay whose promised
     * ETA has now passed → notify?". All delay facts (headline, ETA, supplier) come from the resolver;
     * the scanner adds only the notification-timing decision (ETA < today) and the stable key. Auto-
     * resolves the moment the part is delivered and the resolver stops reporting a parts delay.
     */
    private function partsAwaitingDelivery(): Collection
    {
        $today = Carbon::today();

        return $this->loader->openTickets()
            ->map(function (Maintenance $t) use ($today) {
                $delay = $this->delayResolver->resolve($t);

                // Only a derived parts delay WITH a promised ETA that has already passed warrants a chase.
                if ($delay->delaySource !== MaintenanceDelay::SOURCE_DERIVED_PARTS || $delay->expectedResolutionDate === null) {
                    return null;
                }
                $eta = Carbon::parse($delay->expectedResolutionDate);
                if (! $eta->lt($today)) {
                    return null; // on-track — the ETA has not passed yet
                }

                $daysOverdue = (int) $eta->diffInDays($today);
                $car   = $t->vehicle ? trim($t->vehicle->make . ' ' . $t->vehicle->model) : 'Vehicle';
                $plate = $t->vehicle?->plate_no;

                return [
                    'type'     => 'part_delivery_overdue',
                    'category' => 'maintenance',
                    'severity' => $daysOverdue >= 7 ? 'critical' : 'warning',
                    'title'    => 'Part delivery overdue · ' . $daysOverdue . 'd late',
                    'body'     => trim(($delay->headline ?: 'Waiting for parts') . ' — ' . $car . ($plate ? ' (' . $plate . ')' : '')
                                    . ' · expected ' . $delay->expectedResolutionDate
                                    . ($delay->supplierName ? ' · ' . $delay->supplierName : '')),
                    'url'      => '/parts',
                    'key'      => 'part_delivery_overdue:' . $t->id,
                    'icon'     => 'package',
                    'meta'     => ['maintenance_id' => $t->id, 'plate' => $plate, 'days_overdue' => $daysOverdue, 'supplier' => $delay->supplierName],
                ];
            })
            ->filter()
            ->take(self::CAP)
            ->values();
    }

    /**
     * Cars the sheet shows are BACK from the garage (latest event = IN) but whose
     * maintenance contract is still open — "did you forget to close it?". A 1-day grace
     * is applied: a car that just came back today might go straight back out for more
     * work, so we only nudge once a full day has passed with no new OUT (i.e. the latest
     * event is still IN). Computed by MaintenanceReturnService via the strict
     * latest-event rule.
     */
    private function maintenanceBackNotClosed(): Collection
    {
        return collect($this->returns->reconcile()['rows'])
            ->filter(fn ($r) => $r['flag'] === 'sheet_back' && (int) ($r['days_since_return'] ?? 0) >= 1)
            ->take(self::CAP)
            ->map(fn ($r) => [
                'type'     => 'maintenance_back_open',
                'category' => 'maintenance',
                'severity' => (int) ($r['days_since_return'] ?? 0) >= 7 ? 'warning' : 'info',
                'title'    => 'Back from garage · contract still open · ' . $r['days_since_return'] . 'd',
                'body'     => trim(($r['car'] ?: 'Vehicle') . ($r['plate'] ? ' (' . $r['plate'] . ')' : '')
                                . ' — back since ' . $r['sheet_returned_on']
                                . ' but maintenance contract' . ($r['contract_no'] ? ' #' . $r['contract_no'] : '')
                                . ' is still open. Close it, or it is going back out for more work.'),
                'url'      => '/maintenance',
                // Keyed by contract + return date: a later trip that comes back on a new
                // date is a genuinely new condition; same return date never re-nags.
                'key'      => 'maint_back_open:' . $r['contract_id'] . ':' . $r['sheet_returned_on'],
                'icon'     => 'wrench',
                'meta'     => ['contract_no' => $r['contract_no'], 'plate' => $r['plate'], 'days_since_return' => $r['days_since_return']],
            ]);
    }

    /**
     * Registration / insurance that is already expired or lapses within 14 days. Bounded to a
     * relevant window (not long-dead history) so the feed stays actionable.
     */
    private function expiringDocuments(): Collection
    {
        $today  = now()->startOfDay();
        $soon   = $today->copy()->addDays(14)->toDateString();
        $floor  = $today->copy()->subDays(60)->toDateString();

        return VehicleRegistration::with('vehicle')
            // Skip docs on cars that are out of the fleet: only Ready (OM status 2) or
            // Rented (3) get registration / insurance expiry alerts. No expiry noise for
            // sold, disposed or scrapped vehicles — mirrors the Foresight report filter.
            ->whereHas('vehicle', fn ($q) => $q->whereIn('status', Vehicle::ACTIVE_STATUSES))
            ->where(function ($q) use ($soon, $floor) {
                $q->whereBetween('expiry_date', [$floor, $soon])
                  ->orWhereBetween('insurance_expiry', [$floor, $soon]);
            })
            ->limit(self::CAP)
            ->get()
            ->flatMap(function (VehicleRegistration $reg) use ($today) {
                $out = [];
                foreach ([['expiry_date', 'Registration', 'registration'], ['insurance_expiry', 'Insurance', 'insurance']] as [$col, $label, $slug]) {
                    $date = $reg->$col ? Carbon::parse($reg->$col)->startOfDay() : null;
                    if (! $date) {
                        continue;
                    }
                    $days = (int) $today->diffInDays($date, false); // negative = already expired
                    if ($days > 14 || $days < -60) {
                        continue;
                    }
                    $car = $reg->vehicle ? trim($reg->vehicle->make . ' ' . $reg->vehicle->model) : 'Vehicle';
                    $out[] = [
                        'type'     => 'document_expiry',
                        'category' => 'fleet',
                        'severity' => $days < 0 ? 'critical' : ($days <= 7 ? 'warning' : 'info'),
                        'title'    => $label . ($days < 0 ? ' expired' : ' expiring · ' . $days . 'd'),
                        'body'     => trim($car . ($reg->vehicle?->plate_no ? ' (' . $reg->vehicle->plate_no . ')' : '')
                                        . ' — ' . strtolower($label) . ' ' . ($days < 0 ? 'lapsed ' : 'due ') . $date->toDateString()),
                        'url'      => $reg->vehicle ? '/vehicles/' . $reg->vehicle->id : '/registrations',
                        'key'      => 'expiry:' . $slug . ':' . $reg->id . ':' . $date->toDateString(),
                        'icon'     => 'shield',
                        'meta'     => ['plate' => $reg->vehicle?->plate_no, 'days' => $days],
                    ];
                }
                return $out;
            });
    }

    /** Vehicles whose oil change is due under the strict km rule (odometer − last ≥ interval). */
    private function serviceDue(): Collection
    {
        $due = collect();
        // Only alert for cars that can actually earn: Ready (OM status 2) or Rented (3).
        // No service nags for sold / disposed / under_maintenance / out_of_order vehicles —
        // mirrors the Maintenance Foresight report filter so alerts never outpace the page.
        Vehicle::whereIn('status', Vehicle::ACTIVE_STATUSES)
            ->orderBy('code')->chunkById(500, function ($vehicles) use ($due) {
            foreach ($vehicles as $v) {
                if ($due->count() >= self::CAP) {
                    return false;
                }
                $s = $v->serviceStatus();
                if ($s['status'] !== 'service_due') {
                    continue;
                }
                $due->push([
                    'type'     => 'service_inspection',
                    'category' => 'maintenance',
                    'severity' => ($s['overdue_km'] ?? 0) >= 2000 ? 'warning' : 'info',
                    'title'    => 'Service & Inspection · ' . number_format($s['overdue_km']) . ' km over',
                    'body'     => trim(($v->code ? '#' . $v->code . ' ' : '') . trim($v->make . ' ' . $v->model)
                                    . ($v->plate_no ? ' (' . $v->plate_no . ')' : '')
                                    . ' — ' . number_format($s['current']) . ' km, interval ' . number_format($s['interval']) . ' km'),
                    'url'      => '/vehicles/' . $v->id . '?serviceTicket=oil_change', // click → open/create a maintenance ticket pre-filled with the due Oil Change
                    'key'      => 'service_inspection:' . $v->id, // distinct from the legacy service_due: key so the retarget isn't dedup-blocked
                    'icon'     => 'oil',
                    'meta'     => ['plate' => $v->plate_no, 'overdue_km' => $s['overdue_km']],
                ]);
            }
        });

        return $due;
    }

    /**
     * PREVENTIVE catch: cars APPROACHING their service — within 1,000 km of the interval OR within 7 days
     * of the projected due date (whichever comes first), so a service is never missed because the car was
     * out on a long rental. Distinct from serviceDue() above, which fires only once already overdue.
     *
     * Anti-spam: the dedup key carries the ISO week, so a given car nudges the supervisor at most ONCE per
     * week (the scan runs every few minutes but never re-raises the same week's key); next week a fresh key
     * gives one gentle reminder while the car is still due, and last week's auto-resolves.
     */
    private function serviceDueSoon(): Collection
    {
        $week = Carbon::now()->format('o-\WW'); // ISO year-week, e.g. 2026-W27
        $soon = collect();

        Vehicle::whereIn('status', Vehicle::ACTIVE_STATUSES)
            ->orderBy('code')->chunkById(500, function ($vehicles) use ($soon, $week) {
                foreach ($vehicles as $v) {
                    if ($soon->count() >= self::CAP) {
                        return false;
                    }
                    // Cheap pre-gate (no query): skip cars with no data, already overdue (serviceDue owns
                    // those), or clearly far off — only cars plausibly within reach need the usage-rate query.
                    $s = $v->serviceStatus();
                    if ($s['status'] !== 'ok' || $s['remaining'] === null || $s['remaining'] > 3000) {
                        continue;
                    }

                    $f = $this->forecast->forecast($v);
                    if ($f['status'] !== 'due_soon') {
                        continue;
                    }

                    $when = $f['projected_date'] ? ', ~by ' . $f['projected_date'] : '';
                    $soon->push([
                        'type'     => 'service_inspection',
                        'category' => 'maintenance',
                        'severity' => 'info', // preventive heads-up, not yet a problem
                        'title'    => 'Service & Inspection soon · ~' . number_format((int) $f['remaining_km']) . ' km left',
                        'body'     => trim(($v->code ? '#' . $v->code . ' ' : '') . trim($v->make . ' ' . $v->model)
                                        . ($v->plate_no ? ' (' . $v->plate_no . ')' : '')
                                        . ' — approaching its ' . number_format((int) $f['interval']) . ' km service' . $when),
                        'url'      => '/vehicles/' . $v->id . '?serviceTicket=oil_change', // click → open/create a maintenance ticket pre-filled with the due Oil Change
                        'key'      => 'service_inspection_soon:' . $v->id . ':' . $week,
                        'icon'     => 'oil',
                        'meta'     => [
                            'plate'          => $v->plate_no,
                            'remaining_km'   => $f['remaining_km'],
                            'days_to_due'    => $f['days_to_due'],
                            'projected_date' => $f['projected_date'],
                            'usage_rate'     => $f['usage_rate'],
                        ],
                    ]);
                }
            });

        return $soon;
    }

    /** Maintenance bills over the threshold awaiting manual sign-off. */
    private function pendingApprovals(): Collection
    {
        return Contract::where('contract_type', 'U')
            ->whereHas('maintenance', fn ($q) => $q->where('approval_status', 'pending'))
            ->with(['vehicle', 'maintenance'])
            ->limit(self::CAP)
            ->get()
            ->map(fn (Contract $c) => [
                'type'     => 'approval_pending',
                'category' => 'maintenance',
                'severity' => 'info',
                'title'    => 'Approval needed',
                'body'     => trim(($c->vehicle ? trim($c->vehicle->make . ' ' . $c->vehicle->model) : 'Maintenance job')
                                . ($c->contract_no ? ' · ' . $c->contract_no : '')
                                . ' — bill awaiting sign-off'),
                'url'      => '/maintenance-approvals',
                'key'      => 'approval:' . $c->id,
                'icon'     => 'check',
                'meta'     => ['contract_no' => $c->contract_no, 'plate' => $c->vehicle?->plate_no],
            ]);
    }

    /**
     * "Money pit" cars: over the trailing window their Real Net Profit is below what they cost
     * to maintain — a financial leak worth surfacing. Reuses the exact list the dashboard and
     * Foresight page show, so the alert can never drift from the page. Keyed per vehicle so a
     * car that recovers and slips back later is a genuinely new condition.
     */
    private function negativeYield(): Collection
    {
        return collect($this->realProfit->negativeYieldVehicles())
            ->take(self::CAP)
            ->map(fn ($r) => [
                'type'     => 'negative_yield',
                'category' => 'finance',
                'severity' => 'warning',
                'title'    => 'Negative yield · nets −AED ' . number_format(abs((float) $r['net_yield'])),
                'body'     => trim(($r['code'] ? '#' . $r['code'] . ' ' : '') . ($r['car'] ?: 'Vehicle')
                                . ($r['plate'] ? ' (' . $r['plate'] . ')' : '')
                                . ' — earned AED ' . number_format((float) $r['real_net_profit'])
                                . ' but cost AED ' . number_format((float) $r['maintenance_spend'])
                                . ' to maintain over ' . $r['window_months'] . ' mo.'),
                // Straight to the car itself — its profile carries the spend, the repeat faults and
                // the repair history behind the negative yield (the old Foresight list is retired).
                'url'      => '/vehicles/' . $r['vehicle_id'],
                'key'      => 'negative_yield:' . $r['vehicle_id'],
                'icon'     => 'trend-down',
                'meta'     => ['plate' => $r['plate'], 'net_yield' => $r['net_yield']],
            ]);
    }

    /**
     * A single recent workshop bill over the approval threshold — one repair eating the margin.
     * Bounded to the last 30 days so the feed stays current; keyed per event so each new costly
     * repair nags once. Critical at 3× the threshold.
     */
    private function highMaintenanceCost(): Collection
    {
        $threshold = (float) config('fleet.maintenance_approval_threshold', 500);
        $floor     = now()->subDays(30)->toDateString();

        return Maintenance::query()
            ->whereIn('origin', Maintenance::WORKSHOP_LOG_ORIGINS)
            ->whereNotNull('vehicle_id')
            ->whereNotNull('out_date')
            ->whereDate('out_date', '>=', $floor)
            ->where('cost', '>', $threshold)
            ->with('vehicle')
            ->orderByDesc('out_date')
            ->limit(self::CAP)
            ->get()
            ->map(fn (Maintenance $m) => [
                'type'     => 'high_maintenance_cost',
                'category' => 'finance',
                'severity' => (float) $m->cost >= $threshold * 3 ? 'critical' : 'warning',
                'title'    => 'High repair cost · AED ' . number_format((float) $m->cost),
                'body'     => trim(($m->vehicle ? trim($m->vehicle->make . ' ' . $m->vehicle->model) : 'Vehicle')
                                . ($m->vehicle?->plate_no ? ' (' . $m->vehicle->plate_no . ')' : '')
                                . ' — ' . ($m->service_main ?: 'repair')
                                . ' on ' . optional($m->out_date)->toDateString()
                                . ' cost AED ' . number_format((float) $m->cost)
                                . ' (over the AED ' . number_format($threshold) . ' threshold).'),
                'url'      => '/maintenance',
                'key'      => 'high_maint_cost:' . $m->id,
                'icon'     => 'wrench',
                'meta'     => ['plate' => $m->vehicle?->plate_no, 'cost' => (float) $m->cost],
            ]);
    }

    /**
     * Service Reminders that are OVERDUE (Reminders section). oil_change is deliberately EXCLUDED —
     * the vehicle-level serviceDue() detector already owns it (both derive from the same Oil Change
     * data), so this surfaces the OTHER technical services the old detector never saw: air/oil filters,
     * brake pads, tyres, battery, A/C … Muted or inactive reminders are skipped. Keyed per reminder, so
     * once it's completed (next-due rolls forward, no longer overdue) resolveStale() clears it, and a
     * future cycle re-alerts.
     */
    private function serviceRemindersDue(): Collection
    {
        return ServiceReminder::with('vehicle')
            ->where('active', true)
            ->where('is_muted', false)
            ->where('service_type', '<>', 'oil_change')
            ->whereNotNull('vehicle_id')
            ->get()
            ->filter(fn (ServiceReminder $r) => $r->statusInfo()['status'] === 'overdue')
            ->take(self::CAP)
            ->map(function (ServiceReminder $r) {
                $s   = $r->statusInfo();
                $v   = $r->vehicle;
                $by  = $s['km_remaining'] !== null && $s['km_remaining'] < 0
                    ? number_format(abs($s['km_remaining'])) . ' km over'
                    : ($s['days_remaining'] !== null && $s['days_remaining'] < 0 ? abs($s['days_remaining']) . 'd over' : 'overdue');
                return [
                    'type'     => 'service_reminder_due',
                    'category' => 'maintenance',
                    'severity' => 'warning',
                    'title'    => $r->displayName() . ' overdue · ' . $by,
                    'body'     => trim(($v ? (($v->code ? '#' . $v->code . ' ' : '') . trim($v->make . ' ' . $v->model)) : 'Vehicle')
                                    . ($v?->plate_no ? ' (' . $v->plate_no . ')' : '')
                                    . ' — ' . $r->displayName() . ' service is overdue'),
                    'url'      => $v ? '/vehicles/' . $v->id : '/reminders',
                    'key'      => 'service_reminder:' . $r->id,
                    'icon'     => 'wrench',
                    'meta'     => ['plate' => $v?->plate_no, 'service_type' => $r->service_type, 'km_remaining' => $s['km_remaining'], 'days_remaining' => $s['days_remaining']],
                ];
            })
            ->values();
    }

    /**
     * Contact Reminders (call a vendor/garage) that are OPEN and past their due moment — the "chase this
     * invoice / confirm this quote / collect parts" follow-ups, so an administrative task never slips.
     * Keyed per reminder; marking it done drops it from the active set and resolveStale() clears the bell.
     */
    private function contactRemindersDue(): Collection
    {
        return ContactReminder::with('vendor')
            ->where('status', 'open')
            ->whereNotNull('due_at')
            ->where('due_at', '<=', now())
            ->orderBy('due_at')
            ->limit(self::CAP)
            ->get()
            ->map(function (ContactReminder $r) {
                $vendor  = $r->vendor?->name;
                $daysOld = (int) Carbon::parse($r->due_at)->startOfDay()->diffInDays(now()->startOfDay());
                return [
                    'type'     => 'contact_reminder_due',
                    'category' => 'operations',
                    'severity' => $daysOld >= 3 ? 'warning' : 'info',
                    'title'    => 'Follow-up due' . ($vendor ? ' · ' . $vendor : '') . ($daysOld > 0 ? ' · ' . $daysOld . 'd' : ''),
                    'body'     => trim(($vendor ? $vendor . ' — ' : '') . $r->subject),
                    'url'      => '/reminders',
                    'key'      => 'contact_reminder:' . $r->id,
                    'icon'     => 'phone',
                    'meta'     => ['vendor' => $vendor, 'due_at' => optional($r->due_at)->toDateTimeString(), 'invoice_id' => $r->invoice_id, 'maintenance_id' => $r->maintenance_id],
                ];
            });
    }

    /**
     * PROACTIVE — open rentals whose estimated return date is within the next 7 days but NOT yet
     * overdue: the "coming due" heads-up so the desk can arrange the return / renewal before it
     * lapses. Reuses DashboardService::expiringRentalsList() (same source the homepage panel shows),
     * so the bell and the dashboard flag never disagree. Keyed per contract: one heads-up that
     * auto-resolves the moment the car returns or the rental tips into the overdue detector.
     */
    private function rentalExpiring(): Collection
    {
        return collect($this->dashboard->expiringRentalsList())
            ->take(self::CAP)
            ->map(fn ($r) => [
                'type'     => 'rental_expiring',
                'category' => 'operations',
                'severity' => ($r['days_left'] ?? 7) <= 2 ? 'warning' : 'info',
                'title'    => 'Rental expiring · ' . (($r['days_left'] ?? 0) <= 0 ? 'today' : $r['days_left'] . 'd'),
                'body'     => trim(($r['car'] ?: 'Vehicle') . ($r['plate'] ? ' (' . $r['plate'] . ')' : '')
                                . ' · ' . ($r['customer'] ?: 'customer') . ' — due ' . $r['due']),
                'url'      => '/contracts/' . $r['id'],
                'key'      => 'rental_expiring:' . $r['id'],
                'icon'     => 'clock',
                'meta'     => ['contract_no' => $r['contract_no'], 'plate' => $r['plate'], 'days_left' => $r['days_left']],
            ]);
    }

    /**
     * PROACTIVE — a car with an UPCOMING booking (type-R reservation) whose pickup is within 2 days
     * while it is STILL in the workshop. The pickup won't be ready unless the car leaves maintenance in
     * time, so the rental desk gets a daily nag on the 2-day, 1-day and same-day marks.
     *
     * "In maintenance" is the canonical OperationsService set (open type-U contract / manual garage
     * event / open workflow ticket) — the exact rule the dashboard and the /maintenance-bookings page
     * use, so the bell can never disagree with them. The key is date-stamped so it re-fires once per
     * day (not per 10-min scan); it auto-resolves the moment the car leaves the shop or the pickup date
     * passes — yesterday's stamp is no longer in the detected set, so resolveStale marks it read.
     */
    private function bookingInMaintenance(): Collection
    {
        $today   = Carbon::today();
        // Same lead time the Booking Readiness board / settings use, so the two triggers never disagree.
        $lead    = (int) $this->bookingReadiness->settings()['alert_lead_days'];
        $horizon = $today->copy()->addDays(max(0, $lead));   // today (0) · … · +lead

        $bookings = Contract::query()
            ->upcomingReservation()                       // type-R, not yet ended
            ->whereNotNull('vehicle_id')
            ->whereNotNull('out_date')
            ->whereDate('out_date', '>=', $today)
            ->whereDate('out_date', '<=', $horizon)
            ->with('vehicle:id,plate_no,make,model')
            ->orderBy('out_date')
            ->take(self::CAP)
            ->get();

        if ($bookings->isEmpty()) {
            return collect();
        }

        // One canonical maintenance sweep, then intersect (no per-booking query).
        $inShop = array_flip($this->operations->vehiclesInMaintenance());
        $stamp  = $today->format('Y-m-d');

        return $bookings
            ->filter(fn ($c) => isset($inShop[(int) $c->vehicle_id]))
            ->map(function ($c) use ($today, $stamp) {
                $start    = Carbon::parse($c->out_date);
                $daysLeft = (int) $today->diffInDays($start, false);   // 0 today · 1 · 2
                $plate    = $c->vehicle?->plate_no;
                $car      = trim(($c->vehicle?->make ?? '') . ' ' . ($c->vehicle?->model ?? '')) ?: ($plate ?: 'Vehicle');
                return [
                    'type'     => 'booking_in_maintenance',
                    'category' => 'operations',
                    'severity' => $daysLeft <= 1 ? 'critical' : 'warning',
                    'title'    => 'Booked car in maintenance · ' . ($daysLeft <= 0 ? 'due today' : $daysLeft . 'd left'),
                    'body'     => trim($car . ($plate ? ' (' . $plate . ')' : '')
                                    . ' is still in the workshop but booked out ' . $start->format('D, M j')),
                    'url'      => '/maintenance-bookings',
                    'key'      => 'booking_in_maintenance:' . $c->id . ':' . $stamp,
                    'icon'     => 'wrench',
                    'meta'     => ['contract_no' => $c->contract_no, 'plate' => $plate, 'days_left' => $daysLeft, 'out_date' => $start->toDateString()],
                ];
            })
            ->values();
    }

    /**
     * PROACTIVE — an UPCOMING booking whose pickup is within the alert-lead window (default 2 working
     * days, honouring the excluded-holiday list). The rental desk is nudged to PREP the car before the
     * customer arrives, and the alert stands out from the routine feed with a per-booking readiness
     * read-out: if the car is already Ready it's a quiet info nudge; if a hard block or a still-needed
     * pre-rental inspection remains, it escalates to a warning/critical that names what's pending.
     *
     * Reuses BookingReadinessService (the same brain the board renders) so the bell can never disagree
     * with the page. Distinct from bookingInMaintenance(), which only fires for cars stuck in the shop —
     * this fires for EVERY imminent booking that isn't fully ready. Date-stamped key → one nag per day,
     * auto-resolving the moment the booking is ready, cancelled, or its pickup passes.
     */
    private function bookingReadinessAlerts(): Collection
    {
        $stamp = Carbon::today()->format('Y-m-d');

        return $this->bookingReadiness->upcomingBookings()
            ->where('urgent', true)                       // inside the alert-lead window (working days)
            ->take(self::CAP)
            ->map(function (array $b) use ($stamp) {
                $ready    = $b['verdict'] === BookingReadinessService::VERDICT_READY;
                $blocked  = $b['verdict'] === BookingReadinessService::VERDICT_BLOCKED;
                $daysLeft = (int) $b['days_left'];
                $plate    = $b['vehicle']['plate_no'] ?? null;
                $car      = $b['vehicle']['label'] ?? 'Vehicle';

                // Name the outstanding conditions (blocks + warns, incl. a pending pre-rental inspection).
                $pending = collect($b['checks'])
                    ->whereIn('status', [
                        ContractEligibilityService::BLOCK,
                        ContractEligibilityService::WARN,
                        ContractEligibilityService::MANAGER_OVERRIDE,
                    ])
                    ->pluck('label')->all();

                $when = $daysLeft <= 0 ? 'pickup today' : $daysLeft . 'd to pickup';
                $tail = $ready
                    ? 'Car is ready for pickup.'
                    : 'Needs: ' . (implode(', ', $pending) ?: 'review');

                return [
                    'type'     => 'booking_readiness',
                    'category' => 'operations',
                    'severity' => $blocked ? 'critical' : ($ready ? 'info' : 'warning'),
                    'title'    => 'Upcoming booking · ' . $when,
                    'body'     => trim($car . ($plate ? ' (' . $plate . ')' : '')
                                    . ' booked out ' . Carbon::parse($b['out_date'])->format('D, M j')
                                    . ($b['customer'] ? ' · ' . $b['customer'] : '') . ' — ' . $tail),
                    'url'      => '/booking-readiness',
                    'key'      => 'booking_readiness:' . $b['id'] . ':' . $stamp,
                    'icon'     => 'calendar',
                    'meta'     => [
                        'contract_no' => $b['contract_no'],
                        'plate'       => $plate,
                        'days_left'   => $daysLeft,
                        'out_date'    => $b['out_date'],
                        'verdict'     => $b['verdict'],
                    ],
                ];
            })
            ->values();
    }

    /**
     * DEFERRED MAINTENANCE — a car that was pulled out of the workshop early for a customer has now
     * come back from that rental (operational_status 'available') but still owes the garage a visit.
     * This is the moment the reminder matters: it's free and could be silently re-rented, so nudge
     * the supervisor to route it back to the workshop. Keyed per vehicle; auto-resolves the instant the
     * flag is cleared (car checked back in / dismissed) OR the car goes back out (no longer available).
     */
    private function deferredMaintenanceReturns(): Collection
    {
        return Vehicle::where('is_deferred_maintenance', true)
            ->where('operational_status', 'available')
            ->orderBy('plate_no')
            ->limit(self::CAP)
            ->get()
            ->map(function (Vehicle $v) {
                $car = trim($v->make . ' ' . $v->model) ?: ($v->plate_no ?: 'Vehicle');
                return [
                    'type'     => 'deferred_maintenance_return',
                    'category' => 'maintenance',
                    'severity' => 'warning',
                    'title'    => 'Back from rental · still owes maintenance',
                    'body'     => trim($car . ($v->plate_no ? ' (' . $v->plate_no . ')' : '')
                                    . ' has returned from rental but still owes maintenance'
                                    . ($v->deferred_maintenance_reason ? ' — ' . $v->deferred_maintenance_reason : '')
                                    . '. Resume its paused maintenance (or schedule it back to the workshop).'),
                    'url'      => '/vehicles/' . $v->id,
                    'key'      => 'deferred_maint_return:' . $v->id,
                    'icon'     => 'wrench',
                    'meta'     => ['plate' => $v->plate_no, 'note' => $v->deferred_maintenance_reason],
                ];
            });
    }

    /**
     * TEST INTERRUPTED — a car the system RECOMMENDED for a test (a ticket sitting in the Inspection
     * Request Review gate, WF_PENDING_REVIEW, awaiting the Controller's approval) whose vehicle has since
     * gone back out on rent (operational_status = 'rented') before the test could be performed. The
     * recommendation has effectively lapsed: it can't be actioned while the car is with a customer, so
     * Leen (maintenance.manage) is told the recommended test could not be completed.
     *
     * Pure detection over existing state — no new workflow event. Keyed per ticket; the moment the car
     * returns (no longer 'rented') OR the review is actioned (ticket leaves WF_PENDING_REVIEW) the key
     * drops from the active set and resolveStale() clears it.
     */
    private function testRecommendationsInterrupted(): Collection
    {
        return Maintenance::query()
            ->where('workflow_status', Maintenance::WF_PENDING_REVIEW)
            ->whereHas('vehicle', fn ($q) => $q->where('operational_status', 'rented'))
            ->with('vehicle:id,plate_no,make,model,code')
            ->orderByDesc('id')
            ->limit(self::CAP)
            ->get()
            ->map(function (Maintenance $m) {
                $v   = $m->vehicle;
                $car = $v ? trim($v->make . ' ' . $v->model) : 'Vehicle';
                return [
                    'type'     => 'maint_test_interrupted',
                    'category' => 'maintenance',
                    'severity' => 'warning',
                    'title'    => 'Test interrupted · car back with customer',
                    'body'     => trim(($v && $v->code ? '#' . $v->code . ' ' : '') . $car
                                    . ($v?->plate_no ? ' (' . $v->plate_no . ')' : '')
                                    . ' was recommended for a test, but it has gone back out on rent before the'
                                    . ' test could be done. The recommended test could not be completed — review'
                                    . ' the pending request.'),
                    'url'      => '/maintenance-workflow/' . $m->id,
                    'key'      => 'test_interrupted:' . $m->id,
                    'icon'     => 'alert',
                    'meta'     => ['ticket_id' => $m->id, 'plate' => $v?->plate_no],
                ];
            });
    }

    /**
     * PROACTIVE — a concluded rental (car already back) that still carries an outstanding
     * contract_balance: the customer's payment is overdue. Reuses DashboardService::overdueInvoicesList()
     * (contracts.contract_balance, trailing 12 months). Distinct from overdueRentals(), which nags cars
     * still physically OUT. Keyed per contract; clears once the balance is settled.
     */
    private function invoiceOverdue(): Collection
    {
        // PARKED behind a flag: the overdue-invoice set is a large receivables backlog (hundreds of
        // accounts), so a bell-per-debtor is noise. Off by default until a digest/threshold policy is
        // chosen; the homepage Proactive Flags panel still surfaces the top debtors + true total.
        if (! config('features.invoice_overdue_alerts')) {
            return collect();
        }

        return collect($this->dashboard->overdueInvoicesList(self::CAP))
            ->take(self::CAP)
            ->map(fn ($r) => [
                'type'     => 'invoice_overdue',
                'category' => 'finance',
                'severity' => ($r['balance'] ?? 0) >= 500 ? 'warning' : 'info',
                'title'    => 'Payment overdue · AED ' . number_format((float) $r['balance']),
                'body'     => trim(($r['customer'] ?: 'Customer')
                                . ($r['contract_no'] ? ' · ' . $r['contract_no'] : '')
                                . ' — returned ' . $r['returned_on'] . ', balance AED ' . number_format((float) $r['balance']) . ' unpaid'),
                'url'      => '/contracts/' . $r['id'],
                'key'      => 'invoice_overdue:' . $r['id'],
                'icon'     => 'dollar',
                'meta'     => ['contract_no' => $r['contract_no'], 'plate' => $r['plate'], 'balance' => $r['balance']],
            ]);
    }

    /**
     * PROACTIVE — active inspection schedules that are overdue or due soon (safety / operations
     * checks). Reuses DashboardService::inspectionsDueList(), itself built on
     * InspectionSchedule::statusInfo(). Keyed per schedule; clears once the inspection is logged and
     * the next-due point rolls forward.
     */
    private function inspectionDue(): Collection
    {
        return collect($this->dashboard->inspectionsDueList())
            ->take(self::CAP)
            ->map(fn ($r) => [
                'type'     => 'inspection_due',
                'category' => 'operations',
                'severity' => ($r['status'] ?? '') === 'overdue' ? 'warning' : 'info',
                'title'    => 'Inspection ' . (($r['status'] ?? '') === 'overdue' ? 'overdue' : 'due soon') . ' · ' . ($r['name'] ?: 'Safety check'),
                'body'     => trim(($r['car'] ?: 'Vehicle') . ($r['plate'] ? ' (' . $r['plate'] . ')' : '')
                                . ' — ' . ($r['name'] ?: 'inspection') . ' ' . strtolower($r['label'] ?? 'due')),
                'url'      => $r['vehicle_id'] ? '/vehicles/' . $r['vehicle_id'] : '/inspections/schedules',
                'key'      => 'inspection_due:' . $r['id'],
                'icon'     => 'clipboard',
                'meta'     => ['plate' => $r['plate'], 'status' => $r['status'], 'days_remaining' => $r['days_remaining']],
            ]);
    }

    /**
     * PROACTIVE — the car has physically left the garage but no invoice has been entered, so the job is
     * finished on the ground and still open on paper. Consumes the ONE definition of that condition
     * ({@see LeftGarageInvoiceService}), the same rows the /oversight/left-garage page lists, so the lane
     * and the page can never show different work.
     *
     * Timing: a two-day grace period after collection — a garage is allowed a day or two to send the bill
     * before anyone is nagged about it. Keyed per ticket, so it clears the moment a cost is entered and
     * the ticket drops off the queue.
     */
    private function leftGarageInvoiceMissing(): Collection
    {
        return $this->leftGarageQueue->rows()
            ->filter(fn ($r) => ($r['days_since'] ?? 0) >= self::INVOICE_GRACE_DAYS)
            ->take(self::CAP)
            ->map(function (array $r) {
                $days = (int) ($r['days_since'] ?? 0);
                $car  = $r['plate_no'] ?: ($r['car'] ?: ('Ticket #' . $r['ticket_id']));

                return [
                    'type'     => 'maint_invoice_missing',
                    'category' => 'maintenance',
                    'severity' => $days >= 14 ? 'critical' : ($days >= 7 ? 'warning' : 'info'),
                    'title'    => 'No invoice · left ' . $days . 'd ago · ' . $car,
                    'body'     => trim($car . ' left ' . ($r['garage'] ? $r['garage'] : 'the garage')
                                    . ' ' . $days . ' day(s) ago and no invoice has been entered'
                                    . ($r['invoice_requested']
                                        ? ' — already requested, chase the garage.'
                                        : ' — request the bill from the garage.')),
                    // Straight to the ticket — that is where the invoice is actually requested and
                    // recorded, and it's the same destination the queue page's own row action uses.
                    'url'      => '/maintenance-workflow/' . $r['ticket_id'],
                    'key'      => 'maint_invoice_missing:' . $r['ticket_id'],
                    'icon'     => 'dollar',
                    'meta'     => [
                        'ticket_id'         => $r['ticket_id'],
                        'vehicle_id'        => $r['vehicle_id'],
                        'plate'             => $r['plate_no'],
                        'garage'            => $r['garage'],
                        'days_since'        => $days,
                        'invoice_requested' => $r['invoice_requested'],
                    ],
                ];
            })
            ->values();
    }

    // ── Recipients & dedup ─────────────────────────────────────────────────────

    /**
     * May this user receive this alert, given its type? Resolves the type → required-permission map
     * and defers to Spatie's gate ($user->can): super-admin passes everything via Gate::before, a
     * manager holds every listed permission, and an Inspector/Driver only clears the maintenance set.
     * A type with no mapping is open to everyone.
     */
    private function userMayReceive(User $user, array $alert): bool
    {
        $permission = self::ALERT_PERMISSIONS[$alert['type'] ?? ''] ?? null;

        return $permission === null ? true : $user->can($permission);
    }

    /**
     * The alert TYPES this user is no longer allowed to see — every gated type whose required
     * permission the user lacks. Used on READ (NotificationController) to hide LEGACY rows that
     * were written before the role-based gate existed: same map, applied at fetch time, so the
     * filtering is server-side and the hidden rows never leave the database.
     *
     * @return array<int,string>
     */
    public static function deniedTypesFor(User $user): array
    {
        return collect(self::ALERT_PERMISSIONS)
            ->reject(fn (string $permission) => $user->can($permission)) // keep only types the user CAN'T see
            ->keys()
            ->all();
    }

    /** Everyone who should see operational alerts (all non-suspended users). */
    private function recipients(): Collection
    {
        $q = User::query();
        if (DB::getSchemaBuilder()->hasColumn('users', 'status')) {
            $q->where('status', 'active');
        }
        return $q->get();
    }

    /**
     * The dedup keys this user already holds LIVE, so we never raise the same condition twice.
     * A row stamped `resolved` (its condition previously cleared) is deliberately excluded — that is
     * what allows a recurring condition to re-alert instead of being suppressed forever by a stale row.
     */
    private function existingKeys(User $user): Collection
    {
        return $user->notifications()
            ->where('type', FleetAlert::class)
            ->get()
            ->reject(fn ($n) => ($n->data['resolved'] ?? false) === true)
            ->map(fn ($n) => $n->data['key'] ?? null)
            ->filter()
            ->values();
    }
}
