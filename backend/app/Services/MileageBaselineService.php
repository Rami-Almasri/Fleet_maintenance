<?php

namespace App\Services;

use App\Models\Vehicle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Global Mileage Baseline — the self-healing odometer engine.
 *
 * Every contract handover records the car's odometer: `out_milage` when it leaves the branch,
 * `in_milage` when it comes back. Those readings, ordered in time, ARE the car's true mileage
 * history — far more trustworthy than the single, hand-edited odometer on the car card (which
 * keeps getting typo'd to 0, 1 or an extra digit). This service treats that history as the
 * source of truth and does three things:
 *
 *   1. BASELINE  — the EARLIEST valid out_milage ever recorded for the car ("Start-Mileage").
 *                  Persisted to vehicles.baseline_odometer as a stable anchor.
 *   2. VALIDATE  — walk the readings chronologically against the last ACCEPTED reading; each
 *                  must be >= it and must not jump implausibly far. Drops (mileage running
 *                  backwards) and massive jumps (typos / extra digits) are collected as
 *                  anomalies for the /anomalies dashboard instead of being silently accepted.
 *   3. CORRECT   — heal the live odometer from the validated chain so the car card reflects
 *                  history, not a stale/typo'd manual number (see correction policy below).
 *
 * Placeholder readings (null / 0 / 1) are ignored everywhere — they are the "no reading"
 * sentinels the branch types when it doesn't record the odometer (matching DataHealthService's
 * "cars without mileage" rule).
 *
 * NOTE on sources: the `maintenances` table carries NO mileage. "Maintenance sheets" feed in
 * through the type-'U' maintenance CONTRACTS, which live in `contracts` alongside rentals and
 * are scanned here automatically — so rental + maintenance history are both covered.
 */
class MileageBaselineService
{
    /** Readings at or below this are placeholders ("no reading"), never real mileage. */
    public const PLACEHOLDER_MAX = 1;

    /** Plausible upper bound on distance a car covers per day; above it, a jump is a typo. */
    public const MAX_KM_PER_DAY = 1500;

    /** Ignore jumps smaller than this even if the per-day rate is exceeded (cuts noise). */
    public const JUMP_FLOOR_KM = 30000;

    /**
     * Backward steps smaller than this are treated as handover-reading NOISE, not flagged.
     * Hand-recorded odometers routinely disagree by a few dozen km between a return and the next
     * pickup; in this data the median backward step is ~80 km and ~half are under 100 km. We never
     * let ANY drop push the odometer backwards, but only meaningful drops become anomalies. Lower
     * this toward 0 for a strict "odometer must never decrease" audit.
     */
    public const ROLLBACK_FLOOR_KM = 100;

    /** Cars that have left the fleet — their history doesn't need healing or flagging. */
    private const GONE = ['sold', 'disposed'];

    /** One scan cached per instance so AnomalyService can read both anomaly groups cheaply. */
    private ?array $cache = null;

