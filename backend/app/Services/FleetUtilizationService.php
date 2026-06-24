<?php

namespace App\Services;

use App\Models\Maintenance;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Fleet Utilization — for every car, how its calendar time splits between earning, in the
 * workshop, and sitting idle, over an optional window:
 *
 *   • Days owned        — purchase_date → today (or the window), the denominator for everything
 *   • Days rented       — union of its rental ('C') contract periods (overlaps merged once)
 *   • Days in maintenance — CLOSED workshop visits that fall OUTSIDE any rental (true unpaid downtime);
 *                           a shop day inside an active rental is paid by the customer, so it counts
 *                           as rented (contract-based priority), with the overlap surfaced separately
 *   • Days idle         — owned − (rented ∪ maintenance): available but earning nothing
 *
 * Honesty rules (data is the source of truth — no guessed durations):
 *   - Maintenance days come ONLY from visits that have BOTH an out_date and an actual_in_date.
 *     ~70% of workshop rows are an "OUT" with no recorded return; treating those as "in the shop
 *     until today" would invent months of fake downtime, so they are excluded from the day count.
 *   - Overlapping rentals or overlapping workshop rows are merged so a day is never counted twice.
 *   - Open rentals (out, no in — a car on rent right now) run to today; there are only a handful.
 *
 * Performance anchor (In-Service Date, not purchase): every utilization/downtime/idle percentage is
 * measured from the car's FIRST rental ('C') contract — see ServiceWindowService — NOT from
 * purchase_date. The purchase→first-rental gap is new-car onboarding (NEW-CAR prep, registration),
 * not operational downtime, so counting it would brand a freshly-bought car as ~100% "in maintenance".
 * purchase_date is still returned as `owned_since` / `days_owned` (metadata) so the dead-capital gap
 * stays visible. A car bought but never rented has no anchor → `pending_service` (Pending / Onboarding),
 * reported with the label instead of a skewed 0%.
 */
class FleetUtilizationService
{
    public function __construct(private ServiceWindowService $serviceWindow) {}

