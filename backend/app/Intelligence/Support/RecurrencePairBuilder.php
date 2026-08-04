<?php

namespace App\Intelligence\Support;

use Generator;

/**
 * Builds the recurrence chain: for each fault event on a car, when did that same fault next appear?
 *
 * ── THE DEDUPLICATION IS THE WHOLE POINT ─────────────────────────────────────────────────────────
 * `maintenance_signatures` holds SEVERAL rows for one fault on one car on one day. A ticket can
 * carry both a `derived` and a `human` label; a classifier can match several terms; several tickets
 * can share a date. Measured on live data, 33,026 raw fault rows collapse to **12,608 distinct fault
 * events** — a 2.6× duplication factor, and as high as 3.4× for individual garages.
 *
 * Running a LEAD() window over the raw rows counts each duplicate as its own recurrence. That is not
 * a rounding error: it inflated the first published garage figures by roughly 2.5–3× and made every
 * sample size look far stronger than it was. One garage's headline moved from "18.2 days over 209
 * repairs" to "19.6 days over 29 repairs" — the same signal, but now correctly below the platform's
 * own minimum-sample gate.
 *
 * So STEP 1 is always: collapse to one event per (vehicle_id, signature, occurred_at). The unique
 * index on the destination table enforces the same rule at the storage layer, so a future change
 * that reintroduces duplicates fails loudly instead of quietly doubling the numbers.
 *
 * ── VENDOR ATTRIBUTION ───────────────────────────────────────────────────────────────────────────
 * A deduplicated event may span more than one garage: 2,325 vehicle-days show the same car at two
 * different vendors. We attribute deterministically to the LOWEST maintenance_id of that day — an
 * arbitrary but stable rule — and flag the event `multi_vendor_day` so those cases stay findable
 * rather than being silently assigned.
 *
 * ── OPEN CHAINS ARE KEPT ─────────────────────────────────────────────────────────────────────────
 * Events with no later occurrence are emitted with a null `next_occurred_at`. They are the
 * denominator: a garage whose repairs never come back must be visible as exactly that. Dropping
 * them would make every recurrence rate meaningless.
 *
 * ── CONTRACT ─────────────────────────────────────────────────────────────────────────────────────
 * Pure and streaming. Rows arrive PRE-SORTED by (vehicle_id, signature, occurred_at, maintenance_id).
 * Callers must pre-filter `is_exposure = 0` — exposure rows record that a car was exposed to a
 * system, not that the system failed, and counting them roughly doubles every fault number.
 *
 * ── RIGHT-CENSORING ─────────────────────────────────────────────────────────────────────────────
 * Each event also carries `days_observed` — how long it has been watched, measured against the
 * corpus edge rather than the wall clock. A repair completed last week cannot have come back within
 * 90 days yet, and counting it as one that HELD flatters every garage, the busiest ones most.
 * Consumers filter `days_observed >= window` rather than each deriving a horizon of their own, which
 * is how the platform previously ended up with three different ones.
 *
 * HISTORICAL: includes soft-deleted tickets, for the reason given in VisitCollapser.
 */
final class RecurrencePairBuilder
{
    /**
     * @param  iterable<array|object>  $rows       pre-sorted signature rows
     * @param  string|null             $corpusMax  MAX(occurred_at) across the whole corpus, for
     *                                             right-censoring. Null leaves days_observed null,
     *                                             which the rebuild's validation then rejects.
     */
    public function build(iterable $rows, ?string $corpusMax = null): Generator
    {
        $this->corpusMax = $corpusMax === null ? null : substr($corpusMax, 0, 10);

        $partition = null;   // [vehicle_id, signature]
        $events    = [];     // deduplicated events for the current partition

        foreach ($rows as $raw) {
            $row = $this->normalise($raw);

            if ($row['vehicle_id'] === null || $row['occurred_at'] === null || $row['signature'] === null) {
                continue; // cannot be placed on a chain; the command counts these
            }

            $key = $row['vehicle_id'] . '|' . $row['signature'];

            if ($partition !== null && $key !== $partition) {
                yield from $this->emit($events);
                $events = [];
            }

            $partition = $key;
            $this->accumulate($events, $row);
        }

        if ($events !== []) {
            yield from $this->emit($events);
        }
    }

