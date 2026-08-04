<?php

namespace App\Intelligence\Support;

use Generator;

/**
 * Turns maintenance EVENTS into repair VISITS.
 *
 * ── WHY ──────────────────────────────────────────────────────────────────────────────────────────
 * The legacy Google Sheet modelled one workshop trip as several rows: an `OUT`, then `Follow up`,
 * then `IN`, sometimes a `Change`. Counting `maintenances` rows as repairs therefore overstates
 * repair volume by roughly 2×. Measured on live data, only **23%** of (vehicle, garage, out_date)
 * groups are a single row; 4,030 groups hold exactly two, and the tail reaches twelve.
 *
 * ── WHY THE WINDOW IS ZERO ───────────────────────────────────────────────────────────────────────
 * The window was originally specified as "≤3 days, tune later". Tuned against the real gap
 * distribution between consecutive tickets for the same (vehicle, garage):
 *
 *     gap (days):  0      1    2    3    4    5    6    7    8    9   10
 *     pairs:      15,045 122   82  100  123  132  148  128  124  127  124
 *
 * There is no elbow. Day 0 carries 15,045 pairs; every day after it sits flat around 120, which is
 * the fleet's ordinary revisit rate — not the tail of one visit. A 3-day window would have merged
 * roughly 300 genuinely separate visits on no evidence, and there would have been no way to defend
 * 3 over 5 or 10. So the rule is same-day, and `windowDays` stays configurable only so that a future
 * change is a deliberate, auditable act rather than a rewrite.
 *
 * ── CONTRACT ─────────────────────────────────────────────────────────────────────────────────────
 * Pure and streaming: no database, no models. Rows arrive PRE-SORTED by
 * (vehicle_id, vendor_id, out_date, id) and visits are yielded as each group closes, so the rebuild
 * command can process the full corpus without holding it in memory.
 *
 * HISTORICAL: the caller is expected to include soft-deleted tickets. A retired ticket is a repair
 * that really happened — the car was really off the road — and dropping it would let history rewrite
 * itself every time somebody tidied the board. This matches OperationalKpiService's reasoning.
 */
final class VisitCollapser
{
    /** Same-day only. See the class docblock before changing this. */
    public const DEFAULT_WINDOW_DAYS = 0;

    /** Workflow states that mean "this never became a repair". */
    private const CANCELLED_STATES = ['review_rejected', 'cancelled'];

    public function __construct(
        private readonly int $windowDays = self::DEFAULT_WINDOW_DAYS,
    ) {
    }

    /**
     * @param  iterable<array|object>  $rows       pre-sorted maintenance rows
     * @param  array<string, bool>     $multiVendorDays  keys of "vehicleId|Y-m-d" seen at >1 garage
     * @return Generator<array>        one visit per group
     */
    public function collapse(iterable $rows, array $multiVendorDays = []): Generator
    {
        $group = null;

        foreach ($rows as $raw) {
            $row = $this->normalise($raw);

            // No out_date means no event clock: we cannot place it in time, so it cannot be a visit.
            // The command counts these (1,385 rows) and reports them — never a silent drop.
            if ($row['out_date'] === null) {
                continue;
            }

            if ($group !== null && $this->belongsTo($group, $row)) {
                $this->absorb($group, $row);
                continue;
            }

            if ($group !== null) {
                yield $this->finish($group, $multiVendorDays);
            }

            $group = $this->start($row);
        }

        if ($group !== null) {
            yield $this->finish($group, $multiVendorDays);
        }
    }

    /**
     * Same car, same garage, and within the window of the group's LAST event.
     *
     * NULL vehicle_id (646 rows) and NULL vendor_id (1,738 rows) are compared as themselves, so
     * unattributed tickets group with unattributed tickets and never fall into a real garage's
     * numbers. They still become visits: the repair happened, we just cannot say where or to what.
     */
    private function belongsTo(array $group, array $row): bool
    {
        if ($group['vehicle_id'] !== $row['vehicle_id'] || $group['vendor_id'] !== $row['vendor_id']) {
            return false;
        }

        return $this->daysBetween($group['last_out_date'], $row['out_date']) <= $this->windowDays;
    }

    private function start(array $row): array
    {
        return [
            'vehicle_id'    => $row['vehicle_id'],
            'vendor_id'     => $row['vendor_id'],
            'started_at'    => $row['out_date'],
            'last_out_date' => $row['out_date'],
            'ended_at'      => $row['actual_in_date'],
            'ids'           => [$row['id']],
            'origins'       => $row['origin'] !== null ? [$row['origin'] => true] : [],
            'cancelled'     => $row['is_cancelled'],
        ];
    }

    private function absorb(array &$group, array $row): void
    {
        $group['ids'][]         = $row['id'];
        $group['last_out_date'] = $row['out_date'];

        if ($row['origin'] !== null) {
            $group['origins'][$row['origin']] = true;
        }

        // The visit ends when the LAST of its events ends.
        if ($row['actual_in_date'] !== null
            && ($group['ended_at'] === null || $row['actual_in_date'] > $group['ended_at'])) {
            $group['ended_at'] = $row['actual_in_date'];
        }

        // A visit counts as cancelled only if every event in it was.
        $group['cancelled'] = $group['cancelled'] && $row['is_cancelled'];
    }

    private function finish(array $group, array $multiVendorDays): array
    {
        $origins = array_keys($group['origins']);
        sort($origins);

        $duration = null;
        if ($group['ended_at'] !== null) {
            $days = $this->daysBetween($group['started_at'], $group['ended_at']);
            // 31 tickets close BEFORE they open. The visit is real; only its duration is unusable,
            // so we null the duration and keep the row rather than discarding evidence.
            $duration = $days >= 0 ? $days : null;
        }

        $ids = $group['ids'];
        sort($ids);

        $dayKey = $group['vehicle_id'] . '|' . $group['started_at'];

        return [
            'vehicle_id'             => $group['vehicle_id'],
            'vendor_id'              => $group['vendor_id'],
            'started_at'             => $group['started_at'],
            'ended_at'               => $group['ended_at'],
            'duration_days'          => $duration,
            'is_open'                => $group['ended_at'] === null,
            'is_cancelled'           => $group['cancelled'],
            'event_row_count'        => count($ids),
            'maintenance_ids'        => $ids,
            'primary_maintenance_id' => $ids[0],
            'origin_mix'             => implode('+', $origins),
            'has_close_date'         => $group['ended_at'] !== null,
            'multi_vendor_day'       => isset($multiVendorDays[$dayKey]),
            'grouping_window_days'   => $this->windowDays,
        ];
    }

    /** Rows may arrive as arrays (raw DB cursor) or objects (stdClass) — accept both. */
    private function normalise(array|object $raw): array
    {
        $r = (array) $raw;

        $workflow = $r['workflow_status'] ?? null;

        return [
            'id'             => (int) ($r['id'] ?? 0),
            'vehicle_id'     => isset($r['vehicle_id']) ? (int) $r['vehicle_id'] : null,
            'vendor_id'      => isset($r['vendor_id']) ? (int) $r['vendor_id'] : null,
            'out_date'       => $this->date($r['out_date'] ?? null),
            'actual_in_date' => $this->date($r['actual_in_date'] ?? null),
            'origin'         => $r['origin'] ?? null,
            'is_cancelled'   => $workflow !== null && in_array($workflow, self::CANCELLED_STATES, true),
        ];
    }

    private function date(?string $value): ?string
    {
        if ($value === null || $value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        return substr($value, 0, 10);
    }

    private function daysBetween(string $from, string $to): int
    {
        return (int) round((strtotime($to) - strtotime($from)) / 86400);
    }
}
