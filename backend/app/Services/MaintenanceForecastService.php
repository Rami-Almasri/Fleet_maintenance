<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;

/**
 * Preventive-maintenance forecasting — "this car will need service SOON".
 *
 * Built on the strict km service rule (Vehicle::serviceStatus) but adds the missing PREDICTIVE layer:
 * a projected due DATE derived from the car's own real-world usage rate (km/day, measured from its
 * contract odometer readings). A car counts as approaching service when it is within EITHER threshold —
 * ≤ NEAR_DUE_KM kilometres of the interval OR ≤ NEAR_DUE_DAYS of the projected date — so a high-usage
 * car on a long rental is caught by distance and a low-usage car is caught by time. Whichever comes
 * first wins, which is exactly why a service is never "missed" because the car was out earning.
 *
 * Single source of truth for BOTH surfaces: the ticket-drawer forecast widget and the "service due
 * soon" notification detector call forecast(), so the number on screen and the alert always agree.
 */
class MaintenanceForecastService
{
    /** "Soon" thresholds — approaching service when within EITHER of these of the interval / due date. */
    public const NEAR_DUE_KM   = 1000; // km before the interval
    public const NEAR_DUE_DAYS = 7;    // days before the projected due date

    /** Prefer the last ~6 months of readings for the usage rate (recent behaviour, not the whole life). */
    private const RATE_WINDOW_DAYS = 180;

    /**
     * Average daily distance (km/day) from the car's recent dated odometer readings (contract pickups
     * and returns — the richest time series we have). Null when there isn't enough movement to measure,
     * so we never fabricate a projection out of thin air.
     */
    public function usageRate(Vehicle $vehicle): ?float
    {
        $points = collect();
        Contract::where('vehicle_id', $vehicle->id)
            ->get(['out_date', 'out_milage', 'in_date', 'in_milage'])
            ->each(function ($c) use ($points) {
                if ($c->out_milage && $c->out_date) $points->push(['d' => $c->out_date, 'v' => (int) $c->out_milage]);
                if ($c->in_milage && $c->in_date)   $points->push(['d' => $c->in_date,  'v' => (int) $c->in_milage]);
            });

        $points = $points->filter(fn ($p) => $p['v'] > 0)->sortBy(fn ($p) => $p['d']->timestamp)->values();
        if ($points->count() < 2) {
            return null;
        }

        // Use the recent window when it has enough points, else fall back to the full span.
        $cutoff = Carbon::now()->subDays(self::RATE_WINDOW_DAYS);
        $recent = $points->filter(fn ($p) => $p['d']->greaterThanOrEqualTo($cutoff))->values();
        $use    = $recent->count() >= 2 ? $recent : $points;

        $first = $use->first();
        $last  = $use->last();
        $days  = $first['d']->diffInDays($last['d']);
        $km    = $last['v'] - $first['v'];

        return ($days >= 1 && $km > 0) ? round($km / $days, 1) : null;
    }

    /**
     * How many ACTIVE cars are approaching service right now (forecast status = due_soon) — the count
     * behind the Maintenance Pulse "Service due soon" KPI and a companion to the bell alerts. Uses the
     * same cheap pre-gate as the detector so only cars plausibly within reach pay for the usage query.
     */
    public function dueSoonCount(): int
    {
        $count = 0;
        Vehicle::whereIn('status', Vehicle::ACTIVE_STATUSES)
            ->orderBy('id')->chunkById(500, function ($vehicles) use (&$count) {
                foreach ($vehicles as $v) {
                    $s = $v->serviceStatus();
                    if ($s['status'] !== 'ok' || $s['remaining'] === null || $s['remaining'] > 3000) {
                        continue;
                    }
                    if ($this->forecast($v)['status'] === 'due_soon') {
                        $count++;
                    }
                }
            });

        return $count;
    }

    /**
     * Full service forecast for one vehicle. Combines the km rule (already-overdue / remaining km) with
     * a projected due date from the usage rate, and classifies the car:
     *   overdue   — the interval is already crossed
     *   due_soon  — within NEAR_DUE_KM km OR NEAR_DUE_DAYS days of service (the preventive catch)
     *   ok        — serviced recently, nothing due
     *   no_data   — missing the baseline / interval / odometer to judge (never guessed)
     *
     * @return array{status:string,current:?int,baseline:?int,interval:?int,remaining_km:?int,overdue_km:?int,usage_rate:?float,days_to_due:?int,projected_date:?string,due_soon:bool,overdue:bool}
     */
    public function forecast(Vehicle $vehicle): array
    {
        $s         = $vehicle->serviceStatus();
        $remaining = $s['remaining'];              // interval − distance; negative once overdue
        $overdue   = $s['status'] === 'service_due';
        $noData    = $s['status'] === 'no_data';

        // Project a date only when we can measure how fast the car is being driven.
        $rate = $noData ? null : $this->usageRate($vehicle);
        $daysToDue = null;
        $projected = null;
        if ($rate && $rate > 0 && $remaining !== null) {
            $daysToDue = (int) ceil($remaining / $rate);        // negative when already overdue
            $projected = Carbon::now()->addDays(max(0, $daysToDue));
        }

        $dueSoon = ! $overdue && ! $noData && (
            ($remaining !== null && $remaining <= self::NEAR_DUE_KM) ||
            ($daysToDue !== null && $daysToDue <= self::NEAR_DUE_DAYS)
        );

        $status = $noData ? 'no_data' : ($overdue ? 'overdue' : ($dueSoon ? 'due_soon' : 'ok'));

        return [
            'status'         => $status,
            'current'        => $s['current'],
            'baseline'       => $s['baseline'],
            'interval'       => $s['interval'],
            'remaining_km'   => $remaining,
            'overdue_km'     => $s['overdue_km'],
            'usage_rate'     => $rate,
            'days_to_due'    => $daysToDue,
            'projected_date' => optional($projected)->toDateString(),
            'due_soon'       => $dueSoon,
            'overdue'        => $overdue,
        ];
    }
}
