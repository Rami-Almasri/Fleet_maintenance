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
        // 76722 no longer needs confirmation — once split by plate code it is two plates
        // ("P 76722" → 1902, "U 76722" → 1915), each with a single active car (auto-resolved).
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $this->info($dry ? 'DRY RUN — nothing will be written.' : 'Building plate history…');

        $vehicles = Vehicle::withTrashed()->whereNotNull('plate_no')->where('plate_no', '<>', '')
            ->get(['id', 'plate_no', 'plate_code', 'status', 'car_serial', 'purchase_date', 'make', 'model']);

        // Reverse dictionary (plate letter => [codes]) so a VIN-less car with no OM code can still
        // be placed by the letter embedded in its model text (e.g. "CAMARO / K" → K).
        $letterToCodes = [];
        foreach (\App\Models\PlateCode::all(['em_no', 'letter_en']) as $pc) {
            $L = strtoupper(trim((string) $pc->letter_en));
            if ($L !== '') { $letterToCodes[$L][] = (string) $pc->em_no; }
        }

        // Group by plate DIGITS first…
        $digitGroups = [];
        foreach ($vehicles as $v) {
            $k = PlateResolver::plateDigits($v->plate_no);
            if ($k !== '') { $digitGroups[$k][] = $v; }
        }
        ksort($digitGroups);

        $plateKeySet = 0; $assignments = 0; $current = 0; $previous = 0;
        $cHigh = 0; $cDerived = 0; $cLow = 0; $needsReview = []; $splitPlates = [];
        $writes = [];   // [vehicle_id => plate_key] and assignment rows, applied in a transaction

        foreach ($digitGroups as $key => $members) {
            // …then split each digit-group by plate CODE — same digits + different code = a
            // different plate. Codes come from OM (vehicles.plate_code); VIN-less cars fall back
            // to their model-suffix letter, else adopt the group's sole code.
            $knownCodes = array_values(array_unique(array_filter(array_map(
                fn ($v) => ($v->plate_code !== null && $v->plate_code !== '') ? (string) $v->plate_code : null,
                $members
            ))));
            // Pass 1: each member's code direct from OM, else its model-suffix letter.
            $byId = [];
            foreach ($members as $v) { $byId[$v->id] = $this->directCode($v, $knownCodes, $letterToCodes); }
            $distinct = array_values(array_unique(array_filter($byId)));
            // Pass 2: a member we still can't code adopts the plate's SOLE code (a single-plate
            // reuse where a VIN-less legacy row just lacks the letter) — only splits stay split.
            $subgroups = [];
            foreach ($members as $v) {
                $code = $byId[$v->id] ?? (count($distinct) === 1 ? $distinct[0] : null);
                $subgroups[$code ?? 'ø'][] = $v;
            }
            $isSplit = count($subgroups) > 1;
            if ($isSplit) { $splitPlates[] = $key . ' {' . implode(',', array_map(fn ($c) => $c === 'ø' ? '?' : $c, array_keys($subgroups))) . '}'; }

            foreach ($subgroups as $codeKey => $g) {
                $code = $codeKey === 'ø' ? null : (string) $codeKey;
                usort($g, fn ($a, $b) => (int) $a->car_serial <=> (int) $b->car_serial);
                $n = count($g);
                $isReused = $n > 1;
                $actives = array_values(array_filter($g, fn ($v) => ! in_array($v->status, PlateResolver::GONE_STATUSES, true)));
                $activeCount = count($actives);

                // Decide the current holder. CONFIRMED_CURRENT applies ONLY to a non-split
                // digit-group (a single real plate); a split plate resolves each half on its own.
                $currentId = null; $unresolved = false;
                if ($isReused && $activeCount > 1) {
                    if (! $isSplit && isset(self::CONFIRMED_CURRENT[$key])) {
                        $currentId = self::CONFIRMED_CURRENT[$key];
                    } else {
                        $unresolved = true;
                        $needsReview[] = $code ? "{$key}/{$code}" : $key;
                    }
                } elseif ($activeCount === 1) {
                    $currentId = (int) $actives[0]->id;
                }
                // activeCount === 0 => historical plate, no current holder

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
                    if ($isCurrent && ! $isSplit && isset(self::CONFIRMED_CURRENT[$key])) {
                        $conf = 'high';
                        $notes[] = 'current holder confirmed by manual review';
                    }
                    if ($isSplit) {
                        $notes[] = 'split by plate code (' . ($code ?? 'unknown') . ') — same digits, different plate';
                    }
                    if ($code !== null && ($v->plate_code === null || $v->plate_code === '')) {
                        $notes[] = 'plate code inferred (VIN-less legacy row)';
                    }

                    $writes['plate_key'][$v->id] = $key;
                    $writes['assignments'][] = [
                        'vehicle_id' => (int) $v->id,
                        'plate_key'  => $key,
                        'plate_code' => $code,
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
        if ($splitPlates) {
            $this->line('<info>Plates split by code (same digits, different plate): ' . implode('  ', $splitPlates) . '</info>');
        }
        $this->info($dry ? 'Dry run complete — nothing written.' : 'Backfill complete.');

        return self::SUCCESS;
    }

    /**
     * The plate code to file a vehicle under. Prefers OM's real code (vehicles.plate_code); for a
     * VIN-less legacy car with no OM code, derives it from the plate letter embedded in the model
     * text ("CAMARO / K" → K → its code, preferring a code already present on this plate); failing
     * that, adopts the group's sole code (a single-plate reuse). Null only when truly undetermined.
     *
     * @param  array<int,string>  $knownCodes    codes present among VIN'd members of the digit-group
     * @param  array<string,array<int,string>>  $letterToCodes  plate letter => [codes]
     */
    private function directCode($v, array $knownCodes, array $letterToCodes): ?string
    {
        if ($v->plate_code !== null && $v->plate_code !== '') {
            return (string) $v->plate_code;
        }
        $letter = $this->modelSuffixLetter($v);
        if ($letter !== null && isset($letterToCodes[$letter])) {
            foreach ($letterToCodes[$letter] as $c) {
                if (in_array($c, $knownCodes, true)) { return $c; }   // matches a code already here
            }
            return $letterToCodes[$letter][0];
        }
        return null;
    }

    /** Plate letter carried at the end of a legacy model string, e.g. "CERATO / N" → "N". */
    private function modelSuffixLetter($v): ?string
    {
        foreach ([$v->model ?? '', $v->make ?? ''] as $s) {
            if (preg_match('#/\s*([A-Za-z]{1,3})\s*$#', (string) $s, $m)) {
                return strtoupper($m[1]);
            }
        }
        return null;
    }

    private function dateOnly($v): ?string
    {
        return empty($v) ? null : substr((string) $v, 0, 10);
    }
}
