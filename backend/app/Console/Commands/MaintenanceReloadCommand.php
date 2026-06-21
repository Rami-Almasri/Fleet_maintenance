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
 */
class MaintenanceReloadCommand extends Command
{
    protected $signature = 'maintenance:reload
        {--skip-backup : Skip the pre-flight db:backup (NOT recommended)}
        {--force : Don\'t ask for confirmation}
        {--dry-run : Preview the sheet counts only — no backup, no delete, no write}';

    protected $description = 'Wipe ONLY the maintenances table and re-import it exactly from the sheet (backup-gated).';

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
            return self::SUCCESS;
        }

        // --- Confirm (skipped with --force or in non-interactive shells). ---
        if (! $this->option('force')
            && ! $this->confirm("This DELETES the {$before} sheet-sourced maintenance rows (keeping {$protected} hand-entered/contract rows) and re-imports them from the sheet. Continue?")) {
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
