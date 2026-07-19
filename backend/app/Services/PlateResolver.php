<?php

namespace App\Services;

use App\Models\Vehicle;

/**
 * The ONE place that decides which car a license plate belongs to.
 *
 * Plates get reused: when a car is sold, its plate is later re-issued to a different,
 * active car (e.g. plate 19397 moved off a sold vehicle onto a new one). That leaves us
 * with several vehicle rows sharing one plate — the sold history car and the current one.
 *
 * Every plate lookup in the app must resolve to the CURRENT car, never the historical one.
 * The old rows are kept for history but must never win a plate lookup. The priority is:
 *   1. Prefer a car still in the fleet over one that is sold / disposed / returned.
 *   2. If a make/model label is available (e.g. a maintenance-sheet "CAR" cell), use it to
 *      disambiguate cars that merely share plate digits by coincidence.
 *   3. Otherwise pick the newest car: highest car_serial (OM assigns them incrementally),
 *      then highest id as a final tiebreaker.
 *
 * Never fail or return an arbitrary "first match" when a plate is shared — always apply
 * this priority so callers get a deterministic, current answer.
 */
class PlateResolver
{
    /**
     * OM lifecycle statuses meaning the car has LEFT the fleet. Kept only for history —
     * a plate lookup only falls back to one of these when NO in-fleet car matches.
     * Mirrors Vehicle::OM_STATUS 7/8/9.
     */
    public const GONE_STATUSES = ['sold', 'disposed', 'returned'];

    /**
     * A plate's digits with leading zeros stripped, so "K 19397" / "0019397" / "19397" all
     * collapse to the same key. This is the canonical plate-matching key across the app.
     */
    public static function plateDigits($plate): string
    {
        return ltrim(preg_replace('/\D/', '', (string) $plate), '0');
    }

    /**
     * Look a plate up against the whole fleet and return the current car (or null).
     * Convenience wrapper for one-off lookups; hot loops should build an index once and
     * feed the candidate lists to pickBest() instead of querying per plate.
     */
    public static function resolve($plate, bool $withTrashed = false): ?Vehicle
    {
        $digits = self::plateDigits($plate);
        if ($digits === '') {
            return null;
        }

        $query = $withTrashed ? Vehicle::withTrashed() : Vehicle::query();
        $candidates = $query->whereNotNull('plate_no')->where('plate_no', '<>', '')
            ->get(['id', 'plate_no', 'make', 'model', 'status', 'car_serial'])
            ->filter(fn ($v) => self::plateDigits($v->plate_no) === $digits);

        return self::pickBest($candidates);
    }

    /**
     * Pick the current car from several that share a plate. Accepts Vehicle models or arrays
     * carrying at least id/status/car_serial (and make/model when a label is supplied), and
     * returns whichever element type it was given so callers can read ->id / ['id'] as usual.
     *
     * @param  iterable  $candidates  vehicles that already matched the plate
     * @param  string|null  $label     optional make/model text to disambiguate coincidental shares
     * @return mixed  the winning candidate, or null when the list is empty
     */
    public static function pickBest(iterable $candidates, ?string $label = null)
    {
        $list = [];
        foreach ($candidates as $c) {
            $list[] = $c;
        }
        if (count($list) === 0) {
            return null;
        }
        if (count($list) === 1) {
            return $list[0];
        }

        // 1. Prefer cars still in the fleet. Only if EVERY match is gone do we resolve among
        //    the gone ones (so a plate that only exists on history still resolves to something).
        $inFleet = array_values(array_filter($list, fn ($c) => ! self::isGone($c)));
        $pool = $inFleet ?: $list;
        if (count($pool) === 1) {
            return $pool[0];
        }

        // 2. A make/model label is a stronger signal than recency for cars that merely share
        //    plate digits — use it when it singles one out.
        if ($label !== null && $label !== '') {
            $picked = self::disambiguateByLabel($pool, $label);
            if ($picked !== null) {
                return $picked;
            }
        }

        // 3. Newest wins: highest car_serial, then highest id.
        usort($pool, fn ($a, $b) => self::recencyKey($b) <=> self::recencyKey($a));

        return $pool[0];
    }

