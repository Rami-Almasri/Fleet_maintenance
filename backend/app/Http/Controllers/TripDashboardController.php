<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Services\GoogleSheetsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Delivery Command / Orders board data source.
 *
 * Reads the "Main Trip Dashboard" tab (a ~9k-row pickup/drop-off trip log) via the
 * shared Google service account, parses it into trip records, and aggregates the
 * KPIs / activity / recent list / top drivers / kanban lanes the two front-end
 * pages render. The whole parse+aggregate is cached (READ_TTL) so the pages stay
 * snappy — the sheet is large and changes on a human timescale, not per request.
 *
 * Column layout is mapped by POSITION (the header row's labels are misaligned in
 * the sheet), verified against live data:
 *   1 Trip #  2 Action  3 Request Date  5 Status  6 Car  8 Sales  9 Customer#
 *   10 Del. Time  13 Location  14 Driver  18 Days  19 Total  22 Payment
 *   28 On-time Status  34 Quality (★/☆)  40 Year  41 Month
 */
class TripDashboardController extends Controller
{
    private const KEY        = 'trip_dashboard_v1';   // stored payload {data, built_at}
    private const LOCK       = 'trip_dashboard_v1:lock';
    private const ROWBOUND   = 'trip_dashboard_v1:rowbound';
    private const TTL        = 600;                    // seconds a payload is considered fresh
    private const MAX_COL    = 'AP';                   // last column we read (index 41 = Month)
    private const DEF_BOUND  = 9500;                   // first-read row cap
    private const BOUND_BUF  = 500;                    // headroom above the last data row

