<?php

namespace App\Services\Garage;

use App\Models\Maintenance;
use App\Services\FleetUtilizationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "How many times has this car actually gone into a garage, and when?" — answered from EVERY place
 * the fleet records a garage movement, not just one of them.
 *
 * ── WHY THIS EXISTS ────────────────────────────────────────────────────────────────────────────
 *
 * The fleet writes a garage movement down in three different places, and no one of them sees all of
 * them. On the live fleet, over a 30-day window, 126 cars had garage activity — and reading only the
 * OfficeManager contracts (which is what the Fleet Utilization report does, correctly, for its own
 * purposes) found 108 of them. Seventeen cars were in the Google Sheet log and nowhere else; two were
 * website tickets and nowhere else. One car (91485) made EIGHT garage trips and was completely
 * invisible. An alert that cannot see a car cannot warn anybody about it.
 *
 *   1. THE GOOGLE SHEET WORKSHOP LOG  (`maintenances`, origin `sheet`)
 *      The largest source — 125 of the 126 cars. It writes its own OUT → Follow up → IN cycle every
 *      time the car physically moves, so it is also the FINEST: the trip key is `(out_date, garage)`,
 *      both halves required, because a car really does leave for two garages on one day.
 *      @see [[contract-is-not-a-garage-trip]]
 *
 *   2. THE WEBSITE'S OWN WORKFLOW TICKETS  (`maintenances`, origin `manual`)
 *      Same table, same shape, so they group by the same trip key. A ticket that has a vendor but no
 *      free-text garage borrows the vendor's name, so it keys against the sheet rows for the same
 *      trip instead of splitting into a phantom second one.
 *
 *   3. THE OFFICEMANAGER TYPE-'U' MAINTENANCE CONTRACTS  (`contracts`)
 *      The accounting fact: the car left the fleet's hands on a date and came back on another. It is
 *      the COARSEST — one contract held 18 real trips across 10 garages on vehicle 36047 — so it is
 *      used as a FALLBACK: a contract contributes a stay of its own only when no finer trip evidence
 *      sits inside its window. That is what stops the same physical stay being counted twice.
 *
 * ── DURATION IS NOT COUNTED THE SAME WAY AS DEPARTURES ─────────────────────────────────────────
 *
 * A departure and a duration have different evidence requirements, and conflating them is how this
 * fleet's original downtime numbers went wrong (a one-day oil change left OPEN in the log for 93 days
 * once invented three months of fake downtime).
 *
 *   • A DEPARTURE is certain the moment it is written down. Even if nobody ever logged the return,
 *     the car definitely went. So an unreturned trip still counts as a visit.
 *   • A DURATION needs both ends. 9.8% of recent sheet trips have no logged return, so an unreturned
 *     trip contributes NO time — it is reported as `open_ended`, not run to today. Only the OM
 *     contract may hold a stay open against the clock, which is the standing ruling that only a
 *     contract parks a car. @see [[fleet-test-countdown-tab]]
 *
 * A row with no garage named whose stage is a road test (`Test` / `Under Test`) is a test drive, not
 * a departure to a garage, and never becomes a trip.
 */
class GarageStayResolver
{
    /** Sheet/ticket stages that describe a road test rather than a trip to a garage. */
    private const ROAD_TEST_STAGES = ['Test', 'Under Test'];

    /**
     * Every distinct garage stay this car made that touches the window.
     *
     * @return array{
     *   visits:int,
     *   stays:array<int,array{start:string,end:?string,garage:?string,source:string,open_ended:bool}>,
     *   closed_intervals:array<int,array{0:string,1:string}>,
     *   last_entry_at:?string,
     *   in_garage_by_log:bool
     * }
     */
    public function resolve(int $vehicleId, string $from, ?string $to = null): array
    {
        $today  = Carbon::today();
        $winEnd = $to ? Carbon::parse($to)->startOfDay() : $today->copy();
        if ($winEnd->gt($today)) {
            $winEnd = $today->copy();
        }
        $winStart = Carbon::parse($from)->startOfDay();

        $trips     = $this->logTrips($vehicleId, $winStart);
        $contracts = $this->contractStays($vehicleId, $winStart, $winEnd);

        // A contract speaks only where the log is silent. If ANY trip departed inside the contract's
        // window, that contract's stay is already described — in finer detail — by those trips.
        $stays = $trips;
        foreach ($contracts as $c) {
            $end = $c['end'] ?? $winEnd->toDateString();
            $covered = false;
            foreach ($trips as $t) {
                if ($t['start'] >= $c['start'] && $t['start'] <= $end) {
                    $covered = true;
                    break;
                }
            }
            if (! $covered) {
                $stays[] = $c;
            }
        }

        usort($stays, fn ($a, $b) => $a['start'] <=> $b['start']);

        // Only stays that actually touch the window are this window's visits. An OPEN stay runs to
        // today, so it touches any window that has not closed before it began.
        $lo = $winStart->toDateString();
        $hi = $winEnd->toDateString();
        $inWindow = array_values(array_filter($stays, function ($s) use ($lo, $hi, $today) {
            $end = $s['end'] ?? $today->toDateString();

            return $end >= $lo && $s['start'] <= $hi;
        }));

        // Extra time to charge, for the DURATION engine to merge on top of the contracts it already
        // holds. Two exclusions, both deliberate:
        //
        //   • CONTRACT stays are omitted. The engine loads those itself, from `out_time`/`in_time`,
        //     to the minute. Handing them back here as whole days would stretch a stay that ended at
        //     09:00 to midnight and quietly add up to a day to every contract-only car.
        //   • OPEN-ENDED log trips are omitted. A departure with no logged return is a real visit and
        //     an unknown duration — see the class docblock.
        $closed = [];
        foreach ($inWindow as $s) {
            if ($s['source'] !== 'contract' && $s['end'] !== null) {
                // Log rows carry a DATE and no clock, so a same-day trip is charged the working day
                // it plainly was, not zero seconds.
                $closed[] = [$s['start'] . ' 00:00:00', $s['end'] . ' 23:59:59'];
            }
        }

        $lastEntry = $inWindow ? end($inWindow)['start'] : null;

        return [
            'visits'           => count($inWindow),
            'stays'            => $inWindow,
            'closed_intervals' => $closed,
            'last_entry_at'    => $lastEntry,
            // The log's own opinion on whether the car is still out. Reported, never used to hold the
            // clock open — that remains the contract's job alone.
            'in_garage_by_log' => (bool) array_filter($inWindow, fn ($s) => $s['end'] === null),
        ];
    }

