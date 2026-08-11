<?php

namespace App\Console\Commands;

use App\Services\MaintenanceSheetImporter;
use Illuminate\Console\Command;
use Throwable;

class ImportMaintenanceSheet extends Command
{
    protected $signature = 'import:maintenance-sheet
        {--sheet= : Spreadsheet ID (defaults to config google.sheets.maintenance.id)}
        {--gid= : Tab gid (defaults to config google.sheets.maintenance.log_gid)}
        {--dry-run : Preview only, write nothing}
        {--limit=0 : Stop after N rows}
        {--run-id=0 : (accepted for UI parity; unused)}';

    protected $description = 'Import the N-Maintenance & Repair sheet log into the maintenances table (standalone rows, origin=sheet)';

    public function handle(MaintenanceSheetImporter $importer): int
    {
        $dry   = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit') ?: null;

        $this->info($dry ? 'DRY RUN — nothing will be written.' : 'Importing maintenance sheet log…');

        $sheet = (string) ($this->option('sheet') ?: config('google.sheets.maintenance.id'));
        $gid   = (int) ($this->option('gid') ?: config('google.sheets.maintenance.log_gid'));

        if ($sheet === '') {
            $this->error('No spreadsheet id — set GOOGLE_SHEETS_MAINTENANCE_ID or pass --sheet.');
            return self::FAILURE;
        }

        try {
            $r = $importer->import($sheet, $gid, $dry, $limit);
        } catch (Throwable $e) {
            $this->error('Failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        if (isset($r['error'])) {
            $this->error($r['error']);
            return self::FAILURE;
        }

        // Post-pass: a row that just landed can be the fact that answers a pending inspection request —
        // the car is already at a garage. Withdraw those now so the Controllers' queue re-counts with
        // the import instead of waiting for someone to open it.
        if (! $dry) {
            try {
                $withdrawn = app(\App\Services\MaintenanceWorkflowService::class)->withdrawRequestsForWorkshopLog();
                if ($withdrawn > 0) {
                    $this->line("<comment>Withdrew {$withdrawn} pending inspection request(s) — those cars are in the workshop per this log.</comment>");
                }
            } catch (Throwable $e) {
                $this->warn('Withdraw sweep failed (import itself succeeded): ' . $e->getMessage());
            }
        }

        $this->newLine();
        $this->table(['Metric', 'Count'], [
            [$dry ? 'Would create' : 'Created', $r['imported']],
            [$dry ? 'Would update' : 'Updated', $r['updated']],
            ['Skipped (blank/label)', $r['skipped']],
            ['Unmatched car (no vehicle)', $r['unmatched_cars']],
            ['Ambiguous plate (reported, not guessed)', $r['ambiguous_cars'] ?? 0],
            ['Garage vendors created', $r['vendors_made']],
        ]);

        foreach ($r['samples'] as $s) {
            $m = $s['matched_car'] ? 'matched' : 'NO car';
            $this->line("  • {$s['plate']} ({$m})  {$s['event']}  out={$s['out']}  garage='{$s['garage']}'");
        }

        if (! empty($r['unmatched_samples'])) {
            $this->newLine();
            $this->line('<comment>Plates that did not match a fleet vehicle (first 25):</comment>');
            $this->line('  ' . implode(' · ', $r['unmatched_samples']));
        }

        if (! empty($r['ambiguous_samples'])) {
            $this->newLine();
            $this->line('<comment>Reused plates too ambiguous to place by date — left unlinked for review (first 25):</comment>');
            $this->line('  ' . implode(' · ', $r['ambiguous_samples']));
        }

        return self::SUCCESS;
    }
}
