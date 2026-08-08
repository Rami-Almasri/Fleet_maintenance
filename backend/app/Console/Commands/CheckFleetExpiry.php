<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use App\Services\NotificationScanner;
use Illuminate\Console\Command;

/**
 * Expired-document watch (runs daily) — every car still in the rentable pool whose registration
 * (Mulkiya) or insurance has lapsed gets surfaced to the people who can renew or retire it.
 *
 * This command does NOT change the vehicle. Two reasons:
 *
 *  1. Renting is ALREADY blocked on expired documents, live, at the moment of the rental —
 *     FleetValidationService::rentBlockers() refuses the car by reading the registration dates
 *     directly. A nightly status flip would add nothing to that guard and would only ever be
 *     hours out of date.
 *  2. The status column is owned by om:sync + the Status-sheet overlay. Writing it here would put
 *     a third writer into the fight and be reverted at 03:00 the next morning anyway.
 *
 * So the gap this closes is VISIBILITY, not enforcement: the car is already un-rentable, but until
 * now nobody was told it needed a renewal.
 *
 * HISTORY — the previous version was a permanent no-op and is why this was rewritten. It queried
 * `status = 'active'`, a value dropped from the vocabulary long ago (0 rows have it; the live set is
 * Vehicle::OM_STATUS), and it wrote `status = 'for_sale'`, which is not a status slug at all —
 * for_sale is a separate BOOLEAN column. So it matched nothing, and had it matched it would have
 * written an invalid status. Raising the for_sale flag instead was considered and rejected: an
 * expired policy does not mean the car is being sold, and InspectionEngineService deliberately
 * SKIPS for-sale cars, so flagging one would silently stop its inspections too.
 */
class CheckFleetExpiry extends Command
{
    protected $signature = 'fleet:check-expiry {--dry-run : list the cars, notify nobody}';

    protected $description = 'Alert on cars still in the rentable pool whose registration or insurance has expired';

    /** The statuses that mean "this car is in service" — the only ones an expiry can hurt. */
    public const IN_SERVICE_STATUSES = ['ready', 'rented'];

    /** Fleet managers renew the paperwork; controllers decide whether to retire the car instead. */
    private const FLEET = 'vehicles.manage';
    private const CONTROLLERS = 'maintenance.manage';

    public function handle(NotificationScanner $notifier): int
    {
        $dry = (bool) $this->option('dry-run');
        $today = now()->startOfDay()->toDateString();

        $expired = Vehicle::whereIn('status', self::IN_SERVICE_STATUSES)
            // A car being sold is not renewed, it is handed over — an expiring policy on it is
            // the expected end of the policy, not a problem. `status = 'sold'` only lands once
            // OfficeManager clears the paperwork, so the manual for_sale flag is the signal that
            // arrives in time to stop the chase. Mirrors InspectionEngineService's for-sale skip.
            ->where(fn ($q) => $q->where('for_sale', false)->orWhereNull('for_sale'))
            ->whereHas('registration', function ($q) use ($today) {
                $q->where(function ($q2) use ($today) {
                    $q2->where('expiry_date', '<', $today)
                       ->orWhere('insurance_expiry', '<', $today);
                });
            })
            ->with('registration')
            ->orderBy('plate_no')
            ->get();

        $rows = [];
        $pushed = 0;

        foreach ($expired as $vehicle) {
            $reg = $vehicle->registration;
            $why = [];
            if ($reg?->expiry_date && $reg->expiry_date->isPast()) {
                $why[] = 'registration ' . $reg->expiry_date->toDateString();
            }
            if ($reg?->insurance_expiry && $reg->insurance_expiry->isPast()) {
                $why[] = 'insurance ' . $reg->insurance_expiry->toDateString();
            }

            $car = trim(($vehicle->make ?? '') . ' ' . ($vehicle->model ?? '')) ?: ('Vehicle #' . $vehicle->id);
            $plate = $vehicle->plate_no ?: $car;
            $rows[] = [$plate, $car, $vehicle->status, implode(', ', $why)];

            if ($dry) {
                continue;
            }

            $pushed += $notifier->notifyByAnyPermission([self::FLEET, self::CONTROLLERS], [
                'type'     => 'vehicle_docs_expired',
                'category' => 'fleet',
                // A rented car with lapsed cover is materially worse than one sitting ready.
                'severity' => $vehicle->status === 'rented' ? 'critical' : 'warning',
                'title'    => '📄 Documents expired · ' . $plate,
                'body'     => $car . ' (' . $plate . ') is ' . $vehicle->status
                    . ' with expired ' . implode(' and ', $why)
                    . '. It cannot be rented out until renewed.',
                'url'  => '/vehicles/' . $vehicle->id,
                // Re-fires once a day while it stays expired; stops the day the papers are renewed.
                'key'  => 'vehicle_docs_expired:' . $vehicle->id . ':' . $today,
                'icon' => 'document',
                'meta' => ['vehicle_id' => $vehicle->id, 'plate' => $vehicle->plate_no, 'status' => $vehicle->status],
            ]);
        }

        if ($rows) {
            $this->table(['Plate', 'Car', 'Status', 'Expired'], $rows);
        }

        $this->info(sprintf(
            '%sExpired-document watch — %d in-service car(s) with lapsed papers, %d alert(s) %s.',
            $dry ? '[dry-run] ' : '',
            $expired->count(),
            $pushed,
            $dry ? 'suppressed' : 'pushed'
        ));

        return self::SUCCESS;
    }
}
