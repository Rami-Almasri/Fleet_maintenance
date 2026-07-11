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
 *   • Days in maintenance — TRUE off-road shop days: type-'U' MAINTENANCE CONTRACT days that fall
 *                           OUTSIDE any rental. Rental is King — a 'U' day that overlaps a rental is
 *                           RENTAL time, never shop time. Formula: maintenance days − rental days.
 *   • Days idle         — owned − (rented ∪ maintenance): available but earning nothing
 *
 * Maintenance source = the OfficeManager type-'U' Maintenance CONTRACTS, NOT the raw Google-Sheet
 * workshop log. The sheet log is event-level and littered with stale "OUT" rows whose return was
 * logged months late (one car had a 1-day oil change left OPEN for 93 days), which invented huge fake
 * downtime AND under-counted visits. The type-'U' contract is the authoritative one-per-visit record
 * with clean out→in dates, so both the day counts and the visit counts come from it.
 *
 * Rental is King (base_on is NOT used — it is full of staff names): a day is decided purely by contract
 * overlap. Every 'C' rental day is RENTAL time. A 'U' maintenance day with no rental on it is a true
 * off-road SHOP day. A day that is both on a rental and under maintenance is RENTAL time, not shop time
 * (the maintenance clock pauses while the car is with the customer, and resumes when it returns).
 * Accident / unknown shop time with no 'U' contract is left as Rented via the 'C' dates — we never
 * invent downtime from unknown sources.
 *
 * Honesty rules (data is the source of truth — no guessed durations):
 *   - Maintenance days come from each type-'U' contract's out_date → in_date; an OPEN one (no in_date —
 *     a car in the shop right now) runs to today, exactly like an open rental.
 *   - Overlapping rentals or overlapping maintenance periods are merged so a day is never counted twice.
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

        $rentByVeh  = $this->rentalIntervals($ids, $winStart, $winEnd);       // 'C' rentals
        $maintByVeh = $this->maintenanceIntervals($ids, $winStart, $winEnd);  // every 'U' maintenance contract
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

            // Rental is King (base_on is ignored — it is full of staff names):
            //   • Every day under a 'C' rental is RENTAL time, full stop.
            //   • Shop days = maintenance days MINUS rental days: a 'U' day that overlaps a rental is
            //     rental time, never shop time. Only days physically in the garage AND not with a
            //     customer count as TRUE off-road shop days.
            //   • Accident / unknown shop time with no 'U' contract simply stays Rented via the 'C' dates.
            $maintIntervals = $maintByVeh[$v->id] ?? [];
            $rentIntervals  = $rentByVeh[$v->id] ?? [];

            $rented = $this->mergeDays($rentIntervals, $lo, $hi, $todayNum);   // every rental day (King)
            $union  = $this->mergeDays(array_merge($rentIntervals, $maintIntervals), $lo, $hi, $todayNum);
            $maint  = max(0, $union - $rented);   // TRUE off-road shop days = maintenance − rental
            $idle   = max(0, $serviceDays - $union);

            $cars[] = $base + [
                'in_service_date'          => $inServiceDate,
                'days_in_service'          => $serviceDays,     // performance denominator: in-service → now (clipped to window)
                'days_rented'              => $rented,
                'days_maintenance'         => $maint,           // TRUE off-road shop days (maintenance − rental)
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
                // Rent lost while the car sat in the workshop (pure downtime × its daily rate).
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
     * "Time machine" — what one car was doing on a single calendar day. Priority mirrors the report's
     * contract-wins-overlap rule:
     *   1. On a rental ('C') contract that day            → Rented (paid), with customer + contract.
     *   2. Else inside a CLOSED workshop visit that day    → In the workshop, with garage + issue.
     *   3. Else the day predates purchase                  → Not in the fleet yet.
     *   4. Else the day predates the first rental          → Onboarding (owned, not yet in service).
     *   5. Else                                            → Idle (available, earning nothing).
     *
     * Open workshop rows (an OUT with no recorded return — ~70% of the log) are deliberately NOT used
     * as a verdict: with no honest end date they'd wrongly brand every later day as "in the shop".
     *
     * @return array<string,mixed>
     */
    public function statusOn(int $vehicleId, string $date): array
    {
        $v = DB::table('vehicles')
            ->select('id', 'code', 'plate_no', 'make', 'model', 'year', 'status', 'purchase_date')
            ->where('id', $vehicleId)
            ->first();
        if (! $v) {
            throw new \RuntimeException('Vehicle not found.');
        }

        $day     = Carbon::parse($date)->startOfDay();
        $dayStr  = $day->toDateString();
        $today   = Carbon::today();

        $car = [
            'vehicle_id' => (int) $v->id,
            'plate'      => $v->plate_no,
            'code'       => $v->code,
            'car'        => trim($v->make . ' ' . $v->model) ?: null,
            'year'       => $v->year,
            'date'       => $dayStr,
        ];

        // A future date — nothing has happened yet.
        if ($day->gt($today)) {
            return $car + ['state' => 'future', 'label' => 'In the future', 'detail' => 'That date hasn’t happened yet.'];
        }

        // 1. Rental covering the day (out_date <= day <= in_date; open rental runs to today).
        $rental = DB::table('contracts as c')
            ->leftJoin('customers as cu', 'cu.id', '=', 'c.customer_id')
            ->where('c.contract_type', 'C')
            ->where('c.vehicle_id', $vehicleId)
            ->whereNull('c.deleted_at')
            ->whereNotNull('c.out_date')
            ->whereDate('c.out_date', '<=', $dayStr)
            ->where(fn ($q) => $q->whereNull('c.in_date')->orWhereDate('c.in_date', '>=', $dayStr))
            ->orderByDesc('c.out_date')
            ->first(['c.id', 'c.contract_no', 'c.out_date', 'c.in_date', 'cu.name_en as customer']);

        if ($rental) {
            return $car + [
                'state'    => 'rented',
                'label'    => 'Out on rent',
                'detail'   => $rental->customer ? 'Rented to ' . $rental->customer : 'On a paid rental contract.',
                'contract' => [
                    'id'       => (int) $rental->id,
                    'no'       => $rental->contract_no,
                    'customer' => $rental->customer,
                    'out_date' => substr((string) $rental->out_date, 0, 10),
                    'in_date'  => $rental->in_date ? substr((string) $rental->in_date, 0, 10) : null,
                    'open'     => $rental->in_date === null,
                ],
            ];
        }

        // 2. Closed workshop visit spanning the day.
        $visit = DB::table('maintenances')
            ->whereIn('origin', Maintenance::WORKSHOP_LOG_ORIGINS)
            ->where('vehicle_id', $vehicleId)
            ->whereNotNull('out_date')
            ->whereNotNull('actual_in_date')
            ->whereDate('out_date', '<=', $dayStr)
            ->whereDate('actual_in_date', '>=', $dayStr)
            ->orderByDesc('out_date')
            ->first(['id', 'out_date', 'actual_in_date', 'garage', 'service_main', 'maintenance_type', 'cost']);

        if ($visit) {
            $issue = $visit->service_main ?: $visit->maintenance_type;
            return $car + [
                'state'       => 'maintenance',
                'label'       => 'In the workshop',
                'detail'      => $issue ? ucfirst((string) $issue) : 'In for a workshop visit.',
                'maintenance' => [
                    'id'       => (int) $visit->id,
                    'garage'   => $visit->garage,
                    'issue'    => $issue,
                    'cost'     => $visit->cost !== null ? (float) $visit->cost : null,
                    'out_date' => substr((string) $visit->out_date, 0, 10),
                    'in_date'  => substr((string) $visit->actual_in_date, 0, 10),
                ],
            ];
        }

        // 3-5. Not rented and not in a closed visit → place it on the ownership timeline.
        $purchase = $v->purchase_date ? substr((string) $v->purchase_date, 0, 10) : null;
        if ($purchase && $dayStr < $purchase) {
            return $car + ['state' => 'not_owned', 'label' => 'Not in the fleet yet', 'detail' => 'Bought on ' . $purchase . '.'];
        }

        $inServiceMap  = $this->serviceWindow->inServiceDates([$vehicleId]);
        $inServiceDate = $inServiceMap[$vehicleId] ?? null;
        $inServiceStr  = $inServiceDate ? substr((string) $inServiceDate, 0, 10) : null;

        if ($inServiceStr === null) {
            return $car + ['state' => 'onboarding', 'label' => 'Not yet in service', 'detail' => 'Owned, but it has no rental history yet.'];
        }
        if ($dayStr < $inServiceStr) {
            return $car + ['state' => 'onboarding', 'label' => 'Onboarding', 'detail' => 'Owned but not yet rented — first rental was ' . $inServiceStr . '.'];
        }

        return $car + ['state' => 'idle', 'label' => 'Idle — available', 'detail' => 'In service, but not on rent and not in the workshop that day.'];
    }

    /**
     * "Time machine" over a DATE RANGE — how one car's days between `from` and `to` split into
     * rented / in the workshop / available, using the same Rental-is-King rule the fleet report uses
     * (a 'U' maintenance day that overlaps a rental is rental time, not shop time; a 'U' day with no
     * rental is true off-road shop time). The three counts always sum to the counted total.
     *
     * The counted span is clipped to [purchase_date, today] so days before the car joined the fleet
     * (or in the future) are never miscounted as "available". Also returns the rental contracts and
     * 'U' maintenance contracts that overlap the range, for the detail list.
     *
     * @return array<string,mixed>
     */
    public function usageBreakdown(int $vehicleId, string $from, string $to): array
    {
        $v = DB::table('vehicles')
            ->select('id', 'code', 'plate_no', 'make', 'model', 'year', 'purchase_date')
            ->where('id', $vehicleId)
            ->first();
        if (! $v) {
            throw new \RuntimeException('Vehicle not found.');
        }

        $today = Carbon::today();
        $fromC = Carbon::parse($from)->startOfDay();
        $toC   = Carbon::parse($to)->startOfDay();
        if ($toC->lt($fromC)) {
            [$fromC, $toC] = [$toC, $fromC];   // tolerate a reversed range
        }
        if ($toC->gt($today)) {
            $toC = $today->copy();             // can't count the future
        }

        $todayNum = $this->dayNum($today);
        $loReq    = $this->dayNum($fromC);
        $hi       = $this->dayNum($toC);

        // Don't count days before the car was in the fleet as "available".
        $purchaseNum = $v->purchase_date ? $this->dayNum(Carbon::parse($v->purchase_date)) : null;
        $clipped     = $purchaseNum !== null && $purchaseNum > $loReq;
        $lo          = $clipped ? $purchaseNum : $loReq;
        $total       = max(0, $hi - $lo);

        $rentIntervals  = $this->rentalIntervals([$vehicleId], $fromC, $toC)[$vehicleId] ?? [];
        $maintIntervals = $this->maintenanceIntervals([$vehicleId], $fromC, $toC)[$vehicleId] ?? [];

        $rented     = $this->mergeDays($rentIntervals, $lo, $hi, $todayNum);
        $union = $this->mergeDays(array_merge($rentIntervals, $maintIntervals), $lo, $hi, $todayNum);
        $maint = max(0, $union - $rented);   // TRUE off-road shop days = maintenance − rental (Rental is King)
        $idle  = max(0, $total - $union);

        // The rental contracts overlapping the range (with customer), for the detail list.
        $rentals = DB::table('contracts as c')
            ->leftJoin('customers as cu', 'cu.id', '=', 'c.customer_id')
            ->where('c.contract_type', 'C')
            ->where('c.vehicle_id', $vehicleId)
            ->whereNull('c.deleted_at')
            ->whereNotNull('c.out_date')
            ->whereDate('c.out_date', '<=', $toC->toDateString())
            ->where(fn ($q) => $q->whereNull('c.in_date')->orWhereDate('c.in_date', '>=', $fromC->toDateString()))
            ->orderBy('c.out_date')
            ->get(['c.id', 'c.contract_no', 'c.out_date', 'c.in_date', 'cu.name_en as customer'])
            ->map(fn ($r) => [
                'id'       => (int) $r->id,
                'no'       => $r->contract_no,
                'customer' => $r->customer,
                'out_date' => substr((string) $r->out_date, 0, 10),
                'in_date'  => $r->in_date ? substr((string) $r->in_date, 0, 10) : null,
                'open'     => $r->in_date === null,
            ])->all();

        // The 'U' maintenance contracts overlapping the range.
        $maintList = DB::table('contracts')
            ->where('contract_type', 'U')
            ->where('vehicle_id', $vehicleId)
            ->whereNull('deleted_at')
            ->whereNotNull('out_date')
            ->whereDate('out_date', '<=', $toC->toDateString())
            ->where(fn ($q) => $q->whereNull('in_date')->orWhereDate('in_date', '>=', $fromC->toDateString()))
            ->orderBy('out_date')
            ->get(['contract_no', 'out_date', 'in_date'])
            ->map(fn ($r) => [
                'no'       => $r->contract_no,
                'out_date' => substr((string) $r->out_date, 0, 10),
                'in_date'  => $r->in_date ? substr((string) $r->in_date, 0, 10) : null,
                'open'     => $r->in_date === null,
            ])->all();

        return [
            'mode'               => 'range',
            'vehicle_id'         => (int) $v->id,
            'plate'              => $v->plate_no,
            'code'               => $v->code,
            'car'                => trim($v->make . ' ' . $v->model) ?: null,
            'year'               => $v->year,
            'from'               => $fromC->toDateString(),
            'to'                 => $toC->toDateString(),
            'counted_from'       => $clipped ? substr((string) $v->purchase_date, 0, 10) : $fromC->toDateString(),
            'total_days'         => $total,
            'rented_days'        => $rented,
            'maintenance_days'   => $maint,
            'idle_days'          => $idle,
            'utilization_pct'    => $total ? round($rented / $total * 100, 1) : null,
            'rentals'            => $rentals,
            'maintenance'        => $maintList,
        ];
    }

    /**
     * Fleet Operations — cars that are on rent today AND have a workshop record open today. Rental is
     * King, so these are simply on-rent cars whose maintenance clock is PAUSED until they return; the
     * snapshot is informational (no warning). Two kinds:
     *
     *   - 'tracked'            — an official type-'U' maintenance contract is open over today while the
     *                            car is on rent (a scheduled service the car will return for).
     *   - 'untracked'          — a Google-Sheet workshop event is open over today but NO 'U' contract
     *                            covers it: an accident / customer-damage case with no maintenance
     *                            contract yet — worth opening a 'U' ticket so the off-road time is logged.
     *
     * Sheet events are limited to recently-opened ones to avoid stale "OUT with no return" rows.
     *
     * @return array<string,mixed>
     */
    public function activeShopStays(int $sheetLookbackDays = 60): array
    {
        $today    = Carbon::today();
        $todayStr = $today->toDateString();
        $sheetFloor = $today->copy()->subDays(max(0, $sheetLookbackDays))->toDateString();

        // 1. Cars ON RENT today (open 'C' contract spanning today), latest contract per vehicle.
        $rentals = DB::table('contracts as c')
            ->leftJoin('customers as cu', 'cu.id', '=', 'c.customer_id')
            ->where('c.contract_type', 'C')
            ->whereNull('c.deleted_at')
            ->whereNotNull('c.out_date')
            ->whereDate('c.out_date', '<=', $todayStr)
            ->where(fn ($q) => $q->whereNull('c.in_date')->orWhereDate('c.in_date', '>=', $todayStr))
            ->orderByDesc('c.out_date')
            ->get(['c.id', 'c.contract_no', 'c.vehicle_id', 'c.out_date', 'c.in_date', 'cu.name_en as customer']);

        $rentByVeh = [];
        foreach ($rentals as $r) {
            $vid = (int) $r->vehicle_id;
            if ($vid && ! isset($rentByVeh[$vid])) {
                $rentByVeh[$vid] = $r;   // first = latest out_date
            }
        }
        $vehIds = array_keys($rentByVeh);
        if (empty($vehIds)) {
            return ['generated_at' => $todayStr, 'summary' => $this->shopStaySummary([]), 'cars' => []];
        }

        $vehicles = DB::table('vehicles')->whereIn('id', $vehIds)
            ->get(['id', 'code', 'plate_no', 'make', 'model', 'year', 'day_rent_value'])->keyBy('id');

        // 2. Open 'U' maintenance contracts over today, per vehicle (the tracked case).
        $uByVeh = [];
        foreach (DB::table('contracts')->where('contract_type', 'U')->whereIn('vehicle_id', $vehIds)
            ->whereNull('deleted_at')->whereNotNull('out_date')
            ->whereDate('out_date', '<=', $todayStr)
            ->where(fn ($q) => $q->whereNull('in_date')->orWhereDate('in_date', '>=', $todayStr))
            ->orderByDesc('out_date')
            ->get(['vehicle_id', 'contract_no', 'out_date', 'in_date']) as $u) {
            $uByVeh[(int) $u->vehicle_id] ??= $u;
        }

        // 3. Sheet workshop events — the untracked (accident, no 'U' contract) case. The sheet is messy
        //    (~70% of rows are an "OUT" whose return was never logged), so we DON'T trust a null return
        //    alone. Instead we take each car's LATEST workshop event: the car is only "still in the shop"
        //    if that most recent event is NOT an 'IN' (it never came back), and the visit started within
        //    the lookback window (so ancient unclosed OUTs don't haunt the list forever).
        $latestEvent = [];
        foreach (DB::table('maintenances')->whereIn('origin', Maintenance::WORKSHOP_LOG_ORIGINS)
            ->whereIn('vehicle_id', $vehIds)->whereNotNull('out_date')
            ->whereDate('out_date', '<=', $todayStr)
            ->orderBy('out_date')->orderBy('id')   // ascending → last write per vehicle is its latest event
            ->get(['vehicle_id', 'out_date', 'actual_in_date', 'event_status', 'service_main', 'maintenance_type', 'garage']) as $s) {
            $latestEvent[(int) $s->vehicle_id] = $s;
        }
        $sheetByVeh = [];
        foreach ($latestEvent as $vid => $s) {
            $stillOut = $s->actual_in_date === null || substr((string) $s->actual_in_date, 0, 10) >= $todayStr;
            $recent   = substr((string) $s->out_date, 0, 10) >= $sheetFloor;
            if ($s->event_status !== 'IN' && $stillOut && $recent) {
                $sheetByVeh[$vid] = $s;   // latest event: no return logged yet, and the visit is recent
            }
        }

        // 4. Assemble: only cars that are actually in the shop today (have a 'U' or a sheet event).
        $cars = [];
        foreach ($vehIds as $vid) {
            $u     = $uByVeh[$vid] ?? null;
            $sheet = $sheetByVeh[$vid] ?? null;
            if (! $u && ! $sheet) {
                continue;   // on rent but not in the shop — not our concern here
            }

            $v    = $vehicles[$vid] ?? null;
            $rent = $rentByVeh[$vid];
            $rate = $v ? (float) ($v->day_rent_value ?: 0) : 0;

            $shopOut = $u ? substr((string) $u->out_date, 0, 10) : substr((string) $sheet->out_date, 0, 10);
            $shopIn  = $u
                ? ($u->in_date ? substr((string) $u->in_date, 0, 10) : null)
                : ($sheet->actual_in_date ? substr((string) $sheet->actual_in_date, 0, 10) : null);
            $shopDays = max(1, $this->dayNum($today) - $this->dayNum(Carbon::parse($shopOut)) + 1);

            $cars[] = [
                'vehicle_id'     => $vid,
                'plate'          => $v->plate_no ?? null,
                'code'           => $v->code ?? null,
                'car'            => $v ? (trim($v->make . ' ' . $v->model) ?: null) : null,
                'year'           => $v->year ?? null,
                'day_rent_value' => $rate ?: null,
                'customer'       => $rent->customer,
                'contract_id'    => (int) $rent->id,
                'contract_no'    => $rent->contract_no,
                'rental_out'     => substr((string) $rent->out_date, 0, 10),
                'rental_in'      => $rent->in_date ? substr((string) $rent->in_date, 0, 10) : null,
                'classification' => $u ? 'tracked' : 'untracked',
                'u_contract_no'  => $u->contract_no ?? null,
                'shop_out'       => $shopOut,
                'shop_in'        => $shopIn,
                'shop_open'      => $shopIn === null,
                'shop_days'      => $shopDays,
                'issue'          => $sheet ? ($sheet->service_main ?: $sheet->maintenance_type) : null,
                'garage'         => $sheet->garage ?? null,
            ];
        }

        // Untracked (accident, no ticket) first — worth a 'U' ticket — then longest in the shop.
        usort($cars, fn ($a, $b) => [$a['classification'] === 'tracked', $b['shop_days']]
            <=> [$b['classification'] === 'tracked', $a['shop_days']]);

        return [
            'generated_at' => $todayStr,
            'summary'      => $this->shopStaySummary($cars, $rentByVeh, $vehicles),
            'cars'         => $cars,
        ];
    }

    /**
     * Fleet Operations summary: how many on-rent cars also have a workshop record open today (their
     * maintenance clock is paused until they return), split into tracked ('U' ticket) vs untracked.
     *
     * @param  array<int,array<string,mixed>>  $cars
     */
    private function shopStaySummary(array $cars, array $rentByVeh = [], $vehicles = null): array
    {
        $dailyRentOnRent = 0.0;
        if ($vehicles) {
            foreach (array_keys($rentByVeh) as $vid) {
                $dailyRentOnRent += (float) (($vehicles[$vid]->day_rent_value ?? 0) ?: 0);
            }
        }

        return [
            'cars_on_rent'        => count($rentByVeh),
            'cars_in_shop'        => count($cars),   // on rent + a workshop record open (clock paused)
            'tracked_cars'        => count(array_filter($cars, fn ($c) => $c['classification'] === 'tracked')),
            'untracked_cars'      => count(array_filter($cars, fn ($c) => $c['classification'] === 'untracked')),
            'daily_rental_income' => round($dailyRentOnRent, 2),   // total rent the on-rent fleet bills/day
        ];
    }

    /**
     * Rental-is-King overlap finder — the days a rental and a maintenance window cover the SAME dates.
     * Those days are RENTAL time (the maintenance clock pauses), so this is what we subtract from the
     * maintenance window to get true off-road shop days; it is purely a calculation helper, not a flag.
     *
     * Each side is ['start' => 'Y-m-d', 'end' => 'Y-m-d'|null]; a null end means open (runs to today).
     * Returns null when the two never overlap, or the overlap window + day count when they do. `live`
     * is true when BOTH contracts are still open today (the car is on rent now with a paused ticket).
     *
     * @param  array{start:string,end:?string}  $rental
     * @param  array{start:string,end:?string}  $maintenance
     * @return array{overlap_days:int,overlap_start:string,overlap_end:string,live:bool}|null
     */
    public function rentalMaintenanceOverlap(array $rental, array $maintenance): ?array
    {
        $today = Carbon::today();
        $rStart = Carbon::parse($rental['start'])->startOfDay();
        $rEnd   = ! empty($rental['end']) ? Carbon::parse($rental['end'])->startOfDay() : $today->copy();
        $mStart = Carbon::parse($maintenance['start'])->startOfDay();
        $mEnd   = ! empty($maintenance['end']) ? Carbon::parse($maintenance['end'])->startOfDay() : $today->copy();

        $start = $rStart->gt($mStart) ? $rStart : $mStart;   // later of the two starts
        $end   = $rEnd->lt($mEnd) ? $rEnd : $mEnd;            // earlier of the two ends
        $days  = $this->dayNum($end) - $this->dayNum($start);
        if ($days <= 0) {
            return null;   // no shared day → no overlap to subtract
        }

        return [
            'overlap_days'  => $days,
            'overlap_start' => $start->toDateString(),
            'overlap_end'   => $end->toDateString(),
            'live'          => empty($rental['end']) && empty($maintenance['end']),
        ];
    }

    /**
     * Maintenance↔rental OVERLAP history — every type-'U' maintenance contract whose dates overlapped a
     * 'C' rental (BOTH may be closed), paired up via rentalMaintenanceOverlap(). Rental is King, so the
     * overlap days are rental time (the maintenance clock paused); this powers the per-ticket "true
     * off-road shop days" breakdown on Fleet Operations. `is_live` = the car is on rent right now.
     *
     * @return array<string,mixed>
     */
    public function maintenanceOverlaps(int $months = 12): array
    {
        $today    = Carbon::today();
        $todayNum = $this->dayNum($today);
        $floor    = $today->copy()->subMonthsNoOverflow(max(1, $months))->toDateString();

        // 'U' maintenance contracts opened within the window.
        $us = DB::table('contracts')->where('contract_type', 'U')->whereNull('deleted_at')
            ->whereNotNull('out_date')->whereDate('out_date', '>=', $floor)
            ->get(['contract_no', 'vehicle_id', 'out_date', 'in_date']);

        $vehIds = $us->pluck('vehicle_id')->filter()->unique()->values()->all();
        if (empty($vehIds)) {
            return ['generated_at' => $today->toDateString(), 'months' => $months, 'summary' => ['events' => 0, 'overlap_days' => 0, 'value' => 0.0], 'events' => []];
        }

        // 'C' rentals for those vehicles, grouped per vehicle. Every 'U' here opened on/after
        // $floor, so a rental can only share a day with one if it is still open or ended on/after
        // $floor — rentals that closed before the window can't overlap and would just burn CPU in
        // the pairwise loop below. Same bound rentalIntervals() uses; result is identical.
        $cByVeh = [];
        foreach (DB::table('contracts as c')->leftJoin('customers as cu', 'cu.id', '=', 'c.customer_id')
            ->where('c.contract_type', 'C')->whereNull('c.deleted_at')->whereIn('c.vehicle_id', $vehIds)
            ->whereNotNull('c.out_date')
            ->where(fn ($w) => $w->whereNull('c.in_date')->orWhereDate('c.in_date', '>=', $floor))
            ->get(['c.id', 'c.contract_no', 'c.vehicle_id', 'c.out_date', 'c.in_date', 'cu.name_en as customer']) as $c) {
            $cByVeh[(int) $c->vehicle_id][] = $c;
        }

        $vehicles = DB::table('vehicles')->whereIn('id', $vehIds)
            ->get(['id', 'code', 'plate_no', 'make', 'model', 'year', 'day_rent_value'])->keyBy('id');

        // The 'U' contract carries no reason, so we read WHY the car went in from the sheet workshop
        // log (service_main / type / garage), matched by vehicle + OUT-date proximity (the same visit).
        // A sheet row is only consulted when it falls within ±3 days of a 'U' out date, and every
        // 'U' here opened on/after $floor — so rows older than $floor (with a 7-day safety margin)
        // can never match and only cost Carbon::parse() time below. Bounding the scan is exact.
        $sheetFloor = Carbon::parse($floor)->subDays(7)->toDateString();
        $sheetByVeh = [];
        foreach (DB::table('maintenances')->whereIn('origin', Maintenance::WORKSHOP_LOG_ORIGINS)
            ->whereIn('vehicle_id', $vehIds)->whereNotNull('out_date')
            ->whereDate('out_date', '>=', $sheetFloor)
            ->get(['vehicle_id', 'out_date', 'service_main', 'maintenance_type', 'garage']) as $m) {
            $sheetByVeh[(int) $m->vehicle_id][] = [
                'a'      => $this->dayNum(Carbon::parse($m->out_date)),
                'reason' => $m->service_main ?: $m->maintenance_type,
                'garage' => $m->garage,
            ];
        }

        $events = [];
        foreach ($us as $u) {
            $vid = (int) $u->vehicle_id;
            $ua = $this->dayNum(Carbon::parse($u->out_date));
            $ub = $u->in_date ? $this->dayNum(Carbon::parse($u->in_date)) : $todayNum;
            $rate = $vehicles[$vid] ?? null ? (float) (($vehicles[$vid]->day_rent_value ?? 0) ?: 0) : 0;

            // Closest sheet event within ±3 days of the 'U' out date → the reason it went in.
            $reason = null; $garage = null; $best = PHP_INT_MAX;
            foreach ($sheetByVeh[$vid] ?? [] as $se) {
                $d = abs($se['a'] - $ua);
                if ($d <= 3 && $d < $best) { $best = $d; $reason = $se['reason']; $garage = $se['garage']; }
            }

            foreach ($cByVeh[$vid] ?? [] as $c) {
                // Rental is King: the days both are active are rental time (the clock paused here).
                $overlap = $this->rentalMaintenanceOverlap(
                    ['start' => $c->out_date, 'end' => $c->in_date],
                    ['start' => $u->out_date, 'end' => $u->in_date],
                );
                if ($overlap === null) {
                    continue;
                }

                $v = $vehicles[$vid] ?? null;
                $events[] = [
                    'vehicle_id'        => $vid,
                    'plate'             => $v->plate_no ?? null,
                    'code'              => $v->code ?? null,
                    'car'               => $v ? (trim($v->make . ' ' . $v->model) ?: null) : null,
                    'year'              => $v->year ?? null,
                    'overlap_days'      => $overlap['overlap_days'],
                    'overlap_value'     => $rate > 0 ? round($overlap['overlap_days'] * $rate, 2) : null,
                    'is_live'           => $overlap['live'],   // on rent AND in the shop right now (clock paused)
                    // the rental side
                    'rental_no'         => $c->contract_no,
                    'rental_id'         => (int) $c->id,
                    'rental_out'        => substr((string) $c->out_date, 0, 10),
                    'rental_in'         => $c->in_date ? substr((string) $c->in_date, 0, 10) : null,
                    'rental_open'       => $c->in_date === null,
                    'customer'          => $c->customer,
                    // the maintenance side
                    'maint_no'          => $u->contract_no,
                    'maint_out'         => substr((string) $u->out_date, 0, 10),
                    'maint_in'          => $u->in_date ? substr((string) $u->in_date, 0, 10) : null,
                    'maint_open'        => $u->in_date === null,
                    'reason'            => $reason,   // why it went in (from the sheet log; null if no match)
                    'garage'            => $garage,
                ];
            }
        }

        // Cars on rent right now (clock paused) first, then most recent maintenance.
        usort($events, fn ($a, $b) => [$b['is_live'], $b['maint_out']] <=> [$a['is_live'], $a['maint_out']]);

        return [
            'generated_at' => $today->toDateString(),
            'months'       => $months,
            'summary'      => [
                'events'       => count($events),
                'live'         => count(array_filter($events, fn ($e) => $e['is_live'])),
                'overlap_days' => array_sum(array_map(fn ($e) => $e['overlap_days'], $events)),
                'value'        => round(array_sum(array_map(fn ($e) => (float) ($e['overlap_value'] ?? 0), $events)), 2),
            ],
            'events'       => $events,
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
     * Maintenance periods from the OfficeManager type-'U' MAINTENANCE CONTRACTS — the authoritative
     * one-per-visit record (clean out→in dates), replacing the stale Google-Sheet workshop log. Each
     * contract is one workshop visit, so this set drives BOTH the day counts and the visit counts.
     * Mirrors rentalIntervals(): an OPEN contract (no in_date — a car in the shop now) → end = null,
     * counted to today by the merge.
     *
     * base_on is intentionally NOT used — it is full of staff names and unreliable. Responsibility is
     * decided purely by rental overlap in report(): a 'U' day during a rental is rental time (King), a
     * 'U' day with no rental is true off-road shop time.
     *
     * @param  array<int>  $ids
     * @return array<int,array<int,array{0:string,1:?string}>>
     */
    private function maintenanceIntervals(array $ids, ?Carbon $winStart, Carbon $winEnd): array
    {
        $rows = DB::table('contracts')
            ->where('contract_type', 'U')
            ->whereIn('vehicle_id', $ids)
            ->whereNotNull('out_date')
            ->whereDate('out_date', '<=', $winEnd->toDateString())
            ->when($winStart, fn ($q) => $q->where(function ($w) use ($winStart) {
                $w->whereNull('in_date')->orWhereDate('in_date', '>=', $winStart->toDateString());
            }))
            ->get(['vehicle_id', 'out_date', 'in_date']);

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->vehicle_id][] = [
                substr((string) $r->out_date, 0, 10),
                $r->in_date ? substr((string) $r->in_date, 0, 10) : null,
            ];
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
            // Overlaps the [lo, hi] window. A visit that merely ENDS on `lo` (it returned the day the
            // window opened — e.g. a car coming out of onboarding prep onto its first rental) contributes
            // 0 days and belongs to onboarding, so require b > lo OR a visit that starts on/after lo.
            if (($b > $lo || $a >= $lo) && $a <= $hi) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Workshop visits that ended ON or BEFORE the car entered service (onboarding / NEW-CAR prep).
     * Surfaced so the in-service visit count + this reconciles to the car-profile's lifetime total —
     * otherwise the two pages appear to disagree (e.g. profile shows 8, utilization shows 6, +2 onboarding).
     *
     * A visit whose return date equals the In-Service Date (car came out of the shop and went straight
     * onto its first rental that day) is onboarding prep, not operational, so the test is `b <= inService`.
     * `a < inService` keeps a same-day visit that happens ON the in-service date itself out of onboarding —
     * it pairs with the `a >= lo` clause in countVisits() so the two buckets stay complementary.
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
            if ($b <= $inServiceNum && $a < $inServiceNum) {
                $n++;       // visit finished on/before the first rental, having started before it
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
            'total_days_maintenance' => (int) $sum('days_maintenance'),   // TRUE off-road shop days (maintenance − rental)
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
