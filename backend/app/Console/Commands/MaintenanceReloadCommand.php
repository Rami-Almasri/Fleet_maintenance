<?php

namespace App\Console\Commands;

use App\Models\Maintenance;
use App\Services\DatabaseBackup;
use App\Services\MaintenanceSheetImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Cleanly reload ALL maintenance from its single source of truth — the
 * "N-Maintenance & Repair" sheet (gid 400222171) plus the "Customer Cases" tab.
 *
 * Unlike a plain re-import (which is idempotent but leaves behind rows that were
 * later EDITED or DELETED in the sheet — an edit changes the row hash, so the old
 * version lingers), this empties the `maintenances` table first so it ends up an
 * EXACT mirror of the sheet. ONLY the `maintenances` table is touched — contracts,
 * customers, vehicles, invoices and maintenance_items are never deleted.
 *
 * HARD safety gate: a full database backup runs first and the reload aborts if it
 * fails. Reasons are re-categorized inline by the importer (MAIN → reason vocabulary).
 *
 * ── THE CASCADE NOBODY SEES (read before running this) ──────────────────────────────────────────
 * "ONLY the maintenances table is touched" is true of the DELETE statement and false of its effect.
 * Eleven tables hang off `maintenances` with ON DELETE CASCADE, and the biggest of them is
 * `maintenance_signatures` — the projection the ENTIRE intelligence layer reads (comeback rate,
 * first-time-fix, garage scoring, repair history). Effectively all of it hangs off sheet-origin rows,
 * so this command silently destroys the corpus it never mentions.
 *
 * Re-import does NOT undo that. Rows come back with NEW primary keys, so every child that survived
 * elsewhere is orphaned and every cascaded child is simply gone until its projection is rebuilt
 * (`intelligence:rebuild-signatures`). The backup gate is a way to recover AFTERWARDS, not a safeguard.
 *
 * So the cascade is now COUNTED and shown, and a run that would destroy child rows requires
 * --accept-cascade-loss. That flag is deliberately separate from --force: --force is what ends up in
 * a script, and "don't prompt me" must never silently mean "and wipe the intelligence corpus".
 */
