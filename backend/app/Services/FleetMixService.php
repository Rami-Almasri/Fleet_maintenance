<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * FleetMixService — how the fleet was split between Available / Reserved / Rented / In Maintenance
 * on any given DAY, and therefore whether each of those four is up or down on last month.
 *
 * EVIDENCE CLASS: Derived (D). Produces E-FLEET-MIX. Consumes contracts (F) + vehicles (F).
 *
 * WHY THIS EXISTS
 * The Vehicles page states four live counts. "17 in maintenance" answers *what is true now*; it
 * cannot answer *is that worse than it was*. Nothing in this database snapshots the fleet daily, so
 * the only honest way to state a trend is to REPLAY it from the paperwork: a car was on rent on a
 * date if a rental contract covered that date, in the garage if a maintenance contract did, and so
 * on. Contracts carry out_date / in_date, so every past day is reconstructable exactly.
 *
 * WHAT IS AND IS NOT DERIVED
 * The four counts on a PAST date come from contracts alone. They deliberately do NOT reproduce the
 * live `available` rule in VehicleResource, which also weighs today's condition grade and lifecycle
 * status — neither of which is historised, so applying them to a past date would be invention. To
 * keep the comparison like-for-like, TODAY is measured by the same contract-only rule, and the
 * delta is that rule against itself a month apart. The big number on the tile stays the live count;
 * this service only ever supplies the trend beside it. Both are labelled as what they are.
 *
 * POPULATION
 * Cars we still own (status not sold / disposed), on both dates. A car sold last week would
 * otherwise show up as a month-on-month "loss" of an available car, which is a change of fleet, not
 * a change of availability.
 *
 * @see \App\Http\Controllers\VehicleController::fleetPulse()
 */
class FleetMixService
{
    /** Contract type → the state it puts a car in for the days it covers. */
    private const TYPE_STATE = ['C' => 'rented', 'R' => 'reserved', 'U' => 'maint'];

    /** A car under two contracts at once resolves to the first of these that applies. */
    private const PRECEDENCE = ['maint', 'rented', 'reserved'];

    /** How many monthly points the tile sparklines carry (including today). */
    private const TREND_POINTS = 12;

    /**
     * The whole KPI strip's trend layer in one payload:
     *   counts   — the four states today, by the contract-only rule (the comparable baseline)
     *   previous — the same four on the same day last month
     *   delta    — percentage change per state, null when last month's figure was zero
     *   series   — 12 monthly points per state, oldest first, for the tile sparklines
     *   as_of / compared_to — the two dates, so the page can say exactly what it compared
     */
    public function pulse(?Carbon $asOf = null): array
    {
        $asOf = ($asOf ?: Carbon::today())->startOfDay();

        return Cache::remember(
            'fleet-mix:pulse:' . $asOf->toDateString(),
            now()->addMinutes(15),
            function () use ($asOf) {
                // The 12 monthly sample dates, oldest first, ending on $asOf. subMonths() clamps
                // (31 Mar - 1 month = 28/29 Feb), which is the behaviour we want: every point is a
                // real calendar day.
                $dates = collect(range(self::TREND_POINTS - 1, 0))
                    ->map(fn (int $back) => $asOf->copy()->subMonths($back))
                    ->values();

                $fleet     = $this->ownedFleetIds();
                $contracts = $this->contractsCovering($dates->first(), $asOf);

                $series = $dates->map(fn (Carbon $d) => [
                    'date'   => $d->toDateString(),
                    'counts' => $this->countsOn($d, $fleet, $contracts),
                ]);

                $current  = $series->last()['counts'];
                $previous = $series->slice(-2, 1)->first()['counts'] ?? null;

                return [
                    'as_of'        => $asOf->toDateString(),
                    'compared_to'  => $dates->slice(-2, 1)->first()?->toDateString(),
                    'fleet_size'   => count($fleet),
                    'counts'       => $current,
                    'previous'     => $previous,
                    'delta'        => $this->deltas($current, $previous),
                    'series'       => collect(array_keys($current))
                        ->mapWithKeys(fn (string $state) => [
                            $state => $series->map(fn (array $p) => $p['counts'][$state])->all(),
                        ])->all(),
                    // Said in the payload, not only in this docblock: whoever renders the trend is
                    // obliged to tell the reader what produced it.
                    'basis'        => 'contracts',
                ];
            }
        );
    }

    /** Cars we still own — the population both dates are measured against. */
    private function ownedFleetIds(): array
    {
        return DB::table('vehicles')
            ->whereNull('deleted_at')
            ->whereNotIn('status', ['sold', 'disposed'])
            ->pluck('id')
            ->all();
    }

    /**
     * Every rental / booking / maintenance contract that could cover any day in the window, as
     * lightweight rows. One query feeds all 12 sample dates — the alternative was 36.
     */
    private function contractsCovering(Carbon $from, Carbon $to): \Illuminate\Support\Collection
    {
        return DB::table('contracts')
            ->select('vehicle_id', 'contract_type', 'out_date', 'in_date', 'state')
            ->whereIn('contract_type', array_keys(self::TYPE_STATE))
            ->whereNotNull('vehicle_id')
            ->whereNotNull('out_date')
            ->whereDate('out_date', '<=', $to)
            ->where(function ($q) use ($from) {
                $q->whereNull('in_date')->orWhereDate('in_date', '>=', $from);
            })
            ->get();
    }

    /**
     * The four counts on one day. A car with no contract covering that day counts as available —
     * that is the whole meaning of the word here, and it is why `available` is a subtraction rather
     * than a query of its own.
     */
    private function countsOn(Carbon $day, array $fleet, \Illuminate\Support\Collection $contracts): array
    {
        $owned  = array_flip($fleet);
        $states = [];

        foreach ($contracts as $c) {
            if (! isset($owned[$c->vehicle_id])) {
                continue;
            }
            if (! $this->coversDay($c, $day)) {
                continue;
            }
            $state = self::TYPE_STATE[$c->contract_type] ?? null;
            if (! $state) {
                continue;
            }
            $held = $states[$c->vehicle_id] ?? null;
            if ($held === null || array_search($state, self::PRECEDENCE, true) < array_search($held, self::PRECEDENCE, true)) {
                $states[$c->vehicle_id] = $state;
            }
        }

        $counts = array_count_values($states);

        return [
            'available' => count($fleet) - count($states),
            'reserved'  => $counts['reserved'] ?? 0,
            'rented'    => $counts['rented'] ?? 0,
            'maint'     => $counts['maint'] ?? 0,
        ];
    }

    /**
     * Did this contract cover that day?
     *
     * An empty in_date means "still out" and is only believed while the contract is genuinely open.
     * A CLOSED contract that never got its return date written is a data fault, not an open-ended
     * rental, and treating it as one would leave cars permanently "on rent" in every past month.
     */
    private function coversDay(object $c, Carbon $day): bool
    {
        $out = Carbon::parse($c->out_date)->startOfDay();
        if ($out->gt($day)) {
            return false;
        }
        if ($c->in_date === null) {
            return $c->state === 'open';
        }

        return Carbon::parse($c->in_date)->startOfDay()->gte($day);
    }

    /** Percentage change per state. Null where last month was zero — "up from nothing" has no percentage. */
    private function deltas(array $current, ?array $previous): array
    {
        if (! $previous) {
            return [];
        }

        $out = [];
        foreach ($current as $state => $now) {
            $was = $previous[$state] ?? 0;
            $out[$state] = $was > 0 ? round((($now - $was) / $was) * 100) : null;
        }

        return $out;
    }
}