    /**
     * @param  array<int,string>|null  $statuses  vehicle statuses to include; null/empty = every status
     * @return array{cars: array<int,array<string,mixed>>, summary: array<string,mixed>, window: array<string,mixed>}
     */
    public function report(?string $from = null, ?string $to = null, ?array $statuses = null): array
    {
        $today  = Carbon::today();
        $winEnd = $to ? Carbon::parse($to)->startOfDay() : $today->copy();
        if ($winEnd->gt($today)) {
            $winEnd = $today->copy();
        }
        $winStart = $from ? Carbon::parse($from)->startOfDay() : null;   // null = each car's whole life
        $hi = $this->dayNum($winEnd);
        $loBase = $winStart ? $this->dayNum($winStart) : null;
        $todayNum = $this->dayNum($today);

        $vq = DB::table('vehicles')
            ->select('id', 'code', 'plate_no', 'make', 'model', 'year', 'status', 'purchase_date', 'day_rent_value', 'operational_status');
        if (! empty($statuses)) {
            $vq->whereIn('status', $statuses);
        }
        $vehicles = $vq->get()->keyBy('id');
        $ids = $vehicles->keys()->all();
        if (empty($ids)) {
            return [
                'cars'           => [],
                'summary'        => $this->summarise([]),
                'window'         => $this->windowMeta($winStart, $winEnd),
                'status_options' => $this->statusOptions(),
                'statuses'       => array_values($statuses ?? []),
            ];
        }

        $rentByVeh = $this->rentalIntervals($ids, $winStart, $winEnd);
        $maintByVeh = $this->maintenanceIntervals($ids, $winStart, $winEnd);
        $inServiceMap = $this->serviceWindow->inServiceDates($ids);   // vehicle_id => first-rental 'Y-m-d'

        $cars = [];
        foreach ($vehicles as $v) {
            $rate = (float) ($v->day_rent_value ?: 0);
            // Owned-since (purchase) is METADATA only — the dead-capital gap, never the % denominator.
            $purchaseNum = $v->purchase_date ? $this->dayNum(Carbon::parse($v->purchase_date)) : null;
            $daysOwned   = $purchaseNum !== null ? max(0, $hi - $purchaseNum) : null;

            $base = [
                'vehicle_id'         => (int) $v->id,
                'plate'              => $v->plate_no,
                'code'               => $v->code,
                'car'                => trim($v->make . ' ' . $v->model) ?: null,
                'year'               => $v->year,
                'status'             => $v->status,
                'operational_status' => $v->operational_status,
                'owned_since'        => $v->purchase_date,   // metadata: when capital was committed
                'day_rent_value'     => $rate ?: null,
                'days_owned'         => $daysOwned,          // metadata: purchase → now
            ];

            $inServiceDate = $inServiceMap[$v->id] ?? null;
            $inServiceNum  = $inServiceDate ? $this->dayNum(Carbon::parse($inServiceDate)) : null;

            // Pending Service / Onboarding — purchased but never rented. No performance % (would be
            // skewed / empty); just the label + owned-since so the onboarding cost stays visible.
            if ($inServiceNum === null) {
                $cars[] = $base + [
                    'in_service_date'          => null,
                    'days_in_service'          => null,
                    'days_rented'              => null,
                    'days_maintenance'         => null,
                    'days_maintenance_on_rent' => null,
                    'days_idle'                => null,
                    'rental_count'             => 0,
                    'maintenance_visits'       => 0,                              // none in service yet
                    'onboarding_visits'        => count($maintByVeh[$v->id] ?? []), // all visits so far are onboarding
                    'utilization_pct'          => null,
                    'downtime_pct'             => null,
                    'idle_pct'                 => null,
                    'revenue_lost_downtime'    => null,
                    'pending_service'          => true,
                ];
                continue;
            }

            // Performance anchor = later of (window start, In-Service Date) — replaces purchase_date.
            $lo = $this->maxNullable($loBase, $inServiceNum);
            $serviceDays = $lo !== null ? max(0, $hi - $lo) : 0;

            // Contract-based priority: a workshop day that falls inside an ACTIVE rental is PAID by
            // the customer (e.g. an accident mid-rental), so it counts as RENTED, not downtime. Only
            // shop days with NO rental on them are true (unpaid) downtime. Rental wins the overlap.
            $maintTotal = $this->mergeDays($maintByVeh[$v->id] ?? [], $lo, $hi, $todayNum);   // every day in the shop
            $rented     = $this->mergeDays($rentByVeh[$v->id] ?? [], $lo, $hi, $todayNum);     // every rental day (paid)
            $union      = $this->mergeDays(array_merge($rentByVeh[$v->id] ?? [], $maintByVeh[$v->id] ?? []), $lo, $hi, $todayNum);
            $maint      = max(0, $union - $rented);          // downtime = shop days NOT covered by a rental
            $maintOnRent = max(0, $maintTotal - $maint);     // shop days that happened during a paid rental
            $idle       = max(0, $serviceDays - $union);

            $cars[] = $base + [
                'in_service_date'          => $inServiceDate,
                'days_in_service'          => $serviceDays,     // performance denominator: in-service → now (clipped to window)
                'days_rented'              => $rented,
                'days_maintenance'         => $maint,           // true (unpaid) downtime — outside any rental
                'days_maintenance_on_rent' => $maintOnRent,     // shop days covered by a paid rental (info only)
                'days_idle'                => $idle,
                'rental_count'             => count($rentByVeh[$v->id] ?? []),
                // Visits counted only within the in-service window — pre-service NEW-CAR prep excluded.
                'maintenance_visits'       => $this->countVisits($maintByVeh[$v->id] ?? [], $lo, $hi, $todayNum),
                // Onboarding visits (before first rental) — so in-service + onboarding reconciles to
                // the car profile's lifetime visit total.
                'onboarding_visits'        => $this->countOnboardingVisits($maintByVeh[$v->id] ?? [], $inServiceNum, $todayNum),
                'utilization_pct'          => $serviceDays ? round($rented / $serviceDays * 100, 1) : null,
                'downtime_pct'             => $serviceDays ? round($maint / $serviceDays * 100, 1) : null,
                'idle_pct'                 => $serviceDays ? round($idle / $serviceDays * 100, 1) : null,
                // Rent lost while the car sat in the workshop (downtime × its daily rate).
                'revenue_lost_downtime'    => $rate > 0 ? round($maint * $rate, 2) : null,
                'pending_service'          => false,
            ];
        }

        usort($cars, fn ($a, $b) => ($b['days_maintenance'] ?? -1) <=> ($a['days_maintenance'] ?? -1));

        return [
            'cars'           => $cars,
            'summary'        => $this->summarise($cars),
            'window'         => $this->windowMeta($winStart, $winEnd),
            'status_options' => $this->statusOptions(),   // every status + count, for the filter checkboxes
            'statuses'       => array_values($statuses ?? []),
        ];
    }

