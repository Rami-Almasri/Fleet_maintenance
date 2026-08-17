<?php

namespace App\Console\Commands;

use App\Services\GarageLocationSheetImporter;
use Illuminate\Console\Command;
use Throwable;

/**
 * Refresh "which car is at which garage" from the maintenance sheet's N-Location tab.
 *
 * The tab is the Controllers' live list and the only written record of the garage for a car that
 * went out on an OfficeManager maintenance contract. Every run REPLACES the mirror, so a car whose
 * row was deleted (it came back) stops being shown at a garage.
 */
class ImportGarageLocations extends Command
{
    protected $signature = 'import:garage-locations
        {--sheet= : Spreadsheet ID (defaults to config google.sheets.maintenance.id)}
        {--gid= : Tab gid (defaults to config google.sheets.maintenance.location_gid)}
        {--dry-run : Preview only, write nothing}';

    protected $description = 'Import the N-Location sheet tab (Car | Garage) into vehicle_garage_locations';

    public function handle(GarageLocationSheetImporter $importer): int
    {
        $dry   = (bool) $this->option('dry-run');
        $sheet = (string) ($this->option('sheet') ?: config('google.sheets.maintenance.id'));
        $gid   = (int) ($this->option('gid') ?: config('google.sheets.maintenance.location_gid'));

        if ($sheet === '') {
            $this->error('No spreadsheet id — set GOOGLE_SHEETS_MAINTENANCE_ID or pass --sheet.');
            return self::FAILURE;
        }

        $this->info($dry ? 'DRY RUN — nothing will be written.' : 'Importing the N-Location tab…');

        try {
            $r = $importer->import($sheet, $gid, $dry);
        } catch (Throwable $e) {
            $this->error('Failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        if (isset($r['error'])) {
            $this->error($r['error']);
            return self::FAILURE;
        }

        $this->table(['Metric', 'Count'], [
            ['Rows read',            $r['imported']],
            ['Car matched',          $r['matched']],
            ['Car NOT matched',      $r['unmatched']],
            ['Blank rows skipped',   $r['skipped']],
            ['Garage vendors created', $r['vendors_made']],
        ]);

        foreach ($r['rows'] as $row) {
            $this->line(sprintf(
                '  • %s  →  %s%s',
                $row['car_label'],
                $row['garage_name'],
                $row['vehicle_id'] ? '' : '   <fg=yellow>(car not matched)</>'
            ));
        }

        if ($r['unmatched'] > 0) {
            $this->warn('Rows whose car could not be placed are kept with no vehicle and reported on the board.');
        }

        return self::SUCCESS;
    }
}
