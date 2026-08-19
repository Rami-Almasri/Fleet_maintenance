<?php

namespace App\Services\Components;

use Illuminate\Support\Carbon;

/**
 * The PURE lifecycle math behind Vehicle Installed Components — no database, no models, no clock
 * beyond "now". Split out of {@see ComponentReadModel} so the rules that decide whether a part is
 * "expiring soon" or "past its expected life" can be locked by plain unit tests, the same way
 * OdometerContinuityService's thresholds are.
 *
 * These thresholds are mirrored in the UI (frontend VehicleComponentsPanel / ComponentsDashboard).
 * Keep the two in step: a badge that disagrees with the number it sits next to is worse than no badge.
 */
final class ComponentLifecycle
{
    /** A warranty ending inside this many days is "expiring soon" — the dashboard's warning window. */
    public const WARRANTY_WINDOW_DAYS = 60;

    /** Expected life consumed at or beyond this is "due soon"; at or beyond 100% it is "overdue". */
    public const DUE_SOON_PCT = 80;

    /** Average days in a month — used to compare a months-based expectation against an age in days. */
    private const DAYS_PER_MONTH = 30.44;

    /**
     * Warranty standing: none | active | expiring_soon | expired, plus the signed day count that
     * decided it (negative = already expired).
     *
     * @param Carbon|string|null $until the derived warranty_until date
     */
    public static function warranty($until, ?int $months, ?Carbon $now = null): array
    {
        if (! $until) {
            return ['status' => 'none', 'until' => null, 'days_remaining' => null, 'months' => $months];
        }

        $until = $until instanceof Carbon ? $until : Carbon::parse($until);
        $today = ($now ?: Carbon::now())->copy()->startOfDay();
        $days  = (int) round($today->diffInDays($until->copy()->startOfDay(), false));

        return [
            'status' => match (true) {
                $days < 0                           => 'expired',
                $days <= self::WARRANTY_WINDOW_DAYS => 'expiring_soon',
                default                             => 'active',
            },
            'until'          => $until->toDateString(),
            'days_remaining' => $days,
            'months'         => $months,
        ];
    }

    /**
     * WHICH limit judges this part: the one frozen onto the row at install, or the type's current
     * expectation from the catalog.
     *
     * The snapshot wins WHOLE, never field-by-field. A row that recorded "20,000 km" and said
     * nothing about months meant exactly that — merging today's catalog months into it would invent
     * a time limit nobody stated at install and could flip a part to 'overdue' on a clock that was
     * never set. So: any snapshot value present => the row's pair is the answer, source 'recorded'.
     * Nothing recorded (every row written before the snapshot columns existed) => fall back to the
     * catalog, source 'catalog', and let the UI say so rather than passing it off as history.
     *
     * @return array{km: ?int, months: ?int, source: string} source: recorded | catalog | none
     */
    public static function limitInForce(?int $rowKm, ?int $rowMonths, ?int $catalogKm, ?int $catalogMonths): array
    {
        if ($rowKm !== null || $rowMonths !== null) {
            return ['km' => $rowKm, 'months' => $rowMonths, 'source' => 'recorded'];
        }

        if ($catalogKm !== null || $catalogMonths !== null) {
            return ['km' => $catalogKm, 'months' => $catalogMonths, 'source' => 'catalog'];
        }

        return ['km' => null, 'months' => null, 'source' => 'none'];
    }

    /**
     * How much of the expected life a part has used.
     *
     * Distance and time are BOTH measured and the HARSHER one wins: a taxi burns through kilometres
     * while a parked car ages its rubber, and a part is due when either clock runs out. When no
     * expectation is stated the answer is 'unknown' — deliberately not a guess, because an
     * invented service interval would drive real replacement spend.
     *
     * `$limitSource` is carried through untouched so the UI can distinguish the limit this part was
     * actually fitted under from the type's present-day expectation standing in for it.
     */
    public static function serviceLife(?int $expectedKm, ?int $expectedMonths, ?int $ageDays, ?int $distanceKm, string $limitSource = 'catalog'): array
    {
        $byKm     = ($expectedKm && $distanceKm !== null) ? $distanceKm / $expectedKm * 100 : null;
        $byMonths = ($expectedMonths && $ageDays !== null) ? $ageDays / ($expectedMonths * self::DAYS_PER_MONTH) * 100 : null;

        if ($byKm === null && $byMonths === null) {
            return [
                'status'               => 'unknown',
                'life_used_pct'        => null,
                'basis'                => null,
                'expected_life_km'     => $expectedKm,
                'expected_life_months' => $expectedMonths,
                'limit_source'         => $limitSource,
            ];
        }

        $pct = max($byKm ?? -INF, $byMonths ?? -INF);

        return [
            'status' => match (true) {
                $pct >= 100                => 'overdue',
                $pct >= self::DUE_SOON_PCT => 'due_soon',
                default                    => 'within',
            },
            'life_used_pct'        => (int) round($pct),
            // Which clock ran hardest — what the operator should look at to understand the verdict.
            'basis'                => ($byKm !== null && $byKm >= ($byMonths ?? -INF)) ? 'distance' : 'age',
            'expected_life_km'     => $expectedKm,
            'expected_life_months' => $expectedMonths,
            'limit_source'         => $limitSource,
        ];
    }

    /**
     * Cost per kilometre actually delivered. Null when the part has not moved (or we never recorded
     * what it cost) — dividing by a zero distance would report an infinite cost for a part fitted
     * this morning, which is the opposite of useful.
     */
    public static function costPerKm(?float $cost, ?int $distanceKm): ?float
    {
        if ($cost === null || ! $distanceKm) {
            return null;
        }

        return round($cost / $distanceKm, 4);
    }
}
