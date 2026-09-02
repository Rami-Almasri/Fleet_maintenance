<?php

namespace App\Services\Garage;

use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleGarageAlertState;
use App\Services\FleetUtilizationService;
use App\Services\NotificationScanner;
use App\Support\GarageSeverity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * GARAGE INTELLIGENCE — the two questions the fleet asks about a car's workshop behaviour, and the
 * one rule that decides when an answer is worth interrupting somebody for.
 *
 *   1. Is this car going into the garage TOO OFTEN?   → visits in the rolling window
 *   2. Is it SPENDING TOO LONG in the garage?         → true off-road downtime in the same window
 *
 * ── WHERE THE NUMBERS COME FROM ────────────────────────────────────────────────────────────────
 *
 * Nowhere new. Both readings are taken from FleetUtilizationService::vehicleWindow(), the canonical
 * engine every other surface already reports from ([[maintenance-days-single-source]]):
 *
 *   A VISIT is one OfficeManager type-'U' maintenance contract — the authoritative one-per-stay
 *   record of the car leaving the fleet's hands and coming back. This is precisely why the count is
 *   NOT "how many maintenance rows exist": a car that goes in once and has an inspection, an oil
 *   change, a brake job and an electrical repair done during that stay has FOUR maintenance records,
 *   four workflow tasks and ONE contract — and therefore one visit. The workflow's own tickets link
 *   themselves to that contract (`maintenances.linked_contract_id`) for exactly this reason.
 *
 *   DOWNTIME is the same engine's true off-road seconds: the union of the car's maintenance
 *   intervals, overlaps merged so no second is counted twice, MINUS any second that was also under a
 *   rental. Rental is King — time the customer had the car is rental time, never shop time. An open
 *   stay (no return recorded) runs to the current instant; a stay that started before the window or
 *   runs past its end is clipped to the window, not dropped and not counted whole.
 *
 * ── WHEN SOMEBODY IS TOLD ──────────────────────────────────────────────────────────────────────
 *
 * Only on a CLIMB, and per signal. `notified_visit_severity` / `notified_downtime_severity` on the
 * state row remember what has already been said, so:
 *
 *   normal → warning     alert.        warning → warning   silent (nothing new has happened).
 *   warning → high       alert.        high → warning      silent, and the record drops to warning,
 *   high → critical      alert.                            which re-arms a future climb back to high.
 *
 * That is the entire anti-spam design, and it holds no matter how many maintenance events fire in an
 * afternoon: five updates to the same car produce one alert, because after the first one the level
 * is no longer new. The comparison lives in App\Support\GarageSeverity, DB-free and unit-tested.
 *
 * ── WHEN IT RUNS ───────────────────────────────────────────────────────────────────────────────
 *
 * Event-driven first. OperationsService::reconcileVehicleOperationalStatus() is the single choke
 * point every path that can change a car's garage occupancy already funnels through — every
 * maintenance-workflow transition (dispatch, arrival, under-repair, ready, close, reopen, cancel),
 * every contract open/close, every logistics movement, every hand-entered workshop event. Hooking
 * there means one integration point instead of thirty listeners, and it is scoped to the one
 * affected car. Nothing is attached to blanket model saves.
 *
 * A daily sweep is the safety net, and it is not optional: the window ROLLS. A car can cross into
 * `high` purely because it is still sitting in a garage and time passed, and can fall out of
 * `warning` purely because an old visit aged past 30 days — neither of those is an event anybody
 * fires. The sweep re-reads every car and lets the same escalation rule decide, so it corrects
 * states without ever generating a second alert for a level already announced.
 */
class GarageIntelligenceService
{
    /**
     * Set true around bulk work (fleet-wide reconciles, importers, seeders) so a loop over hundreds
     * of cars does not run a per-car evaluation hundreds of times. The caller is expected to follow
     * the loop with one sweep() instead. Mirrors ContractObserver::$muted.
     */
    public static bool $muted = false;

    public function __construct(
        private FleetUtilizationService $utilization,
        private NotificationScanner $notifier,
        private GarageStayResolver $stays,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('garage_intelligence.enabled', true);
    }

    public function windowDays(): int
    {
        return max(1, (int) config('garage_intelligence.window_days', 30));
    }

    // ── Reading ────────────────────────────────────────────────────────────────────────────────

