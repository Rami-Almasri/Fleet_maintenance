<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleRegistration;
use App\Notifications\FleetAlert;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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

    public function __construct(
        private DashboardService $dashboard,
        private MaintenanceReturnService $returns,
    ) {}

    /**
     * Detect every current condition, then fan the *new* ones out to every active user.
     *
     * @return array{alerts:int, recipients:int, created:int} summary for the CLI / API
     */
    public function scan(): array
    {
        $alerts = $this->detect();
        $users  = $this->recipients();

        $created = 0;
        foreach ($users as $user) {
            $existing = $this->existingKeys($user);
            foreach ($alerts as $alert) {
                if ($existing->contains($alert['key'])) {
                    continue; // user already holds this exact live condition
                }
                $user->notify(new FleetAlert($alert));
                $created++;
            }
        }

        return ['alerts' => count($alerts), 'recipients' => $users->count(), 'created' => $created];
    }

    /** Raise a one-off ad-hoc alert to a single user (used by the "send me a test" button). */
    public function notifyUser(User $user, array $payload): void
    {
        $user->notify(new FleetAlert($payload));
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
            ->concat($this->pendingApprovals())
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
     * Cars the sheet shows are BACK from the garage (latest event = IN) but whose
     * maintenance contract is still open — "did you forget to close it?". A 1-day grace
     * is applied: a car that just came back today might go straight back out for more
     * work, so we only nudge once a full day has passed with no new OUT (i.e. the latest
     * event is still IN). The Return Check page already computes this via the strict
     * latest-event rule; we reuse it so the alert can never drift from the page.
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
                'url'      => '/maintenance-returns',
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
        Vehicle::orderBy('code')->chunkById(500, function ($vehicles) use ($due) {
            foreach ($vehicles as $v) {
                if ($due->count() >= self::CAP) {
                    return false;
                }
                $s = $v->serviceStatus();
                if ($s['status'] !== 'service_due') {
                    continue;
                }
                $due->push([
                    'type'     => 'service_due',
                    'category' => 'maintenance',
                    'severity' => ($s['overdue_km'] ?? 0) >= 2000 ? 'warning' : 'info',
                    'title'    => 'Service due · ' . number_format($s['overdue_km']) . ' km over',
                    'body'     => trim(($v->code ? '#' . $v->code . ' ' : '') . trim($v->make . ' ' . $v->model)
                                    . ($v->plate_no ? ' (' . $v->plate_no . ')' : '')
                                    . ' — ' . number_format($s['current']) . ' km, interval ' . number_format($s['interval']) . ' km'),
                    'url'      => '/vehicles/' . $v->id,
                    'key'      => 'service_due:' . $v->id,
                    'icon'     => 'oil',
                    'meta'     => ['plate' => $v->plate_no, 'overdue_km' => $s['overdue_km']],
                ]);
            }
        });

        return $due;
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

    // ── Recipients & dedup ─────────────────────────────────────────────────────

    /** Everyone who should see operational alerts (all non-suspended users). */
    private function recipients(): Collection
    {
        $q = User::query();
        if (DB::getSchemaBuilder()->hasColumn('users', 'status')) {
            $q->where('status', 'active');
        }
        return $q->get();
    }

    /** The dedup keys this user already holds, so we never raise the same condition twice. */
    private function existingKeys(User $user): Collection
    {
        return $user->notifications()
            ->where('type', FleetAlert::class)
            ->get()
            ->map(fn ($n) => $n->data['key'] ?? null)
            ->filter()
            ->values();
    }
}
