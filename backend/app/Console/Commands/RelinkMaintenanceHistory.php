<?php

namespace App\Console\Commands;

use App\Services\PlateResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Corrective re-link of historical maintenance rows to the vehicle that ACTUALLY held the plate
 * on the event date — using the confirmed plate_assignments timeline as the authoritative source.
 *
 * Why: plates get reused after a sale, and rows imported before the date-aware fix were either
 * left unlinked (vehicle_id NULL) or attached to the newest car instead of the historical owner.
 * This command corrects ONLY the vehicle_id relationship. It never inserts, deletes, or edits any
 * maintenance field — so no duplicates are created and no maintenance data is overwritten. Each
 * record stays exactly as it was; only which car it hangs off changes, and only when the timeline
 * says so with confidence. Ambiguous rows are reported, never guessed.
 *
 * Resolution per row (plate P, event date D, car_label L):
 *   1. If the plate has ONE holder → that car.
 *   2. If a CURRENT holder exists and D >= its tenure start (from_date) → the current holder
 *      (the plate belongs to it now and the event falls in its tenure). This is the is_current
 *      anchor, honouring owner-confirmed holders (e.g. 76722 → 1902).
 *   3. Otherwise the historical owner as-of D: the holder with the greatest from_date <= D,
 *      broken by the make/model label. A tie / no-date / pre-dates-all → AMBIGUOUS (skipped).
 *
 * Scope: rows on REUSED plates only, origin in --origins (default sheet,customer-sheet — the bulk
 * auto-resolved imports). In-app workflow tickets are never in scope (none sit on reused plates).
 * Always --dry-run first; the real run writes vehicle_id inside a single transaction.
 */
class RelinkMaintenanceHistory extends Command
{
    protected $signature = 'maintenance:relink-history
        {--dry-run : Compute and report only; write nothing}
        {--plate= : Limit to one plate (digits, e.g. 19397)}
        {--origins=sheet,customer-sheet : Comma list of maintenance origins in scope}';

    protected $description = 'Re-link historical maintenance rows to the correct vehicle via the plate_assignments timeline (vehicle_id only; additive/reversible report)';