class MaintenanceReloadCommand extends Command
{
    protected $signature = 'maintenance:reload
        {--skip-backup : Skip the pre-flight db:backup (NOT recommended)}
        {--force : Don\'t ask for confirmation}
        {--accept-cascade-loss : Required when child rows (signatures, faults, invoices…) would be cascade-deleted}
        {--dry-run : Preview the sheet counts + the full cascade blast radius — no backup, no delete, no write}';

    protected $description = 'Wipe ONLY the maintenances table and re-import it exactly from the sheet (backup-gated).';

    /**
     * Every table that is DESTROYED by deleting a `maintenances` row, as table => foreign key.
     * Mirrors the ON DELETE CASCADE constraints in the migrations; kept here so the operator sees the
     * true blast radius rather than the one-table story the command used to tell. `nullOnDelete`
     * relations (vehicle_log_events and friends) are not listed: those rows survive — they only lose
     * their linkage — which is its own problem, not this command's to report.
     */
    private const CASCADE_TABLES = [
        'maintenance_signatures'          => 'maintenance_id',
        'maintenance_tasks'               => 'maintenance_id',
        'maintenance_line_items'          => 'maintenance_id',
        'maintenance_invoices'            => 'maintenance_id',
        'repair_inspections'              => 'maintenance_id',
        'recurring_fault_reviews'         => 'maintenance_id',
        'garage_recommendation_decisions' => 'maintenance_id',
        'garage_invoice_submissions'      => 'maintenance_id',
        'maintenance_required_parts'      => 'maintenance_id',
        'resolved_transfer_flags'         => 'maintenance_id',
        'maintenance_watchers'            => 'maintenance_id',
    ];

    public function handle(DatabaseBackup $backup, MaintenanceSheetImporter $importer): int
    {
        $sheet    = (string) config('google.sheets.maintenance.id');
        $logGid   = (int) config('google.sheets.maintenance.log_gid');
        $casesGid = (int) config('google.sheets.maintenance.customer_cases_gid');

        if ($sheet === '') {
            $this->error('No spreadsheet id — set GOOGLE_SHEETS_MAINTENANCE_ID.');
            return self::FAILURE;
        }

        // Sheet-owned rows only. Hand-entered workshop events (origin = 'manual') and
        // per-contract headers (origin = 'contract') are the dashboard's own source of
        // truth — the sheet sync must never wipe them.
        $before    = Maintenance::whereIn('origin', Maintenance::SHEET_ORIGINS)->count();
        $protected = Maintenance::count() - $before;

        // What the DELETE really takes with it. Counted BEFORE anything is touched, and shown in both
        // the dry run and the confirmation, so the cascade can never again be discovered afterwards.
        $cascade      = $this->cascadeImpact();
        $cascadeTotal = array_sum($cascade);

        // --- DRY RUN: preview what the sheet currently holds, change nothing. ---
        if ($this->option('dry-run')) {
            $this->info("DRY RUN — {$before} sheet rows would be replaced; {$protected} hand-entered/contract rows kept. Previewing the sheet (no writes)…");
            try {
                $log   = $importer->import($sheet, $logGid, true, null, 'sheet');
                $cases = $importer->import($sheet, $casesGid, true, null, 'customer-sheet');
            } catch (Throwable $e) {
                $this->error('Preview failed: ' . $e->getMessage());
                return self::FAILURE;
            }
            $this->previewTable($log, $cases);
            $this->cascadeTable($cascade, $cascadeTotal);
            return self::SUCCESS;
        }

        // --- CASCADE GATE: refuse outright unless the operator has acknowledged the child-row loss. ---
        // Checked BEFORE --force is honoured: --force means "don't prompt", never "destroy silently".
        if ($cascadeTotal > 0) {
            $this->cascadeTable($cascade, $cascadeTotal);

            if (! $this->option('accept-cascade-loss')) {
                $this->error("REFUSING TO RUN — this would cascade-delete {$cascadeTotal} child row(s) (see above).");
                $this->line('Re-importing does NOT bring them back: rows return with new primary keys.');
                $this->line('Rebuild the projection afterwards with `php artisan intelligence:rebuild-signatures`.');
                $this->line('If that is genuinely what you want, re-run with --accept-cascade-loss.');
                return self::FAILURE;
            }

            $this->warn("--accept-cascade-loss given: {$cascadeTotal} child row(s) WILL be destroyed.");
        }

        // --- Confirm (skipped with --force or in non-interactive shells). ---
        if (! $this->option('force')
            && ! $this->confirm("This DELETES the {$before} sheet-sourced maintenance rows (keeping {$protected} hand-entered/contract rows), cascade-deletes {$cascadeTotal} child row(s), and re-imports from the sheet. Continue?")) {
            $this->warn('Aborted — nothing changed.');
            return self::SUCCESS;
        }

        // --- HARD GATE: back up first; abort the whole reload if the backup fails. ---
        if (! $this->option('skip-backup')) {
            $this->info('Backing up the database first…');
            try {
                $file = $backup->run();
                $this->info('Backup written: ' . basename($file));
            } catch (Throwable $e) {
                $this->error('Backup FAILED — aborting before any delete. ' . $e->getMessage());
                return self::FAILURE;
            }
        }

        // --- Wipe ONLY the sheet-sourced rows; never the manual/contract rows. ---
        $this->warn("Deleting {$before} sheet-sourced rows from `maintenances` (keeping {$protected})…");
        $deleted = DB::table('maintenances')->whereIn('origin', Maintenance::SHEET_ORIGINS)->delete();

        // --- Re-import both tabs from the single source of truth. ---
        try {
            $this->info('Re-importing the maintenance log tab (origin=sheet)…');
            $log = $importer->import($sheet, $logGid, false, null, 'sheet');
            if (isset($log['error'])) {
                throw new \RuntimeException('log tab: ' . $log['error']);
            }

            $this->info('Re-importing the Customer Cases tab (origin=customer-sheet)…');
            $cases = $importer->import($sheet, $casesGid, false, null, 'customer-sheet');
            if (isset($cases['error'])) {
                throw new \RuntimeException('customer-cases tab: ' . $cases['error']);
            }
        } catch (Throwable $e) {
            $this->error('Re-import FAILED after the wipe: ' . $e->getMessage());
            $this->error('The table was emptied — restore the backup just taken (storage/app/backups) and investigate.');
            return self::FAILURE;
        }

        $after = Maintenance::count();
        $this->newLine();
        $this->table(['Metric', 'Count'], [
            ['Sheet rows before',           $before],
            ['Kept (manual/contract)',      $protected],
            ['Rows deleted',                $deleted],
            ['Imported — log tab',          $log['imported']],
            ['Imported — customer cases',   $cases['imported']],
            ['Unmatched car (log)',         $log['unmatched_cars']],
            ['Unmatched car (cases)',       $cases['unmatched_cars']],
            ['Rows after',                  $after],
            ['Categorized (reason set)',    Maintenance::whereNotNull('maintenance_reason_id')->count()],
        ]);

        $this->info('Maintenance reloaded — the table now mirrors the sheet exactly.');
        return self::SUCCESS;
    }

    /**
     * Count, per table, the child rows that hang off the sheet-origin `maintenances` rows this command
     * deletes — i.e. exactly what ON DELETE CASCADE will destroy. A missing table (an environment on an
     * older migration set) is reported as 0 rather than fataling: the gate must never itself become the
     * reason a reload can't run.
     *
     * @return array<string,int> table => rows that would be destroyed (zero-counts dropped)
     */
    private function cascadeImpact(): array
    {
        $sheetIds = DB::table('maintenances')
            ->whereIn('origin', Maintenance::SHEET_ORIGINS)
            ->pluck('id');

        if ($sheetIds->isEmpty()) {
            return [];
        }

        $impact = [];

        foreach (self::CASCADE_TABLES as $table => $fk) {
            try {
                $n = DB::table($table)->whereIn($fk, $sheetIds)->count();
            } catch (Throwable) {
                continue; // table not present in this environment — nothing to warn about
            }
            if ($n > 0) {
                $impact[$table] = $n;
            }
        }

        // Grandchildren: the per-garage stints hang off maintenance_tasks, not off the ticket, so they
        // die two levels down and would otherwise be invisible in this report.
        try {
            $taskIds = DB::table('maintenance_tasks')->whereIn('maintenance_id', $sheetIds)->pluck('id');
            if ($taskIds->isNotEmpty()) {
                $n = DB::table('maintenance_task_assignments')->whereIn('maintenance_task_id', $taskIds)->count();
                if ($n > 0) {
                    $impact['maintenance_task_assignments'] = $n;
                }
            }
        } catch (Throwable) {
            // no tasks table / no assignments table — nothing to add
        }

        arsort($impact);

        return $impact;
    }

    /** @param array<string,int> $cascade */
    private function cascadeTable(array $cascade, int $total): void
    {
        $this->newLine();

        if ($total === 0) {
            $this->info('Cascade impact: none — no child rows hang off the sheet-sourced tickets.');
            return;
        }

        $this->warn('CASCADE IMPACT — these rows are DESTROYED by the delete, not just unlinked:');
        $this->table(
            ['Table', 'Rows destroyed'],
            array_map(fn ($t, $n) => [$t, number_format($n)], array_keys($cascade), $cascade)
        );
        $this->warn('Total: ' . number_format($total) . ' child row(s).');

        if (isset($cascade['maintenance_signatures'])) {
            $this->error('`maintenance_signatures` is the projection the intelligence layer reads '
                . '(comeback rate, first-time fix, garage scoring). Rebuild it after the reload.');
        }
    }

    private function previewTable(array $log, array $cases): void
    {
        $this->newLine();
        $this->table(['Tab', 'Would create', 'Would update', 'Unmatched car'], [
            ['Maintenance log',  $log['imported'],   $log['updated'],   $log['unmatched_cars']],
            ['Customer cases',   $cases['imported'], $cases['updated'], $cases['unmatched_cars']],
        ]);
        $this->line('A real run backs up, empties `maintenances`, then re-creates every row above.');
    }
}