    /**
     * Scan the whole active fleet's contract mileage history.
     *
     * @return array{
     *   vehicles: array<int, array{vehicle_id:int, baseline:?int, latest_valid:?int,
     *     latest_valid_kind:?string, current_odometer:?int, stored_baseline:?int, origin:?string}>,
     *   rollbacks: list<array>, jumps: list<array>
     * }
     */
    public function scan(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        // Active fleet only — sold/disposed cars have left, so their mileage doesn't matter.
        $vehicles = Vehicle::whereNotIn('status', self::GONE)
            ->get(['id', 'plate_no', 'make', 'model', 'odometer', 'origin', 'baseline_odometer'])
            ->keyBy('id');

        if ($vehicles->isEmpty()) {
            return $this->cache = ['vehicles' => [], 'rollbacks' => [], 'jumps' => []];
        }

        // Every handover reading for those cars in one cheap query (8 columns).
        $rows = DB::table('contracts')
            ->whereNull('deleted_at')
            ->whereNotNull('vehicle_id')
            ->whereIn('vehicle_id', $vehicles->keys())
            ->orderBy('vehicle_id')
            ->get(['id', 'vehicle_id', 'contract_no', 'contract_type', 'out_date', 'out_milage', 'in_date', 'in_milage']);

        // Group raw rows per vehicle so each car's chain is analysed independently.
        $rowsByVehicle = [];
        foreach ($rows as $r) {
            $rowsByVehicle[$r->vehicle_id][] = $r;
        }

        $perVehicle = [];
        $rollbacks  = [];   // one aggregated row per affected car
        $jumps      = [];   // one aggregated row per affected car
        $rollbackEvents = 0; // total backward readings (across all cars) for reporting
        $jumpEvents     = 0; // total implausible jumps (across all cars) for reporting

        foreach ($vehicles as $vid => $v) {
            $a = $this->analyze($this->buildEvents($rowsByVehicle[$vid] ?? []));

            if ($a['rb']['count'] > 0) {
                $rollbacks[] = $this->anomalyRow($v, 'drop', $a['rb']);
                $rollbackEvents += $a['rb']['count'];
            }
            if ($a['jp']['count'] > 0) {
                $jumps[] = $this->anomalyRow($v, 'jump', $a['jp']);
                $jumpEvents += $a['jp']['count'];
            }

            $perVehicle[$vid] = $this->vehicleRow($v, $a['baseline'], $a['latest_valid'], $a['latest_kind']);
        }

        return $this->cache = [
            'vehicles'        => $perVehicle,
            'rollbacks'       => $rollbacks,
            'jumps'           => $jumps,
            'rollback_events' => $rollbackEvents,
            'jump_events'     => $jumpEvents,
        ];
    }

    /**
     * Expand a vehicle's raw contract rows into chronological reading EVENTS (out + in), keeping
     * only real readings. Sorted by date, then OUT before IN on the same day, then contract id —
     * the single ordering used by both the fleet scan and the per-car Apply action.
     *
     * @param  iterable<object>  $rows  rows with id, contract_no, out_date, out_milage, in_date, in_milage
     * @return list<array{date:Carbon, milage:int, kind:string, contract_id:int, contract_no:?string}>
     */
    private function buildEvents(iterable $rows): array
    {
        $events = [];
        foreach ($rows as $r) {
            foreach ([['out', $r->out_date, $r->out_milage], ['in', $r->in_date, $r->in_milage]] as [$kind, $date, $milage]) {
                if ($date === null || ! $this->isReal($milage)) {
                    continue;
                }
                $events[] = [
                    'date'        => Carbon::parse($date),
                    'milage'      => (int) $milage,
                    'kind'        => $kind,
                    'contract_id' => $r->id,
                    'contract_no' => $r->contract_no,
                ];
            }
        }
        usort($events, fn ($a, $b) => [$a['date']->timestamp, $a['kind'] === 'in' ? 1 : 0, $a['contract_id']]
            <=> [$b['date']->timestamp, $b['kind'] === 'in' ? 1 : 0, $b['contract_id']]);

        return $events;
    }

    /**
     * Analyse one car's sorted reading chain: derive the baseline (earliest valid OUT reading,
     * the user's "Start-Mileage"), the latest VALIDATED reading (the healed current odometer), and
     * the per-car rollback / jump aggregates. Each reading is compared to the last ACCEPTED one so
     * a single bad reading flags once without knocking the rest of the chain out of sync.
     *
     * @param  list<array>  $events  from buildEvents()
     * @return array{baseline:?int, latest_valid:?int, latest_kind:?string, rb:array, jp:array}
     */
    private function analyze(array $events): array
    {
        $rb = $this->newAgg();
        $jp = $this->newAgg();
        if (! $events) {
            return ['baseline' => null, 'latest_valid' => null, 'latest_kind' => null, 'rb' => $rb, 'jp' => $jp];
        }

        // Baseline = earliest valid OUT reading; fall back to the first reading if the car was
        // only ever returned (no out reading on file).
        $baseline = null;
        foreach ($events as $e) {
            if ($e['kind'] === 'out') {
                $baseline = $e['milage'];
                break;
            }
        }
        $baseline ??= $events[0]['milage'];

        $lastValid = null;
        foreach ($events as $e) {
            if ($lastValid === null) {
                $lastValid = $e;
                continue;
            }
            $delta = $e['milage'] - $lastValid['milage'];
            $days  = max(0, (int) $lastValid['date']->diffInDays($e['date']));

            if ($delta < 0) {
                // A drop never advances the chain (odometers don't run backwards), but only a
                // meaningful one (beyond reading noise) is flagged as an anomaly.
                if (abs($delta) > self::ROLLBACK_FLOOR_KM) {
                    $this->recordAgg($rb, $lastValid, $e, abs($delta));
                }
            } elseif ($delta > self::JUMP_FLOOR_KM && $delta > $days * self::MAX_KM_PER_DAY) {
                $this->recordAgg($jp, $lastValid, $e, $delta); // suspicious jump — don't advance
            } else {
                $lastValid = $e; // consistent reading — it advances the validated chain
            }
        }

        return [
            'baseline'     => $baseline,
            'latest_valid' => $lastValid['milage'] ?? null,
            'latest_kind'  => $lastValid['kind'] ?? null,
            'rb'           => $rb,
            'jp'           => $jp,
        ];
    }