    /**
     * Reasons we trust enough to re-link. Anything else is reported as ambiguous, never changed.
     *  single    — the plate only ever had one holder.
     *  label     — the row's make/model label names exactly one holder (STRONGEST signal — a record
     *              labelled "FORD MUSTANG" belongs to the Mustang, whatever the date says).
     *  asof      — no decisive label, but the timeline's greatest from_date <= event date is unique.
     *  label_tie — a purchase-date tie broken by the label.
     */
    private const CONFIDENT = ['single', 'label', 'asof', 'label_tie'];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $onlyPlate = $this->option('plate') ? PlateResolver::plateDigits($this->option('plate')) : null;
        $origins = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('origins')))));

        $this->info($dry ? 'DRY RUN — nothing will be written.' : 'Applying re-link…');
        $this->line('Origins in scope: ' . implode(', ', $origins) . ($onlyPlate ? " · plate {$onlyPlate}" : ''));

        // 1) Reused plate_keys + their holder timeline (from plate_assignments, joined to vehicles).
        $reusedKeys = DB::table('plate_assignments')->select('plate_key', DB::raw('count(*) c'))
            ->groupBy('plate_key')->having('c', '>', 1)->pluck('plate_key')->all();
        $reusedSet = array_flip($reusedKeys);

        $holders = [];   // plate_key => [ ['id','from_date','from_ts','is_current','make','model','status'], ... ]
        $rows = DB::table('plate_assignments as pa')
            ->join('vehicles as v', 'v.id', '=', 'pa.vehicle_id')
            ->whereIn('pa.plate_key', $reusedKeys)
            ->get(['pa.plate_key', 'pa.vehicle_id', 'pa.from_date', 'pa.is_current', 'v.make', 'v.model', 'v.status']);
        foreach ($rows as $r) {
            $holders[$r->plate_key][] = [
                'id'         => (int) $r->vehicle_id,
                'from_date'  => $r->from_date,
                'from_ts'    => $r->from_date ? strtotime($r->from_date) : null,
                'is_current' => (bool) $r->is_current,
                'make'       => $r->make,
                'model'      => $r->model,
                'status'     => $r->status,
            ];
        }

        // 2) Walk the historical maintenance rows in scope.
        $stats = [];   // plate_key => ['rows'=>,'change'=>,'unchanged'=>,'ambiguous'=>,'reasons'=>[]]
        $changes = []; // full change log
        $ambiguous = [];
        $totalRows = 0;

        DB::table('maintenances')
            ->whereIn('origin', $origins)
            ->when($onlyPlate === null, fn ($q) => $q->whereNotNull('plate'))
            ->select('id', 'plate', 'car_label', 'vehicle_id', 'out_date', 'origin')
            ->orderBy('id')
            ->chunk(2000, function ($chunk) use (&$stats, &$changes, &$ambiguous, &$totalRows, $reusedSet, $holders, $onlyPlate) {
                foreach ($chunk as $m) {
                    $k = PlateResolver::plateDigits($m->plate);
                    if ($k === '' || ! isset($reusedSet[$k])) {
                        continue; // not a reused plate — out of scope
                    }
                    if ($onlyPlate !== null && $k !== $onlyPlate) {
                        continue;
                    }
                    $totalRows++;
                    $stats[$k] ??= ['rows' => 0, 'change' => 0, 'unchanged' => 0, 'ambiguous' => 0, 'reasons' => []];
                    $stats[$k]['rows']++;

                    $reason = null;
                    $owner = $this->resolveOwner($holders[$k] ?? [], $m->out_date, $m->car_label, $reason);
                    $stats[$k]['reasons'][$reason] = ($stats[$k]['reasons'][$reason] ?? 0) + 1;

                    $newId = $owner['id'] ?? null;
                    $confident = in_array($reason, self::CONFIDENT, true) && $newId !== null;

                    if (! $confident) {
                        $stats[$k]['ambiguous']++;
                        // Log EVERY ambiguous row to the report file (console prints only a sample).
                        $ambiguous[] = ['id' => $m->id, 'plate' => $k, 'date' => $m->out_date, 'label' => $m->car_label, 'current' => $m->vehicle_id, 'reason' => $reason];
                        continue;
                    }

                    $curId = $m->vehicle_id === null ? null : (int) $m->vehicle_id;
                    if ($curId === $newId) {
                        $stats[$k]['unchanged']++;
                        continue;
                    }

                    $stats[$k]['change']++;
                    $changes[] = ['id' => $m->id, 'plate' => $k, 'date' => $m->out_date, 'origin' => $m->origin, 'label' => $m->car_label, 'old' => $curId, 'new' => $newId, 'reason' => $reason];
                }
            });

        // 3) Report.
        $this->renderReport($stats, $changes, $ambiguous, $totalRows, $onlyPlate);

        // 4) Persist the full change + ambiguous logs to files (both runs) for the audit trail.
        $stamp = now()->format('Ymd-His');
        $reportPath = $this->writeReport($stamp, $dry, $origins, $stats, $changes, $ambiguous, $totalRows);
        $this->line("Full report written to: <info>{$reportPath}</info>");

        // 5) Apply.
        if (! $dry) {
            if (! $changes) {
                $this->info('Nothing to change.');
                return self::SUCCESS;
            }
            // Emit an explicit rollback script BEFORE writing — every row's prior value, so the
            // change is fully reversible independently of the transaction (belt and suspenders).
            $rollbackPath = $this->writeRollback($stamp, $changes);
            $this->line("Rollback script written to: <info>{$rollbackPath}</info>");

            DB::transaction(function () use ($changes) {
                foreach ($changes as $c) {
                    // Guard: only ever touch the exact rows we reported, and only vehicle_id.
                    DB::table('maintenances')->where('id', $c['id'])->update(['vehicle_id' => $c['new']]);
                }
            });
            $this->info(count($changes) . ' maintenance row(s) re-linked (single transaction).');
        } else {
            $this->info('Dry run complete — nothing written. Re-run without --dry-run to apply.');
        }

        return self::SUCCESS;
    }

    /**
     * Resolve the vehicle that owned the plate on $date from the holder timeline.
     *
     * Delegates to the tested, LABEL-FIRST temporal resolver (PlateResolver::resolveAsOf), the same
     * logic the live importer uses. Order inside it: (1) a make/model label that names exactly one
     * holder wins outright — so a record labelled with the OLD car's make never migrates to the
     * current holder just because the date is recent; (2) otherwise the holder with the greatest
     * from_date <= the event date; (3) a genuine tie / no-date / pre-dates-all → ambiguous (skipped).
     *
     * We deliberately do NOT short-circuit recent rows to the is_current holder — the confirmed
     * current holder already has the greatest real from_date, so it wins via the date rule, while
     * a differently-labelled record is protected by the label rule.
     *
     * Returns the holder array (with 'id') or null; sets $reason.
     */
    private function resolveOwner(array $holders, $date, ?string $label, ?string &$reason)
    {
        $cands = array_map(fn ($h) => [
            'id' => $h['id'], 'purchase_date' => $h['from_date'], 'make' => $h['make'], 'model' => $h['model'],
        ], $holders);

        return PlateResolver::resolveAsOf($cands, $date, $label, $reason);
    }

    private function renderReport(array $stats, array $changes, array $ambiguous, int $totalRows, ?string $onlyPlate): void
    {
        $tChange = array_sum(array_column($stats, 'change'));
        $tUnchanged = array_sum(array_column($stats, 'unchanged'));
        $tAmbiguous = array_sum(array_column($stats, 'ambiguous'));

        $this->newLine();
        $this->table(['Metric', 'Count'], [
            ['Reused-plate rows in scope', $totalRows],
            ['  → will re-link (vehicle_id change)', $tChange],
            ['  → already correct (no change)', $tUnchanged],
            ['  → ambiguous (skipped, reported)', $tAmbiguous],
            ['Plates affected by a change', count(array_filter($stats, fn ($s) => $s['change'] > 0))],
        ]);

        // Per-plate breakdown (only plates with a change or ambiguity, or the single requested plate).
        $rows = [];
        ksort($stats);
        foreach ($stats as $k => $s) {
            if ($onlyPlate === null && $s['change'] === 0 && $s['ambiguous'] === 0) { continue; }
            $rows[] = [$k, $s['rows'], $s['change'], $s['unchanged'], $s['ambiguous']];
        }
        if ($rows) {
            $this->newLine();
            $this->line('<comment>Per-plate (plates with a change or ambiguity):</comment>');
            $this->table(['Plate', 'In scope', 'Re-link', 'OK', 'Ambiguous'], $rows);
        }

        // NULL→linked vs relink split, and change reasons.
        $nullToId = count(array_filter($changes, fn ($c) => $c['old'] === null));
        $idToId = count($changes) - $nullToId;
        $reasonCounts = [];
        foreach ($changes as $c) { $reasonCounts[$c['reason']] = ($reasonCounts[$c['reason']] ?? 0) + 1; }
        $this->newLine();
        $this->line("Of the re-links: <info>{$nullToId}</info> were NULL→vehicle (never linked), <info>{$idToId}</info> were vehicle→vehicle (mis-linked).");
        $this->line('Change reasons: ' . collect($reasonCounts)->map(fn ($v, $r) => "{$r}={$v}")->implode(', '));

        // Samples.
        if ($changes) {
            $this->newLine();
            $this->line('<comment>Sample before → after (first 12):</comment>');
            foreach (array_slice($changes, 0, 12) as $c) {
                $this->line(sprintf('  #%-7s plate %-8s %s  veh %s → %s  [%s]  %s',
                    $c['id'], $c['plate'], $c['date'] ?? '   ?   ',
                    $c['old'] === null ? 'NULL' : $c['old'], $c['new'], $c['reason'], trim((string) $c['label'])));
            }
        }
        if ($ambiguous) {
            $this->newLine();
            $this->line('<comment>Sample ambiguous (skipped — not guessed) (first 12):</comment>');
            foreach (array_slice($ambiguous, 0, 12) as $a) {
                $this->line(sprintf('  #%-7s plate %-8s %s  current veh %s  reason=%s  %s',
                    $a['id'], $a['plate'], $a['date'] ?? '   ?   ',
                    $a['current'] ?? 'NULL', $a['reason'], trim((string) $a['label'])));
            }
        }
    }

    /** An idempotent SQL script that restores every changed row's prior vehicle_id. */
    private function writeRollback(string $stamp, array $changes): string
    {
        $dir = storage_path('app/reports');
        if (! is_dir($dir)) { @mkdir($dir, 0775, true); }
        $path = "{$dir}/relink-plate-history-{$stamp}-rollback.sql";

        $fh = fopen($path, 'w');
        fwrite($fh, "-- Rollback for maintenance:relink-history applied at {$stamp}\n");
        fwrite($fh, "-- Restores vehicle_id to its value before the re-link. Safe to re-run.\n");
        fwrite($fh, "START TRANSACTION;\n");
        foreach ($changes as $c) {
            $old = $c['old'] === null ? 'NULL' : (int) $c['old'];
            fwrite($fh, "UPDATE maintenances SET vehicle_id = {$old} WHERE id = " . (int) $c['id'] . ";\n");
        }
        fwrite($fh, "COMMIT;\n");
        fclose($fh);

        return $path;
    }

    private function writeReport(string $stamp, bool $dry, array $origins, array $stats, array $changes, array $ambiguous, int $totalRows): string
    {
        $dir = storage_path('app/reports');
        if (! is_dir($dir)) { @mkdir($dir, 0775, true); }
        $mode = $dry ? 'dryrun' : 'applied';
        $path = "{$dir}/relink-plate-history-{$stamp}-{$mode}.csv";

        $fh = fopen($path, 'w');
        fputcsv($fh, ['# maintenance re-link report', $stamp, $mode, 'origins=' . implode('|', $origins), "rows_in_scope={$totalRows}"]);
        fputcsv($fh, ['section', 'maintenance_id', 'plate', 'event_date', 'origin', 'car_label', 'old_vehicle_id', 'new_vehicle_id', 'reason']);
        foreach ($changes as $c) {
            fputcsv($fh, ['change', $c['id'], $c['plate'], $c['date'], $c['origin'], $c['label'], $c['old'] ?? 'NULL', $c['new'], $c['reason']]);
        }
        foreach ($ambiguous as $a) {
            fputcsv($fh, ['ambiguous', $a['id'], $a['plate'], $a['date'], '', $a['label'], $a['current'] ?? 'NULL', '', $a['reason']]);
        }
        fputcsv($fh, []);
        fputcsv($fh, ['# per-plate', 'plate', 'in_scope', 'relink', 'ok', 'ambiguous']);
        ksort($stats);
        foreach ($stats as $k => $s) {
            fputcsv($fh, ['plate', $k, $s['rows'], $s['change'], $s['unchanged'], $s['ambiguous']]);
        }
        fclose($fh);

        return $path;
    }
}
