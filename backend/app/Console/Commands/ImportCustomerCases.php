<?php

namespace App\Console\Commands;

use App\Services\MaintenanceSheetImporter;
use Illuminate\Console\Command;
use Throwable;

/**
 * Import the "Customer Cases" sheet tab — a customer-charge maintenance log — into the
 * maintenances table as standalone rows with origin = 'customer-sheet'. Same importer as
 * the fleet maintenance log; only the tab (gid) and origin differ, and the importer maps
 * that tab's alternate headers (Main Issue / Main Cause / Customer Charge / Contract No. /
 * Bill Receive) onto the existing maintenance columns.
 */
class ImportCustomerCases extends Command
{
    protected $signature = 'import:customer-cases
        {--sheet= : Spreadsheet ID (defaults to config google.sheets.maintenance.id)}
        {--gid= : Tab gid (defaults to config google.sheets.maintenance.customer_cases_gid)}
        {--dry-run : Preview only, write nothing}
        {--limit=0 : Stop after N rows}
        {--run-id=0 : (accepted for UI parity; unused)}';

    protected $description = 'Import the Customer Cases sheet tab into the maintenances table (origin=customer-sheet)';

    public function handle(MaintenanceSheetImporter $importer): int
    {
        $dry   = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit') ?: null;

        $this->info($dry ? 'DRY RUN — nothing will be written.' : 'Importing customer cases…');

        $sheet = (string) ($this->option('sheet') ?: config('google.sheets.maintenance.id'));
        $gid   = (int) ($this->option('gid') ?: config('google.sheets.maintenance.customer_cases_gid'));

        if ($sheet === '') {
            $this->error('No spreadsheet id — set GOOGLE_SHEETS_MAINTENANCE_ID or pass --sheet.');
            return self::FAILURE;
        }

        try {
            $r = $importer->import($sheet, $gid, $dry, $limit, 'customer-sheet');
        } catch (Throwable $e) {
            $this->error('Failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        if (isset($r['error'])) {
            $this->error($r['error']);
            return self::FAILURE;
        }

        $this->newLine();
        $this->table(['Metric', 'Count'], [
            [$dry ? 'Would create' : 'Created', $r['imported']],
            [$dry ? 'Would update' : 'Updated', $r['updated']],
            ['Skipped (blank/label)', $r['skipped']],
            ['Unmatched car (no vehicle)', $r['unmatched_cars']],
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

        return self::SUCCESS;
    }
}