    /**
     * Data Reconciliation rows for the Mileage Reconciliation page.
     *
     * The `summary` ALWAYS describes the full funnel of active cars (categorised against the fixed
     * review threshold, ROLLBACK_FLOOR_KM), so the page can show where every car landed regardless
     * of the current filter:
     *   - needs_review     : |system − scanner| > review threshold (the cars to eyeball)
     *   - within_tolerance : 0 < |diff| ≤ review threshold (handover-reading noise)
     *   - matching         : system already equals the scanner value (mostly auto-healed by apply())
     *   - no_history       : no valid contract reading to compare against
     *
     * What's RETURNED in `rows` is controlled by the view:
     *   - $includeAll = true   → every active car (full audit), regardless of gap.
     *   - $includeAll = false  → only cars with |diff| > $minDiff (the review queue; default 100 km).
     *
     * @return array{rows: list<array>, threshold:int, include_all:bool, total:int, summary:array}
     */
    public function reconciliation(int $minDiff = self::ROLLBACK_FLOOR_KM, bool $includeAll = false): array
    {
        $rows = [];
        $summary = ['needs_review' => 0, 'within_tolerance' => 0, 'matching' => 0, 'no_history' => 0, 'total_gap_km' => 0];

        foreach ($this->scan()['vehicles'] as $r) {
            $scanner = $r['latest_valid'];
            $system  = $r['current_odometer'];

            // No history to compare against — its own bucket.
            if ($scanner === null) {
                $summary['no_history']++;
                if ($includeAll) {
                    $rows[] = $this->reconRow($r, null, null, 'no_history');
                }
                continue;
            }

            $diff = ($system ?? 0) - $scanner;
            $abs  = abs($diff);

            // Categorise for the (filter-independent) summary funnel.
            if ($abs > self::ROLLBACK_FLOOR_KM) {
                $summary['needs_review']++;
                $summary['total_gap_km'] += $abs;
                $status = 'needs_review';
            } elseif ($abs > 0) {
                $summary['within_tolerance']++;
                $status = 'within_tolerance';
            } else {
                $summary['matching']++;
                $status = 'correct';
            }

            if ($includeAll || $abs > $minDiff) {
                $rows[] = $this->reconRow($r, $scanner, $diff, $status);
            }
        }

        // Needs-review first, then by gap size (biggest first); matching/no-history sink to the bottom.
        $rank = ['needs_review' => 0, 'within_tolerance' => 1, 'correct' => 2, 'no_history' => 3];
        usort($rows, fn ($a, $b) => [$rank[$a['status']] ?? 9, -abs($a['difference'] ?? 0)]
            <=> [$rank[$b['status']] ?? 9, -abs($b['difference'] ?? 0)]);

        return [
            'rows'        => $rows,
            'threshold'   => $minDiff,
            'include_all' => $includeAll,
            'total'       => count($rows),
            'summary'     => $summary,
        ];
    }

    /** One reconciliation row (a car's system odometer vs the scanner's validated value). */
    private function reconRow(array $r, ?int $scanner, ?int $diff, string $status): array
    {
        return [
            'vehicle_id'      => $r['vehicle_id'],
            'plate'           => $r['plate'],
            'car'             => $r['car'],
            'system_odometer' => $r['current_odometer'],
            'scanner_value'   => $scanner,
            'baseline'        => $r['baseline'],
            'difference'      => $diff,
            'status'          => $status,
        ];
    }