    /**
     * The car's current garage behaviour, graded. Pure read — computes and returns, persists nothing
     * and notifies nobody, so a page can call it and a test can assert on it without side effects.
     *
     * @return array<string,mixed>
     */
    public function read(int $vehicleId): array
    {
        $windowDays = $this->windowDays();
        $from       = Carbon::today()->subDays($windowDays)->toDateString();

        // EVERY place a garage movement is recorded — the Google Sheet workshop log, the website's
        // own workflow tickets, and the OM contracts — resolved into distinct physical stays with the
        // same stay never counted twice. Reading contracts alone was blind to 18 of the 126 cars with
        // garage activity last month, one of which had made eight trips.
        $resolved = $this->stays->resolve($vehicleId, $from);

        // The duration engine gets the log's CLOSED trips on top of the contracts, so shop time the
        // contracts never knew about is charged — still merged, still with rental time subtracted.
        // Open-ended log trips are deliberately absent: a departure nobody logged a return for is a
        // real visit but an unknown duration, and running it to today is how a one-day oil change
        // once invented 93 days of downtime.
        $w = $this->utilization->vehicleWindow($vehicleId, $from, null, $resolved['closed_intervals']);

        $visits       = (int) $resolved['visits'];
        $downSeconds  = (int) $w['maintenance_seconds'];
        $downtimeDays = $downSeconds / 86400;

        // The percentage is measured against the window the car was actually IN SERVICE for, not a
        // flat 30 days — a car that entered service twelve days ago has been available to break down
        // for twelve days, and dividing by thirty would understate it by more than half. For a car in
        // service throughout, the two are the same number.
        $windowSeconds = (int) $w['window_seconds'];
        $downtimePct   = $windowSeconds > 0 ? round($downSeconds / $windowSeconds * 100, 1) : null;

        $visitSeverity    = GarageSeverity::grade($visits, (array) config('garage_intelligence.visits'));
        $downtimeSeverity = GarageSeverity::grade($downtimeDays, (array) config('garage_intelligence.downtime_days'));

        // A car that has never been rented has no in-service anchor and therefore no honest
        // denominator (see FleetUtilizationService's class docblock). Its workshop time so far is
        // new-car onboarding, not operational downtime, and grading it would brand every freshly
        // bought car critical on day one. Report it as pending, graded `normal`, never alerted.
        if (! empty($w['pending_service'])) {
            $visitSeverity = $downtimeSeverity = GarageSeverity::NORMAL;
        }

        return [
            'vehicle_id'          => $vehicleId,
            'window_days'         => $windowDays,
            'window_from'         => $from,
            'pending_service'     => (bool) ($w['pending_service'] ?? false),
            'visits'              => $visits,
            'downtime_seconds'    => $downSeconds,
            'downtime_days'       => round($downtimeDays, 1),
            'downtime_pct'        => $downtimePct,
            'visit_severity'      => $visitSeverity,
            'downtime_severity'   => $downtimeSeverity,
            'severity'            => GarageSeverity::max($visitSeverity, $downtimeSeverity),
            // "In a garage right now" stays the CONTRACT's answer, per the standing ruling that only
            // an OM maintenance contract parks a car — the log's unreturned rows are far too often an
            // OUT whose return was never written down. The log's own opinion is carried beside it so
            // a disagreement is visible rather than silently resolved.
            'currently_in_garage' => (bool) $w['currently_in_shop'],
            'open_in_log'         => (bool) $resolved['in_garage_by_log'],
            // The newest departure ANY source recorded — the log usually knows about a move days
            // before the contract does.
            'last_entry_at'       => $resolved['last_entry_at'] ?? $w['last_entry_at'],
            'stay_sources'        => array_count_values(array_column($resolved['stays'], 'source')),
            'reasons'             => $this->reasons($visits, $visitSeverity, $downtimeDays, $downtimeSeverity, $downtimePct, (bool) $w['currently_in_shop']),
        ];
    }

    /**
     * The plain-sentence "why" behind a grade — the same wording the notification and the vehicle
     * profile show, assembled once. Only bands that are actually raised speak; a car graded normal
     * on a signal says nothing about it rather than reporting a non-finding.
     *
     * @return array<int,string>
     */
    private function reasons(int $visits, string $visitSeverity, float $downtimeDays, string $downtimeSeverity, ?float $pct, bool $inGarage): array
    {
        $days   = $this->windowDays();
        $out    = [];

        if ($visitSeverity !== GarageSeverity::NORMAL) {
            $out[] = $visits . ' ' . ($visits === 1 ? 'garage visit' : 'garage visits') . ' in the last ' . $days . ' days.';
        }
        if ($downtimeSeverity !== GarageSeverity::NORMAL) {
            $out[] = round($downtimeDays, 1) . ' days spent in the garage'
                . ($pct !== null ? ' — ' . $pct . '% of the period' : '') . '.';
        }
        if ($inGarage && $out !== []) {
            $out[] = 'The car is in a garage right now, so this figure is still growing.';
        }

        return $out;
    }