    /**
     * The workshop log's own OUT → IN cycles, keyed `(out_date, garage)` — the sheet rows and the
     * website's workflow tickets together, because they live in one table and describe one journey.
     *
     * Read from before the window so a stay that STARTED earlier and is still running is seen; the
     * caller clips to the window afterwards.
     *
     * @return array<int,array{start:string,end:?string,garage:?string,source:string,open_ended:bool}>
     */
    private function logTrips(int $vehicleId, Carbon $winStart): array
    {
        $rows = DB::table('maintenances')
            ->leftJoin('vendors', 'maintenances.vendor_id', '=', 'vendors.id')
            ->where('maintenances.vehicle_id', $vehicleId)
            ->whereIn('maintenances.origin', Maintenance::WORKSHOP_LOG_ORIGINS)
            ->whereNull('maintenances.deleted_at')
            ->whereNotNull('maintenances.out_date')
            // Look back beyond the window so a long-running stay is not cut in half.
            ->whereDate('maintenances.out_date', '>=', $winStart->copy()->subDays(180)->toDateString())
            ->get([
                'maintenances.out_date',
                'maintenances.garage',
                'maintenances.actual_in_date',
                'maintenances.event_status',
                'maintenances.origin',
                'vendors.name as vendor_name',
            ]);

        $trips = [];
        foreach ($rows as $r) {
            // A ticket carries its garage as a vendor relation rather than free text; borrowing the
            // vendor's name keys it against the sheet rows for the SAME trip instead of splitting it.
            $garage = trim((string) ($r->garage ?? '')) ?: trim((string) ($r->vendor_name ?? ''));
            $stage  = trim((string) ($r->event_status ?? ''));

            if ($garage === '' && in_array($stage, self::ROAD_TEST_STAGES, true)) {
                continue;   // a road test is not a departure to a garage
            }

            $start = substr((string) $r->out_date, 0, 10);
            $key   = $start . '|' . mb_strtolower($garage);

            if (! isset($trips[$key])) {
                $trips[$key] = [
                    'start'      => $start,
                    'end'        => null,
                    'garage'     => $garage ?: null,
                    'source'     => $r->origin === Maintenance::ORIGIN_MANUAL ? 'ticket' : 'sheet',
                    'open_ended' => true,
                ];
            }

            // The trip's return is the LATEST return any of its rows carries.
            if ($r->actual_in_date) {
                $in = substr((string) $r->actual_in_date, 0, 10);
                if ($in >= $start && ($trips[$key]['end'] === null || $in > $trips[$key]['end'])) {
                    $trips[$key]['end']        = $in;
                    $trips[$key]['open_ended'] = false;
                }
            }
        }

        return array_values($trips);
    }

    /**
     * OM type-'U' maintenance contracts — the coarse fallback, used only where the log said nothing.
     *
     * @return array<int,array{start:string,end:?string,garage:?string,source:string,open_ended:bool}>
     */
    private function contractStays(int $vehicleId, Carbon $winStart, Carbon $winEnd): array
    {
        $rows = DB::table('contracts')
            ->where('contract_type', 'U')
            ->where('vehicle_id', $vehicleId)
            ->whereNotNull('out_date')
            ->whereDate('out_date', '<=', $winEnd->toDateString())
            ->where(fn ($w) => $w->whereNull('in_date')->orWhereDate('in_date', '>=', $winStart->toDateString()))
            ->get(['out_date', 'in_date']);

        $out = [];
        foreach ($rows as $r) {
            $start = substr((string) $r->out_date, 0, 10);
            $end   = $r->in_date ? substr((string) $r->in_date, 0, 10) : null;
            if ($end !== null && $end < $start) {
                continue;   // came back before it left — a typo, not a stay
            }
            $out[] = [
                'start'      => $start,
                'end'        => $end,
                'garage'     => null,       // OM contracts carry no garage — see [[in-the-garage-board]]
                'source'     => 'contract',
                'open_ended' => $end === null,
            ];
        }

        return $out;
    }

    /** Day index helper shared with the canonical engine, so window arithmetic cannot drift. */
    public static function dayNum(Carbon $d): int
    {
        return FleetUtilizationService::dayNum($d);
    }
}