    /**
     * "Apply Baseline" — manually adopt the scanner's validated current reading as the car's
     * odometer for ONE vehicle. Recomputes from contract history (same algorithm as the fleet
     * scan) so the applied value always matches what the page showed, and (re)writes the baseline
     * anchor. Returns before/after for the UI.
     *
     * @throws \RuntimeException when the car has no valid reading to adopt.
     */
    public function applyOne(Vehicle $v): array
    {
        $rows = DB::table('contracts')
            ->whereNull('deleted_at')
            ->where('vehicle_id', $v->id)
            ->get(['id', 'contract_no', 'out_date', 'out_milage', 'in_date', 'in_milage']);

        $a = $this->analyze($this->buildEvents($rows));
        if ($a['latest_valid'] === null) {
            throw new \RuntimeException('No valid contract mileage on record for this car — nothing to apply.');
        }

        $old = $v->odometer !== null ? (int) $v->odometer : null;
        $v->odometer = $a['latest_valid'];
        if ($a['baseline'] !== null) {
            $v->baseline_odometer  = $a['baseline'];
            $v->baseline_synced_at = now();
        }
        $v->save();

        return [
            'vehicle_id'      => $v->id,
            'old'             => $old,
            'new'             => $a['latest_valid'],
            'baseline'        => $a['baseline'],
            'difference'      => ($old ?? 0) - $a['latest_valid'],
            'system_odometer' => $a['latest_valid'],   // post-apply system value (now == scanner)
            'scanner_value'   => $a['latest_valid'],
        ];
    }

    /**
     * Persist the results of a scan: write each car's baseline_odometer and heal its live
     * odometer from the validated chain.
     *
     * Correction policy for the live `odometer` (web-origin cars are never touched):
     *   - current is empty / 0 / 1 (placeholder)        -> seed it from history
     *   - history is ahead of current (current < latest) -> raise it (odometers never run backwards)
     *   - current sits > JUMP_FLOOR_KM ABOVE the latest   -> heal it DOWN (the card has a typo;
     *     a genuinely-further car would already show that distance as its latest out reading)
     *   - otherwise                                       -> leave it (don't churn a fresher reading)
     *
     * @return array{dry_run:bool, vehicles_scanned:int, baseline_set:int, odometer_corrected:int,
     *   rollbacks:int, jumps:int, samples:list<array{plate:?string, from:?int, to:int}>}
     */
    public function apply(bool $dryRun = false): array
    {
        $scan = $this->scan();
        $now  = now();
        $baselineSet = 0;
        $odoCorrected = 0;
        $samples = [];

        foreach ($scan['vehicles'] as $vid => $r) {
            $updates = [];

            // 1. Baseline anchor — (re)write when we have one and it changed.
            if ($r['baseline'] !== null && $r['baseline'] !== $r['stored_baseline']) {
                $updates['baseline_odometer']  = $r['baseline'];
                $updates['baseline_synced_at'] = $now;
                $baselineSet++;
            }

            // 2. Live odometer correction from the validated chain.
            $target = $r['latest_valid'];
            $cur    = $r['current_odometer'];
            if ($target !== null && $r['origin'] !== 'web') {
                $shouldCorrect = ($cur === null || $cur <= self::PLACEHOLDER_MAX)   // placeholder -> seed
                    || ($cur < $target)                                            // behind -> raise
                    || ($cur - $target > self::JUMP_FLOOR_KM);                      // typo above chain -> heal down

                if ($shouldCorrect && $cur !== $target) {
                    $updates['odometer'] = $target;
                    $odoCorrected++;
                    if (count($samples) < 25) {
                        $samples[] = ['plate' => $r['plate'], 'from' => $cur, 'to' => $target];
                    }
                }
            }

            if ($updates && ! $dryRun) {
                Vehicle::where('id', $vid)->update($updates);
            }
        }

        return [
            'dry_run'            => $dryRun,
            'vehicles_scanned'   => count($scan['vehicles']),
            'baseline_set'       => $baselineSet,
            'odometer_corrected' => $odoCorrected,
            'rollback_cars'      => count($scan['rollbacks']),
            'rollback_events'    => $scan['rollback_events'],
            'jump_cars'          => count($scan['jumps']),
            'jump_events'        => $scan['jump_events'],
            'samples'            => $samples,
        ];
    }