    // ── Evaluation (read → persist → decide → tell) ────────────────────────────────────────────

    /**
     * Re-read ONE car, store the reading, and raise an alert if either signal climbed.
     *
     * Best-effort by contract: this hangs off maintenance transitions, and a car's alerting must
     * never be able to fail a dispatch or block a ticket from closing. Anything that goes wrong is
     * reported and swallowed.
     *
     * @param  bool  $notify  false = refresh the stored reading silently (used by backfills)
     */
    public function evaluate(Vehicle|int $vehicle, bool $notify = true): ?VehicleGarageAlertState
    {
        if (! $this->enabled()) {
            return null;
        }

        $vehicleId = $vehicle instanceof Vehicle ? (int) $vehicle->id : (int) $vehicle;
        if ($vehicleId <= 0) {
            return null;
        }

        try {
            $reading = $this->read($vehicleId);
            $state   = VehicleGarageAlertState::firstOrNew(['vehicle_id' => $vehicleId]);

            $climbed = [];
            foreach (['visit' => 'visit_severity', 'downtime' => 'downtime_severity'] as $signal => $field) {
                $current  = $reading[$field];
                $notified = $state->{'notified_' . $signal . '_severity'} ?? GarageSeverity::NORMAL;

                if ($notify && GarageSeverity::shouldNotify($current, $notified)) {
                    $climbed[] = $signal;
                }
                // Recorded whether or not we notified: a climb is remembered so it is not re-announced,
                // and a fall is remembered so a later climb back can be.
                $state->{'notified_' . $signal . '_severity'} = GarageSeverity::settleTo($current, $notified);
            }

            $state->fill([
                'window_days'         => $reading['window_days'],
                'visits'              => $reading['visits'],
                'downtime_seconds'    => $reading['downtime_seconds'],
                'downtime_pct'        => $reading['downtime_pct'] ?? 0,
                'visit_severity'      => $reading['visit_severity'],
                'downtime_severity'   => $reading['downtime_severity'],
                'severity'            => $reading['severity'],
                'currently_in_garage' => $reading['currently_in_garage'],
                'last_entry_at'       => $reading['last_entry_at'],
                'reasons'             => $reading['reasons'],
                'evaluated_at'        => now(),
            ]);

            if ($climbed !== []) {
                $state->last_notified_at = now();
            }
            $state->vehicle_id = $vehicleId;
            $state->save();

            foreach ($climbed as $signal) {
                $this->announce($vehicleId, $signal, $reading);
            }

            return $state;
        } catch (\Throwable $e) {
            report($e);   // never let alerting break a maintenance transition

            return null;
        }
    }

    /**
     * Re-read many cars in one pass. Used by the daily safety net and after a fleet-wide reconcile.
     *
     * Deliberately a loop of scoped evaluations rather than one fleet query: each car is three small
     * indexed lookups, the escalation rule is per-car anyway, and a partial failure costs one car
     * rather than the whole sweep. The alternative — a fleet report plus a bespoke second grading
     * path — would be the duplicate definition this design exists to avoid.
     *
     * @param  array<int>|null  $vehicleIds  null = every vehicle still in the fleet
     * @return array{evaluated:int, attention:int, notified:int}
     */
    public function sweep(?array $vehicleIds = null, bool $notify = true): array
    {
        if (! $this->enabled()) {
            return ['evaluated' => 0, 'attention' => 0, 'notified' => 0];
        }

        $ids = $vehicleIds ?? Vehicle::query()
            ->whereNotIn('status', ['disposed', 'sold', 'returned'])
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        $evaluated = 0;
        $attention = 0;
        $notified  = 0;

        foreach ($ids as $id) {
            $before = VehicleGarageAlertState::where('vehicle_id', $id)->value('last_notified_at');
            $state  = $this->evaluate($id, $notify);
            if (! $state) {
                continue;
            }
            $evaluated++;
            if ($state->severity !== GarageSeverity::NORMAL) {
                $attention++;
            }
            if ($state->last_notified_at && (string) $state->last_notified_at !== (string) $before) {
                $notified++;
            }
        }

        return ['evaluated' => $evaluated, 'attention' => $attention, 'notified' => $notified];
    }