    /**
     * Every vehicle status with its count (across the WHOLE fleet, regardless of the current filter)
     * so the page can render the status checkboxes with live totals.
     *
     * @return array<int,array{status:string,count:int}>
     */
    private function statusOptions(): array
    {
        return DB::table('vehicles')
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->get()
            ->map(fn ($r) => ['status' => $r->status ?? 'unknown', 'count' => (int) $r->count])
            ->all();
    }

    /**
     * Rental ('C') periods intersecting the window, as [start, end|null] per vehicle.
     * end = null means open (on rent now) → counted to today by the merge.
     *
     * @param  array<int>  $ids
     * @return array<int,array<int,array{0:string,1:?string}>>
     */
    private function rentalIntervals(array $ids, ?Carbon $winStart, Carbon $winEnd): array
    {
        $rows = DB::table('contracts')
            ->where('contract_type', 'C')
            ->whereIn('vehicle_id', $ids)
            ->whereNotNull('out_date')
            ->whereDate('out_date', '<=', $winEnd->toDateString())
            ->when($winStart, fn ($q) => $q->where(function ($w) use ($winStart) {
                $w->whereNull('in_date')->orWhereDate('in_date', '>=', $winStart->toDateString());
            }))
            ->get(['vehicle_id', 'out_date', 'in_date']);

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->vehicle_id][] = [substr((string) $r->out_date, 0, 10), $r->in_date ? substr((string) $r->in_date, 0, 10) : null];
        }

        return $out;
    }

    /**
     * CLOSED workshop visits intersecting the window, grouped by vehicle + out_date (so a multi-row
     * visit counts once), as [out, return] per vehicle.
     *
     * @param  array<int>  $ids
     * @return array<int,array<int,array{0:string,1:string}>>
     */
    private function maintenanceIntervals(array $ids, ?Carbon $winStart, Carbon $winEnd): array
    {
        $rows = DB::table('maintenances')
            ->whereIn('origin', Maintenance::WORKSHOP_LOG_ORIGINS)
            ->whereIn('vehicle_id', $ids)
            ->whereNotNull('out_date')
            ->whereNotNull('actual_in_date')
            ->whereDate('out_date', '<=', $winEnd->toDateString())
            ->when($winStart, fn ($q) => $q->whereDate('actual_in_date', '>=', $winStart->toDateString()))
            ->get(['vehicle_id', 'out_date', 'actual_in_date']);

        // Collapse multi-row visits (same vehicle + out_date) to one interval, taking the latest return.
        $visits = [];
        foreach ($rows as $r) {
            $out = substr((string) $r->out_date, 0, 10);
            $ret = substr((string) $r->actual_in_date, 0, 10);
            $key = $r->vehicle_id . '|' . $out;
            if (! isset($visits[$key])) {
                $visits[$key] = [(int) $r->vehicle_id, $out, $ret];
            } elseif ($ret > $visits[$key][2]) {
                $visits[$key][2] = $ret;
            }
        }

        $out = [];
        foreach ($visits as $vi) {
            $out[$vi[0]][] = [$vi[1], $vi[2]];
        }

        return $out;
    }

    /**
     * Total distinct days covered by a set of [start, end|null] intervals, clipped to [lo, hi]
     * (day numbers), overlaps merged. end = null → today.
     *
     * @param  array<int,array{0:string,1:?string}>  $intervals
     */
    private function mergeDays(array $intervals, ?int $lo, int $hi, int $todayNum): int
    {
        if ($lo === null || empty($intervals)) {
            return 0;
        }

        $segs = [];
        foreach ($intervals as [$s, $e]) {
            $a = $this->dayNum(Carbon::parse($s));
            $b = $e !== null ? $this->dayNum(Carbon::parse($e)) : $todayNum;
            if ($b < $a) {
                continue;   // malformed (return before out) — skip rather than count negative
            }
            $a = max($a, $lo);
            $b = min($b, $hi);
            if ($b <= $a) {
                continue;
            }
            $segs[] = [$a, $b];
        }
        if (empty($segs)) {
            return 0;
        }

        usort($segs, fn ($x, $y) => $x[0] <=> $y[0]);
        $total = 0;
        [$cs, $ce] = $segs[0];
        $n = count($segs);
        for ($i = 1; $i < $n; $i++) {
            [$s, $e] = $segs[$i];
            if ($s <= $ce) {
                $ce = max($ce, $e);
            } else {
                $total += $ce - $cs;
                [$cs, $ce] = [$s, $e];
            }
        }

        return $total + ($ce - $cs);
    }

    /**
     * Count workshop visits whose interval intersects the [lo, hi] in-service window. Used so a car's
     * visit count matches its day count — pre-service (NEW-CAR prep) visits before `lo` are excluded.
     *
     * @param  array<int,array{0:string,1:?string}>  $intervals
     */
    private function countVisits(array $intervals, ?int $lo, int $hi, int $todayNum): int
    {
        if ($lo === null) {
            return 0;
        }
        $n = 0;
        foreach ($intervals as [$s, $e]) {
            $a = $this->dayNum(Carbon::parse($s));
            $b = $e !== null ? $this->dayNum(Carbon::parse($e)) : $todayNum;
            if ($b < $a) {
                continue;   // malformed (return before out)
            }
            if ($b >= $lo && $a <= $hi) {
                $n++;       // visit overlaps the in-service window
            }
        }

        return $n;
    }

    /**
     * Workshop visits that ended BEFORE the car entered service (onboarding / NEW-CAR prep). Surfaced
     * so the in-service visit count + this reconciles to the car-profile's lifetime total — otherwise
     * the two pages appear to disagree (e.g. profile shows 8, utilization shows 6, with 2 onboarding).
     *
     * @param  array<int,array{0:string,1:?string}>  $intervals
     */
    private function countOnboardingVisits(array $intervals, int $inServiceNum, int $todayNum): int
    {
        $n = 0;
        foreach ($intervals as [$s, $e]) {
            $a = $this->dayNum(Carbon::parse($s));
            $b = $e !== null ? $this->dayNum(Carbon::parse($e)) : $todayNum;
            if ($b < $a) {
                continue;
            }
            if ($b < $inServiceNum) {
                $n++;       // visit finished before the first rental
            }
        }

        return $n;
    }

    /** Whole-day index for a date (UTC-noon safe), so day arithmetic is exact. */
    private function dayNum(Carbon $d): int
    {
        return (int) floor($d->copy()->startOfDay()->timestamp / 86400);
    }

    private function maxNullable(?int $a, ?int $b): ?int
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }

        return max($a, $b);
    }

    /** @param  array<int,array<string,mixed>>  $cars */
    private function summarise(array $cars): array
    {
        // Averages are over IN-SERVICE cars only — Pending/Onboarding cars have no % to average.
        $inService = array_filter($cars, fn ($c) => empty($c['pending_service']) && $c['days_in_service']);
        $sum = fn ($k) => array_sum(array_map(fn ($c) => (float) ($c[$k] ?? 0), $cars));
        $avgPct = function (string $k) use ($inService) {
            $vals = array_values(array_filter(array_map(fn ($c) => $c[$k], $inService), fn ($x) => $x !== null));
            return $vals ? round(array_sum($vals) / count($vals), 1) : null;
        };

        return [
            'cars'                   => count($cars),
            'pending_service'        => count(array_filter($cars, fn ($c) => ! empty($c['pending_service']))),
            'total_days_rented'      => (int) $sum('days_rented'),
            'total_days_maintenance' => (int) $sum('days_maintenance'),
            'total_days_idle'        => (int) $sum('days_idle'),
            'avg_utilization_pct'    => $avgPct('utilization_pct'),
            'avg_downtime_pct'       => $avgPct('downtime_pct'),
            'avg_idle_pct'           => $avgPct('idle_pct'),
            'cars_in_maintenance'    => count(array_filter($cars, fn ($c) => $c['maintenance_visits'] > 0)),
            'revenue_lost_downtime'  => round($sum('revenue_lost_downtime'), 2),
        ];
    }

    private function windowMeta(?Carbon $winStart, Carbon $winEnd): array
    {
        return [
            'from'     => $winStart?->toDateString(),
            'to'       => $winEnd->toDateString(),
            'lifetime' => $winStart === null,
        ];
    }
}