    /** Mileage-rollback anomaly rows (a later reading lower than an earlier accepted one). */
    public function rollbacks(): array
    {
        return $this->scan()['rollbacks'];
    }

    /** Implausible-jump anomaly rows (two readings too far apart for the elapsed time). */
    public function jumps(): array
    {
        return $this->scan()['jumps'];
    }

    // ---- helpers -------------------------------------------------------------

    private function isReal($milage): bool
    {
        return $milage !== null && (int) $milage > self::PLACEHOLDER_MAX;
    }

    /** Per-vehicle scan result, carrying everything apply() needs to decide corrections. */
    private function vehicleRow(Vehicle $v, ?int $baseline, ?int $latestValid, ?string $latestKind): array
    {
        return [
            'vehicle_id'        => $v->id,
            'plate'             => $v->plate_no,
            'car'               => trim($v->make . ' ' . $v->model) ?: null,
            'baseline'          => $baseline,
            'latest_valid'      => $latestValid,
            'latest_valid_kind' => $latestKind,
            'current_odometer'  => $v->odometer !== null ? (int) $v->odometer : null,
            'stored_baseline'   => $v->baseline_odometer !== null ? (int) $v->baseline_odometer : null,
            'origin'            => $v->origin,
        ];
    }

    /** A fresh per-vehicle anomaly accumulator (keeps the worst example + an occurrence count). */
    private function newAgg(): array
    {
        return ['count' => 0, 'worst_mag' => 0, 'from' => null, 'to' => null];
    }

    /** Record one anomalous step, keeping the largest-magnitude one as the example to show. */
    private function recordAgg(array &$agg, array $from, array $to, int $magnitude): void
    {
        $agg['count']++;
        if ($magnitude >= $agg['worst_mag']) {
            $agg['worst_mag'] = $magnitude;
            $agg['from'] = $from;
            $agg['to']   = $to;
        }
    }

    /**
     * One aggregated anomaly row per car, shaped like every other /anomalies item (links +
     * detail). The detail leads with the worst example and notes how many times the car's
     * history breaks, so staff see the affected car once with enough to fix the source.
     */
    private function anomalyRow(Vehicle $v, string $kind, array $agg): array
    {
        $from = $agg['from'];
        $to   = $agg['to'];
        $delta = $to['milage'] - $from['milage'];
        $days  = (int) $from['date']->diffInDays($to['date']);
        $more  = $agg['count'] > 1 ? ' (+' . ($agg['count'] - 1) . ' more in this car\'s history)' : '';

        $fromLabel = number_format($from['milage']) . ' km on ' . $from['date']->toDateString()
            . ' (' . strtoupper($from['kind']) . ' #' . $from['contract_no'] . ')';
        $toLabel = number_format($to['milage']) . ' km on ' . $to['date']->toDateString()
            . ' (' . strtoupper($to['kind']) . ' #' . $to['contract_no'] . ')';

        $detail = $kind === 'drop'
            ? 'Odometer runs backwards: ' . $fromLabel . ' then ' . $toLabel
                . ' — a later reading lower than an earlier one is impossible' . $more . '. Fix the mis-typed handover reading.'
            : 'Odometer jumps ' . number_format($delta) . ' km in ' . $days . ' day' . ($days === 1 ? '' : 's')
                . ': ' . $fromLabel . ' → ' . $toLabel
                . ' — too far to be real, almost certainly a typo / extra digit' . $more . '.';

        return [
            'vehicle_id'  => $v->id,
            'plate'       => $v->plate_no,
            'car'         => trim($v->make . ' ' . $v->model),
            'contract_id' => $to['contract_id'],
            'contract_no' => $to['contract_no'],
            'customer_id' => null,
            'customer'    => null,
            'out_date'    => $to['date']->toDateString(),
            'in_date'     => null,
            'tag'         => $kind === 'drop' ? 'Mileage rollback' : 'Mileage jump',
            'detail'      => $detail,
        ];
    }
}