    // ── Telling somebody ───────────────────────────────────────────────────────────────────────

    /**
     * Raise ONE signal's alert through the existing notification system — the same FleetAlert
     * payload, the same `notifications` table, the same bell, the same deep-link discipline every
     * other alert in the fleet uses. No second notification framework, no new channel.
     *
     * The dedup key carries the severity AND the date it was reached, so the row is idempotent for
     * the climb that produced it while still allowing the same level to be announced again months
     * later if the car recovers and relapses.
     */
    private function announce(int $vehicleId, string $signal, array $reading): void
    {
        $vehicle = Vehicle::find($vehicleId);
        if (! $vehicle) {
            return;
        }

        $recipients = $this->recipients();
        if ($recipients->isEmpty()) {
            // Silence with a trace, never a broadcast to whoever happens to hold a broad permission.
            report(new \RuntimeException(
                "Garage Intelligence: {$signal} alert for vehicle {$vehicleId} had no configured recipient."
            ));

            return;
        }

        $severity = $signal === 'visit' ? $reading['visit_severity'] : $reading['downtime_severity'];
        $label    = $this->label($vehicle);
        $days     = $reading['window_days'];

        if ($signal === 'visit') {
            $title = $label . ' is going into the garage too often';
            $body  = 'This car has entered the garage ' . $reading['visits'] . ' times in the last ' . $days . ' days.';
        } else {
            $title = $label . ' is spending too long in the garage';
            $body  = 'This car has spent ' . $reading['downtime_days'] . ' days in the garage during the last ' . $days . ' days'
                . ($reading['downtime_pct'] !== null ? ' — ' . $reading['downtime_pct'] . '% of the period' : '') . '.';
        }

        if ($reading['currently_in_garage']) {
            $body .= ' It is in a garage right now.';
        }

        $payload = [
            'type'     => $signal === 'visit' ? 'garage_visit_frequency' : 'garage_downtime',
            'category' => 'maintenance',
            'severity' => GarageSeverity::alertSeverity($severity),
            'title'    => $title,
            'body'     => $body,
            'url'      => '/vehicles/' . $vehicleId,
            'key'      => ($signal === 'visit' ? 'garage_visits:' : 'garage_downtime:')
                . $vehicleId . ':' . $severity . ':' . now()->toDateString(),
            'icon'     => 'wrench',
            'meta'     => [
                'vehicle_id'          => $vehicleId,
                'plate'               => $vehicle->plate_no,
                'signal'              => $signal,
                'attention_level'     => $severity,       // normal|warning|high|critical — the real grade
                'window_days'         => $days,
                'garage_visits'       => $reading['visits'],
                'downtime_days'       => $reading['downtime_days'],
                'downtime_pct'        => $reading['downtime_pct'],
                'currently_in_garage' => $reading['currently_in_garage'],
                'last_entry_at'       => $reading['last_entry_at'],
                'reasons'             => $reading['reasons'],
            ],
        ];

        foreach ($recipients as $user) {
            $this->notifier->notifyUser($user, $payload);
        }
    }

    /**
     * WHO hears about it. Same doctrine as every other recipient list in this application: a named
     * allow-list wins outright, and the fallback is a permission NARROWED BY ROLE — never a bare
     * permission, because a standing monthly-pattern alert fanned out to everyone holding
     * `maintenance.manage` is how a fleet learns to ignore the bell.
     * See [[checkpoint-reminder-recipient-rules]].
     *
     * @return Collection<int,User>
     */
    public function recipients(): Collection
    {
        $ids = (array) config('garage_intelligence.recipients.user_ids', []);
        if (! empty($ids)) {
            return $this->activeOnly(User::query()->whereIn('id', $ids))->get();
        }

        $permission = (string) config('garage_intelligence.recipients.fallback_permission', 'maintenance.manage');
        $roles      = array_filter((array) config('garage_intelligence.recipients.fallback_roles', []));

        if (empty($roles)) {
            return collect();   // no narrowing configured → silence, not a broadcast
        }

        return $this->activeOnly(User::permission($permission)->role($roles))->get();
    }

    private function activeOnly($query)
    {
        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'status')) {
            $query->where('status', 'active');
        }

        return $query;
    }

    /** "Make Model (PLATE)" — the label every other maintenance alert uses. */
    private function label(Vehicle $vehicle): string
    {
        $name = trim($vehicle->make . ' ' . $vehicle->model) ?: 'Vehicle';

        return $vehicle->plate_no ? $name . ' (' . $vehicle->plate_no . ')' : $name;
    }
}