    /**
     * Serve the aggregated payload. Stale-while-revalidate: a stored copy is
     * returned instantly; when it ages past TTL a SINGLE request (lock winner)
     * rebuilds it while everyone else keeps getting the last good copy. The
     * expensive sheet read never blocks more than one caller.
     */
    public function index(GoogleSheetsService $sheets): JsonResponse
    {
        try {
            $store = Cache::get(self::KEY);

            if (is_array($store) && isset($store['data'])) {
                $stale = (time() - ($store['built_at'] ?? 0)) >= self::TTL;
                // Only the lock winner rebuilds; others return the stored copy at once.
                if ($stale && Cache::add(self::LOCK, 1, 60)) {
                    try {
                        return ResponseHelper::SuccessResponse($this->rebuild($sheets));
                    } finally {
                        Cache::forget(self::LOCK);
                    }
                }
                return ResponseHelper::SuccessResponse($store['data']);
            }

            // Cold start: nothing cached yet — build inline (one-time cost).
            return ResponseHelper::SuccessResponse($this->rebuild($sheets));
        } catch (Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** Force a fresh read + rebuild (bypasses the freshness window). */
    public function refresh(GoogleSheetsService $sheets): JsonResponse
    {
        try {
            return ResponseHelper::SuccessResponse($this->rebuild($sheets));
        } catch (Throwable $e) {
            return ResponseHelper::fromException($e);
        }
    }

    /** Public entry point for the scheduled warmer (keeps the cache hot). */
    public function warm(GoogleSheetsService $sheets): array
    {
        return $this->rebuild($sheets);
    }

    /** Build the payload and persist it as the new last-good copy. */
    private function rebuild(GoogleSheetsService $sheets): array
    {
        $data = $this->build($sheets);
        Cache::forever(self::KEY, ['data' => $data, 'built_at' => time()]);
        return $data;
    }

    private function build(GoogleSheetsService $sheets): array
    {
        $id  = (string) config('google.sheets.trips.id');
        $gid = (int) config('google.sheets.trips.gid');

        $rows = $this->readTripRows($sheets, $id, $gid);

        // Rows 1-2 are banners, row 3 is the header — data starts at index 3.
        $trips = [];
        foreach (array_slice($rows, 3) as $row) {
            $tripNo = trim((string) ($row[1] ?? ''));
            if ($tripNo === '') {
                continue; // skip spacer / empty rows
            }
            $trips[] = $this->parseTrip($row);
        }

        $total = count($trips);
        $done = 0; $cancel = 0; $onTime = 0; $onTimeBasis = 0; $qualitySum = 0; $qualityCount = 0;
        $cars = []; $drivers = []; $sales = []; $months = [];

        foreach ($trips as $t) {
            if ($t['is_done']) $done++;
            if ($t['is_cancel']) $cancel++;
            if ($t['ontime'] !== null) { $onTimeBasis++; if ($t['ontime']) $onTime++; }
            if ($t['quality'] > 0) { $qualitySum += $t['quality']; $qualityCount++; }
            if ($t['plate']) $cars[$t['plate']] = true;
            if ($t['driver'] !== '') $drivers[$t['driver']] = ($drivers[$t['driver']] ?? 0) + 1;
            if ($t['sales'] !== '') $sales[$t['sales']] = ($sales[$t['sales']] ?? 0) + 1;
            if ($t['ym']) $months[$t['ym']] = ($months[$t['ym']] ?? 0) + 1;
        }

        // Month-over-month delta on trip volume (latest vs previous calendar month present).
        ksort($months);
        $monthKeys = array_keys($months);
        $curMonth = $monthKeys ? end($monthKeys) : null;
        $prevMonth = count($monthKeys) >= 2 ? $monthKeys[count($monthKeys) - 2] : null;
        $curMonthCount = $curMonth ? $months[$curMonth] : 0;
        $prevMonthCount = $prevMonth ? $months[$prevMonth] : 0;

        // "Recent" = the newest rows (the sheet appends chronologically, so the tail is freshest).
        $recentTrips = array_slice($trips, -60);
        $recentTrips = array_reverse($recentTrips); // newest first

        $recent = array_map(fn ($t) => $this->publicTrip($t), array_slice($recentTrips, 0, 40));

        // Kanban lanes from the recent window: Scheduled (open) / Completed / Cancelled.
        $board = ['scheduled' => [], 'completed' => [], 'cancelled' => []];
        foreach (array_slice($recentTrips, 0, 45) as $t) {
            $lane = $t['is_done'] ? 'completed' : ($t['is_cancel'] ? 'cancelled' : 'scheduled');
            $board[$lane][] = $this->publicTrip($t);
        }

        return [
            'kpis' => [
                'total_trips'     => $total,
                'on_time_pct'     => $onTimeBasis ? (int) round($onTime / $onTimeBasis * 100) : null,
                'total_cars'      => count($cars),
                'total_drivers'   => count($drivers),
                'done'            => $done,
                'cancel'          => $cancel,
                'completion_pct'  => ($done + $cancel) ? (int) round($done / ($done + $cancel) * 100) : null,
                'quality_avg'     => $qualityCount ? round($qualitySum / $qualityCount, 1) : null,
                'month_trips'     => $curMonthCount,
                'month_delta'     => $curMonthCount - $prevMonthCount,
            ],
            'counts' => [
                'scheduled' => count($board['scheduled']),
                'completed' => count($board['completed']),
                'cancelled' => count($board['cancelled']),
                'total'     => $total,
            ],
            'activity'    => $this->activity($trips),
            'recent'      => $recent,
            'top_drivers' => $this->topList($drivers, 4),
            'top_sales'   => $this->topList($sales, 4),
            'board'       => $board,
            'as_of'       => now()->toIso8601String(),
            'source'      => ['sheet' => 'Main Trip Dashboard', 'tab_gid' => $gid],
        ];
    }

    /**
     * Read the trip tab with an ADAPTIVE row bound. An unbounded range makes
     * Google scan the whole allocated grid (~14s); a range bounded just above
     * the real last data row reads in ~2-3s. We remember the last row count and
     * read `A1:AP{count+buffer}`; if that ever truncates (data grew past the
     * bound) we grow and re-read once. The tab title is cached to skip the
     * per-read metadata (listTabs) round trip.
     */
    private function readTripRows(GoogleSheetsService $sheets, string $id, int $gid): array
    {
        $title = Cache::remember("sheet:title:{$id}:{$gid}", 86400, function () use ($sheets, $id, $gid) {
            return $sheets->titleForGid($id, $gid) ?? 'Main';
        });

        $read = function (int $bound) use ($sheets, $id, $title) {
            return $sheets->readRange($id, "'{$title}'!A1:" . self::MAX_COL . $bound);
        };

        $bound = (int) Cache::get(self::ROWBOUND, self::DEF_BOUND);
        $rows = $read($bound);

        // If we came back with as many rows as the cap, data likely extends past
        // it — grow generously and re-read once so we never silently drop rows.
        if (count($rows) >= $bound) {
            $bound = count($rows) + 2000;
            $rows = $read($bound);
        }

        // Keep the next bound tight (fast) but with headroom for new trips.
        Cache::forever(self::ROWBOUND, count($rows) + self::BOUND_BUF);

        return $rows;
    }

    /** Parse one raw sheet row into a normalised internal trip record. */
    private function parseTrip(array $row): array
    {
        $cell = fn ($i) => trim((string) ($row[$i] ?? ''));

        $car = $cell(6);
        // Car string e.g. "JEEP CHEROKEE - Blue - 2019 - K 81836" → model + trailing plate.
        $parts = array_map('trim', explode(' - ', $car));
        $plate = count($parts) > 1 ? end($parts) : '';
        $model = $parts[0] ?? $car;

        $status = $cell(5);
        $ontimeRaw = strtolower($cell(28));
        $ontime = $ontimeRaw === '' ? null : (str_contains($ontimeRaw, 'on time'));

        $quality = substr_count($cell(34), '★'); // filled stars only (☆ = empty)

        $total = (float) str_replace([',', ' '], '', $cell(19));

        $date = $this->parseDate($cell(3));
        $ym = null;
        $year = $cell(40); $month = $cell(41);
        if ($year !== '' && $month !== '') {
            $ym = $year . '-' . str_pad((string) $this->monthNum($month), 2, '0', STR_PAD_LEFT);
        } elseif ($date) {
            $ym = substr($date, 0, 7);
        }

        return [
            'trip_no'  => $cell(1),
            'action'   => $cell(2),
            'date'     => $date,               // Y-m-d or null
            'status'   => $status,
            'is_done'  => str_contains(strtolower($status), 'done'),
            'is_cancel'=> str_contains(strtolower($status), 'cancel'),
            'car'      => $car,
            'model'    => $model,
            'plate'    => $plate,
            'sales'    => $cell(8),
            'customer' => $cell(9),
            'del_time' => $cell(10),
            'location' => $cell(13),
            'driver'   => $cell(14),
            'days'     => $cell(18),
            'total'    => $total,
            'payment'  => $cell(22),
            'ontime'   => $ontime,
            'quality'  => $quality,
            'ym'       => $ym,
        ];
    }

    /** Public/serialisable projection of a trip for the front-end. */
    private function publicTrip(array $t): array
    {
        return [
            'trip_no'  => $t['trip_no'],
            'action'   => $t['action'],
            'date'     => $t['date'],
            'status'   => $t['status'],
            'model'    => $t['model'],
            'plate'    => $t['plate'],
            'car'      => $t['car'],
            'driver'   => $t['driver'],
            'sales'    => $t['sales'],
            'customer' => $t['customer'],
            'location' => $t['location'],
            'del_time' => $t['del_time'],
            'total'    => $t['total'],
            'ontime'   => $t['ontime'],
            'quality'  => $t['quality'],
        ];
    }

    /** Trips per day for the 7 most recent dates that have activity. */
    private function activity(array $trips): array
    {
        $byDay = [];
        foreach ($trips as $t) {
            if (! $t['date']) continue;
            $byDay[$t['date']] = ($byDay[$t['date']] ?? 0) + 1;
        }
        if (! $byDay) return [];
        krsort($byDay);
        $recent = array_slice($byDay, 0, 7, true);
        $recent = array_reverse($recent, true); // oldest → newest for the chart

        $out = [];
        foreach ($recent as $day => $count) {
            $out[] = [
                'day'    => date('j M', strtotime($day)),
                'orders' => $count,
                'tier'   => $count < 10 ? 'low' : ($count < 20 ? 'mid' : 'high'),
            ];
        }
        return $out;
    }

    /** Sort an assoc {name => count} desc and take the top $n as a list. */
    private function topList(array $counts, int $n): array
    {
        arsort($counts);
        $out = [];
        foreach (array_slice($counts, 0, $n, true) as $name => $trips) {
            $out[] = ['name' => $name, 'trips' => $trips];
        }
        return $out;
    }

    /** dd/mm/yyyy → Y-m-d (or null when unparseable). */
    private function parseDate(string $s): ?string
    {
        $s = trim($s);
        if ($s === '') return null;
        $d = \DateTime::createFromFormat('d/m/Y', $s);
        return $d ? $d->format('Y-m-d') : null;
    }

    private function monthNum(string $month): int
    {
        $t = strtotime($month . ' 1 2000');
        return $t ? (int) date('n', $t) : 1;
    }
}