    /**
     * STEP 1 — deduplicate to one event per (vehicle, signature, date).
     *
     * Rows are sorted, so same-day rows arrive consecutively and the last accumulated event is the
     * only one that can match.
     */
    private function accumulate(array &$events, array $row): void
    {
        $last = $events === [] ? null : $events[array_key_last($events)];

        if ($last !== null && $last['occurred_at'] === $row['occurred_at']) {
            $events[array_key_last($events)] = $this->merge($last, $row);

            return;
        }

        $events[] = [
            'vehicle_id'        => $row['vehicle_id'],
            'signature'         => $row['signature'],
            'occurred_at'       => $row['occurred_at'],
            'maintenance_id'    => $row['maintenance_id'],
            'vendor_id'         => $row['vendor_id'],
            'source_row_count'  => 1,
            'label_sources'     => $row['source'] !== null ? [$row['source'] => true] : [],
            'vendor_ids_seen'   => $row['vendor_id'] !== null ? [$row['vendor_id'] => true] : [],
        ];
    }

    private function merge(array $event, array $row): array
    {
        $event['source_row_count']++;

        if ($row['source'] !== null) {
            $event['label_sources'][$row['source']] = true;
        }

        if ($row['vendor_id'] !== null) {
            $event['vendor_ids_seen'][$row['vendor_id']] = true;
        }

        // Deterministic attribution: lowest maintenance_id wins, and it brings its vendor with it.
        if ($row['maintenance_id'] !== null
            && ($event['maintenance_id'] === null || $row['maintenance_id'] < $event['maintenance_id'])) {
            $event['maintenance_id'] = $row['maintenance_id'];
            $event['vendor_id']      = $row['vendor_id'];
        }

        return $event;
    }

    /** STEPS 2–4 — order, look ahead, derive. */
    private function emit(array $events): Generator
    {
        $count = count($events);

        foreach ($events as $i => $event) {
            $next = $events[$i + 1] ?? null;

            $days = null;
            if ($next !== null) {
                $days = (int) round((strtotime($next['occurred_at']) - strtotime($event['occurred_at'])) / 86400);
            }

            // How long this event has been watched. Anchored on the corpus edge, not CURDATE():
            // the corpus ends before today (signatures lag a sheet import), so the clock would
            // silently discard several hundred fully-observed rows.
            $observed = $this->corpusMax === null ? null : max(0, (int) round(
                (strtotime($this->corpusMax) - strtotime($event['occurred_at'])) / 86400
            ));

            $labels = array_keys($event['label_sources']);
            sort($labels);

            yield [
                'vehicle_id'           => $event['vehicle_id'],
                'signature'            => $event['signature'],
                'occurred_at'          => $event['occurred_at'],
                'first_maintenance_id' => $event['maintenance_id'],
                'first_vendor_id'      => $event['vendor_id'],
                'next_occurred_at'     => $next['occurred_at'] ?? null,
                'next_maintenance_id'  => $next['maintenance_id'] ?? null,
                'next_vendor_id'       => $next['vendor_id'] ?? null,
                'days_to_return'       => $days,
                'days_observed'        => $observed,
                'returned_30'          => $days !== null && $days <= 30,
                'returned_60'          => $days !== null && $days <= 60,
                'returned_90'          => $days !== null && $days <= 90,
                'same_vendor'          => $next === null || $event['vendor_id'] === null || $next['vendor_id'] === null
                                            ? null
                                            : $event['vendor_id'] === $next['vendor_id'],
                'label_source'         => count($labels) > 1 ? 'both' : ($labels[0] ?? 'derived'),
                'source_row_count'     => $event['source_row_count'],
                'multi_vendor_day'     => count($event['vendor_ids_seen']) > 1,
                'chain_position'       => $i + 1,
                'chain_length'         => $count,
            ];
        }
    }

    /** The corpus edge every event is censored against; null disables censoring. */
    private ?string $corpusMax = null;

    private function normalise(array|object $raw): array
    {
        $r = (array) $raw;

        $date = $r['occurred_at'] ?? null;
        if ($date === '' || (is_string($date) && str_starts_with($date, '0000-00-00'))) {
            $date = null;
        }

        return [
            'vehicle_id'     => isset($r['vehicle_id']) ? (int) $r['vehicle_id'] : null,
            'signature'      => $r['signature'] ?? null,
            'occurred_at'    => $date === null ? null : substr((string) $date, 0, 10),
            'maintenance_id' => isset($r['maintenance_id']) ? (int) $r['maintenance_id'] : null,
            'vendor_id'      => isset($r['vendor_id']) ? (int) $r['vendor_id'] : null,
            'source'         => $r['source'] ?? null,
        ];
    }
}
