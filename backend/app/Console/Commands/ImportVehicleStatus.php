<?php

namespace App\Console\Commands;

use App\Services\VehicleStatusImporter;
use Illuminate\Console\Command;
use Throwable;

/**
 * Overlay each car's real status from the fleet "Status" sheet — Sold / Personal / Office / For sale.
 * Active cars are LEFT on their live om:sync status. Meant to run LAST, after the API+sheet sync.
 *
 *   php artisan import:vehicle-status --dry-run   # preview every change, write nothing
 *   php artisan import:vehicle-status             # apply
 */
class ImportVehicleStatus extends Command
{
    protected $signature = 'import:vehicle-status
        {--sheet= : Spreadsheet ID (defaults to config vehicle_status.sheet_id)}
        {--gid= : Tab gid (defaults to config vehicle_status.gid)}
        {--dry-run : Preview only, write nothing}
        {--limit=0 : Stop after N matched data rows}';

    protected $description = 'Overlay vehicle status from the fleet Status sheet (Sold/Personal/Office/For-sale); Active cars keep the live om:sync status. Runs last, after the sync.';

    public function handle(VehicleStatusImporter $importer): int
    {
        $dry   = (bool) $this->option('dry-run');
        $sheet = (string) ($this->option('sheet') ?: config('vehicle_status.sheet_id'));
        $gid   = (int) ($this->option('gid') ?: config('vehicle_status.gid'));
        $limit = (int) $this->option('limit') ?: null;

        if ($sheet === '') {
            $this->error('No spreadsheet id — set GOOGLE_SHEETS_VEHICLE_STATUS_ID or pass --sheet.');
            return self::FAILURE;
        }

        $this->info($dry ? 'DRY RUN — nothing will be written.' : 'Applying vehicle status overlay…');

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

        // The actual per-car changes — the whole point of a dry run.
        if (! empty($r['changes'])) {
            $this->newLine();
            $this->line($dry ? '<comment>Changes that WOULD be applied:</comment>' : 'Changes applied:');
            $this->table(
                ['Car', 'VIN', 'Sheet says', 'From', 'To', 'Action'],
                collect($r['changes'])->map(fn ($c) => [
                    $c['label'],
                    $c['vin'] ?: '—',
                    $c['sheet'],
                    $c['from'] ?? '—',
                    $c['to'] ?? '—',
                    $c['action'],
                ])->all()
            );
        } else {
            $this->newLine();
            $this->info('No status changes needed — every matched car already agrees with the sheet.');
        }

        $c = $r['counts'];
        $this->newLine();
        $this->table(['Metric', 'Count'], [
            ['Data rows read', $c['rows']],
            ['Matched to a car', $c['matched']],
            [$dry ? 'Would change status' : 'Status changed', $c['changed']],
            [$dry ? 'Would flag for-sale' : 'Flagged for-sale', $c['flagged']],
            [$dry ? 'Would set category' : 'Category set', $c['category_set'] ?? 0],
            ['Left unchanged (Active/other)', $c['unchanged']],
            ['Protected (web-made)', $c['protected']],
            ['Unmatched (not our car)', $c['unmatched']],
        ]);

        // Status text the sheet uses that our map has no rule for — surface it so the map can grow.
        if (! empty($r['unknown_statuses'])) {
            $this->newLine();
            $this->line('<comment>Status values not in the map (cars left unchanged — add them to config/vehicle_status.php to act on them):</comment>');
            foreach ($r['unknown_statuses'] as $status => $n) {
                $this->line("  • \"{$status}\" × {$n}");
            }
        }

        if (! empty($r['unmatched_samples'])) {
            $this->newLine();
            $this->line('<comment>Rows that did not match a fleet car (first 50 — other owners / typos, safely skipped):</comment>');
            $this->line('  ' . implode(' · ', $r['unmatched_samples']));
        }

        if ($dry) {
            $this->newLine();
            $this->warn('Dry run — nothing was written. Re-run without --dry-run to apply.');
        }

        return self::SUCCESS;
    }
}