    /**
     * Resolve which car held a plate ON a given date — for HISTORICAL / dated records.
     *
     * Unlike resolve()/pickBest() (which always pick the CURRENT car), this attaches a dated
     * event to the car that actually owned the plate at that date. So a repair logged while
     * plate 19397 was on the since-sold Camaro (serial 639) stays on THAT car, not on its
     * same-plate replacement (serial 1951) — histories never cross.
     *
     * Rule: among the cars sharing the plate, the owner as-of $date is the one with the
     * greatest purchase_date that is still <= $date (the most recently acquired car that
     * already existed then). A make/model $label first singles out coincidental digit
     * collisions. When the answer is genuinely ambiguous — a purchase_date tie, a date that
     * predates every candidate, or no date at all — this returns null and sets $reason, so the
     * caller can REPORT the row instead of guessing (never silently attach to the wrong car).
     *
     * @param  iterable     $candidates  vehicles already matched to the plate
     * @param  mixed        $date        the event date (Carbon|DateTime|string|null)
     * @param  string|null  $label       make/model text to break coincidental shares
     * @param  string|null  $reason      out-param: single|label|asof|label_tie|
     *                                    ambiguous_nodate|ambiguous_predate|ambiguous_tie|none
     * @return mixed  the owning candidate, or null when ambiguous/empty
     */
    public static function resolveAsOf(iterable $candidates, $date, ?string $label = null, ?string &$reason = null)
    {
        $list = [];
        foreach ($candidates as $c) {
            $list[] = $c;
        }
        if (count($list) === 0) {
            $reason = 'none';
            return null;
        }
        if (count($list) === 1) {
            $reason = 'single';
            return $list[0];
        }

        // A make/model label is decisive for cars that merely share plate digits (e.g. two
        // different cars, or a cross-emirate collision). If it singles one out, trust it.
        if ($label !== null && $label !== '') {
            $single = self::disambiguateByLabel($list, $label);
            if ($single !== null) {
                $reason = 'label';
                return $single;
            }
        }

        $ts = self::toTimestamp($date);
        if ($ts === null) {
            $reason = 'ambiguous_nodate'; // several candidates, no date to place the event
            return null;
        }

        // Only cars that already existed on/before the event date can have owned the plate then.
        $eligible = array_values(array_filter($list, function ($c) use ($ts) {
            $pd = self::toTimestamp(self::field($c, 'purchase_date'));
            return $pd !== null && $pd <= $ts;
        }));
        if (count($eligible) === 0) {
            $reason = 'ambiguous_predate'; // event predates every candidate's purchase
            return null;
        }

        // The most recently acquired car that already existed = the owner as-of the date.
        usort($eligible, fn ($a, $b) => self::toTimestamp(self::field($b, 'purchase_date'))
            <=> self::toTimestamp(self::field($a, 'purchase_date')));
        $topPd = self::toTimestamp(self::field($eligible[0], 'purchase_date'));
        $tied = array_values(array_filter($eligible, fn ($c) => self::toTimestamp(self::field($c, 'purchase_date')) === $topPd));

        if (count($tied) === 1) {
            $reason = 'asof';
            return $tied[0];
        }

        // Same purchase_date on the frontier (often a bulk-import placeholder) — last chance
        // is the label; otherwise it's a real tie and we refuse to guess.
        if ($label !== null && $label !== '') {
            $single = self::disambiguateByLabel($tied, $label);
            if ($single !== null) {
                $reason = 'label_tie';
                return $single;
            }
        }
        $reason = 'ambiguous_tie';
        return null;
    }

    /** Coerce a Carbon/DateTime/date-string into a UNIX timestamp, or null if unusable. */
    protected static function toTimestamp($d): ?int
    {
        if (empty($d)) {
            return null;
        }
        if ($d instanceof \DateTimeInterface) {
            return $d->getTimestamp();
        }
        $t = strtotime((string) $d);
        return $t === false ? null : $t;
    }

    /** True when the car's OM status means it has left the fleet (sold / disposed / returned). */
    protected static function isGone($c): bool
    {
        return in_array((string) self::field($c, 'status'), self::GONE_STATUSES, true);
    }

    /** Recency ordering key: [car_serial, id] as ints, both descending in pickBest. */
    protected static function recencyKey($c): array
    {
        return [(int) self::field($c, 'car_serial'), (int) self::field($c, 'id')];
    }

    /**
     * Several cars share this plate — pick the one whose make (then model, as a tiebreaker)
     * appears in the label, e.g. "NISSAN PATROL - Brown - 2019 - K 19397". Returns a candidate
     * ONLY when exactly one matches, so an ambiguous label falls through to the recency rule.
     */
    protected static function disambiguateByLabel(array $candidates, string $label)
    {
        $label = strtoupper($label);

        $byMake = array_values(array_filter($candidates, function ($c) use ($label) {
            $make = strtoupper(trim((string) self::field($c, 'make')));
            return $make !== '' && str_contains($label, $make);
        }));
        if (count($byMake) === 1) {
            return $byMake[0];
        }

        $pool = $byMake ?: $candidates;
        $byModel = array_values(array_filter($pool, function ($c) use ($label) {
            $model = strtoupper(trim((string) self::field($c, 'model')));
            return $model !== '' && str_contains($label, $model);
        }));

        return count($byModel) === 1 ? $byModel[0] : null;
    }

    /** Read a field off a candidate whether it's a Vehicle model or a plain array. */
    protected static function field($c, string $key)
    {
        if (is_array($c)) {
            return $c[$key] ?? null;
        }

        return $c->{$key} ?? null;
    }
}
