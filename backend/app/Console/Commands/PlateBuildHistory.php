<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use App\Services\PlateResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill vehicles.plate_key and the plate_assignments timeline from existing data.
 *
 * ADDITIVE + IDEMPOTENT: only sets the new plate_key column and upserts plate_assignments
 * (keyed by vehicle_id+plate_key). It NEVER moves or edits maintenance / inspection / cost
 * data — history stays on its original vehicle_id forever; this only records which car held
 * which plate when, so the history becomes discoverable.
 *
 * Per plate group (ordered by car_serial — OM's reliable acquisition order):
 *   - from_date = purchase_date; to_date = next car's purchase_date (nulled on placeholder
 *     inversions); is_current = the plate's live holder.
 *   - confidence: high (clear) | derived (all-gone historical, or a placeholder date inversion)
 *     | low (a plate with >1 active car that a human must confirm).
 *   - Plates with >1 active car are left UNRESOLVED (is_current unset, low) UNLESS a human has
 *     confirmed the holder in CONFIRMED_CURRENT below. Never guess.
 *
 * Run with --dry-run first to preview; without it to write inside one transaction.
 */
class PlateBuildHistory extends Command
{
    protected $signature = 'plate:build-history {--dry-run : Compute and print only, write nothing}';

    protected $description = 'Backfill vehicles.plate_key + the plate_assignments timeline (additive, idempotent)';

    /**
     * Human-confirmed current holders for plates the auto-rule can't resolve (>1 active car),
     * set by the fleet owner after reviewing `plate:review-ambiguous`. plate_key => vehicle_id.
     * Any ambiguous plate NOT listed here stays Needs Review (no is_current) until confirmed.
     */
    private const CONFIRMED_CURRENT = [
        '13881' => 1916,
        '32967' => 1963,
        '81830' => 1956,
        // '76722' => pending real-fleet check — intentionally omitted, stays Needs Review.
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $this->info($dry ? 'DRY RUN — nothing will be written.' : 'Building plate history…');

        $vehicles = Vehicle::withTrashed()->whereNotNull('plate_no')->where('plate_no', '<>', '')
            ->get(['id', 'plate_no', 'status', 'car_serial', 'purchase_date']);

        // group by canonical plate key
        $groups = [];
        foreach ($vehicles as $v) {
            $k = PlateResolver::plateDigits($v->plate_no);
            if ($k !== '') { $groups[$k][] = $v; }
        }
        ksort($groups);

        $plateKeySet = 0; $assignments = 0; $current = 0; $previous = 0;
        $cHigh = 0; $cDerived = 0; $cLow = 0; $needsReview = [];
        $writes = [];   // [vehicle_id => plate_key] and assignment rows, applied in a transaction

        foreach ($groups as $key => $g) {
            usort($g, fn ($a, $b) => (int) $a->car_serial <=> (int) $b->car_serial);
            $n = count($g);
            $isReused = $n > 1;
            $actives = array_values(array_filter($g, fn ($v) => ! in_array($v->status, PlateResolver::GONE_STATUSES, true)));
            $activeCount = count($actives);

            // Decide the plate's current holder.
            $currentId = null; $unresolved = false;
            if ($isReused && $activeCount > 1) {
                if (isset(self::CONFIRMED_CURRENT[$key])) {
                    $currentId = self::CONFIRMED_CURRENT[$key];   // human-confirmed
                } else {
                    $unresolved = true;                           // leave Needs Review — never guess
                    $needsReview[] = $key;
                }
            } elseif ($activeCount === 1) {
                $currentId = (int) $actives[0]->id;
            } elseif ($activeCount === 0 && ! $isReused) {
                $currentId = null;                                // single sold car: no live holder
            }
            // activeCount === 0 && reused => historical plate, no current holder (currentId stays null)

            foreach ($g as $i => $v) {
                $from = $this->dateOnly($v->purchase_date);
                $to   = ($i < $n - 1) ? $this->dateOnly($g[$i + 1]->purchase_date) : null;
                $inverted = ($to !== null && $from !== null && strtotime($to) < strtotime($from));
                if ($inverted) { $to = null; }
                $isCurrent = ($currentId !== null && (int) $v->id === $currentId);

                // confidence + note
                $conf = 'high'; $notes = [];
                if ($unresolved) {
                    $conf = 'low';
                    $notes[] = "{$activeCount} active cars share this plate — current holder pending review";
                } elseif ($isReused && $activeCount === 0) {
                    $conf = 'derived';
                    $notes[] = 'all cars gone — historical plate, no current holder';
                } elseif ($isReused && $inverted) {
                    $conf = 'derived';
                    $notes[] = 'purchase_date contradicts serial order — hand-off date left open';
                }
                if ($isCurrent && isset(self::CONFIRMED_CURRENT[$key])) {
                    $conf = 'high';
                    $notes[] = 'current holder confirmed by manual review';
                }

                $writes['plate_key'][$v->id] = $key;
                $writes['assignments'][] = [
                    'vehicle_id' => (int) $v->id,
                    'plate_key'  => $key,
                    'plate_raw'  => $v->plate_no,
                    'from_date'  => $from,
                    'to_date'    => $to,
                    'is_current' => $isCurrent,
                    'source'     => 'backfill',
                    'confidence' => $conf,
                    'note'       => $notes ? implode('; ', $notes) : null,
                ];

                $plateKeySet++;
                $assignments++;
                $isCurrent ? $current++ : $previous++;
                if ($conf === 'high') { $cHigh++; } elseif ($conf === 'derived') { $cDerived++; } else { $cLow++; }
            }
        }

        if (! $dry) {
            DB::transaction(function () use ($writes) {
                foreach ($writes['plate_key'] as $vehicleId => $key) {
                    DB::table('vehicles')->where('id', $vehicleId)->update(['plate_key' => $key]);
                }
                $now = now();
                foreach ($writes['assignments'] as $row) {
                    DB::table('plate_assignments')->updateOrInsert(
                        ['vehicle_id' => $row['vehicle_id'], 'plate_key' => $row['plate_key']],
                        $row + ['updated_at' => $now, 'created_at' => $now]
                    );
                }
            });
        }

        $this->newLine();
        $this->table(['Metric', 'Count'], [
            [$dry ? 'plate_key WOULD set' : 'plate_key set', $plateKeySet],
            [$dry ? 'assignments WOULD upsert' : 'assignments upserted', $assignments],
            ['  current holders (is_current)', $current],
            ['  previous vehicles', $previous],
            ['  confidence high', $cHigh],
            ['  confidence derived', $cDerived],
            ['  confidence low (needs review)', $cLow],
            ['plates left Needs Review', count($needsReview)],
        ]);
        if ($needsReview) {
            $this->line('<comment>Needs Review (no current holder set): ' . implode(', ', $needsReview) . '</comment>');
        }
        $this->info($dry ? 'Dry run complete — nothing written.' : 'Backfill complete.');

        return self::SUCCESS;
    }

    private function dateOnly($v): ?string
    {
        return empty($v) ? null : substr((string) $v, 0, 10);
    }
}
